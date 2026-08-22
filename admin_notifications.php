<?php

session_start();

include 'db_connect.php';
require 'lang.php';
require 'csrf.php';

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    http_response_code(403);
    die("Access denied. Admins only.");
}

$admin_id = (int) $_SESSION['user_id'];

$error = '';
$success = '';


/*
|--------------------------------------------------------------------------
| Delete Single Notification
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['delete_notification_id'])
) {

    csrf_verify();

    $notif_id = (int) $_POST['delete_notification_id'];

    if ($notif_id <= 0) {

        $error = t('invalid_user_id_msg');

    } else {

        $result = pg_query_params(
            $conn,
            "DELETE FROM notifications WHERE notification_id = $1",
            array($notif_id)
        );

        if ($result && pg_affected_rows($result) > 0) {
            $success = t('notification_deleted');
        } else {
            $error = t('notification_not_found');
        }
    }
}


/*
|--------------------------------------------------------------------------
| Bulk Delete Notifications
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['bulk_delete_ids']) &&
    is_array($_POST['bulk_delete_ids'])
) {

    csrf_verify();

    $ids = array_map('intval', $_POST['bulk_delete_ids']);
    $ids = array_filter($ids, function ($id) {
        return $id > 0;
    });
    $ids = array_values($ids);

    if (empty($ids)) {

        $error = t('no_items_selected');

    } else {

        $placeholders = array();
        $params = array();

        foreach ($ids as $i => $id) {
            $placeholders[] = '$' . ($i + 1);
            $params[] = $id;
        }

        $in_clause = implode(',', $placeholders);

        $result = pg_query_params(
            $conn,
            "DELETE FROM notifications WHERE notification_id IN ($in_clause)",
            $params
        );

        $deleted = $result ? pg_affected_rows($result) : 0;

        if ($deleted > 0) {
            $success = sprintf(t('notifications_deleted'), $deleted);
        } else {
            $error = sprintf(t('notifications_not_found'), count($ids));
        }
    }
}


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search'])
    ? trim($_GET['search'])
    : '';

$filter_role = isset($_GET['filter_role'])
    ? trim($_GET['filter_role'])
    : 'all';

$page = isset($_GET['page']) && (int) $_GET['page'] > 0
    ? (int) $_GET['page']
    : 1;

$per_page = 50;
$offset = ($page - 1) * $per_page;


/*
|--------------------------------------------------------------------------
| Build Query
|--------------------------------------------------------------------------
*/

$where_clauses = array();
$params = array();
$param_idx = 1;

if ($filter_role !== 'all' && in_array($filter_role, ['student', 'organizer', 'admin'])) {
    $where_clauses[] = "u.role = \$$param_idx";
    $params[] = $filter_role;
    $param_idx++;
}

if (!empty($search)) {
    $where_clauses[] = "n.message ILIKE \$$param_idx";
    $params[] = '%' . $search . '%';
    $param_idx++;
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}


/*
|--------------------------------------------------------------------------
| Total Count
|--------------------------------------------------------------------------
*/

$count_sql = "
    SELECT COUNT(*) AS total
    FROM notifications n
    JOIN users u ON n.user_id = u.user_id
    $where_sql
";

$count_result = pg_query_params($conn, $count_sql, $params);
$total_notifications = (int) pg_fetch_result($count_result, 0, 'total');


/*
|--------------------------------------------------------------------------
| Paginated Notifications
|--------------------------------------------------------------------------
*/

$query_sql = "
    SELECT n.*, u.full_name, u.student_id, u.role AS user_role
    FROM notifications n
    JOIN users u ON n.user_id = u.user_id
    $where_sql
    ORDER BY n.created_at DESC
    LIMIT $per_page OFFSET $offset
";

$notifications = pg_query_params($conn, $query_sql, $params);


/*
|--------------------------------------------------------------------------
| Summary Counts
|--------------------------------------------------------------------------
*/

$summary_total = (int) pg_fetch_result(
    pg_query($conn, "SELECT COUNT(*) FROM notifications"),
    0,
    0
);

