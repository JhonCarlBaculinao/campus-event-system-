<?php

require 'db_connect.php';

/*
|--------------------------------------------------------------------------
| LOGOUT HANDLER
|--------------------------------------------------------------------------
| Supports both POST and GET requests.
| This makes it compatible with existing Logout links/forms
| throughout the system.
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Store session information before destroying it
|--------------------------------------------------------------------------
*/
$user_id = $_SESSION['user_id'] ?? null;
$logout_role = $_SESSION['role'] ?? null;

/*
|--------------------------------------------------------------------------
| Revoke the single-device session token so the session cannot be reused
|--------------------------------------------------------------------------
*/
if ($user_id) {
    $pdo->prepare("UPDATE users
         SET session_token = NULL
         WHERE user_id = ?")->execute(array((int) $user_id));
}

/*
|--------------------------------------------------------------------------
| Remove ONLY this role's auth slot so other tabs/roles stay signed in.
| Fall back to a full logout when no per-role data exists (legacy flow).
|--------------------------------------------------------------------------
*/
$had_role_slot = false;

if (
    $logout_role !== null &&
    isset($_SESSION['rmc_auth'][$logout_role]) &&
    is_array($_SESSION['rmc_auth'][$logout_role])
) {
    unset($_SESSION['rmc_auth'][$logout_role]);
    $had_role_slot = true;

    foreach (array('user_id', 'role', 'full_name', 'auth_token', 'last_activity', 'login_time') as $key) {
        unset($_SESSION[$key]);
    }

    if (!empty($_SESSION['rmc_auth'])) {
        $_SESSION['active_role'] = array_key_first($_SESSION['rmc_auth']);
    } else {
        unset($_SESSION['active_role']);
    }
}

if (!$had_role_slot) {

    /*
    |--------------------------------------------------------------------------
    | Clear all session variables
    |--------------------------------------------------------------------------
    */
    $_SESSION = [];

}

/*
|--------------------------------------------------------------------------
| Destroy the session (only when no role sessions remain)
|--------------------------------------------------------------------------
| The PHPSESSID cookie is ONLY expired when every role slot is gone,
| otherwise remaining tabs would lose their authentication.
|--------------------------------------------------------------------------
*/
if (
    empty($_SESSION['rmc_auth']) &&
    empty($_SESSION['user_id'])
) {
    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
} else {
    session_write_close();
}

/*
|--------------------------------------------------------------------------
| Prevent browser from showing cached protected pages
|--------------------------------------------------------------------------
*/
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

/*
|--------------------------------------------------------------------------
| Redirect to Landing Page
|--------------------------------------------------------------------------
*/
header('Location: landing.php');
exit;