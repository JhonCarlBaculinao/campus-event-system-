<?php


require 'db_connect.php';
require 'lang.php';
require 'csrf.php';
require 'qr_token.php';

/*
|--------------------------------------------------------------------------
| ROLE-BASED ACCESS
|--------------------------------------------------------------------------
| Only organizers can scan attendance.
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'organizer'
) {
    http_response_code(403);
    die("Access denied. Organizers only.");
}

$organizer_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? '';
$first_name = explode(' ', trim($full_name))[0];
$role = 'organizer';
$role_label = 'Event Organizer';

$message = "";
$message_type = "info";

$verified_student = null;
$verified_event = null;
$verified_registration_id = null;
$verified_checkin = null;
$verified_student_id = null;
$verified_department = null;

$verified_method = null;
$verified_scanned_by = null;


/*
|--------------------------------------------------------------------------
| SHARED: VALIDATE REGISTRATION ROW + RECORD ATTENDANCE
|--------------------------------------------------------------------------
| $reg is a row from a registration lookup that already guarantees the
| registration belongs to an event of the logged-in organizer.
|
| NOTE: check-in data lives in a separate `attendance` table (not on
| `registrations` or `users`). If your real table has a different name,
| swap it in below.
|
| Returns [ $type, $text, $student, $event, $registration_id, $checkin,
|           $student_id, $department ]
|--------------------------------------------------------------------------
*/

