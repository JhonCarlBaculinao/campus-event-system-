<?php

session_start();

require 'db_connect.php';
require 'send_email.php';
require 'lang.php';
require 'csrf.php';


/* =========================================================
   STUDENT ROLE PROTECTION
   ========================================================= */

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'student'
) {
    http_response_code(403);
    die("Access denied. Students only.");
}


$student_id = $_SESSION['user_id'];


/* =========================================================
   HANDLE EVENT REGISTRATION
   ========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['register_event_id'])) {

    csrf_verify();

    $event_id = trim($_POST['register_event_id']);


    /* ---------------------------------------------------------
       Check if event exists and is approved
       --------------------------------------------------------- */

    $event_check_result = pg_query_params(
        $conn,
        "SELECT
            event_id,
            title,
            registration_limit,
            event_date,
            start_time,
            end_time,
            status
         FROM events
         WHERE event_id = $1",
        array($event_id)
    );


    $event_check = pg_fetch_assoc($event_check_result);


    if (!$event_check) {

        $message = "The selected event could not be found.";
        $message_type = "error";

    }

    elseif ($event_check['status'] !== 'approved') {

        $message = "This event is no longer available for registration.";
        $message_type = "error";

    }

    else {

        /*
         * Build the complete event date/time.
         */

        $event_start = new DateTime(
            $event_check['event_date'] . ' ' . $event_check['start_time']
        );

        $event_end = new DateTime(
            $event_check['event_date'] . ' ' . $event_check['end_time']
        );

        $now = new DateTime();


        /* -----------------------------------------------------
           Do not allow registration for completed events
           ----------------------------------------------------- */

        if ($now >= $event_end) {

            $message = "Registration is closed because this event has already ended.";
            $message_type = "error";

        }

        elseif ($now >= $event_start && $now < $event_end) {

            $message = "Registration is closed because this event is currently happening.";
            $message_type = "error";

        }

        else {

            /* -------------------------------------------------
               Check existing registration
               ------------------------------------------------- */

            $check = pg_query_params(
                $conn,
                "SELECT registration_id
                 FROM registrations
                 WHERE event_id = $1
                 AND user_id = $2
                 AND status = 'registered'",
                array($event_id, $student_id)
            );


            if (pg_num_rows($check) > 0) {

                $message = "You already registered for this event.";
                $message_type = "warning";

            }

            else {

                /* ---------------------------------------------
                   Check registration capacity
                   --------------------------------------------- */

                $count_result = pg_query_params(
                    $conn,
                    "SELECT COUNT(*)
                     FROM registrations
                     WHERE event_id = $1
                     AND status = 'registered'",
                    array($event_id)
                );


                $current_count = (int) pg_fetch_result(
                    $count_result,
                    0,
                    0
                );


                $registration_limit = (int) $event_check['registration_limit'];


                if (
                    $registration_limit > 0 &&
                    $current_count >= $registration_limit
                ) {

                    $message = "Sorry, this event has reached its registration limit.";
                    $message_type = "error";

                }

                else {

                    /* -----------------------------------------
                       Generate QR code
                       ----------------------------------------- */

                    $qr_code = bin2hex(random_bytes(16));


                    $insert_result = pg_query_params(
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


                    if ($insert_result) {

                        $message =
                            "Successfully registered! Your QR code has been generated.";

                        $message_type = "success";


                        /* -------------------------------------
                           Notification
                           ------------------------------------- */

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
                                "You have successfully registered for \"" .
                                $event_check['title'] .
                                "\"."
                            )
                        );


                        /* -------------------------------------
                           Email confirmation
                           ------------------------------------- */

                        $user_info_result = pg_query_params(
                            $conn,
                            "SELECT
                                email,
                                full_name,
                                email_notifications
                             FROM users
                             WHERE user_id = $1",
                            array($student_id)
                        );


                        $user_info = pg_fetch_assoc(
                            $user_info_result
                        );


                        $wants_email =
                            isset($user_info['email_notifications']) &&
                            (
                                $user_info['email_notifications'] === 't' ||
                                $user_info['email_notifications'] === true
                            );


                        if (
                            !empty($user_info['email']) &&
                            $wants_email
                        ) {

                            send_email_deferred(

                                $user_info['email'],

                                'Event Registration Confirmed',

                                "<h2>Hi " .
                                htmlspecialchars(
                                    $user_info['full_name']
                                ) .
                                "!</h2>

                                <p>
                                You have successfully registered for
                                <strong>" .
                                htmlspecialchars(
                                    $event_check['title']
                                ) .
                                "</strong>.
                                </p>

                                <p>
                                Your QR code is now available on the
                                <strong>My QR Codes</strong> page.
                                </p>"

                            );
                        }

                    }

                    else {

                        $message =
                            "Registration failed. Please try again.";

                        $message_type = "error";
                    }
                }
            }
        }
    }
}


