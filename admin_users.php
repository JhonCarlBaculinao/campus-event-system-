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
$already_active_popup = false;

/*
|--------------------------------------------------------------------------
| Toggle User Status
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['toggle_status'])
) {

    csrf_verify();

    $target_id = (int) $_POST['toggle_status'];

    if ($target_id <= 0) {

        $error = t('invalid_user_id_msg');

    } elseif ($target_id === $admin_id) {

        $error = t('cannot_modify_own_status');

    } else {

        $current_result = pg_query_params(
            $conn,
            "SELECT status
             FROM users
             WHERE user_id = $1",
            array($target_id)
        );

        $current = pg_fetch_assoc($current_result);

        if (!$current) {

            $error = t('user_account_not_found');

        } else {

            if (isset($_POST['set_status']) && $_POST['set_status'] === 'active' && $current['status'] === 'active') {

                $already_active_popup = true;

            } else {

            $new_status =
                ($current['status'] === 'active')
                ? 'deactivated'
                : 'active';

            $update_result = pg_query_params(
                $conn,
                "UPDATE users
                 SET status = $1
                 WHERE user_id = $2",
                array(
                    $new_status,
                    $target_id
                )
            );

            if ($update_result) {

                $success =
                    $new_status === 'active'
                    ? t('user_activated_msg')
                    : t('user_deactivated_msg');

                // Notify the affected user
                $notif_msg = $new_status === 'active'
                    ? 'Your account has been activated by an administrator.'
                    : 'Your account has been deactivated by an administrator.';

                pg_query_params(
                    $conn,
                    "INSERT INTO notifications (user_id, message, type) VALUES ($1, $2, 'admin_action')",
                    array($target_id, $notif_msg)
                );

                // Audit log
                pg_query_params(
                    $conn,
                    "INSERT INTO audit_log (admin_user_id, action, target_user_id, details) VALUES ($1, $2, $3, $4)",
                    array($admin_id, 'toggle_status_' . $new_status, $target_id, 'Status changed to ' . $new_status)
                );

            } else {

                $error = t('user_status_update_error');
            }

            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Bulk Actions
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['bulk_action']) &&
    isset($_POST['user_ids'])
) {

    csrf_verify();

    $action = $_POST['bulk_action'];
    $user_ids = array_map('intval', $_POST['user_ids']);
    $user_ids = array_filter($user_ids, function ($id) use ($admin_id) {
        return $id > 0 && $id !== $admin_id;
    });
    $user_ids = array_values($user_ids);

    if (empty($user_ids)) {

        $error = t('no_items_selected');

    } else {

        $processed = 0;
        $skipped = 0;

        foreach ($user_ids as $uid) {

            if ($action === 'bulk_deactivate') {
                $r = pg_query_params(
                    $conn,
                    "UPDATE users SET status = 'deactivated' WHERE user_id = $1 AND status = 'active'",
                    [$uid]
                );
            } elseif ($action === 'bulk_activate') {
                $r = pg_query_params(
                    $conn,
                    "UPDATE users SET status = 'active' WHERE user_id = $1 AND status = 'deactivated'",
                    [$uid]
                );
            } else {
                continue;
            }

            if ($r && pg_affected_rows($r) > 0) {
                $processed++;
            } else {
                $skipped++;
            }
        }

        if ($skipped === 0) {
            $success = sprintf(t('bulk_action_success'), $processed);
        } else {
            $success = sprintf(t('bulk_action_partial'), $processed, $skipped);
        }
    }
}


/*
|--------------------------------------------------------------------------
| Manual Unlock by Admin
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['unlock_user_id'])
) {

    csrf_verify();

    $unlock_id = (int) $_POST['unlock_user_id'];

    if ($unlock_id <= 0) {

        $error = t('invalid_user_id_msg');

    } elseif ($unlock_id === $admin_id) {

        $error = t('cannot_modify_own_status');

    } else {

        pg_query_params(
            $conn,
            "UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = $1",
            [$unlock_id]
        );

        // Notify the affected user
        pg_query_params(
            $conn,
            "INSERT INTO notifications (user_id, message, type) VALUES ($1, 'Your account has been unlocked by an administrator.', 'admin_action')",
            array($unlock_id)
        );

        // Audit log
        pg_query_params(
            $conn,
            "INSERT INTO audit_log (admin_user_id, action, target_user_id, details) VALUES ($1, 'unlock', $2, 'Account unlocked')",
            array($admin_id, $unlock_id)
        );

        $success = t('user_unlocked') ?: 'Account has been unlocked.';
    }
}


/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search'])
    ? trim($_GET['search'])
    : '';


/*
|--------------------------------------------------------------------------
| Get Users
|--------------------------------------------------------------------------
*/

