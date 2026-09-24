<?php

include_once 'db_connect.php';
require_once 'csrf.php';
require_once 'lang.php';
require_once 'send_email.php';
require_once 'email_templates.php';

$success = '';
$error   = '';

/*
|--------------------------------------------------------------------------
| PAGE 3: TOKEN VERIFICATION (GET with token + id)
|--------------------------------------------------------------------------
*/
if (!empty($_GET['token']) && !empty($_GET['id'])) {

    $raw_token = $_GET['token'];
    $user_id   = (int) $_GET['id'];
    $token_hash = hash('sha256', $raw_token);

    $res = $pdo->prepare("SELECT id FROM unlock_tokens
         WHERE token_hash = ?
           AND user_id = ?
           AND used = FALSE
           AND expires_at > NOW()
         LIMIT 1"); $res->execute([$token_hash, $user_id]);

    if ($res && $res->rowCount() > 0) {

        $token_row = $res->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare("UPDATE unlock_tokens SET used = TRUE WHERE id = ?")->execute([(int) $token_row['id']]);

        $admin_ip_rows = $pdo->prepare("SELECT DISTINCT rate_key FROM rate_limits
             WHERE rate_key LIKE 'login_fail_admin:%'"); $admin_ip_rows->execute([]);

        if ($admin_ip_rows && $admin_ip_rows->rowCount() > 0) {
            $pdo->query("DELETE FROM rate_limits WHERE rate_key LIKE 'login_fail_admin:%'");
        }

        $success = t('unlock_account_success') ?: 'Your account has been unlocked. You may now log in.';
        $show_login_link = true;

    } else {
        $error = t('unlock_token_invalid') ?: 'This unlock link is invalid or has expired. Please request a new one.';
        $show_login_link = true;
    }
}

/*
|--------------------------------------------------------------------------
| PAGE 1 & 2: LOCKED-OUT SCREEN + SEND EMAIL (POST)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_GET['locked'])) {

    csrf_verify();

    $client_ip = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '0.0.0.0';
    $client_ip = trim(explode(',', $client_ip)[0]);

    $unlock_rl_key  = "unlock_email:{$client_ip}";
    $unlock_rl_max  = 3;
    $unlock_rl_win  = 3600;

    if (rmc_rate_is_blocked($conn, $unlock_rl_key, $unlock_rl_max, $unlock_rl_win)) {
        $remaining = rmc_rate_remaining($conn, $unlock_rl_key, $unlock_rl_win);
        $minutes   = (int) ceil($remaining / 60);
        $error = sprintf(
            t('unlock_rate_limit_msg') ?: 'Too many unlock requests. Please try again in %d minute(s).',
            $minutes
        );
    } else {

        $admin_id_input = trim($_POST['admin_id'] ?? '');

        if ($admin_id_input === '') {
            $error = t('unlock_enter_id') ?: 'Please enter your Admin ID.';
        } else {

            $user_res = $pdo->prepare("SELECT user_id, full_name, email
                 FROM users
                 WHERE student_id = ?
                   AND role = 'admin'
                 LIMIT 1");
            $user_res->execute([$admin_id_input]);

            if (!$user_res || $user_res->rowCount() === 0) {
                $error = t('unlock_admin_not_found') ?: 'No admin account found with that ID.';
            } else {

                $admin_user = $user_res->fetch(PDO::FETCH_ASSOC);

                if (empty($admin_user['email']) || !filter_var($admin_user['email'], FILTER_VALIDATE_EMAIL)) {
                    $error = t('unlock_no_email') ?: 'No valid email address is associated with this account.';
                } else {

                    rmc_rate_record_fail($conn, $unlock_rl_key, $unlock_rl_win);

                    $raw_token = bin2hex(random_bytes(32));
                    $token_hash = hash('sha256', $raw_token);
                    $expires_at = gmdate('Y-m-d H:i:s', time() + 600);

                    $pdo->prepare("INSERT INTO unlock_tokens (user_id, token_hash, expires_at, used)
                         VALUES (?, ?, ?, FALSE)")
                        ->execute([(int) $admin_user['user_id'], $token_hash, $expires_at]);

                    $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                        . '://' . $_SERVER['HTTP_HOST']
                        . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

                    $unlock_link = $base_url . '/admin_unlock.php?token=' . $raw_token . '&id=' . (int) $admin_user['user_id'];

                    $safe_name = htmlspecialchars($admin_user['full_name'], ENT_QUOTES, 'UTF-8');
                    $safe_link = htmlspecialchars($unlock_link, ENT_QUOTES, 'UTF-8');

                    $inner = '
                        <h2 style="color:' . RMC_EMAIL_MAROON . ';margin-top:0;">
                            Account Unlock Request
                        </h2>
                        <p>
                            Dear <strong>' . $safe_name . '</strong>,
                        </p>
                        <p>
                            An unlock was requested for your admin account.
                            Click the button below to unlock (expires in 10 minutes):
                        </p>
                        <p style="margin:25px 0;">
                            <a
                                    href="' . $safe_link . '"
                                style="
                                    display:inline-block;
                                    padding:12px 22px;
                                    background:' . RMC_EMAIL_MAROON . ';
                                    color:#ffffff;
                                    text-decoration:none;
                                    border-radius:8px;
                                    font-weight:bold;
                                "
                            >
                                Unlock My Account
                            </a>
                        </p>
                        <p>
                            This link will expire in <strong>10 minutes</strong>.
                        </p>
                        <p>
                            If you do not recognize this request, please ignore this email.
                        </p>';

                    $email_body = rmc_email_wrapper($inner);
                    $subject    = 'RMC Events — Account Unlock Request';

                    send_notification_email($admin_user['email'], $subject, $email_body);

                    $success = t('unlock_email_sent') ?: 'An unlock link has been sent to your registered email address. Check your inbox.';
                }
            }
        }
    }
}

$page_title = t('title_unlock_account') ?: 'Account Unlock — RMC Events';
?>
<?php
        include 'partials/head.php'; ?>

<div class="min-h-screen flex items-center justify-center p-6">

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

        <!-- SUCCESS MESSAGE -->

        <?php
        if (!empty($success)): ?>

            <div class="mt-6 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl p-5 text-center" role="alert">

                <div class="flex items-center justify-center mb-3">
                    <i class="fa-solid fa-circle-check text-3xl text-emerald-500"></i>
                </div>

                <p class="font-semibold text-lg">
                    <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                </p>

                <?php
        if (!empty($show_login_link)): ?>

                    <a
                            href="login.php"
                        class="inline-block mt-4 px-6 py-2.5 rounded-xl bg-rmc-800 hover:bg-rmc-900 transition duration-300 text-white font-bold shadow-lg"
                    >
                        <i class="fa-solid fa-right-to-bracket mr-2"></i>
                        <?= t('proceed_to_login') ?: 'Proceed to Login →'; ?>
                    </a>

                <?php
        endif; ?>

            </div>

        <?php
        endif; ?>

        <!-- ERROR MESSAGE -->

        <?php
        if (!empty($error)): ?>

            <div class="mt-6 bg-red-50 border border-red-200 text-red-700 rounded-2xl p-5 text-center" role="alert">

                <div class="flex items-center justify-center mb-3">
                    <i class="fa-solid fa-circle-xmark text-3xl text-red-500"></i>
                </div>

                <p class="font-semibold">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </p>

                <?php
        if (!empty($show_login_link)): ?>

                    <a
                            href="login.php"
                        class="inline-block mt-4 px-6 py-2.5 rounded-xl bg-rmc-800 hover:bg-rmc-900 transition duration-300 text-white font-bold shadow-lg"
                    >
                        <i class="fa-solid fa-right-to-bracket mr-2"></i>
                        <?= t('proceed_to_login') ?: 'Proceed to Login →'; ?>
                    </a>

                <?php
        endif; ?>

            </div>

        <?php
        endif; ?>

        <!-- LOCKOUT SCREEN (no token, no success yet) -->

        <?php
        if (empty($success) && empty($error) && !empty($_GET['locked'])): ?>

            <div class="mt-6 bg-amber-50 border border-amber-200 text-amber-700 rounded-2xl p-5 text-center" role="alert">

                <div class="flex items-center justify-center mb-3">
                    <i class="fa-solid fa-lock text-3xl text-amber-500"></i>
                </div>

                <p class="font-semibold text-lg">
                    <?= t('unlock_account_locked') ?: 'Your account has been temporarily locked.' ?>
                </p>

                <p class="mt-2 text-sm">
                    <?= t('unlock_account_locked_desc') ?: 'Too many failed login attempts. Enter your Admin ID below to receive an unlock link via email.' ?>
                </p>

            </div>

        <?php
        endif; ?>

        <!-- SEND UNLOCK EMAIL FORM -->

        <?php
        if (empty($success) && empty($error) && !empty($_GET['locked'])): ?>

            <form
                method="POST"
                action="admin_unlock.php?locked=1"
                class="mt-8 space-y-6"
            >

                <?= csrf_field(); ?>

                <!-- Admin ID -->

                <div>

                    <label
                        for="admin_id"
                        class="font-semibold text-slate-700"
                    >
                        <?= t('admin_username') ?: 'Admin Username'; ?>
                    </label>

                    <input
                        type="text"
                        name="admin_id"
                        id="admin_id"
                        required
                        maxlength="100"
                        placeholder="<?= t('placeholder_admin_username') ?: 'Enter your Admin Username'; ?>"
                        class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                    >

                </div>

                <!-- SUBMIT BUTTON -->

                <button
                    type="submit"
                    class="w-full py-3 rounded-xl bg-rmc-800 hover:bg-rmc-900 hover:scale-[1.02] transition duration-300 text-white font-bold text-lg shadow-lg"
                >
                    <i class="fa-solid fa-envelope mr-2"></i>
                    <?= t('unlock_send_email') ?: 'Send Unlock Email'; ?>
                </button>

            </form>

        <?php
        endif; ?>

        <!-- INITIAL STATE: no token, no locked, no success/error -->

        <?php
        if (empty($success) && empty($error) && empty($_GET['token']) && empty($_GET['locked'])): ?>

            <div class="mt-6 text-center text-slate-500">
                <p><?= t('unlock_no_action') ?: 'No action required. This page is for account lockout recovery.' ?></p>
            </div>

        <?php
        endif; ?>

        <!-- BACK TO LOGIN -->

        <div class="text-center mt-6">

            <a
                    href="login.php"
                class="text-sm font-semibold text-rmc-800 hover:text-rmc-900"
            >
                <i class="fa-solid fa-arrow-left mr-1"></i>
                <?= t('back_to_login') ?: '← Back to Login'; ?>
            </a>

        </div>

    </div>

</div>

<?php
        include_once __DIR__ . '/partials/dark_mode.php'; ?>