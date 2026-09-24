<?php


include 'db_connect.php';
require 'lang.php';

if (
    !isset($_SESSION['user_id']) ||
    (
        $_SESSION['role'] !== 'admin' &&
        $_SESSION['role'] !== 'organizer'
    )
) {
    http_response_code(403);
    die("Access denied. Admins and Organizers only.");
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? '';
$first_name = explode(' ', trim($full_name))[0];


/* =========================================================
   ANALYTICS FILTERS
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
   GET AVAILABLE DEPARTMENTS
   ========================================================= */

$departments = [];

$department_result = $pdo->query("
    SELECT DISTINCT
        TRIM(department) AS department
    FROM users
    WHERE role = 'student'
      AND department IS NOT NULL
      AND TRIM(department) <> ''
    ORDER BY department ASC
    ");

if ($department_result) {
    while ($row = $department_result->fetch(PDO::FETCH_ASSOC)) {
        $departments[] = $row['department'];
    }
}


/* =========================================================
   GET AVAILABLE CATEGORIES
   ========================================================= */

$categories = [];

$category_result = $pdo->query("
    SELECT DISTINCT
        category
    FROM events
    WHERE category IS NOT NULL
      AND TRIM(category) <> ''
      AND status != 'deleted'
    ORDER BY category ASC
    ");

if ($category_result) {
    while ($row = $category_result->fetch(PDO::FETCH_ASSOC)) {
        $categories[] = $row['category'];
    }
}


/* =========================================================
   GET AVAILABLE YEARS
   ========================================================= */

$years = [];

$year_result = $pdo->query("
    SELECT DISTINCT
        EXTRACT(YEAR FROM event_date) AS event_year
    FROM events
    WHERE event_date IS NOT NULL
      AND status != 'deleted'
    ORDER BY event_year DESC
    ");

if ($year_result) {
    while ($row = $year_result->fetch(PDO::FETCH_ASSOC)) {
        $years[] = (int) $row['event_year'];
    }
}


/* =========================================================
   EVENT PERFORMANCE
   REGISTRATIONS VS ATTENDANCE
   ========================================================= */

$performance_conditions = [];
$performance_conditions[] = "e.status != 'deleted'";
$performance_params = [];

if ($role === 'organizer') {
    $performance_conditions[] = "e.organizer_id = ?";
    $performance_params[] = $user_id;
}

if ($category_filter !== '') {
    $performance_conditions[] = "e.category = ?";
    $performance_params[] = $category_filter;
}

if ($year_filter !== '') {
    $performance_conditions[] = "EXTRACT(YEAR FROM e.event_date) = ?";
    $performance_params[] = (int) $year_filter;
}

if ($department_filter !== '') {
    $performance_conditions[] = "
        EXISTS (
            SELECT 1
            FROM users du
            WHERE du.user_id = r.user_id
              AND TRIM(du.department) = ?
        )
    ";
    $performance_params[] = $department_filter;
}

$performance_where = ' WHERE ' . implode(' AND ', $performance_conditions);

/*
 * Attendance is counted from the real attendance table and linked to each
 * registration. Only verified attendance is counted, once per registration.
 */
$performance_sql = "
    SELECT
        e.event_id,
        e.title,
        e.event_date,
        e.venue,
        e.status,

        COUNT(DISTINCT CASE WHEN r.status = 'registered' THEN r.registration_id END)
            AS total_registrations,

        COUNT(DISTINCT CASE WHEN r.status = 'registered' AND a.verified = 1 THEN r.registration_id END)
            AS total_attendance

    FROM events e

    LEFT JOIN registrations r
        ON e.event_id = r.event_id
    LEFT JOIN attendance a
        ON r.registration_id = a.registration_id

    $performance_where

    GROUP BY
        e.event_id,
        e.title,
        e.event_date,
        e.venue,
        e.status

    ORDER BY
        total_registrations DESC,
        e.title ASC
";

$performance_result = $pdo->prepare($performance_sql);
$performance_result->execute($performance_params);


$labels = [];
$regCounts = [];
$attCounts = [];
$attendanceRates = [];
$event_rows = [];


if ($performance_result) {

    while ($row = $performance_result->fetch(PDO::FETCH_ASSOC)) {

        $labels[] = $row['title'];

        $registrations = (int) $row['total_registrations'];
        $attendance = (int) $row['total_attendance'];

        $regCounts[] = $registrations;
        $attCounts[] = $attendance;

        $rate = $registrations > 0
            ? round(($attendance / $registrations) * 100, 1)
            : 0;

        $attendanceRates[] = $rate;

        $event_rows[] = array(
            'event_id'    => (int) $row['event_id'],
            'title'       => $row['title'],
            'event_date'  => $row['event_date'],
            'venue'       => $row['venue'],
            'status'      => $row['status'],
            'registered'  => $registrations,
            'attended'    => $attendance,
            'rate'        => $rate
        );

    }

}

/* Top 5 events by registrations, for the At-a-Glance leaderboard
   (event_rows is already ordered by total_registrations DESC above) */
$top5_events = array_slice($event_rows, 0, 5);


/* =========================================================
   REGISTRATION TREND
   ========================================================= */

$trend_conditions = [];
$trend_conditions[] = "e.status != 'deleted'";
$trend_conditions[] = "r.status = 'registered'";
$trend_params = [];

if ($role === 'organizer') {
    $trend_conditions[] = "e.organizer_id = ?";
    $trend_params[] = $user_id;
}

if ($category_filter !== '') {
    $trend_conditions[] = "e.category = ?";
    $trend_params[] = $category_filter;
}

if ($year_filter !== '') {
    $trend_conditions[] = "EXTRACT(YEAR FROM e.event_date) = ?";
    $trend_params[] = (int) $year_filter;
}

if ($department_filter !== '') {
    $trend_conditions[] = "TRIM(u.department) = ?";
    $trend_params[] = $department_filter;
}

$trend_where = ' WHERE ' . implode(' AND ', $trend_conditions);

$trend_sql = "
    SELECT DATE(r.registered_at) AS reg_date,
           COUNT(*) AS total
    FROM registrations r
    JOIN events e ON r.event_id = e.event_id
    JOIN users u ON r.user_id = u.user_id
    $trend_where
    GROUP BY DATE(r.registered_at)
    ORDER BY DATE(r.registered_at) ASC
";

$trend_result = $pdo->prepare($trend_sql);
$trend_result->execute($trend_params);

$trendLabels = [];
$trendCounts = [];

if ($trend_result) {
    while ($row = $trend_result->fetch(PDO::FETCH_ASSOC)) {
        $trendLabels[] = $row['reg_date'];
        $trendCounts[] = (int) $row['total'];
    }
}


/* =========================================================
   REGISTRATIONS BY DEPARTMENT
   ========================================================= */

$dept_conditions = [];
$dept_conditions[] = "e.status != 'deleted'";
$dept_conditions[] = "r.status = 'registered'";
$dept_params = [];

if ($role === 'organizer') {
    $dept_conditions[] = "e.organizer_id = ?";
    $dept_params[] = $user_id;
}

if ($category_filter !== '') {
    $dept_conditions[] = "e.category = ?";
    $dept_params[] = $category_filter;
}

if ($year_filter !== '') {
    $dept_conditions[] = "EXTRACT(YEAR FROM e.event_date) = ?";
    $dept_params[] = (int) $year_filter;
}

$dept_where = ' WHERE ' . implode(' AND ', $dept_conditions);

$deptLabels = [];
$deptCounts = [];

$dept_sql = "
    SELECT COALESCE(NULLIF(TRIM(u.department), ''), 'Unspecified') AS department,
           COUNT(*) AS total
    FROM registrations r
    JOIN users u ON r.user_id = u.user_id
    JOIN events e ON r.event_id = e.event_id
    $dept_where
    GROUP BY COALESCE(NULLIF(TRIM(u.department), ''), 'Unspecified')
    ORDER BY total DESC
";

$dept_result = $pdo->prepare($dept_sql);
$dept_result->execute($dept_params);

if ($dept_result) {
    while ($row = $dept_result->fetch(PDO::FETCH_ASSOC)) {
        $deptLabels[] = $row['department'];
        $deptCounts[] = (int) $row['total'];
    }
}


/* =========================================================
   CURRENT EVENT STATUS COUNTS (real-time summary)
   ========================================================= */

$current_status_counts = [];
$current_status_result = $pdo->query("SELECT status, COUNT(*) AS total
     FROM events
     WHERE status != 'deleted'
     GROUP BY status
     ORDER BY total DESC");
if ($current_status_result) {
    while ($row = $current_status_result->fetch(PDO::FETCH_ASSOC)) {
        $current_status_counts[$row['status']] = (int) $row['total'];
    }
}


/* =========================================================
   EVENT + COURSE/PROGRAM BREAKDOWN
   ========================================================= */

$ep_conditions = [];
$ep_conditions[] = "e.status != 'deleted'";
$ep_conditions[] = "r.status = 'registered'";
$ep_params = [];

if ($role === 'organizer') {
    $ep_conditions[] = "e.organizer_id = ?";
    $ep_params[] = $user_id;
}
if ($category_filter !== '') {
    $ep_conditions[] = "e.category = ?";
    $ep_params[] = $category_filter;
}
if ($year_filter !== '') {
    $ep_conditions[] = "EXTRACT(YEAR FROM e.event_date) = ?";
    $ep_params[] = (int) $year_filter;
}
if ($department_filter !== '') {
    $ep_conditions[] = "TRIM(u.department) = ?";
    $ep_params[] = $department_filter;
}

$ep_where = ' WHERE ' . implode(' AND ', $ep_conditions);

$ep_sql = "
    SELECT e.title AS event_title,
           COALESCE(NULLIF(TRIM(u.department), ''), 'Unspecified') AS dept,
           COUNT(*) AS total
    FROM registrations r
    JOIN events e ON r.event_id = e.event_id
    JOIN users u ON r.user_id = u.user_id
    $ep_where
    GROUP BY e.event_id, e.title,
             COALESCE(NULLIF(TRIM(u.department), ''), 'Unspecified')
    ORDER BY total DESC
";

$ep_result = $pdo->prepare($ep_sql);
$ep_result->execute($ep_params);

$event_dept = [];
if ($ep_result) {
    while ($row = $ep_result->fetch(PDO::FETCH_ASSOC)) {
        $event_dept[$row['event_title']][] = [
            'dept'  => $row['dept'],
            'total' => (int) $row['total'],
        ];
    }
}


/* =========================================================
   EVENT STATUS BREAKDOWN
   ========================================================= */

$status_conditions = [];
$status_conditions[] = "e.status != 'deleted'";
$status_params = [];

if ($role === 'organizer') {
    $status_conditions[] = "e.organizer_id = ?";
    $status_params[] = $user_id;
}

if ($category_filter !== '') {
    $status_conditions[] = "e.category = ?";
    $status_params[] = $category_filter;
}

if ($year_filter !== '') {
    $status_conditions[] = "EXTRACT(YEAR FROM e.event_date) = ?";
    $status_params[] = (int) $year_filter;
}

if ($department_filter !== '') {
    $status_conditions[] = "
        EXISTS (
            SELECT 1
            FROM registrations sr
            JOIN users su ON sr.user_id = su.user_id
            WHERE sr.event_id = e.event_id
              AND TRIM(su.department) = ?
        )
    ";
    $status_params[] = $department_filter;
}

$status_where = ' WHERE ' . implode(' AND ', $status_conditions);

$status_sql = "
    SELECT e.status,
           COUNT(*) AS total
    FROM events e
    $status_where
    GROUP BY e.status
    ORDER BY total DESC
";

$status_result = $pdo->prepare($status_sql);
$status_result->execute($status_params);


$statusLabels = [];
$statusCounts = [];


if ($status_result) {
    while ($row = $status_result->fetch(PDO::FETCH_ASSOC)) {
        $statusLabels[] = ucfirst($row['status']);
        $statusCounts[] = (int) $row['total'];
    }
}


/* =========================================================
   FILTERED STATISTICS
   ========================================================= */

/*
 * Events
 */

$stats_event_conditions = [];
$stats_event_conditions[] = "e.status != 'deleted'";
$stats_event_params = [];

if ($role === 'organizer') {
    $stats_event_conditions[] = "e.organizer_id = ?";
    $stats_event_params[] = $user_id;
}

if ($category_filter !== '') {
    $stats_event_conditions[] = "e.category = ?";
    $stats_event_params[] = $category_filter;
}

if ($year_filter !== '') {
    $stats_event_conditions[] = "EXTRACT(YEAR FROM e.event_date) = ?";
    $stats_event_params[] = (int) $year_filter;
}

if ($department_filter !== '') {
    $stats_event_conditions[] = "
        EXISTS (
            SELECT 1
            FROM registrations sr
            JOIN users su ON sr.user_id = su.user_id
            WHERE sr.event_id = e.event_id
              AND TRIM(su.department) = ?
        )
    ";
    $stats_event_params[] = $department_filter;
}

$stats_event_where = ' WHERE ' . implode(' AND ', $stats_event_conditions);


/*
 * Total events
 */

$total_events_result = $pdo->prepare("
    SELECT COUNT(*)
    FROM events e
    $stats_event_where
    ");
$total_events_result->execute($stats_event_params);

$total_events = $total_events_result
    ? (int)(($total_events_result->fetch(PDO::FETCH_NUM) ?: [0])[0])
    : 0;


/*
 * Approved events
 */

$approved_conditions = $stats_event_conditions;
$approved_params = $stats_event_params;

$approved_conditions[] = "e.status = ?";
$approved_params[] = 'approved';

$approved_where = ' WHERE ' . implode(' AND ', $approved_conditions);

$approved_result = $pdo->prepare("
    SELECT COUNT(*)
    FROM events e
    $approved_where
    ");
$approved_result->execute($approved_params);


$approved_events = $approved_result
    ? (int)(($approved_result->fetch(PDO::FETCH_NUM) ?: [0])[0])
    : 0;


/*
 * Student count
 */

$student_conditions = ["u.role = 'student'"];
$student_params = [];

if ($department_filter !== '') {
    $student_conditions[] = "TRIM(u.department) = ?";
    $student_params[] = $department_filter;
}

$student_where = ' WHERE ' . implode(' AND ', $student_conditions);

$student_result = $pdo->prepare("
    SELECT COUNT(*)
    FROM users u
    $student_where
    ");
$student_result->execute($student_params);


$total_students = $student_result
    ? (int)(($student_result->fetch(PDO::FETCH_NUM) ?: [0])[0])
    : 0;


/*
 * Registrations
 */

$registration_conditions = [];
$registration_conditions[] = "e.status != 'deleted'";
$registration_conditions[] = "r.status = 'registered'";
$registration_params = [];

if ($role === 'organizer') {
    $registration_conditions[] = "e.organizer_id = ?";
    $registration_params[] = $user_id;
}

if ($category_filter !== '') {
    $registration_conditions[] = "e.category = ?";
    $registration_params[] = $category_filter;
}

if ($year_filter !== '') {
    $registration_conditions[] = "EXTRACT(YEAR FROM e.event_date) = ?";
    $registration_params[] = (int) $year_filter;
}

if ($department_filter !== '') {
    $registration_conditions[] = "TRIM(u.department) = ?";
    $registration_params[] = $department_filter;
}

$registration_where = ' WHERE ' . implode(' AND ', $registration_conditions);

$total_registrations_result = $pdo->prepare("
        SELECT COUNT(*)
        FROM registrations r
        JOIN events e ON r.event_id = e.event_id
        JOIN users u ON r.user_id = u.user_id
        $registration_where
        ");
$total_registrations_result->execute($registration_params);


$total_registrations = $total_registrations_result
    ? (int)(($total_registrations_result->fetch(PDO::FETCH_NUM) ?: [0])[0])
    : 0;


/* Attendance */

$overall_attendance_conditions = ["a.verified = 1", "r.status = 'registered'", "e.status != 'deleted'"];
$overall_attendance_params = [];
if ($role === 'organizer') {
    $overall_attendance_conditions[] = 'e.organizer_id = ?';
    $overall_attendance_params[] = $user_id;
}
if ($category_filter !== '') {
    $overall_attendance_conditions[] = 'e.category = ?';
    $overall_attendance_params[] = $category_filter;
}
if ($year_filter !== '') {
    $overall_attendance_conditions[] = 'EXTRACT(YEAR FROM e.event_date) = ?';
    $overall_attendance_params[] = (int) $year_filter;
}
if ($department_filter !== '') {
    $overall_attendance_conditions[] = 'TRIM(u.department) = ?';
    $overall_attendance_params[] = $department_filter;
}

$total_attendance_result = $pdo->prepare("
    SELECT COUNT(DISTINCT a.registration_id)
    FROM attendance a
    JOIN registrations r ON a.registration_id = r.registration_id
    JOIN events e ON r.event_id = e.event_id
    JOIN users u ON r.user_id = u.user_id
    WHERE " . implode(' AND ', $overall_attendance_conditions));
$total_attendance_result->execute($overall_attendance_params);

$total_attendance = $total_attendance_result
    ? (int)(($total_attendance_result->fetch(PDO::FETCH_NUM) ?: [0])[0])
    : 0;


/* Attendance rate */

$overallRate = $total_registrations > 0
    ? round(($total_attendance / $total_registrations) * 100, 1)
    : 0;


/* =========================================================
   FEEDBACK & SATISFACTION ANALYTICS
   ========================================================= */

$feedback_conditions = [];
$feedback_conditions[] = "e.status != 'deleted'";
$feedback_params = [];

if ($role === 'organizer') {
    $feedback_conditions[] = "e.organizer_id = ?";
    $feedback_params[] = $user_id;
}

if ($category_filter !== '') {
    $feedback_conditions[] = "e.category = ?";
    $feedback_params[] = $category_filter;
}

if ($year_filter !== '') {
    $feedback_conditions[] = "EXTRACT(YEAR FROM e.event_date) = ?";
    $feedback_params[] = (int) $year_filter;
}

if ($department_filter !== '') {
    $feedback_conditions[] = "TRIM(u.department) = ?";
    $feedback_params[] = $department_filter;
}

$feedback_where = ' WHERE ' . implode(' AND ', $feedback_conditions);


/* =========================================================
   OVERALL FEEDBACK RATING
   ========================================================= */

$feedback_summary_result = $pdo->prepare("
    SELECT
        ROUND(AVG(f.rating)) AS average_rating,
        COUNT(f.feedback_id) AS total_feedback
    FROM feedback f
    JOIN events e ON f.event_id = e.event_id
    JOIN users u ON f.user_id = u.user_id
    $feedback_where
    ");
$feedback_summary_result->execute($feedback_params);


$feedback_summary = $feedback_summary_result
    ? $feedback_summary_result->fetch(PDO::FETCH_ASSOC)
    : [];


$overall_feedback_rating =
    isset($feedback_summary['average_rating']) &&
    $feedback_summary['average_rating'] !== null
    ? (float) $feedback_summary['average_rating']
    : 0;


$total_feedback =
    isset($feedback_summary['total_feedback'])
    ? (int) $feedback_summary['total_feedback']
    : 0;


/* =========================================================
   FEEDBACK RATING DISTRIBUTION
   ========================================================= */

$rating_distribution = [
    5 => 0,
    4 => 0,
    3 => 0,
    2 => 0,
    1 => 0
];


$rating_result = $pdo->prepare("
    SELECT
        f.rating,
        COUNT(*) AS total
    FROM feedback f
    JOIN events e ON f.event_id = e.event_id
    JOIN users u ON f.user_id = u.user_id
    $feedback_where
    GROUP BY f.rating
    ORDER BY f.rating DESC
    ");
$rating_result->execute($feedback_params);


if ($rating_result) {
    while ($row = $rating_result->fetch(PDO::FETCH_ASSOC)) {
        $rating = (int) $row['rating'];
        if ($rating >= 1 && $rating <= 5) {
            $rating_distribution[$rating] = (int) $row['total'];
        }
    }
}


/* =========================================================
   RATING PERCENTAGES
   ========================================================= */

$rating_percentages = [];

for ($rating = 5; $rating >= 1; $rating--) {
    $rating_percentages[$rating] = $total_feedback > 0
        ? round(($rating_distribution[$rating] / $total_feedback) * 100, 1)
        : 0;
}


/* =========================================================
   MOST COMMON FEEDBACK
   ========================================================= */

$common_feedback = [];

$common_feedback_result = $pdo->prepare("
    SELECT
        TRIM(f.comment) AS comment,
        COUNT(*) AS feedback_count
    FROM feedback f
    JOIN events e ON f.event_id = e.event_id
    JOIN users u ON f.user_id = u.user_id
    $feedback_where
    AND f.comment IS NOT NULL
    AND TRIM(f.comment) <> ''
    GROUP BY TRIM(f.comment)
    ORDER BY
        feedback_count DESC,
        MIN(f.created_at) DESC
    LIMIT 5
    ");
$common_feedback_result->execute($feedback_params);


if ($common_feedback_result) {
    while ($row = $common_feedback_result->fetch(PDO::FETCH_ASSOC)) {
        $common_feedback[] = [
            'comment' => $row['comment'],
            'count'   => (int) $row['feedback_count']
        ];
    }
}


/* =========================================================
   RECENT FEEDBACK FALLBACK
   ========================================================= */

if (count($common_feedback) === 0) {

    $recent_feedback_result = $pdo->prepare("
        SELECT
            TRIM(f.comment) AS comment,
            MIN(f.created_at) AS created_at
        FROM feedback f
        JOIN events e ON f.event_id = e.event_id
        JOIN users u ON f.user_id = u.user_id
        $feedback_where
        AND f.comment IS NOT NULL
        AND TRIM(f.comment) <> ''
        ORDER BY f.created_at DESC
        LIMIT 5
        ");
    $recent_feedback_result->execute($feedback_params);


    if ($recent_feedback_result) {
        while ($row = $recent_feedback_result->fetch(PDO::FETCH_ASSOC)) {
            $common_feedback[] = [
                'comment' => $row['comment'],
                'count'   => 1
            ];
        }
    }
}


/* =========================================================
   UNREAD COUNT + RECENT NOTIFICATIONS (shared header)
   ========================================================= */

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = false");
$stmt->execute([$user_id]);
$unread_count = (int)$stmt->fetchColumn();

$recent_notifications = $pdo->prepare("SELECT notification_id, type, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$recent_notifications->execute([$user_id]);


/* =========================================================
   PAGE VARIABLES (shared partials)
   ========================================================= */

$role_label = ucfirst($role);

if ($role === 'admin') {
    $role_label = 'Administrator';
} elseif ($role === 'organizer') {
    $role_label = 'Event Organizer';
}

$page_title  = t('title_reports');
$active_page = 'reports';

?>

<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     REPORTS HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up"
>

    <div class="flex items-center gap-4 sm:gap-5">

        <div
            class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
        >

            <i class="fa-solid fa-chart-column text-2xl"></i>

        </div>

        <div>

            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                <?= t('analytics_dashboard'); ?>
            </h2>

            <p class="text-slate-600 mt-1 text-sm sm:text-base">
                <?= t('analytics_hero_desc'); ?>
            </p>

        </div>

    </div>

</div>


<!-- =========================================================
     ANALYTICS FILTERS
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 mb-8 animate-up delay-1">

    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">

        <div>

            <h2 class="text-2xl font-bold text-slate-900 flex items-center gap-3">

                <span class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center">

                    <i class="fa-solid fa-filter"></i>

                </span>

                <?= t('analytics_filters'); ?>

            </h2>

            <p class="text-slate-500 mt-1">
                <?= t('filter_desc'); ?>
            </p>

        </div>

        <?php if (
            $department_filter !== '' ||
            $category_filter !== '' ||
            $year_filter !== ''
        ): ?>

            <span class="inline-flex items-center bg-emerald-50 text-emerald-700 border border-emerald-200 px-4 py-2 rounded-xl text-sm font-semibold self-start md:self-auto">
                <?= t('filters_applied'); ?>
            </span>

        <?php endif; ?>

    </div>


    <form method="GET" action="reports.php" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">

        <!-- DEPARTMENT -->

        <div>

            <label for="department" class="block text-sm font-semibold text-slate-700 mb-2">
                <?= t('department_label'); ?>
            </label>

            <select
                name="department"
                id="department"
                class="w-full border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

                <option value="">
                    <?= t('all_departments'); ?>
                </option>

                <?php foreach ($departments as $department): ?>

                    <option
                        value="<?= htmlspecialchars($department); ?>"
                        <?= $department_filter === $department ? 'selected' : ''; ?>
                    >

                        <?= htmlspecialchars($department); ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <!-- CATEGORY -->

        <div>

            <label for="category" class="block text-sm font-semibold text-slate-700 mb-2">
                <?= t('category_label'); ?>
            </label>

            <select
                name="category"
                id="category"
                class="w-full border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

                <option value="">
                    <?= t('all_categories'); ?>
                </option>

                <?php foreach ($categories as $category): ?>

                    <option
                        value="<?= htmlspecialchars($category); ?>"
                        <?= $category_filter === $category ? 'selected' : ''; ?>
                    >

                        <?= htmlspecialchars($category); ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <!-- YEAR -->

        <div>

            <label for="year" class="block text-sm font-semibold text-slate-700 mb-2">
                <?= t('year'); ?>
            </label>

            <select
                name="year"
                id="year"
                class="w-full border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

                <option value="">
                    <?= t('all_years'); ?>
                </option>

                <?php foreach ($years as $year): ?>

                    <option
                        value="<?= $year; ?>"
                        <?= $year_filter === (string) $year ? 'selected' : ''; ?>
                    >

                        <?= $year; ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <!-- BUTTONS -->

        <div class="flex items-end gap-2">

            <button
                type="submit"
                class="flex-1 bg-rmc-800 hover:bg-rmc-900 text-white px-5 py-3 rounded-xl font-semibold transition inline-flex items-center justify-center gap-2"
            >

                <i class="fa-solid fa-filter"></i>

                <?= t('apply'); ?>

            </button>

            <a
                    href="reports.php"
                class="bg-white hover:bg-rmc-50 border border-rmc-200 text-rmc-800 px-5 py-3 rounded-xl font-semibold transition"
            >

                <?= t('reset'); ?>

            </a>

        </div>

    </form>

</div>


<!-- =========================================================
     FILTER SUMMARY
     ========================================================= -->

<div class="bg-rmc-950 text-white rounded-2xl p-5 mb-8 animate-up delay-2">

    <div class="flex flex-wrap items-center gap-3">

        <span class="font-semibold">
            <?= t('currently_viewing'); ?>
        </span>

        <span class="bg-white/10 px-3 py-1 rounded-full text-sm">

            <?php if ($department_filter !== ''): ?>

                <?= t('department_filtered'); ?>
                <?= htmlspecialchars($department_filter); ?>

            <?php else: ?>

                <?= t('all_departments'); ?>

            <?php endif; ?>

        </span>

        <span class="bg-white/10 px-3 py-1 rounded-full text-sm">

            <?php if ($category_filter !== ''): ?>

                <?= t('category_filtered'); ?>
                <?= htmlspecialchars($category_filter); ?>

            <?php else: ?>

                <?= t('all_categories'); ?>

            <?php endif; ?>

        </span>

        <span class="bg-white/10 px-3 py-1 rounded-full text-sm">

            <?php if ($year_filter !== ''): ?>

                <?= t('year_filtered'); ?>
                <?= htmlspecialchars($year_filter); ?>

            <?php else: ?>

                <?= t('all_years'); ?>

            <?php endif; ?>

        </span>

    </div>

</div>


<!-- =========================================================
     CSV EXPORT
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 mb-8 animate-up delay-2">

    <div class="flex items-center gap-3 mb-5">

        <span class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center">
            <i class="fa-solid fa-file-csv"></i>
        </span>

        <h2 class="text-xl font-bold text-slate-900">
            <?= t('csv_export') ?: 'CSV Export'; ?>
        </h2>

    </div>

    <div class="flex flex-wrap gap-3">

        <?php if ($role === 'admin'): ?>

            <a href="export.php?action=users"
               class="inline-flex items-center gap-2 bg-slate-100 hover:bg-emerald-50 hover:text-emerald-700 text-slate-700 border border-slate-200 hover:border-emerald-200 px-4 py-2.5 rounded-xl text-sm font-semibold transition">
                <i class="fa-solid fa-users"></i>
                <?= t('export_users') ?: 'Users'; ?>
            </a>

            <a href="export.php?action=events"
               class="inline-flex items-center gap-2 bg-slate-100 hover:bg-emerald-50 hover:text-emerald-700 text-slate-700 border border-slate-200 hover:border-emerald-200 px-4 py-2.5 rounded-xl text-sm font-semibold transition">
                <i class="fa-solid fa-calendar-days"></i>
                <?= t('export_events') ?: 'Events'; ?>
            </a>

            <a href="export.php?action=registrations"
               class="inline-flex items-center gap-2 bg-slate-100 hover:bg-emerald-50 hover:text-emerald-700 text-slate-700 border border-slate-200 hover:border-emerald-200 px-4 py-2.5 rounded-xl text-sm font-semibold transition">
                <i class="fa-solid fa-clipboard-list"></i>
                <?= t('export_registrations') ?: 'Registrations'; ?>
            </a>

            <a href="export.php?action=attendance"
               class="inline-flex items-center gap-2 bg-slate-100 hover:bg-emerald-50 hover:text-emerald-700 text-slate-700 border border-slate-200 hover:border-emerald-200 px-4 py-2.5 rounded-xl text-sm font-semibold transition">
                <i class="fa-solid fa-check-double"></i>
                <?= t('export_attendance') ?: 'Attendance'; ?>
            </a>

            <a href="export.php?action=feedback"
               class="inline-flex items-center gap-2 bg-slate-100 hover:bg-emerald-50 hover:text-emerald-700 text-slate-700 border border-slate-200 hover:border-emerald-200 px-4 py-2.5 rounded-xl text-sm font-semibold transition">
                <i class="fa-solid fa-star"></i>
                <?= t('export_feedback') ?: 'Feedback'; ?>
            </a>

            <a href="export.php?action=email_logs"
               class="inline-flex items-center gap-2 bg-slate-100 hover:bg-emerald-50 hover:text-emerald-700 text-slate-700 border border-slate-200 hover:border-emerald-200 px-4 py-2.5 rounded-xl text-sm font-semibold transition">
                <i class="fa-solid fa-envelope"></i>
                <?= t('export_email_logs') ?: 'Email Logs'; ?>
            </a>

            <a href="export.php?action=analytics_summary"
               class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2.5 rounded-xl text-sm font-semibold transition shadow-sm">
                <i class="fa-solid fa-chart-line"></i>
                <?= t('export_full_analytics') ?: 'Full Analytics'; ?>
            </a>

        <?php else: ?>

            <a href="export.php?action=my_events"
               class="inline-flex items-center gap-2 bg-slate-100 hover:bg-emerald-50 hover:text-emerald-700 text-slate-700 border border-slate-200 hover:border-emerald-200 px-4 py-2.5 rounded-xl text-sm font-semibold transition">
                <i class="fa-solid fa-calendar-days"></i>
                <?= t('export_my_events') ?: 'My Events'; ?>
            </a>

        <?php endif; ?>

    </div>

</div>


<!-- =========================================================
     AT A GLANCE
     Summary list + attendance gauge + top-5 leaderboard,
     grouped into one dashboard-style row.
     ========================================================= -->

<div class="grid lg:grid-cols-3 gap-6 sm:gap-8 mb-8">

    <!-- SUMMARY LIST -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-7 animate-up delay-2">

        <h2 class="text-lg font-bold text-slate-900 mb-5">Summary</h2>

        <div class="space-y-2.5">

            <a href="<?= $role === 'admin' ? 'admin_events.php' : '#event-analytics'; ?>" class="flex items-center justify-between rounded-2xl px-4 py-3 bg-rmc-50 hover:bg-rmc-100 transition group">
                <span class="text-sm font-semibold text-rmc-900">Total Events</span>
                <span class="text-sm font-bold text-rmc-900"><?= $total_events; ?></span>
            </a>

            <a href="<?= $role === 'admin' ? 'admin_events.php?filter=approved' : '#event-analytics'; ?>" class="flex items-center justify-between rounded-2xl px-4 py-3 bg-emerald-50 hover:bg-emerald-100 transition">
                <span class="text-sm font-semibold text-emerald-900">Approved</span>
                <span class="text-sm font-bold text-emerald-900"><?= $approved_events; ?></span>
            </a>

            <a href="<?= $role === 'admin' ? 'admin_users.php' : '#event-analytics'; ?>" class="flex items-center justify-between rounded-2xl px-4 py-3 bg-slate-100 hover:bg-slate-200 transition">
                <span class="text-sm font-semibold text-slate-700">Students</span>
                <span class="text-sm font-bold text-slate-700"><?= $total_students; ?></span>
            </a>

            <a href="<?= $role === 'admin' ? 'admin_events.php' : '#event-analytics'; ?>" class="flex items-center justify-between rounded-2xl px-4 py-3 bg-amber-50 hover:bg-amber-100 transition">
                <span class="text-sm font-semibold text-amber-800">Registrations</span>
                <span class="text-sm font-bold text-amber-800"><?= $total_registrations; ?></span>
            </a>

        </div>

    </div>


    <!-- ATTENDANCE GAUGE -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-7 animate-up delay-2 flex flex-col">

        <h2 class="text-lg font-bold text-slate-900 mb-1">Overall Attendance Rate</h2>
        <p class="text-slate-500 text-sm mb-2">Attended vs. total registrations</p>

        <div class="relative flex-1 flex items-center justify-center min-h-[200px]">
            <canvas id="gaugeChart" width="220" height="150"></canvas>
            <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none" style="top: 18px;">
                <span class="text-4xl font-bold text-slate-900"><?= $overallRate; ?>%</span>
                <span class="text-xs text-slate-500 mt-1"><?= $total_attendance; ?> / <?= $total_registrations; ?> attended</span>
            </div>
        </div>

    </div>


    <!-- TOP 5 EVENTS LEADERBOARD -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-7 animate-up delay-2">

        <h2 class="text-lg font-bold text-slate-900 mb-1">Top Events</h2>
        <p class="text-slate-500 text-sm mb-4">By registrations</p>

        <?php if (count($top5_events) > 0): ?>

            <div class="space-y-1">

                <div class="grid grid-cols-[1fr_auto_auto] gap-3 px-1 pb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    <span>Event</span>
                    <span class="text-right">Reg.</span>
                    <span class="text-right">Rate</span>
                </div>

                <?php foreach ($top5_events as $i => $te): ?>

                    <a
                            href="event_detail.php?event_id=<?= $te['event_id']; ?>"
                        class="grid grid-cols-[1fr_auto_auto] gap-3 items-center rounded-xl px-1.5 py-2 hover:bg-slate-50 transition"
                    >
                        <span class="flex items-center gap-2 min-w-0">
                            <span class="w-5 h-5 shrink-0 rounded-full bg-rmc-100 text-rmc-800 text-[10px] font-bold flex items-center justify-center"><?= $i + 1; ?></span>
                            <span class="text-sm font-semibold text-slate-700 truncate"><?= htmlspecialchars($te['title']); ?></span>
                        </span>
                        <span class="text-sm font-bold text-slate-700 text-right"><?= $te['registered']; ?></span>
                        <span class="text-sm font-bold text-emerald-600 text-right"><?= $te['rate']; ?>%</span>
                    </a>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="text-center py-10 text-slate-400 text-sm">
                No event data yet.
            </div>

        <?php endif; ?>

    </div>

</div>


<!-- =========================================================
     STATISTICS
     ========================================================= -->

<?php
/*
| Clickable stat cards. Each card navigates to the natural destination
| for the current role. Admin cards open the management pages; organizer
| cards stay within organizer-scoped analytics / check-in pages.
*/
$stat_links = array(
    'total_events'  => ($role === 'admin') ? 'admin_events.php'                   : '#event-analytics',
    'approved'      => ($role === 'admin') ? 'admin_events.php?filter=approved'   : '#event-analytics',
    'students'      => ($role === 'admin') ? 'admin_users.php'                    : '#event-analytics',
    'registrations' => ($role === 'admin') ? 'admin_events.php'                   : '#event-analytics',
    'attendance'    => ($role === 'admin') ? 'admin_events.php?filter=approved'   : 'scan_attendance.php',
    'rate'          => ($role === 'admin') ? 'admin_events.php?filter=approved'   : '#feedback-analytics',
);
?>

<div class="grid lg:grid-cols-6 md:grid-cols-2 sm:grid-cols-2 gap-5 sm:gap-6 mb-8">

    <!-- TOTAL EVENTS -->

    <a href="<?= htmlspecialchars($stat_links['total_events']); ?>" class="block group">

        <div class="stat-card bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 transition group-hover:shadow-md group-hover:border-rmc-200 group-hover:-translate-y-0.5">

            <div class="flex justify-between items-start gap-3">

                <div class="min-w-0">

                    <p class="text-slate-500 text-sm">
                        <?= t('total_events'); ?>
                    </p>

                    <h2 class="text-4xl font-bold mt-2 text-rmc-800">
                        <?= $total_events; ?>
                    </h2>

                </div>

                <div class="w-14 h-14 rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center shrink-0">

                    <i class="fa-solid fa-calendar-days text-xl"></i>

                </div>

            </div>

        </div>

    </a>


    <!-- APPROVED -->

    <a href="<?= htmlspecialchars($stat_links['approved']); ?>" class="block group">

        <div class="stat-card bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 transition group-hover:shadow-md group-hover:border-emerald-200 group-hover:-translate-y-0.5">

            <div class="flex justify-between items-start gap-3">

                <div class="min-w-0">

                    <p class="text-slate-500 text-sm">
                        <?= t('approved'); ?>
                    </p>

                    <h2 class="text-4xl font-bold mt-2 text-emerald-600">
                        <?= $approved_events; ?>
                    </h2>

                </div>

                <div class="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">

                    <i class="fa-solid fa-circle-check text-xl"></i>

                </div>

            </div>

        </div>

    </a>


    <!-- STUDENTS -->

    <a href="<?= htmlspecialchars($stat_links['students']); ?>" class="block group">

        <div class="stat-card bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 transition group-hover:shadow-md group-hover:border-slate-300 group-hover:-translate-y-0.5">

            <div class="flex justify-between items-start gap-3">

                <div class="min-w-0">

                    <p class="text-slate-500 text-sm">
                        <?= t('students'); ?>
                    </p>

                    <h2 class="text-4xl font-bold mt-2 text-slate-700">
                        <?= $total_students; ?>
                    </h2>

                </div>

                <div class="w-14 h-14 rounded-2xl bg-slate-100 text-slate-600 flex items-center justify-center shrink-0">

                    <i class="fa-solid fa-users text-xl"></i>

                </div>

            </div>

        </div>

    </a>


    <!-- REGISTRATIONS -->

    <a href="<?= htmlspecialchars($stat_links['registrations']); ?>" class="block group">

        <div class="stat-card bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 transition group-hover:shadow-md group-hover:border-amber-200 group-hover:-translate-y-0.5">

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

    </a>


    <!-- ATTENDANCE -->

    <a href="<?= htmlspecialchars($stat_links['attendance']); ?>" class="block group">

        <div class="stat-card bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 transition group-hover:shadow-md group-hover:border-rmc-200 group-hover:-translate-y-0.5">

            <div class="flex justify-between items-start gap-3">

                <div class="min-w-0">

                    <p class="text-slate-500 text-sm">
                        <?= t('attendance'); ?>
                    </p>

                    <h2 class="text-4xl font-bold mt-2 text-rmc-600">
                        <?= $total_attendance; ?>
                    </h2>

                </div>

                <div class="w-14 h-14 rounded-2xl bg-rmc-50 text-rmc-600 flex items-center justify-center shrink-0">

                    <i class="fa-solid fa-clipboard-check text-xl"></i>

                </div>

            </div>

        </div>

    </a>


    <!-- ATTENDANCE RATE -->

    <a href="<?= htmlspecialchars($stat_links['rate']); ?>" class="block group">

        <div class="stat-card bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 transition group-hover:shadow-md group-hover:border-rmc-200 group-hover:-translate-y-0.5">

            <div class="flex justify-between items-start gap-3">

                <div class="min-w-0">

                    <p class="text-slate-500 text-sm">
                        <?= t('attendance_rate'); ?>
                    </p>

                    <h2 class="text-4xl font-bold mt-2 text-rmc-800">
                        <?= $overallRate; ?>%
                    </h2>

                </div>

                <div class="w-14 h-14 rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center shrink-0">

                    <i class="fa-solid fa-chart-pie text-xl"></i>

                </div>

            </div>

        </div>

    </a>

</div>


<!-- =========================================================
     PER-EVENT ANALYTICS TABLE
     ========================================================= -->

<div id="event-analytics" class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 mb-8 animate-up delay-3">

    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">

        <div>

            <h2 class="text-2xl font-bold text-slate-900 flex items-center gap-3">

                <span class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center">

                    <i class="fa-solid fa-table-list"></i>

                </span>

                <?= t('event_analytics'); ?>

            </h2>

            <p class="text-slate-500 mt-1">

                <?= t('event_analytics_desc'); ?>

            </p>

        </div>

        <div class="bg-rmc-50 text-rmc-800 border border-rmc-200 px-4 py-2 rounded-xl font-semibold self-start md:self-auto">

            <i class="fa-solid fa-clipboard-list mr-2"></i>

            <?= count($event_rows); ?>
            <?= count($event_rows) == 1 ? t('event') : t('total_events'); ?>

        </div>

    </div>


    <?php if (count($event_rows) > 0): ?>

        <div class="overflow-x-auto">

            <table class="w-full text-left border-collapse min-w-[760px]">

                <thead>

                    <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">

                        <th class="px-4 py-3 font-semibold">
                            <?= t('event'); ?>
                        </th>

                        <th class="px-4 py-3 font-semibold">
                            <?= t('event_date'); ?>
                        </th>

                        <th class="px-4 py-3 font-semibold">
                            <?= t('venue'); ?>
                        </th>

                        <th class="px-4 py-3 font-semibold text-right">
                            <?= t('registrations'); ?>
                        </th>

                        <th class="px-4 py-3 font-semibold text-right">
                            <?= t('attended'); ?>
                        </th>

                        <th class="px-4 py-3 font-semibold text-right">
                            <?= t('attendance_rate'); ?>
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php
                    $er_back = '';
                    if (!empty($department_filter) || !empty($category_filter) || !empty($year_filter)) {
                        $er_back = '&' . http_build_query(array_filter([
                            'department' => $department_filter,
                            'category'   => $category_filter,
                            'year'       => $year_filter,
                        ]));
                    }
                    ?>
                    <?php foreach ($event_rows as $er): ?>

                        <?php $er_detail_url = 'event_detail.php?event_id=' . $er['event_id'] . $er_back; ?>

                        <tr class="border-b border-slate-100 hover:bg-slate-50 transition cursor-pointer"
                            onclick="window.location.href='<?= htmlspecialchars($er_detail_url); ?>'">

                            <td class="px-4 py-4">

                                <div class="flex items-center gap-3 min-w-0">

                                    <div class="min-w-0">

                                        <a href="<?= htmlspecialchars($er_detail_url); ?>" class="font-semibold text-slate-800 hover:text-rmc-700 truncate max-w-[240px] underline decoration-transparent hover:decoration-rmc-300 transition">
                                            <?= htmlspecialchars($er['title']); ?>
                                        </a>

                                        <?php

                                        $er_status = $er['status'];

                                        $er_badge = 'bg-amber-50 text-amber-700 border border-amber-200';

                                        if ($er_status === 'approved') {
                                            $er_badge = 'bg-emerald-50 text-emerald-700 border border-emerald-200';
                                        } elseif ($er_status === 'rejected') {
                                            $er_badge = 'bg-red-50 text-red-700 border border-red-200';
                                        } elseif ($er_status === 'cancelled') {
                                            $er_badge = 'bg-slate-100 text-slate-600 border border-slate-200';
                                        }

                                        ?>

                                        <span class="mt-1 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $er_badge; ?>">
                                            <?= htmlspecialchars($er_status); ?>
                                        </span>

                                    </div>

                                </div>

                            </td>

                            <td class="px-4 py-4 text-slate-600 whitespace-nowrap">

                                <?php

                                $er_date = $er['event_date'] ? date(
                                    'Y-m-d',
                                    strtotime($er['event_date'])
                                ) : '-';

                                echo htmlspecialchars($er_date);

                                ?>

                            </td>

                            <td class="px-4 py-4 text-slate-600 max-w-[200px] truncate">

                                <?= htmlspecialchars($er['venue'] ?: '-'); ?>

                            </td>

                            <td class="px-4 py-4 text-right">

                                <span class="font-semibold text-slate-700">
                                    <?= $er['registered']; ?>
                                </span>

                            </td>

                            <td class="px-4 py-4 text-right">

                                <span class="font-semibold text-emerald-600">
                                    <?= $er['attended']; ?>
                                </span>

                            </td>

                            <td class="px-4 py-4">

                                <div class="flex items-center justify-end gap-2">

                                    <span class="font-semibold text-slate-700">
                                        <?= $er['rate']; ?>%
                                    </span>

                                    <div class="w-16 bg-slate-100 rounded-full h-1.5 overflow-hidden">

                                        <div
                                            class="h-1.5 rounded-full <?= $er['rate'] >= 50 ? 'bg-emerald-500' : 'bg-amber-500'; ?>"
                                            style="width: <?= min(100, $er['rate']); ?>%;"
                                        ></div>

                                    </div>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php else: ?>

        <div class="text-center py-12 text-slate-500">

            <div class="w-14 h-14 mx-auto rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-4">

                <i class="fa-solid fa-chart-column text-2xl"></i>

            </div>

            <p class="font-semibold">
                <?= t('no_event_data'); ?>
            </p>

        </div>

    <?php endif; ?>

</div>


<!-- =========================================================
     FEEDBACK & SATISFACTION ANALYTICS
     ========================================================= -->

<div id="feedback-analytics" class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 mb-8 animate-up delay-3">

    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">

        <div>

            <h2 class="text-2xl font-bold text-slate-900 flex items-center gap-3">

                <span class="w-10 h-10 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center">

                    <i class="fa-solid fa-star"></i>

                </span>

                <?= t('feedback_satisfaction'); ?>

            </h2>

            <p class="text-slate-500 mt-1">

                <?= t('feedback_analytics_desc'); ?>

            </p>

        </div>

        <div class="bg-amber-50 text-amber-700 border border-amber-200 px-4 py-2 rounded-xl font-semibold self-start md:self-auto">

            <i class="fa-solid fa-comments mr-2"></i>

            <?= $total_feedback; ?>
            <?= $total_feedback == 1 ? t('response') : t('responses'); ?>

        </div>

    </div>


    <div class="grid lg:grid-cols-2 gap-6 sm:gap-8">


        <!-- OVERALL SATISFACTION -->

        <div class="bg-gradient-to-br from-amber-50 to-orange-50 border border-amber-100 rounded-3xl p-8">

            <p class="text-sm font-semibold text-slate-500 uppercase tracking-wide">

                <?= t('overall_satisfaction'); ?>

            </p>

            <div class="flex items-center gap-5 mt-4">

                <div>

                    <h3 class="text-5xl font-bold text-slate-800">

                        <?= number_format(
                            $overall_feedback_rating,
                            1
                        ); ?>

                        <span class="text-2xl text-slate-400">
                            / 5.0
                        </span>

                    </h3>

                </div>

                <div class="text-amber-400 text-3xl">

                    <?php

                    $feedback_rounded =
                        round(
                            $overall_feedback_rating
                        );

                    for (
                        $i = 1;
                        $i <= 5;
                        $i++
                    ):

                    ?>

                        <i
                            class="fa-solid fa-star <?= $i <= $feedback_rounded ? '' : 'text-slate-300'; ?>"
                        ></i>

                    <?php endfor; ?>

                </div>

            </div>

            <p class="text-slate-500 mt-4">

                <?= t('based_on'); ?>
                <strong class="text-slate-700">
                    <?= $total_feedback; ?>
                </strong>
                <?= $total_feedback == 1 ? t('student_response') : t('student_responses'); ?>.

            </p>

            <?php if ($total_feedback === 0): ?>

                <div class="mt-5 bg-white/70 rounded-xl p-4 text-sm text-slate-500">

                    <?= t('no_feedback_filtered'); ?>

                </div>

            <?php endif; ?>

        </div>


        <!-- RATING DISTRIBUTION -->

        <div class="bg-rmc-50/60 border border-rmc-100 rounded-3xl p-8">

            <h3 class="text-xl font-bold text-slate-800 mb-6">

                <?= t('rating_distribution'); ?>

            </h3>

            <?php for (
                $rating = 5;
                $rating >= 1;
                $rating--
            ): ?>

                <div class="flex items-center gap-3 mb-4">

                    <div class="w-10 text-sm font-semibold text-slate-700">

                        <?= $rating; ?> ★

                    </div>

                    <div class="flex-1 bg-slate-200 rounded-full h-4 overflow-hidden">

                        <div
                            class="bg-amber-400 h-4 rounded-full transition-all"
                            style="width: <?= $rating_percentages[$rating]; ?>%;"
                        ></div>

                    </div>

                    <div class="w-16 text-right text-sm font-semibold text-slate-600">

                        <?= $rating_percentages[$rating]; ?>%

                    </div>

                </div>

            <?php endfor; ?>

            <div class="mt-5 text-xs text-slate-500">

                <?= t('rating_distribution_desc'); ?>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     MOST COMMON FEEDBACK
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 mb-8 animate-up delay-3">

    <div class="mb-6">

        <h2 class="text-2xl font-bold text-slate-900 flex items-center gap-3">

            <span class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center">

                <i class="fa-solid fa-comments"></i>

            </span>

            <?= t('most_common_feedback'); ?>

        </h2>

        <p class="text-slate-500 mt-1">

            <?= t('common_feedback_desc'); ?>

        </p>

    </div>

    <?php if (count($common_feedback) > 0): ?>

        <div class="grid md:grid-cols-2 gap-5">

            <?php foreach (
                $common_feedback as $feedback_item
            ):
            ?>

                <div class="bg-slate-50 border border-slate-200 rounded-2xl p-5">

                    <div class="flex items-start gap-4">

                        <div class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center flex-shrink-0">

                            <i class="fa-solid fa-quote-left"></i>

                        </div>

                        <div class="flex-1 min-w-0">

                            <p class="text-slate-700 leading-relaxed break-words">

                                "
                                <?= htmlspecialchars(
                                    $feedback_item['comment']
                                ); ?>
                                "

                            </p>

                            <?php if (
                                $feedback_item['count'] > 1
                            ): ?>

                                <div class="mt-3 inline-flex items-center bg-rmc-100 text-rmc-800 px-3 py-1 rounded-full text-xs font-semibold">

                                    <i class="fa-solid fa-repeat mr-1"></i>

                                    <?= t('mentioned'); ?>
                                    <?= $feedback_item['count']; ?>
                                    <?= t('times'); ?>

                                </div>

                            <?php else: ?>

                                <div class="mt-3 text-xs text-slate-400">

                                    <?= t('student_feedback'); ?>

                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="text-center py-10 bg-slate-50 rounded-2xl px-4">

            <div class="w-14 h-14 mx-auto rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-4">

                <i class="fa-regular fa-comment-dots text-2xl"></i>

            </div>

            <p class="font-semibold text-slate-700">

                <?= t('no_written_feedback'); ?>

            </p>

            <p class="text-slate-500 text-sm mt-1">

                <?= t('no_written_feedback_desc'); ?>

            </p>

        </div>

    <?php endif; ?>

</div>


<!-- =========================================================
     EVENT PERFORMANCE
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 mb-8 animate-up delay-3">

    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-6">

        <div>

            <h2 class="text-2xl font-bold text-slate-900">
                <?= t('event_performance'); ?>
            </h2>

            <p class="text-slate-500">
                <?= t('event_performance_desc'); ?>
            </p>

        </div>

        <div class="bg-rmc-50 text-rmc-800 border border-rmc-200 px-4 py-2 rounded-xl font-semibold">

            <?= t('live_analytics'); ?>

        </div>

    </div>

    <?php if (count($labels) > 0): ?>

        <canvas id="eventChart" height="100"></canvas>

    <?php else: ?>

        <div class="text-center py-12 text-slate-500">

            <div class="w-14 h-14 mx-auto rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-4">

                <i class="fa-solid fa-chart-column text-2xl"></i>

            </div>

            <p class="font-semibold">
                <?= t('no_event_data'); ?>
            </p>

        </div>

    <?php endif; ?>

</div>


<!-- =========================================================
     CHART GRID
     ========================================================= -->

<div class="grid lg:grid-cols-2 gap-6 sm:gap-8 mb-8">

    <!-- ATTENDANCE RATE -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 animate-up delay-3">

        <h2 class="text-2xl font-bold text-slate-900 mb-1">
            <?= t('attendance_rate_by_event'); ?>
        </h2>

        <p class="text-slate-500 mb-6">
            <?= t('attendance_rate_by_event_desc'); ?>
        </p>

        <?php if (count($labels) > 0): ?>

            <canvas id="rateChart" height="220"></canvas>

        <?php else: ?>

            <div class="text-center py-10 text-slate-500">
                <?= t('no_data_available'); ?>
            </div>

        <?php endif; ?>

    </div>


    <!-- REGISTRATION TREND -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 animate-up delay-3">

        <h2 class="text-2xl font-bold text-slate-900 mb-1">
            <?= t('registration_trend'); ?>
        </h2>

        <p class="text-slate-500 mb-6">

            <?php if ($year_filter !== ''): ?>

                <?= t('monthly_registrations_for'); ?>
                <?= htmlspecialchars($year_filter); ?>

            <?php else: ?>

                <?= t('daily_registrations'); ?>

            <?php endif; ?>

        </p>

        <canvas id="trendChart" height="220"></canvas>

    </div>


    <!-- DEPARTMENT -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 animate-up delay-3">

        <h2 class="text-2xl font-bold text-slate-900 mb-1">
            <?= t('registrations_by_department'); ?>
        </h2>

        <p class="text-slate-500 mb-6">
            <?= t('dept_desc'); ?>
        </p>

        <?php if (count($deptLabels) > 0): ?>

            <canvas id="deptChart" height="240"></canvas>

        <?php else: ?>

            <div class="text-center py-10 text-slate-500">
                <?= t('no_department_data'); ?>
            </div>

        <?php endif; ?>

    </div>


    <!-- STATUS -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 animate-up delay-3">

        <h2 class="text-2xl font-bold text-slate-900 mb-1">
            <?= t('event_status_breakdown'); ?>
        </h2>

        <p class="text-slate-500 mb-6">
            <?= t('status_desc'); ?>
        </p>

        <?php if (!empty($current_status_counts)): ?>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <?php
            $status_meta = [
                'approved'   => ['icon' => 'fa-solid fa-calendar-check', 'color' => 'emerald'],
                'pending'    => ['icon' => 'fa-solid fa-clock',         'color' => 'amber'],
                'archived'   => ['icon' => 'fa-solid fa-box-archive',   'color' => 'slate'],
                'rejected'   => ['icon' => 'fa-solid fa-xmark',         'color' => 'red'],
                'cancelled'  => ['icon' => 'fa-solid fa-ban',           'color' => 'orange'],
            ];
            foreach ($current_status_counts as $st => $cnt):
                $m = $status_meta[$st] ?? ['icon' => 'fa-solid fa-circle', 'color' => 'blue'];
            ?>
            <div class="bg-<?= $m['color']; ?>-50 border border-<?= $m['color']; ?>-200 rounded-2xl p-4 text-center">
                <i class="<?= $m['icon']; ?> text-<?= $m['color']; ?>-600 text-xl mb-2"></i>
                <p class="text-2xl font-bold text-<?= $m['color']; ?>-700"><?= $cnt; ?></p>
                <p class="text-xs font-semibold text-<?= $m['color']; ?>-600 uppercase tracking-wide">
                    <?= ucfirst($st); ?>
                </p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (count($statusLabels) > 0): ?>

            <canvas id="statusChart" height="240"></canvas>

        <?php else: ?>

            <div class="text-center py-10 text-slate-500">
                <?= t('no_status_data'); ?>
            </div>

        <?php endif; ?>

    </div>

</div>


<!-- =========================================================
     EVENT + COURSE/PROGRAM BREAKDOWN
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 mb-8 animate-up delay-3">

    <h2 class="text-2xl font-bold text-slate-900 mb-1">
        <?= htmlspecialchars(t('event_course_breakdown')); ?>
    </h2>

    <p class="text-slate-500 mb-6">
        <?= htmlspecialchars(t('event_course_breakdown_desc')); ?>
    </p>

    <?php if (!empty($event_dept)): ?>

        <?php foreach ($event_dept as $ev_title => $depts): ?>

            <div class="mb-6 last:mb-0">

                <h3 class="text-lg font-bold text-slate-800 mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-calendar-days text-rmc-600"></i>
                    <?= htmlspecialchars($ev_title); ?>
                </h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">

                    <?php foreach ($depts as $d): ?>

                        <div class="flex items-center justify-between bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">
                            <span class="font-semibold text-slate-700 text-sm">
                                <?= htmlspecialchars($d['dept']); ?>
                            </span>
                            <span class="bg-rmc-100 text-rmc-700 text-xs font-bold px-3 py-1 rounded-full">
                                <?= $d['total']; ?> <?= $d['total'] === 1 ? 'student' : 'students'; ?>
                            </span>
                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        <?php endforeach; ?>

    <?php else: ?>

        <div class="text-center py-10 text-slate-500">
            <?= htmlspecialchars(t('no_registration_data')); ?>
        </div>

    <?php endif; ?>

</div>


<!-- =========================================================
     CHART.JS + CHART SCRIPT
     ========================================================= -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

<script>


/* =====================================================
   ATTENDANCE GAUGE (semi-doughnut, center text drawn via
   the absolutely-positioned div above the canvas in PHP)
   ===================================================== */

new Chart(
    document.getElementById('gaugeChart'),
    {
        type: 'doughnut',
        data: {
            datasets: [{
                data: [<?= $overallRate; ?>, <?= max(0, 100 - $overallRate); ?>],
                backgroundColor: ['#1E3A5F', '#e5e7eb'],
                borderWidth: 0
            }]
        },
        options: {
            circumference: 180,
            rotation: 270,
            cutout: '75%',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false }
            }
        }
    }
);


/* =====================================================
   EVENT PERFORMANCE
   ===================================================== */

<?php if (count($labels) > 0): ?>

new Chart(
    document.getElementById('eventChart'),
    {
        type: 'bar',

        data: {

            labels:
                <?= json_encode($labels); ?>,

            datasets: [

                {
                    label: <?= json_encode(t('registrations')); ?>,

                    data:
                        <?= json_encode($regCounts); ?>,

                    backgroundColor: '#1E3A5F',

                    borderRadius: 10
                },

                {
                    label: <?= json_encode(t('attendance')); ?>,

                    data:
                        <?= json_encode($attCounts); ?>,

                    backgroundColor: '#94a9c2',

                    borderRadius: 10
                }

            ]

        },

        options: {

            responsive: true,

            plugins: {

                legend: {
                    position: 'top'
                }

            },

            scales: {

                y: {

                    beginAtZero: true,

                    ticks: {
                        precision: 0
                    }

                }

            }

        }

    }
);


/* =====================================================
   ATTENDANCE RATE
   ===================================================== */

new Chart(
    document.getElementById('rateChart'),
    {
        type: 'bar',

        data: {

            labels:
                <?= json_encode($labels); ?>,

            datasets: [

                {

                    label:
                        <?= json_encode(t('attendance_rate_percent')); ?>,

                    data:
                        <?= json_encode($attendanceRates); ?>,

                    backgroundColor:
                        '#059669',

                    borderRadius: 10

                }

            ]

        },

        options: {

            indexAxis: 'y',

            responsive: true,

            plugins: {

                legend: {
                    display: false
                }

            },

            scales: {

                x: {

                    beginAtZero: true,

                    max: 100

                }

            }

        }

    }
);

<?php endif; ?>


/* =====================================================
   REGISTRATION TREND
   ===================================================== */

new Chart(
    document.getElementById('trendChart'),
    {
        type: 'line',

        data: {

            labels:
                <?= json_encode($trendLabels); ?>,

            datasets: [

                {

                    label:
                        <?= json_encode(t('registrations')); ?>,

                    data:
                        <?= json_encode($trendCounts); ?>,

                    borderColor:
                        '#1E3A5F',

                    backgroundColor:
                        'rgba(30,58,95,0.12)',

                    fill: true,

                    tension: 0.35,

                    pointBackgroundColor:
                        '#1E3A5F'

                }

            ]

        },

        options: {

            responsive: true,

            plugins: {

                legend: {
                    display: false
                }

            },

            scales: {

                y: {

                    beginAtZero: true,

                    ticks: {
                        precision: 0
                    }

                }

            }

        }

    }
);


<?php if (count($deptLabels) > 0): ?>


/* =====================================================
   DEPARTMENT CHART
   ===================================================== */

new Chart(
    document.getElementById('deptChart'),
    {
        type: 'doughnut',

        data: {

            labels:
                <?= json_encode($deptLabels); ?>,

            datasets: [

                {

                    data:
                        <?= json_encode($deptCounts); ?>,

                    backgroundColor: [

                        '#1E3A5F',
                        '#2f5580',
                        '#4571a3',
                        '#5f8ec2',
                        '#84acd8',
                        '#b3cce9',
                        '#172A4A',
                        '#0B1F3A'

                    ]

                }

            ]

        },

        options: {

            responsive: true,

            plugins: {

                legend: {

                    position: 'bottom'

                }

            }

        }

    }
);

<?php endif; ?>


<?php if (count($statusLabels) > 0): ?>


/* =====================================================
   STATUS CHART
   ===================================================== */

new Chart(
    document.getElementById('statusChart'),
    {
        type: 'pie',

        data: {

            labels:
                <?= json_encode($statusLabels); ?>,

            datasets: [

                {

                    data:
                        <?= json_encode($statusCounts); ?>,

                    backgroundColor: [

                        '#059669',
                        '#d97706',
                        '#dc2626',
                        '#4b5563'

                    ]

                }

            ]

        },

        options: {

            responsive: true,

            plugins: {

                legend: {

                    position: 'bottom'

                }

            }

        }

    }
);

<?php endif; ?>


</script>


<?php include 'partials/footer.php'; ?>