if (!empty($search)) {

    $users = pg_query_params(
        $conn,

        "SELECT *,
                CASE WHEN locked_until IS NOT NULL AND locked_until > NOW()
                     THEN EXTRACT(EPOCH FROM (locked_until - NOW()))::int
                     ELSE 0 END AS lockout_secs
         FROM users
         WHERE full_name ILIKE $1
            OR student_id ILIKE $1
            OR department ILIKE $1
            OR role ILIKE $1
         ORDER BY role, full_name",

        array('%' . $search . '%')
    );

} else {

    $users = pg_query(
        $conn,
        "SELECT *,
                CASE WHEN locked_until IS NOT NULL AND locked_until > NOW()
                     THEN EXTRACT(EPOCH FROM (locked_until - NOW()))::int
                     ELSE 0 END AS lockout_secs
         FROM users
         ORDER BY role, full_name"
    );
}


/*
|--------------------------------------------------------------------------
| Role Badge
|--------------------------------------------------------------------------
*/

function role_badge($role)
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
$page_title  = t('title_manage_users');
$active_page = 'admin_users';

?>
<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     USER MANAGEMENT HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up"
>

    <div class="flex items-center gap-4 sm:gap-5">

        <div
            class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
        >

            <i class="fa-solid fa-users text-2xl"></i>

        </div>

        <div>

            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                <?= t('user_account_management'); ?>
            </h2>

            <p class="text-slate-600 mt-1 text-sm sm:text-base">
                <?= t('manage_users_hero_desc'); ?>
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
     USERS TABLE
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm overflow-hidden animate-up delay-2">

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 px-6 py-5 border-b border-slate-200">

        <div>

            <h2 class="text-xl font-bold text-slate-900">
                <?= t('registered_users'); ?>
            </h2>

            <p class="text-slate-500 text-sm mt-1">
                <?= t('registered_users_desc'); ?>
            </p>

        </div>


        <form method="GET" class="flex gap-2">

            <input
                type="text"
                name="search"
                value="<?= htmlspecialchars($search); ?>"
                placeholder="<?= t('search_users_placeholder'); ?>"
                class="border border-slate-200 rounded-xl px-4 py-2 w-full sm:w-80 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

            <button
                type="submit"
                class="bg-rmc-800 hover:bg-rmc-900 text-white px-4 py-2 rounded-xl text-sm transition"
            >

                <i class="fa-solid fa-magnifying-glass"></i>

            </button>

            <?php if (!empty($search)): ?>

                <a
                    href="admin_users.php"
                    class="border border-slate-200 px-4 py-2 rounded-xl text-sm text-slate-600 hover:bg-rmc-50 transition"
                >

                    <?= t('clear'); ?>

                </a>

            <?php endif; ?>

        </form>

    </div>


    <!-- BULK ACTION TOOLBAR (hidden by default) -->

    <div
        id="bulkUserBar"
        class="hidden px-6 py-3 border-b border-slate-200 bg-rmc-50/60"
    >

        <div class="flex items-center gap-4 flex-wrap">

            <span id="bulkUserCount" class="text-sm font-semibold text-rmc-800"></span>

            <form method="POST" id="bulkUserForm" class="flex items-center gap-3 flex-wrap">

                <?= csrf_field(); ?>

                <input type="hidden" name="bulk_action" id="bulkUserAction" value="">

                <button
                    type="button"
                    class="px-4 py-2 rounded-xl text-sm font-semibold bg-emerald-100 text-emerald-700 hover:bg-emerald-200 transition inline-flex items-center gap-2"
                    onclick="submitBulkUser('bulk_activate')"
                >
                    <i class="fa-solid fa-user-check"></i>
                    <?= t('bulk_activate_users'); ?>
                </button>

                <button
                    type="button"
                    class="px-4 py-2 rounded-xl text-sm font-semibold bg-red-100 text-red-700 hover:bg-red-200 transition inline-flex items-center gap-2"
                    onclick="submitBulkUser('bulk_deactivate')"
                >
                    <i class="fa-solid fa-user-slash"></i>
                    <?= t('bulk_deactivate_users'); ?>
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
                            id="selectAllUsers"
                            class="w-4 h-4 rounded border-slate-300 text-rmc-600 focus:ring-rmc-500 cursor-pointer"
                            aria-label="<?= t('select_all'); ?>"
                            onchange="toggleSelectAll(this, 'user-checkbox'); updateBulkUserBar();"
                        >
                    </th>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('id'); ?>
                    </th>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('full_name'); ?>
                    </th>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('student_id'); ?>
                    </th>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('department_label'); ?>
                    </th>

                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">
                        <?= t('role'); ?>
                    </th>

                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">
                        <?= t('status'); ?>
                    </th>

                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">
                        <?= t('lockout'); ?>
                    </th>

                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">
                        <?= t('actions'); ?>
                    </th>

                </tr>

            </thead>

            <tbody>

                <?php if (pg_num_rows($users) === 0): ?>

                    <tr>

                        <td colspan="9" class="px-6 py-16 text-center">

                            <div class="flex flex-col items-center">

                                <div class="w-16 h-16 rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-4">

                                    <i class="fa-solid fa-user-slash text-3xl"></i>

                                </div>

                                <p class="font-semibold text-slate-700">

                                    <?= t('no_users_found_matching'); ?>
                                    "<?= htmlspecialchars($search); ?>".

                                </p>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>


                <?php while ($row = pg_fetch_assoc($users)): ?>

                    <tr class="border-b border-slate-100 hover:bg-rmc-50/40 transition">

                        <td class="px-6 py-5 text-center">
                            <?php if ((int) $row['user_id'] !== $admin_id): ?>
                            <input
                                type="checkbox"
                                name="user_ids[]"
                                value="<?= (int) $row['user_id']; ?>"
                                data-user-name="<?= htmlspecialchars($row['full_name'], ENT_QUOTES); ?>"
                                data-user-role="<?= htmlspecialchars($row['role'], ENT_QUOTES); ?>"
                                data-user-status="<?= htmlspecialchars($row['status'], ENT_QUOTES); ?>"
                                class="user-checkbox w-4 h-4 rounded border-slate-300 text-rmc-600 focus:ring-rmc-500 cursor-pointer"
                                onchange="updateBulkUserBar();"
                                aria-label="<?= htmlspecialchars($row['full_name']); ?>"
                            >
                            <?php endif; ?>
                        </td>

                        <td class="px-6 py-5 text-slate-500">
                            <?= (int) $row['user_id']; ?>
                        </td>

                        <td class="px-6 py-5 font-semibold text-slate-800">
                            <?= htmlspecialchars($row['full_name']); ?>
                        </td>

                        <td class="px-6 py-5 text-slate-600">
                            <?= htmlspecialchars($row['student_id'] ?? ''); ?>
                        </td>

                        <td class="px-6 py-5 text-slate-600">
                            <?= htmlspecialchars($row['department'] ?? ''); ?>
                        </td>

                        <td class="text-center">

                            <span class="px-3 py-1 rounded-full text-xs font-semibold capitalize <?= role_badge($row['role']); ?>">

                                <?= htmlspecialchars($row['role']); ?>

                            </span>

                        </td>

                        <td class="text-center">

                            <?php if ($row['status'] === "active"): ?>

                                <span class="bg-emerald-100 text-emerald-700 px-3 py-1 rounded-full text-xs font-semibold">

                                    <?= t('active'); ?>

                                </span>

                            <?php else: ?>

                                <span class="bg-slate-200 text-slate-700 px-3 py-1 rounded-full text-xs font-semibold">

                                    <?= t('deactivated'); ?>

                                </span>

                            <?php endif; ?>

                        </td>

                        <td class="text-center">

                            <?php
                            $is_locked = (($row['lockout_secs'] ?? 0) > 0);
                            $remaining = $is_locked ? (int) ceil(((int) $row['lockout_secs']) / 60) : 0;
                            ?>

                            <?php if ($is_locked): ?>

                                <span class="inline-flex items-center gap-1 bg-amber-100 text-amber-700 px-3 py-1 rounded-full text-xs font-semibold" title="Locked for <?= $remaining ?> more minutes">

                                    <i class="fa-solid fa-lock"></i>

                                    <?= $remaining ?>m

                                </span>

                            <?php elseif ($row['failed_attempts'] > 0): ?>

                                <span class="text-xs text-slate-400"><?= (int) $row['failed_attempts'] ?>/5 attempts</span>

                            <?php else: ?>

                                <span class="text-xs text-slate-400">&mdash;</span>

                            <?php endif; ?>

                        </td>

                        <td class="px-6 py-5">

                            <div class="flex justify-center gap-2">

                                <!-- Edit -->

                                <a
                                    href="edit_user.php?id=<?= (int) $row['user_id']; ?>"
                                    class="bg-rmc-800 hover:bg-rmc-900 text-white px-4 py-2 rounded-xl text-sm transition"
                                    title="<?= t('edit_user_title'); ?>"
                                >

                                    <i class="fa-solid fa-pen"></i>

                                </a>


                                <!-- Toggle Status -->

                                <?php if ((int) $row['user_id'] !== $admin_id): ?>

                                    <form method="POST" data-action-form="<?= (int) $row['user_id']; ?>-toggle">

                                        <?= csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="toggle_status"
                                            value="<?= (int) $row['user_id']; ?>"
                                        >

                                        <?php if ($row['status'] === "active"): ?>

                                            <button
                                                type="button"
                                                class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-xl text-sm transition"
                                                title="<?= t('deactivate_user'); ?>"
                                                onclick="openConfirmModal({form: this.closest('form'), title: <?= htmlspecialchars(json_encode(t('deactivate_user')), ENT_QUOTES) ?>, message: <?= htmlspecialchars(json_encode(t('deactivate_user_confirm')), ENT_QUOTES) ?>, itemName: <?= htmlspecialchars(json_encode(htmlspecialchars($row['full_name'], ENT_QUOTES)), ENT_QUOTES) ?>, itemLabel: <?= htmlspecialchars(json_encode(t('user')), ENT_QUOTES) ?>, actionText: <?= htmlspecialchars(json_encode(t('deactivate_user')), ENT_QUOTES) ?>, color: 'red', icon: 'fa-solid fa-user-slash'});"
                                            >

                                                <i class="fa-solid fa-user-slash"></i>

                                            </button>

                                        <?php else: ?>

                                            <input type="hidden" name="set_status" value="active">

                                            <button
                                                type="button"
                                                class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-sm transition"
                                                title="<?= t('activate_user'); ?>"
                                                onclick="openConfirmModal({form: this.closest('form'), title: <?= htmlspecialchars(json_encode(t('activate_user')), ENT_QUOTES) ?>, message: <?= htmlspecialchars(json_encode(t('activate_user_confirm')), ENT_QUOTES) ?>, itemName: <?= htmlspecialchars(json_encode(htmlspecialchars($row['full_name'], ENT_QUOTES)), ENT_QUOTES) ?>, itemLabel: <?= htmlspecialchars(json_encode(t('user')), ENT_QUOTES) ?>, actionText: <?= htmlspecialchars(json_encode(t('activate_user')), ENT_QUOTES) ?>, color: 'emerald', icon: 'fa-solid fa-user-check'});"
                                            >

                                                <i class="fa-solid fa-user-check"></i>

                                            </button>

                                        <?php endif; ?>

                                    </form>

                                <?php else: ?>

                                    <span
                                        class="bg-slate-200 text-slate-500 px-4 py-2 rounded-xl text-sm cursor-not-allowed"
                                        title="<?= t('cannot_change_own_status'); ?>"
                                    >

                                        <i class="fa-solid fa-shield-halved"></i>

                                    </span>

                                <?php endif; ?>

                                <?php if ($is_locked && (int) $row['user_id'] !== $admin_id): ?>

                                    <form method="POST" class="inline" data-action-form="<?= (int) $row['user_id']; ?>-unlock">

                                        <?= csrf_field(); ?>

                                        <input type="hidden" name="unlock_user_id" value="<?= (int) $row['user_id']; ?>">

                                        <button
                                            type="button"
                                            class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-2 rounded-xl text-sm transition"
                                            title="Unlock account"
                                            onclick="openConfirmModal({form: this.closest('form'), title: <?= json_encode(t('unlock_account') ?: 'Unlock Account') ?>, message: <?= json_encode(t('unlock_account_confirm') ?: 'This will reset the failed login counter and unlock this account.') ?>, itemName: <?= json_encode(htmlspecialchars($row['full_name'], ENT_QUOTES)) ?>, itemLabel: <?= json_encode(t('user')) ?>, actionText: <?= json_encode(t('unlock_account') ?: 'Unlock Account') ?>, color: 'amber', icon: 'fa-solid fa-lock-open'});"
                                        >

                                            <i class="fa-solid fa-lock-open"></i>

                                        </button>

                                    </form>

                                <?php endif; ?>

                            </div>

                        </td>

                    </tr>

                <?php endwhile; ?>

            </tbody>

        </table>

    </div>

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
                id="confirmModalCancelBtn"
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

