<?php

include 'db_connect.php';
require 'csrf.php';
require 'lang.php';
require 'totp.php';

/*
|--------------------------------------------------------------------------
| TWO-FACTOR AUTHENTICATION — CODE ENTRY
|--------------------------------------------------------------------------
*/

$error = '';

/*
| This page is only reachable when a login is pending 2FA verification.
*/
if (
    !isset($_SESSION['pending_2fa_user_id']) ||
    (int)$_SESSION['pending_2fa_user_id'] <= 0
) {
    header("Location: login.php");
    exit();
}

$pending_user_id = (int)$_SESSION['pending_2fa_user_id'];

/*
| Re-read the user so the TOTP secret is never stored in the session.
*/
$result = $pdo->prepare("SELECT user_id, full_name, role, status, appearance, twofa_secret
     FROM users
     WHERE user_id = ?
     LIMIT 1");
$result->execute([$pending_user_id]);

$pending_user = $result->fetch(PDO::FETCH_ASSOC);

/*
| The account must still exist, be active, and actually have 2FA enabled.
*/
if (
    !$pending_user ||
    strtolower($pending_user['status'] ?? '') !== 'active' ||
    empty($pending_user['twofa_secret'])
) {
    unset(
        $_SESSION['pending_2fa_user_id'],
        $_SESSION['pending_2fa_role'],
        $_SESSION['pending_2fa_attempts']
    );
    header("Location: login.php");
    exit();
}

/*
| Brute-force guard: max 5 attempts per pending session.
*/
$max_attempts = 5;
$attempts     = (int)($_SESSION['pending_2fa_attempts'] ?? 0);

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
    |--------------------------------------------------------------------------
    | CSRF PROTECTION
    |--------------------------------------------------------------------------
    */
    csrf_verify();

    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rl_key = "2fa_fail:{$client_ip}";

    if ($attempts >= $max_attempts || rmc_rate_is_blocked($conn, $rl_key, 10, 900)) {

        /* Record this lockout attempt too */
        if ($attempts >= $max_attempts) {
            rmc_rate_record_fail($conn, $rl_key, 900);
        }

        unset(
            $_SESSION['pending_2fa_user_id'],
            $_SESSION['pending_2fa_role'],
            $_SESSION['pending_2fa_attempts']
        );

        header("Location: login.php?2fa=locked");
        exit();
    }

    $code = trim($_POST['code'] ?? '');

    if ($code === '') {

        $error = t('twofa_code_required');

    } elseif (!preg_match('/^[0-9]{6}$/', $code)) {

        $error = t('twofa_invalid_code');

    } elseif (totp_verify($pending_user['twofa_secret'], $code)) {

        /*
        |--------------------------------------------------------------------------
        | 2FA PASSED — clear rate limits and complete the login
        |--------------------------------------------------------------------------
        */
        rmc_rate_clear($conn, $rl_key);

        unset(
            $_SESSION['pending_2fa_user_id'],
            $_SESSION['pending_2fa_role'],
            $_SESSION['pending_2fa_attempts']
        );

        session_regenerate_id(true);

        /*
        |--------------------------------------------------------------------------
        | SINGLE-DEVICE SESSION TOKEN
        |--------------------------------------------------------------------------
        */

        $session_token = bin2hex(random_bytes(32));

        $pdo->prepare("UPDATE users
             SET session_token = ?
             WHERE user_id = ?")->execute(array(
                hash('sha256', $session_token),
                (int) $pending_user['user_id']
            ));

        $_SESSION['user_id']    = (int)$pending_user['user_id'];
        $_SESSION['role']       = $pending_user['role'];
        $_SESSION['full_name']  = $pending_user['full_name'];
        $_SESSION['auth_token'] = $session_token;

        $_SESSION['rmc_auth'][$pending_user['role']] = [
            'user_id'       => (int)$pending_user['user_id'],
            'role'          => $pending_user['role'],
            'full_name'     => $pending_user['full_name'],
            'auth_token'    => $session_token,
            'login_time'    => time(),
            'last_activity' => time(),
        ];
        $_SESSION['active_role'] = $pending_user['role'];

        $login_appearance =
            (isset($pending_user['appearance']) &&
            in_array($pending_user['appearance'], ['light', 'dark', 'system'], true))
                ? $pending_user['appearance']
                : 'system';

        $_SESSION['appearance'] = $login_appearance;

        setcookie(
            'appearance',
            $login_appearance,
            [
                'expires'  => time() + (365 * 24 * 60 * 60),
                'path'     => '/',
                'samesite' => 'Lax',
            ]
        );

        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();

        setcookie('rmc_tab_role', $pending_user['role'], [
            'expires'  => 0,
            'path'     => '/',
            'samesite' => 'Lax',
        ]);

        header("Location: dashboard.php?rmc_role=" . urlencode($pending_user['role']));
        exit();

    } else {

        /*
        |--------------------------------------------------------------------------
        | Invalid code — track the attempt
        |--------------------------------------------------------------------------
        */
        $attempts++;
        $_SESSION['pending_2fa_attempts'] = $attempts;

        /* Record failure for IP-based rate limiting */
        rmc_rate_record_fail($conn, $rl_key, 900);

        if ($attempts >= $max_attempts) {

            $error = t('twofa_too_many_attempts');

        } else {

            $error = sprintf(t('twofa_invalid_attempts_left'), $max_attempts - $attempts);
        }
    }
}
?>