$summary_unread = (int) pg_fetch_result(
    pg_query($conn, "SELECT COUNT(*) FROM notifications WHERE is_read = false"),
    0,
    0
);

$summary_students = (int) pg_fetch_result(
    pg_query($conn, "SELECT COUNT(*) FROM notifications n JOIN users u ON n.user_id = u.user_id WHERE u.role = 'student'"),
    0,
    0
);

$summary_organizers = (int) pg_fetch_result(
    pg_query($conn, "SELECT COUNT(*) FROM notifications n JOIN users u ON n.user_id = u.user_id WHERE u.role = 'organizer'"),
    0,
    0
);

$summary_admins = (int) pg_fetch_result(
    pg_query($conn, "SELECT COUNT(*) FROM notifications n JOIN users u ON n.user_id = u.user_id WHERE u.role = 'admin'"),
    0,
    0
);

$total_pages = max(1, (int) ceil($total_notifications / $per_page));


/*
|--------------------------------------------------------------------------
| Role Badge
|--------------------------------------------------------------------------
*/

function admin_notif_role_badge($role)
{
    switch ($role) {
        case 'admin':
            return 'bg-purple-100 text-purple-700';
        case 'organizer':
            return 'bg-rmc-100 text-rmc-800';
        default:
            return 'bg-gray-100 text-gray-700';
    }
}


/*
|--------------------------------------------------------------------------
| Notification Type Badge
|--------------------------------------------------------------------------
*/

function notif_type_badge($type)
{
    switch ($type) {
        case 'event_reminder':
            return 'bg-yellow-100 text-yellow-700';
        case 'registration':
            return 'bg-green-100 text-green-700';
        case 'event_approval':
            return 'bg-rmc-100 text-rmc-800';
        case 'event_rejection':
            return 'bg-red-100 text-red-700';
        case 'new_event':
            return 'bg-purple-100 text-purple-700';
        case 'event_cancelled':
            return 'bg-red-100 text-red-700';
        case 'attendance':
            return 'bg-green-100 text-green-700';
        default:
            return 'bg-gray-100 text-gray-700';
    }
}


/*
|--------------------------------------------------------------------------
| Shared Partial Variables
|--------------------------------------------------------------------------
*/

$full_name = $_SESSION['full_name'] ?? '';
$first_name = explode(' ', trim($full_name))[0];

$unread_count = (int) pg_fetch_result(
    pg_query_params(
        $conn,
        "SELECT COUNT(*)
         FROM notifications
         WHERE user_id = $1
           AND is_read = false",
        array($admin_id)
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
    array($admin_id)
);

$role_label  = 'Administrator';
$page_title  = t('notification_management');
$active_page = 'admin_notifications';


/*
|--------------------------------------------------------------------------
| Build query-string helper (preserves filters)
|--------------------------------------------------------------------------
*/

function build_query_string($overrides = array())
{
    $params = array(
        'search'     => $_GET['search']     ?? '',
        'filter_role' => $_GET['filter_role'] ?? 'all',
        'page'       => $_GET['page']       ?? '1',
    );

    $params = array_merge($params, $overrides);

    $pairs = array();

    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null) {
            $pairs[] = $k . '=' . urlencode($v);
        }
    }

    return implode('&', $pairs);
}

?>
<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     NOTIFICATION MANAGEMENT HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up"
>

    <div class="flex items-center gap-4 sm:gap-5">

        <div
            class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
        >

            <i class="fa-solid fa-bell text-2xl"></i>

        </div>

        <div>

            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                <?= t('notification_management'); ?>
            </h2>

            <p class="text-slate-600 mt-1 text-sm sm:text-base">
                <?= t('notification_manage_desc'); ?>
            </p>

        </div>

    </div>

</div>


<!-- =========================================================
     MESSAGES
     ========================================================= -->