var COLOR_MAP = {
    red:     { header: 'bg-red-700',    itemBg: 'bg-red-50',    itemBorder: 'border-red-200',    itemLabel: 'text-red-500',    btn: 'bg-red-700 hover:bg-red-800' },
    emerald: { header: 'bg-emerald-700',itemBg: 'bg-emerald-50',itemBorder: 'border-emerald-200',itemLabel: 'text-emerald-500',btn: 'bg-emerald-700 hover:bg-emerald-800' },
    amber:   { header: 'bg-amber-700',  itemBg: 'bg-amber-50',  itemBorder: 'border-amber-200',  itemLabel: 'text-amber-500',  btn: 'bg-amber-700 hover:bg-amber-800' },
    slate:   { header: 'bg-slate-700',  itemBg: 'bg-slate-50',  itemBorder: 'border-slate-200',  itemLabel: 'text-slate-500',  btn: 'bg-slate-700 hover:bg-slate-800' },
    blue:    { header: 'bg-blue-700',   itemBg: 'bg-blue-50',   itemBorder: 'border-blue-200',   itemLabel: 'text-blue-500',   btn: 'bg-blue-700 hover:bg-blue-800' }
};

function openConfirmModal(opts) {

    CONFIRM_PENDING_FORM = opts.form || null;

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

    var cancelBtn = document.getElementById('confirmModalCancelBtn');
    if (CONFIRM_PENDING_FORM) {
        cancelBtn.classList.remove('hidden');
        cancelBtn.textContent = <?= json_encode(t('cancel')); ?>;
    } else {
        cancelBtn.classList.add('hidden');
    }

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
        } else {
            closeConfirmModal();
        }
    });


