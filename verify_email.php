<?php
session_start();

require 'db_connect.php';
require 'send_email.php';
require 'csrf.php';
require 'lang.php';
require_once 'email_templates.php';

$error = '';
$success = '';

$email = $_SESSION['verification_email'] ?? '';

if ($email === '') {

    header("Location: register.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| RESEND VERIFICATION EMAIL
|--------------------------------------------------------------------------
| Cooldown: 60 seconds. Rate limit: maximum 5 resends per session.
|--------------------------------------------------------------------------
*/

$resend_error = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['resend_verification'])
) {

    csrf_verify();

    $cooldown_seconds = 60;

    $last_resend = $_SESSION['verify_resend_at'] ?? 0;

    $elapsed = time() - (int)$last_resend;

    if ($elapsed < $cooldown_seconds) {

        $resend_error = sprintf(
            t('resend_cooldown_msg'),
            $cooldown_seconds - $elapsed
        );

    } else {

        $resend_count = (int)($_SESSION['verify_resend_count'] ?? 0);

        if ($resend_count >= 5) {

            $resend_error = t('resend_rate_limit_msg');

        } else {

            $pending = pg_query_params(
                $conn,
                "
                SELECT verification_id, full_name, email
                FROM email_verifications
                WHERE LOWER(TRIM(email)) = LOWER(TRIM($1))
                ORDER BY created_at DESC
                LIMIT 1
                ",
                [$email]
            );

            if (!$pending) {

                error_log(
                    "Resend verification lookup failed: " .
                    pg_last_error($conn)
                );

                $resend_error = t('verification_db_error');

            } elseif (pg_num_rows($pending) === 0) {

                $resend_error = t('verification_not_found');

            } else {

                $pending_row = pg_fetch_assoc($pending);

                try {

                    $new_code = (string) random_int(100000, 999999);

                } catch (Exception $e) {

                    error_log(
                        "Resend code generation failed: " .
                        $e->getMessage()
                    );

                    $new_code = null;
                }

                if ($new_code === null) {

                    $resend_error = t('verification_code_gen_error');

                } else {

                    $expires_at = date(
                        'Y-m-d H:i:s',
                        time() + (10 * 60)
                    );

                    $update = pg_query_params(
                        $conn,
                        "
                        UPDATE email_verifications
                        SET verification_code = $1,
                            expires_at = $2,
                            attempts = 0
                        WHERE verification_id = $3
                        ",
                        [
                            $new_code,
                            $expires_at,
                            $pending_row['verification_id']
                        ]
                    );

                    if (!$update) {

                        error_log(
                            "Resend verification update failed: " .
                            pg_last_error($conn)
                        );

                        $resend_error = t('verification_db_error');

                    } else {

                        $email_html = build_verification_email_html(
                            $pending_row['full_name'],
                            $new_code
                        );

                        $email_sent = send_email_deferred(
                            $email,
                            'Verify Your Regis Marie College Account',
                            $email_html
                        );

                        if (!$email_sent) {

                            error_log(
                                "Resend verification email failed for: " .
                                $email
                            );

                            $resend_error = t('verification_email_failed');

                        } else {

                            $_SESSION['verify_resend_at'] = time();

                            $_SESSION['verify_resend_count'] =
                                $resend_count + 1;

                            $resend_error = '';
                            $success = t('resend_verification_sent');
                        }
                    }
                }
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| VERIFY CODE
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !isset($_POST['resend_verification'])
) {

    csrf_verify();

    $code = trim($_POST['verification_code'] ?? '');

    if ($code === '') {

        $error = t('code_empty_msg');

    } elseif (!preg_match('/^[0-9]{6}$/', $code)) {

        $error = t('code_format_msg');

    } else {

        /*
        |--------------------------------------------------------------------------
        | GET PENDING REGISTRATION
        |--------------------------------------------------------------------------
        */

        $query = pg_query_params(
            $conn,
            "
            SELECT
                verification_id,
                full_name,
                student_id,
                department,
                email,
                password_hash,
                verification_code,
                expires_at,
                attempts
            FROM email_verifications
            WHERE LOWER(TRIM(email)) = LOWER(TRIM($1))
            ORDER BY created_at DESC
            LIMIT 1
            ",
            [$email]
        );

        if (!$query) {

            error_log(
                "Verification lookup failed: " .
                pg_last_error($conn)
            );

            $error = t('verification_db_error');

        } elseif (pg_num_rows($query) === 0) {

            $error = t('verification_not_found');

        } else {

            $verification = pg_fetch_assoc($query);

            /*
            |--------------------------------------------------------------------------
            | CHECK ATTEMPT LIMIT
            |--------------------------------------------------------------------------
            */

            if ((int)$verification['attempts'] >= 5) {

                $error = t('too_many_attempts_msg');

            } else {

                /*
                |--------------------------------------------------------------------------
                | CHECK EXPIRATION
                |--------------------------------------------------------------------------
                */

                $expires_timestamp =
                    strtotime($verification['expires_at']);

                if ($expires_timestamp === false) {

                    $error = t('code_expired_msg');

                } elseif (time() > $expires_timestamp) {

                    $error = t('code_expired_msg');

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | CHECK CODE
                    |--------------------------------------------------------------------------
                    */

                    if (
                        !hash_equals(
                            (string)$verification['verification_code'],
                            (string)$code
                        )
                    ) {

                        /*
                        |--------------------------------------------------------------------------
                        | INCREASE ATTEMPT COUNT
                        |--------------------------------------------------------------------------
                        */

                        pg_query_params(
                            $conn,
                            "
                            UPDATE email_verifications
                            SET attempts = attempts + 1
                            WHERE verification_id = $1
                            ",
                            [
                                $verification['verification_id']
                            ]
                        );

                        $remaining =
                            4 - (int)$verification['attempts'];

                        if ($remaining < 0) {
                            $remaining = 0;
                        }

                        $error = sprintf(
                            t('incorrect_code_msg'),
                            $remaining
                        );

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | DOUBLE-CHECK EMAIL / STUDENT ID
                        |--------------------------------------------------------------------------
                        */

                        $check_existing = pg_query_params(
                            $conn,
                            "
                            SELECT user_id
                            FROM users
                            WHERE
                                LOWER(TRIM(email)) = LOWER(TRIM($1))
                                OR student_id = $2
                            LIMIT 1
                            ",
                            [
                                $verification['email'],
                                $verification['student_id']
                            ]
                        );

                        if (!$check_existing) {

                            error_log(
                                "Final duplicate check failed: " .
                                pg_last_error($conn)
                            );

                            $error = t('unable_to_complete_registration');

                        } elseif (pg_num_rows($check_existing) > 0) {

                            $error = t('already_registered_msg');

                            /*
                            |--------------------------------------------------------------------------
                            | DELETE PENDING RECORD
                            |--------------------------------------------------------------------------
                            */

                            pg_query_params(
                                $conn,
                                "
                                DELETE FROM email_verifications
                                WHERE verification_id = $1
                                ",
                                [
                                    $verification['verification_id']
                                ]
                            );

                        } else {

                            /*
                            |--------------------------------------------------------------------------
                            | CREATE USER ACCOUNT
                            |--------------------------------------------------------------------------
                            */

                            $insert_user = pg_query_params(
                                $conn,
                                "
                                INSERT INTO users
                                (
                                    full_name,
                                    student_id,
                                    department,
                                    email,
                                    password,
                                    role,
                                    status,
                                    created_at,
                                    email_verified
                                )
                                VALUES
                                (
                                    $1,
                                    $2,
                                    $3,
                                    $4,
                                    $5,
                                    'student',
                                    'active',
                                    NOW(),
                                    true
                                )
                                RETURNING user_id
                                ",
                                [
                                    $verification['full_name'],
                                    $verification['student_id'],
                                    $verification['department'],
                                    strtolower(
                                        trim(
                                            $verification['email']
                                        )
                                    ),
                                    $verification['password_hash']
                                ]
                            );

                            if (!$insert_user) {

                                error_log(
                                    "User creation after verification failed: " .
                                    pg_last_error($conn)
                                );

                                $error = t('account_creation_failed');

                            } else {

                                /*
                                |--------------------------------------------------------------------------
                                | GET NEW USER ID
                                |--------------------------------------------------------------------------
                                */

                                $new_user =
                                    pg_fetch_assoc($insert_user);

                                /*
                                |--------------------------------------------------------------------------
                                | DELETE VERIFICATION RECORD
                                |--------------------------------------------------------------------------
                                */

                                pg_query_params(
                                    $conn,
                                    "
                                    DELETE FROM email_verifications
                                    WHERE verification_id = $1
                                    ",
                                    [
                                        $verification['verification_id']
                                    ]
                                );

                                /*
                                |--------------------------------------------------------------------------
                                | SEND WELCOME EMAIL
                                |--------------------------------------------------------------------------
                                */

                                $welcome_message = build_welcome_email_html(
                                    $verification['full_name']
                                );

                                $welcome_sent =
                                    send_email_deferred(
                                        $verification['email'],
                                        'Welcome to Regis Marie College Event System',
                                        $welcome_message
                                    );

                                if (!$welcome_sent) {

                                    error_log(
                                        "Welcome email failed after verification for: " .
                                        $verification['email']
                                    );
                                }

                                /*
                                |--------------------------------------------------------------------------
                                | REMOVE SESSION VERIFICATION DATA
                                |--------------------------------------------------------------------------
                                */

                                unset($_SESSION['verification_email']);

                                /*
                                |--------------------------------------------------------------------------
                                | SUCCESS
                                |--------------------------------------------------------------------------
                                */

                                $success = t('account_verified_success');
                            }
                        }
                    }
                }
            }
        }
    }
}
?>

<?php
$page_title = t('verify_email_title');
include 'partials/head.php';
?>

<div class="min-h-screen flex items-center justify-center bg-slate-100 px-6">

    <div class="w-full max-w-md">

        <div class="bg-white rounded-[26px] shadow-2xl border border-slate-200 p-8 sm:p-10">

            <!-- LOGO -->

            <div class="text-center">

                <div class="w-20 h-20 mx-auto rounded-full bg-white border border-slate-200 shadow-md flex items-center justify-center">

                    <img
                        src="img/logo.webp"
                        class="w-full h-full object-contain"
                        alt="Regis Marie College Logo"
                    >

                </div>

                <h1 class="text-3xl font-bold text-rmc-800 mt-5">

                    <?= t('verify_gmail_heading'); ?>

                </h1>

                <p class="text-slate-500 mt-2">

                    <?= t('verify_desc_sent'); ?>

                </p>

                <p class="font-semibold text-rmc-800 mt-2 break-all">

                    <?= htmlspecialchars(
                        $email,
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>

                </p>

            </div>


            <?php if ($error): ?>

                <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 mt-6">

                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>

                </div>

            <?php endif; ?>


            <?php if ($resend_error): ?>

                <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 mt-6">

                    <?= htmlspecialchars(
                        $resend_error,
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>

                </div>

            <?php endif; ?>


            <?php if ($success): ?>

                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl p-4 mt-6">

                    <?= htmlspecialchars(
                        $success,
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>

                </div>

                <div class="text-center mt-6">

                    <a
                        href="login.php"
                        class="inline-block w-full bg-rmc-800 hover:bg-rmc-900 text-white font-bold py-3 rounded-xl shadow-lg transition"
                    >

                        <?= t('proceed_to_login'); ?>

                    </a>

                </div>

            <?php else: ?>

                <form method="POST" class="mt-8">

                    <?= csrf_field(); ?>

                    <label class="block text-sm font-semibold text-slate-700">

                        <?= t('verification_code_label'); ?>

                    </label>

                    <input
                        type="text"
                        name="verification_code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        pattern="[0-9]{6}"
                        required
                        autofocus
                        placeholder="<?= t('verification_code_placeholder'); ?>"
                        class="mt-2 w-full text-center text-2xl tracking-[0.5em] rounded-xl border border-slate-200 px-5 py-4 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                    >

                    <p class="text-xs text-slate-500 text-center mt-3">

                        <?= t('verification_code_expiry_hint'); ?>

                    </p>

                    <button
                        type="submit"
                        class="w-full mt-6 bg-rmc-800 hover:bg-rmc-900 hover:scale-[1.02] transition duration-300 text-white font-bold py-3 rounded-xl shadow-lg"
                    >

                        <?= t('verify_gmail_button'); ?>

                    </button>

                </form>

                <div class="text-center mt-6">

                    <a
                        href="register.php"
                        class="text-rmc-800 font-semibold hover:text-rmc-900"
                    >

                        <?= t('register_again'); ?>

                    </a>

                </div>

                <form method="POST" class="mt-4 text-center">

                    <?= csrf_field(); ?>

                    <input
                        type="hidden"
                        name="resend_verification"
                        value="1"
                    >

                    <button
                        type="submit"
                        class="text-sm font-semibold text-rmc-800 hover:text-rmc-900 underline"
                    >

                        <?= t('resend_verification'); ?>

                    </button>

                </form>

            <?php endif; ?>

        </div>

    </div>

</div>

<?php
include_once __DIR__ . '/partials/dark_mode.php';
?>
