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

$error   = '';
$success = '';


/*
|--------------------------------------------------------------------------
| Toggle User Email Notifications
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['toggle_email_notif'])
) {

    csrf_verify();

    $target_id = (int) $_POST['toggle_email_notif'];

    if ($target_id <= 0) {

        $error = 'Invalid user ID.';

    } else {

        $current_result = pg_query_params(
            $conn,
            "SELECT user_id, email_notifications, full_name
             FROM users
             WHERE user_id = $1",
            array($target_id)
        );

        $current = pg_fetch_assoc($current_result);

        if (!$current) {

            $error = 'User not found.';

        } else {

            $new_value =
                ($current['email_notifications'] ?? 'f') === 't'
                ? 'false'
                : 'true';

            pg_query_params(
                $conn,
                "UPDATE users
                 SET email_notifications = $1
                 WHERE user_id = $2",
                array($new_value, $target_id)
            );

            $label = $new_value === 'true' ? 'enabled' : 'disabled';
            $success = 'Email notifications ' . $label . ' for ' .
                htmlspecialchars($current['full_name']) . '.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search      = trim($_GET['search'] ?? '');
$role_filter = $_GET['role_filter'] ?? 'all';
$notif_filter = $_GET['notif_filter'] ?? 'all';

if (!in_array($role_filter, ['all', 'student', 'organizer', 'admin'], true)) {
    $role_filter = 'all';
}
if (!in_array($notif_filter, ['all', 'on', 'off'], true)) {
    $notif_filter = 'all';
}


/*
|--------------------------------------------------------------------------
| Query Users
|--------------------------------------------------------------------------
*/

$where   = [];
$params  = [];

if (!empty($search)) {
    $where[]  = "(full_name ILIKE '%' || $" . (count($params) + 1) . "% OR student_id ILIKE '%' || $" . (count($params) + 1) . "%)";
    $params[] = $search;
}

if ($role_filter !== 'all') {
    $where[]  = "role = $" . (count($params) + 1);
    $params[] = $role_filter;
}

