<?php

include 'db_connect.php';
require 'csrf.php';
require 'lang.php';
require 'totp.php';


$selected_role = 'admin';
$error = '';

if (isset($_GET['2fa']) && $_GET['2fa'] === 'locked') {
    $error = t('twofa_too_many_attempts');
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verify();

    $login = trim($_POST['student_id'] ?? '');
    $password = $_POST['password'] ?? '';

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
    } elseif ($login === '' || $password === '') {
        $error = t('enter_credentials');
    } elseif (!rmc_is_role_allowed($conn, $selected_role)) {
        $error = 'Administrator access is currently disabled by the administrator. Please try again later.';
    } else {

        $query = $pdo->prepare("SELECT * FROM users WHERE student_id = ? AND role = ? LIMIT 1"); $query->execute([$login, $selected_role]);

        if ($query === false) {
            error_log("Login database query failed: " . ($pdo->errorInfo()[2] ?? ''));
            $error = t('login_process_error');
        } else {
            $user = $query->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $any_user = $pdo->prepare("SELECT role FROM users WHERE student_id = ? LIMIT 1"); $any_user->execute([$login]);
                $actual_role =
                    ($any_user !== false && $any_user->rowCount() > 0)
                        ? (($any_user->fetch(PDO::FETCH_NUM) ?: [null])[0]) : null;

                if ($actual_role !== null && $actual_role !== $selected_role) {
                    $role_label = t($actual_role);
                    $error = sprintf(
                        t('wrong_role_hint'),
                        ucfirst($role_label),
                        $role_label
                    );
                } else {
                    $error = sprintf(t('invalid_credentials'), 'Administrator');
                }
            } elseif (strtolower($user['status']) !== 'active') {
                $error = t('account_deactivated_msg');
            } elseif ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
                $remaining_q = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS secs FROM users WHERE user_id = ?");
                $remaining_q->execute([(int) $user['user_id']]);
                $secs = (int) ($remaining_q->fetchColumn() ?? 0);
                $minutes = max(1, (int) ceil($secs / 60));
                $error = sprintf(
                    t('too_many_attempts_cooldown') ?: 'Account temporarily locked. Try again in %d minute(s).', 
                    $minutes
                );
            } elseif (!password_verify($password, $user['password'])) {
                $error = sprintf(t('invalid_credentials'), 'Administrator');
                rmc_rate_record_fail($conn, $rl_key, $rl_window);
                $pdo->prepare("UPDATE users SET failed_attempts = failed_attempts + 1 WHERE user_id = ?")->execute([(int) $user['user_id']]);
                $pdo->prepare("UPDATE users SET locked_until = NOW() + INTERVAL 15 MINUTE WHERE user_id = ? AND failed_attempts >= 5")->execute([(int) $user['user_id']]);
            } else {
                rmc_rate_clear($conn, $rl_key);
                $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?")->execute([(int) $user['user_id']]);

                if (!empty($user['twofa_secret'])) {
                    session_regenerate_id(true);
                    $_SESSION['pending_2fa_user_id'] = (int)$user['user_id'];
                    $_SESSION['pending_2fa_role']    = $user['role'];
                    $_SESSION['pending_2fa_attempts'] = 0;
                    header("Location: login_2fa.php");
                    exit();
                }

                session_regenerate_id(true);

                $session_token = bin2hex(random_bytes(32));
                $pdo->prepare("UPDATE users SET session_token = ? WHERE user_id = ?")->execute(array(hash('sha256', $session_token), (int) $user['user_id']));

                $_SESSION['user_id'] = (int)$user['user_id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['auth_token'] = $session_token;

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
                setcookie('appearance', $login_appearance, [
                    'expires'  => time() + (365 * 24 * 60 * 60),
                    'path'     => '/',
                    'samesite' => 'Lax',
                ]);

                $_SESSION['login_time'] = time();
                $_SESSION['last_activity'] = time();

                setcookie('rmc_tab_role', $selected_role, [
                    'expires'  => 0,
                    'path'     => '/',
                    'samesite' => 'Lax',
                ]);

                header("Location: dashboard.php?rmc_role=" . urlencode($selected_role));
                exit();
            }
        }
    }
}
?>

<?php $page_title = t('title_login'); include 'partials/head.php'; ?>