/* =========================================================
   SEARCH AND FILTER
   ========================================================= */

$search = isset($_GET['search'])
    ? trim($_GET['search'])
    : '';

$category_filter = isset($_GET['category'])
    ? trim($_GET['category'])
    : '';


/* =========================================================
   GET APPROVED EVENTS
   ========================================================= */

$sql = "
    SELECT
        e.*,

        COUNT(
            r.registration_id
        ) FILTER (
            WHERE r.status = 'registered'
        ) AS registered_count,

        CASE
            WHEN EXISTS (
                SELECT 1
                FROM registrations sr
                WHERE sr.event_id = e.event_id
                AND sr.user_id = $1
            )
            THEN TRUE
            ELSE FALSE
        END AS is_registered

    FROM events e

    LEFT JOIN registrations r
        ON e.event_id = r.event_id

    WHERE e.status = 'approved'
";

$params = array($student_id);

$param_number = 2;


/* Search */

if (!empty($search)) {

    $sql .= "
        AND e.title ILIKE $" .
        $param_number;

    $params[] = "%" . $search . "%";

    $param_number++;
}


/* Category */

if (!empty($category_filter)) {

    $sql .= "
        AND e.category = $" .
        $param_number;

    $params[] = $category_filter;

    $param_number++;
}


$sql .= "
    GROUP BY e.event_id
    ORDER BY e.event_date ASC, e.start_time ASC
";


$events = pg_query_params(
    $conn,
    $sql,
    $params
);


if (!$events) {

    die("Unable to load events.");
}


/* =========================================================
   NOTIFICATION DATA (for the shared header bell)
   ========================================================= */

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


/* =========================================================
   PAGE VARIABLES (for the shared partials)
   ========================================================= */

$role        = 'student';
$full_name   = $_SESSION['full_name'] ?? 'Student';
$first_name  = explode(' ', trim($full_name))[0];

$page_title  = 'Browse Events — RMC Events';
$active_page = 'events';

?>
<?php include 'partials/head.php'; ?>

<style>

/* =========================================================
   EVENT COUNTDOWN
   ========================================================= */

.countdown-box {

    background:
        linear-gradient(
            135deg,
            rgba(251,244,244,0.95),
            rgba(246,230,230,0.95)
        );

    border: 1px solid #edd0d0;

}


/* =========================================================
   PROGRESS BAR
   ========================================================= */

.progress-track {

    width: 100%;
    height: 9px;

    background: #e2e8f0;

    border-radius: 999px;

    overflow: hidden;

}


.progress-bar {

    height: 100%;

    border-radius: 999px;

    transition:
        width 0.5s ease;

}

</style>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =====================================================
     PAGE HEADING
     ===================================================== -->

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6 lg:mb-8">

    <div class="flex items-center gap-3">

        <div class="w-11 h-11 rounded-xl bg-rmc-800 text-white flex items-center justify-center shrink-0">

            <i class="fa-solid fa-calendar-days"></i>

        </div>

        <div>

            <h1 class="text-xl sm:text-2xl font-bold text-slate-900">

                <?= t('browse_events'); ?>

            </h1>

            <p class="text-sm text-slate-500 mt-1">

                Discover and register for exciting campus activities.

            </p>

        </div>

    </div>

</div>


<!-- =====================================================
     HERO
     ===================================================== -->

<div
    class="hero-card relative overflow-hidden rounded-[26px] border border-rmc-100 bg-gradient-to-br from-rmc-50 via-white to-rmc-100 p-6 sm:p-10 mb-6 lg:mb-8"
