<?php


require 'db_connect.php';
require 'lang.php';
require 'csrf.php';
require 'totp.php';

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role'])
) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];
$full_name = $_SESSION['full_name'] ?? '';
$first_name = explode(' ', trim($full_name))[0];

$error = '';
$success = '';

/*
| Coerces a DB boolean-ish value to a real PHP bool. Some PDO/pgsql
| configurations return boolean columns as native PHP true/false,
| others return the string 't'/'f' — this handles both so the app
| doesn't silently misread the stored value either way.
*/
function to_bool($value): bool {
    if (is_bool($value)) {
        return $value;
    }
    if ($value === null) {
        return false;
    }
    return in_array(strtolower((string) $value), ['t', 'true', '1', 'y', 'yes'], true);
}

/*
| Flash-style messages carried via query string (used by the 2FA actions
| which redirect after a successful update).
*/
if (isset($_GET['twofa'])) {
    if ($_GET['twofa'] === 'enabled') {
        $success = t('twofa_enabled_success');
    } elseif ($_GET['twofa'] === 'disabled') {
        $success = t('twofa_disabled_success');
    }
}

/*
|--------------------------------------------------------------------------
| Department List
|--------------------------------------------------------------------------
*/

$departments = [
    "BS Computer Science",
    "BS Information Technology",
    "BSOA - Office Administration",
    "BS Education"
];

/*
|--------------------------------------------------------------------------
| Fetch Current User
|--------------------------------------------------------------------------
*/