<style>
    input::-ms-reveal, input::-ms-clear { display: none; }
    input[type="password"]::-webkit-credentials-auto-fill-button { visibility: hidden; display: none !important; pointer-events: none; }
    input[type="password"]::-webkit-textfield-decoration-container { display: none; }

    @keyframes rmcWalk {
        0%   { left: -30px; }
        100% { left: calc(100% + 10px); }
    }
    .rmc-walker {
        position: absolute;
        bottom: 24px;
        width: 22px;
        height: 36px;
        animation: rmcWalk 9s linear infinite;
        z-index: 5;
    }
    .rmc-walker svg { width: 100%; height: 100%; }
    .rmc-walker .leg { animation: rmcLegSwing 0.5s ease-in-out infinite alternate; transform-origin: 11px 20px; }
    .rmc-walker .leg-r { animation-delay: 0.25s; }
    @keyframes rmcLegSwing {
        from { transform: rotate(-18deg); }
        to   { transform: rotate(18deg); }
    }
    .rmc-blob {
        position: absolute;
        border-radius: 50%;
        background: rgba(37,99,235,0.08);
        animation: rmcFloat 10s ease-in-out infinite;
        z-index: 0;
    }
    @keyframes rmcFloat {
        0%, 100% { transform: translate(0,0); }
        50%      { transform: translate(10px,-14px); }
    }
    @media (prefers-reduced-motion: reduce) {
        .rmc-walker, .rmc-blob { animation: none; }
    }
</style>

<div class="min-h-screen flex">

    <!-- LEFT PANEL -->
    <div class="hidden lg:flex w-1/2 relative">
        <img src="img/campus.jpg" alt="Regis Marie College Campus" class="absolute inset-0 w-full h-full object-cover" onerror="this.style.display='none'; document.getElementById('imgFallback').style.display='flex';">
        <div id="imgFallback" class="absolute inset-0 w-full h-full bg-gradient-to-br from-rmc-800 to-rmc-950" style="display:none;"></div>
        <div class="absolute inset-0 bg-rmc-950/70"></div>

        <div class="rmc-walker" aria-hidden="true">
            <svg viewBox="0 0 22 36">
                <circle cx="11" cy="5" r="4.2" fill="#fff" opacity="0.9"/>
                <line x1="11" y1="9" x2="11" y2="21" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity="0.9"/>
                <line x1="11" y1="12" x2="4" y2="18" stroke="#fff" stroke-width="2.5" stroke-linecap="round" opacity="0.9"/>
                <line x1="11" y1="12" x2="18" y2="16" stroke="#fff" stroke-width="2.5" stroke-linecap="round" opacity="0.9"/>
                <line class="leg leg-l" x1="11" y1="21" x2="6" y2="32" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity="0.9"/>
                <line class="leg leg-r" x1="11" y1="21" x2="16" y2="32" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity="0.9"/>
            </svg>
        </div>

        <div class="relative z-10 flex flex-col justify-center px-16 text-white">
            <div class="w-24 h-24 rounded-full bg-white p-2 shadow-lg mb-8 flex items-center justify-center">
                <img src="img/logo.webp" alt="Regis Marie College Logo" class="w-full h-full object-contain">
            </div>
            <h1 class="text-5xl font-bold tracking-tight"><?= t('welcome_back'); ?></h1>
            <p class="mt-6 text-xl opacity-90"><?= t('campus_event_system'); ?></p>
            <p class="mt-4 text-lg opacity-80 max-w-md leading-relaxed"><?= t('login_hero_blurb'); ?></p>
        </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="flex w-full lg:w-1/2 items-center justify-center p-6 sm:p-8">
        <div class="bg-white/90 backdrop-blur-xl shadow-2xl rounded-[26px] border border-slate-200/60 w-full max-w-md p-8 sm:p-10 animate-up relative overflow-hidden">

            <div class="rmc-blob" style="width:70px;height:70px;top:10px;right:24px;" aria-hidden="true"></div>
            <div class="rmc-blob" style="width:40px;height:40px;bottom:100px;left:14px;animation-delay:2s;" aria-hidden="true"></div>

            <div class="relative z-10">

            <!-- LOGO / TITLE -->
            <div class="text-center">
                <div class="w-20 h-20 mx-auto rounded-full bg-white border border-rmc-100 shadow-md flex items-center justify-center">
                    <img src="img/logo.webp" alt="Regis Marie College Logo" class="w-full h-full object-contain">
                </div>
                <h2 class="text-3xl font-bold text-rmc-800 mt-5 tracking-tight"><?= t('regis_marie_college'); ?></h2>
                <p class="text-slate-500 mt-1 text-sm"><?= t('campus_event_system'); ?></p>
            </div>

            <!-- ROLE BADGE -->
            <div class="mt-6 flex justify-center">
                <span class="inline-flex items-center gap-2 bg-rmc-50 text-rmc-800 border border-rmc-200 rounded-full px-4 py-2 text-sm font-semibold">
                    <i class="fa-solid fa-shield-halved"></i>
                    <?= t('admin'); ?> <?= t('login'); ?>
                </span>
            </div>

            <!-- ERROR MESSAGE -->
            <?php if (!empty($error)): ?>
                <div class="mt-6 bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 text-center" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php if ($selected_role === 'admin' && stripos($error ?? '', 'try again') !== false): ?>
                    <a href="admin_unlock.php?locked=1" class="block mt-3 text-sm font-semibold text-rmc-800 hover:text-rmc-900 underline">
                        <?= t('unlock_via_email') ?: 'Unlock via Email'; ?>
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <!-- LOGIN FORM -->
            <form method="POST" action="" class="mt-6 space-y-6" autocomplete="on">
                <?= csrf_field(); ?>
                <input type="hidden" name="role" value="admin">

                <!-- ID / USERNAME -->
                <div>
                    <label for="idInput" class="font-semibold text-slate-700">
                        <?= t('admin_username'); ?>
                    </label>
                    <input type="text" name="student_id" id="idInput" required autocomplete="username" maxlength="100" placeholder="<?= t('placeholder_admin_username'); ?>" class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition">
                </div>

                <!-- PASSWORD -->
                <div>
                    <div class="flex justify-between items-center">
                        <label for="password" class="font-semibold text-slate-700">
                            <?= t('password'); ?>
                        </label>
                        <a href="forgot_password.php" class="text-sm font-semibold text-rmc-800 hover:text-rmc-900">
                            <?= t('forgot_password'); ?>
                        </a>
                    </div>
                    <div class="relative mt-2">
                        <input id="password" type="password" name="password" required autocomplete="current-password" maxlength="255" placeholder="<?= t('placeholder_password'); ?>" class="w-full rounded-xl border border-slate-200 px-5 py-3 pr-20 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition">
                        <button type="button" id="togglePassword" class="absolute right-4 top-1/2 -translate-y-1/2 text-sm font-semibold text-rmc-800 hover:text-rmc-900" aria-label="<?= t('toggle_password'); ?>">
                            <?= t('show'); ?>
                        </button>
                    </div>
                </div>

                <!-- LOGIN BUTTON -->
                <button type="submit" class="w-full py-3 rounded-xl bg-rmc-800 hover:bg-rmc-900 hover:scale-[1.02] transition duration-300 text-white font-bold text-lg shadow-lg">
                    <?= t('login'); ?>
                </button>

            </form>

            <!-- OTHER LOGIN OPTIONS -->
            <div class="mt-8 space-y-3">
                <p class="text-center text-slate-500 text-sm">Or sign in as:</p>
                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    