>

    <div
        class="absolute -top-16 -right-16 w-56 h-56 rounded-full bg-rmc-200/40 blur-3xl pointer-events-none"
    ></div>

    <div
        class="absolute -bottom-20 -left-10 w-64 h-64 rounded-full bg-rmc-100/50 blur-3xl pointer-events-none"
    ></div>

    <div class="relative">

        <span
            class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wider bg-rmc-800 text-white px-3 py-1 rounded-full"
        >

            <i class="fa-solid fa-calendar-days"></i>

            Campus Life

        </span>

        <h2 class="text-3xl sm:text-4xl font-bold text-slate-900 mt-4">

            Explore <span class="text-rmc-800">Campus Events</span>

        </h2>

        <p class="mt-3 text-slate-600 max-w-2xl">

            Seminars • Workshops • Sports • Competitions • Student Activities

        </p>

    </div>

</div>


<!-- =====================================================
     SEARCH
     ===================================================== -->

<div
    class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-5 sm:p-6 mb-6 lg:mb-8"
>

    <form
        method="GET"
        class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4"
    >

        <div class="relative">

            <i
                class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"
            ></i>

            <input
                type="text"
                name="search"
                value="<?= htmlspecialchars($search); ?>"
                placeholder="Search event..."
                class="w-full border border-slate-200 rounded-xl pl-11 pr-4 py-3 focus:outline-none focus:ring-2 focus:ring-rmc-800/20 focus:border-rmc-800 bg-white"
            >

        </div>

        <div class="relative">

            <i
                class="fa-solid fa-folder absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"
            ></i>

            <input
                type="text"
                name="category"
                value="<?= htmlspecialchars($category_filter); ?>"
                placeholder="Category..."
                class="w-full border border-slate-200 rounded-xl pl-11 pr-4 py-3 focus:outline-none focus:ring-2 focus:ring-rmc-800/20 focus:border-rmc-800 bg-white"
            >

        </div>

        <button
            type="submit"
            class="bg-rmc-800 hover:bg-rmc-900 text-white rounded-xl font-semibold py-3 transition flex items-center justify-center gap-2"
        >

            <i class="fa-solid fa-magnifying-glass"></i>

            <?= t('search'); ?> Events

        </button>

        <a
            href="events.php"
            class="flex items-center justify-center gap-2 rounded-xl border border-rmc-200 text-rmc-800 font-semibold py-3 hover:bg-rmc-50 transition"
        >

            <i class="fa-solid fa-filter-circle-xmark"></i>

            <?= t('clear'); ?> Filters

        </a>

    </form>

</div>


<!-- =====================================================
     MESSAGE
     ===================================================== -->

<?php if (isset($message)): ?>

<?php

$message_class =
    $message_type === 'success'
    ? 'bg-green-100 border-green-300 text-green-700'
    :
    (
        $message_type === 'warning'
        ? 'bg-yellow-100 border-yellow-300 text-yellow-700'
        : 'bg-red-100 border-red-300 text-red-700'
    );

$message_icon =
    $message_type === 'success'
    ? 'fa-circle-check'
    :
    (
        $message_type === 'warning'
        ? 'fa-triangle-exclamation'
        : 'fa-circle-exclamation'
    );

?>

<div
    class="<?= $message_class; ?> border rounded-2xl px-5 py-4 mb-6 lg:mb-8 flex items-center gap-3"
>

    <i
        class="fa-solid <?= $message_icon; ?>"
    ></i>

    <?= htmlspecialchars($message); ?>

</div>

<?php endif; ?>


<!-- =====================================================
     EVENTS GRID
     ===================================================== -->

<div
    class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5 lg:gap-6"
>


<?php if (pg_num_rows($events) === 0): ?>

<div
    class="md:col-span-2 xl:col-span-3 bg-white rounded-[26px] border border-slate-200 shadow-sm p-10 text-center"
>

    <div
        class="w-16 h-16 mx-auto rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center"
    >

        <i class="fa-solid fa-calendar-xmark text-2xl"></i>

    </div>

    <h3 class="text-xl font-bold text-slate-900 mt-4">

        No events found

    </h3>

    <p class="text-slate-500 mt-2 max-w-md mx-auto">

        No approved campus events match your current search. Try clearing your filters.

    </p>

    <a
        href="events.php"
        class="inline-flex items-center gap-2 mt-5 bg-rmc-800 hover:bg-rmc-900 text-white rounded-xl px-5 py-3 font-semibold transition"
    >

        <i class="fa-solid fa-filter-circle-xmark"></i>

        <?= t('clear'); ?> Filters

    </a>

