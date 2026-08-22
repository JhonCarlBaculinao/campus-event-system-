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
| Create Admin
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['create_admin'])
) {

    csrf_verify();

    $full_name  = trim($_POST['full_name'] ?? '');
    $student_id = trim($_POST['student_id'] ?? '');
    $password   = $_POST['password'] ?? '';
    $department = trim($_POST['department'] ?? '');

    $valid_departments = [
        'BS Computer Science',
        'BS Information Technology',
        'BSOA - Office Administration',
        'BS Education',
        'Analytics',
        'Engineering',
        'Business',
    ];

    if (
        $full_name === '' ||
        $student_id === '' ||
        $password === '' ||
        $department === ''
    ) {

        $error = t('fill_all_fields');

    } elseif (mb_strlen($full_name) < 2) {

        $error = t('valid_full_name_msg');

    } elseif (mb_strlen($student_id) < 3) {

        $error = t('valid_student_id_msg');

    } elseif (!in_array($department, $valid_departments, true)) {

        $error = t('select_valid_department');

    } elseif (strlen($password) < 12) {

        $error = 'Password must be at least 12 characters.';

    } elseif (
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password) ||
        !preg_match('/[^A-Za-z0-9]/', $password)
    ) {

        $error = 'Password must include uppercase, lowercase, number, and special character.';

    } else {

        $check = pg_query_params(
            $conn,
            "SELECT user_id
             FROM users
             WHERE student_id = $1
             LIMIT 1",
            array($student_id)
        );

        if (!$check) {

            $error = 'Unable to verify Student ID. Please try again.';

        } elseif (pg_num_rows($check) > 0) {

            $error = t('student_id_exists_msg');

        } else {

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            if ($hashed_password === false) {

                $error = t('password_hash_error');

            } else {

                $insert = pg_query_params(
                    $conn,
                    "INSERT INTO users
                         (full_name, student_id, password, department, role, status, email_notifications, appearance, email_verified, failed_attempts, created_at)
                     VALUES
                         ($1, $2, $3, $4, 'admin', 'active', 't', 'system', 'f', 0, NOW())
                     RETURNING user_id",
                    array(
                        $full_name,
                        $student_id,
                        $hashed_password,
                        $department
                    )
                );

                if ($insert) {

                    $row = pg_fetch_assoc($insert);
                    $new_user_id = (int) $row['user_id'];

                    pg_query_params(
                        $conn,
                        "INSERT INTO audit_log (admin_user_id, action, target_user_id, details) VALUES ($1, $2, $3, $4)",
                        array(
                            $admin_id,
                            'create_admin',
                            $new_user_id,
                            'Admin account created: ' . $full_name . ' (' . $student_id . ')'
                        )
                    );

                    header("Location: admin_manage_admins.php?msg=created");
                    exit();

                } else {

                    $error = 'Unable to create admin account. Please try again.';
                }
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Toggle Admin Status
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

        $count_result = pg_query_params(
            $conn,
            "SELECT COUNT(*) AS cnt
             FROM users
             WHERE role = 'admin' AND status = 'active'",
            array()
        );

        $admin_count = (int) pg_fetch_result($count_result, 0, 'cnt');

        $current_result = pg_query_params(
            $conn,
            "SELECT status
             FROM users
             WHERE user_id = $1 AND role = 'admin'",
            array($target_id)
        );

        $current = pg_fetch_assoc($current_result);

        if (!$current) {

            $error = t('user_account_not_found');

        } elseif (
            $current['status'] === 'active' &&
            $admin_count <= 1
        ) {

            $error = 'Cannot deactivate the last active administrator.';

        } else {

            $new_status =
                ($current['status'] === 'active')
                ? 'deactivated'
                : 'active';

            $update_result = pg_query_params(
                $conn,
                "UPDATE users
                 SET status = $1
                 WHERE user_id = $2 AND role = 'admin'",
                array(
                    $new_status,
                    $target_id
                )
            );

            if ($update_result) {

                pg_query_params(
                    $conn,
                    "INSERT INTO notifications (user_id, message, type) VALUES ($1, $2, 'admin_action')",
                    array(
                        $target_id,
                        $new_status === 'active'
                            ? 'Your admin account has been activated by an administrator.'
                            : 'Your admin account has been deactivated by an administrator.'
                    )
                );

                pg_query_params(
                    $conn,
                    "INSERT INTO audit_log (admin_user_id, action, target_user_id, details) VALUES ($1, $2, $3, $4)",
                    array(
                        $admin_id,
                        'toggle_status_' . $new_status,
                        $target_id,
                        'Admin status changed to ' . $new_status
                    )
                );

                header("Location: admin_manage_admins.php?msg=updated");
                exit();

            } else {

                $error = t('user_status_update_error');
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Unlock Admin
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['unlock_admin_id'])
) {

    csrf_verify();

    $unlock_id = (int) $_POST['unlock_admin_id'];

    if ($unlock_id <= 0) {

        $error = t('invalid_user_id_msg');

    } elseif ($unlock_id === $admin_id) {

        $error = t('cannot_modify_own_status');

    } else {

        pg_query_params(
            $conn,
            "UPDATE users
             SET failed_attempts = 0, locked_until = NULL
             WHERE user_id = $1 AND role = 'admin'",
            array($unlock_id)
        );

        pg_query_params(
            $conn,
            "INSERT INTO notifications (user_id, message, type) VALUES ($1, 'Your admin account has been unlocked by an administrator.', 'admin_action')",
            array($unlock_id)
        );

        pg_query_params(
            $conn,
            "INSERT INTO audit_log (admin_user_id, action, target_user_id, details) VALUES ($1, 'unlock', $2, 'Admin account unlocked')",
            array($admin_id, $unlock_id)
        );

        header("Location: admin_manage_admins.php?msg=unlocked");
        exit();
    }
}


/*
|--------------------------------------------------------------------------
| Flash Messages
|--------------------------------------------------------------------------
*/

$msg = $_GET['msg'] ?? '';

if ($msg === 'created') {
    $success = 'Admin account created successfully.';
} elseif ($msg === 'updated') {
    $success = 'Admin status updated successfully.';
} elseif ($msg === 'unlocked') {
    $success = t('user_unlocked') ?: 'Account has been unlocked.';
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
| Get Admins
|--------------------------------------------------------------------------
*/

if (!empty($search)) {

    $admins = pg_query_params(
        $conn,

        "SELECT *,
                CASE WHEN locked_until IS NOT NULL AND locked_until > NOW()
                     THEN EXTRACT(EPOCH FROM (locked_until - NOW()))::int
                     ELSE 0 END AS lockout_secs
         FROM users
         WHERE role = 'admin'
           AND (full_name ILIKE $1
                OR student_id ILIKE $1)
         ORDER BY full_name",

        array('%' . $search . '%')
    );

} else {

    $admins = pg_query(
        $conn,
        "SELECT *,
                CASE WHEN locked_until IS NOT NULL AND locked_until > NOW()
                     THEN EXTRACT(EPOCH FROM (locked_until - NOW()))::int
                     ELSE 0 END AS lockout_secs
         FROM users
         WHERE role = 'admin'
         ORDER BY full_name"
    );
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
$page_title  = 'Manage Admins — RMC Events';
$active_page = 'admin_manage_admins';

$valid_departments = [
    'BS Computer Science',
    'BS Information Technology',
    'BSOA - Office Administration',
    'BS Education',
    'Analytics',
    'Engineering',
    'Business',
];

?>
<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     MANAGE ADMINS HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up"
>

    <div class="flex items-center gap-4 sm:gap-5">

        <div
            class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
        >

            <i class="fa-solid fa-user-shield text-2xl"></i>

        </div>

        <div>

            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                Manage Admins
            </h2>

            <p class="text-slate-600 mt-1 text-sm sm:text-base">
                Create and manage administrator accounts
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
     CREATE ADMIN FORM
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm overflow-hidden animate-up delay-2">

    <div class="px-6 py-5 border-b border-slate-200">

        <h2 class="text-xl font-bold text-slate-900">
            Create New Admin
        </h2>

        <p class="text-slate-500 text-sm mt-1">
            Add a new administrator account to the system
        </p>

    </div>

    <div class="p-6">

        <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">

            <?= csrf_field(); ?>

            <input type="hidden" name="create_admin" value="1">


            <!-- Full Name -->

            <div>

                <label
                    for="full_name"
                    class="block text-sm font-semibold text-slate-700 mb-1.5"
                >
                    <?= t('full_name'); ?>
                </label>

                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    value="<?= htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                    placeholder="<?= t('placeholder_full_name'); ?>"
                    required
                    class="border border-slate-200 rounded-xl px-4 py-2.5 w-full bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                >

            </div>


            <!-- Student ID -->

            <div>

                <label
                    for="student_id"
                    class="block text-sm font-semibold text-slate-700 mb-1.5"
                >
                    <?= t('student_id'); ?>
                </label>

                <input
                    type="text"
                    id="student_id"
                    name="student_id"
                    value="<?= htmlspecialchars($_POST['student_id'] ?? ''); ?>"
                    placeholder="<?= t('placeholder_student_id_example'); ?>"
                    required
                    class="border border-slate-200 rounded-xl px-4 py-2.5 w-full bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                >

            </div>


            <!-- Password -->

            <div>

                <label
                    for="password"
                    class="block text-sm font-semibold text-slate-700 mb-1.5"
                >
                    <?= t('password'); ?>
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Min 12 characters"
                    required
                    minlength="12"
                    class="border border-slate-200 rounded-xl px-4 py-2.5 w-full bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                >

                <p class="text-xs text-slate-400 mt-1">
                    Must include uppercase, lowercase, number, and special character.
                </p>

            </div>


            <!-- Department -->

            <div>

                <label
                    for="department"
                    class="block text-sm font-semibold text-slate-700 mb-1.5"
                >
                    <?= t('department'); ?>
                </label>

                <select
                    id="department"
                    name="department"
                    required
                    class="border border-slate-200 rounded-xl px-4 py-2.5 w-full bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                >

                    <option value="">
                        <?= t('select_department'); ?>
                    </option>

                    <?php foreach ($valid_departments as $dept): ?>

                        <option
                            value="<?= htmlspecialchars($dept); ?>"
                            <?= (($_POST['department'] ?? '') === $dept) ? 'selected' : ''; ?>
                        >
                            <?= htmlspecialchars($dept); ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- Submit -->

            <div class="md:col-span-2">

                <button
                    type="submit"
                    class="bg-rmc-800 hover:bg-rmc-900 text-white px-6 py-2.5 rounded-xl text-sm font-semibold transition inline-flex items-center gap-2"
                >

                    <i class="fa-solid fa-plus"></i>

                    Create Admin

                </button>

            </div>

        </form>

    </div>

</div>


<!-- =========================================================
     ADMIN LIST
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm overflow-hidden animate-up delay-3 mt-8">

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 px-6 py-5 border-b border-slate-200">

        <div>

            <h2 class="text-xl font-bold text-slate-900">
                Administrator Accounts
            </h2>

            <p class="text-slate-500 text-sm mt-1">
                All administrator accounts in the system
            </p>

        </div>


        <form method="GET" class="flex gap-2">

            <input
                type="text"
                name="search"
                value="<?= htmlspecialchars($search); ?>"
                placeholder="Search by name or ID..."
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
                    href="admin_manage_admins.php"
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
                        <?= t('lockout'); ?>
                    </th>

                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">
                        <?= t('created_at'); ?>
                    </th>

                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">
                        <?= t('actions'); ?>
                    </th>

                </tr>

            </thead>

            <tbody>

                <?php if (pg_num_rows($admins) === 0): ?>

                    <tr>

                        <td colspan="7" class="px-6 py-16 text-center">

                            <div class="flex flex-col items-center">

                                <div class="w-16 h-16 rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-4">

                                    <i class="fa-solid fa-user-shield text-3xl"></i>

                                </div>

                                <p class="font-semibold text-slate-700">

                                    No administrators found
                                    <?php if (!empty($search)): ?>
                                        matching "<?= htmlspecialchars($search); ?>"
                                    <?php endif; ?>

                                </p>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>


                <?php while ($row = pg_fetch_assoc($admins)): ?>

                    <?php
                    $is_self = ((int) $row['user_id'] === $admin_id);
                    $is_locked = (($row['lockout_secs'] ?? 0) > 0);
                    $remaining = $is_locked ? (int) ceil(((int) $row['lockout_secs']) / 60) : 0;
                    ?>

                    <tr class="border-b border-slate-100 hover:bg-rmc-50/40 transition">

                        <td class="px-6 py-5 font-semibold text-slate-800">

                            <?= htmlspecialchars($row['full_name']); ?>

                            <?php if ($is_self): ?>

                                <span class="ml-1.5 text-xs text-rmc-600 font-semibold">(You)</span>

                            <?php endif; ?>

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

                            <?php else: ?>

                                <span class="bg-slate-200 text-slate-700 px-3 py-1 rounded-full text-xs font-semibold">

                                    <?= t('deactivated'); ?>

                                </span>

                            <?php endif; ?>

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

                        <td class="px-6 py-5 text-slate-500 text-sm text-center">

                            <?= date(
                                'M d, Y',
                                strtotime($row['created_at'] ?? 'now')
                            ); ?>

                        </td>

                        <td class="px-6 py-5">

                            <div class="flex justify-center gap-2">

                                <!-- Toggle Status -->

                                <?php if (!$is_self): ?>

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
                                                onclick="openConfirmModal({form: this.closest('form'), title: <?= json_encode(t('deactivate_user')) ?>, message: <?= json_encode(t('deactivate_user_confirm')) ?>, itemName: <?= json_encode(htmlspecialchars($row['full_name'], ENT_QUOTES)) ?>, itemLabel: <?= json_encode(t('administrator')) ?>, actionText: <?= json_encode(t('deactivate_user')) ?>, color: 'red', icon: 'fa-solid fa-user-slash'});"
                                            >

                                                <i class="fa-solid fa-user-slash"></i>

                                            </button>

                                        <?php else: ?>

                                            <button
                                                type="button"
                                                class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-sm transition"
                                                title="<?= t('activate_user'); ?>"
                                                onclick="openConfirmModal({form: this.closest('form'), title: <?= json_encode(t('activate_user')) ?>, message: <?= json_encode(t('activate_user_confirm')) ?>, itemName: <?= json_encode(htmlspecialchars($row['full_name'], ENT_QUOTES)) ?>, itemLabel: <?= json_encode(t('administrator')) ?>, actionText: <?= json_encode(t('activate_user')) ?>, color: 'emerald', icon: 'fa-solid fa-user-check'});"
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

                                <!-- Unlock -->

                                <?php if ($is_locked && !$is_self): ?>

                                    <form method="POST" class="inline" data-action-form="<?= (int) $row['user_id']; ?>-unlock">

                                        <?= csrf_field(); ?>

                                        <input type="hidden" name="unlock_admin_id" value="<?= (int) $row['user_id']; ?>">

                                        <button
                                            type="button"
                                            class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-2 rounded-xl text-sm transition"
                                            title="Unlock account"
                                            onclick="openConfirmModal({form: this.closest('form'), title: <?= json_encode(t('unlock_account') ?: 'Unlock Account') ?>, message: <?= json_encode(t('unlock_account_confirm') ?: 'This will reset the failed login counter and unlock this account.') ?>, itemName: <?= json_encode(htmlspecialchars($row['full_name'], ENT_QUOTES)) ?>, itemLabel: <?= json_encode(t('administrator')) ?>, actionText: <?= json_encode(t('unlock_account') ?: 'Unlock Account') ?>, color: 'amber', icon: 'fa-solid fa-lock-open'});"
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
