<?php

session_start();

require 'db_connect.php';
require 'lang.php';

/*
|--------------------------------------------------------------------------
| ROLE-BASED ACCESS
|--------------------------------------------------------------------------
| This page is ONLY for students.
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'student'
) {
    http_response_code(403);
    die("Access denied. Students only.");
}

$student_id = (int) $_SESSION['user_id'];
$role = 'student';
$full_name = $_SESSION['full_name'] ?? 'Student';
$first_name = explode(' ', trim($full_name))[0];

/*
|--------------------------------------------------------------------------
| STUDENT ATTENDANCE HISTORY
|--------------------------------------------------------------------------
| Shows events the student attended (attendance records) with check-in
| time and verification status, newest first.
|--------------------------------------------------------------------------
*/

$activities = pg_query_params(
    $conn,
    "SELECT
        a.attendance_id,
        a.verified,
        a.scanned_at,
        a.checked_in_at,

        e.event_id,
        e.title,
        e.category,
        e.event_date,
        e.start_time,
        e.end_time,
        e.venue,

        r.registration_id,
        r.status AS registration_status

     FROM attendance a

     JOIN registrations r
        ON r.registration_id = a.registration_id

     JOIN events e
        ON e.event_id = r.event_id

     WHERE r.user_id = $1

     ORDER BY e.event_date DESC, e.start_time DESC",
    array($student_id)
);

if (!$activities) {

    error_log(
        "My Activities query failed: " .
        pg_last_error($conn)
    );

    die("Unable to load your activities.");
}

$activity_rows = array();

while ($row = pg_fetch_assoc($activities)) {
    $activity_rows[] = $row;
}

/*
|--------------------------------------------------------------------------
| SUMMARY STATS
|--------------------------------------------------------------------------
*/

$total_attended = 0;
$total_verified = 0;

