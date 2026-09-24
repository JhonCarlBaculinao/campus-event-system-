<?php

include 'db_connect.php';
require 'csrf.php';
require 'lang.php';
require 'send_email.php';
require_once 'email_templates.php';
require_once 'notifications_helper.php';

if (empty($_SESSION['verification_email'])) {
    header("Location: account.php?mode=register");
    exit();
}

$email = $_SESSION['verification_email'];

$error   = '';
$success = '';

$MAX_ATTEMPTS = 5;

/*
|--------------------------------------------------------------------------
| Handle POST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    $form_action = $_POST['form_action'] ?? '';

    /*
    |======================================================================
    | VERIFY CODE
    |======================================================================
    */
    if ($form_action === 'verify_code') {

        $entered_code = trim($_POST['verification_code'] ?? '');

        $stmt = $pdo->prepare("
            SELECT *
            FROM email_verifications
            WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {

            unset($_SESSION['verification_email']);
            $error = 'Your verification session has expired. Please register again.';

        } elseif (strtotime($record['expires_at']) < time()) {

            $pdo->prepare("DELETE FROM email_verifications WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))")
                ->execute([$email]);
            unset($_SESSION['verification_email']);
            $error = 'This verification code has expired. Please register again.';

        } elseif ((int) $record['attempts'] >= $MAX_ATTEMPTS) {

            $pdo->prepare("DELETE FROM email_verifications WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))")
                ->execute([$email]);
            unset($_SESSION['verification_email']);
            $error = 'Too many incorrect attempts. Please register again.';

        } elseif ($entered_code === '' || $entered_code !== (string) $record['verification_code']) {

            $pdo->prepare("UPDATE email_verifications SET attempts = attempts + 1 WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))")
                ->execute([$email]);

            $remaining = $MAX_ATTEMPTS - ((int) $record['attempts'] + 1);

            $error = $remaining > 0
                ? "Incorrect code. {$remaining} attempt(s) remaining."
                : 'Incorrect code. Please register again.';

            if ($remaining <= 0) {
                $pdo->prepare("DELETE FROM email_verifications WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))")
                    ->execute([$email]);
                unset($_SESSION['verification_email']);
            }

        } else {

            /* Code correct — create the real account */

            $check_again = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) OR student_id = ? LIMIT 1");
            $check_again->execute([$record['email'], $record['student_id']]);

            if ($check_again->rowCount() > 0) {

                $pdo->prepare("DELETE FROM email_verifications WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))")
                    ->execute([$email]);
                unset($_SESSION['verification_email']);
                $error = 'An account with this email or student ID already exists. Please log in instead.';

            } else {

                /*
                |------------------------------------------------------------
                | Organizer signups need admin approval before they can log
                | in, so they land in 'pending' instead of 'active'. Student
                | signups are unaffected.
                |------------------------------------------------------------
                */

                $requested_role = in_array($record['role'] ?? 'student', ['student', 'organizer'], true)
                    ? $record['role']
                    : 'student';

                $initial_status = $requested_role === 'organizer' ? 'pending' : 'active';

                $insert_user = $pdo->prepare("
                    INSERT INTO users
                        (full_name, student_id, department, email, password, role, status,
                         email_notifications, appearance, email_verified, created_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, 1, 'system', 1, NOW())
                ");

                $insert_user->execute([
                    $record['full_name'],
                    $record['student_id'],
                    $record['department'],
                    $record['email'],
                    $record['password_hash'],
                    $requested_role,
                    $initial_status,
                ]);

                $pdo->prepare("DELETE FROM email_verifications WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))")
                    ->execute([$email]);

                unset($_SESSION['verification_email']);

                if ($requested_role === 'organizer') {

                    /* Let every admin know a new organizer needs review */

                    notify_admins(
                        $pdo,
                        htmlspecialchars($record['full_name']) . ' has requested an organizer account and is awaiting your approval.',
                        'organizer_signup'
                    );

                    $_SESSION['registration_success'] =
                        'Your organizer account has been created and is awaiting admin approval. ' .
                        'You will be notified once it has been reviewed.';

                } else {

                    $_SESSION['registration_success'] = 'Your account has been verified! You can now log in.';
                }

                header("Location: account.php?mode=signin");
                exit();
            }
        }
    }

    /*
    |======================================================================
    | RESEND CODE
    |======================================================================
    */
    elseif ($form_action === 'resend_code') {

        $stmt = $pdo->prepare("
            SELECT *
            FROM email_verifications
            WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {

            unset($_SESSION['verification_email']);
            $error = 'Your verification session has expired. Please register again.';

        } else {

            try {
                $new_code = (string) random_int(100000, 999999);
            } catch (Exception $e) {
                $new_code = null;
            }

            if ($new_code === null) {

                $error = 'Could not generate a new code. Please try again.';

            } else {

                $new_expiry = date('Y-m-d H:i:s', time() + (10 * 60));

                $pdo->prepare("
                    UPDATE email_verifications
                    SET verification_code = ?, expires_at = ?, attempts = 0
                    WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
                ")->execute([$new_code, $new_expiry, $email]);

                $email_message = build_verification_email_html($record['full_name'], $new_code);

                $sent = send_notification_email(
                    $email,
                    'Verify Your Regis Marie College Account',
                    $email_message
                );

                if ($sent) {
                    $success = 'A new verification code has been sent to your email.';
                } else {
                    $error = 'Could not send the verification email. Please try again shortly.';
                }
            }
        }
    }
}

/* Re-fetch expiry for display (may have just been updated above) */
$expires_at_display = null;
if (!empty($_SESSION['verification_email'])) {
    $stmt = $pdo->prepare("SELECT expires_at FROM email_verifications WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $expires_at_display = $row['expires_at'];
    }
}

$page_title = 'Verify Your Email';
include 'partials/head.php';
?>

<div class="min-h-screen flex items-center justify-center bg-rmc-50 px-4 py-10">

    <div class="w-full max-w-md bg-white rounded-[26px] border border-slate-200 shadow-sm p-8">

        <div class="flex flex-col items-center text-center mb-6">

            <div class="w-14 h-14 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg mb-4">
                <i class="fa-solid fa-envelope-circle-check text-2xl"></i>
            </div>

            <h1 class="text-2xl font-bold text-slate-900">Verify Your Email</h1>

            <p class="text-slate-500 text-sm mt-2">
                We sent a 6-digit code to
                <span class="font-semibold text-slate-700"><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></span>.
                Enter it below to finish creating your account.
            </p>

        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 rounded-xl p-3 text-sm mb-5">
                <i class="fa-solid fa-circle-exclamation mr-1"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl p-3 text-sm mb-5">
                <i class="fa-solid fa-circle-check mr-1"></i>
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['verification_email'])): ?>

            <form method="POST" action="" class="space-y-4">
                <?= csrf_field(); ?>
                <input type="hidden" name="form_action" value="verify_code">

                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Verification Code</label>
                    <input
                        type="text"
                        name="verification_code"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="6"
                        required
                        autofocus
                        placeholder="123456"
                        class="w-full text-center tracking-[0.5em] text-lg font-bold border border-slate-200 rounded-xl px-4 py-3 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
                    >
                </div>

                <?php if ($expires_at_display): ?>
                    <p class="text-xs text-slate-400 text-center">
                        This code expires at <?= date('h:i A', strtotime($expires_at_display)); ?>.
                    </p>
                <?php endif; ?>

                <button
                    type="submit"
                    class="w-full py-3 rounded-xl text-white font-bold text-sm bg-rmc-800 hover:bg-rmc-900 transition"
                >
                    Verify & Create Account
                </button>

            </form>

            <form method="POST" action="" class="mt-3">
                <?= csrf_field(); ?>
                <input type="hidden" name="form_action" value="resend_code">
                <button type="submit" class="w-full py-2.5 rounded-xl text-sm font-semibold text-rmc-800 hover:bg-rmc-50 border border-slate-200 transition">
                    Resend Code
                </button>
            </form>

        <?php else: ?>

            <a href="account.php?mode=register" class="block w-full text-center py-3 rounded-xl text-white font-bold text-sm bg-rmc-800 hover:bg-rmc-900 transition">
                Back to Sign Up
            </a>

        <?php endif; ?>

        <a href="account.php" class="block text-center text-xs text-slate-500 hover:text-slate-700 mt-5">
            <i class="fa-solid fa-arrow-left mr-1"></i> Back to Login
        </a>

    </div>

</div>

<?php include 'partials/footer.php'; ?>