if ($notif_filter === 'on') {
    $where[] = "email_notifications = true";
} elseif ($notif_filter === 'off') {
    $where[] = "(email_notifications = false OR email_notifications IS NULL)";
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$params[] = 100;

$users_result = pg_query_params(
    $conn,
    "SELECT user_id, full_name, student_id, role, email, email_notifications, status
     FROM users
     $where_sql
     ORDER BY full_name ASC
     LIMIT $" . count($params),
    $params
);

$users = $users_result ? pg_fetch_all($users_result) : array();


/*
|--------------------------------------------------------------------------
| Counts
|--------------------------------------------------------------------------
*/

$counts_result = pg_query(
    $conn,
    "SELECT
         COUNT(*) AS total,
         SUM(CASE WHEN email_notifications = true THEN 1 ELSE 0 END) AS on_count,
         SUM(CASE WHEN email_notifications = false OR email_notifications IS NULL THEN 1 ELSE 0 END) AS off_count
     FROM users
     WHERE role IN ('student', 'organizer', 'admin')"
);

$counts = pg_fetch_assoc($counts_result);
$total_users  = (int) ($counts['total']   ?? 0);
$on_count     = (int) ($counts['on_count'] ?? 0);
$off_count    = (int) ($counts['off_count'] ?? 0);


/*
|--------------------------------------------------------------------------
| Header Vars
|--------------------------------------------------------------------------
*/

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

$role_label  = 'Administrator';
$page_title  = 'Email Notification Settings — RMC Events';
$active_page = 'admin_email_settings';

?>
<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- HERO -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up"
>

    <div class="flex items-center gap-4 sm:gap-5">

        <div
            class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
        >
            <i class="fa-solid fa-envelope-circle-check text-2xl"></i>
        </div>

        <div>
            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                Email Notification Settings
            </h2>
            <p class="text-slate-600 mt-1 text-sm sm:text-base">
                Manage email notification preferences for all users
            </p>
        </div>

    </div>

</div>


<!-- MESSAGES -->

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


<!-- STATS CARDS -->

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8 animate-up delay-1">

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm px-6 py-5">
        <div class="text-sm text-slate-500 mb-1">Total Users</div>
        <div class="text-3xl font-bold text-slate-900"><?= $total_users; ?></div>
    </div>

    <div class="bg-white rounded-2xl border border-emerald-200 shadow-sm px-6 py-5">
        <div class="text-sm text-emerald-600 mb-1">Emails Enabled</div>
        <div class="text-3xl font-bold text-emerald-700"><?= $on_count; ?></div>
    </div>

    <div class="bg-white rounded-2xl border border-red-200 shadow-sm px-6 py-5">
        <div class="text-sm text-red-500 mb-1">Emails Disabled</div>
        <div class="text-3xl font-bold text-red-600"><?= $off_count; ?></div>
    </div>

</div>


<!-- USERS TABLE CARD -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm overflow-hidden animate-up delay-2">

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 px-6 py-5 border-b border-slate-200">

        <div>
            <h2 class="text-xl font-bold text-slate-900">User Email Preferences</h2>
            <p class="text-slate-500 text-sm mt-1">Toggle email notifications per user</p>
        </div>

        <form method="GET" class="flex gap-2 flex-wrap">

            <input
                type="text"
                name="search"
                value="<?= htmlspecialchars($search); ?>"
                placeholder="Search by name or ID..."
                class="border border-slate-200 rounded-xl px-4 py-2 w-full sm:w-72 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

            <select
                name="role_filter"
                class="border border-slate-200 rounded-xl px-4 py-2 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition text-sm"
            >
                <option value="all" <?= $role_filter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                <option value="student" <?= $role_filter === 'student' ? 'selected' : ''; ?>>Students</option>
                <option value="organizer" <?= $role_filter === 'organizer' ? 'selected' : ''; ?>>Organizers</option>
                <option value="admin" <?= $role_filter === 'admin' ? 'selected' : ''; ?>>Admins</option>
            </select>

            <select
                name="notif_filter"
                class="border border-slate-200 rounded-xl px-4 py-2 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition text-sm"
            >
                <option value="all" <?= $notif_filter === 'all' ? 'selected' : ''; ?>>All Settings</option>
                <option value="on" <?= $notif_filter === 'on' ? 'selected' : ''; ?>>Emails On</option>
                <option value="off" <?= $notif_filter === 'off' ? 'selected' : ''; ?>>Emails Off</option>
            </select>

            <button
                type="submit"
                class="bg-rmc-800 hover:bg-rmc-900 text-white px-4 py-2 rounded-xl text-sm transition"
            >
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>

            <?php if (!empty($search) || $role_filter !== 'all' || $notif_filter !== 'all'): ?>
                <a
                    href="admin_email_settings.php"
                    class="border border-slate-200 px-4 py-2 rounded-xl text-sm text-slate-600 hover:bg-rmc-50 transition"
                >
                    <?= t('clear'); ?>
                </a>
            <?php endif; ?>

        </form>

    </div>


    <div class="overflow-x-auto">

        <table class="min-w-full">

            <thead class="bg-rmc-950 text-white">

                <tr>
                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">Name</th>
                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">Student ID</th>
                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">Role</th>
                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">Status</th>
                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">Email Notifications</th>
                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">Action</th>
                </tr>

            </thead>

            <tbody>

                <?php if (empty($users)): ?>

                    <tr>
                        <td colspan="6" class="px-6 py-16 text-center">
                            <div class="flex flex-col items-center">
                                <div class="w-16 h-16 rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-4">
                                    <i class="fa-solid fa-users text-3xl"></i>
                                </div>
                                <p class="font-semibold text-slate-700">
                                    No users found
                                    <?php if (!empty($search)): ?>
                                        matching "<?= htmlspecialchars($search); ?>"
                                    <?php endif; ?>
                                </p>
                            </div>
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($users as $row): ?>

                        <?php $email_on = ($row['email_notifications'] ?? 'f') === 't'; ?>

                        <tr class="border-b border-slate-100 hover:bg-rmc-50/40 transition">

                            <td class="px-6 py-5 font-semibold text-slate-800">
                                <?= htmlspecialchars($row['full_name']); ?>
                            </td>

                            <td class="px-6 py-5 text-slate-600">
                                <?= htmlspecialchars($row['student_id'] ?? ''); ?>
                            </td>

                            <td class="px-6 py-5 text-center">
                                <?php
                                $role_colors = [
                                    'student'   => 'bg-blue-100 text-blue-700',
                                    'organizer' => 'bg-purple-100 text-purple-700',
                                    'admin'     => 'bg-amber-100 text-amber-700',
                                ];
                                $rc = $role_colors[$row['role']] ?? 'bg-slate-100 text-slate-700';
                                ?>
                                <span class="<?= $rc; ?> px-3 py-1 rounded-full text-xs font-semibold">
                                    <?= htmlspecialchars(ucfirst($row['role'])); ?>
                                </span>
                            </td>

                            <td class="px-6 py-5 text-center">
                                <?php if ($row['status'] === 'active'): ?>
                                    <span class="bg-emerald-100 text-emerald-700 px-3 py-1 rounded-full text-xs font-semibold">
                                        Active
                                    </span>
                                <?php else: ?>
                                    <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-xs font-semibold">
                                        <?= htmlspecialchars(ucfirst($row['status'])); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td class="px-6 py-5 text-center">
                                <?php if ($email_on): ?>
                                    <span class="inline-flex items-center gap-1 bg-emerald-100 text-emerald-700 px-3 py-1 rounded-full text-xs font-semibold">
                                        <i class="fa-solid fa-check-circle"></i> ON
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 bg-slate-100 text-slate-500 px-3 py-1 rounded-full text-xs font-semibold">
                                        <i class="fa-solid fa-circle-xmark"></i> OFF
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td class="px-6 py-5 text-center">
                                <form method="POST" style="display:inline;">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="toggle_email_notif" value="<?= (int) $row['user_id']; ?>">

                                    <?php if ($email_on): ?>

                                        <button
                                            type="button"
                                            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-xl text-xs font-semibold transition"
                                            title="Disable email notifications"
                                            onclick="openConfirmModal({form: this.closest('form'), title: 'Disable Email Notifications', message: 'Disable email notifications for this user? They will no longer receive email alerts.', itemName: <?= json_encode(htmlspecialchars($row['full_name'], ENT_QUOTES)) ?>, itemLabel: 'user', actionText: 'Disable', color: 'red', icon: 'fa-solid fa-envelope-circle-xmark'});"
                                        >
                                            <i class="fa-solid fa-bell-slash mr-1"></i> Disable
                                        </button>

                                    <?php else: ?>

                                        <button
                                            type="button"
                                            class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-xs font-semibold transition"
                                            title="Enable email notifications"
                                            onclick="openConfirmModal({form: this.closest('form'), title: 'Enable Email Notifications', message: 'Enable email notifications for this user? They will receive email alerts for registrations, approvals, and reminders.', itemName: <?= json_encode(htmlspecialchars($row['full_name'], ENT_QUOTES)) ?>, itemLabel: 'user', actionText: 'Enable', color: 'emerald', icon: 'fa-solid fa-envelope-circle-check'});"
                                        >
                                            <i class="fa-solid fa-bell mr-1"></i> Enable
                                        </button>

                                    <?php endif; ?>

                                </form>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- Confirm Modal -->

<div id="confirmModal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/50 backdrop-blur-sm p-4">
    <div class="bg-white rounded-[22px] shadow-2xl w-full max-w-md overflow-hidden animate-in">
        <div class="px-6 pt-6 pb-4 text-center">
            <div id="confirmModalIcon" class="w-14 h-14 rounded-2xl bg-red-100 text-red-600 flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-triangle-exclamation text-2xl"></i>
            </div>
            <h3 id="confirmModalTitle" class="text-xl font-bold text-slate-900 mb-1"></h3>
            <p id="confirmModalMsg" class="text-sm text-slate-600 mb-2"></p>
            <p id="confirmModalItem" class="text-sm text-slate-500"></p>
        </div>
        <div class="flex border-t border-slate-200">
            <button
                id="confirmModalCancelBtn"
                class="flex-1 px-4 py-3.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition"
            >
                Cancel
            </button>
            <button
                id="confirmModalConfirmBtn"
                class="flex-1 px-4 py-3.5 text-sm font-semibold text-white transition"
            ></button>
        </div>
    </div>
</div>


<script>
(function () {
    const modal = document.getElementById('confirmModal');
    const titleEl = document.getElementById('confirmModalTitle');
    const msgEl = document.getElementById('confirmModalMsg');
    const itemEl = document.getElementById('confirmModalItem');
    const iconWrap = document.getElementById('confirmModalIcon');
    const cancelBtn = document.getElementById('confirmModalCancelBtn');
    const confirmBtn = document.getElementById('confirmModalConfirmBtn');
    let pendingForm = null;

    const colorMap = {
        red:    { bg: 'bg-red-100',    text: 'text-red-600',    btn: 'bg-red-600 hover:bg-red-700' },
        emerald:{ bg: 'bg-emerald-100', text: 'text-emerald-600', btn: 'bg-emerald-600 hover:bg-emerald-700' },
        amber:  { bg: 'bg-amber-100',  text: 'text-amber-600',  btn: 'bg-amber-600 hover:bg-amber-700' },
    };

    window.openConfirmModal = function (opts) {
        pendingForm = opts.form;
        titleEl.textContent = opts.title || '';
        msgEl.textContent = opts.message || '';
        itemEl.textContent = (opts.itemLabel ? opts.itemLabel + ': ' : '') + (opts.itemName || '');
        const c = colorMap[opts.color] || colorMap.red;
        iconWrap.className = 'w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-4 ' + c.bg + ' ' + c.text;
        iconWrap.innerHTML = '<i class="' + (opts.icon || 'fa-solid fa-triangle-exclamation') + ' text-2xl"></i>';
        confirmBtn.className = 'flex-1 px-4 py-3.5 text-sm font-semibold text-white transition ' + c.btn;
        confirmBtn.textContent = opts.actionText || 'Confirm';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    };

    cancelBtn.addEventListener('click', function () {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        pendingForm = null;
    });

    confirmBtn.addEventListener('click', function () {
        if (pendingForm) {
            pendingForm.submit();
        }
    });

    modal.addEventListener('click', function (e) {
        if (e.target === modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            pendingForm = null;
        }
    });
})();
</script>


<?php include 'partials/footer.php'; ?>
