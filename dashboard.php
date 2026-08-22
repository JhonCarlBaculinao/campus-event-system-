<?php

session_start();

require 'db_connect.php';
require 'send_email.php';
require 'lang.php';
require 'csrf.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$full_name = $_SESSION['full_name'];
$role      = $_SESSION['role'];
$user_id   = $_SESSION['user_id'];

$first_name = explode(' ', trim($full_name))[0];


/* =========================================================
   EVENT REMINDER CHECK - STUDENTS
   ========================================================= */

if ($role === 'student') {

    $reminder_query = "
        SELECT
            e.event_id,
            e.title,
            e.event_date
        FROM registrations r
        JOIN events e
            ON r.event_id = e.event_id
        WHERE r.user_id = $1
          AND e.status = 'approved'
          AND e.event_date BETWEEN CURRENT_DATE
                               AND CURRENT_DATE + INTERVAL '2 days'
          AND NOT EXISTS (
                SELECT 1
                FROM notifications n
                WHERE n.user_id = $1
                  AND n.type = 'event_reminder'
                  AND n.message LIKE '%' || e.title || '%'
          )
    ";

    $reminder_result = pg_query_params(
        $conn,
        $reminder_query,
        array($user_id)
    );

    $student_email = null;
    $reminder_emails_sent = isset($_SESSION['reminder_emails_sent'])
        ? (int) $_SESSION['reminder_emails_sent']
        : 0;

    while ($row = pg_fetch_assoc($reminder_result)) {

        $msg = 'Reminder: Your event "' .
            $row['title'] .
            '" is coming up on ' .
            $row['event_date'] .
            '.';

        pg_query_params(
            $conn,
            "INSERT INTO notifications
            (
                user_id,
                type,
                message,
                is_read,
                created_at
            )
            VALUES
            (
                $1,
                'event_reminder',
                $2,
                false,
                NOW()
            )",
            array($user_id, $msg)
        );

        if ($student_email === null) {

            $u = pg_fetch_assoc(
                pg_query_params(
                    $conn,
                    "SELECT email
                     FROM users
                     WHERE user_id = $1",
                    array($user_id)
                )
            );

            $student_email = $u['email'] ?? '';
        }

        if (
            !empty($student_email) &&
            $reminder_emails_sent < 10
        ) {

            send_email_deferred(
                $student_email,
                "Event Reminder",
                "<h2>Event Reminder</h2>
                <p>Your event
                <strong>" .
                htmlspecialchars($row['title']) .
                "</strong>
                is coming up on
                <strong>" .
                htmlspecialchars($row['event_date']) .
                "</strong>.</p>"
            );

            $reminder_emails_sent++;
        }
    }

    $_SESSION['reminder_emails_sent'] = $reminder_emails_sent;
}


/* =========================================================
   BULK CANCEL HANDLER — ORGANIZER
   ========================================================= */

if ($role === 'organizer' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_cancel_ids'])) {

    csrf_verify();

    $ids = array_filter(
        array_map('intval', $_POST['bulk_cancel_ids']),
        fn($id) => $id > 0
    );

    $cancelled = 0;
    $failed    = 0;

    foreach ($ids as $eid) {

        $chk = pg_query_params(
            $conn,
            "SELECT event_id, title, status
             FROM events
             WHERE event_id = $1
               AND organizer_id = $2",
            array($eid, $user_id)
        );

        if (!$chk || pg_num_rows($chk) === 0) { $failed++; continue; }

        $ev = pg_fetch_assoc($chk);

        if ($ev['status'] !== 'approved') { $failed++; continue; }

        pg_query_params(
            $conn,
            "UPDATE events SET status = 'cancelled' WHERE event_id = $1",
            array($eid)
        );

        pg_query_params(
            $conn,
            "UPDATE registrations SET status = 'cancelled' WHERE event_id = $1",
            array($eid)
        );

        pg_query_params(
            $conn,
            "INSERT INTO notifications (user_id, type, message, is_read, created_at)
             SELECT user_id, 'event_cancelled', 'The event \"' || $2 || '\" has been cancelled.', false, NOW()
             FROM registrations WHERE event_id = $1",
            array($eid, $ev['title'])
        );

        $cancelled++;
    }

    $bulk_msg = $cancelled . ' event(s) cancelled.';
    if ($failed > 0) { $bulk_msg .= ' ' . $failed . ' skipped (not found, not owned, or not approved).';
    }

    $_SESSION['flash_success'] = $bulk_msg;

    header("Location: dashboard.php#my-events");
    exit();
}


/* =========================================================
   STUDENT PROFILE
   ========================================================= */

$student_profile = null;

if ($role === 'student') {

    $student_profile = pg_fetch_assoc(
        pg_query_params(
            $conn,
            "SELECT full_name, student_id, department, email
             FROM users
             WHERE user_id = $1",
            array($user_id)
        )
    );
}


/* =========================================================
   DASHBOARD STATS
   ========================================================= */

$stats = [

    'events'           => 0,
    'registrations'    => 0,
    'notifications'    => 0,
    'pending'          => 0,
    'participants'     => 0,
    'attended'         => 0,
    'attendance_rate'  => 0,
    'upcoming'         => 0

];

$my_events = null;
$upcoming_events = null;


/* =========================================================
   STUDENT
   ========================================================= */

if ($role === "student") {

    $r = pg_query(
        $conn,
        "SELECT COUNT(*)
         FROM events
         WHERE status = 'approved'
           AND event_date >= CURRENT_DATE"
    );

    $stats['events'] = (int) pg_fetch_result($r, 0, 0);


    $r = pg_query_params(
        $conn,
        "SELECT COUNT(*)
         FROM registrations
         WHERE user_id = $1",
        array($user_id)
    );

    $stats['registrations'] = (int) pg_fetch_result($r, 0, 0);


    $upcoming_events = pg_query_params(
        $conn,
        "SELECT
            e.event_id,
            e.title,
            e.category,
            e.event_date,
            e.start_time,
            e.end_time,
            e.venue,
            e.registration_limit,

            (
                SELECT COUNT(*)
                FROM registrations r2
                WHERE r2.event_id = e.event_id
            ) AS registered_count,

            CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM registrations r3
                    WHERE r3.event_id = e.event_id
                      AND r3.user_id = $1
                )
                THEN true
                ELSE false
            END AS is_registered

         FROM events e

         WHERE e.status = 'approved'
           AND e.event_date >= CURRENT_DATE

         ORDER BY
            e.event_date ASC,
            e.start_time ASC

         LIMIT 5",
        array($user_id)
    );
}


/* =========================================================
   ORGANIZER
   ========================================================= */

elseif ($role === "organizer") {

    $r = pg_query_params(
        $conn,
        "SELECT COUNT(*)
         FROM events
         WHERE organizer_id = $1
           AND status != 'deleted'",
        array($user_id)
    );

    $stats['events'] = (int) pg_fetch_result($r, 0, 0);


    $r = pg_query_params(
        $conn,
        "SELECT COUNT(r.registration_id)
         FROM registrations r
         JOIN events e
           ON r.event_id = e.event_id
         WHERE e.organizer_id = $1",
        array($user_id)
    );

    $stats['participants'] = (int) pg_fetch_result($r, 0, 0);


    $r = pg_query_params(
        $conn,
        "SELECT COUNT(a.attendance_id)
         FROM attendance a
         JOIN registrations r
           ON a.registration_id = r.registration_id
         JOIN events e
           ON r.event_id = e.event_id
         WHERE e.organizer_id = $1",
        array($user_id)
    );

    $stats['attended'] = (int) pg_fetch_result($r, 0, 0);


    $stats['attendance_rate'] =
        $stats['participants'] > 0
        ? round(
            ($stats['attended'] / $stats['participants']) * 100,
            1
        )
        : 0;


    $r = pg_query_params(
        $conn,
        "SELECT COUNT(*)
         FROM events
         WHERE organizer_id = $1
           AND status = 'approved'
           AND event_date >= CURRENT_DATE",
        array($user_id)
    );

    $stats['upcoming'] = (int) pg_fetch_result($r, 0, 0);


    $my_events = pg_query_params(
        $conn,
        "SELECT
            event_id,
            title,
            event_date,
            venue,
            status
         FROM events
         WHERE organizer_id = $1
           AND status != 'deleted'
         ORDER BY event_date DESC",
        array($user_id)
    );
}


/* =========================================================
   ADMIN
   ========================================================= */