<?php if ($error): ?>

    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 mb-6 animate-up delay-1">

        <i class="fa-solid fa-circle-exclamation mr-2"></i>

        <?= htmlspecialchars($error); ?>

    </div>

<?php endif; ?>


<?php if ($success): ?>

    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 mb-6 animate-up delay-1">

        <i class="fa-solid fa-circle-check mr-2"></i>

        <?= htmlspecialchars($success); ?>

    </div>

<?php endif; ?>


<!-- =========================================================
     STATISTICS
     ========================================================= -->

<div class="grid grid-cols-1 md:grid-cols-3 gap-5 sm:gap-6 mb-8">

    <!-- TOTAL NOTIFICATIONS -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 animate-up delay-1">

        <div class="flex justify-between items-start gap-3">

            <div class="min-w-0">

                <p class="text-slate-500 text-sm">
                    <?= t('total_notifications'); ?>
                </p>

                <h2 class="text-4xl font-bold mt-2 text-slate-800">
                    <?= $summary_total; ?>
                </h2>

            </div>

            <div class="w-14 h-14 rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center shrink-0">

                <i class="fa-solid fa-bell text-xl"></i>

            </div>

        </div>

    </div>


    <!-- UNREAD COUNT -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 animate-up delay-2">

        <div class="flex justify-between items-start gap-3">

            <div class="min-w-0">

                <p class="text-slate-500 text-sm">
                    <?= t('unread_count'); ?>
                </p>

                <h2 class="text-4xl font-bold mt-2 text-amber-600">
                    <?= $summary_unread; ?>
                </h2>

            </div>

            <div class="w-14 h-14 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0">

                <i class="fa-solid fa-envelope-open text-xl"></i>

            </div>

        </div>

    </div>


    <!-- BY ROLE: STUDENTS -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 animate-up delay-3">

        <div class="flex justify-between items-start gap-3">

            <div class="min-w-0">

                <p class="text-slate-500 text-sm">
                    <?= t('role'); ?>: <?= t('students'); ?>
                </p>

                <h2 class="text-4xl font-bold mt-2 text-emerald-600">
                    <?= $summary_students; ?>
                </h2>

            </div>

            <div class="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">

                <i class="fa-solid fa-user-graduate text-xl"></i>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     NOTIFICATIONS TABLE
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm overflow-hidden animate-up delay-2">

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 px-6 py-5 border-b border-slate-200">

        <div>

            <h2 class="text-xl font-bold text-slate-900">
                <?= t('all_notifications'); ?>
            </h2>

            <p class="text-slate-500 text-sm mt-1">
                <?= $total_notifications; ?> total &middot;
                <?= $summary_unread; ?> <?= t('unread_count'); ?>
            </p>

        </div>


        <form method="GET" class="flex gap-2 flex-wrap">

            <select
                name="filter_role"
                class="border border-slate-200 rounded-xl px-4 py-2 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition text-sm"
            >

                <option value="all" <?= $filter_role === 'all' ? 'selected' : ''; ?>>
                    <?= t('all_roles'); ?>
                </option>

                <option value="student" <?= $filter_role === 'student' ? 'selected' : ''; ?>>
                    <?= t('students'); ?>
                </option>

                <option value="organizer" <?= $filter_role === 'organizer' ? 'selected' : ''; ?>>
                    <?= t('organizers'); ?>
                </option>

                <option value="admin" <?= $filter_role === 'admin' ? 'selected' : ''; ?>>
                    <?= t('admins'); ?>
                </option>

            </select>

            <input
                type="text"
                name="search"
                value="<?= htmlspecialchars($search); ?>"
                placeholder="<?= t('search_users_placeholder'); ?>"
                class="border border-slate-200 rounded-xl px-4 py-2 w-full sm:w-72 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

            <button
                type="submit"
                class="bg-rmc-800 hover:bg-rmc-900 text-white px-4 py-2 rounded-xl text-sm transition"
            >

                <i class="fa-solid fa-magnifying-glass"></i>

            </button>

            <?php if (!empty($search) || $filter_role !== 'all'): ?>

                <a
                    href="admin_notifications.php"
                    class="border border-slate-200 px-4 py-2 rounded-xl text-sm text-slate-600 hover:bg-rmc-50 transition"
                >

                    <?= t('clear'); ?>

                </a>

            <?php endif; ?>

        </form>

    </div>


    <!-- BULK ACTION TOOLBAR -->

    <div
        id="bulkNotifBar"
        class="hidden px-6 py-3 border-b border-slate-200 bg-rmc-50/60"
    >

        <div class="flex items-center gap-4 flex-wrap">

            <span id="bulkNotifCount" class="text-sm font-semibold text-rmc-800"></span>

            <form method="POST" id="bulkNotifForm" class="flex items-center gap-3 flex-wrap">

                <?= csrf_field(); ?>

                <button
                    type="button"
                    onclick="submitBulkNotifDelete()"
                    class="px-4 py-2 rounded-xl text-sm font-semibold bg-red-100 text-red-700 hover:bg-red-200 transition inline-flex items-center gap-2"
                >
                    <i class="fa-solid fa-trash"></i>
                    <?= t('delete'); ?>
                </button>

            </form>

        </div>

    </div>


    <div class="overflow-x-auto">

        <table class="min-w-full">

            <thead class="bg-rmc-950 text-white">

                <tr>

                    <th class="px-6 py-4 text-center w-10">
                        <input
                            type="checkbox"
                            id="selectAllNotifs"
                            class="w-4 h-4 rounded border-slate-300 text-rmc-600 focus:ring-rmc-500 cursor-pointer"
                            aria-label="<?= t('select_all'); ?>"
                            onchange="toggleSelectAll(this, 'notif-checkbox'); updateBulkNotifBar();"
                        >
                    </th>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('id'); ?>
                    </th>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('recipient'); ?>
                    </th>

                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">
                        <?= t('role'); ?>
                    </th>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('message'); ?>
                    </th>

                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">
                        <?= t('notification_type'); ?>
                    </th>

                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">
                        <?= t('status'); ?>
                    </th>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('date_sent'); ?>
                    </th>

                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">
                        <?= t('actions'); ?>
                    </th>

                </tr>

            </thead>

            <tbody>

                <?php if (pg_num_rows($notifications) === 0): ?>

                    <tr>

                        <td colspan="9" class="px-6 py-16 text-center">

                            <div class="flex flex-col items-center">

                                <div class="w-16 h-16 rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-4">

                                    <i class="fa-regular fa-bell-slash text-3xl"></i>

                                </div>

                                <p class="font-semibold text-slate-700">

                                    <?= t('no_notifications_found'); ?>

                                </p>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

                <?php while ($row = pg_fetch_assoc($notifications)): ?>

                    <tr class="border-b border-slate-100 hover:bg-rmc-50/40 transition">

                        <td class="px-6 py-5 text-center">
                            <input
                                type="checkbox"
                                name="notif_ids[]"
                                value="<?= (int) $row['notification_id']; ?>"
                                data-notif-message="<?= htmlspecialchars($row['message'], ENT_QUOTES); ?>"
                                class="notif-checkbox w-4 h-4 rounded border-slate-300 text-rmc-600 focus:ring-rmc-500 cursor-pointer"
                                onchange="updateBulkNotifBar();"
                            >
                        </td>

                        <td class="px-6 py-5 text-slate-500">
                            <?= (int) $row['notification_id']; ?>
                        </td>

                        <td class="px-6 py-5">

                            <div class="font-semibold text-slate-800">
                                <?= htmlspecialchars($row['full_name']); ?>
                            </div>

                            <?php if (!empty($row['student_id'])): ?>
                                <div class="text-xs text-slate-500 mt-0.5">
                                    <?= htmlspecialchars($row['student_id']); ?>
                                </div>
                            <?php endif; ?>

                        </td>

                        <td class="text-center">

                            <span class="px-3 py-1 rounded-full text-xs font-semibold capitalize <?= admin_notif_role_badge($row['user_role']); ?>">

                                <?= htmlspecialchars($row['user_role']); ?>

                            </span>

                        </td>

                        <td class="px-6 py-5 text-slate-600 text-sm max-w-xs truncate" title="<?= htmlspecialchars($row['message']); ?>">

                            <?= htmlspecialchars(mb_strimwidth($row['message'], 0, 80, '...')); ?>

                        </td>

                        <td class="text-center">

                            <span class="px-3 py-1 rounded-full text-xs font-semibold capitalize <?= notif_type_badge($row['type']); ?>">

                                <?= htmlspecialchars(str_replace('_', ' ', $row['type'])); ?>

                            </span>

                        </td>

                        <td class="text-center">

                            <?php if ($row['is_read']): ?>

                                <span class="bg-emerald-100 text-emerald-700 px-3 py-1 rounded-full text-xs font-semibold">

                                    <?= t('read'); ?>

                                </span>

                            <?php else: ?>

                                <span class="bg-amber-100 text-amber-700 px-3 py-1 rounded-full text-xs font-semibold">

                                    <?= t('unread'); ?>

                                </span>

                            <?php endif; ?>

                        </td>

                        <td class="px-6 py-5 text-slate-600 whitespace-nowrap text-sm">
                            <?= htmlspecialchars($row['created_at']); ?>
                        </td>

                        <td class="px-6 py-5">

                            <div class="flex justify-center gap-2">

                                <form method="POST">

                                    <?= csrf_field(); ?>

                                    <input
                                        type="hidden"
                                        name="delete_notification_id"
                                        value="<?= (int) $row['notification_id']; ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-xl text-sm transition"
                                        title="<?= t('delete'); ?>"
                                    >

                                        <i class="fa-solid fa-trash"></i>

                                    </button>

                                </form>

                            </div>

                        </td>

                    </tr>

                <?php endwhile; ?>

            </tbody>

        </table>

    </div>


    <!-- PAGINATION -->

    <?php if ($total_pages > 1): ?>

        <div class="px-6 py-5 border-t border-slate-200 flex items-center justify-between flex-wrap gap-3">

            <p class="text-sm text-slate-500">
                <?= $total_notifications; ?> total &middot;
                Page <?= $page; ?> of <?= $total_pages; ?>
            </p>

            <div class="flex items-center gap-2">

                <?php if ($page > 1): ?>

                    <a
                        href="?<?= build_query_string(['page' => $page - 1]); ?>"
                        class="border border-slate-200 px-4 py-2 rounded-xl text-sm text-slate-600 hover:bg-rmc-50 transition"
                    >

                        <i class="fa-solid fa-chevron-left"></i>

                    </a>

                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>

                    <?php if ($i === $page): ?>

                        <span class="bg-rmc-800 text-white px-4 py-2 rounded-xl text-sm font-semibold">
                            <?= $i; ?>
                        </span>

                    <?php else: ?>

                        <a
                            href="?<?= build_query_string(['page' => $i]); ?>"
                            class="border border-slate-200 px-4 py-2 rounded-xl text-sm text-slate-600 hover:bg-rmc-50 transition"
                        >
                            <?= $i; ?>
                        </a>

                    <?php endif; ?>

                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>

                    <a
                        href="?<?= build_query_string(['page' => $page + 1]); ?>"
                        class="border border-slate-200 px-4 py-2 rounded-xl text-sm text-slate-600 hover:bg-rmc-50 transition"
                    >

                        <i class="fa-solid fa-chevron-right"></i>

                    </a>

                <?php endif; ?>

            </div>

        </div>

    <?php endif; ?>

