<?php

session_start();

include 'db_connect.php';
require 'send_email.php';
require 'lang.php';
require 'csrf.php';

if (
    !isset($_SESSION['user_id']) ||
    (
        $_SESSION['role'] !== 'admin' &&
        $_SESSION['role'] !== 'organizer'
    )
) {
    header("Location: login.php");
    exit();
}

$role      = $_SESSION['role'];
$user_id   = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? '';
$first_name = explode(' ', trim($full_name))[0];


/* =========================================================
   VALIDATE EVENT ID
   ========================================================= */

$event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;

if ($event_id <= 0) {
    header("Location: reports.php");
    exit();
}


/* =========================================================
   OPTIONAL FILTER PARAMS (for back link)
   ========================================================= */

$department_filter = isset($_GET['department'])
    ? trim($_GET['department'])
    : '';

$category_filter = isset($_GET['category'])
    ? trim($_GET['category'])
    : '';

$year_filter = isset($_GET['year'])
    ? trim($_GET['year'])
    : '';


/* =========================================================
   BUILD BACK URL
   ========================================================= */

$back_params = array();

if ($department_filter !== '') {
    $back_params['department'] = $department_filter;
}

if ($category_filter !== '') {
    $back_params['category'] = $category_filter;
}

if ($year_filter !== '') {
    $back_params['year'] = $year_filter;
}

$back_url = 'reports.php';

if (!empty($back_params)) {
    $back_url .= '?' . http_build_query($back_params);
}


/* =========================================================
   FETCH EVENT DATA
   ========================================================= */

$event_conditions = array("e.event_id = $1");
$event_params     = array($event_id);
$event_param_idx  = 2;

if ($role === 'organizer') {
    $event_conditions[] =
        "e.organizer_id = $" . $event_param_idx;
    $event_params[] = $user_id;
    $event_param_idx++;
}

$event_result = pg_query_params(
    $conn,

    "SELECT
        e.event_id,
        e.title,
        e.description,
        e.category,
        e.event_date,
        e.start_time,
        e.end_time,
        e.venue,
        e.status,
        e.organizer_id,
        u.full_name AS organizer_name

     FROM events e

     JOIN users u
        ON e.organizer_id = u.user_id

     WHERE " . implode(' AND ', $event_conditions),

    $event_params
);

if (!$event_result || pg_num_rows($event_result) === 0) {
    header("Location: " . $back_url);
    exit();
}

$event = pg_fetch_assoc($event_result);

$event_title      = $event['title'];
$event_date       = $event['event_date'];
$event_venue      = $event['venue'];
$event_status     = $event['status'];
$event_category   = $event['category'];
$organizer_name   = $event['organizer_name'];
$event_start_time = $event['start_time'];
$event_end_time   = $event['end_time'];


/* =========================================================
   FETCH PARTICIPANTS
   ========================================================= */

$participants_result = pg_query_params(
    $conn,

    "SELECT
        r.registration_id,
        r.registered_at,
        r.status AS registration_status,

        u.full_name AS student_name,
        u.student_id,
        u.department,

        a.attendance_id,
        a.checked_in_at

     FROM registrations r

     JOIN users u
        ON r.user_id = u.user_id

     LEFT JOIN attendance a
        ON r.registration_id = a.registration_id

     WHERE r.event_id = $1

     ORDER BY r.registered_at ASC",

    array($event_id)
);

$participants = array();

if ($participants_result) {
    while ($row = pg_fetch_assoc($participants_result)) {
        $participants[] = $row;
    }
}


/* =========================================================
   FETCH FEEDBACK
   ========================================================= */

$feedback_result = pg_query_params(
    $conn,

    "SELECT
        f.feedback_id,
        f.rating,
        f.comment,
        f.is_anonymous,
        f.created_at,
        u.full_name

     FROM feedback f

     JOIN users u
        ON f.user_id = u.user_id

     WHERE f.event_id = $1

     ORDER BY f.created_at DESC",

    array($event_id)
);

$feedback_items = array();