elseif ($role === "admin") {

    $r = pg_query(
        $conn,
        "SELECT COUNT(*)
         FROM events
         WHERE status != 'deleted'"
    );

    $stats['events'] = (int) pg_fetch_result($r, 0, 0);


    $r = pg_query(
        $conn,
        "SELECT COUNT(*)
         FROM events
         WHERE status = 'pending'"
    );

    $stats['pending'] = (int) pg_fetch_result($r, 0, 0);


    $r = pg_query(
        $conn,
        "SELECT COUNT(*)
         FROM registrations"
    );

    $stats['participants'] = (int) pg_fetch_result($r, 0, 0);


    $r = pg_query(
        $conn,
        "SELECT COUNT(*)
         FROM attendance"
    );

    $stats['attended'] = (int) pg_fetch_result($r, 0, 0);


    $stats['attendance_rate'] =
        $stats['participants'] > 0
        ? round(
            ($stats['attended'] / $stats['participants']) * 100,
            1
        )
        : 0;


    $r = pg_query(
        $conn,
        "SELECT COUNT(*)
         FROM events
         WHERE status = 'approved'
           AND event_date >= CURRENT_DATE"
    );

    $stats['upcoming'] = (int) pg_fetch_result($r, 0, 0);
}


/* =========================================================
   UNREAD NOTIFICATIONS
   ========================================================= */

$r = pg_query_params(
    $conn,
    "SELECT COUNT(*)
     FROM notifications
     WHERE user_id = $1
       AND is_read = false",
    array($user_id)
);

$stats['notifications'] = (int) pg_fetch_result($r, 0, 0);

/* =========================================================
   RECENT NOTIFICATIONS
   ========================================================= */

$recent_notifications = pg_query_params(
    $conn,
    "SELECT notification_id, type, message, is_read, created_at
     FROM notifications
     WHERE user_id = $1
     ORDER BY created_at DESC
     LIMIT 5",
    array($user_id)
);


/* =========================================================
   STATUS BADGE
   ========================================================= */

function dash_status_badge($status)
{
    switch ($status) {

        case 'approved':
            return 'bg-emerald-50 text-emerald-700 border border-emerald-200';

        case 'rejected':
            return 'bg-red-50 text-red-700 border border-red-200';

        case 'cancelled':
            return 'bg-slate-100 text-slate-600 border border-slate-200';

        case 'archived':
            return 'bg-slate-100 text-slate-700 border border-slate-300';

        default:
            return 'bg-amber-50 text-amber-700 border border-amber-200';
    }
}


/* =========================================================
   UPCOMING EVENT HELPERS
   ========================================================= */

function upcoming_registration_status(
    $registered_count,
    $limit,
    $is_registered
) {

    $registered_count = (int) $registered_count;
    $limit = (int) $limit;

    if ($is_registered) {

        return [
            'text'  => 'Registered',
            'class' => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
            'dot'   => 'bg-emerald-500'
        ];
    }

    if ($limit > 0 && $registered_count >= $limit) {

        return [
            'text'  => 'Registration Closed',
            'class' => 'bg-red-50 text-red-700 border border-red-200',
            'dot'   => 'bg-red-500'
        ];
    }

    if ($limit > 0 && $registered_count >= ($limit * 0.8)) {

        return [
            'text'  => 'Almost Full',
            'class' => 'bg-amber-50 text-amber-700 border border-amber-200',
            'dot'   => 'bg-amber-500'
        ];
    }

    return [
        'text'  => 'Registration Open',
        'class' => 'bg-rmc-50 text-rmc-800 border border-rmc-200',
        'dot'   => 'bg-rmc-500'
    ];
}


function format_event_date($date)
{
    if (empty($date)) {
        return '';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return htmlspecialchars($date);
    }

    return date('M d, Y', $timestamp);
}


function format_event_time($time)
{
    if (empty($time)) {
        return '';
    }

    $timestamp = strtotime($time);

    if ($timestamp === false) {
        return htmlspecialchars($time);
    }

    return date('g:i A', $timestamp);
}


/* =========================================================
   ROLE LABELS
   ========================================================= */

$role_label = ucfirst($role);

if ($role === 'admin') {
    $role_label = 'Administrator';
}

if ($role === 'organizer') {
    $role_label = 'Event Organizer';
}

if ($role === 'student') {
    $role_label = 'Student';
}


/* =========================================================
   GREETING
   ========================================================= */

$hour = (int) date('H');

if ($hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour < 18) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}

$page_title  = 'RMC Events — Dashboard';
$active_page = 'dashboard';
?>

<?php include 'partials/head.php'; ?>

<style>

/* =========================================================
   DASHBOARD - HERO
   ========================================================= */

.hero-card {
    position: relative;
    overflow: hidden;

    background:
        linear-gradient(
            135deg,
            #fdf8f8 0%,
            #fbf1f1 55%,
            #f6e6e6 100%
        );

    border: 1px solid rgba(122,12,12,.10);
}

.hero-grid {
    background-image:
        linear-gradient(
            rgba(122,12,12,.05) 1px,
            transparent 1px
        ),
        linear-gradient(
            90deg,
            rgba(122,12,12,.05) 1px,
            transparent 1px
        );

    background-size: 32px 32px;
}

.hero-orb {
    position: absolute;
    border-radius: 9999px;
    filter: blur(2px);
    pointer-events: none;
}

.hero-orb-one {
    width: 330px;
    height: 330px;
    right: -100px;
    top: -150px;
    background: rgba(122,12,12,.07);
}

.hero-orb-two {
    width: 250px;
    height: 250px;
    left: 45%;
    bottom: -180px;
    background: rgba(122,12,12,.05);
}

</style>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<?php if (!empty($_SESSION['flash_success'])): ?>

<div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl px-5 py-4 text-sm font-medium animate-up" role="alert">
    <i class="fa-solid fa-circle-check mr-2"></i>
    <?= htmlspecialchars($_SESSION['flash_success']); ?>
</div>

<?php unset($_SESSION['flash_success']); endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>

<div class="mb-6 bg-red-50 border border-red-200 text-red-800 rounded-2xl px-5 py-4 text-sm font-medium animate-up" role="alert">
    <i class="fa-solid fa-circle-exclamation mr-2"></i>
    <?= htmlspecialchars($_SESSION['flash_error']); ?>
</div>

<?php unset($_SESSION['flash_error']); endif; ?>


<!-- =========================================================
     HERO
     ========================================================= -->

<?php if ($role === 'student'): ?>

