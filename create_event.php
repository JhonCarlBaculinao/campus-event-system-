<?php
session_start();

include 'db_connect.php';
require 'lang.php';
require 'csrf.php';
require 'send_email.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    http_response_code(403);
    die("Access denied. Organizers only.");
}

$full_name = $_SESSION['full_name'] ?? '';
$first_name = explode(' ', trim($full_name))[0];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verify();

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $event_date = $_POST['event_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $venue = trim($_POST['venue'] ?? '');
    $registration_limit = (int)($_POST['registration_limit'] ?? 0);

    $organizer_id = $_SESSION['user_id'];

    /* =========================================
       BASIC VALIDATION
       ========================================= */

    if (
        empty($title) ||
        empty($description) ||
        empty($category) ||
        empty($event_date) ||
        empty($start_time) ||
        empty($end_time) ||
        empty($venue)
    ) {

        $error = t('complete_required_fields');

    } elseif ($registration_limit < 1) {

        $error = t('registration_limit_min');

    } elseif ($end_time <= $start_time) {

        $error = t('end_after_start');

    } elseif ($event_date < date('Y-m-d')) {

        $error = t('date_not_past');

    } elseif (pg_fetch_assoc(pg_query_params(
        $conn,
        "SELECT 1 FROM events
         WHERE event_date = $1
           AND start_time = $2
           AND end_time   = $3
           AND status != 'deleted'
         LIMIT 1",
        [$event_date, $start_time, $end_time]
    ))) {

        $error = t('date_time_occupied') ?: 'This date and time is already occupied by another event.';

    } else {

        /* =========================================
           POSTER UPLOAD
           ========================================= */

        $poster_image = null;

        if (
            isset($_FILES['poster_image']) &&
            $_FILES['poster_image']['error'] === UPLOAD_ERR_OK
        ) {

            $allowed_extensions = [
                'jpg',
                'jpeg',
                'png',
                'webp'
            ];

            $file_extension = strtolower(
                pathinfo(
                    $_FILES['poster_image']['name'],
                    PATHINFO_EXTENSION
                )
            );

            if (!in_array($file_extension, $allowed_extensions, true)) {

                $error = t('invalid_poster_format');

            } elseif ($_FILES['poster_image']['size'] > 5 * 1024 * 1024) {

                $error = t('poster_too_large');

            } elseif (!in_array(
                $_FILES['poster_image']['type'],
                ['image/jpeg', 'image/png', 'image/webp'],
                true
            ) || @getimagesize($_FILES['poster_image']['tmp_name']) === false) {

                $error = t('invalid_poster_format');

            } else {

                $new_filename =
                    uniqid('poster_', true) .
                    '.' .
                    $file_extension;

                $upload_path = 'img/' . $new_filename;

                if (
                    move_uploaded_file(
                        $_FILES['poster_image']['tmp_name'],
                        $upload_path
                    )
                ) {

                    $poster_image = $new_filename;

                } else {

                    $error = t('poster_upload_failed');
                }
            }
        }

        /* =========================================
           CREATE EVENT
           ========================================= */

        if (!isset($error)) {

            $query = pg_query_params(
                $conn,

                "INSERT INTO events
                (
                    title,
                    description,
                    category,
                    event_date,
                    start_time,
                    end_time,
                    venue,
                    registration_limit,
                    organizer_id,
                    status,
                    poster_image
                )
                VALUES
                (
                    $1,
                    $2,
                    $3,
                    $4,
                    $5,
                    $6,
                    $7,
                    $8,
                    $9,
                    'pending',
                    $10
                )
                RETURNING event_id",

                [
                    $title,
                    $description,
                    $category,
                    $event_date,
                    $start_time,
                    $end_time,
                    $venue,
                    $registration_limit,
                    $organizer_id,
                    $poster_image
                ]
            );

            if ($query) {

                $created_event = pg_fetch_assoc($query);

                $event_id = $created_event['event_id'];

                /* =========================================
                   GET ORGANIZER INFORMATION
                   ========================================= */

                $organizer_result = pg_query_params(
                    $conn,

                    "SELECT full_name, email
                     FROM users
                     WHERE user_id = $1",

                    [$organizer_id]
                );

                $organizer = pg_fetch_assoc($organizer_result);

                /* =========================================
                   NOTIFY ORGANIZER
                   ========================================= */

                $organizer_message =
                    'Your event "' .
                    $title .
                    '" has been submitted successfully and is waiting for administrator approval.';

                pg_query_params(
                    $conn,

                    "INSERT INTO notifications
                    (
                        user_id,
                        message,
                        type
                    )
                    VALUES
                    (
                        $1,
                        $2,
                        $3
                    )",

                    [
                        $organizer_id,
                        $organizer_message,
                        'event_submission'
                    ]
                );

                /* =========================================
                   EMAIL ORGANIZER
                   ========================================= */

                if (!empty($organizer['email'])) {

                    send_email_deferred(
                        $organizer['email'],
                        'Event Submitted Successfully',

                        '<h2>Hello ' .
                        htmlspecialchars($organizer['full_name']) .
                        '!</h2>

                        <p>Your event
                        <strong>' .
                        htmlspecialchars($title) .
                        '</strong>
                        has been submitted successfully.</p>

                        <p>The event is currently
                        <strong>pending administrator approval</strong>.</p>

                        <p>You will receive another notification once an administrator approves or rejects your event.</p>'
                    );
                }

                /* =========================================
                   GET ALL ADMINS
                   ========================================= */

                $admins = pg_query(
                    $conn,

                    "SELECT user_id, full_name, email
                     FROM users
                     WHERE role = 'admin'"
                );

                /* =========================================
                   NOTIFY ALL ADMINS
                   ========================================= */

                while ($admin = pg_fetch_assoc($admins)) {

                    $admin_message =
                        'A new event "' .
                        $title .
                        '" has been submitted by ' .
                        $organizer['full_name'] .
                        ' and is waiting for approval.';

                    /* Site notification */

                    pg_query_params(
                        $conn,

                        "INSERT INTO notifications
                        (
                            user_id,
                            message,
                            type
                        )
                        VALUES
                        (
                            $1,
                            $2,
                            $3
                        )",

                        [
                            $admin['user_id'],
                            $admin_message,
                            'event_pending'
                        ]
                    );

                    /* Gmail notification */

                    if (!empty($admin['email'])) {

                        send_email_deferred(
                            $admin['email'],
                            'New Event Awaiting Approval',

                            '<h2>New Event Submission</h2>

                            <p>A new event has been submitted by
                            <strong>' .
                            htmlspecialchars($organizer['full_name']) .
                            '</strong>.</p>

                            <p>
                            <strong>Event:</strong>
                            ' .
                            htmlspecialchars($title) .
                            '
                            </p>

                            <p>
                            <strong>Date:</strong>
                            ' .
                            htmlspecialchars($event_date) .
                            '
                            </p>

                            <p>
                            <strong>Venue:</strong>
                            ' .
                            htmlspecialchars($venue) .
                            '
                            </p>

                            <p>Please log in to the administrator panel to review the event.</p>'
                        );
                    }
                }

                $success = t('event_submitted_msg');

                /* Clear POST values after successful submission */

                $_POST = [];
            } else {

                $error = t('event_create_error');

            }
        }
    }
}


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
   PAGE VARIABLES (shared partials)
   ========================================================= */