</div>


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
                    <?= htmlspecialchars(t('confirm')); ?>
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

/* =========================================================
   GENERIC CONFIRMATION MODAL
   ========================================================= */

var CONFIRM_PENDING_FORM = null;

function openConfirmModal(opts) {

    CONFIRM_PENDING_FORM = opts.form || null;

    document.getElementById('confirmModalHeader').className = 'bg-red-700 text-white px-6 py-5 flex items-center gap-3';
    document.getElementById('confirmModalIcon').className = (opts.icon || 'fa-solid fa-trash') + ' text-lg';
    document.getElementById('confirmModalTitle').textContent = opts.title || '';
    document.getElementById('confirmModalMessage').textContent = opts.message || '';

    var itemBox = document.getElementById('confirmModalItemBox');
    itemBox.className = 'mt-4 bg-red-50 border border-red-200 rounded-2xl px-4 py-3';

    document.getElementById('confirmModalItemLabel').className = 'text-[10px] font-bold uppercase tracking-wide text-red-500 mb-1';
    document.getElementById('confirmModalItemLabel').textContent = opts.itemLabel || '';
    document.getElementById('confirmModalItemName').textContent = opts.itemName || '—';

    var btn = document.getElementById('confirmModalConfirmBtn');
    btn.className = 'flex-1 bg-red-700 hover:bg-red-800 text-white font-bold px-5 py-3 rounded-xl transition inline-flex items-center justify-center gap-2';
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

        if (_bulkNotifDeleteIds) {
            var ids = _bulkNotifDeleteIds;
            _bulkNotifDeleteIds = null;

            var csrfToken = document.querySelector('#bulkNotifForm input[name="csrf_token"]').value;
            var formData = new FormData();
            formData.append('csrf_token', csrfToken);
            for (var i = 0; i < ids.length; i++) {
                formData.append('bulk_delete_ids[]', ids[i]);
            }

            closeConfirmModal();

            fetch('admin_notifications.php', {
                method: 'POST',
                body: formData
            }).then(function() {
                window.location.reload();
            });

            return;
        }

        if (CONFIRM_PENDING_FORM) {
            CONFIRM_PENDING_FORM.submit();
        }
    });