<?php $page_title = t('twofa_login_title'); include 'partials/head.php'; ?>

<style>

    input[type="number"]::-webkit-outer-spin-button,
    input[type="number"]::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }

</style>

<div class="min-h-screen flex items-center justify-center px-4 py-10">

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

            <h2 class="text-2xl font-bold text-rmc-800 mt-5 tracking-tight">
                <?= t('twofa_step_heading'); ?>
            </h2>

            <p class="text-slate-500 mt-2 text-sm leading-relaxed">
                <?= t('twofa_step_desc'); ?>
            </p>

            <p class="mt-3 text-sm font-semibold text-slate-700">
                <?= htmlspecialchars($pending_user['full_name'], ENT_QUOTES, 'UTF-8'); ?>
            </p>

        </div>


        <!-- ERROR MESSAGE -->

        <?php if (!empty($error)): ?>

            <div
                class="mt-6 bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 text-center text-sm"
                role="alert"
            >
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>

        <?php endif; ?>


        <!-- 2FA CODE FORM -->

        <form
            method="POST"
            action=""
            class="mt-8 space-y-6"
            autocomplete="off"
        >

            <?= csrf_field(); ?>

            <div>

                <label
                    for="code"
                    class="font-semibold text-slate-700 block text-center"
                >
                    <?= t('twofa_verification_code'); ?>
                </label>

                <input
                    type="number"
                    name="code"
                    id="code"
                    required
                    inputmode="numeric"
                    min="0"
                    max="999999"
                    maxlength="6"
                    placeholder="000000"
                    autofocus
                    class="mt-3 w-full rounded-xl border border-slate-200 px-5 py-4 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition text-center text-2xl font-bold tracking-[0.5em] text-slate-800"
                >

            </div>


            <button
                type="submit"
                class="w-full py-3 rounded-xl bg-rmc-800 hover:bg-rmc-900 hover:scale-[1.02] transition duration-300 text-white font-bold text-lg shadow-lg"
            >
                <?= t('twofa_verify'); ?>
            </button>

        </form>


        <!-- BACK TO LOGIN -->

        <div class="text-center mt-8">

            <a
                    href="login.php"
                class="text-sm font-semibold text-rmc-800 hover:text-rmc-900"
            >
                <?= t('twofa_back_to_login'); ?>
            </a>

        </div>

    </div>

</div>


<?php include_once __DIR__ . '/partials/dark_mode.php'; ?>