<div class="grid lg:grid-cols-[1fr_330px] gap-5 lg:gap-6 mb-7 lg:mb-9 items-stretch">

    <section class="hero-card hero-grid rounded-[28px] shadow-sm animate-up overflow-hidden">
        <div class="hero-orb hero-orb-one"></div>
        <div class="hero-orb hero-orb-two"></div>

        <div class="relative z-10 p-6 sm:p-8 lg:p-10">
            <div class="max-w-3xl">
                <div class="inline-flex items-center gap-2 bg-rmc-800 text-white border border-rmc-800 rounded-full px-3.5 py-2 text-[11px] font-bold tracking-wide mb-5">
                    <span class="w-2 h-2 bg-emerald-400 rounded-full"></span>
                    CAMPUS LIFE
                </div>

                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight leading-[1.05] text-slate-900">
                    Discover.
                    <br class="hidden sm:block">
                    Participate.
                    <span class="text-rmc-800">Connect.</span>
                </h2>

                <p class="text-slate-600 mt-5 max-w-2xl leading-relaxed text-sm sm:text-base">
                    Discover what's happening around RMC, join activities,
                    and stay connected with your campus community.
                </p>

                <div class="flex flex-wrap gap-3 mt-7">
                    <a href="events.php" class="inline-flex items-center gap-2 bg-rmc-800 hover:bg-rmc-900 text-white px-5 py-3 rounded-xl font-semibold text-sm transition shadow-lg shadow-rmc-950/10">
                        <i class="fa-solid fa-calendar-days"></i>
                        Browse Events
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>

                    <a href="calendar.php" class="inline-flex items-center gap-2 bg-white hover:bg-rmc-50 border border-rmc-200 text-rmc-800 px-5 py-3 rounded-xl font-semibold text-sm transition">
                        <i class="fa-regular fa-calendar"></i>
                        View Calendar
                    </a>

                    <a href="my_qr.php" class="inline-flex items-center gap-2 bg-white hover:bg-rmc-50 border border-rmc-200 text-rmc-800 px-5 py-3 rounded-xl font-semibold text-sm transition">
                        <i class="fa-solid fa-qrcode"></i>
                        My QR Pass
                    </a>
                </div>
            </div>
        </div>
    </section>


    <!-- =========================================================
         STUDENT PROFILE CARD
         ========================================================= -->

    <section class="bg-white border border-slate-200 rounded-[28px] shadow-sm p-6 sm:p-7 animate-up delay-1 flex flex-col">

        <div class="flex items-center gap-3.5">

            <div class="w-12 h-12 rounded-2xl bg-rmc-800 text-white flex items-center justify-center font-bold text-lg shrink-0">
                <?= strtoupper(substr($first_name, 0, 1)); ?>
            </div>

            <div class="min-w-0">

                <p class="text-[10px] font-bold uppercase tracking-widest text-rmc-800 mb-1">
                    Student Profile
                </p>

                <h2 class="font-bold text-slate-900 leading-tight truncate">
                    <?= htmlspecialchars($full_name); ?>
                </h2>

                <p class="text-xs text-slate-500 mt-0.5">
                    <?= htmlspecialchars($role_label); ?>
                </p>

            </div>

        </div>


        <div class="mt-5 pt-5 border-t border-slate-100 space-y-3">

            <?php if (!empty($student_profile['department'])): ?>

                <div class="flex items-center gap-3">

                    <div class="w-9 h-9 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-building-columns text-sm"></i>
                    </div>

                    <div class="min-w-0">

                        <p class="text-[10px] uppercase font-semibold tracking-wide text-slate-400">
                            Department
                        </p>

                        <p class="text-sm font-semibold text-slate-800 truncate">
                            <?= htmlspecialchars($student_profile['department']); ?>
                        </p>

                    </div>

                </div>

            <?php endif; ?>


            <div class="flex items-center gap-3">

                <div class="w-9 h-9 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-id-card text-sm"></i>
                </div>

                <div class="min-w-0">

                    <p class="text-[10px] uppercase font-semibold tracking-wide text-slate-400">
                        Student ID
                    </p>

                    <p class="text-sm font-semibold text-slate-800 truncate">
                        <?= htmlspecialchars($student_profile['student_id']); ?>
                    </p>

                </div>

            </div>

        </div>


        <div class="mt-5 flex items-center justify-between gap-3 bg-rmc-50 border border-rmc-100 rounded-2xl px-4 py-3.5">

            <span class="inline-flex items-center gap-2 text-sm font-semibold text-rmc-800">
                <i class="fa-solid fa-ticket"></i>
                Events Registered
            </span>

            <span class="text-2xl font-bold text-rmc-800 counter" data-value="<?= $stats['registrations']; ?>">0</span>

        </div>


        <div class="mt-auto pt-5">

            <a href="settings.php" class="inline-flex items-center justify-center gap-2 w-full bg-rmc-800 hover:bg-rmc-900 text-white px-4 py-2.5 rounded-xl text-sm font-semibold transition">
                <i class="fa-solid fa-gear text-xs"></i>
                Manage Profile
            </a>

        </div>

    </section>

    <?php else: ?>

<!-- =========================================================
     HERO
     ========================================================= -->

<section
    class="hero-card hero-grid rounded-[28px] shadow-sm mb-7 lg:mb-9 animate-up"
>

    <div class="hero-orb hero-orb-one"></div>

    <div class="hero-orb hero-orb-two"></div>


    <div class="relative z-10 p-6 sm:p-8 lg:p-10">

        <div class="grid lg:grid-cols-[1fr_300px] gap-8 items-center">


            <!-- HERO TEXT -->

            <div class="max-w-3xl">


                <div
                    class="inline-flex items-center gap-2 bg-rmc-800 text-white border border-rmc-800 rounded-full px-3.5 py-2 text-[11px] font-bold tracking-wide mb-5"
                >

                    <span class="w-2 h-2 bg-emerald-400 rounded-full"></span>

                    RMC EVENTS

                </div>


                <h2
                    class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight leading-[1.05] text-slate-900"
                >

                    Discover.

                    <br class="hidden sm:block">

                    Participate.

                    <span class="text-rmc-800">
                        Connect.
                    </span>

                </h2>


                <p
                    class="text-slate-600 mt-5 max-w-2xl leading-relaxed text-sm sm:text-base"
                >

                    Stay updated with campus events, register for activities,
                    and keep track of your participation at Regis Marie College.

                </p>


                <div class="flex flex-wrap gap-3 mt-7">


                    <?php if ($role === 'student'): ?>


                        <a
                            href="events.php"
                            class="inline-flex items-center gap-2 bg-rmc-800 hover:bg-rmc-900 text-white px-5 py-3 rounded-xl font-semibold text-sm transition shadow-lg shadow-rmc-950/10"
                        >

                            <i class="fa-solid fa-calendar-days"></i>

                            Browse Events

                            <i class="fa-solid fa-arrow-right text-xs"></i>

                        </a>


                        <a
                            href="calendar.php"
                            class="inline-flex items-center gap-2 bg-white hover:bg-rmc-50 border border-rmc-200 text-rmc-800 px-5 py-3 rounded-xl font-semibold text-sm transition"
                        >

                            <i class="fa-regular fa-calendar"></i>

                            View Calendar

                        </a>


                    <?php elseif ($role === 'organizer'): ?>


                        <a
                            href="create_event.php"
                            class="inline-flex items-center gap-2 bg-rmc-800 hover:bg-rmc-900 text-white px-5 py-3 rounded-xl font-semibold text-sm transition"
                        >

                            <i class="fa-solid fa-plus"></i>

                            Create Event

                            <i class="fa-solid fa-arrow-right text-xs"></i>

                        </a>


                        <a
                            href="reports.php"
                            class="inline-flex items-center gap-2 bg-white hover:bg-rmc-50 border border-rmc-200 text-rmc-800 px-5 py-3 rounded-xl font-semibold text-sm transition"
                        >

                            <i class="fa-solid fa-chart-column"></i>

                            View Analytics

                        </a>


                    <?php else: ?>


                        <a
                            href="admin_events.php"
                            class="inline-flex items-center gap-2 bg-rmc-800 hover:bg-rmc-900 text-white px-5 py-3 rounded-xl font-semibold text-sm transition"
                        >

                            <i class="fa-solid fa-calendar-check"></i>

                            Manage Events

                            <i class="fa-solid fa-arrow-right text-xs"></i>

                        </a>


                        <a
                            href="reports.php"
                            class="inline-flex items-center gap-2 bg-white hover:bg-rmc-50 border border-rmc-200 text-rmc-800 px-5 py-3 rounded-xl font-semibold text-sm transition"
                        >

                            <i class="fa-solid fa-chart-pie"></i>

                            View Analytics

                        </a>


                    <?php endif; ?>

                </div>

            </div>


            <!-- HERO SIDE PANEL -->

            <div
                class="hidden lg:block"
            >

                <div
                    class="rounded-2xl bg-white border border-rmc-100 p-5 shadow-sm"
                >

                    <div class="flex items-center justify-between mb-5">

                        <div>

                            <p class="text-xs text-slate-500">
                                Your account
                            </p>

                            <p class="font-bold text-slate-900 mt-1">
                                <?= htmlspecialchars($role_label); ?>
                            </p>

                        </div>


                        <div
                            class="w-11 h-11 rounded-xl bg-rmc-800 flex items-center justify-center"
                        >

                            <?php if ($role === 'student'): ?>

                                <i class="fa-solid fa-user-graduate text-white"></i>

                            <?php elseif ($role === 'organizer'): ?>

                                <i class="fa-solid fa-calendar-plus text-white"></i>

                            <?php else: ?>

                                <i class="fa-solid fa-shield-halved text-white"></i>

                            <?php endif; ?>

                        </div>

                    </div>


                    <div class="space-y-3">

                        <div class="flex items-center justify-between">

                            <span class="text-xs text-slate-500">
                                Events
                            </span>

                            <span class="text-sm font-bold text-slate-900">
                                <?= $stats['events']; ?>
                            </span>

                        </div>


                        <?php if ($role === 'student'): ?>

                            <div class="flex items-center justify-between">

                                <span class="text-xs text-slate-500">
                                    Registrations
                                </span>

                                <span class="text-sm font-bold text-slate-900">
                                    <?= $stats['registrations']; ?>
                                </span>

                            </div>

                        <?php else: ?>

                            <div class="flex items-center justify-between">

                                <span class="text-xs text-slate-500">
                                    Participants
                                </span>

                                <span class="text-sm font-bold text-slate-900">
                                    <?= $stats['participants']; ?>
                                </span>

                            </div>

                        <?php endif; ?>


                        <div class="pt-3 border-t border-rmc-100">

                            <div class="flex items-center gap-2 text-xs text-slate-500">

                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>

                                System active

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>