/* =========================================================
   SELECT ALL + BULK ACTIONS
   ========================================================= */

function toggleSelectAll(master, groupClass) {

    var boxes = document.querySelectorAll('.' + groupClass);
    for (var i = 0; i < boxes.length; i++) {
        if (!boxes[i].disabled) {
            boxes[i].checked = master.checked;
        }
    }
}

function updateBulkUserBar() {

    var checked = document.querySelectorAll('.user-checkbox:checked');
    var count = checked.length;

    var bar = document.getElementById('bulkUserBar');
    var countEl = document.getElementById('bulkUserCount');
    var master = document.getElementById('selectAllUsers');
    var allBoxes = document.querySelectorAll('.user-checkbox');

    if (count > 0) {
        bar.classList.remove('hidden');
        countEl.textContent = <?= json_encode(t('selected_count')); ?>.replace('%d', count);
    } else {
        bar.classList.add('hidden');
    }

    if (master && allBoxes.length > 0) {
        var enabledCount = 0;
        var checkedEnabled = 0;
        for (var i = 0; i < allBoxes.length; i++) {
            if (!allBoxes[i].disabled) {
                enabledCount++;
                if (allBoxes[i].checked) checkedEnabled++;
            }
        }
        master.checked = enabledCount > 0 && checkedEnabled === enabledCount;
        master.indeterminate = checkedEnabled > 0 && checkedEnabled < enabledCount;
    }
}