</div>

<?php else: ?>


<?php while ($row = pg_fetch_assoc($events)): ?>


<?php

/* =========================================================
   EVENT DATA
   ========================================================= */

$registered_count =
    (int) $row['registered_count'];

$registration_limit =
    (int) $row['registration_limit'];


$slots_left =
    max(
        0,
        $registration_limit - $registered_count
    );


$is_full =
    $registration_limit > 0 &&
    $registered_count >= $registration_limit;


$is_registered =
    ($row['is_registered'] === 't' ||
     $row['is_registered'] === true);


/* =========================================================
   REGISTRATION PERCENTAGE
   ========================================================= */

if ($registration_limit > 0) {

    $registration_percentage =
        round(
            ($registered_count /
            $registration_limit) * 100
        );

    $registration_percentage =
        min(
            100,
            max(
                0,
                $registration_percentage
            )
        );

} else {

    $registration_percentage = 0;

}


/* =========================================================
   EVENT DATE + TIME
   ========================================================= */

try {

    $event_start = new DateTime(
        $row['event_date'] .
        ' ' .
        $row['start_time']
    );


    $event_end = new DateTime(
        $row['event_date'] .
        ' ' .
        $row['end_time']
    );


    $now = new DateTime();


}
catch (Exception $e) {

    $event_start = null;
    $event_end = null;
    $now = new DateTime();

}


/* =========================================================
   EVENT STATUS
   ========================================================= */

if ($event_start && $event_end) {

    if ($now >= $event_end) {

        $event_status =
            'completed';

        $event_status_text =
            'Completed';

        $event_status_class =
            'bg-slate-200 text-slate-700';

        $event_status_icon =
            'fa-circle-check';

    }

    elseif (
        $now >= $event_start &&
        $now < $event_end
    ) {

        $event_status =
            'live';

        $event_status_text =
            'Happening Now';

        $event_status_class =
            'bg-red-100 text-red-700';

        $event_status_icon =
            'fa-fire';

    }

    else {

        $event_status =
            'upcoming';

        $event_status_text =
            'Upcoming';

        $event_status_class =
            'bg-rmc-50 text-rmc-800';

        $event_status_icon =
            'fa-calendar-days';

    }

}
else {

    $event_status =
        'upcoming';

    $event_status_text =
        'Upcoming';

    $event_status_class =
        'bg-rmc-50 text-rmc-800';

    $event_status_icon =
        'fa-calendar-days';

}


/* =========================================================
   REGISTRATION STATUS
   ========================================================= */

if ($event_status === 'completed') {

    $registration_status =
        'Registration Closed';

    $registration_status_class =
        'bg-red-100 text-red-700';

    $registration_status_icon =
        'fa-lock';

}

elseif ($event_status === 'live') {

    $registration_status =
        'Registration Closed';

    $registration_status_class =
        'bg-red-100 text-red-700';

    $registration_status_icon =
        'fa-lock';

}

elseif ($is_full) {

    $registration_status =
        'Registration Closed';

    $registration_status_class =
        'bg-red-100 text-red-700';

    $registration_status_icon =
        'fa-lock';

}

elseif (
    $registration_limit > 0 &&
    $registration_percentage >= 80
) {

    $registration_status =
        'Almost Full';

    $registration_status_class =
        'bg-yellow-100 text-yellow-700';

    $registration_status_icon =
        'fa-triangle-exclamation';

}

else {

    $registration_status =
        'Registration Open';

    $registration_status_class =
        'bg-green-100 text-green-700';

    $registration_status_icon =
        'fa-door-open';

}


/* =========================================================
   PROGRESS BAR
   ========================================================= */

if ($registration_percentage >= 90) {

    $progress_class =
        'bg-red-500';

}
elseif ($registration_percentage >= 70) {

    $progress_class =
        'bg-yellow-500';

}
else {

    $progress_class =
        'bg-green-500';

}

?>


<!-- =====================================================
     EVENT CARD
     ===================================================== -->