<?php endif; ?>


<!-- =========================================================
     STUDENT STATISTICS
     ========================================================= -->

<?php if ($role === 'student'): ?>

<section class="grid grid-cols-1 sm:grid-cols-3 gap-4 lg:gap-5 mb-7 lg:mb-9">

    <div class="stat-card bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 animate-up delay-1">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-medium text-slate-500">Events</p>
                <h3 class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2 counter" data-value="<?= $stats['events']; ?>">0</h3>
            </div>
            <div class="icon-box w-11 h-11 rounded-xl bg-rmc-50 text-rmc-800">
                <i class="fa-solid fa-calendar-days"></i>
            </div>
        </div>
        <p class="mt-4 text-xs text-slate-400">Approved upcoming campus events</p>
    </div>

    <div class="stat-card bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 animate-up delay-2">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-medium text-slate-500">Registered</p>
                <h3 class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2 counter" data-value="<?= $stats['registrations']; ?>">0</h3>
            </div>
            <div class="icon-box w-11 h-11 rounded-xl bg-emerald-50 text-emerald-700">
                <i class="fa-solid fa-ticket"></i>
            </div>
        </div>
        <p class="mt-4 text-xs text-slate-400">Events you've registered for</p>
    </div>

    <div class="stat-card bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 animate-up delay-3">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-medium text-slate-500">Notifications</p>
                <h3 class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2 counter" data-value="<?= $stats['notifications']; ?>">0</h3>
            </div>
            <div class="icon-box w-11 h-11 rounded-xl bg-red-50 text-red-600">
                <i class="fa-regular fa-bell"></i>
            </div>
        </div>
        <p class="mt-4 text-xs text-slate-400">Unread notifications</p>
    </div>

</section>

<?php else: ?>

<!-- =========================================================
     STATISTICS
     ========================================================= -->

<section
    class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 lg:gap-5 mb-7 lg:mb-9"
>


<!-- =========================================================
     EVENTS
     ========================================================= -->

<a
    href="<?= $role === 'student' ? 'events.php' : 'admin_events.php'; ?>"
    class="stat-card block bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 animate-up delay-1 hover:border-rmc-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-rmc-500 focus:ring-offset-2 transition group"
>

    <div class="flex items-start justify-between">

        <div>

            <p class="text-sm font-medium text-slate-500">

                <?php if ($role === 'student'): ?>

                    Available Events

                <?php else: ?>

                    Total Events

                <?php endif; ?>

            </p>


            <h3
                class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2 counter group-hover:text-rmc-800 transition"
                data-value="<?= $stats['events']; ?>"
            >
                0
            </h3>

        </div>


        <div
            class="icon-box w-11 h-11 rounded-xl bg-rmc-50 text-rmc-800 group-hover:scale-110 transition"
        >

            <i class="fa-solid fa-calendar-days"></i>

        </div>

    </div>


    <div class="mt-4 flex items-center gap-2 text-xs text-slate-400 group-hover:text-rmc-600 transition">

        <span
            class="w-6 h-6 rounded-lg bg-slate-50 flex items-center justify-center"
        >

            <i class="fa-solid fa-arrow-trend-up"></i>

        </span>

        Campus events

    </div>

</a>


<?php if ($role === 'student'): ?>


<!-- =========================================================
     REGISTRATIONS
     ========================================================= -->

<div
    class="stat-card bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 animate-up delay-2"
>

    <div class="flex items-start justify-between">

        <div>

            <p class="text-sm font-medium text-slate-500">
                My Registrations
            </p>

            <h3
                class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2 counter"
                data-value="<?= $stats['registrations']; ?>"
            >
                0
            </h3>

        </div>


        <div
            class="icon-box w-11 h-11 rounded-xl bg-emerald-50 text-emerald-700"
        >

            <i class="fa-solid fa-ticket"></i>

        </div>

    </div>


    <p class="mt-4 text-xs text-slate-400">
        Your registered events
    </p>

</div>


<!-- =========================================================
     NOTIFICATIONS
     ========================================================= -->

<div
    class="stat-card bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 animate-up delay-3"
>

    <div class="flex items-start justify-between">

        <div>

            <p class="text-sm font-medium text-slate-500">
                Notifications
            </p>

            <h3
                class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2 counter"
                data-value="<?= $stats['notifications']; ?>"
            >
                0
            </h3>

        </div>


        <div
            class="icon-box w-11 h-11 rounded-xl bg-red-50 text-red-600"
        >

            <i class="fa-regular fa-bell"></i>

        </div>

    </div>


    <p class="mt-4 text-xs text-slate-400">
        Unread notifications
    </p>

</div>


<!-- =========================================================
     QR
     ========================================================= -->

<div
    class="stat-card bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 animate-up delay-4"
>

    <div class="flex items-start justify-between">

        <div>

            <p class="text-sm font-medium text-slate-500">
                Event Pass
            </p>

            <h3 class="text-lg font-bold text-slate-900 mt-2">
                My QR Codes
            </h3>

        </div>


        <div
            class="icon-box w-11 h-11 rounded-xl bg-rmc-50 text-rmc-800"
        >

            <i class="fa-solid fa-qrcode"></i>

        </div>

    </div>


    <a
        href="my_qr.php"
        class="inline-flex items-center gap-2 mt-4 text-sm font-semibold text-rmc-800 hover:text-rmc-900"
    >

        View My QR

        <i class="fa-solid fa-arrow-right text-xs"></i>

    </a>

</div>


<?php elseif ($role === 'organizer'): ?>


<!-- PARTICIPANTS -->

<a
    href="reports.php"
    class="stat-card block bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 animate-up delay-2 hover:border-rmc-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-rmc-500 focus:ring-offset-2 transition group"
>

    <div class="flex items-start justify-between">

        <div>

            <p class="text-sm font-medium text-slate-500">
                Participants
            </p>

            <h3
                class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2 counter group-hover:text-rmc-800 transition"
                data-value="<?= $stats['participants']; ?>"
            >
                0
            </h3>

        </div>


        <div
            class="icon-box w-11 h-11 rounded-xl bg-rmc-50 text-rmc-800 group-hover:scale-110 transition"
        >

            <i class="fa-solid fa-users"></i>

        </div>

    </div>


    <p class="mt-4 text-xs text-slate-400 group-hover:text-rmc-600 transition">
        Across your events
    </p>

</a>


<!-- ATTENDANCE -->

<a
    href="reports.php"
    class="stat-card block bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 animate-up delay-3 hover:border-rmc-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-rmc-500 focus:ring-offset-2 transition group"
>

    <div class="flex items-start justify-between">

        <div>

            <p class="text-sm font-medium text-slate-500">
                Attendance Rate
            </p>

            <h3 class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2 group-hover:text-rmc-800 transition">

                <?= $stats['attendance_rate']; ?>%

            </h3>

        </div>


        <div
            class="icon-box w-11 h-11 rounded-xl bg-emerald-50 text-emerald-700 group-hover:scale-110 transition"
        >

            <i class="fa-solid fa-chart-line"></i>

        </div>

    </div>


    <div class="mt-5">

        <div class="flex justify-between text-[11px] text-slate-400 mb-2 group-hover:text-rmc-600 transition">

            <span>
                Attendance
            </span>

            <span class="font-semibold">
                <?= $stats['attendance_rate']; ?>%
            </span>

        </div>


        <div
            class="w-full bg-slate-100 rounded-full h-2 overflow-hidden"
        >

            <div
                class="bg-emerald-500 h-2 rounded-full progress-bar group-hover:bg-rmc-600 transition"
                style="width: <?= min(100, $stats['attendance_rate']); ?>%;"
            ></div>

        </div>

    </div>

</a>


<!-- UPCOMING -->

<a
    href="reports.php"
    class="stat-card block bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 animate-up delay-4 hover:border-rmc-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-rmc-500 focus:ring-offset-2 transition group"