$role_label = 'Event Organizer';

$page_title  = t('title_create_event');
$active_page = 'create_event';

?>

<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     CREATE EVENT HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 flex flex-col sm:flex-row sm:items-center gap-6 mb-8 animate-up"
>

    <div
        class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
    >

        <i class="fa-solid fa-calendar-plus text-2xl"></i>

    </div>

    <div class="min-w-0">

        <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
            <?= t('create_event'); ?>
        </h2>

        <p class="text-slate-600 mt-1 text-sm sm:text-base">
            <?= t('create_event_hero_desc'); ?>
        </p>

    </div>

</div>


<!-- =========================================================
     CREATE EVENT FORM
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 max-w-4xl mx-auto animate-up delay-1">

    <?php if (isset($success)): ?>

        <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 mb-6">

            <i class="fa-solid fa-circle-check mr-2"></i>

            <?= htmlspecialchars($success); ?>

        </div>

    <?php endif; ?>


    <?php if (isset($error)): ?>

        <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 mb-6">

            <i class="fa-solid fa-circle-exclamation mr-2"></i>

            <?= htmlspecialchars($error); ?>

        </div>

    <?php endif; ?>


    <form
        method="POST"
        action="create_event.php"
        enctype="multipart/form-data"
        class="space-y-6"
    >

        <?= csrf_field(); ?>

        <input type="hidden" name="create_event" value="1">


        <div>

            <label class="font-semibold text-slate-700">
                <?= t('event_title'); ?>
            </label>

            <input
                type="text"
                name="title"
                required
                value="<?= isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>"
                class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

        </div>


        <div>

            <label class="font-semibold text-slate-700">
                <?= t('description'); ?>
            </label>

            <textarea
                name="description"
                rows="5"
                required
                class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            ><?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>

        </div>


        <div>

            <label class="font-semibold text-slate-700">
                <?= t('category'); ?>
            </label>

            <input
                type="text"
                name="category"
                required
                placeholder="<?= t('category_placeholder'); ?>"
                value="<?= isset($_POST['category']) ? htmlspecialchars($_POST['category']) : ''; ?>"
                class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

        </div>


        <div class="grid md:grid-cols-3 gap-5">

            <div>

                <label class="font-semibold text-slate-700">
                    <?= t('event_date'); ?>
                </label>

                <input
                    type="date"
                    name="event_date"
                    required
                    value="<?= isset($_POST['event_date']) ? htmlspecialchars($_POST['event_date']) : ''; ?>"
                    class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                >

            </div>


            <div>

                <label class="font-semibold text-slate-700">
                    <?= t('start_time'); ?>
                </label>

                <input
                    type="time"
                    name="start_time"
                    required
                    value="<?= isset($_POST['start_time']) ? htmlspecialchars($_POST['start_time']) : ''; ?>"
                    class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                >

            </div>


            <div>

                <label class="font-semibold text-slate-700">
                    <?= t('end_time'); ?>
                </label>

                <input
                    type="time"
                    name="end_time"
                    required
                    value="<?= isset($_POST['end_time']) ? htmlspecialchars($_POST['end_time']) : ''; ?>"
                    class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                >

            </div>

        </div>


        <div>

            <label class="font-semibold text-slate-700">
                <?= t('venue'); ?>
            </label>

            <input
                type="text"
                name="venue"
                required
                value="<?= isset($_POST['venue']) ? htmlspecialchars($_POST['venue']) : ''; ?>"
                class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

        </div>


        <div>

            <label class="font-semibold text-slate-700">
                <?= t('registration_limit'); ?>
            </label>

            <input
                type="number"
                name="registration_limit"
                min="1"
                required
                placeholder="<?= t('registration_limit_placeholder'); ?>"
                value="<?= isset($_POST['registration_limit']) ? htmlspecialchars($_POST['registration_limit']) : ''; ?>"
                class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

            <p class="text-sm text-slate-500 mt-2">
                <?= t('registration_limit_desc'); ?>
            </p>

        </div>


        <div>

            <label class="font-semibold text-slate-700">
                <?= t('poster_image'); ?>
            </label>

            <input
                type="file"
                name="poster_image"
                accept=".jpg,.jpeg,.png,.webp"
                class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50"
            >

            <p class="text-sm text-slate-500 mt-2">
                <?= t('poster_size_hint'); ?>
            </p>

        </div>


        <button
            type="submit"
            class="w-full bg-rmc-800 hover:bg-rmc-900 text-white font-bold py-4 rounded-xl transition inline-flex items-center justify-center gap-2"
        >

            <i class="fa-solid fa-paper-plane"></i>

            <?= t('submit_for_approval'); ?>

        </button>

    </form>

</div>


<?php include 'partials/footer.php'; ?>