if ($feedback_result) {
    while ($row = pg_fetch_assoc($feedback_result)) {
        $feedback_items[] = $row;
    }
}


/* =========================================================
   CALCULATE STATISTICS
   ========================================================= */

$total_registrations = count($participants);

$total_attended = 0;

foreach ($participants as $p) {
    if (!empty($p['attendance_id'])) {
        $total_attended++;
    }
}

$attendance_rate =
    $total_registrations > 0
    ? round(($total_attended / $total_registrations) * 100, 1)
    : 0;

$avg_rating = 0;

if (count($feedback_items) > 0) {
    $rating_sum = 0;

    foreach ($feedback_items as $fb) {
        $rating_sum += (int) $fb['rating'];
    }

    $avg_rating =
        round($rating_sum / count($feedback_items), 1);
}


/* =========================================================
   STATUS BADGE
   ========================================================= */

$status_badge = 'bg-amber-50 text-amber-700 border border-amber-200';

if ($event_status === 'approved') {
    $status_badge = 'bg-emerald-50 text-emerald-700 border border-emerald-200';
} elseif ($event_status === 'rejected') {
    $status_badge = 'bg-red-50 text-red-700 border border-red-200';
} elseif ($event_status === 'cancelled' || $event_status === 'archived') {
    $status_badge = 'bg-slate-100 text-slate-600 border border-slate-200';
}


/* =========================================================
   PAGE SETUP
   ========================================================= */

$page_title  = t('event_analytics') . ' — ' . $event_title;
$active_page = 'reports';

?>

<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     BREADCRUMB + BACK
     ========================================================= -->

<div class="px-6 lg:px-8 pt-6 pb-2">

    <nav class="flex items-center gap-2 text-sm text-slate-500 mb-6">

        <a
            href="<?= htmlspecialchars($back_url); ?>"
            class="hover:text-rmc-700 transition font-medium"
        >
            <?= t('event_analytics'); ?>
        </a>

        <i class="fa-solid fa-chevron-right text-[10px] text-slate-400"></i>

        <span class="text-slate-800 font-semibold">
            <?= t('event_details'); ?>
        </span>

    </nav>

    <a
        href="<?= htmlspecialchars($back_url); ?>"
        class="inline-flex items-center gap-2 text-sm font-semibold text-rmc-700 hover:text-rmc-900 transition mb-6"
    >
        <i class="fa-solid fa-arrow-left"></i>
        <?= t('event_analytics'); ?>
    </a>

</div>


<!-- =========================================================
     EVENT HEADER CARD
     ========================================================= -->

