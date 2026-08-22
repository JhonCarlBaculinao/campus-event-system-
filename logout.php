<?php
session_start();

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

/*
|--------------------------------------------------------------------------
| Revoke the single-device session token so the session cannot be reused
|--------------------------------------------------------------------------
*/
if ($user_id) {
    pg_query_params(
        $conn,
        "UPDATE users
         SET session_token = NULL
         WHERE user_id = $1",
        array((int) $user_id)
    );
}

/*
|--------------------------------------------------------------------------
| Clear all session variables
|--------------------------------------------------------------------------
*/
$_SESSION = [];

/*
|--------------------------------------------------------------------------
| Remove the session cookie
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| Destroy the session
|--------------------------------------------------------------------------
*/
session_destroy();

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
| Redirect to Login
|--------------------------------------------------------------------------
*/
header('Location: login.php');
exit;