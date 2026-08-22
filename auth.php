<?php

/*
|--------------------------------------------------------------------------
| Authentication & Role Protection
|--------------------------------------------------------------------------
| Centralized security functions for the Campus Event Management System.
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| Require Login
|--------------------------------------------------------------------------
*/

function require_login()
{
    if (!isset($_SESSION['user_id'])) {

        header("Location: login.php");
        exit();

    }
}


/*
|--------------------------------------------------------------------------
| Require Specific Role
|--------------------------------------------------------------------------
|
| Usage:
|
| require_role('student');
| require_role('organizer');
| require_role('admin');
|
|--------------------------------------------------------------------------
*/

function require_role($required_role)
{
    require_login();

    if (
        !isset($_SESSION['role']) ||
        $_SESSION['role'] !== $required_role
    ) {

        http_response_code(403);

        ?>

        <!DOCTYPE html>
        <html lang="en">

        <head>

            <meta charset="UTF-8">

            <meta
                name="viewport"
                content="width=device-width, initial-scale=1.0"
            >

            <title>Access Denied</title>

            <script src="https://cdn.tailwindcss.com"></script>

        </head>

        <body class="min-h-screen bg-slate-100 flex items-center justify-center p-6">

            <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full p-10 text-center">

                <div class="text-6xl mb-6">
                    🔒
                </div>

                <h1 class="text-3xl font-bold text-red-700 mb-3">
                    Access Denied
                </h1>

                <p class="text-gray-600 mb-8">
                    You do not have permission to access this page.
                </p>

                <a
                    href="dashboard.php"
                    class="inline-block bg-gradient-to-r from-red-700 to-red-900 text-white font-bold px-6 py-3 rounded-xl hover:opacity-90 transition"
                >
                    Return to Dashboard
                </a>

            </div>

        </body>

        </html>

        <?php

        exit();
    }
}


/*
|--------------------------------------------------------------------------
| Get Current Role
|--------------------------------------------------------------------------
*/

function current_role()
{
    return $_SESSION['role'] ?? null;
}


/*
|--------------------------------------------------------------------------
| Check Whether User Has a Role
|--------------------------------------------------------------------------
*/

function has_role($role)
{
    return isset($_SESSION['role']) &&
           $_SESSION['role'] === $role;
}


/*
|--------------------------------------------------------------------------
| Get Current User ID
|--------------------------------------------------------------------------
*/

function current_user_id()
{
    return $_SESSION['user_id'] ?? null;
}