<div
    class="bg-white rounded-[26px] overflow-hidden border border-slate-200 shadow-sm hover:-translate-y-1 hover:shadow-lg transition duration-300 flex flex-col"
>


<!-- POSTER -->

<?php if (!empty($row['poster_image'])): ?>

<img
    src="img/<?= htmlspecialchars($row['poster_image']); ?>"
    class="w-full h-48 object-cover"
>

<?php else: ?>

<div
    class="h-48 bg-gradient-to-r from-rmc-200 to-rmc-400 flex items-center justify-center"
>

<i
    class="fa-solid fa-calendar-days text-6xl text-white"
></i>

</div>

<?php endif; ?>


<div class="p-5 sm:p-6 flex flex-col flex-1">


<!-- =====================================================
     TOP BADGES
     ===================================================== -->

<div
    class="flex items-center justify-between gap-2 mb-4 flex-wrap"
>


<span
    class="bg-rmc-50 text-rmc-800 border border-rmc-100 text-xs px-3 py-1 rounded-full font-bold"
>

<i class="fa-solid fa-tag mr-1"></i>

<?= htmlspecialchars($row['category']); ?>

</span>


<span
    class="<?= $event_status_class; ?> text-xs px-3 py-1 rounded-full font-bold"
>

<i
    class="fa-solid <?= $event_status_icon; ?> mr-1"
></i>

<?= $event_status_text; ?>

</span>


</div>


<!-- =====================================================
     TITLE
     ===================================================== -->

<h3
    class="text-xl font-bold text-slate-900 mb-4 leading-snug"
>

<?= htmlspecialchars($row['title']); ?>

</h3>


<!-- =====================================================
     EVENT COUNTDOWN
     ===================================================== -->

<div
    class="countdown-box rounded-2xl p-4 mb-5"
    data-start="<?= htmlspecialchars($event_start ? $event_start->format('c') : ''); ?>"
    data-end="<?= htmlspecialchars($event_end ? $event_end->format('c') : ''); ?>"
>


<div class="flex justify-between items-center mb-3">


<div
    class="text-sm font-bold text-slate-700"
>

<i
    class="fa-solid fa-fire text-rmc-700 mr-1"
></i>

EVENT COUNTDOWN

</div>


<span
    class="countdown-status text-xs font-bold px-3 py-1 rounded-full bg-rmc-50 text-rmc-800 border border-rmc-100"
>

<?= htmlspecialchars($event_status_text); ?>

</span>


</div>


<div
    class="grid grid-cols-4 gap-2"
>


<div
    class="bg-white rounded-xl p-2 sm:p-3 text-center shadow-sm border border-slate-100"
>

<div
    class="count-days text-xl sm:text-2xl font-bold text-rmc-800"
>
00
</div>

<div
    class="text-[10px] font-bold text-slate-500"
>
DAYS
</div>

</div>


<div
    class="bg-white rounded-xl p-2 sm:p-3 text-center shadow-sm border border-slate-100"
>

<div
    class="count-hours text-xl sm:text-2xl font-bold text-rmc-800"
>
00
</div>

<div
    class="text-[10px] font-bold text-slate-500"
>
HOURS
</div>

</div>


<div
    class="bg-white rounded-xl p-2 sm:p-3 text-center shadow-sm border border-slate-100"
>

<div
    class="count-minutes text-xl sm:text-2xl font-bold text-rmc-700"
>
00
</div>

<div
    class="text-[10px] font-bold text-slate-500"
>
MINUTES
</div>

</div>


<div
    class="bg-white rounded-xl p-2 sm:p-3 text-center shadow-sm border border-slate-100"
>

<div
    class="count-seconds text-xl sm:text-2xl font-bold text-red-600"
>
00
</div>

<div
    class="text-[10px] font-bold text-slate-500"
>
SECONDS
</div>

</div>


</div>


<div
    class="countdown-message text-center mt-3 text-sm font-bold text-slate-600"
>

</div>


</div>


<!-- =====================================================
     EVENT DETAILS
     ===================================================== -->

<div
    class="space-y-2 text-slate-600 text-sm"
>


<p>

<i
    class="fa-solid fa-calendar text-rmc-800 w-5"
></i>

<?= htmlspecialchars($row['event_date']); ?>

</p>


