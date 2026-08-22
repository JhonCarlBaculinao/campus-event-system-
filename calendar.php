<?php

session_start();

require 'db_connect.php';
require 'send_email.php';
require 'lang.php';
require 'csrf.php';

/*
|--------------------------------------------------------------------------
| ROLE PROTECTION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'student'
) {
    http_response_code(403);
    die("Access denied. Students only.");
}

$full_name = $_SESSION['full_name'] ?? '';
$role = $_SESSION['role'] ?? '';
$student_id = $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| PHILIPPINE TIMEZONE
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Asia/Manila');

$timezone = new DateTimeZone('Asia/Manila');
$now = new DateTime('now', $timezone);

/*
|--------------------------------------------------------------------------
| REGISTRATION MESSAGE
|--------------------------------------------------------------------------
*/

$message = '';
$message_type = '';

/*
|--------------------------------------------------------------------------
| EVENT REGISTRATION FROM CALENDAR
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    if (isset($_POST['register_event_id'])) {

        $event_id = (int) $_POST['register_event_id'];

        /*
        |--------------------------------------------------------------------------
        | GET EVENT
        |--------------------------------------------------------------------------
        */

        $event_query = pg_query_params(
            $conn,
            "SELECT
                event_id,
                title,
                event_date,
                start_time,
                end_time,
                venue,
                registration_limit,
                status
             FROM events
             WHERE event_id = $1
             LIMIT 1",
            array($event_id)
        );

        $event = pg_fetch_assoc($event_query);

        if (!$event) {

            $message = "The selected event could not be found.";
            $message_type = 'error';

        } elseif ($event['status'] !== 'approved') {

            $message = "This event is no longer available for registration.";
            $message_type = 'error';

        } else {

            /*
            |--------------------------------------------------------------------------
            | CALCULATE EVENT TIME
            |--------------------------------------------------------------------------
            */

            $event_start = new DateTime(
                $event['event_date'] . ' ' . $event['start_time'],
                $timezone
            );

            $event_end = new DateTime(
                $event['event_date'] . ' ' . $event['end_time'],
                $timezone
            );

            if ($event_end <= $event_start) {
                $event_end->modify('+1 day');
            }

            /*
            |--------------------------------------------------------------------------
            | EVENT ALREADY COMPLETED
            |--------------------------------------------------------------------------
            */

            if ($now >= $event_end) {

                $message = "Registration is closed because this event has already ended.";
                $message_type = 'error';

            }

            /*
            |--------------------------------------------------------------------------
            | EVENT CURRENTLY HAPPENING
            |--------------------------------------------------------------------------
            */

            elseif ($now >= $event_start && $now < $event_end) {

                $message = "Registration is closed because this event is already happening.";
                $message_type = 'error';

            }

            else {

                /*
                |--------------------------------------------------------------------------
                | CHECK DUPLICATE REGISTRATION
                |--------------------------------------------------------------------------
                */

                $check = pg_query_params(
                    $conn,
                    "SELECT registration_id
                     FROM registrations
                     WHERE event_id = $1
                       AND user_id = $2
                     LIMIT 1",
                    array($event_id, $student_id)
                );

                if (pg_num_rows($check) > 0) {

                    $message = "You are already registered for this event.";
                    $message_type = 'error';

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | CHECK REGISTRATION LIMIT
                    |--------------------------------------------------------------------------
                    */

                    $count_query = pg_query_params(
                        $conn,
                        "SELECT COUNT(*)
                         FROM registrations
                         WHERE event_id = $1
                         AND status = 'registered'",
                        array($event_id)
                    );

                    $current_count = (int) pg_fetch_result(
                        $count_query,
                        0,
                        0
                    );

                    $registration_limit =
                        (int) $event['registration_limit'];

                    if (
                        $registration_limit > 0 &&
                        $current_count >= $registration_limit
                    ) {

                        $message =
                            "Sorry, this event has reached its registration limit.";

                        $message_type = 'error';

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | GENERATE QR CODE
                        |--------------------------------------------------------------------------
                        */

                        $qr_code = bin2hex(random_bytes(16));

                        /*
                        |--------------------------------------------------------------------------
                        | INSERT REGISTRATION
                        |--------------------------------------------------------------------------
                        */

                        $insert = pg_query_params(
                            $conn,
                            "INSERT INTO registrations
                            (
                                event_id,
                                user_id,
                                qr_code,
                                status
                            )
                            VALUES
                            (
                                $1,
                                $2,
                                $3,
                                'registered'
                            )",
                            array(
                                $event_id,
                                $student_id,
                                $qr_code
                            )
                        );

                        if (!$insert) {

                            $message =
                                "Unable to complete your registration. Please try again.";

                            $message_type = 'error';

                        } else {

                            /*
                            |--------------------------------------------------------------------------
                            | CREATE SYSTEM NOTIFICATION
                            |--------------------------------------------------------------------------
                            */

                            $notification_message =
                                "You have successfully registered for \"" .
                                $event['title'] .
                                "\".";

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
                                    'registration'
                                )",
                                array(
                                    $student_id,
                                    $notification_message
                                )
                            );

                            /*
                            |--------------------------------------------------------------------------
                            | GET USER EMAIL SETTINGS
                            |--------------------------------------------------------------------------
                            */

                            $user_info_query = pg_query_params(
                                $conn,
                                "SELECT
                                    email,
                                    full_name,
                                    email_notifications
                                 FROM users
                                 WHERE user_id = $1
                                 LIMIT 1",
                                array($student_id)
                            );

                            $user_info =
                                pg_fetch_assoc($user_info_query);

                            /*
                            |--------------------------------------------------------------------------
                            | SEND EMAIL CONFIRMATION
                            |--------------------------------------------------------------------------
                            */

                            $wants_email =
                                (
                                    isset($user_info['email_notifications']) &&
                                    (
                                        $user_info['email_notifications'] === 't' ||
                                        $user_info['email_notifications'] === true ||
                                        $user_info['email_notifications'] === '1' ||
                                        $user_info['email_notifications'] === 1
                                    )
                                );

                            if (
                                !empty($user_info['email']) &&
                                $wants_email
                            ) {

                                send_email_deferred(
                                    $user_info['email'],
                                    'Event Registration Confirmed',
                                    "
                                    <h2>
                                        Hi " .
                                        htmlspecialchars(
                                            $user_info['full_name'] ?? 'Student'
                                        ) .
                                    "!
                                    </h2>

                                    <p>
                                        You have successfully registered for
                                        <strong>" .
                                        htmlspecialchars(
                                            $event['title']
                                        ) .
                                        "</strong>.
                                    </p>

                                    <p>
                                        <strong>Date:</strong>
                                        " .
                                        htmlspecialchars(
                                            $event['event_date']
                                        ) .
                                    "
                                    </p>

                                    <p>
                                        <strong>Time:</strong>
                                        " .
                                        htmlspecialchars(
                                            $event['start_time']
                                        ) .
                                        " -
                                        " .
                                        htmlspecialchars(
                                            $event['end_time']
                                        ) .
                                    "
                                    </p>

                                    <p>
                                        <strong>Venue:</strong>
                                        " .
                                        htmlspecialchars(
                                            $event['venue']
                                        ) .
                                    "
                                    </p>

                                    <p>
                                        Your QR code is now available on the
                                        <strong>My QR Codes</strong> page.
                                    </p>
                                    "
                                );
                            }

                            $message =
                                "Successfully registered for \"" .
                                $event['title'] .
                                "\". Your QR code has been generated.";

                            $message_type = 'success';
                        }
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| VALIDATE MONTH / YEAR
|--------------------------------------------------------------------------
*/

$month = isset($_GET['month'])
    ? (int) $_GET['month']
    : (int) date('n');

$year = isset($_GET['year'])
    ? (int) $_GET['year']
    : (int) date('Y');

if ($month < 1 || $month > 12) {
    $month = (int) date('n');
}

$current_year = (int) date('Y');

if (
    $year < $current_year - 10 ||
    $year > $current_year + 10
) {
    $year = $current_year;
}

/*
|--------------------------------------------------------------------------
| MONTH CALCULATIONS
|--------------------------------------------------------------------------
*/

$prev_month = $month - 1;
$prev_year = $year;

if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year--;
}

$next_month = $month + 1;
$next_year = $year;

if ($next_month > 12) {
    $next_month = 1;
    $next_year++;
}

$first_day_timestamp =
    mktime(0, 0, 0, $month, 1, $year);

$days_in_month =
    (int) date('t', $first_day_timestamp);

$first_day_of_week =
    (int) date('w', $first_day_timestamp);

$month_name =
    date('F', $first_day_timestamp);

$start_date =
    sprintf(
        '%04d-%02d-01',
        $year,
        $month
    );

$end_date =
    date(
        'Y-m-t',
        $first_day_timestamp
    );

/*
|--------------------------------------------------------------------------
| GET APPROVED EVENTS
|--------------------------------------------------------------------------
*/

$result = pg_query_params(
    $conn,
    "SELECT
        event_id,
        title,
        event_date,
        start_time,
        end_time,
        venue,
        registration_limit
     FROM events
     WHERE status = 'approved'
       AND event_date BETWEEN $1 AND $2
     ORDER BY event_date ASC, start_time ASC, title ASC",
    [
        $start_date,
        $end_date
    ]
);

if (!$result) {

    error_log(
        "Calendar query failed: " .
        pg_last_error($conn)
    );

    die("Unable to load the event calendar.");
}

$events_by_day = [];

/*
|--------------------------------------------------------------------------
| PROCESS EVENTS
|--------------------------------------------------------------------------
*/

while ($row = pg_fetch_assoc($result)) {

    /*
    |--------------------------------------------------------------------------
    | REGISTRATION COUNT
    |--------------------------------------------------------------------------
    */

    $registration_query = pg_query_params(
        $conn,
        "SELECT COUNT(*)
         FROM registrations
         WHERE event_id = $1",
        array($row['event_id'])
    );

    $registered_count = (int) pg_fetch_result(
        $registration_query,
        0,
        0
    );

    $registration_limit =
        (int) $row['registration_limit'];

    /*
    |--------------------------------------------------------------------------
    | REGISTRATION PERCENTAGE
    |--------------------------------------------------------------------------
    */

    if ($registration_limit > 0) {

        $registration_percentage =
            min(
                100,
                round(
                    (
                        $registered_count /
                        $registration_limit
                    ) * 100
                )
            );

        $slots_remaining =
            max(
                0,
                $registration_limit -
                $registered_count
            );

    } else {

        $registration_percentage = 0;
        $slots_remaining = null;
    }

    /*
    |--------------------------------------------------------------------------
    | EVENT START / END
    |--------------------------------------------------------------------------
    */

    $event_start = new DateTime(
        $row['event_date'] . ' ' . $row['start_time'],
        $timezone
    );

    $event_end = new DateTime(
        $row['event_date'] . ' ' . $row['end_time'],
        $timezone
    );

    if ($event_end <= $event_start) {
        $event_end->modify('+1 day');
    }

    /*
    |--------------------------------------------------------------------------
    | EVENT STATUS
    |--------------------------------------------------------------------------
    */

    if ($now < $event_start) {

        $event_status = 'upcoming';
        $event_status_label = 'Upcoming';
        $event_status_icon = 'fa-solid fa-clock';

    } elseif (
        $now >= $event_start &&
        $now < $event_end
    ) {

        $event_status = 'happening';
        $event_status_label = 'Happening Now';
        $event_status_icon = 'fa-solid fa-fire';

    } else {

        $event_status = 'completed';
        $event_status_label = 'Completed';
        $event_status_icon = 'fa-solid fa-circle-check';
    }

    /*
    |--------------------------------------------------------------------------
    | REGISTRATION STATUS
    |--------------------------------------------------------------------------
    */

    if (
        $event_status === 'completed' ||
        $event_status === 'happening'
    ) {

        $registration_status = 'closed';
        $registration_label = 'Registration Closed';

    } elseif (
        $registration_limit > 0 &&
        $registered_count >= $registration_limit
    ) {

        $registration_status = 'closed';
        $registration_label = 'Registration Closed';

    } elseif (
        $registration_limit > 0 &&
        $registration_percentage >= 80
    ) {

        $registration_status = 'almost_full';
        $registration_label = 'Almost Full';

    } else {

        $registration_status = 'open';
        $registration_label = 'Registration Open';
    }

    /*
    |--------------------------------------------------------------------------
    | ALREADY REGISTERED
    |--------------------------------------------------------------------------
    */

    $registered_check = pg_query_params(
        $conn,
        "SELECT registration_id
         FROM registrations
         WHERE event_id = $1
           AND user_id = $2
         LIMIT 1",
        array(
            $row['event_id'],
            $student_id
        )
    );

    $is_registered =
        pg_num_rows($registered_check) > 0;

    /*
    |--------------------------------------------------------------------------
    | DAYS UNTIL EVENT
    |--------------------------------------------------------------------------
    */

    $days_until =
        (int) $now->diff($event_start)->format('%r%a');

    /*
    |--------------------------------------------------------------------------
    | EVENT DAY
    |--------------------------------------------------------------------------
    */

    $day =
        (int) date(
            'j',
            strtotime($row['event_date'])
        );

    /*
    |--------------------------------------------------------------------------
    | STORE EVENT DATA
    |--------------------------------------------------------------------------
    */

    $row['registered_count'] =
        $registered_count;

    $row['registration_percentage'] =
        $registration_percentage;

    $row['slots_remaining'] =
        $slots_remaining;

    $row['event_status'] =
        $event_status;

    $row['event_status_label'] =
        $event_status_label;

    $row['event_status_icon'] =
        $event_status_icon;

    $row['registration_status'] =
        $registration_status;

    $row['registration_label'] =
        $registration_label;

    $row['is_registered'] =
        $is_registered;

    $row['days_until'] =
        $days_until;

    $events_by_day[$day][] = $row;
}

/*
|--------------------------------------------------------------------------
| CALENDAR TOTALS
|--------------------------------------------------------------------------
*/

$total_events = 0;
$upcoming_events = 0;
$happening_events = 0;
$completed_events = 0;

foreach ($events_by_day as $day_events) {

    foreach ($day_events as $event) {

        $total_events++;

        if ($event['event_status'] === 'upcoming') {
            $upcoming_events++;
        }

        elseif ($event['event_status'] === 'happening') {
            $happening_events++;
        }

        elseif ($event['event_status'] === 'completed') {
            $completed_events++;
        }
    }
}

/*
|--------------------------------------------------------------------------
| NOTIFICATION DATA (for the shared header bell)
|--------------------------------------------------------------------------
*/

$unread_count = (int) pg_fetch_result(
    pg_query_params(
        $conn,
        "SELECT COUNT(*)
         FROM notifications
         WHERE user_id = $1
           AND is_read = false",
        array($student_id)
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
    array($student_id)
);

/*
|--------------------------------------------------------------------------
| PAGE VARIABLES (for the shared partials)
|--------------------------------------------------------------------------
*/

$role        = 'student';
$first_name  = explode(' ', trim($full_name))[0];

$page_title  = 'Event Calendar — RMC Events';
$active_page = 'calendar';

?>



<?php include 'partials/head.php'; ?>

<style>

/* =========================================================
   CALENDAR CELLS
   ========================================================= */

.calendar-cell {
    min-height: 150px;
}

.event-button {
    transition:
        transform .18s ease,
        box-shadow .18s ease,
        background-color .18s ease;
}

.event-button:hover {
    transform: translateY(-2px);
}

/* =========================================================
   MODAL ANIMATION
   ========================================================= */

.modal-animation {
    animation: modalIn .2s ease-out;
}

@keyframes modalIn {

    from {
        opacity: 0;
        transform: translateY(15px) scale(.97);
    }

    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }

}

/* =========================================================
   RESPONSIVE CALENDAR
   ========================================================= */

@media (max-width: 768px) {

    .calendar-cell {
        min-height: 105px;
    }

    .calendar-event-title {
        font-size: 10px;
    }

    .calendar-event-time {
        display: none;
    }

}

@media (max-width: 480px) {

    .calendar-cell {
        min-height: 82px;
        padding: 5px !important;
    }

    .calendar-weekday {
        font-size: 9px;
        padding-top: 10px;
        padding-bottom: 10px;
    }

    .calendar-day-number {
        width: 26px !important;
        height: 26px !important;
        font-size: 11px;
    }

    .today-label {
        display: none;
    }

    .event-button {
        padding: 5px !important;
        margin-top: 4px !important;
        border-radius: 7px !important;
    }

    .event-button i {
        display: none;
    }

    .calendar-event-title {
        font-size: 9px;
        line-height: 1.2;
    }

    .calendar-event-status {
        font-size: 8px;
        margin-top: 2px;
    }

}

</style>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     PAGE HEADING
     ========================================================= -->

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6 lg:mb-8">

    <div class="flex items-center gap-3">

        <div class="w-11 h-11 rounded-xl bg-rmc-800 text-white flex items-center justify-center shrink-0">

            <i class="fa-solid fa-calendar"></i>

        </div>

        <div>

            <h1 class="text-xl sm:text-2xl font-bold text-slate-900">

                <?= t('calendar'); ?>

            </h1>

            <p class="text-sm text-slate-500 mt-1">

                Explore approved campus events.

            </p>

        </div>

    </div>

</div>


<!-- =========================================================
     HERO
     ========================================================= -->

<section
    class="relative overflow-hidden rounded-[26px] border border-rmc-100 bg-gradient-to-br from-rmc-50 via-white to-rmc-100 p-6 sm:p-8 lg:p-9 mb-6 lg:mb-8"
>

    <div
        class="absolute -right-16 -top-16 w-48 h-48 rounded-full bg-rmc-200/40 blur-3xl pointer-events-none"
    ></div>

    <div
        class="absolute -right-8 -bottom-20 w-56 h-56 rounded-full bg-rmc-100/50 blur-3xl pointer-events-none"
    ></div>


    <div class="relative">

        <div class="max-w-3xl">

            <div
                class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-rmc-800 text-white text-xs font-semibold mb-4"
            >

                <i class="fa-solid fa-calendar-days"></i>

                EVENT CALENDAR

            </div>


            <h2
                class="text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight text-slate-900"
            >

                Academic Event Calendar

            </h2>


            <p
                class="mt-2 text-sm sm:text-base text-slate-600 max-w-2xl"
            >

                Stay updated with approved campus activities, registration
                availability, and upcoming events.

            </p>


            <div class="mt-6 flex flex-wrap gap-2">

                <span
                    class="inline-flex items-center gap-2 bg-white border border-rmc-100 px-3 py-2 rounded-full text-xs sm:text-sm text-slate-700"
                >

                    <span class="w-2.5 h-2.5 rounded-full bg-rmc-600"></span>

                    Upcoming

                </span>


                <span
                    class="inline-flex items-center gap-2 bg-white border border-rmc-100 px-3 py-2 rounded-full text-xs sm:text-sm text-slate-700"
                >

                    <span class="w-2.5 h-2.5 rounded-full bg-red-500"></span>

                    Happening Now

                </span>


                <span
                    class="inline-flex items-center gap-2 bg-white border border-rmc-100 px-3 py-2 rounded-full text-xs sm:text-sm text-slate-700"
                >

                    <span class="w-2.5 h-2.5 rounded-full bg-slate-400"></span>

                    Completed

                </span>

            </div>

        </div>

    </div>

</section>


<!-- =========================================================
     MESSAGE
     ========================================================= -->

<?php if (!empty($message)): ?>

    <?php if ($message_type === 'success'): ?>

        <div
            class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-green-50 border border-green-200 text-green-800 rounded-2xl px-5 py-4 shadow-sm"
        >

            <div class="flex items-start gap-3">

                <div
                    class="w-9 h-9 rounded-full bg-green-100 flex items-center justify-center shrink-0"
                >

                    <i class="fa-solid fa-circle-check text-green-600"></i>

                </div>

                <div>

                    <p class="font-semibold">
                        Registration successful
                    </p>

                    <p class="text-sm mt-0.5">
                        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                    </p>

                </div>

            </div>


            <a
                href="my_qr.php"
                class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-rmc-800 hover:bg-rmc-900 text-white text-sm font-bold transition"
            >

                <i class="fa-solid fa-qrcode"></i>

                View My QR

            </a>

        </div>

    <?php else: ?>

        <div
            class="mb-6 flex items-start gap-3 bg-red-50 border border-red-200 text-red-800 rounded-2xl px-5 py-4 shadow-sm"
        >

            <div
                class="w-9 h-9 rounded-full bg-red-100 flex items-center justify-center shrink-0"
            >

                <i class="fa-solid fa-circle-exclamation text-red-600"></i>

            </div>

            <div>

                <p class="font-semibold">
                    Unable to register
                </p>

                <p class="text-sm mt-0.5">
                    <?= htmlspecialchars($message); ?>
                </p>

            </div>

        </div>

    <?php endif; ?>

<?php endif; ?>


<!-- =========================================================
     CALENDAR SUMMARY
     ========================================================= -->

<div
    class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6"
>

    <div
        class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4"
    >

        <div class="flex items-center justify-between">

            <div>

                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide">
                    Total Events
                </p>

                <p class="text-2xl font-extrabold text-slate-800 mt-1">
                    <?= $total_events; ?>
                </p>

            </div>

            <div
                class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center"
            >

                <i class="fa-solid fa-calendar-days"></i>

            </div>

        </div>

    </div>


    <div
        class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4"
    >

        <div class="flex items-center justify-between">

            <div>

                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide">
                    Upcoming
                </p>

                <p class="text-2xl font-extrabold text-rmc-800 mt-1">
                    <?= $upcoming_events; ?>
                </p>

            </div>

            <div
                class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center"
            >

                <i class="fa-solid fa-clock"></i>

            </div>

        </div>

    </div>


    <div
        class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4"
    >

        <div class="flex items-center justify-between">

            <div>

                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide">
                    Happening
                </p>

                <p class="text-2xl font-extrabold text-red-600 mt-1">
                    <?= $happening_events; ?>
                </p>

            </div>

            <div
                class="w-10 h-10 rounded-xl bg-red-100 text-red-600 flex items-center justify-center"
            >

                <i class="fa-solid fa-fire"></i>

            </div>

        </div>

    </div>


    <div
        class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4"
    >

        <div class="flex items-center justify-between">

            <div>

                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide">
                    Completed
                </p>

                <p class="text-2xl font-extrabold text-slate-600 mt-1">
                    <?= $completed_events; ?>
                </p>

            </div>

            <div
                class="w-10 h-10 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center"
            >

                <i class="fa-solid fa-circle-check"></i>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     CALENDAR CARD
     ========================================================= -->

<section
    class="bg-white rounded-[26px] shadow-sm border border-slate-200 overflow-hidden"
>


<!-- =========================================================
     MONTH HEADER
     ========================================================= -->

<div
    class="p-4 sm:p-6 border-b border-slate-200"
>

    <div
        class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4"
    >


        <div>

            <p
                class="text-xs font-bold uppercase tracking-wider text-rmc-800"
            >
                Event Schedule
            </p>

            <h2
                class="text-2xl sm:text-3xl font-extrabold text-slate-900 mt-1"
            >

                <?= htmlspecialchars($month_name); ?>

                <?= $year; ?>

            </h2>

            <p class="text-xs sm:text-sm text-slate-500 mt-1">
                Select an event to view details and registration status.
            </p>

        </div>


        <div
            class="flex items-center gap-2"
        >

            <a
                href="calendar.php?month=<?= $prev_month; ?>&year=<?= $prev_year; ?>"
                class="flex-1 sm:flex-none inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-rmc-200 bg-white hover:bg-rmc-50 text-rmc-800 text-sm font-bold transition"
            >

                <i class="fa-solid fa-chevron-left"></i>

                <span class="hidden sm:inline">
                    Previous
                </span>

            </a>


            <a
                href="calendar.php"
                class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl bg-rmc-800 hover:bg-rmc-900 text-white text-sm font-bold transition"
            >

                Today

            </a>


            <a
                href="calendar.php?month=<?= $next_month; ?>&year=<?= $next_year; ?>"
                class="flex-1 sm:flex-none inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-rmc-200 bg-white hover:bg-rmc-50 text-rmc-800 text-sm font-bold transition"
            >

                <span class="hidden sm:inline">
                    Next
                </span>

                <i class="fa-solid fa-chevron-right"></i>

            </a>

        </div>

    </div>

</div>


<!-- =========================================================
     WEEKDAYS
     ========================================================= -->

<div
    class="grid grid-cols-7 bg-rmc-800 text-white"
>

    <div class="calendar-weekday py-3 sm:py-4 text-center text-[10px] sm:text-xs font-bold uppercase tracking-wide">
        Sun
    </div>

    <div class="calendar-weekday py-3 sm:py-4 text-center text-[10px] sm:text-xs font-bold uppercase tracking-wide">
        Mon
    </div>

    <div class="calendar-weekday py-3 sm:py-4 text-center text-[10px] sm:text-xs font-bold uppercase tracking-wide">
        Tue
    </div>

    <div class="calendar-weekday py-3 sm:py-4 text-center text-[10px] sm:text-xs font-bold uppercase tracking-wide">
        Wed
    </div>

    <div class="calendar-weekday py-3 sm:py-4 text-center text-[10px] sm:text-xs font-bold uppercase tracking-wide">
        Thu
    </div>

    <div class="calendar-weekday py-3 sm:py-4 text-center text-[10px] sm:text-xs font-bold uppercase tracking-wide">
        Fri
    </div>

    <div class="calendar-weekday py-3 sm:py-4 text-center text-[10px] sm:text-xs font-bold uppercase tracking-wide">
        Sat
    </div>

</div>


<!-- =========================================================
     CALENDAR GRID
     ========================================================= -->

<div class="grid grid-cols-7">


<?php for (
    $i = 0;
    $i < $first_day_of_week;
    $i++
): ?>

    <div
        class="calendar-cell border-r border-b border-slate-200 bg-slate-50/70"
    ></div>

<?php endfor; ?>


<?php for (
    $day = 1;
    $day <= $days_in_month;
    $day++
): ?>


    <?php

    $cell_date =
        sprintf(
            '%04d-%02d-%02d',
            $year,
            $month,
            $day
        );

    $is_today =
        $cell_date === $now->format('Y-m-d');

    $day_events =
        $events_by_day[$day] ?? [];

    ?>


    <div
        class="
            calendar-cell
            border-r
            border-b
            border-slate-200
            p-2
            sm:p-3
            transition
            overflow-hidden
            <?= $is_today
                ? 'bg-rmc-50/70 ring-2 ring-inset ring-rmc-300'
                : 'bg-white hover:bg-slate-50'
            ?>
        "
    >


        <!-- DAY HEADER -->

        <div
            class="flex items-center justify-between mb-1"
        >

            <div
                class="
                    calendar-day-number
                    font-bold
                    text-sm
                    <?= $is_today
                        ? 'bg-rmc-800 text-white rounded-full w-8 h-8 flex items-center justify-center shadow-sm'
                        : 'text-slate-700'
                    ?>
                "
            >

                <?= $day; ?>

            </div>


            <?php if ($is_today): ?>

                <span
                    class="today-label text-[9px] font-extrabold text-rmc-700 uppercase"
                >
                    Today
                </span>

            <?php endif; ?>

        </div>


        <!-- EVENT COUNT -->

        <?php if (count($day_events) > 0): ?>

            <div
                class="text-[9px] font-semibold text-slate-400 mb-1"
            >

                <?= count($day_events); ?>

                <?= count($day_events) === 1 ? 'event' : 'events'; ?>

            </div>

        <?php endif; ?>


        <!-- EVENTS -->

        <?php foreach ($day_events as $event): ?>


            <?php

            $event_json = json_encode(
                [
                    'event_id' =>
                        (int) $event['event_id'],

                    'title' =>
                        $event['title'],

                    'event_date' =>
                        $event['event_date'],

                    'start_time' =>
                        $event['start_time'],

                    'end_time' =>
                        $event['end_time'],

                    'venue' =>
                        $event['venue'],

                    'registration_limit' =>
                        (int) $event['registration_limit'],

                    'registered_count' =>
                        (int) $event['registered_count'],

                    'registration_percentage' =>
                        (int) $event['registration_percentage'],

                    'slots_remaining' =>
                        $event['slots_remaining'],

                    'event_status' =>
                        $event['event_status'],

                    'event_status_label' =>
                        $event['event_status_label'],

                    'registration_status' =>
                        $event['registration_status'],

                    'registration_label' =>
                        $event['registration_label'],

                    'is_registered' =>
                        $event['is_registered'],

                    'days_until' =>
                        $event['days_until']
                ],
                JSON_HEX_TAG |
                JSON_HEX_APOS |
                JSON_HEX_AMP |
                JSON_HEX_QUOT
            );


            if ($event['event_status'] === 'upcoming') {

                $event_classes =
                    'bg-rmc-50 border-rmc-200 text-rmc-800';

                $status_dot =
                    'bg-rmc-600';

                $status_text =
                    'Upcoming';

            } elseif ($event['event_status'] === 'happening') {

                $event_classes =
                    'bg-red-50 border-red-200 text-red-800';

                $status_dot =
                    'bg-red-500';

                $status_text =
                    'Live';

            } else {

                $event_classes =
                    'bg-slate-100 border-slate-200 text-slate-600';

                $status_dot =
                    'bg-slate-400';

                $status_text =
                    'Completed';
            }

            ?>


            <button
                type="button"
                class="
                    event-button
                    w-full
                    text-left
                    block
                    mt-2
                    rounded-xl
                    px-2
                    py-2
                    border
                    <?= $event_classes; ?>
                "
                data-event="<?= htmlspecialchars($event_json, ENT_QUOTES, 'UTF-8'); ?>"
            >

                <div
                    class="calendar-event-title font-bold truncate flex items-center gap-1.5"
                >

                    <span
                        class="w-1.5 h-1.5 rounded-full <?= $status_dot; ?> shrink-0"
                    ></span>

                    <span class="truncate">
                        <?= htmlspecialchars(
                            $event['title'],
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>
                    </span>

                </div>


                <div
                    class="calendar-event-time text-[9px] mt-1 opacity-70 truncate"
                >

                    <i class="fa-regular fa-clock mr-1"></i>

                    <?= htmlspecialchars(
                        date(
                            'g:i A',
                            strtotime($event['start_time'])
                        )
                    ); ?>

                </div>


                <div
                    class="calendar-event-status text-[9px] font-bold mt-1"
                >

                    <?= $status_text; ?>

                    <?php if ($event['is_registered']): ?>

                        <span class="ml-1 text-green-600">
                            • Registered
                        </span>

                    <?php endif; ?>

                </div>

            </button>


        <?php endforeach; ?>


    </div>


<?php endfor; ?>


</div>


<!-- =========================================================
     CALENDAR FOOTER
     ========================================================= -->

<div
    class="px-4 sm:px-6 py-4 border-t border-slate-200 bg-slate-50"
>

    <div
        class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
    >

        <p class="text-xs sm:text-sm text-slate-500">

            <i class="fa-solid fa-circle-info text-rmc-600 mr-1"></i>

            Click any event to view its details.

        </p>


        <a
            href="events.php"
            class="inline-flex items-center gap-2 text-sm font-bold text-rmc-800 hover:text-rmc-900"
        >

            Browse all events

            <i class="fa-solid fa-arrow-right"></i>

        </a>

    </div>

</div>


</section>


<!-- =========================================================
     EVENT MODAL
     ========================================================= -->

<div
    id="eventModal"
    class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-[100] hidden items-center justify-center p-3 sm:p-5"
>


    <div
        id="eventModalContent"
        class="modal-animation bg-white w-full max-w-xl max-h-[94vh] overflow-y-auto rounded-3xl shadow-2xl"
    >


        <!-- MODAL HEADER -->

        <div
            class="relative bg-gradient-to-br from-rmc-700 via-rmc-800 to-rmc-900 text-white p-6 sm:p-7"
        >

            <div
                class="absolute right-0 top-0 w-32 h-32 rounded-full bg-white/10 -translate-y-1/2 translate-x-1/2"
            ></div>


            <div class="relative flex justify-between items-start gap-4">

                <div class="min-w-0">

                    <p
                        id="modalStatus"
                        class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-bold bg-white/15 border border-white/20 mb-3"
                    ></p>


                    <h2
                        id="modalTitle"
                        class="text-2xl sm:text-3xl font-extrabold break-words"
                    ></h2>

                </div>


                <button
                    type="button"
                    onclick="closeEventModal()"
                    class="w-10 h-10 rounded-full bg-white/15 hover:bg-white/25 flex items-center justify-center text-lg shrink-0 transition"
                >

                    <i class="fa-solid fa-xmark"></i>

                </button>

            </div>

        </div>


        <!-- MODAL BODY -->

        <div class="p-5 sm:p-7">


            <!-- EVENT INFORMATION -->

            <div class="space-y-4">


                <div
                    class="flex items-center gap-4"
                >

                    <div
                        class="w-11 h-11 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center shrink-0"
                    >

                        <i class="fa-solid fa-calendar"></i>

                    </div>

                    <div class="min-w-0">

                        <p class="text-xs text-slate-400 font-medium">
                            Event Date
                        </p>

                        <p
                            id="modalDate"
                            class="font-bold text-slate-800"
                        ></p>

                    </div>

                </div>


                <div
                    class="flex items-center gap-4"
                >

                    <div
                        class="w-11 h-11 rounded-xl bg-rmc-100 text-rmc-800 flex items-center justify-center shrink-0"
                    >

                        <i class="fa-solid fa-clock"></i>

                    </div>

                    <div class="min-w-0">

                        <p class="text-xs text-slate-400 font-medium">
                            Event Time
                        </p>

                        <p
                            id="modalTime"
                            class="font-bold text-slate-800"
                        ></p>

                    </div>

                </div>


                <div
                    class="flex items-center gap-4"
                >

                    <div
                        class="w-11 h-11 rounded-xl bg-red-100 text-red-700 flex items-center justify-center shrink-0"
                    >

                        <i class="fa-solid fa-location-dot"></i>

                    </div>

                    <div class="min-w-0">

                        <p class="text-xs text-slate-400 font-medium">
                            Venue
                        </p>

                        <p
                            id="modalVenue"
                            class="font-bold text-slate-800 break-words"
                        ></p>

                    </div>

                </div>

            </div>


            <!-- REGISTRATION -->

            <div
                class="mt-6 bg-slate-50 border border-slate-200 rounded-2xl p-5"
            >

                <div
                    class="flex justify-between items-center mb-3 gap-3"
                >

                    <div>

                        <p class="text-sm font-extrabold text-slate-700">

                            <i class="fa-solid fa-users text-rmc-700 mr-1"></i>

                            Registration

                        </p>

                    </div>


                    <p
                        id="modalRegistrationPercentage"
                        class="font-extrabold"
                    ></p>

                </div>


                <div
                    class="flex justify-between text-xs sm:text-sm mb-2 gap-3"
                >

                    <span
                        id="modalRegistrationCount"
                        class="font-bold text-slate-700"
                    ></span>

                    <span
                        id="modalSlots"
                        class="text-slate-500 text-right"
                    ></span>

                </div>


                <div
                    class="w-full h-2.5 bg-slate-200 rounded-full overflow-hidden"
                >

                    <div
                        id="modalProgressBar"
                        class="h-full rounded-full transition-all duration-500"
                        style="width: 0%"
                    ></div>

                </div>

            </div>


            <!-- REGISTRATION STATUS -->

            <div
                id="modalRegistrationStatus"
                class="mt-5 rounded-2xl p-4 text-center font-bold border"
            ></div>


            <!-- BUTTONS -->

            <div
                class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-3"
            >

                <a
                    id="modalViewEvent"
                    href="#"
                    class="flex items-center justify-center gap-2 py-3 rounded-xl border border-rmc-200 text-rmc-800 font-bold hover:bg-rmc-50 transition"
                >

                    <i class="fa-solid fa-eye"></i>

                    View Event

                </a>


                <form
                    id="modalRegisterForm"
                    method="POST"
                >

                    <?= csrf_field(); ?>


                    <input
                        type="hidden"
                        name="register_event_id"
                        id="modalRegisterEventId"
                    >


                    <button
                        type="submit"
                        id="modalRegisterButton"
                        class="w-full py-3 rounded-xl bg-rmc-800 hover:bg-rmc-900 text-white font-bold transition"
                    >

                        <i class="fa-solid fa-user-plus mr-2"></i>

                        Register Now

                    </button>

                </form>

            </div>

        </div>

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| EVENT MODAL ELEMENTS
|--------------------------------------------------------------------------
*/

const eventModal =
    document.getElementById('eventModal');

const modalTitle =
    document.getElementById('modalTitle');

const modalStatus =
    document.getElementById('modalStatus');

const modalDate =
    document.getElementById('modalDate');

const modalTime =
    document.getElementById('modalTime');

const modalVenue =
    document.getElementById('modalVenue');

const modalRegistrationCount =
    document.getElementById('modalRegistrationCount');

const modalRegistrationPercentage =
    document.getElementById('modalRegistrationPercentage');

const modalSlots =
    document.getElementById('modalSlots');

const modalProgressBar =
    document.getElementById('modalProgressBar');

const modalRegistrationStatus =
    document.getElementById('modalRegistrationStatus');

const modalRegisterEventId =
    document.getElementById('modalRegisterEventId');

const modalRegisterButton =
    document.getElementById('modalRegisterButton');

const modalViewEvent =
    document.getElementById('modalViewEvent');


/*
|--------------------------------------------------------------------------
| FORMAT DATE
|--------------------------------------------------------------------------
*/

function formatEventDate(dateString) {

    const date =
        new Date(dateString + 'T00:00:00');

    return date.toLocaleDateString(
        'en-US',
        {
            month: 'long',
            day: 'numeric',
            year: 'numeric'
        }
    );

}


/*
|--------------------------------------------------------------------------
| FORMAT TIME
|--------------------------------------------------------------------------
*/

function formatEventTime(timeString) {

    const parts =
        timeString.split(':');

    if (parts.length < 2) {
        return timeString;
    }

    let hour =
        parseInt(parts[0], 10);

    const minute =
        parts[1];

    const suffix =
        hour >= 12 ? 'PM' : 'AM';

    hour =
        hour % 12 || 12;

    return hour + ':' + minute + ' ' + suffix;

}


/*
|--------------------------------------------------------------------------
| OPEN EVENT MODAL
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll('.event-button')
    .forEach(button => {

        button.addEventListener('click', function () {

            let event;

            try {

                event =
                    JSON.parse(
                        this.getAttribute('data-event')
                    );

            } catch (error) {

                console.error(
                    'Unable to load event information.',
                    error
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | BASIC INFORMATION
            |--------------------------------------------------------------------------
            */

            modalTitle.textContent =
                event.title;

            modalDate.textContent =
                formatEventDate(
                    event.event_date
                );

            modalTime.textContent =
                formatEventTime(
                    event.start_time
                ) +
                ' - ' +
                formatEventTime(
                    event.end_time
                );

            modalVenue.textContent =
                event.venue;


            /*
            |--------------------------------------------------------------------------
            | EVENT STATUS
            |--------------------------------------------------------------------------
            */

            if (event.event_status === 'upcoming') {

                modalStatus.innerHTML =
                    '<i class="fa-solid fa-clock mr-1"></i> UPCOMING';

                modalStatus.className =
                    'inline-flex items-center px-3 py-1.5 rounded-full text-xs font-bold bg-white/15 border border-white/20';


            } else if (
                event.event_status === 'happening'
            ) {

                modalStatus.innerHTML =
                    '<i class="fa-solid fa-fire mr-1"></i> HAPPENING NOW';

                modalStatus.className =
                    'inline-flex items-center px-3 py-1.5 rounded-full text-xs font-bold bg-red-500/30 border border-red-200/30';


            } else {

                modalStatus.innerHTML =
                    '<i class="fa-solid fa-circle-check mr-1"></i> COMPLETED';

                modalStatus.className =
                    'inline-flex items-center px-3 py-1.5 rounded-full text-xs font-bold bg-slate-500/30 border border-white/20';

            }


            /*
            |--------------------------------------------------------------------------
            | REGISTRATION INFORMATION
            |--------------------------------------------------------------------------
            */

            modalRegistrationPercentage.textContent =
                event.registration_percentage + '%';


            if (event.registration_limit > 0) {

                modalRegistrationCount.textContent =
                    event.registered_count +
                    ' / ' +
                    event.registration_limit +
                    ' registered';

            } else {

                modalRegistrationCount.textContent =
                    event.registered_count +
                    ' registered';

            }


            if (
                event.registration_limit > 0
            ) {

                modalSlots.textContent =
                    event.slots_remaining +
                    (
                        event.slots_remaining == 1
                            ? ' slot remaining'
                            : ' slots remaining'
                    );

            } else {

                modalSlots.textContent =
                    'No registration limit';

            }


            modalProgressBar.style.width =
                event.registration_percentage + '%';


            /*
            |--------------------------------------------------------------------------
            | PROGRESS BAR COLOR
            |--------------------------------------------------------------------------
            */

            if (
                event.registration_percentage >= 100
            ) {

                modalProgressBar.className =
                    'h-full rounded-full transition-all duration-500 bg-red-600';

                modalRegistrationPercentage.className =
                    'font-extrabold text-red-600';

            }

            else if (
                event.registration_percentage >= 80
            ) {

                modalProgressBar.className =
                    'h-full rounded-full transition-all duration-500 bg-yellow-500';

                modalRegistrationPercentage.className =
                    'font-extrabold text-yellow-600';

            }

            else {

                modalProgressBar.className =
                    'h-full rounded-full transition-all duration-500 bg-green-500';

                modalRegistrationPercentage.className =
                    'font-extrabold text-green-600';

            }


            /*
            |--------------------------------------------------------------------------
            | REGISTRATION STATUS
            |--------------------------------------------------------------------------
            */

            if (event.is_registered) {

                modalRegistrationStatus.innerHTML =
                    '<i class="fa-solid fa-circle-check mr-2"></i>' +
                    'Already Registered';

                modalRegistrationStatus.className =
                    'mt-5 rounded-2xl p-4 text-center font-bold border bg-green-50 text-green-700 border-green-200';


                modalRegisterButton.disabled = true;

                modalRegisterButton.className =
                    'w-full py-3 rounded-xl bg-slate-200 text-slate-400 font-bold cursor-not-allowed';

                modalRegisterButton.innerHTML =
                    '<i class="fa-solid fa-check mr-2"></i> Already Registered';


            } else if (
                event.registration_status === 'closed'
            ) {

                modalRegistrationStatus.innerHTML =
                    '<i class="fa-solid fa-lock mr-2"></i>' +
                    'Registration Closed';

                modalRegistrationStatus.className =
                    'mt-5 rounded-2xl p-4 text-center font-bold border bg-red-50 text-red-700 border-red-200';


                modalRegisterButton.disabled = true;

                modalRegisterButton.className =
                    'w-full py-3 rounded-xl bg-slate-200 text-slate-400 font-bold cursor-not-allowed';

                modalRegisterButton.innerHTML =
                    '<i class="fa-solid fa-lock mr-2"></i> Registration Closed';


            } else if (
                event.registration_status === 'almost_full'
            ) {

                modalRegistrationStatus.innerHTML =
                    '<i class="fa-solid fa-triangle-exclamation mr-2"></i>' +
                    'Almost Full';

                modalRegistrationStatus.className =
                    'mt-5 rounded-2xl p-4 text-center font-bold border bg-yellow-50 text-yellow-700 border-yellow-200';


                modalRegisterButton.disabled = false;

                modalRegisterButton.className =
                    'w-full py-3 rounded-xl bg-rmc-800 hover:bg-rmc-900 text-white font-bold transition';

                modalRegisterButton.innerHTML =
                    '<i class="fa-solid fa-user-plus mr-2"></i> Register Now';


            } else {

                modalRegistrationStatus.innerHTML =
                    '<i class="fa-solid fa-circle-check mr-2"></i>' +
                    'Registration Open';

                modalRegistrationStatus.className =
                    'mt-5 rounded-2xl p-4 text-center font-bold border bg-green-50 text-green-700 border-green-200';


                modalRegisterButton.disabled = false;

                modalRegisterButton.className =
                    'w-full py-3 rounded-xl bg-rmc-800 hover:bg-rmc-900 text-white font-bold transition';

                modalRegisterButton.innerHTML =
                    '<i class="fa-solid fa-user-plus mr-2"></i> Register Now';

            }


            /*
            |--------------------------------------------------------------------------
            | REGISTER FORM
            |--------------------------------------------------------------------------
            */

            modalRegisterEventId.value =
                event.event_id;


            /*
            |--------------------------------------------------------------------------
            | VIEW EVENT
            |--------------------------------------------------------------------------
            */

            modalViewEvent.href =
                'events.php?event_id=' +
                encodeURIComponent(
                    event.event_id
                );


            /*
            |--------------------------------------------------------------------------
            | SHOW MODAL
            |--------------------------------------------------------------------------
            */

            eventModal.classList.remove('hidden');

            eventModal.classList.add('flex');

            document.body.classList.add('overflow-hidden');

        });

    });


