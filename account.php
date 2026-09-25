<?php


set_time_limit(60);
ini_set('memory_limit', '256M');

while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);


include 'db_connect.php';

if (!isset($pdo) || $pdo === null) {
    die("Something went wrong. Please try again later.");
}

if (!isset($conn) || $conn === null) {
    $conn = $pdo;
}

require 'csrf.php';
require 'lang.php';
require 'totp.php';
require 'send_email.php';
require_once 'email_templates.php';
require_once 'notifications_helper.php';

$login_error    = '';
$register_error = '';
$active_mode    = 'signin';
$form_action    = $_POST['form_action'] ?? '';
$is_admin_attempt = false;

if (isset($_GET['mode']) && $_GET['mode'] === 'register') {
    $active_mode = 'signup';
}

if (isset($_GET['2fa']) && $_GET['2fa'] === 'locked') {
    $login_error = t('twofa_too_many_attempts');
}

/*
|--------------------------------------------------------------------------
| One-time flash message from verify_email.php (verification / approval)
|--------------------------------------------------------------------------
*/
$registration_success = $_SESSION['registration_success'] ?? '';
if ($registration_success !== '') {
    unset($_SESSION['registration_success']);
}

try {


    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        csrf_verify();

        /*
        |======================================================================
        | LOGIN
        |======================================================================
        */
        if ($form_action === 'login') {

            $active_mode = 'signin';
            $error = '';

            $login = trim($_POST['student_id'] ?? '');
            $password = $_POST['password'] ?? '';
            $selected_role = trim($_POST['role'] ?? '');
            $remember_me = isset($_POST['remember_me']);

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

                $query = $pdo->prepare("SELECT *
                     FROM users
                     WHERE student_id = ?
                     AND role = ?
                     LIMIT 1"); $query->execute([$login, $selected_role]);

                if ($query === false) {

                    $error = t('login_process_error');

                } else {

                    $user = $query->fetch(PDO::FETCH_ASSOC);

                    if (!$user) {

                        $role_name = ucfirst($selected_role);

                        $any_user = $pdo->prepare("SELECT role
                                              FROM users
                                              WHERE student_id = ?
                                              LIMIT 1"); $any_user->execute([$login]);

                        $any_row = $any_user->fetch(PDO::FETCH_NUM);
                        $actual_role = $any_row !== false ? $any_row[0] : null;

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

                    elseif (strtolower($user['status']) === 'pending') {

                        $error = 'Your organizer account is awaiting admin approval. We\'ll email you once it has been reviewed.';

                    }

                    elseif (strtolower($user['status']) === 'rejected') {

                        $error = 'Your organizer account application was not approved. Please contact the administrator for details.';

                    }

                    elseif (strtolower($user['status']) !== 'active') {

                        $error = t('account_deactivated_msg');

                    }

                    elseif ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {

                        $remaining_q = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS secs FROM users WHERE user_id = ?");
                        $remaining_q->execute([(int) $user['user_id']]);
                        $secs = (int) ($remaining_q->fetchColumn() ?? 0);
                        $minutes = max(1, (int) ceil($secs / 60));
                        $error = sprintf(
                            t('too_many_attempts_cooldown') ?: 'Account temporarily locked. Try again in %d minute(s).',
                            $minutes
                        );

                    }

                    elseif (!password_verify($password, $user['password'])) {

                        $role_name = ucfirst($selected_role);

                        $error = sprintf(t('invalid_credentials'), $role_name);

                        rmc_rate_record_fail($conn, $rl_key, $rl_window);

                        $pdo->prepare("UPDATE users SET failed_attempts = failed_attempts + 1 WHERE user_id = ?")->execute([(int) $user['user_id']]);

                        $pdo->prepare("UPDATE users SET locked_until = NOW() + INTERVAL 15 MINUTE WHERE user_id = ? AND failed_attempts >= 5")->execute([(int) $user['user_id']]);

                    }

                    else {

                        rmc_rate_clear($conn, $rl_key);

                        $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?")
                            ->execute([(int) $user['user_id']]);

                        if (!empty($user['twofa_secret'])) {

                            session_regenerate_id(true);
                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                            $_SESSION['pending_2fa_user_id'] = (int)$user['user_id'];
                            $_SESSION['pending_2fa_role']    = $user['role'];
                            $_SESSION['pending_2fa_attempts'] = 0;

                            header("Location: login_2fa.php");
                            exit();
                        }

                        session_regenerate_id(true);

                        $session_token = bin2hex(random_bytes(32));

                        $pdo->prepare("UPDATE users
                             SET session_token = ?
                             WHERE user_id = ?")->execute(array(
                                hash('sha256', $session_token),
                                (int) $user['user_id']
                            ));

                        $_SESSION['user_id'] = (int)$user['user_id'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['auth_token'] = $session_token;
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        $_SESSION['rmc_auth'][$selected_role] = [
                            'user_id'       => (int)$user['user_id'],
                            'role'          => $user['role'],
                            'full_name'     => $user['full_name'],
                            'auth_token'    => $session_token,
                            'login_time'    => time(),
                            'last_activity' => time(),
                        ];
                        $_SESSION['active_role'] = $selected_role;

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

                        $_SESSION['login_time'] = time();
                        $_SESSION['last_activity'] = time();

                        setcookie('rmc_tab_role', $selected_role, [
                            'expires'  => 0,
                            'path'     => '/',
                            'samesite' => 'Lax',
                        ]);

                        if ($remember_me) {
                            setcookie(session_name(), session_id(), [
                                'expires'  => time() + (30 * 24 * 60 * 60),
                                'path'     => '/',
                                'samesite' => 'Lax',
                                'httponly' => true,
                            ]);
                        }

                        if ($selected_role === 'organizer' && !empty($user['must_change_password'])) {
                            header("Location: update_password.php?forced=1");
                            exit();
                        }

                        header("Location: dashboard.php?rmc_role=" . urlencode($selected_role));
                        exit();
                    }
                }
            }

            $login_error = $error;
        }

        /*
        |======================================================================
        | REGISTER
        |======================================================================
        */
        elseif ($form_action === 'register') {

            $active_mode = 'signup';
            $error = '';

            $client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $rl_key = "register_fail:{$client_ip}";
            if (rmc_rate_is_blocked($conn, $rl_key, 5, 3600)) {
                $remaining = rmc_rate_remaining($conn, $rl_key, 3600);
                $minutes   = (int) ceil($remaining / 60);
                $error = sprintf(
                    t('too_many_attempts_cooldown') ?: 'Too many attempts. Please try again in %d minute(s).',
                    $minutes
                );
            }

            $full_name = trim($_POST['full_name'] ?? '');
            $student_id = trim($_POST['student_id'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            $signup_role = ($_POST['signup_role'] ?? 'student') === 'organizer' ? 'organizer' : 'student';

            if ($error !== '') {

                /* rate limited — skip validation */

            } elseif (
                $full_name === '' ||
                $student_id === '' ||
                ($signup_role === 'student' && $department === '') ||
                $email === '' ||
                $password === '' ||
                $confirm_password === ''
            ) {

                $error = t('fill_all_fields');

            } elseif (mb_strlen($full_name) < 2) {

                $error = t('valid_full_name_msg');

            } elseif (mb_strlen($student_id) < 3) {

                $error = t('valid_student_id_msg');

            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

                $error = t('valid_email_msg');

            } elseif (
                !preg_match(
                    '/^[a-zA-Z0-9._%+\-]+@gmail\.com$/i',
                    $email
                )
            ) {

                $error = t('gmail_only_msg');

            } elseif (
                $signup_role === 'student' &&
                !in_array($department, rmc_departments(), true)
            ) {

                $error = t('select_valid_department');

            } elseif (rmc_password_policy_error($password) !== null) {

                $error = rmc_password_policy_message();

            } elseif ($password !== $confirm_password) {

                $error = t('passwords_mismatch_msg');

            } else {

                $check_student = $pdo->prepare("
                    SELECT user_id
                    FROM users
                    WHERE student_id = ?
                    LIMIT 1
                    "); $check_student->execute([$student_id]);

                if (!$check_student) {

                    $error = t('registration_process_error');

                } elseif ($check_student->rowCount() > 0) {

                    $error = t('student_id_exists_msg');

                } else {

                    $check_email = $pdo->prepare("
                        SELECT user_id
                        FROM users
                        WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
                        LIMIT 1
                        "); $check_email->execute([$email]);

                    if (!$check_email) {

                        $error = t('registration_process_error');

                    } elseif ($check_email->rowCount() > 0) {

                        $error = t('email_exists_msg');

                    } else {

                        $delete_old = $pdo->prepare("
                            DELETE FROM email_verifications
                            WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
                            "); $delete_old->execute([$email]);

                        if (!$delete_old) {

                            $error = t('registration_process_error');

                        } else {

                            $hashed_password = password_hash(
                                $password,
                                PASSWORD_DEFAULT
                            );

                            if ($hashed_password === false) {

                                $error = t('password_hash_error');

                            } else {

                                try {

                                    $verification_code = (string) random_int(
                                        100000,
                                        999999
                                    );

                                } catch (Exception $e) {

                                    $error = t('verification_code_gen_error');

                                    $verification_code = null;
                                }

                                if ($verification_code !== null) {

                                    $expires_at = date(
                                        'Y-m-d H:i:s',
                                        time() + (10 * 60)
                                    );

                                    $insert_verification = $pdo->prepare("
                                        INSERT INTO email_verifications
                                        (full_name, student_id, department, role, email, password_hash, verification_code, expires_at, attempts, created_at)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
                                        ");

                                    $insert_verification->execute([
                                        $full_name,
                                        $student_id,
                                        $department,
                                        $signup_role,
                                        $email,
                                        $hashed_password,
                                        $verification_code,
                                        $expires_at
                                    ]);

                                    if (!$insert_verification) {

                                        $error = 'Unable to start email verification. Please try again.';

                                    } else {

                                        $email_message = build_verification_email_html(
                                            $full_name,
                                            $verification_code
                                        );

                                        $email_sent = send_notification_email(
                                            $email,
                                            'Verify Your Regis Marie College Account',
                                            $email_message
                                        );

                                        if (!$email_sent) {

                                            $pdo->prepare("
                                                DELETE FROM email_verifications
                                                WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
                                                ")->execute([$email]);

                                            $error = t('verification_email_failed');

                                        } else {

                                            $_SESSION['verification_email'] = $email;

                                            header("Location: verify_email.php");
                                            exit();
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                if (!empty($error) && strpos($error, 'Too many attempts') === false) {
                    rmc_rate_record_fail($conn, $rl_key, 3600);
                }
            }

            $register_error = $error;
        }
    }

} catch (\Throwable $e) {
    error_log('Account page error: ' . $e->getMessage());
    echo "Something went wrong. Please try again later.";
    exit;
}

$page_title = $active_mode === 'signup' ? t('title_signup') : t('title_login');
include 'partials/head.php';

/*
|--------------------------------------------------------------------------
| Display-text fallbacks
|--------------------------------------------------------------------------
*/
function rmc_display_text(string $key, string $fallback): string {
    $value = t($key);
    if ($value === '' || $value === $key) {
        return $fallback;
    }
    return $value;
}

$remember_me_label   = rmc_display_text('remember_me', 'Remember me');
$welcome_back_label  = rmc_display_text('welcome_back', 'Welcome Back');
$discover_headline   = 'Discover. Participate. Connect.';
$login_blurb         = rmc_display_text('login_hero_blurb', 'Sign in to manage your events and stay up to date with campus activities.');
$register_blurb      = rmc_display_text('register_hero_blurb', 'Create your student account to register for campus events, receive announcements, and stay connected with school activities.');
?>
<style>

    input::-ms-reveal, input::-ms-clear { display: none; }
    /* NOTE: do not use display:none!important + pointer-events:none on
       ::-webkit-credentials-auto-fill-button — on iOS Safari this leaves an
       invisible overlay that blocks taps/typing in type="password" fields.
       opacity+visibility hides it visually without breaking touch input. */
    input[type="password"]::-webkit-credentials-auto-fill-button {
        visibility: hidden;
        opacity: 0;
    }

    html, body {
        height: 100%;
        margin: 0;
    }

    body {
        background: #0B1F3A;
    }

    .rmc-shell {
        position: relative;
        min-height: 100vh;
        width: 100%;
        display: flex;
        flex-direction: column;
        box-sizing: border-box;
        overflow: hidden;
        background: #0B1F3A;
    }

    #rmcAuroraSvg {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        z-index: 0;
    }

    @media (min-width: 1024px) {
        .rmc-shell {
            flex-direction: row;
        }
    }

    .rmc-brand-pane {
        position: relative;
        z-index: 2;
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 3rem 1.75rem;
        color: #fff;
    }

    @media (min-width: 1024px) {
        .rmc-brand-pane {
            flex: 0 0 42%;
            min-height: 100vh;
            padding: 4rem 3.5rem;
        }
    }

    .rmc-brand-logo {
        position: relative;
        width: 68px;
        height: 68px;
        border-radius: 9999px;
        background: #fff;
        padding: 6px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.28);
        margin-bottom: 1.5rem;
        object-fit: contain;
    }

    .rmc-pill-badge {
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        background: rgba(255,255,255,0.12);
        border: 1px solid rgba(255,255,255,0.25);
        color: #fff;
        font-size: 0.68rem;
        font-weight: 600;
        letter-spacing: 0.04em;
        padding: 0.35rem 0.9rem;
        border-radius: 9999px;
        margin-bottom: 1.1rem;
        max-width: 100%;
    }

    .rmc-brand-headline {
        color: #fff;
        position: relative;
        font-size: 1.65rem;
        line-height: 1.25;
        font-weight: 800;
        letter-spacing: -0.01em;
        max-width: 26rem;
        word-wrap: break-word;
        transition: opacity .25s ease;
    }

    .rmc-brand-blurb {
        position: relative;
        margin-top: 0.85rem;
        font-size: 0.9rem;
        color: #fff;
        font-weight: 700;
        line-height: 1.6;
        max-width: 24rem;
        word-wrap: break-word;
        transition: opacity .25s ease;
    }

    .rmc-ghost-btn {
        position: relative;
        margin-top: 1.75rem;
        border: 1.5px solid rgba(255,255,255,0.75);
        background: transparent;
        border-radius: 9999px;
        padding: 0.65rem 2.1rem;
        font-weight: 700;
        font-size: 0.85rem;
        color: #fff;
        transition: all 0.25s ease;
        cursor: pointer;
    }

    .rmc-ghost-btn:hover {
        background: #fff;
        color: var(--color-primary-800, #1E3A5F);
    }

    .rmc-form-pane {
        position: relative;
        z-index: 2;
        flex: 1 1 auto;
        box-sizing: border-box;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2.5rem 1.5rem 3rem;
    }

    @media (min-width: 1024px) {
        .rmc-form-pane {
            min-height: 100vh;
            padding: 3rem 4rem;
        }
    }

    .rmc-form-inner {
        position: relative;
        background: rgba(255,255,255,0.97);
        border-radius: 20px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.35);
        width: 100%;
        max-width: 420px;
        padding: 2rem 2.25rem;
        box-sizing: border-box;
    }

    /* Cursor-following mascot eyes — decorative only, with no form impact. */
    .rmc-watching-eyes {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 0.7rem;
        height: 42px;
        margin: -0.25rem auto 0.8rem;
        pointer-events: none;
        user-select: none;
    }
    .rmc-watching-eye {
        position: relative;
        width: 58px;
        height: 34px;
        border-radius: 52% 48% 50% 50% / 58% 58% 42% 42%;
        background: #fff;
        border: 1px solid rgba(30,58,95,.12);
        box-shadow: 0 6px 16px rgba(30,58,95,.12);
        overflow: hidden;
        transition: transform .22s cubic-bezier(.22,.75,.2,1), height .22s ease, border-radius .22s ease;
    }
    .rmc-watching-eye::before {
        content: '';
        position: absolute;
        inset: 5px 10px;
        border-radius: 50%;
        background: rgba(11,31,58,.08);
    }
    .rmc-eye-pupil {
        position: absolute;
        left: 50%;
        top: 50%;
        width: 17px;
        height: 17px;
        border-radius: 50%;
        background: #0B1F3A;
        transform: translate(-50%, -50%);
        box-shadow: inset 0 2px 3px rgba(255,255,255,.55), 0 2px 5px rgba(11,31,58,.28);
        transition: transform .12s ease-out;
    }
    .rmc-eye-pupil::after {
        content: '';
        position: absolute;
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: #fff;
        top: 3px;
        left: 4px;
        opacity: .9;
    }
    .rmc-watching-eyes.is-closed .rmc-watching-eye {
        height: 8px;
        border-radius: 999px;
        transform: translateY(10px);
    }
    .rmc-watching-eyes.is-closed .rmc-eye-pupil {
        opacity: 0;
    }
    .rmc-watching-eyes.is-closed .rmc-watching-eye::before {
        inset: 3px 7px;
        background: rgba(11,31,58,.16);
    }
    @media (max-width: 520px) {
        .rmc-watching-eye { width: 48px; height: 29px; }
        .rmc-eye-pupil { width: 15px; height: 15px; }
    }

    .rmc-panel-fade {
        position: relative;
        z-index: 1;
        animation: rmcFadeIn .4s ease;
    }

    @keyframes rmcFadeIn {
        from { opacity: 0; transform: translateY(8px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .rmc-field label {
        display: block;
        font-size: 0.72rem;
        font-weight: 600;
        color: var(--color-text-secondary, #6b7280);
        margin-bottom: 0.35rem;
    }

    .rmc-field-row {
        position: relative;
        display: flex;
        align-items: center;
        background: var(--color-bg-tertiary, #f9fafb);
        border: 1.5px solid var(--color-border-light, #e5e7eb);
        border-radius: 0.75rem;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .rmc-field-row:focus-within {
        border-color: var(--color-border-focus, #2563EB);
        box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
    }

    .rmc-field-row input,
    .rmc-field-row select {
        flex: 1;
        background: transparent;
        border: none;
        outline: none;
        color: var(--color-text-primary, #1f2937);
        padding: 0.65rem 0.75rem;
        font-size: 0.9rem;
        min-width: 0;
    }

    .rmc-field-row select option { color: var(--color-text-primary, #1f2937); }

    .rmc-field-row i.rmc-field-icon {
        color: var(--color-text-tertiary, #9ca3af);
        font-size: 0.8rem;
        margin: 0 0.75rem;
    }

    .rmc-field-row i.rmc-pwd-eye {
        color: var(--color-text-tertiary, #9ca3af);
        cursor: pointer;
        margin-right: 0.75rem;
    }

    .rmc-checkbox {
        width: 1rem;
        height: 1rem;
        accent-color: var(--color-primary-800, #1E3A5F);
        border-radius: 0.25rem;
    }

    .rmc-role-tabs { background: var(--color-bg-tertiary, #f3f4f6); }

    .rmc-role-tabs .role-tab {
        transition: all 0.2s ease;
        color: var(--color-text-secondary, #6b7280);
    }

    .rmc-role-tabs .role-tab.active {
        background: var(--color-bg-secondary, #fff);
        color: var(--color-primary-800, #1E3A5F);
        box-shadow: 0 1px 3px rgba(0,0,0,0.12), inset 0 0 0 1px rgba(30,58,95,0.25);
    }

    .rmc-submit {
        background: linear-gradient(120deg, var(--color-primary-800, #1E3A5F), var(--color-primary-950, #0B1F3A));
        box-shadow: var(--shadow-primary-md, 0 8px 20px -6px rgba(30,58,95,0.55));
    }

    .rmc-submit:hover {
        box-shadow: var(--shadow-primary-lg, 0 10px 26px -6px rgba(30,58,95,0.7));
        transform: translateY(-1px);
    }

    .rmc-back-home {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.85rem;
        color: var(--color-text-secondary, #6b7280);
        padding: 1rem;
        text-align: center;
        justify-content: center;
    }

    .rmc-back-home:hover { color: var(--color-text-primary, #1f2937); }

    #rmcWalker {
        position: absolute;
        bottom: 40px;
        width: 22px;
        height: 36px;
        z-index: 1;
    }
    #rmcWalker svg { width: 100%; height: 100%; }
    #rmcWalker .leg { transform-origin: 11px 20px; }

    @media (prefers-reduced-motion: reduce) {
        .rmc-panel-fade, .rmc-brand-headline, .rmc-brand-blurb, .rmc-watching-eye, .rmc-eye-pupil {
            animation: none !important;
            transition: none !important;
        }
        #rmcWalker, #rmcAuroraSvg circle, #rmcAuroraSvg path { animation: none !important; }
    }

</style>

<div class="rmc-shell" id="rmcShell" data-mode="<?= htmlspecialchars($active_mode, ENT_QUOTES, 'UTF-8'); ?>">

    <svg id="rmcAuroraSvg" viewBox="0 0 1400 900" preserveAspectRatio="xMidYMid slice">
        <circle id="rmcOrb1" cx="230" cy="230" r="220" fill="#0C447C" opacity="0.55" style="mix-blend-mode: screen;"></circle>
        <circle id="rmcOrb2" cx="1080" cy="700" r="260" fill="#085041" opacity="0.4" style="mix-blend-mode: screen;"></circle>
        <circle id="rmcOrb3" cx="960" cy="150" r="170" fill="#3C3489" opacity="0.4" style="mix-blend-mode: screen;"></circle>
        <circle id="rmcOrb4" cx="460" cy="740" r="140" fill="#072A47" opacity="0.5" style="mix-blend-mode: screen;"></circle>
        <path id="rmcWave1" d="M0,660 C340,620 700,720 1400,650 L1400,900 L0,900 Z" fill="#0C447C" opacity="0.28"></path>
        <path id="rmcWave2" d="M0,740 C420,700 840,780 1400,720 L1400,900 L0,900 Z" fill="#085041" opacity="0.22"></path>
    </svg>

    <!-- ================= BRAND PANE ================= -->
    <div class="rmc-brand-pane">

        <div id="rmcWalker" aria-hidden="true">
            <svg viewBox="0 0 22 36">
                <circle cx="11" cy="5" r="4.2" fill="#fff" opacity="0.85"/>
                <line x1="11" y1="9" x2="11" y2="21" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity="0.85"/>
                <line x1="11" y1="12" x2="4" y2="18" stroke="#fff" stroke-width="2.5" stroke-linecap="round" opacity="0.85"/>
                <line x1="11" y1="12" x2="18" y2="16" stroke="#fff" stroke-width="2.5" stroke-linecap="round" opacity="0.85"/>
                <line id="rmcLegL" class="leg" x1="11" y1="21" x2="6" y2="32" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity="0.85"/>
                <line id="rmcLegR" class="leg" x1="11" y1="21" x2="16" y2="32" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity="0.85"/>
            </svg>
        </div>

        <img src="img/logo.webp" class="rmc-brand-logo" alt="Regis Marie College Logo">

        <span class="rmc-pill-badge">
            <i class="fa-solid fa-graduation-cap"></i>
            <?= htmlspecialchars(t('campus_event_system'), ENT_QUOTES, 'UTF-8'); ?>
        </span>

        <h1 class="rmc-brand-headline" id="brandHeadline">
            <?= $active_mode === 'signup'
                ? htmlspecialchars($discover_headline, ENT_QUOTES, 'UTF-8')
                : htmlspecialchars($welcome_back_label, ENT_QUOTES, 'UTF-8');
            ?>
        </h1>

        <p class="rmc-brand-blurb" id="brandBlurb">
            <?= $active_mode === 'signup'
                ? htmlspecialchars($register_blurb, ENT_QUOTES, 'UTF-8')
                : htmlspecialchars($login_blurb, ENT_QUOTES, 'UTF-8');
            ?>
        </p>

        <button
            type="button"
            class="rmc-ghost-btn"
            id="brandSwitchBtn"
            onclick="rmcSetMode('<?= $active_mode === 'signup' ? 'signin' : 'signup'; ?>')"
        >
            <?= $active_mode === 'signup'
                ? htmlspecialchars(t('sign_in'), ENT_QUOTES, 'UTF-8')
                : htmlspecialchars(t('sign_up'), ENT_QUOTES, 'UTF-8');
            ?>
        </button>

    </div>

    <!-- ================= FORM PANE ================= -->
    <div class="rmc-form-pane">
        <div class="rmc-form-inner">

            <div class="rmc-watching-eyes" id="rmcWatchingEyes" aria-hidden="true">
                <div class="rmc-watching-eye"><span class="rmc-eye-pupil"></span></div>
                <div class="rmc-watching-eye"><span class="rmc-eye-pupil"></span></div>
            </div>

            <!-- ---- SIGN IN PANEL ---- -->
            <div class="rmc-panel rmc-panel-fade" id="panelSignin" <?= $active_mode === 'signup' ? 'hidden' : ''; ?>>

                <h2 class="text-2xl font-bold tracking-tight text-gray-900"><?= t('login'); ?></h2>
                <p class="text-xs text-gray-500 mt-1"><?= t('campus_event_system'); ?></p>

                <div class="rmc-role-tabs mt-5 grid grid-cols-3 gap-1 rounded-lg p-1" id="roleTabs">
                    <button type="button" onclick="selectRole('student')" id="tab-student" class="role-tab active py-1.5 rounded-md text-xs font-semibold flex items-center justify-center gap-1">
                        <i class="fa-solid fa-graduation-cap"></i> <?= t('student'); ?>
                    </button>
                    <button type="button" onclick="selectRole('organizer')" id="tab-organizer" class="role-tab py-1.5 rounded-md text-xs font-semibold flex items-center justify-center gap-1">
                        <i class="fa-solid fa-calendar-check"></i> <?= t('organizer'); ?>
                    </button>
                    <button type="button" onclick="selectRole('admin')" id="tab-admin" class="role-tab py-1.5 rounded-md text-xs font-semibold flex items-center justify-center gap-1">
                        <i class="fa-solid fa-user-shield"></i> <?= t('admin'); ?>
                    </button>
                </div>

                <?php if ($registration_success): ?>
                    <div class="mt-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl p-3 text-xs">
                        <i class="fa-solid fa-circle-check mr-1"></i>
                        <?= htmlspecialchars($registration_success, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if ($login_error): ?>
                    <div class="mt-4 bg-red-50 border border-red-200 text-red-700 rounded-xl p-3 text-xs">
                        <?= htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php if ($is_admin_attempt && stripos($login_error, 'try again') !== false): ?>
                        <a href="admin_unlock.php?locked=1" class="block mt-2 text-xs font-semibold text-[var(--color-primary-800)] hover:text-[var(--color-primary-950)] underline">
                            <?= t('unlock_via_email') ?: 'Unlock via Email'; ?>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <form method="POST" action="" class="mt-6 space-y-4" autocomplete="on">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="form_action" value="login">
                    <input type="hidden" name="role" id="selectedRole" value="student">

                    <div class="rmc-field">
                        <label id="idLabel" for="idInput"><?= t('student_id_username'); ?></label>
                        <div class="rmc-field-row">
                            <i class="fa-solid fa-user rmc-field-icon" id="idFieldIcon"></i>
                            <input type="text" name="student_id" id="idInput" required autocomplete="username" maxlength="100"
                                placeholder="<?= t('placeholder_student_id'); ?>">
                        </div>
                    </div>

                    <div class="rmc-field">
                        <div class="flex justify-between items-center">
                            <label for="password" class="!mb-0"><?= t('password'); ?></label>
                            <a href="forgot_password.php" class="text-[0.7rem] font-semibold text-[var(--color-primary-800)] hover:text-[var(--color-primary-950)]"><?= t('forgot_password'); ?></a>
                        </div>
                        <div class="rmc-field-row">
                            <i class="fa-solid fa-lock rmc-field-icon"></i>
                            <input id="password" type="password" name="password" required autocomplete="current-password" maxlength="255"
                                placeholder="<?= t('placeholder_password'); ?>">
                            <i id="togglePassword" class="fa-solid fa-eye rmc-pwd-eye" aria-label="<?= t('toggle_password'); ?>"></i>
                        </div>
                    </div>

                    <label class="flex items-center gap-2 select-none cursor-pointer">
                        <input type="checkbox" name="remember_me" class="rmc-checkbox">
                        <span class="text-xs text-gray-500"><?= htmlspecialchars($remember_me_label, ENT_QUOTES, 'UTF-8'); ?></span>
                    </label>

                    <button type="submit" class="rmc-submit w-full py-3 rounded-xl text-white font-bold text-sm transition duration-300">
                        <?= t('login'); ?>
                    </button>
                </form>

                <p class="text-center text-xs text-gray-500 mt-5">
                    <?= t('dont_have_account'); ?>
                    <a href="#" onclick="rmcSetMode('signup'); return false;" class="font-semibold text-[var(--color-primary-800)] hover:text-[var(--color-primary-950)]"><?= t('sign_up'); ?></a>
                </p>
            </div>

            <!-- ---- SIGN UP PANEL ---- -->
            <div class="rmc-panel rmc-panel-fade" id="panelSignup" <?= $active_mode === 'signup' ? '' : 'hidden'; ?>>

                <h2 class="text-2xl font-bold tracking-tight text-gray-900"><?= t('create_account'); ?></h2>
                <p class="text-xs text-gray-500 mt-1"><?= t('campus_event_system'); ?></p>

                <?php if ($register_error): ?>
                    <div class="mt-4 bg-red-50 border border-red-200 text-red-700 rounded-xl p-3 text-xs">
                        <?= htmlspecialchars($register_error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="mt-5 space-y-3" autocomplete="on">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="form_action" value="register">
                    <input type="hidden" name="signup_role" id="signupRoleInput" value="student">

                    <div class="rmc-field">
                        <label>Register as</label>
                        <div class="rmc-role-tabs grid grid-cols-2 gap-1 rounded-lg p-1" id="signupRoleTabs">
                            <button type="button" onclick="selectSignupRole('student')" id="signup-tab-student" class="role-tab active py-1.5 rounded-md text-xs font-semibold flex items-center justify-center gap-1">
                                <i class="fa-solid fa-graduation-cap"></i> Student
                            </button>
                            <button type="button" onclick="selectSignupRole('organizer')" id="signup-tab-organizer" class="role-tab py-1.5 rounded-md text-xs font-semibold flex items-center justify-center gap-1">
                                <i class="fa-solid fa-calendar-check"></i> Organizer
                            </button>
                        </div>
                        <p id="organizerHint" class="text-[0.68rem] text-amber-600 mt-2" style="display:none;">
                            <i class="fa-solid fa-circle-info mr-1"></i>
                            Organizer accounts require admin approval before you can log in.
                        </p>
                    </div>

                    <div class="rmc-field">
                        <label><?= t('full_name'); ?></label>
                        <div class="rmc-field-row">
                            <i class="fa-solid fa-user rmc-field-icon"></i>
                            <input type="text" name="full_name" required autocomplete="name" placeholder="<?= t('placeholder_full_name'); ?>"
                                value="<?= $form_action === 'register' ? htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES, 'UTF-8') : ''; ?>">
                        </div>
                    </div>

                    <div class="rmc-field">
                        <label id="signupIdLabel"><?= t('student_id'); ?></label>
                        <div class="rmc-field-row">
                            <i class="fa-solid fa-id-card rmc-field-icon" id="signupIdIcon"></i>
                            <input type="text" name="student_id" id="signupIdInput" required autocomplete="username" placeholder="2025-00001"
                                value="<?= $form_action === 'register' ? htmlspecialchars($_POST['student_id'] ?? '', ENT_QUOTES, 'UTF-8') : ''; ?>">
                        </div>
                    </div>

                    <div class="rmc-field">
                        <label><?= t('gmail_address'); ?></label>
                        <div class="rmc-field-row">
                            <i class="fa-solid fa-envelope rmc-field-icon"></i>
                            <input type="email" name="email" required autocomplete="email" placeholder="<?= t('placeholder_gmail'); ?>"
                                value="<?= $form_action === 'register' ? htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') : ''; ?>">
                        </div>
                    </div>

                    <div class="rmc-field" id="deptFieldWrap">
                        <label><?= t('department'); ?></label>

                        <div class="rmc-field-row" id="deptSelectRow">
                            <i class="fa-solid fa-building-columns rmc-field-icon"></i>
                            <select name="department" id="deptSelect" required>
                                <option value=""><?= t('select_department'); ?></option>
                                <?php
                                $departments = rmc_departments();
                                $selected_dept = $form_action === 'register' ? ($_POST['department'] ?? '') : '';
                                foreach ($departments as $dept):
                                ?>
                                    <option value="<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8'); ?>" <?= ($selected_dept === $dept) ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="rmc-field">
                        <label><?= t('password'); ?></label>
                        <div class="rmc-field-row">
                            <i class="fa-solid fa-lock rmc-field-icon"></i>
                            <input type="password" name="password" id="regPassword" required minlength="8"
                                pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}"
                                title="Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one special symbol."
                                autocomplete="new-password"
                                placeholder="<?= t('password_min_hint'); ?>">
                            <i class="fa-solid fa-eye rmc-pwd-eye" data-target="regPassword"></i>
                        </div>
                    </div>

                    <div class="rmc-field">
                        <label><?= t('confirm_password'); ?></label>
                        <div class="rmc-field-row">
                            <i class="fa-solid fa-lock rmc-field-icon"></i>
                            <input type="password" name="confirm_password" id="regConfirmPassword" required minlength="8"
                                pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}"
                                title="Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one special symbol."
                                autocomplete="new-password"
                                placeholder="<?= t('placeholder_confirm_password'); ?>">
                            <i class="fa-solid fa-eye rmc-pwd-eye" data-target="regConfirmPassword"></i>
                        </div>
                    </div>

                    <p class="text-[0.68rem] text-gray-400"><?= t('verification_code_hint'); ?></p>
                    <p class="text-[0.68rem] text-gray-500"><?= htmlspecialchars(rmc_password_policy_message(), ENT_QUOTES, 'UTF-8'); ?></p>

                    <button type="submit" class="rmc-submit w-full py-3 rounded-xl text-white font-bold text-sm transition duration-300">
                        <?= t('create_account'); ?>
                    </button>
                </form>

                <p class="text-center text-xs text-gray-500 mt-4">
                    <?= t('already_have_account'); ?>
                    <a href="#" onclick="rmcSetMode('signin'); return false;" class="font-semibold text-[var(--color-primary-800)] hover:text-[var(--color-primary-950)]"><?= t('sign_in'); ?></a>
                </p>
            </div>

        </div>
    </div>

</div>

<a href="landing.php" class="rmc-back-home">
    <i class="fa-solid fa-house-user text-xs"></i>
    <?= t('back_to_home'); ?>
</a>

<script>

const loginT = <?= json_encode([
    'student_id_username' => t('student_id_username'),
    'placeholder_student_id' => t('placeholder_student_id'),
    'organizer_username' => t('organizer_username'),
    'placeholder_organizer_username' => t('placeholder_organizer_username'),
    'admin_username' => t('admin_username'),
    'placeholder_admin_username' => t('placeholder_admin_username'),
]); ?>;

const roleIcons = { student: 'fa-graduation-cap', organizer: 'fa-calendar-check', admin: 'fa-user-shield' };

function selectRole(role) {

    document.querySelectorAll('#roleTabs .role-tab').forEach(tab => tab.classList.remove('active'));
    const active = document.getElementById('tab-' + role);
    if (active) active.classList.add('active');

    document.getElementById('selectedRole').value = role;

    const label = document.getElementById('idLabel');
    const input = document.getElementById('idInput');
    const icon = document.getElementById('idFieldIcon');

    Object.values(roleIcons).forEach(cls => icon.classList.remove(cls));
    icon.classList.add(roleIcons[role]);

    if (role === 'student') {
        label.textContent = loginT.student_id_username;
        input.placeholder = loginT.placeholder_student_id;
    } else if (role === 'organizer') {
        label.textContent = loginT.organizer_username;
        input.placeholder = loginT.placeholder_organizer_username;
    } else if (role === 'admin') {
        label.textContent = loginT.admin_username;
        input.placeholder = loginT.placeholder_admin_username;
    }
}

function selectSignupRole(role) {

    document.querySelectorAll('#signupRoleTabs .role-tab').forEach(tab => tab.classList.remove('active'));
    const active = document.getElementById('signup-tab-' + role);
    if (active) active.classList.add('active');

    document.getElementById('signupRoleInput').value = role;

    const hint = document.getElementById('organizerHint');
    if (hint) hint.style.display = (role === 'organizer') ? 'block' : 'none';

    const idLabel = document.getElementById('signupIdLabel');
    const idInput = document.getElementById('signupIdInput');
    const idIcon  = document.getElementById('signupIdIcon');

    const deptFieldWrap = document.getElementById('deptFieldWrap');
    const deptSelect    = document.getElementById('deptSelect');

    if (role === 'organizer') {

        idLabel.textContent = 'Organizer ID / Username';
        idInput.placeholder = 'Choose a username you\'ll log in with';
        idIcon.classList.remove('fa-id-card');
        idIcon.classList.add('fa-id-badge');

        deptFieldWrap.style.display = 'none';
        deptSelect.disabled = true;
        deptSelect.required = false;

    } else {

        idLabel.textContent = <?= json_encode(t('student_id')); ?>;
        idInput.placeholder = '2025-00001';
        idIcon.classList.remove('fa-id-badge');
        idIcon.classList.add('fa-id-card');

        deptFieldWrap.style.display = 'block';
        deptSelect.disabled = false;
        deptSelect.required = true;
    }
}

document.querySelectorAll('.rmc-pwd-eye[data-target]').forEach(function (toggle) {
    toggle.addEventListener('click', function () {
        const input = document.getElementById(toggle.dataset.target);
        if (!input) return;
        if (input.type === 'password') {
            input.type = 'text';
            toggle.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            toggle.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });
});

const togglePassword = document.getElementById('togglePassword');
const password = document.getElementById('password');

togglePassword.addEventListener('click', function() {
    if (password.type === 'password') {
        password.type = 'text';
        this.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        password.type = 'password';
        this.classList.replace('fa-eye-slash', 'fa-eye');
    }
});

/* =========================================================
   CURSOR-FOLLOWING EYES
   - Follow the pointer everywhere on the page.
   - Close while any password field is focused.
   - Decorative only; does not change authentication behavior.
   ========================================================= */
(function initRmcWatchingEyes() {
    const eyes = document.getElementById('rmcWatchingEyes');
    if (!eyes) return;

    const pupils = Array.from(eyes.querySelectorAll('.rmc-eye-pupil'));
    const passwordFields = Array.from(document.querySelectorAll('input[type="password"]'));

    function movePupils(clientX, clientY) {
        if (eyes.classList.contains('is-closed')) return;
        pupils.forEach(function(pupil) {
            const eye = pupil.parentElement;
            const rect = eye.getBoundingClientRect();
            const dx = clientX - (rect.left + rect.width / 2);
            const dy = clientY - (rect.top + rect.height / 2);
            const distance = Math.sqrt(dx * dx + dy * dy) || 1;
            const maxX = 9;
            const maxY = 6;
            const x = Math.max(-maxX, Math.min(maxX, (dx / distance) * Math.min(maxX, distance / 9)));
            const y = Math.max(-maxY, Math.min(maxY, (dy / distance) * Math.min(maxY, distance / 9)));
            pupil.style.transform = 'translate(calc(-50% + ' + x.toFixed(2) + 'px), calc(-50% + ' + y.toFixed(2) + 'px))';
        });
    }

    document.addEventListener('mousemove', function(e) {
        movePupils(e.clientX, e.clientY);
    }, { passive: true });

    function closeEyes() {
        eyes.classList.add('is-closed');
    }

    function openEyes() {
        eyes.classList.remove('is-closed');
    }

    passwordFields.forEach(function(field) {
        field.addEventListener('focus', closeEyes);
        field.addEventListener('blur', openEyes);
    });
})();

/* =========================================================
   MODE SWITCH (sign in <-> sign up)
   ========================================================= */

let rmcCurrentMode = document.getElementById('rmcShell').dataset.mode === 'signup' ? 'signup' : 'signin';

const brandContent = {
    signin: {
        headline: <?= json_encode($welcome_back_label); ?>,
        blurb: <?= json_encode($login_blurb); ?>,
        btn: <?= json_encode(t('sign_up')); ?>
    },
    signup: {
        headline: <?= json_encode($discover_headline); ?>,
        blurb: <?= json_encode($register_blurb); ?>,
        btn: <?= json_encode(t('sign_in')); ?>
    }
};

function rmcSetMode(mode) {

    if (mode !== 'signin' && mode !== 'signup') return;
    if (mode === rmcCurrentMode) return;

    rmcCurrentMode = mode;

    const shell = document.getElementById('rmcShell');
    shell.dataset.mode = mode;

    const signinPanel = document.getElementById('panelSignin');
    const signupPanel = document.getElementById('panelSignup');

    const showSignin = mode === 'signin';

    signinPanel.hidden = !showSignin;
    signupPanel.hidden = showSignin;

    const visiblePanel = showSignin ? signinPanel : signupPanel;
    visiblePanel.classList.remove('rmc-panel-fade');
    void visiblePanel.offsetWidth;
    visiblePanel.classList.add('rmc-panel-fade');

    const content = brandContent[mode];
    const headlineEl = document.getElementById('brandHeadline');
    const blurbEl = document.getElementById('brandBlurb');
    const btnEl = document.getElementById('brandSwitchBtn');

    headlineEl.style.opacity = 0;
    blurbEl.style.opacity = 0;

    setTimeout(function () {
        headlineEl.textContent = content.headline;
        blurbEl.textContent = content.blurb;
        btnEl.textContent = content.btn;
        btnEl.setAttribute('onclick', "rmcSetMode('" + (mode === 'signup' ? 'signin' : 'signup') + "')");
        headlineEl.style.opacity = 1;
        blurbEl.style.opacity = 1;
    }, 180);
}

document.addEventListener('DOMContentLoaded', function() {
    selectRole('student');
    selectSignupRole('student');
});

/* =========================================================
   AURORA BACKGROUND ANIMATION + WALKING FIGURE
   ========================================================= */
(function() {
    var prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReduced) return;

    var t = 0;
    var orb1 = document.getElementById('rmcOrb1');
    var orb2 = document.getElementById('rmcOrb2');
    var orb3 = document.getElementById('rmcOrb3');
    var orb4 = document.getElementById('rmcOrb4');
    var wave1 = document.getElementById('rmcWave1');
    var wave2 = document.getElementById('rmcWave2');
    var walker = document.getElementById('rmcWalker');
    var legL = document.getElementById('rmcLegL');
    var legR = document.getElementById('rmcLegR');

    var wx = -30;
    var brandPane = walker ? walker.parentElement : null;

    function frame() {
        t += 0.012;

        if (orb1) { orb1.setAttribute('cx', 230 + Math.sin(t) * 90); orb1.setAttribute('cy', 230 + Math.cos(t * 0.8) * 60); }
        if (orb2) { orb2.setAttribute('cx', 1080 + Math.cos(t * 0.7) * 80); orb2.setAttribute('cy', 700 + Math.sin(t * 0.9) * 60); }
        if (orb3) { orb3.setAttribute('cx', 960 + Math.sin(t * 0.6 + 1) * 70); orb3.setAttribute('cy', 150 + Math.cos(t * 0.5) * 45); }
        if (orb4) { orb4.setAttribute('cx', 460 + Math.cos(t * 0.65) * 60); orb4.setAttribute('cy', 740 + Math.sin(t * 0.75) * 50); }

        if (wave1) {
            var w1 = 'M0,' + (660 + Math.sin(t) * 26) + ' C340,' + (620 + Math.cos(t * 1.2) * 28) + ' 700,' + (720 + Math.sin(t * 0.8) * 22) + ' 1400,' + (650 + Math.cos(t) * 28) + ' L1400,900 L0,900 Z';
            wave1.setAttribute('d', w1);
        }
        if (wave2) {
            var w2 = 'M0,' + (740 + Math.cos(t * 0.9) * 20) + ' C420,' + (700 + Math.sin(t * 1.1) * 24) + ' 840,' + (780 + Math.cos(t * 0.7) * 22) + ' 1400,' + (720 + Math.sin(t) * 24) + ' L1400,900 L0,900 Z';
            wave2.setAttribute('d', w2);
        }

        if (walker && brandPane) {
            var paneWidth = brandPane.getBoundingClientRect().width || 400;
            wx += 0.7;
            if (wx > paneWidth + 10) { wx = -30; }
            walker.style.left = wx + 'px';
            var swing = Math.sin(t * 16) * 6;
            if (legL) legL.setAttribute('x2', 6 - swing);
            if (legR) legR.setAttribute('x2', 16 + swing);
        }

        requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
})();

</script>

<?php include_once __DIR__ . '/partials/dark_mode.php'; ?>