function process_attendance($pdo, $reg, $scan_method, $organizer_id, $token_hash)
{
    /*
    |--------------------------------------------------------------------------
    | REGISTRATION STATUS
    |--------------------------------------------------------------------------
    */

    if (
        !empty($reg['registration_status']) &&
        $reg['registration_status'] !== 'registered'
    ) {
        return array(
            'warning',
            t('registration_not_active'),
            null, null, null, null, null, null
        );
    }

    /*
    |--------------------------------------------------------------------------
    | EVENT STATUS
    |--------------------------------------------------------------------------
    */

    if ($reg['event_status'] === 'cancelled') {
        return array(
            'error',
            t('event_cancelled_msg'),
            null, null, null, null, null, null
        );
    }

    if ($reg['event_status'] !== 'approved') {
        return array(
            'warning',
            t('event_not_approved_msg'),
            null, null, null, null, null, null
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ALREADY CHECKED IN
    |--------------------------------------------------------------------------
    */

    $check = $pdo->prepare("
        SELECT
            attendance_id,
            verified,
            checked_in_at
        FROM attendance
        WHERE registration_id = ?
        ORDER BY attendance_id DESC
        LIMIT 1
    ");
    $check->execute([$reg['registration_id']]);

    $attendance = $check->fetch(PDO::FETCH_ASSOC);

    if ($attendance) {

        $verified_checkin = null;

        if (!empty($attendance['checked_in_at'])) {
            $verified_checkin = date(
                'F d, Y — h:i A',
                strtotime($attendance['checked_in_at'])
            );
        }

        return array(
            'warning',
            t('already_checked_in_msg'),
            $reg['full_name'],
            $reg['title'],
            $reg['registration_id'],
            $verified_checkin,
            $reg['student_id'] ?? null,
            $reg['department'] ?? null
        );
    }

    /*
    |--------------------------------------------------------------------------
    | NEW ATTENDANCE (with audit fields)
    |--------------------------------------------------------------------------
    */

    $insert = $pdo->prepare("
        INSERT INTO attendance
        (
            registration_id,
            verified,
            scanned_at,
            checked_in_at,
            scan_method,
            scanned_by,
            token_hash
        )
        VALUES
        (
            ?,
            TRUE,
            NOW(),
            NOW(),
            ?,
            ?,
            ?
        )
    ");

    $insert_ok = $insert->execute([
        $reg['registration_id'],
        $scan_method,
        $organizer_id,
        $token_hash
    ]);

    if (!$insert_ok) {

        error_log(
            "Attendance insert failed: " .
            ($insert->errorInfo()[2] ?? '')
        );

        return array(
            'error',
            t('attendance_record_error'),
            null, null, null, null, null, null
        );
    }

    $new_attendance_id = $pdo->lastInsertId();

    $fetch_new = $pdo->prepare("
        SELECT attendance_id, checked_in_at
        FROM attendance
        WHERE attendance_id = ?
    ");
    $fetch_new->execute([$new_attendance_id]);
    $attendance = $fetch_new->fetch(PDO::FETCH_ASSOC);

    $verified_checkin = null;

    if (!empty($attendance['checked_in_at'])) {
        $verified_checkin = date(
            'F d, Y — h:i A',
            strtotime($attendance['checked_in_at'])
        );
    }

    $success_text = $scan_method === 'manual'
        ? t('manual_attendance_success')
        : t('attendance_success_msg');

    return array(
        'success',
        $success_text,
        $reg['full_name'],
        $reg['title'],
        $reg['registration_id'],
        $verified_checkin,
        $reg['student_id'] ?? null,
        $reg['department'] ?? null
    );
}


/*
|--------------------------------------------------------------------------
| PROCESS POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    $submitted_code = trim((string) ($_POST['qr_code'] ?? ''));
    $manual_event   = (int) ($_POST['manual_event_id'] ?? 0);
    $manual_sid     = trim((string) ($_POST['manual_student_id'] ?? ''));
    $used_method    = null;


    /* =========================================================
       1) QR CODE PATH  (signed token OR legacy qr_code)
       ========================================================= */

    if ($submitted_code !== '') {

        $token_hash = hash('sha256', $submitted_code);

        if (strpos($submitted_code, 'RMC1.') === 0) {

            /*
            |--------------------------------------------------------------------------
            | SIGNED TOKEN — verify signature, expiry, event binding
            |--------------------------------------------------------------------------
            */

            $vt = qr_token_verify($submitted_code);

            if (!$vt['ok']) {

                $message = t('qr_token_invalid');
                $message_type = "error";

            } elseif ($vt['expired']) {

                $message = t('qr_token_expired');
                $message_type = "error";

            } else {

                $rid = (int) $vt['data']['rid'];
                $eid = (int) $vt['data']['eid'];

                $result = $pdo->prepare("
                    SELECT
                        r.registration_id,
                        r.status AS registration_status,

                        u.user_id,
                        u.full_name,
                        u.email,
                        u.student_id,
                        u.department,

                        e.event_id,
                        e.title,
                        e.event_date,
                        e.start_time,
                        e.end_time,
                        e.venue,
                        e.organizer_id,
                        e.status AS event_status

                    FROM registrations r

                    JOIN users u
                        ON r.user_id = u.user_id

                    JOIN events e
                        ON r.event_id = e.event_id

                    WHERE r.registration_id = ?
                      AND r.event_id = ?
                      AND e.organizer_id = ?

                    LIMIT 1
                ");

                $result->execute([$rid, $eid, $organizer_id]);

                $reg = $result->fetch(PDO::FETCH_ASSOC);

                if (!$reg) {
                    $message = t('qr_invalid_organizer');
                    $message_type = "error";
                } else {
                    list(
                        $message_type,
                        $message,
                        $verified_student,
                        $verified_event,
                        $verified_registration_id,
                        $verified_checkin,
                        $verified_student_id,
                        $verified_department
                    ) = process_attendance(
                        $pdo,
                        $reg,
                        'qr',
                        $organizer_id,
                        $token_hash
                    );
                    $used_method = 'qr';
                }

            }

        } else {

            /*
            |--------------------------------------------------------------------------
            | LEGACY qr_code (printed cards / pasted static code)
            |--------------------------------------------------------------------------
            */

            $result = $pdo->prepare("
                SELECT
                    r.registration_id,
                    r.status AS registration_status,

                    u.user_id,
                    u.full_name,
                    u.email,
                    u.student_id,
                    u.department,

                    e.event_id,
                    e.title,
                    e.event_date,
                    e.start_time,
                    e.end_time,
                    e.venue,
                    e.organizer_id,
                    e.status AS event_status

                FROM registrations r

                JOIN users u
                    ON r.user_id = u.user_id

                JOIN events e
                    ON r.event_id = e.event_id

                WHERE r.qr_code = ?
                  AND e.organizer_id = ?

                LIMIT 1
            ");

            $result->execute([$submitted_code, $organizer_id]);

            $reg = $result->fetch(PDO::FETCH_ASSOC);

            if (!$reg) {
                $message = t('qr_invalid_organizer');
                $message_type = "error";
            } else {
                list(
                    $message_type,
                    $message,
                    $verified_student,
                    $verified_event,
                    $verified_registration_id,
                    $verified_checkin,
                    $verified_student_id,
                    $verified_department
                ) = process_attendance(
                    $pdo,
                    $reg,
                    'manual',
                    $organizer_id,
                    $token_hash
                );
                $used_method = 'manual';
            }

        }

    }


    /* =========================================================
       2) MANUAL FALLBACK  (student ID + event)
       ========================================================= */

    elseif ($manual_event > 0 && $manual_sid !== '') {

        $result = $pdo->prepare("
            SELECT
                r.registration_id,
                r.status AS registration_status,

                u.user_id,
                u.full_name,
                u.email,
                u.student_id,
                u.department,

                e.event_id,
                e.title,
                e.event_date,
                e.start_time,
                e.end_time,
                e.venue,
                e.organizer_id,
                e.status AS event_status

            FROM registrations r

            JOIN users u
                ON r.user_id = u.user_id

            JOIN events e
                ON r.event_id = e.event_id

            WHERE LOWER(u.student_id) = LOWER(?)
              AND r.event_id = ?
              AND e.organizer_id = ?

            LIMIT 1
        ");

        $result->execute([$manual_sid, $manual_event, $organizer_id]);

        $reg = $result->fetch(PDO::FETCH_ASSOC);

        if (!$reg) {
            $message = t('attendee_not_registered');
            $message_type = "error";
        } else {
            list(
                $message_type,
                $message,
                $verified_student,
                $verified_event,
                $verified_registration_id,
                $verified_checkin,
                $verified_student_id,
                $verified_department
            ) = process_attendance(
                $pdo,
                $reg,
                'manual',
                $organizer_id,
                null
            );
            $used_method = 'manual';
        }

    }

    if ($used_method !== null) {
        $verified_method = ($used_method === 'qr')
            ? t('scan_method_qr')
            : t('scan_method_manual');
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX RESPONSE (camera scan + file-upload scan)
    |--------------------------------------------------------------------------
    | The camera scanner and the QR-image-upload path both submit via
    | fetch() with ajax=1 so they can show an instant popup instead of
    | waiting on a full page reload. The manual check-in form still does
    | a normal POST and falls through to the regular page render below.
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {

        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode(array(
            'message_type' => $message_type,
            'message'      => $message,
            'student'      => $verified_student,
            'student_id'   => $verified_student_id,
            'department'   => $verified_department,
            'event'        => $verified_event,
            'checkin'      => $verified_checkin,
            'method'       => $verified_method,
        ));

        exit;
    }

}


/*
|--------------------------------------------------------------------------
| MESSAGE STYLES
|--------------------------------------------------------------------------
*/

$message_styles = [

    'success' =>
        'bg-green-50 text-green-800 border-green-200',

    'warning' =>
        'bg-yellow-50 text-yellow-800 border-yellow-200',

    'error' =>
        'bg-red-50 text-red-800 border-red-200',

    'info' =>
        'bg-rmc-50 text-rmc-800 border-rmc-200'

];


/* =========================================================
   UNREAD COUNT + RECENT NOTIFICATIONS (shared header)
   ========================================================= */

$unread_stmt = $pdo->prepare("
    SELECT COUNT(*) AS cnt
    FROM notifications
    WHERE user_id = ?
      AND is_read = 0
");
$unread_stmt->execute([$_SESSION['user_id']]);
$unread_count = (int) $unread_stmt->fetchColumn();

$recent_notifications = $pdo->prepare("
    SELECT notification_id, type, message, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");
$recent_notifications->execute([$_SESSION['user_id']]);


/* =========================================================
   ORGANIZER'S EVENTS (for the manual check-in dropdown)
   ========================================================= */

$organizer_events = array();

$events_result = $pdo->prepare("
    SELECT event_id, title, event_date
    FROM events
    WHERE organizer_id = ?
      AND status = 'approved'
    ORDER BY event_date ASC
");

$events_result->execute([$organizer_id]);

while ($row = $events_result->fetch(PDO::FETCH_ASSOC)) {
    $organizer_events[] = $row;
}


/* =========================================================
   PAGE VARIABLES (shared partials)
   ========================================================= */

$role_label = 'Event Organizer';

$page_title  = t('title_scan_attendance');
$active_page = 'scan_attendance';

?>


<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     SCANNER HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up"
>

    <div class="flex items-center gap-4 sm:gap-5">

        <div
            class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
        >

            <i class="fa-solid fa-qrcode text-2xl"></i>

        </div>

        <div>

            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                <?= t('qr_checkin_title'); ?>
            </h2>

            <p class="text-slate-600 mt-1 text-sm sm:text-base">
                <?= t('qr_checkin_desc'); ?>
            </p>

        </div>

    </div>

</div>


<!-- =========================================================
     VERIFICATION RESULT
     ========================================================= -->

<?php if ($message): ?>

<div
    class="<?= $message_styles[$message_type]; ?> border rounded-[26px] p-6 sm:p-8 mb-8 shadow-sm animate-up"
>

    <?php if ($message_type === 'success'): ?>

        <div class="flex items-center gap-4">

            <div class="w-14 h-14 rounded-full bg-emerald-600 text-white flex items-center justify-center shrink-0">

                <i class="fa-solid fa-check text-2xl"></i>

            </div>

            <div>

                <p class="text-sm font-bold uppercase tracking-wide">
                    <?= t('attendance_verified'); ?>
                </p>

                <h2 class="text-2xl font-bold">
                    <?= t('present'); ?>
                </h2>

            </div>

        </div>

    <?php elseif ($message_type === 'warning'): ?>

        <div class="flex items-center gap-4">

            <div class="w-14 h-14 rounded-full bg-amber-500 text-white flex items-center justify-center shrink-0">

                <i class="fa-solid fa-triangle-exclamation text-xl"></i>

            </div>

            <div>

                <p class="text-sm font-bold uppercase tracking-wide">
                    <?= t('attendance_notice'); ?>
                </p>

                <h2 class="text-xl font-bold">
                    <?= t('already_checked_in'); ?>
                </h2>

            </div>

        </div>

    <?php else: ?>

        <div class="flex items-center gap-4">

            <div class="w-14 h-14 rounded-full bg-red-600 text-white flex items-center justify-center shrink-0">

                <i class="fa-solid fa-xmark text-2xl"></i>

            </div>

            <div>

                <p class="text-sm font-bold uppercase tracking-wide">
                    <?= t('attendance_error'); ?>
                </p>

                <h2 class="text-xl font-bold">
                    <?= t('verification_failed'); ?>
                </h2>

            </div>

        </div>

    <?php endif; ?>


    <p class="mt-5 font-semibold">
        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
    </p>


    <?php if ($verified_student): ?>

        <div class="mt-6 bg-white/80 rounded-2xl p-6 border border-current/10">

            <div class="grid md:grid-cols-4 gap-5">

                <div>

                    <p class="text-xs uppercase font-bold opacity-60">
                        <?= t('student'); ?>
                    </p>

                    <p class="text-xl font-bold mt-1 break-words">
                        <?= htmlspecialchars($verified_student); ?>
                    </p>

                </div>

                <?php if (!empty($verified_student_id)): ?>
                <div>

                    <p class="text-xs uppercase font-bold opacity-60">
                        <?= t('student_id'); ?>
                    </p>

                    <p class="text-xl font-bold mt-1 break-words">
                        <?= htmlspecialchars($verified_student_id); ?>
                    </p>

                </div>
                <?php endif; ?>

                <?php if (!empty($verified_department)): ?>
                <div>

                    <p class="text-xs uppercase font-bold opacity-60">
                        <?= t('department'); ?>
                    </p>

                    <p class="text-lg font-bold mt-1 break-words">
                        <?= htmlspecialchars($verified_department); ?>
                    </p>

                </div>
                <?php endif; ?>

                <div>

                    <p class="text-xs uppercase font-bold opacity-60">
                        <?= t('event'); ?>
                    </p>

                    <p class="text-xl font-bold mt-1 break-words">
                        <?= htmlspecialchars($verified_event); ?>
                    </p>

                </div>

                <div>

                    <p class="text-xs uppercase font-bold opacity-60">
                        <?= t('status'); ?>
                    </p>

                    <p class="text-xl font-bold mt-1 text-emerald-700">

                        <i class="fa-solid fa-circle-check mr-1"></i>

                        <?= t('present'); ?>

                    </p>

                </div>

                <?php if ($verified_method): ?>

                    <div>

                        <p class="text-xs uppercase font-bold opacity-60">
                            <?= t('scan_method_label'); ?>
                        </p>

                        <p class="text-lg font-bold mt-1">

                            <i class="fa-solid fa-qrcode mr-1"></i>

                            <?= htmlspecialchars($verified_method); ?>

                        </p>

                    </div>

                <?php endif; ?>

            </div>

            <?php if ($verified_checkin): ?>

                <div class="mt-5 pt-5 border-t border-current/10">

                    <p class="text-xs uppercase font-bold opacity-60">
                        <?= t('check_in'); ?>
                    </p>

                    <p class="text-lg font-bold mt-1">

                        <i class="fa-solid fa-clock mr-2"></i>

                        <?= htmlspecialchars($verified_checkin); ?>

                    </p>

                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>

</div>

<?php endif; ?>


<!-- =========================================================
     SCANNER + MANUAL ENTRY
     ========================================================= -->

<div class="grid lg:grid-cols-2 gap-6 sm:gap-8">

    <!-- CAMERA SCANNER -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-7 animate-up delay-1">

        <div class="flex items-center gap-3 mb-5">

            <div class="w-12 h-12 rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center">

                <i class="fa-solid fa-camera text-xl"></i>

            </div>

            <div>

                <h2 class="text-xl font-bold text-slate-900">
                    <?= t('camera_scanner'); ?>
                </h2>

                <p class="text-slate-500 text-sm">
                    <?= t('camera_scanner_desc'); ?>
                </p>

            </div>

        </div>

        <!-- CAMERA PICKER (shown after permission is granted, allows switching cameras) -->
        <div id="cameraPickerWrap" class="mb-3">
            <label for="cameraSelect" class="text-xs font-semibold text-slate-500 uppercase tracking-wide">
                Camera
            </label>
            <select
                id="cameraSelect"
                class="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-700 focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                onchange="switchCamera()"
            ></select>
        </div>

        <div
            id="reader"
            class="rounded-2xl overflow-hidden border border-slate-200 bg-slate-50 hidden"
        ></div>

        <div class="flex items-center gap-2 mb-4">
            <button
                id="startScanBtn"
                onclick="document.getElementById('reader').classList.remove('hidden'); this.classList.add('hidden'); startScanner();"
                class="bg-rmc-800 hover:bg-rmc-900 text-white px-5 py-3 rounded-xl font-semibold transition flex items-center justify-center gap-2"
            >
                <i class="fa-solid fa-camera"></i>
                <?= t('start_scanning'); ?>
            </button>

            <!-- Switch Camera button -->
            <button
                id="switchCameraBtn"
                type="button"
                onclick="document.getElementById('cameraPickerWrap').classList.toggle('hidden');"
                class="bg-rmc-50 hover:bg-rmc-100 text-slate-800 px-4 py-2 rounded-sm font-semibold transition"
            >
                <i class="fa-solid fa-exchange-alt"></i>
                <?= t('switch_camera'); ?>
            </button>
        </div>

        <div class="mt-4 bg-rmc-50 text-rmc-800 border border-rmc-100 rounded-xl p-4 text-sm flex items-start gap-2">

            <i class="fa-solid fa-circle-info mt-1"></i>

            <span>
                <?= t('camera_hint'); ?>
            </span>

        </div>

        <!-- UPLOAD QR IMAGE (fallback when live camera scanning doesn't cooperate) -->
        <div class="mt-4 pt-4 border-t border-slate-100">

            <label class="font-medium text-slate-700 text-sm">
                Or upload a QR code image
            </label>

            <input
                type="file"
                id="qrFileInput"
                accept="image/*"
                onchange="handleQrFileUpload(this.files[0])"
                class="mt-2 w-full text-sm text-slate-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:bg-rmc-800 file:text-white file:font-semibold hover:file:bg-rmc-900 file:cursor-pointer cursor-pointer"
            >

            <div id="qrFileStatus" class="mt-2 text-sm"></div>

        </div>

    </div>


    <!-- MANUAL ENTRY -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-7 animate-up delay-2">

        <div class="flex items-center gap-3 mb-5">

            <div class="w-12 h-12 rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center">

                <i class="fa-solid fa-keyboard text-xl"></i>

            </div>

            <div>

                <h2 class="text-xl font-bold text-slate-900">
                    <?= t('manual_check_in'); ?>
                </h2>

                <p class="text-slate-500 text-sm">
                    <?= t('manual_entry_desc'); ?>
                </p>

            </div>

        </div>

        <form method="POST" action="scan_attendance.php" class="space-y-5">

            <?= csrf_field(); ?>

            <div>

                <label class="font-medium text-slate-700">
                    <?= t('qr_code_value'); ?>
                </label>

                <input
                    type="text"
                    name="qr_code"
                    autocomplete="off"
                    placeholder="<?= t('paste_qr_placeholder'); ?>"
                    class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                >

            </div>

            <button
                type="submit"
                class="w-full bg-rmc-800 hover:bg-rmc-900 text-white py-3 rounded-xl font-semibold hover:scale-[1.02] transition inline-flex items-center justify-center gap-2"
            >

                <i class="fa-solid fa-check"></i>

                <?= t('verify_attendance'); ?>

            </button>

        </form>

        <div class="my-6 flex items-center gap-3">

            <span class="h-px flex-1 bg-slate-200"></span>

            <span class="text-xs font-bold uppercase tracking-wide text-slate-400">
                <?= t('scan_method_manual'); ?>
            </span>

            <span class="h-px flex-1 bg-slate-200"></span>

        </div>

        <form method="POST" action="scan_attendance.php" class="space-y-5">

            <?= csrf_field(); ?>

            <div>

                <label class="font-medium text-slate-700">
                    <?= t('manual_checkin_event_label'); ?>
                </label>

                <select
                    name="manual_event_id"
                    required
                    class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                >

                    <option value="">
                        <?= t('select_event'); ?>
                    </option>

                    <?php foreach ($organizer_events as $ev): ?>

                        <option value="<?= (int) $ev['event_id']; ?>">
                            <?= htmlspecialchars($ev['title']); ?>
                            —
                            <?= htmlspecialchars(date('M j, Y', strtotime($ev['event_date']))); ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div>

                <label class="font-medium text-slate-700">
                    <?= t('manual_checkin_student_id'); ?>
                </label>

                <input
                    type="text"
                    name="manual_student_id"
                    required
                    autocomplete="off"
                    placeholder="e.g. 2026-ANAL-01"
                    class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                >

            </div>

            <button
                type="submit"
                class="w-full bg-slate-800 hover:bg-slate-900 text-white py-3 rounded-xl font-semibold hover:scale-[1.02] transition inline-flex items-center justify-center gap-2"
            >

                <i class="fa-solid fa-user-check"></i>

                <?= t('manual_check_in'); ?>

            </button>

        </form>

        <div class="mt-6 bg-rmc-50/60 border border-rmc-100 rounded-2xl p-5">

            <h3 class="font-bold text-slate-800">
                <?= t('attendance_process'); ?>
            </h3>

            <div class="mt-4 space-y-3 text-sm text-slate-600">

                <div class="flex gap-3">

                    <span class="w-7 h-7 rounded-full bg-rmc-100 text-rmc-800 flex items-center justify-center font-bold shrink-0">
                        1
                    </span>

                    <p>
                        <?= t('process_step1'); ?>
                    </p>

                </div>

                <div class="flex gap-3">

                    <span class="w-7 h-7 rounded-full bg-rmc-100 text-rmc-800 flex items-center justify-center font-bold shrink-0">
                        2
                    </span>

                    <p>
                        <?= t('process_step2'); ?>
                    </p>

                </div>

                <div class="flex gap-3">

                    <span class="w-7 h-7 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold shrink-0">
                        3
                    </span>

                    <p>
                        <?= t('process_step3'); ?>
                    </p>

                </div>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     SCAN RESULT MODAL (popup shown after camera/file-upload scan)
     ========================================================= -->

<div
    id="scanResultModal"
    class="hidden fixed inset-0 z-[70] bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-3 sm:p-4"
    onclick="if (event.target === this) closeScanResultModal();"
>

    <div class="bg-white rounded-3xl w-full max-w-sm sm:max-w-md shadow-2xl overflow-hidden">

        <div id="scanResultHeader" class="px-6 py-6 text-white flex items-center gap-4">

            <div id="scanResultIconWrap" class="w-14 h-14 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i id="scanResultIcon" class="fa-solid text-2xl"></i>
            </div>

            <div class="min-w-0">
                <p id="scanResultLabel" class="text-xs font-bold uppercase tracking-wide opacity-90"></p>
                <h3 id="scanResultTitle" class="font-bold text-xl leading-snug"></h3>
            </div>

        </div>

        <div class="p-6">

            <p id="scanResultMessage" class="font-semibold text-slate-700 mb-4"></p>

            <div id="scanResultDetails" class="bg-slate-50 border border-slate-200 rounded-2xl p-4 space-y-3"></div>

        </div>

        <div class="px-6 pb-6 flex gap-3">

            <button
                type="button"
                onclick="closeScanResultModal(); retryScanner();"
                class="flex-1 bg-rmc-800 hover:bg-rmc-900 text-white py-3 rounded-xl font-semibold transition"
            >
                <i class="fa-solid fa-camera mr-2"></i>
                Scan Next
            </button>

            <button
                type="button"
                onclick="closeScanResultModal();"
                class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 py-3 rounded-xl font-semibold transition"
            >
                Close
            </button>

        </div>

    </div>

</div>


<!-- =========================================================
     QR SCANNER LIBRARY + SCRIPT
     ========================================================= -->

<script src="js/html5-qrcode.min.js" onerror="var s=document.createElement('script');s.src='https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js';document.head.appendChild(s);"></script>

<script>

const scanT = <?= json_encode([
    'processing_qr' => t('processing_qr'),
    'unable_process_qr' => t('unable_process_qr'),
    'camera_not_detected' => t('camera_not_detected'),
    'use_manual_checkin' => t('use_manual_checkin'),
    'csrf' => csrf_token(),
]); ?>;

let scannerStarted = false;
let html5QrCode = null;
let preferredId = null;
let qrScanTimeout = null;

/* Standard scan config for html5-qrcode's start() — required as the
   2nd argument (see startWithFallback / switchCamera below). */
const qrScanConfig = {
    fps: 10,
    qrbox: function (viewfinderWidth, viewfinderHeight) {
        const minEdge = Math.min(viewfinderWidth, viewfinderHeight);
        const size = Math.floor(minEdge * 0.7);
        return { width: size, height: size };
    }
};

function showScannerError(icon, title, subtitle) {
    document.getElementById("reader").innerHTML =
        "<div class='text-center text-slate-400 p-10'>" +
        "<i class='fa-solid " + icon + " text-4xl mb-4'></i>" +
        "<br>" + title +
        (subtitle ? "<br><br>" + subtitle : "") +
        "</div>";
}

function showNoQRMessage() {
    document.getElementById("reader").innerHTML =
        "<div class='bg-rmc-50 border border-rmc-200 text-rmc-800 rounded-2xl p-6 text-center mb-4 animate-up'>" +
        "<i class='fa-solid fa-qrcode text-2xl mb-3'></i>" +
        "<h3 class='font-bold text-rmc-800'>No QR code detected</h3>" +
        "<p class='text-slate-600'>Please show the QR code clearly to the camera.</p>" +
        "<button onclick='tryAgainScanner()' class='mt-3 inline-block bg-rmc-800 hover:bg-rmc-900 text-white px-4 py-2 rounded-xl text-sm font-semibold transition'>Try Again</button>" +
        "</div>";
}

function scannerFriendlyError(error) {

    var name = (error && error.name) ? error.name : '';
    var msg  = (error && error.message) ? error.message : String(error || '');

    if (name === 'NotAllowedError' || name === 'SecurityError' ||
        /permission|denied|blocked/i.test(msg)) {
        return "Camera permission was denied. Allow camera access for this site in your browser settings, then click Start Scanning again.";
    }

    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
        return "No camera device was found by the browser.";
    }

    if (name === 'NotReadableError' || name === 'TrackStartError') {
        return "The camera is already in use by another application. Close it and try again.";
    }

    if (name === 'OverconstrainedError') {
        return "The selected camera could not be started with the requested settings.";
    }

    if (/insecure|https/i.test(msg)) {
        return "Camera access requires a secure context. Open this page via http://127.0.0.1/";
    }

    return "Camera error: " + (name ? name + " — " : "") + msg;
}

function showScannerRealError(error) {
    document.getElementById("reader").innerHTML =
        "<div class='text-center text-slate-500 p-8'>" +
        "<i class='fa-solid fa-video-slash text-3xl mb-3 text-slate-300'></i>" +
        "<p class='font-semibold text-red-600 mb-2'>Unable to start the camera</p>" +
        "<p class='text-sm leading-relaxed'>" + scannerFriendlyError(error) + "</p>" +
        "<button type='button' onclick='retryScanner()' class='mt-4 inline-flex items-center gap-2 bg-rmc-800 hover:bg-rmc-900 text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition'>" +
        "<i class='fa-solid fa-rotate-right'></i> Try Again</button>" +
        "</div>";
}

function populateCameraPicker(cameras, preferredId) {
    var wrap   = document.getElementById("cameraPickerWrap");
    var select = document.getElementById("cameraSelect");
    if (!wrap || !select) return cameras && cameras.length ? cameras[0].id : null;

    select.innerHTML = "";

    if (!cameras || cameras.length === 0) return null;

    cameras.forEach(function(cam, idx) {
        var opt = document.createElement("option");
        opt.value = cam.id;
        var label = cam.label || ("Camera " + (idx + 1));
        if (/front|user|face|integrated|built[- ]?in|webcam/i.test(label)) {
            label += " (built-in)";
        }
        opt.textContent = label;
        select.appendChild(opt);
    });

    var chosen = preferredId || select.options[0].value;
    select.value = chosen;

    /* Show the picker only when there is an actual choice to make */
    if (cameras.length > 1) {
        wrap.classList.remove("hidden");
    }

    return chosen;
}

function switchCamera() {

    var select = document.getElementById("cameraSelect");
    if (!select || !select.value) return;

    stopScanner();
    html5QrCode = null;

    html5QrCode = new Html5Qrcode("reader");

    try {
        var p = html5QrCode.start(
            select.value,
            qrScanConfig,
            onScanSuccess
        );
        if (p && typeof p.catch === 'function') {
            p.catch(function(error) {
                console.error(error);
                showScannerRealError(error);
            });
        }
    } catch (syncErr) {
        console.error(syncErr);
        showScannerRealError(syncErr);
    }

}

function retryScanner() {
    var btn = document.getElementById("startScanBtn");
    if (btn) {
        btn.classList.remove("hidden");
        btn.click();
    } else {
        document.getElementById("reader").classList.remove("hidden");
        startScanner();
    }
}

function tryAgainScanner() {
    /* Clear the no-QR message, reset scanner state, and restart */
    clearTimeout(qrScanTimeout);
    document.getElementById("reader").innerHTML = "";
    scannerStarted = false;
    document.getElementById("reader").classList.remove("hidden");
    startScanner();
}

function startWithFallback(reader, preferredId) {

    var attempts = [];

    if (preferredId) {
        attempts.push(preferredId);
    }

    attempts.push({ facingMode: "user" });
    attempts.push({ video: true });

    function tryNext(index) {

        if (index >= attempts.length) {
            showScannerRealError({ name: "NotFoundError", message: "No usable camera found after trying all available devices." });
            return;
        }

        /* Fresh instance per attempt — a failed start leaves the previous
           instance in a state where re-calling start() throws synchronously,
           which previously left the loading spinner stuck forever. */
        try { html5QrCode.stop().then(function() { html5QrCode.clear(); }).catch(function() {}); } catch(e) {}
        html5QrCode = new Html5Qrcode("reader");

        var promise;
        try {
            promise = html5QrCode.start(
                attempts[index],
                qrScanConfig,
                onScanSuccess
            );
        } catch (syncErr) {
            console.error("Scanner attempt " + index + " threw:", syncErr);
            tryNext(index + 1);
            return;
        }

        if (!promise || typeof promise.then !== 'function') {
            tryNext(index + 1);
            return;
        }

        promise.then(function() {
            reader.style.minHeight = "";
        }).catch(function(error) {

            console.error("Scanner attempt " + index + " failed:", error);
            tryNext(index + 1);
        });
    }

    tryNext(0);
}

function startScanner() {

    if (typeof Html5Qrcode === 'undefined') {
        showScannerError('fa-cloud-arrow-down', scanT.camera_not_detected, scanT.use_manual_checkin);
        return;
    }

    var reader = document.getElementById("reader");
    reader.style.minHeight = "280px";
    reader.innerHTML =
        "<div class='text-center text-slate-400 p-10'>" +
        "<i class='fa-solid fa-spinner fa-spin text-2xl mb-3'></i>" +
        "<br>Requesting camera access..." +
        "<br><span class='text-xs text-slate-400 mt-2 block'>If your browser shows a permission prompt, please allow it.</span>" +
        "</div>";

    /* No auto-timeouts of any kind here anymore. Scanning simply waits
       until the camera responds (permission granted/denied) or a real
       error occurs — no "no QR detected" popup, no watchdog timeout.
       Use the "Try Again" button (shown on real errors) to restart
       manually if ever needed. */

    /*
    | getCameras() triggers the browser permission prompt.
    | Labels are only available AFTER permission is granted.
    */
    Html5Qrcode.getCameras().then(function(cameras) {

        if (!cameras || cameras.length === 0) {
            /* Genuinely no camera enumerated → fall back to generic constraints */
            startWithFallback(reader, null);
            return;
        }

        preferredId = populateCameraPicker(cameras, null);

        /* Prefer a built-in/front camera when the label reveals one */
        for (var i = 0; i < cameras.length; i++) {
            var label = (cameras[i].label || '').toLowerCase();
            if (label.indexOf('front') !== -1 || label.indexOf('integrated') !== -1 ||
                label.indexOf('built') !== -1 || label.indexOf('webcam') !== -1 ||
                label.indexOf('face') !== -1) {
                preferredId = cameras[i].id;
                populateCameraPicker(cameras, preferredId);
                break;
            }
        }

        startWithFallback(reader, preferredId);

    }).catch(function(error) {

        console.error(error);

        /* Permission denied or enumeration unsupported — still attempt
           a direct start so the browser can re-prompt if appropriate. */
        if (error && (error.name === 'NotAllowedError' || error.name === 'SecurityError')) {
            showScannerRealError(error);
            return;
        }

        startWithFallback(reader, null);
    });

}

function stopScanner() {

    if (html5QrCode) {

        try {
            html5QrCode.stop().then(function() {
                html5QrCode.clear();
            }).catch(function() {});
        } catch (e) {}

    }

}

function handleQrFileUpload(file) {

    /* Clear any leftover camera-session timers/flags so an earlier
       camera attempt can't stomp on this upload's status message. */
    clearTimeout(qrScanTimeout);
    scannerStarted = false;

    var statusEl = document.getElementById("qrFileStatus");

    if (!file) {
        return;
    }

    if (typeof Html5Qrcode === 'undefined') {
        statusEl.className = "mt-2 text-sm text-red-600 font-semibold";
        statusEl.textContent = "QR library not loaded — cannot scan file.";
        return;
    }

    statusEl.className = "mt-2 text-sm text-slate-500";
    statusEl.textContent = "Reading image...";

    /* Stop the live camera first if it's running, so both scanners
       don't fight over the same #reader element. */
    stopScanner();

    var fileScanner = new Html5Qrcode("reader");

    fileScanner.scanFile(file, false)
        .then(function(decodedText) {

            statusEl.className = "mt-2 text-sm text-emerald-600 font-semibold";
            statusEl.textContent = "QR code detected — verifying...";

            try { fileScanner.clear(); } catch (e) {}

            onScanSuccess(decodedText);

        })
        .catch(function(error) {

            console.error("File scan failed:", error);

            statusEl.className = "mt-2 text-sm text-red-600 font-semibold";
            statusEl.textContent = "No QR code could be detected in that image. Try a clearer photo or use manual entry below.";

            try { fileScanner.clear(); } catch (e) {}

        });

    /* Reset the input so re-selecting the same file fires onchange again */
    document.getElementById("qrFileInput").value = "";
}

const SCAN_RESULT_STYLES = {
    success: { header: 'bg-emerald-600', icon: 'fa-check', label: 'Attendance Verified', title: 'Present' },
    warning: { header: 'bg-amber-500',   icon: 'fa-triangle-exclamation', label: 'Attendance Notice', title: 'Already Checked In' },
    error:   { header: 'bg-red-600',     icon: 'fa-xmark', label: 'Attendance Error', title: 'Verification Failed' },
    info:    { header: 'bg-rmc-800',     icon: 'fa-circle-info', label: 'Notice', title: 'Notice' }
};

function scanResultEscape(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}

function showScanResultModal(data) {

    const type = SCAN_RESULT_STYLES[data.message_type] ? data.message_type : 'info';
    const style = SCAN_RESULT_STYLES[type];

    const header = document.getElementById('scanResultHeader');
    header.className = 'px-6 py-6 text-white flex items-center gap-4 ' + style.header;

    document.getElementById('scanResultIcon').className = 'fa-solid ' + style.icon + ' text-2xl';
    document.getElementById('scanResultLabel').textContent = style.label;
    document.getElementById('scanResultTitle').textContent =
        (type === 'success' || type === 'warning') ? style.title : (data.message || style.title);

    document.getElementById('scanResultMessage').textContent = data.message || '';

    const rows = [];

    if (data.student) {
        rows.push(['Student', data.student]);
    }
    if (data.student_id) {
        rows.push(['Student ID', data.student_id]);
    }
    if (data.department) {
        rows.push(['Department', data.department]);
    }
    if (data.event) {
        rows.push(['Event', data.event]);
    }
    if (data.method) {
        rows.push(['Scan Method', data.method]);
    }
    if (data.checkin) {
        rows.push(['Check-In Time', data.checkin]);
    }

    const detailsEl = document.getElementById('scanResultDetails');

    if (rows.length === 0) {
        detailsEl.classList.add('hidden');
        detailsEl.innerHTML = '';
    } else {
        detailsEl.classList.remove('hidden');
        detailsEl.innerHTML = rows.map(function(r) {
            return '<div class="flex justify-between gap-3">' +
                '<span class="text-xs font-bold uppercase tracking-wide text-slate-400">' + scanResultEscape(r[0]) + '</span>' +
                '<span class="text-sm font-bold text-slate-800 text-right">' + scanResultEscape(r[1]) + '</span>' +
                '</div>';
        }).join('');
    }

    document.getElementById('scanResultModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeScanResultModal() {
    document.getElementById('scanResultModal').classList.add('hidden');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeScanResultModal();
    }
});

function onScanSuccess(decodedText) {

    if (scannerStarted) {
        return;
    }

    clearTimeout(qrScanTimeout);

    scannerStarted = true;

    stopScanner();

    document.getElementById("reader").innerHTML =

        "<div class='text-center text-slate-500 p-6'>" +

        "<i class='fa-solid fa-spinner fa-spin text-2xl mb-3'></i>" +

        "<br>" + scanT.processing_qr +

        "</div>";


    const formData = new URLSearchParams();

    formData.append(
        "qr_code",
        decodedText
    );

    formData.append(
        "csrf_token",
        scanT.csrf
    );

    formData.append(
        "ajax",
        "1"
    );


    fetch(
        "scan_attendance.php",
        {
            method: "POST",

            headers: {
                "Content-Type":
                    "application/x-www-form-urlencoded"
            },

            body: formData.toString()
        }
    )
    .then(function(response) {

        return response.json();

    })
    .then(function(data) {

        scannerStarted = false;

        document.getElementById("reader").classList.add("hidden");
        document.getElementById("reader").innerHTML = "";

        var startBtn = document.getElementById("startScanBtn");
        if (startBtn) {
            startBtn.classList.remove("hidden");
        }

        showScanResultModal(data);

    })
    .catch(function(error) {

        console.error(error);

        scannerStarted = false;

        document.getElementById("reader").innerHTML =

            "<div class='text-center text-red-500 p-10'>" +

            scanT.unable_process_qr +

            "</div>";

    });

}


</script>


<?php include 'partials/footer.php'; ?>