$result = $pdo->prepare("SELECT *
     FROM users
     WHERE user_id = ?"); $result->execute([$user_id]);

$user = $result->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Process POST Requests
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    |--------------------------------------------------------------------------
    | CSRF Protection
    |--------------------------------------------------------------------------
    |
    | Every POST action on this page uses the same CSRF protection.
    |
    */

    csrf_verify();


    /*
    |--------------------------------------------------------------------------
    | Change Language
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['change_language'])) {

        $new_lang = (
            isset($_POST['lang']) &&
            in_array($_POST['lang'], $supported_langs, true)
        )
            ? $_POST['lang']
            : 'en';

        $_SESSION['lang'] = $new_lang;

        setcookie(
            'lang',
            $new_lang,
            [
                'expires' => time() + (365 * 24 * 60 * 60),
                'path' => '/'
            ]
        );

        header("Location: settings.php");
        exit();
    }


    /*
    |--------------------------------------------------------------------------
    | Change Appearance
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['change_appearance'])) {

        $new_appearance = (
            isset($_POST['appearance']) &&
            in_array($_POST['appearance'], ['light', 'dark', 'system'], true)
        )
            ? $_POST['appearance']
            : 'system';

        $update = $pdo->prepare("UPDATE users
             SET appearance = ?
             WHERE user_id = ?"); $update->execute([$new_appearance, $user_id]);

        if ($update) {

            $_SESSION['appearance'] = $new_appearance;

            setcookie(
                'appearance',
                $new_appearance,
                [
                    'expires' => time() + (365 * 24 * 60 * 60),
                    'path'    => '/',
                    'samesite' => 'Lax'
                ]
            );

            header("Location: settings.php");
            exit();

        } else {

            $error = t('appearance_update_error');
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Toggle Email Notifications
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['toggle_email_notifications'])) {

        $is_ajax = (
            isset($_POST['ajax']) ||
            (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        );

        $current_email_notifications = to_bool($user['email_notifications'] ?? false);
        $new_value = !$current_email_notifications;

        $update_ok = false;
        $debug_info = null;

        try {

            $update = $pdo->prepare("UPDATE users
                 SET email_notifications = ?
                 WHERE user_id = ?");

            $update_ok = $update->execute([
                $new_value ? 1 : 0,
                $user_id
            ]);

            if (!$update_ok) {
                $debug_info = $update->errorInfo();
            }

        } catch (\PDOException $e) {

            $update_ok = false;
            $debug_info = $e->getMessage();
        }

        if ($is_ajax) {

            header('Content-Type: application/json');

            echo json_encode([
                'success'              => $update_ok,
                'email_notifications'  => $new_value,
                'message'              => $update_ok ? null : t('email_notif_update_error'),
                /* TEMP: remove the 'debug' key once the toggle is confirmed working. */
                'debug'                => $update_ok ? null : $debug_info
            ]);

            exit();
        }

        if ($update_ok) {

            header("Location: settings.php");
            exit();

        } else {

            $error = t('email_notif_update_error');
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication (admin only)
    |--------------------------------------------------------------------------
    |
    | 1. enable_2fa_init      -> generate a new secret, hold it in the session
    |                            until the user confirms a valid TOTP code.
    | 2. enable_2fa_confirm   -> verify the code, then persist the secret.
    | 3. enable_2fa_cancel    -> discard a pending secret.
    | 4. disable_2fa          -> verify the current code, then remove the secret.
    |
    */

    if ($role === 'admin' && isset($_POST['enable_2fa_init'])) {

        $_SESSION['twofa_pending_secret'] = totp_generate_secret();

        header("Location: settings.php#twofa");
        exit();
    }

    if ($role === 'admin' && isset($_POST['enable_2fa_confirm'])) {

        $pending_secret = $_SESSION['twofa_pending_secret'] ?? '';

        if ($pending_secret === '') {

            $error = t('twofa_session_expired');

        } else {

            $code = trim($_POST['twofa_code'] ?? '');

            if (!totp_verify($pending_secret, $code)) {

                $error = t('twofa_invalid_code');

            } else {

$update = $pdo->prepare("UPDATE users
                     SET twofa_secret = ?,
                         session_token = NULL
WHERE user_id = ?");
$update->execute([$pending_secret, $user_id]);

                if ($update) {

                    unset($_SESSION['twofa_pending_secret']);

                    $new_token = bin2hex(random_bytes(32));
                    $new_hash = hash('sha256', $new_token);
                    $pdo->prepare("UPDATE users SET session_token = ? WHERE user_id = ?")->execute([$new_hash, $user_id]);
                    $_SESSION['auth_token'] = $new_token;
                    if (isset($_SESSION['rmc_auth'][$role]) && is_array($_SESSION['rmc_auth'][$role])) {
                        $_SESSION['rmc_auth'][$role]['auth_token'] = $new_token;
                    }
                    session_regenerate_id(true);

                    $success = t('twofa_enabled_success');

                    header("Location: settings.php?twofa=enabled");
                    exit();

                } else {

                    $error = t('twofa_enable_error');
                }
            }
        }
    }

    if ($role === 'admin' && isset($_POST['enable_2fa_cancel'])) {

        unset($_SESSION['twofa_pending_secret']);

        header("Location: settings.php#twofa");
        exit();
    }

    if ($role === 'admin' && isset($_POST['disable_2fa'])) {

        $code = trim($_POST['twofa_code'] ?? '');

        if (!totp_verify($user['twofa_secret'] ?? '', $code)) {

            $error = t('twofa_invalid_code');

        } else {

$update = $pdo->prepare("UPDATE users
                 SET twofa_secret = NULL,
                     session_token = NULL
WHERE user_id = ?");
$update->execute([$user_id]);

            if ($update) {

                $new_token = bin2hex(random_bytes(32));
                $new_hash = hash('sha256', $new_token);
                $pdo->prepare("UPDATE users SET session_token = ? WHERE user_id = ?")->execute([$new_hash, $user_id]);
                $_SESSION['auth_token'] = $new_token;
                if (isset($_SESSION['rmc_auth'][$role]) && is_array($_SESSION['rmc_auth'][$role])) {
                    $_SESSION['rmc_auth'][$role]['auth_token'] = $new_token;
                }
                session_regenerate_id(true);

                $success = t('twofa_disabled_success');

                header("Location: settings.php?twofa=disabled");
                exit();

            } else {

                $error = t('twofa_disable_error');
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Update Personal Information (safe fields only)
    |--------------------------------------------------------------------------
    |
    | Only non-sensitive, self-manageable fields are editable here:
    |   - full_name (all roles)
    |   - department (students only)
    |
    | Role, student ID / username, and email address are NOT editable from
    | this form. Changing a verified email requires a secure verification
    | flow (separate from password reset), so it is intentionally excluded.
    |
    */

    if (isset($_POST['update_info'])) {

        $full_name = trim($_POST['full_name'] ?? '');

        /*
        | Only students can change their department.
        */

        $department = (
            $role === 'student'
        )
            ? trim($_POST['department'] ?? '')
            : ($user['department'] ?? '');


        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($full_name === '') {

            $error = t('full_name_empty_msg');

        } elseif (
            $role === 'student' &&
            !in_array($department, $departments, true)
        ) {

            $error = t('select_valid_department');
        }


        /*
        |--------------------------------------------------------------------------
        | Update Account
        |--------------------------------------------------------------------------
        */

        if (empty($error)) {

            if ($role === 'student') {

$update = $pdo->prepare("UPDATE users
                     SET
                         full_name = ?,
                         department = ?
WHERE user_id = ?");

$update->execute([
    $full_name,
    $department,
    $user_id
]);

            } else {

                $update = $pdo->prepare("UPDATE users
                     SET
                        full_name = ?
                     WHERE user_id = ?"); $update->execute([
                        $full_name,
                        $user_id
                    ]);
            }


            if ($update) {

                /*
                |--------------------------------------------------------------------------
                | Update Session Name
                |--------------------------------------------------------------------------
                */

                $_SESSION['full_name'] = $full_name;

                $success = t('personal_info_updated');


                /*
                |--------------------------------------------------------------------------
                | Refresh User Data
                |--------------------------------------------------------------------------
                */

                $result = $pdo->prepare("SELECT *
                     FROM users
                     WHERE user_id = ?"); $result->execute([$user_id]);

                $user = $result->fetch(PDO::FETCH_ASSOC);

            } else {

                $error = t('account_update_error');
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Change Password
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['change_password'])) {

        $current_password =
            $_POST['current_password'] ?? '';

        $new_password =
            $_POST['new_password'] ?? '';

        $confirm_password =
            $_POST['confirm_password'] ?? '';


        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($current_password === '') {

            $error = t('enter_current_password');

        } elseif ($new_password === '') {

            $error = t('enter_new_password');

        } elseif (
            !password_verify(
                $current_password,
                $user['password']
            )
        ) {

            $error = t('current_password_incorrect');

        } elseif (strlen($new_password) < 8) {

            $error = t('new_password_min_msg');

        } elseif ($new_password !== $confirm_password) {

            $error = t('new_passwords_mismatch');

        } elseif ($current_password === $new_password) {

            $error = t('password_same_as_current');
        }


        /*
        |--------------------------------------------------------------------------
        | Update Password
        |--------------------------------------------------------------------------
        */

        if (empty($error)) {

            $hashed_password =
                password_hash(
                    $new_password,
                    PASSWORD_DEFAULT
                );

$update = $pdo->prepare("UPDATE users
                 SET password = ?,
                     session_token = NULL
WHERE user_id = ?");

$update->execute([
    $hashed_password,
    $user_id
]);

            if ($update) {

                $new_token = bin2hex(random_bytes(32));
                $new_hash = hash('sha256', $new_token);

                $pdo->prepare("UPDATE users SET session_token = ? WHERE user_id = ?")->execute([$new_hash, $user_id]);

                $_SESSION['auth_token'] = $new_token;
                if (isset($_SESSION['rmc_auth'][$role]) && is_array($_SESSION['rmc_auth'][$role])) {
                    $_SESSION['rmc_auth'][$role]['auth_token'] = $new_token;
                }
                session_regenerate_id(true);

                $success = t('password_changed_settings');

            } else {

                $error = t('password_change_error');
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Email Notification State
|--------------------------------------------------------------------------
*/

$emailOn = to_bool($user['email_notifications'] ?? false);


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

$page_title  = t('title_settings');
$active_page = 'settings';

?>
<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     SETTINGS HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 flex flex-col sm:flex-row sm:items-center gap-6 mb-8 animate-up"
>

    <div
        class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
    >

        <i class="fa-solid fa-gear text-2xl"></i>

    </div>

    <div class="min-w-0">

        <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
            <?= t('settings'); ?>
        </h2>

        <p class="text-slate-600 mt-1 text-sm sm:text-base">
            <?= t('settings_hero_desc'); ?>
        </p>

    </div>

</div>


<!-- =========================================================
     ALERTS
     ========================================================= -->

<?php if ($error): ?>

    <div
        class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 mb-6 animate-up"
    >

        <i class="fa-solid fa-circle-exclamation mr-2"></i>

        <?= htmlspecialchars($error); ?>

    </div>

<?php endif; ?>

<?php if ($success): ?>

    <div
        class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 mb-6 animate-up"
    >

        <i class="fa-solid fa-circle-check mr-2"></i>

        <?= htmlspecialchars($success); ?>

    </div>

<?php endif; ?>


<!-- =========================================================
     ACCOUNT INFORMATION
     ========================================================= -->

<div
    class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 mb-8 animate-up delay-1"
>

    <div class="flex items-center justify-between gap-4 mb-6">

        <h2 class="text-xl font-bold text-slate-900 flex items-center gap-3">

            <span
                class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center"
            >

                <i class="fa-solid fa-user"></i>

            </span>

            <?= t('account_information'); ?>

        </h2>


        <!-- Profile action menu (⋮) -->

        <div class="relative shrink-0" id="profileMenu">

            <button
                type="button"
                id="profileMenuBtn"
                aria-label="<?= t('edit'); ?>"
                aria-haspopup="menu"
                aria-expanded="false"
                class="w-10 h-10 rounded-xl border border-slate-200 bg-slate-50 hover:bg-rmc-50 hover:border-rmc-200 text-slate-600 hover:text-rmc-800 flex items-center justify-center transition"
            >

                <i class="fa-solid fa-ellipsis-vertical text-lg"></i>

            </button>


            <div
                id="profileMenuDropdown"
                class="hidden absolute right-0 mt-2 w-64 bg-white border border-slate-200 rounded-2xl shadow-xl py-2 z-20"
                role="menu"
            >

                <button
                    type="button"
                    id="profileEditBtn"
                    role="menuitem"
                    class="w-full text-left px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-rmc-50 hover:text-rmc-800 transition flex items-center gap-3"
                >

                    <i class="fa-solid fa-pen-to-square text-rmc-600"></i>

                    <?= t('edit_personal_info'); ?>

                </button>

            </div>

        </div>

    </div>


    <!-- Account Metadata -->

    <div class="flex flex-wrap gap-3 mb-7 text-sm text-slate-600">

        <div class="bg-rmc-50/60 border border-rmc-100 rounded-2xl px-4 py-3">

            <span class="block text-slate-400 text-xs uppercase font-semibold">

                <?= ucfirst(htmlspecialchars($role)); ?> <?= t('since'); ?>

            </span>

            <span class="font-semibold text-slate-800">

                <?= !empty($user['created_at'])
                    ? htmlspecialchars(
                        date(
                            "F j, Y",
                            strtotime($user['created_at'])
                        )
                    )
                    : 'N/A';
                ?>

            </span>

        </div>


        <div class="bg-rmc-50/60 border border-rmc-100 rounded-2xl px-4 py-3">

            <span class="block text-slate-400 text-xs uppercase font-semibold">
                <?= t('role'); ?>
            </span>

            <span class="font-semibold text-slate-800 capitalize">

                <?= htmlspecialchars($role); ?>

            </span>

        </div>


        <?php if (!empty($user['student_id'])): ?>

            <div class="bg-rmc-50/60 border border-rmc-100 rounded-2xl px-4 py-3">

                <span class="block text-slate-400 text-xs uppercase font-semibold">

                    <?= ($role === 'student')
                        ? t('student_id')
                        : t('username');
                    ?>

                </span>

                <span class="font-semibold text-slate-800">

                    <?= htmlspecialchars(
                        $user['student_id']
                    ); ?>

                </span>

            </div>

        <?php endif; ?>

    </div>


    <!-- Profile (read-only) -->

    <p class="text-sm text-slate-500 bg-rmc-50/60 border border-rmc-100 rounded-2xl px-4 py-3 mb-6">
        <i class="fa-solid fa-shield-halved mr-2 text-rmc-600"></i>
        <?= t('profile_readonly_hint'); ?>
    </p>


    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-6 mb-8">

        <div>

            <dt class="text-xs uppercase tracking-wide font-semibold text-slate-400">
                <?= t('full_name'); ?>
            </dt>

            <dd class="mt-1 font-semibold text-slate-800 text-lg">
                <?= htmlspecialchars($user['full_name'] ?? ''); ?>
            </dd>

        </div>


        <div>

            <dt class="text-xs uppercase tracking-wide font-semibold text-slate-400">
                <?= t('email_address'); ?>
            </dt>

            <dd class="mt-1 font-semibold text-slate-800">
                <?= htmlspecialchars($user['email'] ?? '—'); ?>
            </dd>

            <p class="text-xs text-slate-400 mt-1">
                <?= ($role === 'admin')
                    ? htmlspecialchars(t('email_readonly_note_admin'))
                    : htmlspecialchars(t('email_readonly_note'));
                ?>
            </p>

        </div>


        <?php if ($role === 'student'): ?>

            <div>

                <dt class="text-xs uppercase tracking-wide font-semibold text-slate-400">
                    <?= t('department'); ?>
                </dt>

                <dd class="mt-1 font-semibold text-slate-800">
                    <?= htmlspecialchars($user['department'] ?? ''); ?>
                </dd>

            </div>

        <?php endif; ?>

    </dl>


    <!-- Edit Personal Information (inline panel) -->

    <div
        id="editProfilePanel"
        class="hidden border-t border-slate-100 pt-6 animate-up"
    >

        <h3 class="font-bold text-slate-800 mb-4 flex items-center gap-2">

            <i class="fa-solid fa-pen-to-square text-rmc-600"></i>

            <?= t('edit_personal_info'); ?>

        </h3>


        <form method="POST" class="space-y-5">

            <?= csrf_field(); ?>

            <input type="hidden" name="update_info" value="1">


            <div>

                <label for="editFullName" class="font-semibold text-slate-700">
                    <?= t('full_name'); ?>
                </label>

                <input
                    type="text"
                    id="editFullName"
                    name="full_name"
                    required
                    maxlength="150"
                    value="<?= htmlspecialchars(
                        $user['full_name'] ?? ''
                    ); ?>"
                    class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                >

            </div>


            <?php if ($role === 'student'): ?>

                <div>

                    <label for="editDepartment" class="font-semibold text-slate-700">
                        <?= t('department'); ?>
                    </label>

                    <select
                        id="editDepartment"
                        name="department"
                        required
                        class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                    >

                        <?php foreach ($departments as $dept): ?>

                            <option
                                value="<?= htmlspecialchars($dept); ?>"
                                <?= (
                                    trim(
                                        $user['department'] ?? ''
                                    ) === $dept
                                )
                                    ? 'selected'
                                    : '';
                                ?>
                            >

                                <?= htmlspecialchars($dept); ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

            <?php endif; ?>


            <div class="flex flex-wrap items-center gap-3">

                <button
                    type="submit"
                    class="bg-rmc-800 hover:bg-rmc-900 text-white font-bold px-6 py-3 rounded-xl transition inline-flex items-center gap-2"
                >

                    <i class="fa-solid fa-floppy-disk"></i>

                    <?= t('save_changes'); ?>

                </button>

                <button
                    type="button"
                    id="profileCancelBtn"
                    class="bg-white hover:bg-rmc-50 border border-slate-200 text-slate-600 hover:text-rmc-800 font-semibold px-6 py-3 rounded-xl transition"
                >

                    <?= t('cancel'); ?>

                </button>

            </div>

        </form>

    </div>

</div>


<!-- =========================================================
     CHANGE PASSWORD
     ========================================================= -->

<div
    class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 mb-8 animate-up delay-2"
>

    <h2 class="text-xl font-bold text-slate-900 mb-6 flex items-center gap-3">

        <span
            class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center"
        >

            <i class="fa-solid fa-lock"></i>

        </span>

        <?= t('change_password'); ?>

    </h2>


    <form method="POST" class="space-y-5">

        <?= csrf_field(); ?>


        <div>

            <label class="font-semibold text-slate-700">
                <?= t('current_password'); ?>
            </label>

            <input
                type="password"
                name="current_password"
                required
                autocomplete="current-password"
                class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

        </div>


        <div>

            <label class="font-semibold text-slate-700">
                <?= t('new_password'); ?>
            </label>

            <input
                type="password"
                name="new_password"
                required
                minlength="8"
                autocomplete="new-password"
                placeholder="<?= t('password_min_hint'); ?>"
                class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

        </div>


        <div>

            <label class="font-semibold text-slate-700">
                <?= t('confirm_new_password'); ?>
            </label>

            <input
                type="password"
                name="confirm_password"
                required
                minlength="8"
                autocomplete="new-password"
                class="w-full mt-2 border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

        </div>


        <button
            type="submit"
            name="change_password"
            value="1"
            class="bg-rmc-800 hover:bg-rmc-900 text-white font-bold px-6 py-3 rounded-xl transition inline-flex items-center gap-2"
        >

            <i class="fa-solid fa-key"></i>

            <?= t('update_password'); ?>

        </button>

    </form>

</div>


<?php if ($role === 'admin'): ?>

<?php
$twofa_enabled          = !empty($user['twofa_secret']);
$twofa_pending_secret   = $_SESSION['twofa_pending_secret'] ?? '';
$twofa_otpauth_uri      = '';
if ($twofa_pending_secret !== '') {
    $twofa_otpauth_uri = totp_otpauth_uri($user['full_name'], $twofa_pending_secret);
}
?>


<!-- =========================================================
     TWO-FACTOR AUTHENTICATION (admin)
     ========================================================= -->

<div
    id="twofa"
    class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 mb-8 animate-up delay-3"
>

    <h2 class="text-xl font-bold text-slate-900 mb-6 flex items-center gap-3">

        <span
            class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center"
        >

            <i class="fa-solid fa-shield-halved"></i>

        </span>

        <?= t('twofa_title'); ?>

    </h2>


    <?php if ($twofa_pending_secret !== ''): ?>

        <!-- SETUP STEP 1: show QR + secret, ask for confirmation code -->

        <div class="space-y-6">

            <p class="text-slate-600 text-sm leading-relaxed">
                <?= t('twofa_scan_qr'); ?>
            </p>

            <div class="flex flex-col items-center gap-4">

                <div
                    id="twofaQr"
                    class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm"
                ></div>

                <div class="text-center">

                    <p class="text-sm font-semibold text-slate-700">
                        <?= t('twofa_manual_entry'); ?>
                    </p>

                    <code
                        class="inline-block mt-2 px-4 py-2 bg-slate-100 border border-slate-200 rounded-lg font-mono text-rmc-800 tracking-widest select-all"
                    >
                        <?= htmlspecialchars($twofa_pending_secret, ENT_QUOTES, 'UTF-8'); ?>
                    </code>

                </div>

            </div>

            <form
                method="POST"
                class="space-y-5 max-w-md mx-auto w-full"
            >

                <?= csrf_field(); ?>

                <input type="hidden" name="enable_2fa_confirm" value="1">

                <div>

                    <label
                        for="twofaEnableCode"
                        class="font-semibold text-slate-700"
                    >
                        <?= t('twofa_enter_code'); ?>
                    </label>

                    <input
                        type="number"
                        name="twofa_code"
                        id="twofaEnableCode"
                        required
                        inputmode="numeric"
                        min="0"
                        max="999999"
                        maxlength="6"
                        placeholder="000000"
                        class="mt-2 w-full border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition text-center font-bold tracking-[0.4em]"
                    >

                </div>

                <div class="flex flex-wrap gap-3">

                    <button
                        type="submit"
                        class="bg-rmc-800 hover:bg-rmc-900 text-white font-bold px-6 py-3 rounded-xl transition inline-flex items-center gap-2"
                    >
                        <i class="fa-solid fa-check"></i>
                        <?= t('twofa_confirm_enable'); ?>
                    </button>

                    <button
                        type="submit"
                        name="enable_2fa_cancel"
                        value="1"
                        class="bg-white hover:bg-rmc-50 border border-slate-200 text-slate-600 hover:text-rmc-800 font-semibold px-6 py-3 rounded-xl transition"
                    >
                        <?= t('cancel'); ?>
                    </button>

                </div>

            </form>

        </div>

        <script>
            const twofaOtpauthUri = <?= json_encode($twofa_otpauth_uri); ?>;
            document.addEventListener('DOMContentLoaded', function () {
                if (
                    typeof QRCode !== 'undefined' &&
                    document.getElementById('twofaQr')
                ) {
                    new QRCode(document.getElementById('twofaQr'), {
                        text: twofaOtpauthUri,
                        width: 200,
                        height: 200,
                        correctLevel: QRCode.CorrectLevel.M
                    });
                }
            });
        </script>

        <script
            src="js/qrcode.min.js"
            defer
        ></script>


    <?php elseif ($twofa_enabled): ?>

        <!-- STATUS: enabled -->

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">

            <div class="min-w-0">

                <p class="font-semibold text-slate-800 flex items-center gap-2">

                    <span
                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-green-100 text-green-700 text-xs font-bold"
                    >
                        <i class="fa-solid fa-shield-check"></i>
                        <?= t('twofa_enabled'); ?>
                    </span>

                </p>

                <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
                    <?= t('twofa_desc'); ?>
                </p>

            </div>

        </div>

        <div class="mt-6 border-t border-slate-100 pt-6 max-w-md">

            <p class="text-sm font-semibold text-slate-700">
                <?= t('twofa_disable'); ?>
            </p>

            <p class="text-sm text-slate-500 mt-1">
                <?= t('twofa_disable_hint'); ?>
            </p>

            <form method="POST" class="mt-4 flex flex-wrap gap-3 items-end">

                <?= csrf_field(); ?>

                <input type="hidden" name="disable_2fa" value="1">

                <div class="min-w-0 flex-1">

                    <input
                        type="number"
                        name="twofa_code"
                        required
                        inputmode="numeric"
                        min="0"
                        max="999999"
                        maxlength="6"
                        placeholder="000000"
                        class="w-full border border-slate-200 rounded-2xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition text-center font-bold tracking-[0.4em]"
                    >

                </div>

                <button
                    type="submit"
                    class="bg-white hover:bg-red-50 border border-red-200 text-red-600 hover:text-red-700 font-semibold px-6 py-3 rounded-xl transition inline-flex items-center gap-2"
                >
                    <i class="fa-solid fa-lock-open"></i>
                    <?= t('twofa_disable_btn'); ?>
                </button>

            </form>

        </div>


    <?php else: ?>

        <!-- STATUS: not enabled -->

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">

            <div class="min-w-0">

                <p class="font-semibold text-slate-800 flex items-center gap-2">

                    <span
                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-slate-100 text-slate-600 text-xs font-bold"
                    >
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <?= t('twofa_not_enabled'); ?>
                    </span>

                </p>

                <p class="text-sm text-slate-500 mt-1.5 leading-relaxed">
                    <?= t('twofa_desc'); ?>
                </p>

            </div>

            <form method="POST" class="shrink-0">

                <?= csrf_field(); ?>

                <input type="hidden" name="enable_2fa_init" value="1">

                <button
                    type="submit"
                    class="bg-rmc-800 hover:bg-rmc-900 text-white font-bold px-6 py-3 rounded-xl transition inline-flex items-center gap-2"
                >
                    <i class="fa-solid fa-shield-halved"></i>
                    <?= t('twofa_enable'); ?>
                </button>

            </form>

        </div>

    <?php endif; ?>

</div>

<?php endif; ?>


<!-- =========================================================
     PREFERENCES
     ========================================================= -->

<div
    class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 sm:p-8 animate-up delay-3"
>

    <h2 class="text-xl font-bold text-slate-900 mb-6 flex items-center gap-3">

        <span
            class="w-10 h-10 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center"
        >

            <i class="fa-solid fa-sliders"></i>

        </span>

        <?= t('preferences'); ?>

    </h2>


    <!-- APPEARANCE -->

    <div class="flex items-center justify-between gap-4 mb-7">

        <div class="min-w-0">

            <p class="font-semibold text-slate-800">
                <?= t('appearance'); ?>
            </p>

            <p class="text-sm text-slate-500 mt-0.5">
                <?= t('appearance_desc'); ?>
            </p>

        </div>

        <?php
        $current_appearance = (
            isset($user['appearance']) &&
            in_array($user['appearance'], ['light', 'dark', 'system'], true)
        )
            ? $user['appearance']
            : 'system';
        ?>

        <form
            method="POST"
            class="flex gap-1 p-1 rounded-full bg-slate-100 shrink-0"
        >

            <?= csrf_field(); ?>

            <input
                type="hidden"
                name="change_appearance"
                value="1"
            >

            <?php

            $appearance_options = [
                'light'  => t('appearance_light'),
                'dark'   => t('appearance_dark'),
                'system' => t('appearance_system')
            ];

            foreach ($appearance_options as $appearance_value => $appearance_label) {

                $appearance_selected =
                    ($current_appearance === $appearance_value);
            ?>

                <button
                    type="submit"
                    name="appearance"
                    value="<?= $appearance_value; ?>"
                    data-appearance-option="<?= $appearance_value; ?>"
                    class="appearance-option text-sm font-medium px-4 py-1.5 rounded-full transition outline-none focus:ring-2 focus:ring-rmc-300 <?= $appearance_selected
                        ? 'bg-rmc-800 text-white shadow'
                        : 'text-slate-600 hover:text-slate-800';
                    ?>"
                >
                    <?= $appearance_label; ?>
                </button>

            <?php
            }
            ?>

        </form>

    </div>


    <!-- LANGUAGE -->

    <div class="flex items-center justify-between gap-4 pt-6 border-t border-slate-100">

        <div class="min-w-0">

            <p class="font-semibold text-slate-800">
                <?= t('language'); ?>
            </p>

            <p class="text-sm text-slate-500 mt-0.5">
                <?= t('language_desc'); ?>
            </p>

        </div>

        <form method="POST" class="flex gap-2 shrink-0">

            <?= csrf_field(); ?>

            <input
                type="hidden"
                name="change_language"
                value="1"
            >

            <select
                name="lang"
                onchange="this.form.submit()"
                class="border border-slate-200 rounded-xl px-4 py-2 bg-slate-50 focus:ring-2 focus:ring-rmc-300 outline-none transition"
            >

                <option
                    value="en"
                    <?= (
                        ($_SESSION['lang'] ?? 'en') === 'en'
                    )
                        ? 'selected'
                        : '';
                    ?>
                >
                    <?= t('lang_english'); ?>
                </option>

                <option
                    value="fil"
                    <?= (
                        ($_SESSION['lang'] ?? 'en') === 'fil'
                    )
                        ? 'selected'
                        : '';
                    ?>
                >
                    <?= t('lang_filipino'); ?>
                </option>

                <option
                    value="es"
                    <?= (
                        ($_SESSION['lang'] ?? 'en') === 'es'
                    )
                        ? 'selected'
                        : '';
                    ?>
                >
                    <?= t('lang_spanish'); ?>
                </option>

                <option
                    value="fr"
                    <?= (
                        ($_SESSION['lang'] ?? 'en') === 'fr'
                    )
                        ? 'selected'
                        : '';
                    ?>
                >
                    <?= t('lang_french'); ?>
                </option>

                <option
                    value="ja"
                    <?= (
                        ($_SESSION['lang'] ?? 'en') === 'ja'
                    )
                        ? 'selected'
                        : '';
                    ?>
                >
                    <?= t('lang_japanese'); ?>
                </option>

                <option
                    value="ko"
                    <?= (
                        ($_SESSION['lang'] ?? 'en') === 'ko'
                    )
                        ? 'selected'
                        : '';
                    ?>
                >
                    <?= t('lang_korean'); ?>
                </option>

                <option
                    value="zh"
                    <?= (
                        ($_SESSION['lang'] ?? 'en') === 'zh'
                    )
                        ? 'selected'
                        : '';
                    ?>
                >
                    <?= t('lang_chinese'); ?>
                </option>

            </select>

        </form>

    </div>


    <!-- EMAIL NOTIFICATIONS -->

    <div class="flex items-center justify-between gap-4 pt-6 border-t border-slate-100 mt-7">

        <div class="min-w-0">

            <p class="font-semibold text-slate-800">
                <?= t('email_notifications'); ?>
            </p>

            <p class="text-sm text-slate-500 mt-0.5">
                <?= t('email_notifications_desc'); ?>
            </p>

        </div>

        <form method="POST" class="shrink-0" id="emailNotifForm">

            <?= csrf_field(); ?>

            <input
                type="hidden"
                name="toggle_email_notifications"
                value="1"
            >

            <button
                type="submit"
                id="emailNotifToggleBtn"
                aria-label="<?= t('toggle_email_notifications'); ?>"
                class="w-16 h-9 rounded-full relative flex items-center px-1 email-notif-toggle <?= $emailOn ? 'bg-rmc-800' : 'bg-gray-300'; ?>"
            >

                <span
                    id="emailNotifThumb"
                    class="w-7 h-7 bg-white rounded-full shadow email-notif-thumb"
                    style="transform: translateX(<?= $emailOn ? '28px' : '0px'; ?>);"
                ></span>

            </button>

        </form>

    </div>

</div>


<!-- =========================================================
     SETTINGS APPEARANCE OPTIONS
     ========================================================= -->

<style>

.email-notif-toggle {
    transition: background-color .25s ease, box-shadow .25s ease, opacity .2s ease;
}

.email-notif-toggle:active .email-notif-thumb {
    width: 1.9rem;
}

.email-notif-thumb {
    transition: transform .32s cubic-bezier(.34, 1.56, .64, 1), width .18s ease;
}

.email-notif-toggle.is-busy {
    opacity: .65;
    cursor: wait;
}

.email-notif-toggle.is-shaking {
    animation: emailNotifShake .4s ease;
}

@keyframes emailNotifShake {
    0%, 100% { transform: translateX(0); }
    20%, 60% { transform: translateX(-4px); }
    40%, 80% { transform: translateX(4px); }
}

</style>

<script>

function syncSettingsAppearanceOptions(mode) {

    const active = mode || 'system';

    document
        .querySelectorAll('.appearance-option')
        .forEach(function (option) {

            const isActive =
                option.dataset.appearanceOption === active;

            option.classList.toggle('bg-rmc-800', isActive);
            option.classList.toggle('text-white', isActive);
            option.classList.toggle('shadow', isActive);

            option.classList.toggle('text-slate-600', !isActive);
            option.classList.toggle('hover:text-slate-800', !isActive);
        });
}

document.querySelectorAll('.appearance-option').forEach(function (option) {

    option.addEventListener('click', function () {

        const mode = option.dataset.appearanceOption;

        if (typeof window.RMCApplyAppearance === 'function') {
            window.RMCApplyAppearance(mode);
        }

        syncSettingsAppearanceOptions(mode);
    });
});


/* =========================================================
   PROFILE MENU + EDIT PANEL
   ========================================================= */

const profileMenuBtn = document.getElementById('profileMenuBtn');
const profileMenuDropdown = document.getElementById('profileMenuDropdown');
const profileEditBtn = document.getElementById('profileEditBtn');
const profileCancelBtn = document.getElementById('profileCancelBtn');
const editProfilePanel = document.getElementById('editProfilePanel');

function closeProfileMenu() {

    if (!profileMenuDropdown) return;

    profileMenuDropdown.classList.add('hidden');

    if (profileMenuBtn) {
        profileMenuBtn.setAttribute('aria-expanded', 'false');
    }
}

function toggleProfileMenu(event) {

    event.stopPropagation();

    if (!profileMenuDropdown) return;

    const isHidden =
        profileMenuDropdown.classList.contains('hidden');

    profileMenuDropdown.classList.toggle('hidden', !isHidden);

    if (profileMenuBtn) {
        profileMenuBtn.setAttribute(
            'aria-expanded',
            isHidden ? 'true' : 'false'
        );
    }
}

function openEditProfilePanel() {

    closeProfileMenu();

    if (editProfilePanel) {

        editProfilePanel.classList.remove('hidden');

        editProfilePanel.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest'
        });

        const firstField =
            document.getElementById('editFullName');

        if (firstField) firstField.focus();
    }
}

if (profileMenuBtn) {
    profileMenuBtn.addEventListener('click', toggleProfileMenu);
}

if (profileEditBtn) {
    profileEditBtn.addEventListener('click', openEditProfilePanel);
}

if (profileCancelBtn) {
    profileCancelBtn.addEventListener('click', function () {

        if (editProfilePanel) {
            editProfilePanel.classList.add('hidden');
        }
    });
}

document.addEventListener('click', function (event) {

    const menu = document.getElementById('profileMenu');

    if (menu && !menu.contains(event.target)) {
        closeProfileMenu();
    }
});


/* =========================================================
   EMAIL NOTIFICATIONS TOGGLE (animated, AJAX)
   ========================================================= */

(function () {

    const form = document.getElementById('emailNotifForm');
    const btn = document.getElementById('emailNotifToggleBtn');
    const thumb = document.getElementById('emailNotifThumb');

    if (!form || !btn || !thumb) return;

    const confirmDisableMsg = <?= json_encode(t('confirm_disable_email_notifications')); ?>;

    function setVisualState(isOn) {
        btn.classList.toggle('bg-rmc-800', isOn);
        btn.classList.toggle('bg-gray-300', !isOn);
        thumb.style.transform = 'translateX(' + (isOn ? '28px' : '0px') + ')';
    }

    function shake() {
        btn.classList.add('is-shaking');
        setTimeout(function () {
            btn.classList.remove('is-shaking');
        }, 400);
    }

    form.addEventListener('submit', function (event) {

        event.preventDefault();

        if (btn.dataset.busy === '1') return;

        const wasOn = btn.classList.contains('bg-rmc-800');
        const goingOn = !wasOn;

        /* Confirm before turning OFF — no confirmation needed to turn back on. */
        if (wasOn && !goingOn) {
            if (!window.confirm(confirmDisableMsg)) {
                return;
            }
        }

        btn.dataset.busy = '1';
        btn.classList.add('is-busy');

        /* Optimistic UI update -- animate immediately, confirm with the server after. */
        setVisualState(goingOn);

        const formData = new FormData(form);
        formData.append('ajax', '1');

        fetch('settings.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {

                btn.dataset.busy = '0';
                btn.classList.remove('is-busy');

                if (!data.success) {

                    /* Revert the optimistic change and let the user know. */
                    setVisualState(wasOn);
                    shake();

                    console.error('Email notification toggle failed:', data);

                    if (data.debug) {
                        alert(
                            (data.message || 'Update failed') +
                            '\n\nDebug info (temporary, remove once fixed):\n' +
                            JSON.stringify(data.debug, null, 2)
                        );
                    }
                }
            })
            .catch(function (err) {

                btn.dataset.busy = '0';
                btn.classList.remove('is-busy');

                setVisualState(wasOn);
                shake();

                console.error('Email notification toggle request error:', err);
            });
    });

})();

</script>


<?php include 'partials/footer.php'; ?>