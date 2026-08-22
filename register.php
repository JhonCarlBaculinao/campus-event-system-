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

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($error !== '') {

        /* rate limited — skip validation */

    } elseif (
        $full_name === '' ||
        $student_id === '' ||
        $department === '' ||
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

    /*
    |--------------------------------------------------------------------------
    | GMAIL-ONLY CHECK
    |--------------------------------------------------------------------------
    */

    } elseif (
        !preg_match(
            '/^[a-zA-Z0-9._%+\-]+@gmail\.com$/i',
            $email
        )
    ) {

        $error = t('gmail_only_msg');

    } elseif (strlen($password) < 8) {

        $error = t('password_min_msg');

    } elseif ($password !== $confirm_password) {

        $error = t('passwords_mismatch_msg');

    } else {

        /*
        |--------------------------------------------------------------------------
        | CHECK EXISTING STUDENT ID
        |--------------------------------------------------------------------------
        */

        $check_student = pg_query_params(
            $conn,
            "
            SELECT user_id
            FROM users
            WHERE student_id = $1
            LIMIT 1
            ",
            [$student_id]
        );

        if (!$check_student) {

            error_log(
                "Registration student ID check failed: " .
                pg_last_error($conn)
            );

            $error = t('registration_process_error');

        } elseif (pg_num_rows($check_student) > 0) {

            $error = t('student_id_exists_msg');

        } else {

            /*
            |--------------------------------------------------------------------------
            | CHECK EXISTING EMAIL
            |--------------------------------------------------------------------------
            */

            $check_email = pg_query_params(
                $conn,
                "
                SELECT user_id
                FROM users
                WHERE LOWER(TRIM(email)) = LOWER(TRIM($1))
                LIMIT 1
                ",
                [$email]
            );

            if (!$check_email) {

                error_log(
                    "Registration email check failed: " .
                    pg_last_error($conn)
                );

                $error = t('registration_process_error');

            } elseif (pg_num_rows($check_email) > 0) {

                $error = t('email_exists_msg');

            } else {

                /*
                |--------------------------------------------------------------------------
                | REMOVE OLD/PENDING VERIFICATION FOR THIS EMAIL
                |--------------------------------------------------------------------------
                */

                $delete_old = pg_query_params(
                    $conn,
                    "
                    DELETE FROM email_verifications
                    WHERE LOWER(TRIM(email)) = LOWER(TRIM($1))
                    ",
                    [$email]
                );

                if (!$delete_old) {

                    error_log(
                        "Old verification cleanup failed: " .
                        pg_last_error($conn)
                    );

                    $error = t('registration_process_error');

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | HASH PASSWORD
                    |--------------------------------------------------------------------------
                    */

                    $hashed_password = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    if ($hashed_password === false) {

                        $error = t('password_hash_error');

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | GENERATE 6-DIGIT VERIFICATION CODE
                        |--------------------------------------------------------------------------
                        */

                        try {

                            $verification_code = (string) random_int(
                                100000,
                                999999
                            );

                        } catch (Exception $e) {

                            error_log(
                                "Verification code generation failed: " .
                                $e->getMessage()
                            );

                            $error = t('verification_code_gen_error');

                            $verification_code = null;
                        }

                        if ($verification_code !== null) {

                            /*
                            |--------------------------------------------------------------------------
                            | CODE EXPIRES AFTER 10 MINUTES
                            |--------------------------------------------------------------------------
                            */

                            $expires_at = date(
                                'Y-m-d H:i:s',
                                time() + (10 * 60)
                            );

                            /*
                            |--------------------------------------------------------------------------
                            | SAVE PENDING REGISTRATION
                            |--------------------------------------------------------------------------
                            */

                            $insert_verification = pg_query_params(
                                $conn,
                                "
                                INSERT INTO email_verifications
                                (
                                    full_name,
                                    student_id,
                                    department,
                                    email,
                                    password_hash,
                                    verification_code,
                                    expires_at,
                                    attempts,
                                    created_at
                                )
                                VALUES
                                (
                                    $1,
                                    $2,
                                    $3,
                                    $4,
                                    $5,
                                    $6,
                                    $7,
                                    0,
                                    NOW()
                                )
                                ",
                                [
                                    $full_name,
                                    $student_id,
                                    $department,
                                    $email,
                                    $hashed_password,
                                    $verification_code,
                                    $expires_at
                                ]
                            );

                            if (!$insert_verification) {

                                error_log(
                                    "Verification insert failed: " .
                                    pg_last_error($conn)
                                );

                                $error = 'Unable to start email verification. Please try again.';

                            } else {

                                /*
                                |--------------------------------------------------------------------------
                                | SEND VERIFICATION EMAIL
                                |--------------------------------------------------------------------------
                                */

                                $email_message = build_verification_email_html(
                                    $full_name,
                                    $verification_code
                                );

                                $email_sent = send_email_deferred(
                                    $email,
                                    'Verify Your Regis Marie College Account',
                                    $email_message
                                );

                                if (!$email_sent) {

                                    /*
                                    |--------------------------------------------------------------------------
                                    | REMOVE PENDING REGISTRATION IF EMAIL FAILED
                                    |--------------------------------------------------------------------------
                                    */

                                    pg_query_params(
                                        $conn,
                                        "
                                        DELETE FROM email_verifications
                                        WHERE LOWER(TRIM(email)) = LOWER(TRIM($1))
                                        ",
                                        [$email]
                                    );

                                    error_log(
                                        "Verification email failed for: " .
                                        $email
                                    );

                                    $error = t('verification_email_failed');

                                } else {

                                    /*
                                    |--------------------------------------------------------------------------
                                    | SAVE EMAIL IN SESSION
                                    |--------------------------------------------------------------------------
                                    */

                                    $_SESSION['verification_email'] = $email;

                                    /*
                                    |--------------------------------------------------------------------------
                                    | REDIRECT TO VERIFICATION PAGE
                                    |--------------------------------------------------------------------------
                                    */

                                    header(
                                        "Location: verify_email.php"
                                    );

                                    exit();
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
    }
}
?>

<?php
$page_title = t('title_signup');
include 'partials/head.php';
?>

<div class="min-h-screen flex">

    <!-- LEFT PANEL -->

    <div class="hidden lg:flex w-1/2 relative overflow-hidden">

        <img
            src="img/campus.jpg"
            class="absolute inset-0 w-full h-full object-cover"
            alt="Regis Marie College Campus"
            onerror="this.style.display='none'; document.getElementById('imgFallback').style.display='block';"
        >

        <div
            id="imgFallback"
            class="absolute inset-0 w-full h-full bg-gradient-to-br from-rmc-800 to-rmc-950"
            style="display:none;"
        ></div>

        <div class="absolute inset-0 bg-rmc-950/70"></div>

        <div class="absolute -top-20 -left-20 w-72 h-72 bg-rmc-500/20 rounded-full blur-3xl"></div>

        <div class="absolute bottom-0 right-0 w-96 h-96 bg-rmc-300/20 rounded-full blur-3xl"></div>

        <div class="relative z-10 flex flex-col justify-center px-16 text-white">

            <div class="w-24 h-24 mb-8 rounded-full bg-white p-2 shadow-lg flex items-center justify-center">

                <img
                    src="img/logo.webp"
                    class="w-full h-full object-contain"
                    alt="Regis Marie College Logo"
                >

            </div>

            <h1 class="text-5xl font-extrabold leading-tight tracking-tight">

                <?= t('join_our'); ?>

                <span class="text-rmc-300">
                    <?= t('community'); ?>
                </span>

            </h1>

            <p class="mt-6 text-xl">
                <?= t('regis_marie_college'); ?>
            </p>

            <p class="mt-3 text-lg opacity-90 max-w-md leading-relaxed">
                <?= t('register_hero_blurb'); ?>
            </p>

            <div class="mt-10 bg-white/10 backdrop-blur-lg rounded-2xl p-5 w-80 border border-white/20">

                <p class="font-semibold text-lg flex items-center gap-3">

                    <i class="fa-solid fa-calendar-days text-rmc-300"></i>

                    <?= t('campus_events'); ?>

                </p>

                <p class="text-sm mt-2 opacity-90">
                    <?= t('register_once_blurb'); ?>
                </p>

            </div>

        </div>

    </div>


    <!-- RIGHT PANEL -->

    <div class="flex w-full lg:w-1/2 justify-center items-center p-6 sm:p-8">

        <div class="w-full max-w-md bg-white/90 backdrop-blur-xl rounded-[26px] border border-slate-200/60 shadow-2xl p-8 sm:p-10 animate-up">

            <div class="text-center">

                <div class="w-20 h-20 mx-auto rounded-full bg-white border border-rmc-100 shadow-md flex items-center justify-center">

                    <img
                        src="img/logo.webp"
                        class="w-full h-full object-contain"
                        alt="Regis Marie College Logo"
                    >

                </div>

                <h2 class="text-3xl font-bold text-rmc-800 mt-4 tracking-tight">

                    <?= t('create_account'); ?>

                </h2>

                <p class="text-slate-500 mt-1 text-sm">

                    <?= t('campus_event_system'); ?>

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


            <form method="POST" class="mt-8 space-y-5">

                <?= csrf_field(); ?>


                <div>

                    <label class="text-sm font-semibold text-slate-700">

                        <?= t('full_name'); ?>

                    </label>

                    <input
                        type="text"
                        name="full_name"
                        required
                        autocomplete="name"
                        placeholder="<?= t('placeholder_full_name'); ?>"
                        value="<?= htmlspecialchars(
                            $_POST['full_name'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>"
                        class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                    >

                </div>


                <div>

                    <label class="text-sm font-semibold text-slate-700">

                        <?= t('student_id'); ?>

                    </label>

                    <input
                        type="text"
                        name="student_id"
                        required
                        autocomplete="username"
                        placeholder="2025-00001"
                        value="<?= htmlspecialchars(
                            $_POST['student_id'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>"
                        class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                    >

                </div>


                <div>

                    <label class="text-sm font-semibold text-slate-700">

                        <?= t('gmail_address'); ?>

                    </label>

                    <input
                        type="email"
                        name="email"
                        required
                        autocomplete="email"
                        placeholder="<?= t('placeholder_gmail'); ?>"
                        value="<?= htmlspecialchars(
                            $_POST['email'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>"
                        class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                    >

                    <p class="text-xs text-slate-500 mt-2">
                        <?= t('verification_code_hint'); ?>
                    </p>

                </div>


                <div>

                    <label class="text-sm font-semibold text-slate-700">

                        <?= t('department'); ?>

                    </label>

                    <select
                        name="department"
                        required
                        class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                    >

                        <option value="">
                            <?= t('select_department'); ?>
                        </option>

                        <?php

                        $departments = [
                            'BS Computer Science',
                            'BS Information Technology',
                            'BSOA - Office Administration',
                            'BS Education'
                        ];

                        foreach ($departments as $dept):

                        ?>

                            <option
                                value="<?= htmlspecialchars(
                                    $dept,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>"
                                <?= (
                                    ($_POST['department'] ?? '') === $dept
                                )
                                    ? 'selected'
                                    : ''; ?>
                            >

                                <?= htmlspecialchars(
                                    $dept,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div>

                    <label class="text-sm font-semibold text-slate-700">

                        <?= t('password'); ?>

                    </label>

                    <input
                        type="password"
                        name="password"
                        required
                        minlength="8"
                        autocomplete="new-password"
                        placeholder="<?= t('password_min_hint'); ?>"
                        class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                    >

                </div>


                <div>

                    <label class="text-sm font-semibold text-slate-700">

                        <?= t('confirm_password'); ?>

                    </label>

                    <input
                        type="password"
                        name="confirm_password"
                        required
                        minlength="8"
                        autocomplete="new-password"
                        placeholder="<?= t('placeholder_confirm_password'); ?>"
                        class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                    >

                </div>


                <button
                    type="submit"
                    class="w-full bg-rmc-800 hover:bg-rmc-900 hover:scale-[1.02] transition duration-300 text-white font-bold py-3 rounded-xl shadow-lg"
                >

                    <?= t('create_account'); ?>

                </button>

            </form>


            <div class="text-center mt-8">

                <span class="text-slate-500">

                    <?= t('already_have_account'); ?>

                </span>

                <a
                    href="login.php"
                    class="font-semibold text-rmc-800 hover:text-rmc-900"
                >

                    <?= t('sign_in'); ?>

                </a>

            </div>

        </div>

    </div>

</div>

<?php
include_once __DIR__ . '/partials/dark_mode.php';
?>