<p>

<i
    class="fa-solid fa-clock text-rmc-800 w-5"
></i>

<?= htmlspecialchars($row['start_time']); ?>

-

<?= htmlspecialchars($row['end_time']); ?>

</p>


<p>

<i
    class="fa-solid fa-location-dot text-rmc-800 w-5"
></i>

<?= htmlspecialchars($row['venue']); ?>

</p>


</div>


<!-- =====================================================
     REGISTRATION STATUS
     ===================================================== -->

<div
    class="mt-5"
>


<div
    class="flex justify-between items-center mb-2"
>


<span
    class="text-sm font-bold text-slate-800"
>

<i
    class="fa-solid fa-users text-rmc-800 mr-1"
></i>

Registration

</span>


<span
    class="text-sm font-semibold text-slate-600"
>

<?= $registered_count; ?>

/

<?= $registration_limit > 0
    ? $registration_limit
    : '∞'; ?>

registered

</span>


</div>


<?php if ($registration_limit > 0): ?>

<div
    class="progress-track"
>

<div
    class="progress-bar <?= $progress_class; ?>"
    style="width: <?= $registration_percentage; ?>%;"
></div>

</div>


<div
    class="flex justify-between items-center mt-2"
>


<span
    class="text-xs font-semibold text-slate-500"
>

<?= $registration_percentage; ?>% filled

</span>


<span
    class="text-xs font-semibold <?= $slots_left <= 0
        ? 'text-red-600'
        : ($registration_percentage >= 80
            ? 'text-yellow-600'
            : 'text-green-600'); ?>"
>

<?php if ($slots_left <= 0): ?>

No slots remaining

<?php else: ?>

<?= $slots_left; ?>

slot<?= $slots_left == 1 ? '' : 's'; ?>

remaining

<?php endif; ?>

</span>


</div>

<?php endif; ?>


</div>


<!-- =====================================================
     REGISTRATION STATUS BADGE
     ===================================================== -->

<div class="mt-5">


<span
    class="<?= $registration_status_class; ?> inline-flex items-center px-4 py-2 rounded-full text-xs font-bold"
>

<i
    class="fa-solid <?= $registration_status_icon; ?> mr-2"
></i>

<?= $registration_status; ?>

</span>


</div>


<!-- =====================================================
     REGISTRATION BUTTON
     ===================================================== -->

<form
    method="POST"
    class="mt-5"
>


<?= csrf_field(); ?>


<input
    type="hidden"
    name="register_event_id"
    value="<?= htmlspecialchars($row['event_id']); ?>"
>


<?php if ($is_registered): ?>


<button
    type="button"
    disabled
    class="w-full py-3 rounded-xl bg-green-100 text-green-700 font-bold cursor-not-allowed"
>

<i
    class="fa-solid fa-circle-check mr-2"
></i>

Already Registered

</button>


<?php elseif ($event_status === 'completed'): ?>


<button
    type="button"
    disabled
    class="w-full py-3 rounded-xl bg-slate-200 text-slate-500 font-bold cursor-not-allowed"
>

<i
    class="fa-solid fa-circle-check mr-2"
></i>

Event Completed

</button>


<?php elseif ($event_status === 'live'): ?>


<button
    type="button"
    disabled
    class="w-full py-3 rounded-xl bg-red-100 text-red-600 font-bold cursor-not-allowed"
>

<i
    class="fa-solid fa-lock mr-2"
></i>

Registration Closed

</button>


<?php elseif ($is_full): ?>


<button
    type="button"
    disabled
    class="w-full py-3 rounded-xl bg-slate-300 text-slate-600 font-bold cursor-not-allowed"
>

<i
    class="fa-solid fa-ban mr-2"
></i>

Registration Closed

</button>


<?php else: ?>


<button
    type="submit"
    class="w-full py-3 rounded-xl bg-rmc-800 hover:bg-rmc-900 text-white font-bold transition"
>

<i
    class="fa-solid fa-user-plus mr-2"
></i>

Register Now

</button>


<?php endif; ?>


</form>


<!-- =====================================================
     PHOTOS
     ===================================================== -->

<a
    href="gallery.php?id=<?= htmlspecialchars($row['event_id']); ?>"
    class="block text-center mt-3 py-2 rounded-xl border border-rmc-200 text-rmc-800 font-semibold text-sm hover:bg-rmc-50 transition"
