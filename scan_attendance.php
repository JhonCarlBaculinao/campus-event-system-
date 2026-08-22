<?php

session_start();

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
| Returns [ $type, $text, $student, $event, $registration_id, $checkin ]
|--------------------------------------------------------------------------
*/

function process_attendance($conn, $reg, $scan_method, $organizer_id, $token_hash)
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

    $check = pg_query_params(
        $conn,
        "
        SELECT
            attendance_id,
            verified,
            checked_in_at

        FROM attendance

        WHERE registration_id = $1

        ORDER BY attendance_id DESC

        LIMIT 1
        ",
        array($reg['registration_id'])
    );

    $attendance = $check ? pg_fetch_assoc($check) : null;

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

    $insert = pg_query_params(
        $conn,
        "
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
            $1,
            TRUE,
            NOW(),
            NOW(),
            $2,
            $3,
            $4
        )

        RETURNING
            attendance_id,
            checked_in_at
        ",
        array(
            $reg['registration_id'],
            $scan_method,
            $organizer_id,
            $token_hash
        )
    );

    if (!$insert) {

        error_log(
            "Attendance insert failed: " .
            pg_last_error($conn)
        );

        return array(
            'error',
            t('attendance_record_error'),
            null, null, null, null, null, null
        );
    }

    $attendance = pg_fetch_assoc($insert);

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

        if ($submitted_code === '') {
            $message = t('qr_cannot_be_empty');
            $message_type = "error";
        }

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

                $result = pg_query_params(
                    $conn,
                    "
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

                    WHERE r.registration_id = $1
                      AND r.event_id = $2
                      AND e.organizer_id = $3

                    LIMIT 1
                    ",
                    array(
                        $rid,
                        $eid,
                        $organizer_id
                    )
                );

                if (!$result) {

                    error_log(
                        "Attendance token lookup failed: " .
                        pg_last_error($conn)
                    );

                    $message = t('qr_process_error');
                    $message_type = "error";

                } else {

                    $reg = pg_fetch_assoc($result);

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
                        $conn,
                        $reg,
                        'qr',
                        $organizer_id,
                        $token_hash
                    );
                    $used_method = 'qr';
                }

                }

            }

        } else {

            /*
            |--------------------------------------------------------------------------
            | LEGACY qr_code (printed cards / pasted static code)
            |--------------------------------------------------------------------------
            */

            $result = pg_query_params(
                $conn,
                "
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

                WHERE r.qr_code = $1
                  AND e.organizer_id = $2

                LIMIT 1
                ",
                array(
                    $submitted_code,
                    $organizer_id
                )
            );

            if (!$result) {

                error_log(
                    "Attendance QR lookup failed: " .
                    pg_last_error($conn)
                );

                $message = t('qr_process_error');
                $message_type = "error";

            } else {

                $reg = pg_fetch_assoc($result);

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
                        $conn,
                        $reg,
                        'manual',
                        $organizer_id,
                        $token_hash
                    );
                    $used_method = 'manual';
                }

            }

        }

    }


    /* =========================================================
       2) MANUAL FALLBACK  (student ID + event)
       ========================================================= */

    elseif ($manual_event > 0 && $manual_sid !== '') {

            $result = pg_query_params(
                $conn,
                "
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

                WHERE LOWER(u.student_id) = LOWER($1)
                  AND r.event_id = $2
                  AND e.organizer_id = $3

                LIMIT 1
                ",
                array(
                    $manual_sid,
                    $manual_event,
                    $organizer_id
                )
            );

        if (!$result) {

            error_log(
                "Manual attendance lookup failed: " .
                pg_last_error($conn)
            );

            $message = t('attendance_record_error');
            $message_type = "error";

        } else {

            $reg = pg_fetch_assoc($result);

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
                    $conn,
                    $reg,
                    'manual',
                    $organizer_id,
                    null
                );
                $used_method = 'manual';
            }

        }

    }

    if ($used_method !== null) {
        $verified_method = ($used_method === 'qr')
            ? t('scan_method_qr')
            : t('scan_method_manual');
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

$unread_count = (int) pg_fetch_result(
    pg_query_params(
        $conn,
        "SELECT COUNT(*)
         FROM notifications
         WHERE user_id = $1
           AND is_read = false",
        array($_SESSION['user_id'])
    ),
    0,
    0
);

$recent_notifications = pg_query_params(
    $conn,
    "SELECT notification_id, type, message, is_read, created_at
     FROM notifications
     WHERE user_id = $1
     ORDER BY created_at DESC
     LIMIT 5",
    array($_SESSION['user_id'])
);


/* =========================================================
   ORGANIZER'S EVENTS (for the manual check-in dropdown)
   ========================================================= */

$organizer_events = array();

$events_result = pg_query_params(
    $conn,
    "
    SELECT event_id, title, event_date
    FROM events
    WHERE organizer_id = $1
      AND status = 'approved'
    ORDER BY event_date ASC
    ",
    array($organizer_id)
);

if ($events_result) {
    while ($row = pg_fetch_assoc($events_result)) {
        $organizer_events[] = $row;
    }
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

        <div
            id="reader"
            class="rounded-2xl overflow-hidden border border-slate-200 bg-slate-50"
        ></div>

        <div class="mt-4 bg-rmc-50 text-rmc-800 border border-rmc-100 rounded-xl p-4 text-sm flex items-start gap-2">

            <i class="fa-solid fa-circle-info mt-1"></i>

            <span>
                <?= t('camera_hint'); ?>
            </span>

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
     QR SCANNER LIBRARY + SCRIPT
     ========================================================= -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>

<script>

const scanT = <?= json_encode([
    'processing_qr' => t('processing_qr'),
    'unable_process_qr' => t('unable_process_qr'),
    'camera_not_detected' => t('camera_not_detected'),
    'use_manual_checkin' => t('use_manual_checkin'),
    'csrf' => csrf_token(),
]); ?>;

let scannerStarted = false;

function onScanSuccess(decodedText) {

    if (scannerStarted) {
        return;
    }

    scannerStarted = true;


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

        return response.text();

    })
    .then(function() {

        location.reload();

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


const html5QrCode =
    new Html5Qrcode("reader");


html5QrCode.start(
    {
        facingMode: "environment"
    },
    {
        fps: 10,
        qrbox: 250
    },
    onScanSuccess
).catch(function(error) {

    console.error(error);

    document.getElementById("reader").innerHTML =

        "<div class='text-center text-slate-400 p-10'>" +

        "<i class='fa-solid fa-camera-slash text-4xl mb-4'></i>" +

        "<br>" +

        scanT.camera_not_detected +

        "<br><br>" +

        scanT.use_manual_checkin +

        "</div>";

});

</script>


<?php include 'partials/footer.php'; ?>