>

    <div class="flex items-start justify-between">

        <div>

            <p class="text-sm font-medium text-slate-500">
                Upcoming Events
            </p>

            <h3
                class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2 counter group-hover:text-rmc-800 transition"
                data-value="<?= $stats['upcoming']; ?>"
            >
                0
            </h3>

        </div>


        <div
            class="icon-box w-11 h-11 rounded-xl bg-rmc-50 text-rmc-800 group-hover:scale-110 transition"
        >

            <i class="fa-solid fa-clock"></i>

        </div>

    </div>


    <p class="mt-4 text-xs text-slate-400 group-hover:text-rmc-600 transition">
        Approved upcoming events
    </p>

</a>


<?php else: ?>


<!-- PENDING -->

<a
    href="admin_events.php?filter=pending"
    class="stat-card block bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 animate-up delay-2 hover:border-rmc-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-rmc-500 focus:ring-offset-2 transition group"
>

    <div class="flex items-start justify-between">

        <div>

            <p class="text-sm font-medium text-slate-500">
                Pending Approvals
            </p>

            <h3
                class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2 counter group-hover:text-rmc-800 transition"
                data-value="<?= $stats['pending']; ?>"
            >
                0
            </h3>

        </div>


        <div
            class="icon-box w-11 h-11 rounded-xl bg-amber-50 text-amber-700 group-hover:scale-110 transition"
        >

            <i class="fa-solid fa-hourglass-half"></i>

        </div>

    </div>


    <p class="mt-4 text-xs text-slate-400 group-hover:text-rmc-600 transition">
        Events awaiting review
    </p>

</a>


<!-- PARTICIPANTS -->

<a
    href="admin_users.php"
    class="stat-card block bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 animate-up delay-3 hover:border-rmc-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-rmc-500 focus:ring-offset-2 transition group"
>

    <div class="flex items-start justify-between">

        <div>

            <p class="text-sm font-medium text-slate-500">
                Participants
            </p>

            <h3
                class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2 counter group-hover:text-rmc-800 transition"
                data-value="<?= $stats['participants']; ?>"
            >
                0
            </h3>

        </div>


        <div
            class="icon-box w-11 h-11 rounded-xl bg-rmc-50 text-rmc-800 group-hover:scale-110 transition"
        >

            <i class="fa-solid fa-users"></i>

        </div>

    </div>


    <p class="mt-4 text-xs text-slate-400 group-hover:text-rmc-600 transition">
        Total registrations
    </p>

</a>


<!-- ATTENDANCE -->

<a
    href="reports.php"
    class="stat-card block bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 animate-up delay-4 hover:border-rmc-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-rmc-500 focus:ring-offset-2 transition group"
>

    <div class="flex items-start justify-between">

        <div>

            <p class="text-sm font-medium text-slate-500">
                Attendance Rate
            </p>

            <h3 class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2 group-hover:text-rmc-800 transition">

                <?= $stats['attendance_rate']; ?>%

            </h3>

        </div>


        <div
            class="icon-box w-11 h-11 rounded-xl bg-emerald-50 text-emerald-700 group-hover:scale-110 transition"
        >

            <i class="fa-solid fa-chart-line"></i>

        </div>

    </div>


    <div class="mt-5">

        <div class="flex justify-between text-[11px] text-slate-400 mb-2 group-hover:text-rmc-600 transition">

            <span>
                Attendance
            </span>

            <span class="font-semibold">
                <?= $stats['attendance_rate']; ?>%
            </span>

        </div>


        <div
            class="w-full bg-slate-100 rounded-full h-2 overflow-hidden"
        >

            <div
                class="bg-emerald-500 h-2 rounded-full progress-bar group-hover:bg-rmc-600 transition"
                style="width: <?= min(100, $stats['attendance_rate']); ?>%;"
            ></div>

        </div>

    </div>

</a>


<!-- UPCOMING -->

<a
    href="admin_events.php?filter=approved"
    class="stat-card block bg-white border border-slate-200 rounded-2xl p-5 sm:p-6 animate-up delay-4 hover:border-rmc-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-rmc-500 focus:ring-offset-2 transition group"
>

    <div class="flex items-start justify-between">

        <div>

            <p class="text-sm font-medium text-slate-500">
                Upcoming Events
            </p>

            <h3
                class="text-3xl sm:text-4xl font-bold text-slate-900 mt-2 counter group-hover:text-rmc-800 transition"
                data-value="<?= $stats['upcoming']; ?>"
            >
                0
            </h3>

        </div>


        <div
            class="icon-box w-11 h-11 rounded-xl bg-rmc-50 text-rmc-800 group-hover:scale-110 transition"
        >

            <i class="fa-solid fa-calendar-days"></i>

        </div>

    </div>


    <p class="mt-4 text-xs text-slate-400 group-hover:text-rmc-600 transition">
        Approved upcoming events
    </p>

</a>


<?php endif; ?>

</section>



<?php endif; ?>


<!-- =========================================================
     STUDENT UPCOMING EVENTS
     ========================================================= -->

<?php if ($role === "student"): ?>


<section
    class="content-card bg-white border border-slate-200 rounded-[26px] shadow-sm p-5 sm:p-6 lg:p-8 animate-up"