>

<i
    class="fa-solid fa-images mr-2"
></i>

View Photos

</a>


<!-- =====================================================
     FEEDBACK
     ===================================================== -->

<a
    href="feedback.php?id=<?= htmlspecialchars($row['event_id']); ?>"
    class="block text-center mt-2 py-2 rounded-xl border border-rmc-200 text-rmc-800 font-semibold text-sm hover:bg-rmc-50 transition"
>

<i
    class="fa-solid fa-star mr-2"
></i>

Feedback

</a>


</div>

</div>


<?php endwhile; ?>


<?php endif; ?>


</div>


<!-- =====================================================
     COUNTDOWN JAVASCRIPT
     ===================================================== -->

<script>

function updateEventCountdown(card) {

    const startString = card.dataset.start;
    const endString = card.dataset.end;

    if (!startString || !endString) {
        return;
    }


    const start = new Date(startString);
    const end = new Date(endString);


    const daysElement =
        card.querySelector('.count-days');

    const hoursElement =
        card.querySelector('.count-hours');

    const minutesElement =
        card.querySelector('.count-minutes');

    const secondsElement =
        card.querySelector('.count-seconds');

    const messageElement =
        card.querySelector('.countdown-message');

    const statusElement =
        card.querySelector('.countdown-status');


    function pad(number) {

        return String(number).padStart(2, '0');

    }


    const now = new Date();


    /* =====================================================
       COMPLETED
       ===================================================== */

    if (now >= end) {

        daysElement.textContent = '00';
        hoursElement.textContent = '00';
        minutesElement.textContent = '00';
        secondsElement.textContent = '00';


        statusElement.textContent =
            'COMPLETED';


        statusElement.className =
            'countdown-status text-xs font-bold px-3 py-1 rounded-full bg-slate-200 text-slate-700';


        messageElement.innerHTML =
            '<i class="fa-solid fa-circle-check mr-1"></i> EVENT COMPLETED';


        return;

    }


    /* =====================================================
       HAPPENING NOW
       ===================================================== */

    if (now >= start && now < end) {

        daysElement.textContent = '00';
        hoursElement.textContent = '00';
        minutesElement.textContent = '00';
        secondsElement.textContent = '00';


        statusElement.textContent =
            'LIVE';


        statusElement.className =
            'countdown-status text-xs font-bold px-3 py-1 rounded-full bg-red-100 text-red-700';


        messageElement.innerHTML =
            '<i class="fa-solid fa-fire mr-1"></i> HAPPENING NOW';


        return;

    }


    /* =====================================================
       UPCOMING
       ===================================================== */

    const difference =
        start.getTime() - now.getTime();


    const totalSeconds =
        Math.floor(difference / 1000);


    const days =
        Math.floor(
            totalSeconds / 86400
        );


    const hours =
        Math.floor(
            (totalSeconds % 86400) / 3600
        );


    const minutes =
        Math.floor(
            (totalSeconds % 3600) / 60
        );


    const seconds =
        totalSeconds % 60;


    daysElement.textContent =
        pad(days);


    hoursElement.textContent =
        pad(hours);


    minutesElement.textContent =
        pad(minutes);


    secondsElement.textContent =
        pad(seconds);


    statusElement.textContent =
        'UPCOMING';


    statusElement.className =
        'countdown-status text-xs font-bold px-3 py-1 rounded-full bg-rmc-50 text-rmc-800 border border-rmc-100';


    if (days > 0) {

        messageElement.innerHTML =
            '<i class="fa-solid fa-hourglass-half mr-1"></i> EVENT STARTS SOON';

    }

    else {

        messageElement.innerHTML =
            '<i class="fa-solid fa-fire mr-1"></i> STARTING TODAY';

    }

}


/* =========================================================
   INITIALIZE ALL COUNTDOWNS
   ========================================================= */

function updateAllCountdowns() {

    document
        .querySelectorAll('.countdown-box')
        .forEach(
            updateEventCountdown
        );

}


updateAllCountdowns();


/*
 * Update every second.
 */

setInterval(
    updateAllCountdowns,
    1000
);

</script>


<?php include 'partials/footer.php'; ?>
