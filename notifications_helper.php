<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| NOTIFICATIONS HELPER
| Handles website notifications AND Gmail/email notifications.
|--------------------------------------------------------------------------
*/

require_once 'send_email.php';

/*
|--------------------------------------------------------------------------
| Helper: treat 't', '1', 1, true as "on" (covers Postgres->MySQL mix)
|--------------------------------------------------------------------------
*/
function rmc_pref_is_on($value): bool
{
    return in_array($value, ['t', '1', 1, true], true);
}

/*
|--------------------------------------------------------------------------
| Generate a random temporary password (organizer approval, etc.)
| Guarantees the password satisfies the shared application policy
| (>= 8 chars, at least one uppercase, one lowercase, one digit and one
| special symbol). Character set omits 0/O/1/l/I for readability.
|--------------------------------------------------------------------------
*/
function rmc_generate_temp_password($length = 10)
{
    if ($length < 8) {
        $length = 8;
    }

    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // no O/I
    $lower = 'abcdefghijkmnopqrstuvwxyz'; // no l/i
    $digits = '23456789'; // no 0/1
    $symbols = '@#$%&*!?';

    $password = $upper[random_int(0, strlen($upper) - 1)]
        . $lower[random_int(0, strlen($lower) - 1)]
        . $digits[random_int(0, strlen($digits) - 1)]
        . $symbols[random_int(0, strlen($symbols) - 1)];

    $all = $upper . $lower . $digits . $symbols;
    for ($i = strlen($password); $i < $length; $i++) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }

    return str_shuffle($password);
}

/*
|--------------------------------------------------------------------------
| Notify ONE user
|--------------------------------------------------------------------------
*/
function notify_user($conn, $user_id, $message, $type, $email_subject = null, $email_html = null, $force_email = false)
{
    global $pdo;

    $stmt = $pdo->prepare(
        "SELECT user_id, full_name, email, email_notifications
         FROM users
         WHERE user_id = ?"
    );
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return false;
    }

    // Website notification
    $notif_stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, message, type, is_read)
         VALUES (?, ?, ?, 0)"
    );
    $notification_result = $notif_stmt->execute([$user_id, $message, $type]);

    // Email notification
    $email_on = rmc_pref_is_on($user['email_notifications'] ?? 'f');

    if (($force_email || $email_on) && !empty($user['email'])) {

        if ($email_subject === null) {
            $email_subject = 'Regis Marie College - Campus Event Notification';
        }

        if ($email_html === null) {
            $email_html =
                '<!DOCTYPE html><html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
                    <h2>Hello ' . htmlspecialchars($user['full_name']) . '!</h2>
                    <p>' . htmlspecialchars($message) . '</p>
                    <hr>
                    <p><strong>Regis Marie College</strong><br>Campus Event Management System</p>
                 </body></html>';
        }

        send_notification_email($user['email'], $email_subject, $email_html);
    }

    return $notification_result !== false;
}

/*
|--------------------------------------------------------------------------
| Notify ALL users
|--------------------------------------------------------------------------
*/
function notify_all_users($conn, $message, $type, $email_subject = null, $email_html_template = null)
{
    global $pdo;

    $users = $pdo->query(
        "SELECT user_id, full_name, email, email_notifications FROM users ORDER BY user_id"
    );

    if (!$users) {
        return false;
    }

    while ($user = $users->fetch(PDO::FETCH_ASSOC)) {

        $pdo->prepare(
            "INSERT INTO notifications (user_id, message, type, is_read) VALUES (?, ?, ?, 0)"
        )->execute([$user['user_id'], $message, $type]);

        $email_on = rmc_pref_is_on($user['email_notifications'] ?? 'f');

        if ($email_on && !empty($user['email'])) {

            $subject = $email_subject ?? 'Regis Marie College - Campus Event Notification';

            if ($email_html_template !== null) {
                $email_html = str_replace(
                    ['{FULL_NAME}', '{MESSAGE}'],
                    [htmlspecialchars($user['full_name']), htmlspecialchars($message)],
                    $email_html_template
                );
            } else {
                $email_html =
                    '<h2>Hello ' . htmlspecialchars($user['full_name']) . '!</h2>
                     <p>' . htmlspecialchars($message) . '</p>
                     <hr>
                     <p><strong>Regis Marie College</strong><br>Campus Event Management System</p>';
            }

            send_notification_email($user['email'], $subject, $email_html);
        }
    }

    return true;
}

/*
|--------------------------------------------------------------------------
| Notify users by ROLE
|--------------------------------------------------------------------------
*/
function notify_role($conn, $role, $message, $type, $email_subject = null, $email_html_template = null)
{
    global $pdo;

    $stmt = $pdo->prepare(
        "SELECT user_id, full_name, email, email_notifications
         FROM users
         WHERE role = ?
         ORDER BY user_id"
    );
    $stmt->execute([$role]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as $user) {

        $pdo->prepare(
            "INSERT INTO notifications (user_id, message, type, is_read) VALUES (?, ?, ?, 0)"
        )->execute([$user['user_id'], $message, $type]);

        $email_on = rmc_pref_is_on($user['email_notifications'] ?? 'f');

        if ($email_on && !empty($user['email'])) {

            $subject = $email_subject ?? 'Regis Marie College - Campus Event Notification';

            if ($email_html_template !== null) {
                $email_html = str_replace(
                    ['{FULL_NAME}', '{MESSAGE}'],
                    [htmlspecialchars($user['full_name']), htmlspecialchars($message)],
                    $email_html_template
                );
            } else {
                $email_html =
                    '<h2>Hello ' . htmlspecialchars($user['full_name']) . '!</h2>
                     <p>' . htmlspecialchars($message) . '</p>
                     <hr>
                     <p><strong>Regis Marie College</strong><br>Campus Event Management System</p>';
            }

            send_notification_email($user['email'], $subject, $email_html);
        }
    }

    return true;
}

/*
|--------------------------------------------------------------------------
| Role shortcuts
|--------------------------------------------------------------------------
*/
function notify_students($conn, $message, $type, $email_subject = null, $email_html_template = null)
{
    return notify_role($conn, 'student', $message, $type, $email_subject, $email_html_template);
}

function notify_organizers($conn, $message, $type, $email_subject = null, $email_html_template = null)
{
    return notify_role($conn, 'organizer', $message, $type, $email_subject, $email_html_template);
}

function notify_admins($conn, $message, $type, $email_subject = null, $email_html_template = null)
{
    return notify_role($conn, 'admin', $message, $type, $email_subject, $email_html_template);
}