>


    <!-- SECTION HEADER -->

    <div
        class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6 lg:mb-7"
    >

        <div>

            <div class="flex items-center gap-3">

                <div
                    class="w-9 h-9 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center"
                >

                    <i class="fa-solid fa-calendar-days"></i>

                </div>

                <div>

                    <h2 class="text-xl sm:text-2xl font-bold text-slate-900">
                        Upcoming Events
                    </h2>

                    <p class="text-sm text-slate-500 mt-1">
                        Don't miss what's happening around campus.
                    </p>

                </div>

            </div>

        </div>


        <a
            href="events.php"
            class="inline-flex items-center gap-2 text-sm font-semibold text-rmc-800 hover:text-rmc-900"
        >

            View All Events

            <i class="fa-solid fa-arrow-right text-xs"></i>

        </a>

    </div>


    <?php if ($upcoming_events && pg_num_rows($upcoming_events) > 0): ?>


        <div class="space-y-4">


            <?php while ($event = pg_fetch_assoc($upcoming_events)): ?>


                <?php

                $registered_count =
                    (int) $event['registered_count'];

                $registration_limit =
                    (int) $event['registration_limit'];

                $is_registered =
                    ($event['is_registered'] === 't' ||
                     $event['is_registered'] === true);

                $registration_status =
                    upcoming_registration_status(
                        $registered_count,
                        $registration_limit,
                        $is_registered
                    );

                $percentage = 0;

                if ($registration_limit > 0) {

                    $percentage =
                        min(
                            100,
                            round(
                                ($registered_count / $registration_limit) * 100
                            )
                        );
                }

                $event_timestamp =
                    strtotime($event['event_date']);

                $days_left = '';

                if ($event_timestamp !== false) {

                    $today = new DateTime();

                    $event_day = new DateTime(
                        date('Y-m-d', $event_timestamp)
                    );

                    $difference =
                        $today->diff($event_day)->days;

                    if ($event_day >= $today) {

                        if ($difference === 0) {

                            $days_left = 'Today';

                        } elseif ($difference === 1) {

                            $days_left = 'Tomorrow';

                        } else {

                            $days_left =
                                $difference . ' days left';
                        }
                    }
                }

                ?>


                <!-- EVENT CARD -->

                <div
                    class="event-card border border-slate-200 rounded-2xl p-4 sm:p-5 lg:p-6"
                >

                    <div
                        class="flex flex-col xl:flex-row gap-5 xl:items-center"
                    >


                        <!-- DATE -->

                        <div
                            class="flex xl:flex-col items-center justify-center gap-3 xl:gap-0 w-full xl:w-20 xl:h-20 bg-rmc-50 border border-rmc-100 rounded-2xl px-4 py-3 xl:px-2 shrink-0"
                        >

                            <span
                                class="text-[10px] uppercase font-bold tracking-wider text-rmc-800"
                            >

                                <?= date('M', $event_timestamp); ?>

                            </span>

                            <span
                                class="text-2xl sm:text-3xl font-bold text-slate-900 leading-none"
                            >

                                <?= date('d', $event_timestamp); ?>

                            </span>

                            <span
                                class="text-[10px] font-medium text-slate-400 xl:hidden"
                            >

                                <?= date('Y', $event_timestamp); ?>

                            </span>

                        </div>


                        <!-- EVENT INFORMATION -->

                        <div class="flex-1 min-w-0">


                            <div class="flex flex-wrap items-center gap-2 mb-2">


                                <span
                                    class="text-[10px] sm:text-[11px] font-bold uppercase tracking-wide bg-rmc-50 text-rmc-800 px-2.5 py-1 rounded-full"
                                >

                                    <?= htmlspecialchars(
                                        $event['category'] ?? 'General'
                                    ); ?>

                                </span>


                                <span
                                    class="inline-flex items-center gap-1.5 text-[10px] sm:text-[11px] font-bold px-2.5 py-1 rounded-full <?= $registration_status['class']; ?>"
                                >

                                    <span
                                        class="w-1.5 h-1.5 rounded-full <?= $registration_status['dot']; ?>"
                                    ></span>

                                    <?= htmlspecialchars(
                                        $registration_status['text']
                                    ); ?>

                                </span>


                                <?php if (!empty($days_left)): ?>

                                    <span class="text-xs font-medium text-slate-400">

                                        <?= htmlspecialchars($days_left); ?>

                                    </span>

                                <?php endif; ?>

                            </div>


                            <h3
                                class="text-lg sm:text-xl font-bold text-slate-900 truncate"
                            >

                                <?= htmlspecialchars(
                                    $event['title']
                                ); ?>

                            </h3>


                            <div
                                class="flex flex-col sm:flex-row sm:flex-wrap gap-x-5 gap-y-2 mt-3 text-xs sm:text-sm text-slate-500"
                            >


                                <span class="inline-flex items-center gap-2">

                                    <i class="fa-regular fa-calendar text-rmc-800 w-4"></i>

                                    <?= format_event_date(
                                        $event['event_date']
                                    ); ?>

                                </span>


                                <span class="inline-flex items-center gap-2">

                                    <i class="fa-regular fa-clock text-rmc-800 w-4"></i>

                                    <?php if (!empty($event['start_time'])): ?>

                                        <?= format_event_time(
                                            $event['start_time']
                                        ); ?>

                                    <?php endif; ?>


                                    <?php if (!empty($event['end_time'])): ?>

                                        –
                                        <?= format_event_time(
                                            $event['end_time']
                                        ); ?>

                                    <?php endif; ?>

                                </span>


                                <span class="inline-flex items-center gap-2">

                                    <i class="fa-solid fa-location-dot text-red-500 w-4"></i>

                                    <span class="truncate max-w-[250px]">

                                        <?= htmlspecialchars(
                                            $event['venue']
                                        ); ?>

                                    </span>

                                </span>

                            </div>

                        </div>


                        <!-- REGISTRATION INFO -->

                        <div
                            class="w-full xl:w-64 shrink-0 border-t xl:border-t-0 xl:border-l border-slate-100 pt-4 xl:pt-0 xl:pl-5"
                        >


                            <?php if ($registration_limit > 0): ?>


                                <div
                                    class="flex justify-between text-xs mb-2"
                                >

                                    <span class="text-slate-500">
                                        Registration
                                    </span>

                                    <span class="font-bold text-slate-700">

                                        <?= $registered_count; ?>

                                        /

                                        <?= $registration_limit; ?>

                                    </span>

                                </div>


                                <div
                                    class="w-full h-2 bg-slate-100 rounded-full overflow-hidden"
                                >

                                    <div
                                        class="progress-bar bg-rmc-700 h-2 rounded-full"
                                        style="width: <?= $percentage; ?>%;"
                                    ></div>

                                </div>


                                <p class="text-[11px] text-slate-400 mt-2">

                                    <?= $percentage; ?>% of slots filled

                                </p>


                            <?php else: ?>


                                <p class="text-xs text-slate-500">

                                    <i class="fa-solid fa-users mr-1"></i>

                                    <?= $registered_count; ?> registered

                                    <span class="text-slate-400">
                                        • No limit
                                    </span>

                                </p>


                            <?php endif; ?>


                            <div class="flex gap-2 mt-4">


                                <a
                                    href="events.php"
                                    class="flex-1 inline-flex items-center justify-center gap-2 bg-slate-900 hover:bg-slate-800 text-white px-4 py-2.5 rounded-xl text-xs font-bold transition"
                                >

                                    View Event

                                    <i class="fa-solid fa-arrow-right text-[10px]"></i>

                                </a>


                                <?php if ($is_registered): ?>


                                    <a
                                        href="my_qr.php"
                                        class="inline-flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2.5 rounded-xl text-xs font-bold transition"
                                        title="View QR"
                                    >

                                        <i class="fa-solid fa-qrcode"></i>

                                    </a>


                                <?php elseif (
                                    $registration_limit <= 0 ||
                                    $registered_count < $registration_limit
                                ): ?>


                                    <a
                                        href="events.php"
                                        class="inline-flex items-center justify-center gap-2 bg-rmc-800 hover:bg-rmc-900 text-white px-4 py-2.5 rounded-xl text-xs font-bold transition"
                                    >

                                        Register

                                    </a>


                                <?php else: ?>


                                    <span
                                        class="inline-flex items-center justify-center bg-slate-100 text-slate-400 px-4 py-2.5 rounded-xl text-xs font-bold cursor-not-allowed"
                                    >

                                        Full

                                    </span>


                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                </div>


            <?php endwhile; ?>

        </div>


    <?php else: ?>


        <!-- EMPTY STATE -->

        <div class="text-center py-14 px-5">

            <div
                class="w-16 h-16 mx-auto rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400 text-2xl mb-4"
            >

                <i class="fa-regular fa-calendar"></i>

            </div>


            <h3 class="text-lg font-bold text-slate-700">
                No Upcoming Events
            </h3>


            <p class="text-sm text-slate-500 mt-2">
                There are currently no approved upcoming events.
            </p>


            <a
                href="events.php"
                class="inline-flex items-center gap-2 mt-5 bg-rmc-800 hover:bg-rmc-900 text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition"
            >

                Browse Events

                <i class="fa-solid fa-arrow-right text-xs"></i>

            </a>

        </div>


    <?php endif; ?>

</section>

<?php endif; ?>


<!-- =========================================================
     ORGANIZER EVENTS
     ========================================================= -->

<?php if ($role === "organizer"): ?>


<section
    class="mt-7 lg:mt-8 content-card bg-white border border-slate-200 rounded-[26px] shadow-sm p-5 sm:p-6 lg:p-8 animate-up"
