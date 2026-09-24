<?php


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
 | STUDENT ACTIVITIES (REGISTRATIONS & EVENTS)
 |--------------------------------------------------------------------------
 | Shows events the student registered for, with registration status,
 | event details, and verification status. Newest first.
 |
 | Check-in data lives on the separate `attendance` table (one row per
 | registration, created when an organizer scans/checks the student in),
 | so it's brought in with a LEFT JOIN — registrations with no scan yet
 | simply come back with NULL attendance fields.
 |--------------------------------------------------------------------------
 */

$activities = $pdo->prepare("
    SELECT
        r.registration_id,
        e.event_id,
        e.title,
        e.event_date,
        e.start_time,
        e.end_time,
        e.venue,
        e.status AS event_status,
        r.status AS registration_status,
        r.registered_at,
        a.verified,
        a.scanned_at,
        a.checked_in_at

    FROM users
    JOIN registrations r ON r.user_id = users.user_id
    JOIN events e ON e.event_id = r.event_id
    LEFT JOIN attendance a ON a.registration_id = r.registration_id

    WHERE users.user_id = ?

    ORDER BY e.event_date DESC, e.start_time DESC
");

$activities->execute([$student_id]);
$activity_rows = $activities->fetchAll(PDO::FETCH_ASSOC);

/*
 |--------------------------------------------------------------------------
 | SUMMARY STATS
 |--------------------------------------------------------------------------
 */

$total_attended = count($activity_rows);
$total_verified = 0;

foreach ($activity_rows as $row) {
    if (!empty($row['verified'])) {
        $total_verified++;
    }
}

/*
 |--------------------------------------------------------------------------
 | NOTIFICATION DATA (for the shared header bell)
 |--------------------------------------------------------------------------
 */

$unread_stmt = $pdo->prepare("
    SELECT COUNT(*) AS cnt
    FROM notifications
    WHERE user_id = ?
      AND is_read = 0
");
$unread_stmt->execute([$student_id]);
$unread_count = (int) $unread_stmt->fetchColumn();

$recent_notifications = $pdo->prepare("
    SELECT notification_id, type, message, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");
$recent_notifications->execute([$student_id]);

$page_title  = t('title_my_activities');
$active_page = 'my_activities';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campus Event System - My Activities</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.2.0/remixicon.min.css">
    <link rel="stylesheet" href="https://unpkg.com/swiper/swiper-bundle.min.css">
</head>
<body>

<?php include 'partials/head.php'; ?>
<?php include 'partials/sidebar.php'; ?>
<?php include 'partials/header.php'; ?>


<!-- MY ACTIVITIES HERO -->
<div class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up">
    <div class="flex items-center gap-4 sm:gap-5">
        <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0">
            <i class="fa-solid fa-user-clock text-2xl"></i>
        </div>
        <div>
            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                My Activities
            </h2>
            <p class="text-slate-600 mt-1 text-sm sm:text-base">
                Your event registrations and attendance status
            </p>
        </div>
    </div>
</div>


<!-- SUMMARY STATS -->
<div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-8 animate-up delay-1">

    <div class="bg-white border border-slate-200 rounded-[26px] shadow-sm p-6 flex items-center gap-5">
        <div class="w-12 h-12 rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-calendar-check"></i>
        </div>
        <div>
            <p class="text-2xl sm:text-3xl font-bold text-slate-900 counter" data-value="<?= $total_attended; ?>">
                <?= $total_attended; ?>
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
                <?= $total_verified; ?>
            </p>
            <p class="text-sm text-slate-500">
                <?= t('attendance_status_verified'); ?>
            </p>
        </div>
    </div>

</div>


<!-- ACTIVITIES LIST -->
<div class="animate-up delay-1">

    <?php if (count($activity_rows) === 0): ?>

        <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm px-6 py-20 text-center animate-up delay-1">
            <div class="w-16 h-16 mx-auto rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-5">
                <i class="fa-solid fa-user-clock text-3xl"></i>
            </div>
            <h2 class="text-xl font-bold text-slate-800">
                <?= t('no_activities_title'); ?>
            </h2>
            <p class="text-slate-500 mt-2 max-w-sm mx-auto">
                <?= t('no_activities_desc'); ?>
            </p>
            <a href="events.php" class="inline-flex items-center gap-2 mt-6 px-6 py-3 rounded-xl bg-rmc-800 text-white text-sm font-semibold hover:bg-rmc-700 transition">
                <?= t('browse_events'); ?>
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </a>
        </div>

    <?php else: ?>

        <div class="space-y-5">

            <?php foreach ($activity_rows as $row): ?>

                <?php $is_verified = !empty($row['verified']); ?>

                <div class="bg-white border border-slate-200 rounded-[26px] shadow-sm p-5 sm:p-6 hover:shadow-lg hover:-translate-y-0.5 transition animate-up">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-4">

                        <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center text-xl shrink-0">
                            <i class="fa-solid fa-calendar-day"></i>
                        </div>

                        <div class="flex-1 min-w-0">

                            <div class="flex flex-wrap items-center gap-2">

                                <h3 class="text-base sm:text-lg font-bold text-slate-900 leading-snug">
                                    <?= htmlspecialchars($row['title']); ?>
                                </h3>

                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-xs font-semibold">
                                    <i class="fa-solid fa-circle-check"></i>
                                    <?= $is_verified ? t('attendance_status_verified') : t('attendance_status_pending'); ?>
                                </span>

                            </div>

                            <p class="text-sm text-slate-500 mt-1.5">
                                <i class="fa-solid fa-calendar text-slate-400 mr-1"></i>
                                <?= htmlspecialchars(
                                    date('F d, Y', strtotime($row['event_date']))
                                ); ?>

                                <?php if (!empty($row['start_time'])): ?>
                                    <span class="mx-1">·</span>
                                    <i class="fa-regular fa-clock text-slate-400 mr-1"></i>
                                    <?= htmlspecialchars(
                                        date('g:i A', strtotime($row['start_time']))
                                    ); ?>
                                <?php endif; ?>

                                <?php if (!empty($row['venue'])): ?>
                                    <span class="mx-1">·</span>
                                    <i class="fa-solid fa-location-dot text-slate-400 mr-1"></i>
                                    <?= htmlspecialchars($row['venue']); ?>
                                <?php endif; ?>
                            </p>

                        </div>
                    </div>
                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>


    <!-- NOTIFICATION COUNT BADGE -->
    <div class="mt-8 bg-white rounded-[26px] border border-slate-200 shadow-sm p-4">
        <div class="flex items-center gap-3">
            <span class="text-sm text-slate-600">
                <?= t('unread_notifications'); ?>: <?= $unread_count; ?>
            </span>
            <a href="notifications.php" class="text-sm text-rmc-800 hover:text-rmc-600 transition">
                View all
            </a>
        </div>
    </div>

</div>


<?php include 'partials/footer.php'; ?>

</body>
</html>