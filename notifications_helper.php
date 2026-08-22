<?php

/*
|--------------------------------------------------------------------------
| NOTIFICATIONS HELPER
|--------------------------------------------------------------------------
| This file handles BOTH:
|
| 1. Website notifications
| 2. Gmail/email notifications
|
| Notifications are role-based.
|--------------------------------------------------------------------------
*/

require_once 'send_email.php';


/*
|--------------------------------------------------------------------------
| Notify ONE user
|--------------------------------------------------------------------------
*/
function notify_user(
    $conn,
    $user_id,
    $message,
    $type,
    $email_subject = null,
    $email_html = null,
    $force_email = false
) {

    // Get user information
    $user_result = pg_query_params(
        $conn,
        "SELECT user_id, full_name, email, email_notifications
         FROM users
         WHERE user_id = $1",
        array($user_id)
    );

    if (!$user_result) {
        return false;
    }

    $user = pg_fetch_assoc($user_result);

    if (!$user) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | WEBSITE NOTIFICATION
    |--------------------------------------------------------------------------
    */

    $notification_result = pg_query_params(
        $conn,
        "INSERT INTO notifications
        (user_id, message, type, is_read)
        VALUES
        ($1, $2, $3, FALSE)",
        array(
            $user_id,
            $message,
            $type
        )
    );


    /*
    |--------------------------------------------------------------------------
    | EMAIL NOTIFICATION
    |--------------------------------------------------------------------------
    | Check email_notifications preference unless forced (e.g. password reset,
    | account verification, 2FA codes).
    |--------------------------------------------------------------------------
    */

    $email_on = ($user['email_notifications'] ?? 'f') === 't';

    if ($force_email || $email_on) {
        if (!empty($user['email'])) {

            if ($email_subject === null) {
                $email_subject = 'Regis Marie College - Campus Event Notification';
            }

            if ($email_html === null) {

                $email_html =
                    '<!DOCTYPE html>
                    <html>
                    <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">

                        <h2>Hello ' .
                        htmlspecialchars($user['full_name']) .
                        '!</h2>

                        <p>' .
                        htmlspecialchars($message) .
                        '</p>

                        <hr>

                        <p>
                            <strong>Regis Marie College</strong><br>
                            Campus Event Management System
                        </p>

                    </body>
                    </html>';
            }

            send_email_deferred(
                $user['email'],
                $email_subject,
                $email_html
            );
        }
    }

    return $notification_result !== false;
}


/*
|--------------------------------------------------------------------------
| Notify ALL users
|--------------------------------------------------------------------------
*/
function notify_all_users(
    $conn,
    $message,
    $type,
    $email_subject = null,
    $email_html_template = null
) {

    $users = pg_query(
        $conn,
        "SELECT user_id, full_name, email, email_notifications
         FROM users
         ORDER BY user_id"
    );

    if (!$users) {
        return false;
    }


    while ($user = pg_fetch_assoc($users)) {

        /*
        |--------------------------------------------------------------------------
        | Website notification
        |--------------------------------------------------------------------------
        */

        pg_query_params(
            $conn,
            "INSERT INTO notifications
            (user_id, message, type, is_read)
            VALUES
            ($1, $2, $3, FALSE)",
            array(
                $user['user_id'],
                $message,
                $type
            )
        );


        /*
        |--------------------------------------------------------------------------
        | Email notification
        |--------------------------------------------------------------------------
        */

        $email_on = ($user['email_notifications'] ?? 'f') === 't';

        if ($email_on && !empty($user['email'])) {

            $subject = $email_subject;

            if ($subject === null) {
                $subject = 'Regis Marie College - Campus Event Notification';
            }


            if ($email_html_template !== null) {

                $email_html = str_replace(
                    array(
                        '{FULL_NAME}',
                        '{MESSAGE}'
                    ),
                    array(
                        htmlspecialchars($user['full_name']),
                        htmlspecialchars($message)
                    ),
                    $email_html_template
                );

            } else {

                $email_html =
                    '<h2>Hello ' .
                    htmlspecialchars($user['full_name']) .
                    '!</h2>

                    <p>' .
                    htmlspecialchars($message) .
                    '</p>

                    <hr>

                    <p>
                        <strong>Regis Marie College</strong><br>
                        Campus Event Management System
                    </p>';
            }


            send_email_deferred(
                $user['email'],
                $subject,
                $email_html
            );
        }
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| Notify users by ROLE
|--------------------------------------------------------------------------
*/
function notify_role(
    $conn,
    $role,
    $message,
    $type,
    $email_subject = null,
    $email_html_template = null
) {

    $users = pg_query_params(
        $conn,
        "SELECT user_id, full_name, email, email_notifications
         FROM users
         WHERE role = $1
         ORDER BY user_id",
        array($role)
    );

    if (!$users) {
        return false;
    }


    while ($user = pg_fetch_assoc($users)) {

        /*
        |--------------------------------------------------------------------------
        | Website notification
        |--------------------------------------------------------------------------
        */

        pg_query_params(
            $conn,
            "INSERT INTO notifications
            (user_id, message, type, is_read)
            VALUES
            ($1, $2, $3, FALSE)",
            array(
                $user['user_id'],
                $message,
                $type
            )
        );


        /*
        |--------------------------------------------------------------------------
        | Gmail notification
        |--------------------------------------------------------------------------
        */

        $email_on = ($user['email_notifications'] ?? 'f') === 't';

        if ($email_on && !empty($user['email'])) {

            $subject = $email_subject;

            if ($subject === null) {
                $subject = 'Regis Marie College - Campus Event Notification';
            }


            if ($email_html_template !== null) {

                $email_html = str_replace(
                    array(
                        '{FULL_NAME}',
                        '{MESSAGE}'
                    ),
                    array(
                        htmlspecialchars($user['full_name']),
                        htmlspecialchars($message)
                    ),
                    $email_html_template
                );

            } else {

                $email_html =
                    '<h2>Hello ' .
                    htmlspecialchars($user['full_name']) .
                    '!</h2>

                    <p>' .
                    htmlspecialchars($message) .
                    '</p>

                    <hr>

                    <p>
                        <strong>Regis Marie College</strong><br>
                        Campus Event Management System
                    </p>';
            }


            send_email_deferred(
                $user['email'],
                $subject,
                $email_html
            );
        }
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| Notify ALL STUDENTS
|--------------------------------------------------------------------------
*/
function notify_students(
    $conn,
    $message,
    $type,
    $email_subject = null,
    $email_html_template = null
) {

    return notify_role(
        $conn,
        'student',
        $message,
        $type,
        $email_subject,
        $email_html_template
    );
}


/*
|--------------------------------------------------------------------------
| Notify ALL ORGANIZERS
|--------------------------------------------------------------------------
*/
function notify_organizers(
    $conn,
    $message,
    $type,
    $email_subject = null,
    $email_html_template = null
) {

    return notify_role(
        $conn,
        'organizer',
        $message,
        $type,
        $email_subject,
        $email_html_template
    );
}


/*
|--------------------------------------------------------------------------
| Notify ALL ADMINS
|--------------------------------------------------------------------------
*/
function notify_admins(
    $conn,
    $message,
    $type,
    $email_subject = null,
    $email_html_template = null
) {

    return notify_role(
        $conn,
        'admin',
        $message,
        $type,
        $email_subject,
        $email_html_template
    );
}

?>