function submitBulkUser(action) {

    var checked = document.querySelectorAll('.user-checkbox:checked');
    if (checked.length === 0) {
        return;
    }

    if (action === 'bulk_activate') {
        var allAlreadyActive = true;
        for (var a = 0; a < checked.length; a++) {
            if (checked[a].getAttribute('data-user-status') !== 'active') {
                allAlreadyActive = false;
                break;
            }
        }
        if (allAlreadyActive) {
            openConfirmModal({
                form: null,
                title: <?= json_encode(t('activate_user')) ?>,
                message: 'This user is already active.',
                itemName: '',
                itemLabel: '',
                actionText: 'OK',
                color: 'blue',
                icon: 'fa-solid fa-circle-info'
            });
            return;
        }
    }

    var names = [];
    for (var i = 0; i < checked.length; i++) {
        names.push(checked[i].getAttribute('data-user-name'));
    }

    var confirmKey = (action === 'bulk_deactivate') ? <?= json_encode(t('bulk_deactivate_confirm')) ?> : <?= json_encode(t('bulk_activate_confirm')) ?>;
    var actionKey = (action === 'bulk_deactivate') ? <?= json_encode(t('bulk_deactivate_users')) ?> : <?= json_encode(t('bulk_activate_users')) ?>;
    var icon = (action === 'bulk_deactivate') ? 'fa-solid fa-user-slash' : 'fa-solid fa-user-check';
    var color = (action === 'bulk_deactivate') ? 'red' : 'emerald';

    var form = document.getElementById('bulkUserForm');
    var actionInput = document.getElementById('bulkUserAction');
    actionInput.value = action;

    var oldInputs = form.querySelectorAll('.bulk-user-id-input');
    for (var j = 0; j < oldInputs.length; j++) { oldInputs[j].remove(); }

    for (var k = 0; k < checked.length; k++) {
        var hid = document.createElement('input');
        hid.type = 'hidden';
        hid.name = 'user_ids[]';
        hid.value = checked[k].value;
        hid.className = 'bulk-user-id-input';
        form.appendChild(hid);
    }

    openConfirmModal({
        form: form,
        title: actionKey,
        message: confirmKey.replace('%d', checked.length),
        itemName: names.join(', '),
        itemLabel: <?= json_encode(t('selected_count')); ?>.replace('%d', checked.length),
        actionText: actionKey,
        color: color,
        icon: icon
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

<?php if ($already_active_popup): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    openConfirmModal({
        form: null,
        title: <?= htmlspecialchars(json_encode(t('activate_user')), ENT_QUOTES) ?>,
        message: <?= htmlspecialchars(json_encode('This user is already active.'), ENT_QUOTES) ?>,
        itemName: '',
        itemLabel: '',
        actionText: 'OK',
        color: 'blue',
        icon: 'fa-solid fa-circle-info'
    });
});
</script>
<?php endif; ?>

<?php include 'partials/footer.php'; ?>