>


    <div
        class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6"
    >

        <div>

            <div class="flex items-center gap-3">

                <div
                    class="w-9 h-9 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center"
                >

                    <i class="fa-solid fa-calendar-check"></i>

                </div>

                <div>

                    <h2 class="text-xl sm:text-2xl font-bold text-slate-900">
                        My Events
                    </h2>

                    <p class="text-sm text-slate-500 mt-1">
                        Manage and monitor the events you've created.
                    </p>

                </div>

            </div>

        </div>


        <a
            href="create_event.php"
            class="inline-flex items-center justify-center gap-2 bg-rmc-800 hover:bg-rmc-900 text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition"
        >

            <i class="fa-solid fa-plus"></i>

            New Event

        </a>

    </div>


    <?php if ($my_events && pg_num_rows($my_events) > 0): ?>

        <form method="POST" id="bulkCancelForm">
            <?= csrf_field(); ?>

            <div id="bulkCancelBar" class="hidden mb-4 flex flex-col sm:flex-row items-start sm:items-center gap-3 bg-red-50 border border-red-200 rounded-2xl px-5 py-3 animate-up">
                <div class="flex items-center gap-3 flex-1 min-w-0">
                    <i class="fa-solid fa-triangle-exclamation text-red-600"></i>
                    <span class="text-sm font-semibold text-red-800"><span id="bulkCancelCount">0</span> <?= t('selected_count'); ?></span>
                </div>
                <button type="button" onclick="openConfirmModal({bulkForm: document.getElementById('bulkCancelForm'), title: <?= json_encode(t('bulk_cancel_confirm_title') ?: 'Cancel Events') ?>, message: <?= json_encode(t('bulk_cancel_confirm_msg') ?: 'Are you sure you want to cancel the selected events? This cannot be undone.') ?>, itemName: '<?= t('selected_events') ?>', itemLabel: <?= json_encode(t('event')) ?>, actionText: <?= json_encode(t('cancel')) ?>, color: 'red', icon: 'fa-solid fa-xmark'});"
                    class="inline-flex items-center gap-2 bg-red-700 hover:bg-red-800 text-white px-4 py-2 rounded-xl text-sm font-semibold transition">
                    <i class="fa-solid fa-xmark"></i>
                    <?= t('cancel_events'); ?>
                </button>
            </div>
        </form>

        <div class="overflow-x-auto">

            <table class="min-w-full">

                <thead>

                    <tr
                        class="border-b border-slate-200 text-[10px] sm:text-xs uppercase tracking-wider text-slate-400"
                    >

                        <th class="px-4 py-4 text-center w-12">
                            <input type="checkbox" id="selectAllEvents" class="bulk-select-all w-4 h-4 rounded border-slate-300 text-rmc-800 focus:ring-rmc-500 cursor-pointer" aria-label="<?= t('select_all'); ?>" data-target="event-row-cb">
                        </th>

                        <th class="px-4 py-4 text-left">
                            Event
                        </th>

                        <th class="px-4 py-4 text-left">
                            Date
                        </th>

                        <th class="px-4 py-4 text-left">
                            Venue
                        </th>

                        <th class="px-4 py-4 text-center">
                            Status
                        </th>

                        <th class="px-4 py-4 text-center">
                            Actions
                        </th>

                    </tr>

                </thead>


                <tbody>


                    <?php while ($ev = pg_fetch_assoc($my_events)): ?>


                        <tr
                            class="border-b border-slate-100 hover:bg-slate-50 transition event-row"
                            data-event-id="<?= $ev['event_id']; ?>"
                            data-event-status="<?= htmlspecialchars($ev['status']); ?>"
                        >

                            <td class="px-4 py-4 text-center">
                                <?php if ($ev['status'] === 'approved'): ?>
                                <input type="checkbox" name="bulk_cancel_ids[]" value="<?= $ev['event_id']; ?>" class="bulk-cb event-row-cb w-4 h-4 rounded border-slate-300 text-rmc-800 focus:ring-rmc-500 cursor-pointer" aria-label="<?= t('select_event'); ?>">
                                <?php endif; ?>
                            </td>

                            <td class="px-4 py-4">

                                <div class="font-semibold text-slate-800">
                                    <?= htmlspecialchars($ev['title']); ?>
                                </div>

                            </td>


                            <td class="px-4 py-4 text-sm text-slate-500">

                                <?= format_event_date(
                                    $ev['event_date']
                                ); ?>

                            </td>


                            <td class="px-4 py-4 text-sm text-slate-500">

                                <?= htmlspecialchars($ev['venue']); ?>

                            </td>


                            <td class="px-4 py-4 text-center">

                                <span
                                    class="inline-flex px-3 py-1 rounded-full text-xs font-semibold <?= dash_status_badge($ev['status']); ?>"
                                >

                                    <?= ucfirst(
                                        htmlspecialchars($ev['status'])
                                    ); ?>

                                </span>

                            </td>


                            <td class="px-4 py-4">

                                <div
                                    class="flex justify-center gap-2 flex-wrap"
                                >


                                    <?php if ($ev['status'] !== 'cancelled'): ?>


                                        <a
                                            href="edit_event.php?id=<?= $ev['event_id']; ?>"
                                            class="inline-flex items-center gap-2 bg-rmc-800 hover:bg-rmc-900 text-white px-3 py-2 rounded-lg text-xs font-semibold transition"
                                        >

                                            <i class="fa-solid fa-pen"></i>

                                            <?= t('edit'); ?>

                                        </a>


                                        <a
                                            href="manage_photos.php?id=<?= $ev['event_id']; ?>"
                                            class="inline-flex items-center gap-2 bg-slate-800 hover:bg-slate-700 text-white px-3 py-2 rounded-lg text-xs font-semibold transition"
                                        >

                                            <i class="fa-solid fa-image"></i>

                                            Photos

                                        </a>


                                        <?php if ($ev['status'] === 'approved'): ?>


                                            <form
                                                method="POST"
                                                action="cancel_event.php"
                                                data-action-form="cancel-<?= $ev['event_id']; ?>"
                                            >

                                                <?= csrf_field(); ?>


                                                <input
                                                    type="hidden"
                                                    name="event_id"
                                                    value="<?= $ev['event_id']; ?>"
                                                >

                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-lg text-xs font-semibold transition"
                                                    onclick="openConfirmModal({form: this.closest('form'), title: <?= json_encode(t('cancel')) ?>, message: <?= json_encode(t('cancel_event_confirm')) ?>, itemName: <?= json_encode(htmlspecialchars($ev['title'], ENT_QUOTES)) ?>, itemLabel: <?= json_encode(t('event')) ?>, actionText: <?= json_encode(t('cancel')) ?>, color: 'red', icon: 'fa-solid fa-xmark'});"
                                                >

                                                    <i class="fa-solid fa-xmark"></i>

                                                    <?= t('cancel'); ?>

                                                </button>

                                            </form>


                                        <?php endif; ?>


                                    <?php else: ?>


                                        <span class="text-slate-400 text-xs italic">
                                            Event Cancelled
                                        </span>


                                    <?php endif; ?>


                                </div>

                            </td>

                        </tr>


                    <?php endwhile; ?>


                </tbody>

            </table>

        </div>

        </form>

    <?php else: ?>


        <div class="text-center py-12">

            <div
                class="w-14 h-14 mx-auto rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400 text-xl mb-4"
            >

                <i class="fa-regular fa-calendar-plus"></i>

            </div>


            <p class="text-slate-500 text-sm">
                You haven't created any events yet.
            </p>


            <a
                href="create_event.php"
                class="inline-flex items-center gap-2 mt-5 bg-rmc-800 hover:bg-rmc-900 text-white px-5 py-2.5 rounded-xl text-sm font-semibold"
            >

                <i class="fa-solid fa-plus"></i>

                Create Your First Event

            </a>

        </div>


    <?php endif; ?>

</section>

<?php endif; ?>


<!-- =========================================================
     ADMIN QUICK ACTIONS
     ========================================================= -->

<?php if ($role === "admin"): ?>


<section
    class="mt-7 lg:mt-8 content-card bg-white border border-slate-200 rounded-[26px] shadow-sm p-5 sm:p-6 lg:p-8 animate-up"
>


    <div class="mb-6">

        <div class="flex items-center gap-3">

            <div
                class="w-9 h-9 rounded-xl bg-red-50 text-red-600 flex items-center justify-center"
            >

                <i class="fa-solid fa-shield-halved"></i>

            </div>

            <div>

                <h2 class="text-xl sm:text-2xl font-bold text-slate-900">
                    Quick Administration
                </h2>

                <p class="text-sm text-slate-500 mt-1">
                    Access the most frequently used administration tools.
                </p>

            </div>

        </div>

    </div>


    <div
        class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4"
    >


        <!-- MANAGE EVENTS -->

        <a
            href="admin_events.php"
            class="quick-card group border border-slate-200 rounded-2xl p-5 hover:border-rmc-300 transition"
        >

            <div
                class="w-11 h-11 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center text-lg mb-4 group-hover:scale-105 transition"
            >

                <i class="fa-solid fa-calendar-check"></i>

            </div>

            <h3 class="font-bold text-slate-900">
                <?= t('manage_events'); ?>
            </h3>

            <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                Review and manage campus events.
            </p>

            <div
                class="mt-4 text-xs font-semibold text-rmc-800 flex items-center gap-2"
            >

                Open

                <i class="fa-solid fa-arrow-right text-[10px]"></i>

            </div>

        </a>


        <!-- USERS -->

        <a
            href="admin_users.php"
            class="quick-card group border border-slate-200 rounded-2xl p-5 hover:border-rmc-300 transition"
        >

            <div
                class="w-11 h-11 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center text-lg mb-4 group-hover:scale-105 transition"
            >

                <i class="fa-solid fa-users"></i>

            </div>

            <h3 class="font-bold text-slate-900">
                <?= t('manage_users'); ?>
            </h3>

            <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                Manage system accounts and roles.
            </p>

            <div
                class="mt-4 text-xs font-semibold text-rmc-800 flex items-center gap-2"
            >

                Open

                <i class="fa-solid fa-arrow-right text-[10px]"></i>

            </div>

        </a>


        <!-- REPORTS -->

        <a
            href="reports.php"
            class="quick-card group border border-slate-200 rounded-2xl p-5 hover:border-emerald-300 transition"
        >

            <div
                class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center text-lg mb-4 group-hover:scale-105 transition"
            >

                <i class="fa-solid fa-chart-column"></i>

            </div>

            <h3 class="font-bold text-slate-900">
                <?= t('reports'); ?>
            </h3>

            <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                View attendance and event analytics.
            </p>

            <div
                class="mt-4 text-xs font-semibold text-emerald-700 flex items-center gap-2"
            >

                Open

                <i class="fa-solid fa-arrow-right text-[10px]"></i>

            </div>

        </a>


        <!-- EMAIL LOGS -->

        <a
            href="admin_email_logs.php"
            class="quick-card group border border-slate-200 rounded-2xl p-5 hover:border-red-300 transition"
        >

            <div
                class="w-11 h-11 rounded-xl bg-red-50 text-red-600 flex items-center justify-center text-lg mb-4 group-hover:scale-105 transition"
            >

                <i class="fa-solid fa-envelope"></i>

            </div>

            <h3 class="font-bold text-slate-900">
                <?= t('email_logs'); ?>
            </h3>

            <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                Monitor system email activity.
            </p>

            <div
                class="mt-4 text-xs font-semibold text-red-600 flex items-center gap-2"
            >

                Open

                <i class="fa-solid fa-arrow-right text-[10px]"></i>

            </div>

        </a>


    </div>

