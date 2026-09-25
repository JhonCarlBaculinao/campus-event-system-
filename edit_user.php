<?php


include 'db_connect.php';
require 'lang.php';
require 'csrf.php';
require_once 'notifications_helper.php';

/*
|--------------------------------------------------------------------------
| Admin Access Protection
|--------------------------------------------------------------------------
*/

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
| Validate Target User ID
|--------------------------------------------------------------------------
*/

$target_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($target_id <= 0) {
    die("Invalid user ID.");
}

/*
|--------------------------------------------------------------------------
| Department List
|--------------------------------------------------------------------------
*/

$departments = rmc_departments();

/*
|--------------------------------------------------------------------------
| Allowed Roles
|--------------------------------------------------------------------------
*/

$allowed_roles = [
    'student',
    'organizer',
    'admin'
];

/*
|--------------------------------------------------------------------------
| Process Update
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    |--------------------------------------------------------------------------
    | CSRF Protection
    |--------------------------------------------------------------------------
    */

    csrf_verify();

    /*
    |--------------------------------------------------------------------------
    | Get Submitted Data
    |--------------------------------------------------------------------------
    */

    $full_name = trim($_POST['full_name'] ?? '');
    $student_id = trim($_POST['student_id'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $new_password = $_POST['new_password'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | Basic Validation
    |--------------------------------------------------------------------------
    */

    if ($full_name === '') {

        $error = t('full_name_empty_msg');

    } elseif ($student_id === '') {

        $error = t('username_empty_msg');

    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = t('valid_email_msg');

    } elseif (!in_array($role, $allowed_roles, true)) {

        $error = t('invalid_role_selected');

    } elseif ($role === 'student' && $department === '') {

        $error = t('department_required_student');

    } elseif (!empty($new_password) && rmc_password_policy_error($new_password) !== null) {

        $error = rmc_password_policy_message();

    }

    /*
    |--------------------------------------------------------------------------
    | Prevent Admin From Changing Their Own Role
    |--------------------------------------------------------------------------
    */

    if (
        empty($error) &&
        $target_id === $admin_id
    ) {

        $current_admin_result = $pdo->prepare("SELECT role FROM users WHERE user_id = ?"); $current_admin_result->execute([$admin_id]);

        $current_admin = $current_admin_result->fetch(PDO::FETCH_ASSOC);

        if ($current_admin && $role !== $current_admin['role']) {

            $error = t('cannot_change_own_role');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Check Student ID / Username Uniqueness
    |--------------------------------------------------------------------------
    */

    if (empty($error)) {

        $check_student = $pdo->prepare("SELECT user_id
             FROM users
             WHERE student_id = ?
             AND user_id != ?"); $check_student->execute([
                $student_id,
                $target_id
            ]);

        if ($check_student->rowCount() > 0) {

            $error = t('username_in_use_msg');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Check Email Uniqueness
    |--------------------------------------------------------------------------
    */

    if (empty($error) && $email !== '') {

        $check_email = $pdo->prepare("SELECT user_id
             FROM users
             WHERE LOWER(email) = LOWER(?)
             AND user_id != ?"); $check_email->execute([
                $email,
                $target_id
            ]);

        if ($check_email->rowCount() > 0) {

            $error = t('email_in_use_msg');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Make Sure Target User Exists
    |--------------------------------------------------------------------------
    */

    if (empty($error)) {

        $target_check = $pdo->prepare("SELECT user_id
             FROM users
             WHERE user_id = ?"); $target_check->execute([$target_id]);

        if ($target_check->rowCount() === 0) {

            $error = t('user_account_missing');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update User
    |--------------------------------------------------------------------------
    */

    if (empty($error)) {

        if (!empty($new_password)) {

            $hashed_password = password_hash(
                $new_password,
                PASSWORD_DEFAULT
            );

            $update_result = $pdo->prepare("UPDATE users
                 SET
                    full_name = ?,
                    student_id = ?,
                    department = ?,
                    email = ?,
                    role = ?,
                    password = ?,
                    session_token = NULL
                 WHERE user_id = ?");

            $update_result->execute([
                    $full_name,
                    $student_id,
                    $department,
                    $email !== '' ? $email : null,
                    $role,
                    $hashed_password,
                    $target_id
                ]);

        } else {

            $update_result = $pdo->prepare("UPDATE users
                 SET
                    full_name = ?,
                    student_id = ?,
                    department = ?,
                    email = ?,
                    role = ?
                 WHERE user_id = ?");

            $update_result->execute([
                    $full_name,
                    $student_id,
                    $department,
                    $email !== '' ? $email : null,
                    $role,
                    $target_id
                ]);
        }

        if ($update_result) {

            /*
            |--------------------------------------------------------------------------
            | If Editing Current Admin's Name
            |--------------------------------------------------------------------------
            */

            if ($target_id === $admin_id) {

                $_SESSION['full_name'] = $full_name;
                $self_token = bin2hex(random_bytes(32));
                $pdo->prepare("UPDATE users SET session_token = ? WHERE user_id = ?")
                    ->execute([hash('sha256', $self_token), $admin_id]);
                $_SESSION['auth_token'] = $self_token;
                if (isset($_SESSION['rmc_auth']['admin']) && is_array($_SESSION['rmc_auth']['admin'])) {
                    $_SESSION['rmc_auth']['admin']['auth_token'] = $self_token;
                    $_SESSION['rmc_auth']['admin']['full_name'] = $full_name;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Notify the affected user (website + forced email) that their
            | account info and/or password was changed by an administrator.
            | Skip notifying if the admin is editing their own account.
            |--------------------------------------------------------------------------
            */

            if ($target_id !== $admin_id) {

                if (!empty($new_password)) {

                    notify_user(
                        $pdo,
                        $target_id,
                        'An administrator has changed the password on your account. If you did not request this, please contact the school administrator immediately.',
                        'admin_action',
                        'Regis Marie College - Your Account Password Was Changed',
                        null,
                        true
                    );

                } else {

                    notify_user(
                        $pdo,
                        $target_id,
                        'An administrator has updated your account information.',
                        'admin_action',
                        'Regis Marie College - Your Account Information Was Updated',
                        null,
                        true
                    );
                }
            }

            header("Location: admin_users.php?updated=1");
            exit();

        } else {

            $error = t('user_update_error');
        }
    }
}

/*
|--------------------------------------------------------------------------
| Fetch User
|--------------------------------------------------------------------------
*/

$result = $pdo->prepare("SELECT *
     FROM users
     WHERE user_id = ?"); $result->execute([$target_id]);

$user = $result->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("User account not found.");
}


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

$recent_notifications = $pdo->prepare("SELECT notification_id, type, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5"); $recent_notifications->execute([$admin_id]);

$role_label  = 'Administrator';
$page_title  = t('title_edit_user');
$active_page = '';

?>
<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     EDIT USER HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up"
>

    <div class="flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-5">

        <div
            class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
        >

            <i class="fa-solid fa-user-pen text-2xl"></i>

        </div>

        <div class="flex-1 min-w-0">

            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                <?= t('edit_user'); ?>
            </h2>

            <p class="text-slate-600 mt-1 text-sm sm:text-base">
                <?= t('edit_user_hero_desc'); ?>
                <strong class="text-rmc-800"><?= htmlspecialchars($user['full_name'] ?? ''); ?></strong>.
            </p>

        </div>

        <a
                href="admin_users.php"
            class="inline-flex items-center gap-2 bg-white hover:bg-rmc-50 border border-rmc-200 text-rmc-800 px-5 py-3 rounded-xl font-semibold transition self-start sm:self-auto"
        >

            <i class="fa-solid fa-arrow-left"></i>

            <?= t('back_to_users'); ?>

        </a>

    </div>

</div>


<!-- =========================================================
     EDIT USER FORM
     ========================================================= -->

<div class="max-w-3xl bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-10 animate-up delay-1">

    <?php if ($error): ?>

        <div class="mb-8 bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4">

            <i class="fa-solid fa-circle-exclamation mr-2"></i>

            <?= htmlspecialchars($error); ?>

        </div>

    <?php endif; ?>


    <form method="POST" class="space-y-6">

        <?= csrf_field(); ?>


        <!-- FULL NAME -->

        <div>

            <label for="full_name" class="block text-sm font-semibold text-slate-700">
                <?= t('full_name'); ?>
            </label>

            <input
                id="full_name"
                type="text"
                name="full_name"
                required
                maxlength="150"
                value="<?= htmlspecialchars($user['full_name'] ?? ''); ?>"
                class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

        </div>


        <!-- STUDENT ID -->

        <div>

            <label for="student_id" class="block text-sm font-semibold text-slate-700">
                <?= t('student_id_username'); ?>
            </label>

            <input
                id="student_id"
                type="text"
                name="student_id"
                required
                maxlength="100"
                value="<?= htmlspecialchars($user['student_id'] ?? ''); ?>"
                class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

        </div>


        <!-- EMAIL -->

        <div>

            <label for="email" class="block text-sm font-semibold text-slate-700">
                <?= t('email_address'); ?>
            </label>

            <input
                id="email"
                type="email"
                name="email"
                maxlength="255"
                value="<?= htmlspecialchars($user['email'] ?? ''); ?>"
                placeholder="example@gmail.com"
                class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

        </div>


        <!-- DEPARTMENT -->

        <div>

            <label for="department" class="block text-sm font-semibold text-slate-700">
                <?= t('department_label'); ?>
            </label>

            <select
                id="department"
                name="department"
                required
                class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

                <option value="">
                    <?= t('select_department'); ?>
                </option>

                <?php foreach ($departments as $dept): ?>

                    <option
                        value="<?= htmlspecialchars($dept); ?>"
                        <?= (trim($user['department'] ?? '') === $dept) ? 'selected' : ''; ?>
                    >

                        <?= htmlspecialchars($dept); ?>

                    </option>

                <?php endforeach; ?>


                <?php

                /*
                |--------------------------------------------------------------------------
                | Preserve Existing Unrecognized Department
                |--------------------------------------------------------------------------
                */

                if (
                    !empty($user['department']) &&
                    !in_array(
                        trim($user['department']),
                        $departments,
                        true
                    )
                ):
                ?>

                    <option
                        value="<?= htmlspecialchars($user['department']); ?>"
                        selected
                    >

                        <?= htmlspecialchars($user['department']); ?>
                        <?= t('unrecognized_department'); ?>

                    </option>

                <?php endif; ?>

            </select>

        </div>


        <!-- ROLE -->

        <div>

            <label for="role" class="block text-sm font-semibold text-slate-700">
                <?= t('account_role'); ?>
            </label>

            <select
                id="role"
                name="role"
                required
                class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                <?= ($target_id === $admin_id) ? 'disabled' : ''; ?>
            >

                <option value="student" <?= ($user['role'] === 'student') ? 'selected' : ''; ?>>

                    <i class="fa-solid fa-graduation-cap"></i>
                    <?= t('student'); ?>

                </option>

                <option value="organizer" <?= ($user['role'] === 'organizer') ? 'selected' : ''; ?>>

                    <i class="fa-solid fa-calendar-day"></i>
                    <?= t('organizer'); ?>

                </option>

                <option value="admin" <?= ($user['role'] === 'admin') ? 'selected' : ''; ?>>

                    <i class="fa-solid fa-user-shield"></i>
                    <?= t('administrator'); ?>

                </option>

            </select>


            <?php if ($target_id === $admin_id): ?>

                <!-- Disabled select does not submit its value -->

                <input
                    type="hidden"
                    name="role"
                    value="admin"
                >

                <p class="text-sm text-rmc-800 mt-2">

                    <i class="fa-solid fa-shield-halved mr-1"></i>

                    <?= t('cannot_change_own_role'); ?>

                </p>

            <?php endif; ?>

        </div>


        <!-- PASSWORD -->

        <div>

            <label for="new_password" class="block text-sm font-semibold text-slate-700">
                <?= t('new_password'); ?>
            </label>

            <input
                id="new_password"
                type="password"
                name="new_password"
                minlength="8"
                pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}"
                title="Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one special symbol."
                autocomplete="new-password"
                placeholder="<?= t('leave_blank_keep_password'); ?>"
                class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

            <p class="text-sm text-slate-500 mt-2">

                <?= t('leave_blank_desc'); ?>

            </p>

            <p class="text-sm text-slate-500 mt-1">

                <?= htmlspecialchars(rmc_password_policy_message(), ENT_QUOTES, 'UTF-8'); ?>

            </p>

        </div>


        <!-- BUTTONS -->

        <div class="flex flex-col sm:flex-row gap-4 pt-3">

            <a
                    href="admin_users.php"
                class="sm:w-1/2 text-center py-3 rounded-xl border border-slate-200 font-semibold text-slate-600 hover:bg-rmc-50 transition"
            >

                <i class="fa-solid fa-arrow-left mr-2"></i>

                <?= t('back'); ?>

            </a>

            <button
                type="submit"
                class="sm:w-1/2 py-3 rounded-xl bg-rmc-800 hover:bg-rmc-900 text-white font-bold shadow-lg transition"
            >

                <i class="fa-solid fa-floppy-disk mr-2"></i>

                <?= t('save_changes'); ?>

            </button>

        </div>

    </form>

</div>


<?php include 'partials/footer.php'; ?>