<div class="px-6 lg:px-8">

    <div
        class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up"
    >

        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-6">

            <div class="flex items-start gap-4 sm:gap-5 min-w-0">

                <div
                    class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
                >
                    <i class="fa-solid fa-calendar-days text-2xl"></i>
                </div>

                <div class="min-w-0">

                    <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight break-words">
                        <?= htmlspecialchars($event_title); ?>
                    </h2>

                    <div class="flex flex-wrap items-center gap-3 mt-3">

                        <span class="inline-flex items-center text-sm text-slate-600">
                            <i class="fa-regular fa-calendar mr-2 text-rmc-600"></i>
                            <?php

                            $formatted_date = $event_date
                                ? date('F j, Y', strtotime($event_date))
                                : '-';

                            echo htmlspecialchars($formatted_date);

                            ?>
                        </span>

                        <?php if ($event_venue): ?>
                            <span class="inline-flex items-center text-sm text-slate-600">
                                <i class="fa-solid fa-location-dot mr-2 text-rmc-600"></i>
                                <?= htmlspecialchars($event_venue); ?>
                            </span>
                        <?php endif; ?>

                        <?php if ($event_category): ?>
                            <span class="inline-flex items-center text-sm text-slate-600">
                                <i class="fa-solid fa-tag mr-2 text-rmc-600"></i>
                                <?= htmlspecialchars($event_category); ?>
                            </span>
                        <?php endif; ?>

                        <span class="inline-flex items-center text-sm text-slate-600">
                            <i class="fa-solid fa-user-tie mr-2 text-rmc-600"></i>
                            <?= htmlspecialchars($organizer_name); ?>
                        </span>

                    </div>

                </div>

            </div>

            <span class="<?= $status_badge; ?> px-4 py-1.5 rounded-full text-xs font-semibold uppercase tracking-wide self-start md:self-auto shrink-0">
                <?= htmlspecialchars($event_status); ?>
            </span>

        </div>

    </div>


    <!-- =========================================================
         STATS GRID
         ========================================================= -->

    <div class="grid lg:grid-cols-4 md:grid-cols-2 sm:grid-cols-2 gap-5 sm:gap-6 mb-8">

        <!-- TOTAL REGISTRATIONS -->

        <div class="stat-card bg-white rounded-[26px] border border-slate-200 shadow-sm p-6">

            <div class="flex justify-between items-start gap-3">

                <div class="min-w-0">
                    <p class="text-slate-500 text-sm">
                        <?= t('registrations'); ?>
                    </p>
                    <h2 class="text-4xl font-bold mt-2 text-amber-600">
                        <?= $total_registrations; ?>
                    </h2>
                </div>

                <div class="w-14 h-14 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-user-plus text-xl"></i>
                </div>

            </div>

        </div>


        <!-- TOTAL ATTENDEES -->

        <div class="stat-card bg-white rounded-[26px] border border-slate-200 shadow-sm p-6">

            <div class="flex justify-between items-start gap-3">

                <div class="min-w-0">
                    <p class="text-slate-500 text-sm">
                        <?= t('attended'); ?>
                    </p>
                    <h2 class="text-4xl font-bold mt-2 text-emerald-600">
                        <?= $total_attended; ?>
                    </h2>
                </div>

                <div class="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-user-check text-xl"></i>
                </div>

            </div>

        </div>


        <!-- ATTENDANCE RATE -->

        <div class="stat-card bg-white rounded-[26px] border border-slate-200 shadow-sm p-6">

            <div class="flex justify-between items-start gap-3">

                <div class="min-w-0">
                    <p class="text-slate-500 text-sm">
                        <?= t('attendance_rate'); ?>
                    </p>
                    <h2 class="text-4xl font-bold mt-2 text-rmc-800">
                        <?= $attendance_rate; ?>%
                    </h2>
                </div>

                <div class="w-14 h-14 rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-chart-pie text-xl"></i>
                </div>

            </div>

        </div>


        <!-- FEEDBACK RATING -->

        <div class="stat-card bg-white rounded-[26px] border border-slate-200 shadow-sm p-6">

            <div class="flex justify-between items-start gap-3">

                <div class="min-w-0">
                    <p class="text-slate-500 text-sm">
                        <?= t('feedback_satisfaction'); ?>
                    </p>
                    <h2 class="text-4xl font-bold mt-2 text-rmc-800">
                        <?= $avg_rating; ?>
                        <span class="text-lg text-slate-400 font-normal">
                            / 5
                        </span>
                    </h2>
                </div>

                <div class="w-14 h-14 rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-star text-xl"></i>
                </div>

            </div>

        </div>

    </div>


    <!-- =========================================================
         PARTICIPANTS TABLE
         ========================================================= -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 mb-8 animate-up delay-2">

        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">

            <div>

                <h2 class="text-2xl font-bold text-slate-900 flex items-center gap-3">

                    <span class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center">
                        <i class="fa-solid fa-users"></i>
                    </span>

                    <?= t('students'); ?>

                </h2>

                <p class="text-slate-500 mt-1">
                    <?= t('registrations'); ?> &mdash;
                    <?= $total_registrations; ?>
                </p>

            </div>

        </div>


        <?php if (count($participants) > 0): ?>

            <div class="overflow-x-auto">

                <table class="w-full text-left border-collapse min-w-[760px]">

                    <thead>

                        <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">

                            <th class="px-4 py-3 font-semibold">#</th>

                            <th class="px-4 py-3 font-semibold">
                                <?= t('full_name'); ?>
                            </th>

                            <th class="px-4 py-3 font-semibold">
                                <?= t('student_id'); ?>
                            </th>

                            <th class="px-4 py-3 font-semibold">
                                <?= t('department_label'); ?>
                            </th>

                            <th class="px-4 py-3 font-semibold">
                                <?= t('event_date'); ?>
                            </th>

                            <th class="px-4 py-3 font-semibold">
                                <?= t('attendance_status_verified'); ?>
                            </th>

                            <th class="px-4 py-3 font-semibold">
                                <?= t('check_in'); ?>
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($participants as $idx => $p): ?>

                            <tr class="border-b border-slate-100 hover:bg-slate-50 transition">

                                <td class="px-4 py-4 text-slate-500 text-sm">
                                    <?= $idx + 1; ?>
                                </td>

                                <td class="px-4 py-4">

                                    <span class="font-semibold text-slate-800">
                                        <?= htmlspecialchars($p['student_name']); ?>
                                    </span>

                                </td>

                                <td class="px-4 py-4 text-slate-600 whitespace-nowrap">
                                    <?= htmlspecialchars($p['student_id'] ?? '-'); ?>
                                </td>

                                <td class="px-4 py-4 text-slate-600 max-w-[160px] truncate">
                                    <?= htmlspecialchars($p['department'] ?? '-'); ?>
                                </td>

                                <td class="px-4 py-4 text-slate-600 whitespace-nowrap">

                                    <?php

                                    $reg_date = $p['registered_at']
                                        ? date(
                                            'Y-m-d',
                                            strtotime($p['registered_at'])
                                        )
                                        : '-';

                                    echo htmlspecialchars($reg_date);

                                    ?>

                                </td>

                                <td class="px-4 py-4">

                                    <?php if (!empty($p['attendance_id'])): ?>

                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            <i class="fa-solid fa-circle-check mr-1.5 text-[10px]"></i>
                                            <?= t('present'); ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                            <i class="fa-regular fa-clock mr-1.5 text-[10px]"></i>
                                            <?= t('attendance_status_pending'); ?>
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td class="px-4 py-4 text-slate-600 whitespace-nowrap">

                                    <?php if (!empty($p['checked_in_at'])): ?>

                                        <?php

                                        $checkin_time = date(
                                            'g:i A',
                                            strtotime($p['checked_in_at'])
                                        );

                                        echo htmlspecialchars($checkin_time);

                                        ?>

                                    <?php else: ?>

                                        -

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="text-center py-12">

                <div class="w-16 h-16 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-users-slash text-2xl"></i>
                </div>

                <h3 class="text-lg font-semibold text-slate-700 mb-1">
                    <?= t('no_activities_title'); ?>
                </h3>

                <p class="text-slate-500 text-sm max-w-md mx-auto">
                    <?= t('no_activities_desc'); ?>
                </p>

            </div>

        <?php endif; ?>

    </div>


    <!-- =========================================================
         FEEDBACK SECTION
         ========================================================= -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 mb-8 animate-up delay-3">

        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">

            <div>

                <h2 class="text-2xl font-bold text-slate-900 flex items-center gap-3">

                    <span class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center">
                        <i class="fa-solid fa-comments"></i>
                    </span>

                    <?= t('feedback_satisfaction'); ?>

                </h2>

                <p class="text-slate-500 mt-1">

                    <?php if (count($feedback_items) > 0): ?>

                        <?= count($feedback_items); ?>
                        <?= count($feedback_items) === 1 ? 'review' : 'reviews'; ?>
                        &mdash;
                        <?= t('feedback_satisfaction'); ?>

                    <?php else: ?>

                        <?= t('feedback_analytics_desc'); ?>

                    <?php endif; ?>

                </p>

            </div>

            <?php if (count($feedback_items) > 0): ?>

                <div class="bg-rmc-50 text-rmc-800 border border-rmc-200 px-5 py-3 rounded-xl self-start md:self-auto">

                    <div class="flex items-center gap-3">

                        <span class="text-3xl font-bold">
                            <?= $avg_rating; ?>
                        </span>

                        <div>

                            <div class="flex items-center gap-0.5">

                                <?php for ($s = 1; $s <= 5; $s++): ?>

                                    <?php if ($s <= floor($avg_rating)): ?>

                                        <i class="fa-solid fa-star text-amber-400 text-sm"></i>

                                    <?php elseif ($s - $avg_rating < 1 && $s - $avg_rating > 0): ?>

                                        <i class="fa-solid fa-star-half-stroke text-amber-400 text-sm"></i>

                                    <?php else: ?>

                                        <i class="fa-regular fa-star text-amber-400 text-sm"></i>

                                    <?php endif; ?>

                                <?php endfor; ?>

                            </div>

                            <p class="text-xs text-slate-500 mt-0.5">
                                <?= count($feedback_items); ?>
                                <?= count($feedback_items) === 1 ? 'review' : 'reviews'; ?>
                            </p>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

        </div>


        <?php if (count($feedback_items) > 0): ?>

            <div class="space-y-4">

                <?php foreach ($feedback_items as $fb): ?>

                    <div class="border border-slate-100 rounded-2xl p-5 hover:border-slate-200 transition">

                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-3">

                            <div class="flex items-center gap-3">

                                <div class="w-10 h-10 rounded-full bg-rmc-100 text-rmc-700 flex items-center justify-center font-bold text-sm shrink-0">

                                    <?php

                                    $display_name = ($fb['is_anonymous'] === 't' || $fb['is_anonymous'] === true)
                                        ? '?'
                                        : mb_substr($fb['full_name'], 0, 1);

                                    echo htmlspecialchars($display_name);

                                    ?>

                                </div>

                                <div>

                                    <p class="font-semibold text-slate-800 text-sm">

                                        <?php if ($fb['is_anonymous'] === 't' || $fb['is_anonymous'] === true): ?>

                                            <?= t('anonymous'); ?>

                                        <?php else: ?>

                                            <?= htmlspecialchars($fb['full_name']); ?>

                                        <?php endif; ?>

                                    </p>

                                    <div class="flex items-center gap-0.5 mt-0.5">

                                        <?php for ($s = 1; $s <= 5; $s++): ?>

                                            <?php if ($s <= (int) $fb['rating']): ?>

                                                <i class="fa-solid fa-star text-amber-400 text-xs"></i>

                                            <?php else: ?>

                                                <i class="fa-regular fa-star text-amber-400 text-xs"></i>

                                            <?php endif; ?>

                                        <?php endfor; ?>

                                        <span class="ml-1 text-xs text-slate-500">
                                            (<?= (int) $fb['rating']; ?>/5)
                                        </span>

                                    </div>

                                </div>

                            </div>

                            <span class="text-xs text-slate-400 whitespace-nowrap">

                                <?php

                                $fb_date = $fb['created_at']
                                    ? date(
                                        'M j, Y \a\t g:i A',
                                        strtotime($fb['created_at'])
                                    )
                                    : '-';

                                echo htmlspecialchars($fb_date);

                                ?>

                            </span>

                        </div>

                        <?php if (!empty(trim($fb['comment']))): ?>

                            <p class="text-slate-600 text-sm leading-relaxed pl-[52px]">
                                <?= htmlspecialchars($fb['comment']); ?>
                            </p>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="text-center py-12">

                <div class="w-16 h-16 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-4">
                    <i class="fa-regular fa-comment-dots text-2xl"></i>
                </div>

                <h3 class="text-lg font-semibold text-slate-700 mb-1">
                    <?= t('no_feedback_yet'); ?>
                </h3>

                <p class="text-slate-500 text-sm max-w-md mx-auto">
                    <?= t('no_feedback_event'); ?>
                </p>

            </div>

        <?php endif; ?>

    </div>

</div>


<?php include 'partials/footer.php'; ?>