/*
|--------------------------------------------------------------------------
| CLOSE EVENT MODAL
|--------------------------------------------------------------------------
*/

function closeEventModal() {

    eventModal.classList.add('hidden');

    eventModal.classList.remove('flex');

    document.body.classList.remove('overflow-hidden');

}


/*
|--------------------------------------------------------------------------
| CLICK OUTSIDE MODAL
|--------------------------------------------------------------------------
*/

eventModal.addEventListener(
    'click',
    function (event) {

        if (
            event.target === eventModal
        ) {

            closeEventModal();

        }

    }
);


/*
|--------------------------------------------------------------------------
| ESC KEY
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'keydown',
    function (event) {

        if (event.key === 'Escape') {

            closeEventModal();

            closeMobileMenu();

        }

    }
);


/*
|--------------------------------------------------------------------------
| PREVENT MODAL FORM DOUBLE SUBMISSION
|--------------------------------------------------------------------------
*/

const modalRegisterForm =
    document.getElementById('modalRegisterForm');

modalRegisterForm.addEventListener(
    'submit',
    function () {

        if (
            modalRegisterButton.disabled
        ) {

            return;

        }

        modalRegisterButton.disabled = true;

        modalRegisterButton.innerHTML =
            '<i class="fa-solid fa-spinner fa-spin mr-2"></i>' +
            ' Registering...';

    }
);

</script>
<?php include 'partials/footer.php'; ?>