/* =========================================================
   SELECT ALL + BULK ACTIONS
   ========================================================= */

function toggleSelectAll(master, groupClass) {

    var boxes = document.querySelectorAll('.' + groupClass);
    for (var i = 0; i < boxes.length; i++) {
        boxes[i].checked = master.checked;
    }
}

function updateBulkNotifBar() {

    var checked = document.querySelectorAll('.notif-checkbox:checked');
    var count = checked.length;

    var bar = document.getElementById('bulkNotifBar');
    var countEl = document.getElementById('bulkNotifCount');
    var master = document.getElementById('selectAllNotifs');

    if (count > 0) {
        bar.classList.remove('hidden');
        countEl.textContent = <?= json_encode(t('selected_count')); ?>.replace('%d', count);
    } else {
        bar.classList.add('hidden');
    }

    if (master) {
        var allBoxes = document.querySelectorAll('.notif-checkbox');
        master.checked = allBoxes.length > 0 && count === allBoxes.length;
        master.indeterminate = count > 0 && count < allBoxes.length;
    }
}


/* =========================================================
   BULK DELETE HANDLER
   ========================================================= */

var _bulkNotifDeleteIds = null;

function submitBulkNotifDelete() {

    var checked = document.querySelectorAll('.notif-checkbox:checked');
    if (checked.length === 0) return;

    var ids = [];
    for (var i = 0; i < checked.length; i++) {
        ids.push(checked[i].value);
    }

    _bulkNotifDeleteIds = ids;

    openConfirmModal({
        form: null,
        title: <?= json_encode(t('delete')); ?>,
        message: <?= json_encode(t('bulk_delete_confirm')); ?>.replace('%d', checked.length),
        itemName: checked.length + ' <?= t('notifications'); ?>',
        itemLabel: <?= json_encode(t('selected_count')); ?>.replace('%d', checked.length),
        actionText: <?= json_encode(t('delete')); ?>,
        color: 'red',
        icon: 'fa-solid fa-trash'
    });
}


/* =========================================================
   KEYBOARD: ESC TO CLOSE
   ========================================================= */

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

</script>


<?php include 'partials/footer.php'; ?>
