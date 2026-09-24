<?php


require 'db_connect.php';
require 'csrf.php';
require 'lang.php';

$error = "";
$success = "";
$token_invalid = false;

$token = trim($_GET['token'] ?? '');

if ($token !== '') {

    /*
    |--------------------------------------------------------------------------
    | HASH THE SUPPLIED RAW TOKEN
    |--------------------------------------------------------------------------
    | forgot_password.php stores hash('sha256', raw_token) in the database,
    | so we must hash the token from the URL before searching.
    |--------------------------------------------------------------------------
    */

    $token_hash = hash('sha256', $token);

    /*
    |--------------------------------------------------------------------------
    | FIND TOKEN
    |--------------------------------------------------------------------------
    */

    $query = $pdo->prepare("SELECT
            user_id, full_name,
            reset_token_expiry
         FROM users
         WHERE reset_token = ?
         LIMIT 1");
    $query->execute([$token_hash]);

    if (!$query) {

        error_log(
            "Password reset token lookup failed: " .
            ($pdo->errorInfo()[2] ?? '')
        );

        $token_invalid = true;
        $error = t('request_process_error');

    } else {

        $user = $query->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $token_invalid = true;
            $error = t('reset_link_invalid');
        }

        /*
        |--------------------------------------------------------------------------
        | CHECK EXPIRATION
        |--------------------------------------------------------------------------
        */

        if (
            $user && (
                empty($user['reset_token_expiry']) ||
                strtotime($user['reset_token_expiry']) < time()
            )
        ) {

            $token_invalid = true;
            $error = t('reset_link_expired');

        }
    }
} else {

    $token_invalid = true;
    $error = t('reset_link_invalid');
}

/*
|--------------------------------------------------------------------------
| HANDLE PASSWORD RESET
|--------------------------------------------------------------------------
*/

if (
    !$token_invalid &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    csrf_verify();

    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {

        $error = t('password_min_msg');

    } elseif ($password !== $confirm_password) {

        $error = t('passwords_mismatch_msg');

    } else {

        $hashed = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        if ($hashed === false) {

            $error = t('password_hash_error2');

        } else {

            /*
            |--------------------------------------------------------------------------
            | UPDATE PASSWORD
            |--------------------------------------------------------------------------
            |
            | The token is cleared immediately after successful reset.
            |
            */

            $update = $pdo->prepare("UPDATE users
                 SET password = ?, reset_token = NULL,
                     reset_token_expiry = NULL,
                     session_token = NULL
                 WHERE user_id = ?
                   AND reset_token = ?");
            $update->execute([
                $hashed,
                $user['user_id'],
                $token_hash
            ]);

            if (!$update) {

                error_log(
                    "Password reset update failed: " .
                    ($pdo->errorInfo()[2] ?? '')
                );

                $error = t('password_change_error');

            } elseif ($update->rowCount() !== 1) {

                $error = t('reset_link_invalid');

            } else {

                $success = t('password_changed_msg');
            }
        }
    }
}
?>

<?php $page_title = t('title_reset_password'); include 'partials/head.php'; ?>


<div class="min-h-screen bg-gradient-to-br from-rmc-50 via-white to-rmc-100 flex items-center justify-center p-5">

    <div class="bg-white/90 backdrop-blur-xl shadow-2xl rounded-[26px] border border-slate-200/60 w-full max-w-md p-8 sm:p-10 animate-up">

        <div class="text-center">

            <div class="w-20 h-20 mx-auto rounded-full bg-white border border-rmc-100 shadow-md flex items-center justify-center">

                <img
                    src="img/logo.webp"
                    class="w-full h-full object-contain"
                    alt="Regis Marie College Logo"
                >

            </div>

            <h2 class="mt-5 text-3xl font-bold text-rmc-800 tracking-tight">
                <?= t('create_new_password'); ?>
            </h2>

            <p class="text-slate-500 mt-2 text-sm">
                <?= t('reset_desc'); ?>
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

            <div class="text-center mt-6">

                <a
                        href="login.php"
                    class="font-bold text-rmc-800 hover:text-rmc-900"
                >
                    <?= t('return_to_login'); ?>
                </a>

            </div>

        <?php elseif (!$token_invalid): ?>

            <form method="POST" class="mt-8 space-y-5">

                <?= csrf_field(); ?>

                <div>

                    <label
                        for="password"
                        class="font-semibold text-slate-700"
                    >
                        <?= t('new_password'); ?>
                    </label>

                    <input
                        id="password"
                        type="password"
                        name="password"
                        required
                        minlength="8"
                        autocomplete="new-password"
                        class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                    >

                </div>

                <div>

                    <label
                        for="confirm_password"
                        class="font-semibold text-slate-700"
                    >
                        <?= t('confirm_password'); ?>
                    </label>

                    <input
                        id="confirm_password"
                        type="password"
                        name="confirm_password"
                        required
                        minlength="8"
                        autocomplete="new-password"
                        class="mt-2 w-full rounded-xl border border-slate-200 px-5 py-3 bg-slate-50 focus:bg-white focus:ring-4 focus:ring-rmc-200 focus:border-rmc-300 outline-none transition"
                    >

                </div>

                <div class="flex items-center gap-2">

                    <input
                        type="checkbox"
                        id="showPassword"
                        onclick="togglePassword()"
                        class="w-4 h-4 rounded border-slate-300"
                    >

                    <label
                        for="showPassword"
                        class="text-sm text-slate-600"
                    >
                        <?= t('show_password'); ?>
                    </label>

                </div>

                <button
                    type="submit"
                    class="w-full py-3 rounded-xl bg-rmc-800 hover:bg-rmc-900 text-white font-bold hover:scale-[1.02] transition"
                >
                    <?= t('save_new_password'); ?>
                </button>

            </form>

        <?php else: ?>

            <div class="text-center mt-6">

                <a
                        href="forgot_password.php"
                    class="inline-block w-full bg-rmc-800 hover:bg-rmc-900 text-white font-bold py-3 rounded-xl shadow-lg transition"
                >
                    <?= t('send_reset_link'); ?>
                </a>

            </div>

        <?php endif; ?>

        <div class="text-center mt-8">

            <a
                    href="login.php"
                class="font-semibold text-rmc-800 hover:text-rmc-900"
            >
                <?= t('back_to_login'); ?>
            </a>

        </div>

    </div>

</div>


<script>

function togglePassword() {

    const password =
        document.getElementById("password");

    const confirm =
        document.getElementById("confirm_password");

    const type =
        password.type === "password"
            ? "text"
            : "password";

    password.type = type;
    confirm.type = type;
}

</script>


<?php include_once __DIR__ . '/partials/dark_mode.php'; ?>