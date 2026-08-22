<?php
session_start();

require 'db_connect.php';
require 'send_email.php';
require 'csrf.php';
require 'lang.php';
require_once 'email_templates.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rl_key = "reset_fail:{$client_ip}";
    if (rmc_rate_is_blocked($conn, $rl_key, 5, 3600)) {
        $remaining = rmc_rate_remaining($conn, $rl_key, 3600);
        $minutes   = (int) ceil($remaining / 60);
        $error = sprintf(
            t('too_many_attempts_cooldown') ?: 'Too many attempts. Please try again in %d minute(s).',
            $minutes
        );
    }

    $email = strtolower(trim($_POST['email'] ?? ''));

    if ($error !== '') {

        /* rate limited — skip processing */

    } elseif ($email === '') {

        $error = t('enter_email_msg');

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = t('invalid_email_msg');

    } else {

        /*
        |--------------------------------------------------------------------------
        | FIND USER (CASE-INSENSITIVE)
        |--------------------------------------------------------------------------
        */

        $query = pg_query_params(
            $conn,
            "SELECT user_id, full_name, email
             FROM users
             WHERE LOWER(email) = LOWER($1)
             LIMIT 1",
            [$email]
        );

        if (!$query) {

            error_log(
                "Forgot password lookup failed: " .
                pg_last_error($conn)
            );

            $error = t('request_process_error');

        } else {

            /*
            |--------------------------------------------------------------------------
            | ALWAYS SHOW SAME MESSAGE
            |--------------------------------------------------------------------------
            | This prevents revealing whether an email exists.
            |--------------------------------------------------------------------------
            */

            $success = t('reset_link_sent_msg');

            rmc_rate_record_fail($conn, $rl_key, 3600);

            if (pg_num_rows($query) > 0) {

                $user = pg_fetch_assoc($query);

                /*
                |--------------------------------------------------------------------------
                | GENERATE SECURE RESET TOKEN
                |--------------------------------------------------------------------------
                */

                $raw_token = bin2hex(random_bytes(32));

                /*
                |--------------------------------------------------------------------------
                | STORE HASHED TOKEN (NEVER THE RAW TOKEN)
                |--------------------------------------------------------------------------
                */

                $token_hash = hash('sha256', $raw_token);

                /*
                |--------------------------------------------------------------------------
                | TOKEN EXPIRES IN 30 MINUTES
                |--------------------------------------------------------------------------
                */

                $expiry = date(
                    'Y-m-d H:i:s',
                    time() + (30 * 60)
                );

                $update = pg_query_params(
                    $conn,
                    "UPDATE users
                     SET reset_token = $1,
                         reset_token_expiry = $2
                     WHERE user_id = $3",
                    [
                        $token_hash,
                        $expiry,
                        $user['user_id']
                    ]
                );

                if (!$update) {

                    error_log(
                        "Reset token update failed: " .
                        pg_last_error($conn)
                    );

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | BUILD RESET URL
                    |--------------------------------------------------------------------------
                    |
                    | Built from the current request so it works on
                    | localhost (any project folder) and in production.
                    |
                    |--------------------------------------------------------------------------
                    */

                    $scheme = (
                        isset($_SERVER['HTTPS']) &&
                        $_SERVER['HTTPS'] !== 'off'
                    )
                        ? 'https'
                        : 'http';

                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

                    $script_dir = rtrim(
                        str_replace(
                            '\\',
                            '/',
                            dirname($_SERVER['SCRIPT_NAME'] ?? '/')
                        ),
                        '/'
                    );

                    $reset_url =
                        $scheme .
                        '://' .
                        $host .
                        $script_dir .
                        '/reset_password.php?token=' .
                        urlencode($raw_token);

                    /*
                    |--------------------------------------------------------------------------
                    | EMAIL CONTENT
                    |--------------------------------------------------------------------------
                    */

                    $email_message = build_password_reset_email_html(
                        $user['full_name'],
                        $reset_url
                    );

                    $email_sent = send_email_deferred(
                        $user['email'],
                        'Reset Your Regis Marie College Event System Password',
                        $email_message
                    );

                    if (!$email_sent) {

                        error_log(
                            "Password reset email failed for: " .
                            $user['email']
                        );
                    }
                }
            }
        }
    }
}
?>

<?php
$page_title = t('title_forgot_password');
include 'partials/head.php';
?>

<div class="min-h-screen flex items-center justify-center bg-slate-100 p-6">

    <div class="w-full max-w-md bg-white/90 backdrop-blur-xl shadow-2xl rounded-[26px] border border-slate-200/60 p-8 sm:p-10 animate-up">

        <div class="text-center">

            <div class="w-20 h-20 mx-auto rounded-full bg-white border border-rmc-100 shadow-md p-2">

                <img
                    src="img/logo.webp"
                    class="w-full h-full object-contain"
                    alt="Regis Marie College Logo"
                >

            </div>

            <h1 class="text-3xl font-bold text-rmc-800 mt-5">

                <?= t('forgot_password_title'); ?>

            </h1>

            <p class="text-slate-500 mt-2">

                <?= t('forgot_desc'); ?>

            </p>

        </div>

        <?php if ($error): ?>

            <div class="mt-6 bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4">

                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>

            </div>

        <?php endif; ?>

        <?php if ($success): ?>

            <div class="mt-6 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl p-4">

                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>

            </div>

        <?php endif; ?>

        <form method="POST" class="mt-8 space-y-5">

            <?= csrf_field(); ?>

            <div>

                <label class="block text-sm font-semibold text-slate-700">

                    <?= t('email_address'); ?>

                </label>

                <input
                    type="email"
                    name="email"
                    required
                    autocomplete="email"
                    placeholder="<?= t('placeholder_registered_email'); ?>"
                    value="<?= htmlspecialchars(
                        $_POST['email'] ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>"
                    class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                >

            </div>

            <button
                type="submit"
                class="w-full bg-rmc-800 hover:bg-rmc-900 hover:scale-[1.02] transition duration-300 text-white font-bold py-3 rounded-xl shadow-lg"
            >

                <?= t('send_reset_link'); ?>

            </button>

        </form>

        <div class="text-center mt-6">

            <a
                href="login.php"
                class="font-semibold text-rmc-800 hover:text-rmc-900"
            >

                <?= t('back_to_login'); ?>

            </a>

        </div>

    </div>

</div>

<?php include_once __DIR__ . '/partials/dark_mode.php'; ?>