</section>

<?php endif; ?>


<!-- =========================================================
     GENERIC CONFIRMATION MODAL
     ========================================================= -->

<div
    id="confirmModal"
    class="hidden fixed inset-0 z-[80] bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4 transition-opacity duration-200"
    onclick="if (event.target === this) closeConfirmModal();"
    role="dialog"
    aria-modal="true"
    aria-labelledby="confirmModalTitle"
>

    <div class="bg-white rounded-3xl w-full max-w-md shadow-2xl overflow-hidden transform transition-transform duration-200 scale-95 opacity-0" id="confirmModalInner">

        <div id="confirmModalHeader" class="bg-red-700 text-white px-6 py-5 flex items-center gap-3">

            <div class="w-11 h-11 rounded-2xl bg-white/15 flex items-center justify-center shrink-0">

                <i id="confirmModalIcon" class="fa-solid fa-trash text-lg"></i>

            </div>

            <div class="min-w-0">

                <h3 id="confirmModalTitle" class="font-bold text-lg leading-snug">
                </h3>

            </div>

        </div>

        <div class="p-6">

            <p id="confirmModalMessage" class="text-sm text-slate-600 leading-relaxed">
            </p>

            <div id="confirmModalItemBox" class="mt-4 bg-red-50 border border-red-200 rounded-2xl px-4 py-3">

                <p id="confirmModalItemLabel" class="text-[10px] font-bold uppercase tracking-wide text-red-500 mb-1">
                </p>

                <p id="confirmModalItemName" class="font-bold text-slate-900 break-words">
                    —
                </p>

            </div>

        </div>

        <div class="px-6 pb-6 flex flex-col-reverse sm:flex-row gap-3">

            <button
                type="button"
                onclick="closeConfirmModal();"
                class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold px-5 py-3 rounded-xl transition"
            >
                <?= htmlspecialchars(t('cancel')); ?>
            </button>

            <button
                type="button"
                id="confirmModalConfirmBtn"
                class="flex-1 bg-red-700 hover:bg-red-800 text-white font-bold px-5 py-3 rounded-xl transition inline-flex items-center justify-center gap-2"
            >
                <i id="confirmModalBtnIcon" class="fa-solid fa-trash"></i>
                <span id="confirmModalBtnText"></span>
            </button>

        </div>

    </div>

</div>


<script>

var CONFIRM_PENDING_FORM = null;

var COLOR_MAP = {
    red:     { header: 'bg-red-700',    itemBg: 'bg-red-50',    itemBorder: 'border-red-200',    itemLabel: 'text-red-500',    btn: 'bg-red-700 hover:bg-red-800' },
    emerald: { header: 'bg-emerald-700',itemBg: 'bg-emerald-50',itemBorder: 'border-emerald-200',itemLabel: 'text-emerald-500',btn: 'bg-emerald-700 hover:bg-emerald-800' },
    amber:   { header: 'bg-amber-700',  itemBg: 'bg-amber-50',  itemBorder: 'border-amber-200',  itemLabel: 'text-amber-500',  btn: 'bg-amber-700 hover:bg-amber-800' },
    slate:   { header: 'bg-slate-700',  itemBg: 'bg-slate-50',  itemBorder: 'border-slate-200',  itemLabel: 'text-slate-500',  btn: 'bg-slate-700 hover:bg-slate-800' },
    blue:    { header: 'bg-blue-700',   itemBg: 'bg-blue-50',   itemBorder: 'border-blue-200',   itemLabel: 'text-blue-500',   btn: 'bg-blue-700 hover:bg-blue-800' }
};

function openConfirmModal(opts) {

    CONFIRM_PENDING_FORM = opts.form || opts.bulkForm || null;

    var c = COLOR_MAP[opts.color] || COLOR_MAP.red;

    document.getElementById('confirmModalHeader').className = c.header + ' text-white px-6 py-5 flex items-center gap-3';
    document.getElementById('confirmModalIcon').className = (opts.icon || 'fa-solid fa-trash') + ' text-lg';
    document.getElementById('confirmModalTitle').textContent = opts.title || '';
    document.getElementById('confirmModalMessage').textContent = opts.message || '';

    var itemBox = document.getElementById('confirmModalItemBox');
    itemBox.className = 'mt-4 ' + c.itemBg + ' border ' + c.itemBorder + ' rounded-2xl px-4 py-3';

    document.getElementById('confirmModalItemLabel').className = 'text-[10px] font-bold uppercase tracking-wide ' + c.itemLabel + ' mb-1';
    document.getElementById('confirmModalItemLabel').textContent = opts.itemLabel || '';
    document.getElementById('confirmModalItemName').textContent = opts.itemName || '—';

    var btn = document.getElementById('confirmModalConfirmBtn');
    btn.className = 'flex-1 ' + c.btn + ' text-white font-bold px-5 py-3 rounded-xl transition inline-flex items-center justify-center gap-2';
    document.getElementById('confirmModalBtnIcon').className = (opts.icon || 'fa-solid fa-trash');
    document.getElementById('confirmModalBtnText').textContent = opts.actionText || '';

    var modal = document.getElementById('confirmModal');
    var inner = document.getElementById('confirmModalInner');
    modal.classList.remove('hidden');
    inner.style.transform = 'scale(0.95)';
    inner.style.opacity = '0';

    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            inner.style.transform = 'scale(1)';
            inner.style.opacity = '1';
        });
    });

    document.body.style.overflow = 'hidden';

    setTimeout(function() { btn.focus(); }, 200);
}

function closeConfirmModal() {

    var modal = document.getElementById('confirmModal');
    var inner = document.getElementById('confirmModalInner');
    inner.style.transform = 'scale(0.95)';
    inner.style.opacity = '0';

    setTimeout(function() {
        modal.classList.add('hidden');
        inner.style.transform = '';
        inner.style.opacity = '';
        document.body.style.overflow = '';
    }, 150);

    CONFIRM_PENDING_FORM = null;
}

document.getElementById('confirmModalConfirmBtn')
    .addEventListener('click', function () {

        if (CONFIRM_PENDING_FORM) {
            CONFIRM_PENDING_FORM.submit();
        }
    });

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeConfirmModal();
    }
    if (event.key === 'Tab' && !document.getElementById('confirmModal').classList.contains('hidden')) {
        var modal = document.getElementById('confirmModalInner');
        var focusable = modal.querySelectorAll('button:not([disabled])');
        if (focusable.length === 0) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey) {
            if (document.activeElement === first) { event.preventDefault(); last.focus(); }
        } else {
            if (document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    }
});

/* ===== Bulk Select — Organizer Events ===== */
(function() {
    var selectAll = document.getElementById('selectAllEvents');
    var bar = document.getElementById('bulkCancelBar');
    var counter = document.getElementById('bulkCancelCount');
    var cbs = document.querySelectorAll('.event-row-cb');

    if (!selectAll || cbs.length === 0) return;

    function sync() {
        var checked = document.querySelectorAll('.event-row-cb:checked').length;
        counter.textContent = checked;
        if (bar) bar.classList.toggle('hidden', checked === 0);
        selectAll.checked = checked > 0 && checked === cbs.length;
        selectAll.indeterminate = checked > 0 && checked < cbs.length;
    }

    selectAll.addEventListener('change', function() {
        cbs.forEach(function(cb) { cb.checked = selectAll.checked; });
        sync();
    });

    cbs.forEach(function(cb) { cb.addEventListener('change', sync); });
    sync();
})();

</script>


<?php include 'partials/footer.php'; ?>

