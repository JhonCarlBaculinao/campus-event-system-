<?php
session_start();

include 'db_connect.php';
require 'csrf.php';
require 'lang.php';
require 'totp.php';

function rmc_is_role_allowed($conn, $role) {
    if ($role === 'admin') return true;
    $key = $role . '_access';
    $res = pg_query_params($conn, "SELECT setting_value FROM system_settings WHERE setting_key = $1", array($key));
    if ($res && pg_num_rows($res) > 0) {
        return pg_fetch_result($res, 0, 0) === '1';
    }
    return true; // default: allowed
}

/*
|--------------------------------------------------------------------------
| LOGIN PROCESS
|--------------------------------------------------------------------------
*/

$error = '';

/*
| The login page also re-opens when a 2FA attempt limit was reached,
| so surface the lockout message here.
*/
if (isset($_GET['2fa']) && $_GET['2fa'] === 'locked') {
    $error = t('twofa_too_many_attempts');
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    |--------------------------------------------------------------------------
    | CSRF PROTECTION
    |--------------------------------------------------------------------------
    */
    csrf_verify();

    /*
    |--------------------------------------------------------------------------
    | Get and sanitize input (needed before rate-limit key selection)
    |--------------------------------------------------------------------------
    */
    $login = trim($_POST['student_id'] ?? '');
    $password = $_POST['password'] ?? '';
    $selected_role = trim($_POST['role'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | RATE LIMITING — role-aware, only counts FAILED attempts
    |--------------------------------------------------------------------------
    | Normal users: 5 failures per 15 min per IP
    | Admin:        15 failures per 15 min per IP (generous for sysadmin work)
    |
    | The counter is ONLY incremented when password_verify() fails.
    | A successful login clears the counter immediately.
    |--------------------------------------------------------------------------
    */
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '0.0.0.0';
    $client_ip = trim(explode(',', $client_ip)[0]);

    $is_admin_attempt = ($selected_role === 'admin');
    $rl_max   = $is_admin_attempt ? 15 : 5;
    $rl_key   = $is_admin_attempt ? "login_fail_admin:{$client_ip}" : "login_fail:{$client_ip}";
    $rl_window = 900;

    if (rmc_rate_is_blocked($conn, $rl_key, $rl_max, $rl_window)) {
        $remaining = rmc_rate_remaining($conn, $rl_key, $rl_window);
        $minutes   = (int) ceil($remaining / 60);
        $error = sprintf(
            t('too_many_attempts_cooldown') ?: 'Too many failed attempts. Please try again in %d minute(s).',
            $minutes
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validate selected role
    |--------------------------------------------------------------------------
    */
    $allowed_roles = ['student', 'organizer', 'admin'];

    if ($error !== '') {

        /* rate limit already set error — do nothing */

    } elseif (!in_array($selected_role, $allowed_roles, true)) {

        $error = t('invalid_role_selected');

    } elseif ($login === '' || $password === '') {

        $error = t('enter_credentials');

    } elseif (!rmc_is_role_allowed($conn, $selected_role)) {

        $role_label = ucfirst($selected_role);
        $error = $role_label . ' access is currently disabled by the administrator. Please try again later.';

    } else {

        /*
        |--------------------------------------------------------------------------
        | Find user by Student ID / Username and Role
        |--------------------------------------------------------------------------
        |
        | We intentionally check the account WITHOUT filtering status first.
        | This allows us to tell the user if the account has been deactivated.
        |
        */

        $query = pg_query_params(
            $conn,
            "SELECT *
             FROM users
             WHERE student_id = $1
             AND role = $2
             LIMIT 1",
            [$login, $selected_role]
        );

        /*
        |--------------------------------------------------------------------------
        | Check database query
        |--------------------------------------------------------------------------
        */
        if ($query === false) {

            error_log("Login database query failed: " . pg_last_error($conn));

            $error = t('login_process_error');

        } else {

            $user = pg_fetch_assoc($query);

            /*
            |--------------------------------------------------------------------------
            | User does not exist (for the selected role tab)
            |--------------------------------------------------------------------------
            |
            | The login is scoped to BOTH student_id and role, so an Organizer or
            | Admin who signs in while the default "Student" tab is active gets no
            | row here. That is almost always a forgotten role tab — check whether
            | the ID belongs to a different role and tell the user which tab to
            | use. Security is unchanged: access still requires the correct
            | (student_id, role, password) triple below.
            |
            */
            if (!$user) {

                $role_name = ucfirst($selected_role);

                $any_user = pg_query_params(
                    $conn,
                    "SELECT role
                     FROM users
                     WHERE student_id = $1
                     LIMIT 1",
                    [$login]
                );

                $actual_role =
                    ($any_user !== false && pg_num_rows($any_user) > 0)
                        ? pg_fetch_result($any_user, 0, 0)
                        : null;

                if ($actual_role !== null && $actual_role !== $selected_role) {

                    $role_label = t($actual_role);

                    $error = sprintf(
                        t('wrong_role_hint'),
                        ucfirst($role_label),
                        $role_label
                    );

                } else {

                    $error = sprintf(t('invalid_credentials'), $role_name);
                }

            }

            /*
            |--------------------------------------------------------------------------
            | Account is deactivated
            |--------------------------------------------------------------------------
            */
            elseif (strtolower($user['status']) !== 'active') {

                $error = t('account_deactivated_msg');

            }

            /*
            |--------------------------------------------------------------------------
            | Account is locked (per-user lockout)
            |--------------------------------------------------------------------------
            */
            elseif ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {

                $remaining_q = pg_query_params(
                    $conn,
                    "SELECT EXTRACT(EPOCH FROM (locked_until - NOW()))::int AS secs FROM users WHERE user_id = $1",
                    [(int) $user['user_id']]
                );
                $secs = $remaining_q ? (int) pg_fetch_result($remaining_q, 0, 'secs') : 0;
                $minutes = max(1, (int) ceil($secs / 60));
                $error = sprintf(
                    t('too_many_attempts_cooldown') ?: 'Account temporarily locked. Try again in %d minute(s).',
                    $minutes
                );

            }

            /*
            |--------------------------------------------------------------------------
            | Account is active - verify password
            |--------------------------------------------------------------------------
            */
            elseif (!password_verify($password, $user['password'])) {

                $role_name = ucfirst($selected_role);

                $error = sprintf(t('invalid_credentials'), $role_name);

                /* Record this failure for rate limiting */
                rmc_rate_record_fail($conn, $rl_key, $rl_window);

                /* Increment per-user failed attempts counter */
                pg_query_params(
                    $conn,
                    "UPDATE users SET failed_attempts = failed_attempts + 1 WHERE user_id = $1",
                    [(int) $user['user_id']]
                );

                /* Lock account after 5 failed attempts for 15 minutes */
                pg_query_params(
                    $conn,
                    "UPDATE users SET locked_until = NOW() + INTERVAL '15 minutes' WHERE user_id = $1 AND failed_attempts >= 5",
                    [(int) $user['user_id']]
                );

            }

            /*
            |--------------------------------------------------------------------------
            | LOGIN SUCCESS
            |--------------------------------------------------------------------------
            */
            else {

                /*
                |--------------------------------------------------------------------------
                | CLEAR RATE LIMIT on successful login
                |--------------------------------------------------------------------------
                */
                rmc_rate_clear($conn, $rl_key);

                /* Reset per-user lockout counters on successful login */
                pg_query_params(
                    $conn,
                    "UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = $1",
                    [(int) $user['user_id']]
                );

                /*
                |--------------------------------------------------------------------------
                | TWO-FACTOR AUTHENTICATION STEP
                |--------------------------------------------------------------------------
                |
                | If the account has two-factor authentication enabled, do NOT
                | create a full session yet. Store a pending marker (never the
                | secret) and require a valid TOTP code on login_2fa.php first.
                |
                */
                if (!empty($user['twofa_secret'])) {

                    session_regenerate_id(true);

                    $_SESSION['pending_2fa_user_id'] = (int)$user['user_id'];
                    $_SESSION['pending_2fa_role']    = $user['role'];
                    $_SESSION['pending_2fa_attempts'] = 0;

                    header("Location: login_2fa.php");
                    exit();
                }

                /*
                |--------------------------------------------------------------------------
                | Prevent Session Fixation
                |--------------------------------------------------------------------------
                */
                session_regenerate_id(true);

                /*
                |--------------------------------------------------------------------------
                | SINGLE-DEVICE SESSION TOKEN
                |--------------------------------------------------------------------------
                |
                | Mint a fresh random token and store its SHA-256 hash in the
                | database. Any previously active session for this account no
                | longer matches the stored hash and is invalidated, so only
                | this device stays signed in.
                |
                */
                $session_token = bin2hex(random_bytes(32));

                pg_query_params(
                    $conn,
                    "UPDATE users
                     SET session_token = $1
                     WHERE user_id = $2",
                    array(
                        hash('sha256', $session_token),
                        (int) $user['user_id']
                    )
                );

                /*
                |--------------------------------------------------------------------------
                | Store authenticated user information
                |--------------------------------------------------------------------------
                */
                $_SESSION['user_id'] = (int)$user['user_id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['auth_token'] = $session_token;

                /*
                |--------------------------------------------------------------------------
                | Persist account appearance preference
                |--------------------------------------------------------------------------
                */
                $login_appearance =
                    (isset($user['appearance']) &&
                    in_array($user['appearance'], ['light', 'dark', 'system'], true))
                        ? $user['appearance']
                        : 'system';

                $_SESSION['appearance'] = $login_appearance;

                setcookie(
                    'appearance',
                    $login_appearance,
                    [
                        'expires' => time() + (365 * 24 * 60 * 60),
                        'path'    => '/',
                        'samesite' => 'Lax',
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Optional: store login timestamp
                |--------------------------------------------------------------------------
                */
                $_SESSION['login_time'] = time();
                $_SESSION['last_activity'] = time();

                /*
                |--------------------------------------------------------------------------
                | Redirect to dashboard
                |--------------------------------------------------------------------------
                */
                header("Location: dashboard.php");
                exit();
            }
        }
    }
}
?>

<?php $page_title = t('title_login'); include 'partials/head.php'; ?>


<style>

    /* Hide Microsoft Edge password reveal button */
    input::-ms-reveal,
    input::-ms-clear {
        display: none;
    }

    /* Hide Chromium password eye */
    input[type="password"]::-webkit-credentials-auto-fill-button {
        visibility: hidden;
        display: none !important;
        pointer-events: none;
    }

    input[type="password"]::-webkit-textfield-decoration-container {
        display: none;
    }

</style>


<div class="min-h-screen flex">


    <!-- ==========================================================
         LEFT PANEL
         ========================================================== -->

    <div class="hidden lg:flex w-1/2 relative">

        <!-- Campus Background Image -->

        <img
            src="img/campus.jpg"
            alt="Regis Marie College Campus"
            class="absolute inset-0 w-full h-full object-cover"
            onerror="this.style.display='none'; document.getElementById('imgFallback').style.display='flex';"
        >

        <!-- Fallback Background -->

        <div
            id="imgFallback"
            class="absolute inset-0 w-full h-full bg-gradient-to-br from-rmc-800 to-rmc-950"
            style="display:none;"
        ></div>

        <!-- Image Overlay -->

        <div class="absolute inset-0 bg-rmc-950/70"></div>


        <!-- Left Content -->

        <div class="relative z-10 flex flex-col justify-center px-16 text-white">

            <div class="w-24 h-24 rounded-full bg-white p-2 shadow-lg mb-8 flex items-center justify-center">

                <img
                    src="img/logo.webp"
                    alt="Regis Marie College Logo"
                    class="w-full h-full object-contain"
                >

            </div>

            <h1 class="text-5xl font-bold tracking-tight">
                <?= t('welcome_back'); ?>
            </h1>

            <p class="mt-6 text-xl opacity-90">
                <?= t('campus_event_system'); ?>
            </p>

            <p class="mt-4 text-lg opacity-80 max-w-md leading-relaxed">

                <?= t('login_hero_blurb'); ?>

            </p>

        </div>

    </div>


    <!-- ==========================================================
         RIGHT PANEL
         ========================================================== -->

    <div class="flex w-full lg:w-1/2 items-center justify-center p-6 sm:p-8">

        <div class="bg-white/90 backdrop-blur-xl shadow-2xl rounded-[26px] border border-slate-200/60 w-full max-w-md p-8 sm:p-10 animate-up">


            <!-- LOGO / TITLE -->

            <div class="text-center">

                <div class="w-20 h-20 mx-auto rounded-full bg-white border border-rmc-100 shadow-md flex items-center justify-center">

                    <img
                        src="img/logo.webp"
                        alt="Regis Marie College Logo"
                        class="w-full h-full object-contain"
                    >

                </div>

                <h2 class="text-3xl font-bold text-rmc-800 mt-5 tracking-tight">
                    <?= t('regis_marie_college'); ?>
                </h2>

                <p class="text-slate-500 mt-1 text-sm">
                    <?= t('campus_event_system'); ?>
                </p>

            </div>


            <!-- ROLE TABS -->

            <div
                class="mt-8 grid grid-cols-3 gap-2 bg-slate-100 rounded-xl p-1"
                id="roleTabs"
            >

                <button
                    type="button"
                    onclick="selectRole('student')"
                    id="tab-student"
                    class="role-tab bg-white text-rmc-800 shadow py-2 rounded-lg font-semibold text-sm"
                >
                    <?= t('student'); ?>
                </button>

                <button
                    type="button"
                    onclick="selectRole('organizer')"
                    id="tab-organizer"
                    class="role-tab py-2 rounded-lg font-semibold text-sm text-slate-500"
                >
                    <?= t('organizer'); ?>
                </button>

                <button
                    type="button"
                    onclick="selectRole('admin')"
                    id="tab-admin"
                    class="role-tab py-2 rounded-lg font-semibold text-sm text-slate-500"
                >
                    <?= t('admin'); ?>
                </button>

            </div>


            <!-- ERROR MESSAGE -->

            <?php if (!empty($error)): ?>

                <div
                    class="mt-6 bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 text-center"
                    role="alert"
                >

                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>

                </div>

                <?php if ($is_admin_attempt && stripos($error ?? '', 'try again') !== false): ?>
                    <a href="admin_unlock.php?locked=1" class="block mt-3 text-sm font-semibold text-rmc-800 hover:text-rmc-900 underline">
                        <?= t('unlock_via_email') ?: 'Unlock via Email'; ?>
                    </a>
                <?php endif; ?>

            <?php endif; ?>


            <!-- LOGIN FORM -->

            <form
                method="POST"
                action=""
                class="mt-8 space-y-6"
                autocomplete="on"
            >

                <!-- CSRF TOKEN -->

                <?= csrf_field(); ?>


                <!-- SELECTED ROLE -->

                <input
                    type="hidden"
                    name="role"
                    id="selectedRole"
                    value="student"
                >


                <!-- ID / USERNAME -->

                <div>

                    <label
                        id="idLabel"
                        for="idInput"
                        class="font-semibold text-slate-700"
                    >
                        <?= t('student_id_username'); ?>
                    </label>

                    <input
                        type="text"
                        name="student_id"
                        id="idInput"
                        required
                        autocomplete="username"
                        maxlength="100"
                        placeholder="<?= t('placeholder_student_id'); ?>"
                        class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                    >

                </div>


                <!-- PASSWORD -->

                <div>

                    <div class="flex justify-between items-center">

                        <label
                            for="password"
                            class="font-semibold text-slate-700"
                        >
                            <?= t('password'); ?>
                        </label>

                        <a
                            href="forgot_password.php"
                            class="text-sm font-semibold text-rmc-800 hover:text-rmc-900"
                        >
                            <?= t('forgot_password'); ?>
                        </a>

                    </div>


                    <div class="relative mt-2">

                        <input
                            id="password"
                            type="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            maxlength="255"
                            placeholder="<?= t('placeholder_password'); ?>"
                            class="w-full rounded-xl border border-slate-200 px-5 py-3 pr-20 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                        >

                        <button
                            type="button"
                            id="togglePassword"
                            class="absolute right-4 top-1/2 -translate-y-1/2 text-sm font-semibold text-rmc-800 hover:text-rmc-900"
                            aria-label="<?= t('toggle_password'); ?>"
                        >
                            <?= t('show'); ?>
                        </button>

                    </div>

                </div>


                <!-- LOGIN BUTTON -->

                <button
                    type="submit"
                    class="w-full py-3 rounded-xl bg-rmc-800 hover:bg-rmc-900 hover:scale-[1.02] transition duration-300 text-white font-bold text-lg shadow-lg"
                >
                    <?= t('login'); ?>
                </button>

            </form>


            <!-- SIGN UP -->

            <div class="text-center mt-8">

                <span class="text-slate-500">
                    <?= t('dont_have_account'); ?>
                </span>

                <a
                    href="register.php"
                    class="font-bold text-rmc-800 hover:text-rmc-900"
                >
                    <?= t('sign_up'); ?>
                </a>

            </div>

        </div>

    </div>

</div>


<script>

const loginT = <?= json_encode([
    'student_id_username' => t('student_id_username'),
    'placeholder_student_id' => t('placeholder_student_id'),
    'organizer_username' => t('organizer_username'),
    'placeholder_organizer_username' => t('placeholder_organizer_username'),
    'admin_username' => t('admin_username'),
    'placeholder_admin_username' => t('placeholder_admin_username'),
    'show' => t('show'),
    'hide' => t('hide'),
]); ?>;

/*
|--------------------------------------------------------------------------
| ROLE SELECTION
|--------------------------------------------------------------------------
*/

function selectRole(role) {

    document.querySelectorAll('.role-tab').forEach(function(tab) {

        tab.classList.remove(
            'bg-white',
            'text-rmc-800',
            'shadow'
        );

        tab.classList.add('text-slate-500');

    });

    const active = document.getElementById('tab-' + role);

    if (active) {

        active.classList.add(
            'bg-white',
            'text-rmc-800',
            'shadow'
        );

        active.classList.remove('text-slate-500');

    }

    document.getElementById('selectedRole').value = role;

    const label = document.getElementById('idLabel');
    const input = document.getElementById('idInput');

    if (role === 'student') {

        label.textContent = loginT.student_id_username;

        input.placeholder =
            loginT.placeholder_student_id;

    }

    else if (role === 'organizer') {

        label.textContent = loginT.organizer_username;

        input.placeholder =
            loginT.placeholder_organizer_username;

    }

    else if (role === 'admin') {

        label.textContent = loginT.admin_username;

        input.placeholder =
            loginT.placeholder_admin_username;

    }

}


/*
|--------------------------------------------------------------------------
| SHOW / HIDE PASSWORD
|--------------------------------------------------------------------------
*/

const togglePassword =
    document.getElementById('togglePassword');

const password =
    document.getElementById('password');

togglePassword.addEventListener('click', function() {

    if (password.type === 'password') {

        password.type = 'text';

        this.textContent = loginT.hide;

    }

    else {

        password.type = 'password';

        this.textContent = loginT.show;

    }

});


/*
|--------------------------------------------------------------------------
| DEFAULT ROLE
|--------------------------------------------------------------------------
*/

document.addEventListener('DOMContentLoaded', function() {

    selectRole('student');

});

</script>


<?php include_once __DIR__ . '/partials/dark_mode.php'; ?>