<a href="login_student.php" class="inline-flex items-center gap-2 bg-white hover:bg-rmc-50 border border-rmc-200 text-rmc-800 px-4 py-2.5 rounded-xl text-sm font-semibold transition"><i class="fa-solid fa-user-graduate"></i> Student Login</a>
<a href="login_organizer.php" class="inline-flex items-center gap-2 bg-white hover:bg-rmc-50 border border-rmc-200 text-rmc-800 px-4 py-2.5 rounded-xl text-sm font-semibold transition"><i class="fa-solid fa-calendar-plus"></i> Organizer Login</a>
                </div>
            </div>

            <!-- BACK TO MAIN LOGIN -->
            <div class="text-center mt-6">
                <a href="login.php" class="text-sm font-semibold text-slate-400 hover:text-rmc-800 transition">
                    <i class="fa-solid fa-arrow-left mr-1"></i> All Roles Login
                </a>
            </div>

            </div>
        </div>
    </div>

</div>

<script>

const togglePassword = document.getElementById('togglePassword');
const pwd = document.getElementById('password');

togglePassword.addEventListener('click', function() {
    if (pwd.type === 'password') {
        pwd.type = 'text';
        this.textContent = <?= json_encode(t('hide')); ?>;
    } else {
        pwd.type = 'password';
        this.textContent = <?= json_encode(t('show')); ?>;
    }
});

</script>

<?php include_once __DIR__ . '/partials/dark_mode.php'; ?>