<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'db_connect.php';
require 'csrf.php';
require 'lang.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$forced  = isset($_GET['forced']) && $_GET['forced'] === '1';

$error = '';
$success = '';

$user_stmt = $pdo->prepare("SELECT user_id, full_name, password, must_change_password FROM users WHERE user_id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verify();

    $current_password = $_POST['current_password'] ?? '';
    $new_password      = $_POST['new_password'] ?? '';
    $confirm_password  = $_POST['confirm_password'] ?? '';

    if ($current_password === '' || $new_password === '' || $confirm_password === '') {
        $error = 'Please fill in all fields.';
    } elseif (!password_verify($current_password, $user['password'])) {
        $error = 'Your current password is incorrect.';
    } elseif (strlen($new_password) < 8) {
        $error = 'New password must be at least 8 characters long.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New password and confirmation do not match.';
    } elseif (password_verify($new_password, $user['password'])) {
        $error = 'New password must be different from your current password.';
    } else {

        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $new_token = bin2hex(random_bytes(32));
        $new_token_hash = hash('sha256', $new_token);

        $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0, session_token = ? WHERE user_id = ?")
            ->execute([$new_hash, $new_token_hash, $user_id]);

        $_SESSION['auth_token'] = $new_token;
        $active_role = $_SESSION['role'] ?? null;
        if ($active_role && isset($_SESSION['rmc_auth'][$active_role]) && is_array($_SESSION['rmc_auth'][$active_role])) {
            $_SESSION['rmc_auth'][$active_role]['auth_token'] = $new_token;
        }

        $pdo->prepare("INSERT INTO notifications (user_id, message, type, is_read) VALUES (?, ?, 'account', 0)")
            ->execute([$user_id, 'Your password was changed successfully.']);

        header("Location: dashboard.php?password_updated=1");
        exit();
    }
}

$page_title = 'Change Password — RMC Events';
?>
<?php include 'partials/head.php'; ?>

<div class="min-h-screen flex items-center justify-center p-6 bg-slate-50">

    <div class="bg-white/90 backdrop-blur-xl shadow-2xl rounded-[26px] border border-slate-200/60 w-full max-w-md p-8 sm:p-10 animate-up">

        <div class="text-center">
            <div class="w-16 h-16 mx-auto rounded-full bg-rmc-50 border border-rmc-100 flex items-center justify-center">
                <i class="fa-solid fa-key text-2xl text-rmc-800"></i>
            </div>
            <h2 class="text-2xl font-bold text-rmc-800 mt-4">
                <?= $forced ? 'Set a New Password' : 'Change Password'; ?>
            </h2>
            <?php if ($forced): ?>
                <p class="text-slate-500 mt-2 text-sm">
                    For your security, you must set a new password before continuing.
                </p>
            <?php endif; ?>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mt-6 bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 text-center text-sm">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="update_password.php<?= $forced ? '?forced=1' : ''; ?>" class="mt-6 space-y-5">

            <?= csrf_field(); ?>

            <div>
                <label for="current_password" class="font-semibold text-slate-700 text-sm">
                    Current / Temporary Password
                </label>
                <input
                    type="password"
                    name="current_password"
                    id="current_password"
                    required
                    autocomplete="current-password"
                    class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                >
            </div>

            <div>
                <label for="new_password" class="font-semibold text-slate-700 text-sm">
                    New Password
                </label>
                <input
                    type="password"
                    name="new_password"
                    id="new_password"
                    required
                    minlength="8"
                    autocomplete="new-password"
                    class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                >
                <p class="text-xs text-slate-400 mt-1">At least 8 characters.</p>
            </div>

            <div>
                <label for="confirm_password" class="font-semibold text-slate-700 text-sm">
                    Confirm New Password
                </label>
                <input
                    type="password"
                    name="confirm_password"
                    id="confirm_password"
                    required
                    minlength="8"
                    autocomplete="new-password"
                    class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                >
            </div>

            <button
                type="submit"
                class="w-full py-3 rounded-xl bg-rmc-800 hover:bg-rmc-900 hover:scale-[1.02] transition duration-300 text-white font-bold text-lg shadow-lg"
            >
                Save New Password
            </button>

        </form>

        <?php if (!$forced): ?>
            <div class="text-center mt-6">
                <a href="dashboard.php" class="text-sm font-semibold text-slate-400 hover:text-rmc-800 transition">
                    <i class="fa-solid fa-arrow-left mr-1"></i> Back to Dashboard
                </a>
            </div>
        <?php endif; ?>

    </div>

</div>

<?php include 'partials/footer.php'; ?>