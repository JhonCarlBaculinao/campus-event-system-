<?php


include 'db_connect.php';
require 'lang.php';
require 'csrf.php';
require_once 'notifications_helper.php';

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
| Approve Pending Organizer
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['approve_organizer'])
) {

    csrf_verify();

    $target_id = (int) $_POST['approve_organizer'];

    if ($target_id <= 0) {

        $error = t('invalid_user_id_msg');

    } else {

        $temp_password = rmc_generate_temp_password(10);
        $password_hash = password_hash($temp_password, PASSWORD_DEFAULT);

        $update_result = $pdo->prepare("UPDATE users
             SET status = 'active', password = ?, must_change_password = 1
             WHERE user_id = ? AND role = 'organizer' AND status = 'pending'");
        $update_result->execute(array($password_hash, $target_id));

        if ($update_result && $update_result->rowCount() > 0) {

            $org_stmt = $pdo->prepare("SELECT full_name, email FROM users WHERE user_id = ?");
            $org_stmt->execute(array($target_id));
            $org = $org_stmt->fetch(PDO::FETCH_ASSOC);

            $email_html = build_organizer_approved_email_html($org['full_name'], $temp_password);

            notify_user(
                $pdo,
                $target_id,
                'Your organizer account has been approved! Check your email for your temporary password.',
                'admin_action',
                'Your RMC Events Organizer Account Has Been Approved',
                $email_html,
                true
            );

            $pdo->prepare("INSERT INTO audit_log (admin_user_id, target_user_id, target_event_id, action, details) VALUES (?, ?, NULL, 'approve_organizer', 'Organizer application approved, temporary password issued')")
                ->execute(array($admin_id, $target_id));

            header("Location: admin_manage_organizers.php?msg=approved");
            exit();

        } else {

            $error = 'This organizer application could not be found or was already reviewed.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Reject Pending Organizer
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['reject_organizer'])
) {

    csrf_verify();

    $target_id = (int) $_POST['reject_organizer'];

    if ($target_id <= 0) {

        $error = t('invalid_user_id_msg');

    } else {

        $update_result = $pdo->prepare("UPDATE users
             SET status = 'rejected'
             WHERE user_id = ? AND role = 'organizer' AND status = 'pending'");
        $update_result->execute(array($target_id));

        if ($update_result && $update_result->rowCount() > 0) {

            notify_user(
                $pdo,
                $target_id,
                'Your organizer account application was not approved. Please contact the administrator for details.',
                'admin_action'
            );

            $pdo->prepare("INSERT INTO audit_log (admin_user_id, target_user_id, target_event_id, action, details) VALUES (?, ?, NULL, 'reject_organizer', 'Organizer application rejected')")
                ->execute(array($admin_id, $target_id));

            header("Location: admin_manage_organizers.php?msg=rejected");
            exit();

        } else {

            $error = 'This organizer application could not be found or was already reviewed.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Toggle Organizer Status (active <-> deactivated)
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

        $current_result = $pdo->prepare("SELECT status
             FROM users
             WHERE user_id = ? AND role = 'organizer'");
        $current_result->execute(array($target_id));

        $current = $current_result->fetch(PDO::FETCH_ASSOC);

        if (!$current) {

            $error = t('user_account_not_found');

        } elseif (!in_array($current['status'], ['active', 'deactivated'], true)) {

            $error = 'This organizer still has a pending or rejected application and cannot be toggled directly.';

        } else {

            $new_status =
                ($current['status'] === 'active')
                ? 'deactivated'
                : 'active';

            $update_result = $pdo->prepare("UPDATE users
                 SET status = ?
                 WHERE user_id = ? AND role = 'organizer'");
            $update_result->execute(array(
                $new_status,
                $target_id
            ));

            if ($update_result) {

                if ($new_status === 'deactivated') {
                    $pdo->prepare("UPDATE users SET session_token = NULL WHERE user_id = ? AND role = 'organizer'")
                        ->execute([$target_id]);
                }

                notify_user(
                    $pdo,
                    $target_id,
                    $new_status === 'active'
                        ? 'Your organizer account has been activated by an administrator.'
                        : 'Your organizer account has been deactivated by an administrator.',
                    'admin_action',
                    $new_status === 'active'
                        ? 'Regis Marie College - Your Organizer Account Has Been Activated'
                        : 'Regis Marie College - Your Organizer Account Has Been Deactivated',
                    null,
                    true
                );

                $pdo->prepare("INSERT INTO audit_log (admin_user_id, target_user_id, target_event_id, action, details) VALUES (?, ?, NULL, ?, ?)")
                    ->execute(array(
                        $admin_id,
                        $target_id,
                        'toggle_organizer_status_' . $new_status,
                        'Organizer status changed to ' . $new_status
                    ));

                header("Location: admin_manage_organizers.php?msg=updated");
                exit();

            } else {

                $error = t('user_status_update_error');
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Unlock Organizer
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['unlock_organizer_id'])
) {

    csrf_verify();

    $unlock_id = (int) $_POST['unlock_organizer_id'];

    if ($unlock_id <= 0) {

        $error = t('invalid_user_id_msg');

    } elseif ($unlock_id === $admin_id) {

        $error = t('cannot_modify_own_status');

    } else {

        $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = ? AND role = 'organizer'")
            ->execute(array($unlock_id));

        notify_user(
            $pdo,
            $unlock_id,
            'Your organizer account has been unlocked by an administrator.',
            'admin_action',
            'Regis Marie College - Your Organizer Account Has Been Unlocked',
            null,
            true
        );

        $pdo->prepare("INSERT INTO audit_log (admin_user_id, target_user_id, target_event_id, action, details) VALUES (?, ?, NULL, 'unlock_organizer', 'Organizer account unlocked')")
            ->execute(array($admin_id, $unlock_id));

        header("Location: admin_manage_organizers.php?msg=unlocked");
        exit();
    }
}


/*
|--------------------------------------------------------------------------
| Flash Messages
|--------------------------------------------------------------------------
*/

$msg = $_GET['msg'] ?? '';

if ($msg === 'updated') {
    $success = 'Organizer status updated successfully.';
} elseif ($msg === 'unlocked') {
    $success = t('user_unlocked') ?: 'Account has been unlocked.';
} elseif ($msg === 'approved') {
    $success = 'Organizer application approved.';
} elseif ($msg === 'rejected') {
    $success = 'Organizer application rejected.';
}


/*
|--------------------------------------------------------------------------
| Search & Filter
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search'])
    ? trim($_GET['search'])
    : '';

$status_filter = isset($_GET['status_filter'])
    ? trim($_GET['status_filter'])
    : 'all';

$valid_status_filters = ['all', 'pending', 'active', 'deactivated', 'rejected'];

if (!in_array($status_filter, $valid_status_filters, true)) {
    $status_filter = 'all';
}


/*
|--------------------------------------------------------------------------
| Pending Count (for the tab badge)
|--------------------------------------------------------------------------
*/

$pending_count_stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'organizer' AND status = 'pending'");
$pending_count = (int) $pending_count_stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| Get Organizers
|--------------------------------------------------------------------------
*/

$params = [];
$where_clauses = ["u.role = 'organizer'"];

if (!empty($search)) {
    $like = '%' . $search . '%';
    $where_clauses[] = "(u.full_name LIKE ? OR u.student_id LIKE ?)";
    $params[] = $like;
    $params[] = $like;
}

if ($status_filter !== 'all') {
    $where_clauses[] = "u.status = ?";
    $params[] = $status_filter;
}

$where_sql = implode(' AND ', $where_clauses);

$orgs = $pdo->prepare("SELECT u.*, CASE WHEN u.locked_until IS NOT NULL AND u.locked_until > NOW() THEN TIMESTAMPDIFF(SECOND, NOW(), u.locked_until) ELSE 0 END AS lockout_secs, (SELECT COUNT(*) FROM events e WHERE e.organizer_id = u.user_id AND e.status != 'deleted') AS events_created FROM users u WHERE $where_sql ORDER BY FIELD(u.status, 'pending', 'active', 'deactivated', 'rejected'), u.full_name");
$orgs->execute($params);


/*
|--------------------------------------------------------------------------
| Shared Partial Variables
|--------------------------------------------------------------------------
*/

$full_name = $_SESSION['full_name'] ?? '';
$first_name = explode(' ', trim($full_name))[0];

$unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_stmt->execute([$admin_id]);
$unread_count = (int) $unread_stmt->fetchColumn();

$recent_notifications = $pdo->prepare("SELECT notification_id, type, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $recent_notifications->execute([$admin_id]);

$role_label  = 'Administrator';
$page_title  = 'Manage Organizers — RMC Events';
$active_page = 'admin_manage_organizers';

?>
<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     MANAGE ORGANIZERS HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up"
>

    <div class="flex items-center gap-4 sm:gap-5">

        <div
            class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
        >

            <i class="fa-solid fa-people-group text-2xl"></i>

        </div>

        <div>

            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                Manage Organizers
            </h2>

            <p class="text-slate-600 mt-1 text-sm sm:text-base">
                View, approve, and manage organizer accounts
            </p>

        </div>

        <?php if ($pending_count > 0): ?>

                <a
                    href="admin_manage_organizers.php?status_filter=pending"
                class="ml-auto hidden sm:inline-flex items-center gap-2 bg-amber-500 hover:bg-amber-600 text-white px-4 py-2.5 rounded-xl text-sm font-semibold transition shadow-sm"
            >
                <i class="fa-solid fa-clock"></i>
                <?= $pending_count; ?> pending approval<?= $pending_count === 1 ? '' : 's'; ?>
            </a>

        <?php endif; ?>

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
     ORGANIZER LIST CARD
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm overflow-hidden animate-up delay-2">

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 px-6 py-5 border-b border-slate-200">

        <div>

            <h2 class="text-xl font-bold text-slate-900">
                Organizer Accounts
            </h2>

            <p class="text-slate-500 text-sm mt-1">
                All registered organizer accounts in the system
            </p>

        </div>


        <form method="GET" class="flex gap-2 flex-wrap">

            <input
                type="text"
                name="search"
                value="<?= htmlspecialchars($search); ?>"
                placeholder="Search by name or ID..."
                class="border border-slate-200 rounded-xl px-4 py-2 w-full sm:w-72 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($status_filter); ?>">

            <button
                type="submit"
                class="bg-rmc-800 hover:bg-rmc-900 text-white px-4 py-2 rounded-xl text-sm transition"
            >

                <i class="fa-solid fa-magnifying-glass"></i>

            </button>

            <?php if (!empty($search) || $status_filter !== 'all'): ?>

                    <a
                        href="admin_manage_organizers.php"
                    class="border border-slate-200 px-4 py-2 rounded-xl text-sm text-slate-600 hover:bg-rmc-50 transition"
                >

                    <?= t('clear'); ?>

                </a>

            <?php endif; ?>

        </form>

    </div>


    <!-- STATUS FILTER TABS -->

    <div class="flex flex-wrap items-center gap-2 px-6 py-4 border-b border-slate-200 bg-rmc-50/40">

        <?php foreach (['all', 'pending', 'active', 'deactivated', 'rejected'] as $fk): ?>

                <a
                    href="admin_manage_organizers.php<?= ($fk !== 'all') ? '?status_filter=' . $fk : ''; ?><?= !empty($search) ? (($fk !== 'all') ? '&' : '?') . 'search=' . urlencode($search) : ''; ?>"
                class="px-4 py-2 rounded-xl text-sm font-semibold transition inline-flex items-center gap-2 <?= $status_filter === $fk ? 'bg-rmc-800 text-white' : 'bg-white text-slate-600 border border-slate-200 hover:bg-rmc-100'; ?>"
            >

                <?= htmlspecialchars(ucfirst($fk)); ?>

                <?php if ($fk === 'pending' && $pending_count > 0): ?>
                    <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold <?= $status_filter === 'pending' ? 'bg-white text-rmc-800' : 'bg-amber-500 text-white'; ?>">
                        <?= $pending_count; ?>
                    </span>
                <?php endif; ?>

            </a>

        <?php endforeach; ?>

    </div>


    <div class="overflow-x-auto">

        <table class="min-w-full">

            <thead class="bg-rmc-950 text-white">

                <tr>

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
                        <?= t('status'); ?>
                    </th>

                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">
                        Events Created
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

                <?php if ($orgs->rowCount() === 0): ?>

                    <tr>

                        <td colspan="7" class="px-6 py-16 text-center">

                            <div class="flex flex-col items-center">

                                <div class="w-16 h-16 rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-4">

                                    <i class="fa-solid fa-people-group text-3xl"></i>

                                </div>

                                <p class="font-semibold text-slate-700">

                                    No organizers found
                                    <?php if (!empty($search)): ?>
                                        matching "<?= htmlspecialchars($search); ?>"
                                    <?php endif; ?>

                                </p>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>


                <?php while ($row = $orgs->fetch(PDO::FETCH_ASSOC)): ?>

                    <?php
                    $is_locked = (($row['lockout_secs'] ?? 0) > 0);
                    $remaining = $is_locked ? (int) ceil(((int) $row['lockout_secs']) / 60) : 0;
                    $is_pending = ($row['status'] === 'pending');
                    ?>

                    <tr class="border-b border-slate-100 hover:bg-rmc-50/40 transition <?= $is_pending ? 'bg-amber-50/40' : ''; ?>">

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

                            <?php if ($row['status'] === "active"): ?>

                                <span class="bg-emerald-100 text-emerald-700 px-3 py-1 rounded-full text-xs font-semibold">
                                    <?= t('active'); ?>
                                </span>

                            <?php elseif ($row['status'] === "pending"): ?>

                                <span class="bg-amber-100 text-amber-700 px-3 py-1 rounded-full text-xs font-semibold">
                                    <i class="fa-solid fa-clock mr-1"></i> Pending
                                </span>

                            <?php elseif ($row['status'] === "rejected"): ?>

                                <span class="bg-slate-200 text-slate-600 px-3 py-1 rounded-full text-xs font-semibold">
                                    Rejected
                                </span>

                            <?php else: ?>

                                <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-xs font-semibold">
                                    <?= t('deactivated'); ?>
                                </span>

                            <?php endif; ?>

                        </td>

                        <td class="text-center">

                            <span class="bg-rmc-100 text-rmc-800 px-3 py-1 rounded-full text-xs font-semibold">

                                <?= (int) $row['events_created']; ?>

                            </span>

                        </td>

                        <td class="text-center">

                            <?php if ($is_locked): ?>

                                <span class="inline-flex items-center gap-1 bg-amber-100 text-amber-700 px-3 py-1 rounded-full text-xs font-semibold" title="Locked for <?= $remaining ?> more minutes">

                                    <i class="fa-solid fa-lock"></i>

                                    <?= $remaining ?>m

                                </span>

                            <?php elseif (($row['failed_attempts'] ?? 0) > 0): ?>

                                <span class="text-xs text-slate-400"><?= (int) $row['failed_attempts'] ?>/5 attempts</span>

                            <?php else: ?>

                                <span class="text-xs text-slate-400">&mdash;</span>

                            <?php endif; ?>

                        </td>

                        <td class="px-6 py-5">

                            <div class="flex justify-center gap-2">

                                <?php if ($is_pending): ?>

                                    <!-- Approve -->

                                    <form method="POST" data-action-form="<?= (int) $row['user_id']; ?>-approve">

                                        <?= csrf_field(); ?>

                                        <input type="hidden" name="approve_organizer" value="<?= (int) $row['user_id']; ?>">

                                        <button
                                            type="button"
                                            class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-sm transition"
                                            title="Approve organizer"
                                            onclick="openConfirmModal({form: this.closest('form'), title: 'Approve Organizer', message: 'This will activate the account and let them log in and create events.', itemName: <?= htmlspecialchars(json_encode(htmlspecialchars($row['full_name'], ENT_QUOTES)), ENT_QUOTES) ?>, itemLabel: 'Organizer', actionText: 'Approve', color: 'emerald', icon: 'fa-solid fa-check'});"
                                        >
                                            <i class="fa-solid fa-check"></i>
                                        </button>

                                    </form>

                                    <!-- Reject -->

                                    <form method="POST" data-action-form="<?= (int) $row['user_id']; ?>-reject">

                                        <?= csrf_field(); ?>

                                        <input type="hidden" name="reject_organizer" value="<?= (int) $row['user_id']; ?>">

                                        <button
                                            type="button"
                                            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-xl text-sm transition"
                                            title="Reject organizer"
                                            onclick="openConfirmModal({form: this.closest('form'), title: 'Reject Organizer', message: 'This applicant will not be able to log in. They will be notified of the decision.', itemName: <?= htmlspecialchars(json_encode(htmlspecialchars($row['full_name'], ENT_QUOTES)), ENT_QUOTES) ?>, itemLabel: 'Organizer', actionText: 'Reject', color: 'red', icon: 'fa-solid fa-xmark'});"
                                        >
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>

                                    </form>

                                <?php elseif (in_array($row['status'], ['active', 'deactivated'], true)): ?>

                                    <!-- Toggle Status -->

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
                                                onclick="openConfirmModal({form: this.closest('form'), title: <?= htmlspecialchars(json_encode(t('deactivate_user')), ENT_QUOTES) ?>, message: <?= htmlspecialchars(json_encode(t('deactivate_user_confirm')), ENT_QUOTES) ?>, itemName: <?= htmlspecialchars(json_encode(htmlspecialchars($row['full_name'], ENT_QUOTES)), ENT_QUOTES) ?>, itemLabel: <?= htmlspecialchars(json_encode(t('organizer')), ENT_QUOTES) ?>, actionText: <?= htmlspecialchars(json_encode(t('deactivate_user')), ENT_QUOTES) ?>, color: 'red', icon: 'fa-solid fa-user-slash'});"
                                            >

                                                <i class="fa-solid fa-user-slash"></i>

                                            </button>

                                        <?php else: ?>

                                            <button
                                                type="button"
                                                class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-sm transition"
                                                title="<?= t('activate_user'); ?>"
                                                onclick="openConfirmModal({form: this.closest('form'), title: <?= htmlspecialchars(json_encode(t('activate_user')), ENT_QUOTES) ?>, message: <?= htmlspecialchars(json_encode(t('activate_user_confirm')), ENT_QUOTES) ?>, itemName: <?= htmlspecialchars(json_encode(htmlspecialchars($row['full_name'], ENT_QUOTES)), ENT_QUOTES) ?>, itemLabel: <?= htmlspecialchars(json_encode(t('organizer')), ENT_QUOTES) ?>, actionText: <?= htmlspecialchars(json_encode(t('activate_user')), ENT_QUOTES) ?>, color: 'emerald', icon: 'fa-solid fa-user-check'});"
                                            >

                                                <i class="fa-solid fa-user-check"></i>

                                            </button>

                                        <?php endif; ?>

                                    </form>

                                <?php endif; ?>

                                <!-- Unlock -->

                                <?php if ($is_locked): ?>

                                    <form method="POST" class="inline" data-action-form="<?= (int) $row['user_id']; ?>-unlock">

                                        <?= csrf_field(); ?>

                                        <input type="hidden" name="unlock_organizer_id" value="<?= (int) $row['user_id']; ?>">

                                        <button
                                            type="button"
                                            class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-2 rounded-xl text-sm transition"
                                            title="Unlock account"
                                            onclick="openConfirmModal({form: this.closest('form'), title: <?= htmlspecialchars(json_encode(t('unlock_account') ?: 'Unlock Account'), ENT_QUOTES) ?>, message: <?= htmlspecialchars(json_encode(t('unlock_account_confirm') ?: 'This will reset the failed login counter and unlock this account.'), ENT_QUOTES) ?>, itemName: <?= htmlspecialchars(json_encode(htmlspecialchars($row['full_name'], ENT_QUOTES)), ENT_QUOTES) ?>, itemLabel: <?= htmlspecialchars(json_encode(t('organizer')), ENT_QUOTES) ?>, actionText: <?= htmlspecialchars(json_encode(t('unlock_account') ?: 'Unlock Account'), ENT_QUOTES) ?>, color: 'amber', icon: 'fa-solid fa-lock-open'});"
                                        >

                                            <i class="fa-solid fa-lock-open"></i>

                                        </button>

                                    </form>

                                <?php endif; ?>

                                <!-- View Events -->

                                    <a
                                        href="admin_events.php?organizer=<?= (int) $row['user_id']; ?>"
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm transition inline-flex items-center gap-1"
                                    title="View Events"
                                >

                                    <i class="fa-solid fa-calendar-days"></i>

                                </a>

                            </div>

                        </td>

                    </tr>

                <?php endwhile; ?>

            </tbody>

        </table>

    </div>

    <?php if ($orgs->rowCount() > 0): ?>

        <div class="px-6 py-3 border-t border-slate-200 bg-slate-50/60">

            <p class="text-xs text-slate-500">

                Showing <?= $orgs->rowCount(); ?> organizer(s)

            </p>

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