foreach ($activity_rows as $row) {

    $total_attended++;

    $is_verified =
        $row['verified'] === 't' ||
        $row['verified'] === true ||
        $row['verified'] === '1' ||
        $row['verified'] === 1;

    if ($is_verified) {
        $total_verified++;
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

$page_title  = t('title_my_activities');
$active_page = 'my_activities';

?>

<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     MY ACTIVITIES HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 flex flex-col sm:flex-row sm:items-center gap-6 mb-8 animate-up"
>

    <div
        class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
    >

        <i class="fa-solid fa-user-clock text-2xl"></i>

    </div>

    <div class="min-w-0">

        <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
            <?= t('my_activities'); ?>
        </h2>

        <p class="text-slate-600 mt-1 text-sm sm:text-base">
            <?= t('my_activities_hero_desc'); ?>
        </p>

    </div>

</div>


<!-- =========================================================
     SUMMARY STATS
     ========================================================= -->

<div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-8 animate-up delay-1">

    <div class="bg-white border border-slate-200 rounded-[26px] shadow-sm p-6 flex items-center gap-5">

        <div class="w-12 h-12 rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-calendar-check"></i>
        </div>

        <div>

            <p class="text-2xl sm:text-3xl font-bold text-slate-900 counter" data-value="<?= $total_attended; ?>">
                0
            </p>

            <p class="text-sm text-slate-500">
                <?= t('events_attended'); ?>
            </p>

        </div>

    </div>

    <div class="bg-white border border-slate-200 rounded-[26px] shadow-sm p-6 flex items-center gap-5">

        <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-700 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-circle-check"></i>
        </div>

        <div>

            <p class="text-2xl sm:text-3xl font-bold text-slate-900 counter" data-value="<?= $total_verified; ?>">
                0
            </p>

            <p class="text-sm text-slate-500">
                <?= t('attendance_status_verified'); ?>
            </p>

        </div>

    </div>

</div>


<!-- =========================================================
     ACTIVITIES LIST
     ========================================================= -->

<?php if (count($activity_rows) === 0): ?>

    <div
        class="bg-white rounded-[26px] border border-slate-200 shadow-sm px-6 py-20 text-center animate-up delay-1"
    >

        <div
            class="w-16 h-16 mx-auto rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-5"
        >

            <i class="fa-solid fa-user-clock text-3xl"></i>

        </div>

        <h2 class="text-xl font-bold text-slate-800">
            <?= t('no_activities_title'); ?>
        </h2>

        <p class="text-slate-500 mt-2 max-w-sm mx-auto">
            <?= t('no_activities_desc'); ?>
        </p>

        <a
            href="events.php"
            class="inline-flex items-center gap-2 mt-6 px-6 py-3 rounded-xl bg-rmc-800 text-white text-sm font-semibold hover:bg-rmc-700 transition"
        >

            <?= t('browse_events'); ?>

            <i class="fa-solid fa-arrow-right text-xs"></i>

        </a>

    </div>

<?php else: ?>

    <div class="space-y-5">

        <?php foreach ($activity_rows as $row): ?>

            <?php

            $is_verified =
                $row['verified'] === 't' ||
                $row['verified'] === true ||
                $row['verified'] === '1' ||
                $row['verified'] === 1;

            if ($is_verified) {

                $activity_badge =
                    'bg-emerald-50 text-emerald-700 border-emerald-200';

                $activity_icon =
                    'fa-circle-check';

                $activity_title =
                    t('attendance_status_verified');

            } else {

                $activity_badge =
                    'bg-amber-50 text-amber-700 border-amber-200';

                $activity_icon =
                    'fa-clock';

                $activity_title =
                    t('attendance_status_pending');

            }

            $formatted_date =
                date(
                    'F d, Y',
                    strtotime($row['event_date'])
                );

            $check_in_display = '';

            if (!empty($row['checked_in_at'])) {

                $check_in_display =
                    date(
                        'g:i A',
                        strtotime($row['checked_in_at'])
                    );

            }

            ?>

            <div
                class="bg-white border border-slate-200 rounded-[26px] shadow-sm p-5 sm:p-6 hover:shadow-lg hover:-translate-y-0.5 transition animate-up"
            >

                <div class="flex flex-col sm:flex-row sm:items-center gap-4">

                    <div
                        class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center text-xl shrink-0"
                    >

                        <i class="fa-solid fa-calendar-day"></i>

                    </div>

                    <div class="flex-1 min-w-0">

                        <div class="flex flex-wrap items-center gap-2">

                            <h3 class="text-base sm:text-lg font-bold text-slate-900 leading-snug">
                                <?= htmlspecialchars($row['title']); ?>
                            </h3>

                            <span
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-xs font-semibold <?= $activity_badge; ?>"
                            >

                                <i class="fa-solid <?= $activity_icon; ?>"></i>

                                <?= $activity_title; ?>

                            </span>

                        </div>

                        <p class="text-sm text-slate-500 mt-1.5">

                            <i class="fa-solid fa-calendar text-slate-400 mr-1"></i>

                            <?= htmlspecialchars($formatted_date); ?>

                            <?php if (!empty($row['start_time'])): ?>

                                <span class="mx-1">·</span>

                                <i class="fa-regular fa-clock text-slate-400 mr-1"></i>

                                <?= htmlspecialchars(
                                    date(
                                        'g:i A',
                                        strtotime($row['start_time'])
                                    )
                                ); ?>

                            <?php endif; ?>

                            <?php if (!empty($row['venue'])): ?>

                                <span class="mx-1">·</span>

                                <i class="fa-solid fa-location-dot text-slate-400 mr-1"></i>

                                <?= htmlspecialchars($row['venue']); ?>

                            <?php endif; ?>

                        </p>

                    </div>

                    <?php if (!empty($check_in_display)): ?>

                        <div
                            class="shrink-0 flex sm:flex-col items-center sm:items-end gap-2 sm:gap-1 px-4 py-3 rounded-2xl bg-slate-50"
                        >

                            <span class="text-[10px] uppercase tracking-wide font-bold text-slate-400">
                                <?= t('checked_in_at'); ?>
                            </span>

                            <span class="text-sm font-semibold text-slate-800">
                                <?= htmlspecialchars($check_in_display); ?>
                            </span>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        <?php endforeach; ?>

    </div>

<?php endif; ?>


<?php include 'partials/footer.php'; ?>
