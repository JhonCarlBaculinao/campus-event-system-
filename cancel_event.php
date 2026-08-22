<?php

session_start();

require 'db_connect.php';
require 'send_email.php';
require 'csrf.php';

/*
|--------------------------------------------------------------------------
| SECURITY CHECK
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {

    header("Location: login.php");
    exit();

}

if (($_SESSION['role'] ?? '') !== 'organizer') {

    http_response_code(403);

    die("Access denied. Organizers only.");

}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header("Location: dashboard.php");
    exit();

}

csrf_verify();

/*
|--------------------------------------------------------------------------
| GET DATA
|--------------------------------------------------------------------------
*/

$organizer_id = (int) $_SESSION['user_id'];

$event_id = isset($_POST['event_id'])
    ? (int) $_POST['event_id']
    : 0;

if ($event_id <= 0) {

    die("Invalid event.");

}

/*
|--------------------------------------------------------------------------
| GET EVENT
|--------------------------------------------------------------------------
*/

$event_result = pg_query_params(
    $conn,
    "SELECT
        e.event_id,
        e.title,
        e.event_date,
        e.venue,
        e.status,
        e.organizer_id,
        u.full_name AS organizer_name,
        u.email AS organizer_email
     FROM events e
     JOIN users u
       ON e.organizer_id = u.user_id
     WHERE e.event_id = $1
       AND e.organizer_id = $2
     LIMIT 1",
    [
        $event_id,
        $organizer_id
    ]
);

if (!$event_result) {

    error_log(
        "Cancel event lookup failed: " .
        pg_last_error($conn)
    );

    die("Unable to process the event.");

}

if (pg_num_rows($event_result) === 0) {

    http_response_code(404);

    die(
        "Event not found or you do not have permission to cancel this event."
    );

}

$event = pg_fetch_assoc($event_result);

/*
|--------------------------------------------------------------------------
| ONLY APPROVED EVENTS
|--------------------------------------------------------------------------
*/

if ($event['status'] !== 'approved') {

    die("Only approved events can be cancelled.");

}

/*
|--------------------------------------------------------------------------
| GET STUDENTS BEFORE TRANSACTION
|--------------------------------------------------------------------------
|
| We gather email recipients first.
| Emails will be sent AFTER COMMIT.
|
*/

$students = pg_query_params(
    $conn,
    "SELECT DISTINCT
        u.user_id,
        u.email,
        u.full_name
     FROM registrations r
     JOIN users u
       ON r.user_id = u.user_id
     WHERE r.event_id = $1
       AND u.role = 'student'",
    [$event_id]
);

if (!$students) {

    error_log(
        "Cancel event student lookup failed: " .
        pg_last_error($conn)
    );

    die("Unable to process event notifications.");

}

$student_recipients = [];

while ($student = pg_fetch_assoc($students)) {

    $student_recipients[] = $student;

}

/*
|--------------------------------------------------------------------------
| GET ADMINS BEFORE TRANSACTION
|--------------------------------------------------------------------------
*/

$admins_result = pg_query(
    $conn,
    "SELECT
        user_id,
        email,
        full_name
     FROM users
     WHERE role = 'admin'"
);

if (!$admins_result) {

    error_log(
        "Cancel event admin lookup failed: " .
        pg_last_error($conn)
    );

    die("Unable to process event notifications.");

}

$admin_recipients = [];

while ($admin = pg_fetch_assoc($admins_result)) {

    $admin_recipients[] = $admin;

}

/*
|--------------------------------------------------------------------------
| START TRANSACTION
|--------------------------------------------------------------------------
*/

if (!pg_query($conn, "BEGIN")) {

    die("Unable to start the cancellation process.");

}

try {

    /*
    |--------------------------------------------------------------------------
    | CANCEL EVENT
    |--------------------------------------------------------------------------
    */

    $update = pg_query_params(
        $conn,
        "UPDATE events
         SET status = 'cancelled'
         WHERE event_id = $1
           AND organizer_id = $2
           AND status = 'approved'",
        [
            $event_id,
            $organizer_id
        ]
    );

    if (!$update) {

        throw new Exception(
            "Database error while cancelling event."
        );

    }

    if (pg_affected_rows($update) !== 1) {

        throw new Exception(
            "Event could not be cancelled."
        );

    }

    /*
    |--------------------------------------------------------------------------
    | ORGANIZER NOTIFICATION
    |--------------------------------------------------------------------------
    */

    $organizer_message =
        'Your event "' .
        $event['title'] .
        '" has been cancelled.';

    $notification = pg_query_params(
        $conn,
        "INSERT INTO notifications
        (
            user_id,
            message,
            type,
            is_read,
            created_at
        )
        VALUES
        (
            $1,
            $2,
            'event_cancelled',
            false,
            NOW()
        )",
        [
            $organizer_id,
            $organizer_message
        ]
    );

    if (!$notification) {

        throw new Exception(
            "Unable to create organizer notification."
        );

    }

    /*
    |--------------------------------------------------------------------------
    | STUDENT NOTIFICATIONS
    |--------------------------------------------------------------------------
    */

    foreach ($student_recipients as $student) {

        $student_message =
            'Event "' .
            $event['title'] .
            '" has been cancelled by the organizer.';

        $notification = pg_query_params(
            $conn,
            "INSERT INTO notifications
            (
                user_id,
                message,
                type,
                is_read,
                created_at
            )
            VALUES
            (
                $1,
                $2,
                'event_cancelled',
                false,
                NOW()
            )",
            [
                $student['user_id'],
                $student_message
            ]
        );

        if (!$notification) {

            throw new Exception(
                "Unable to create student notification."
            );

        }

    }

    /*
    |--------------------------------------------------------------------------
    | ADMIN NOTIFICATIONS
    |--------------------------------------------------------------------------
    */

    foreach ($admin_recipients as $admin) {

        $admin_message =
            'Event "' .
            $event['title'] .
            '" has been cancelled by organizer "' .
            $event['organizer_name'] .
            '".';

        $notification = pg_query_params(
            $conn,
            "INSERT INTO notifications
            (
                user_id,
                message,
                type,
                is_read,
                created_at
            )
            VALUES
            (
                $1,
                $2,
                'event_cancelled',
                false,
                NOW()
            )",
            [
                $admin['user_id'],
                $admin_message
            ]
        );

        if (!$notification) {

            throw new Exception(
                "Unable to create admin notification."
            );

        }

    }

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    if (!pg_query($conn, "COMMIT")) {

        throw new Exception(
            "Unable to commit event cancellation."
        );

    }

} catch (Throwable $e) {

    pg_query($conn, "ROLLBACK");

    error_log(
        "Event cancellation failed: " .
        $e->getMessage()
    );

    die(
        "Unable to cancel the event. Please try again."
    );

}

/*
|--------------------------------------------------------------------------
| SEND EMAILS AFTER DATABASE COMMIT
|--------------------------------------------------------------------------
|
| Email failure will NOT undo the event cancellation.
|
*/

/*
|--------------------------------------------------------------------------
| ORGANIZER EMAIL
|--------------------------------------------------------------------------
*/

if (!empty($event['organizer_email'])) {

    $organizer_email_message =
        '<h2>Event Cancelled</h2>

        <p>Hello ' .
        htmlspecialchars(
            $event['organizer_name'],
            ENT_QUOTES,
            'UTF-8'
        ) .
        ',</p>

        <p>Your event
        <strong>' .
        htmlspecialchars(
            $event['title'],
            ENT_QUOTES,
            'UTF-8'
        ) .
        '</strong>
        has been cancelled.</p>';

    if (!send_email_deferred(
        $event['organizer_email'],
        'Event Cancelled',
        $organizer_email_message
    )) {

        error_log(
            "Organizer cancellation email failed."
        );

    }

}

/*
|--------------------------------------------------------------------------
| STUDENT EMAILS
|--------------------------------------------------------------------------
*/

foreach ($student_recipients as $student) {

    if (empty($student['email'])) {
        continue;
    }

    $message =
        '<h2>Event Cancelled</h2>

        <p>Hello ' .
        htmlspecialchars(
            $student['full_name'],
            ENT_QUOTES,
            'UTF-8'
        ) .
        ',</p>

        <p>
        We would like to inform you that the event
        <strong>' .
        htmlspecialchars(
            $event['title'],
            ENT_QUOTES,
            'UTF-8'
        ) .
        '</strong>
        scheduled for
        <strong>' .
        htmlspecialchars(
            $event['event_date'],
            ENT_QUOTES,
            'UTF-8'
        ) .
        '</strong>
        at
        <strong>' .
        htmlspecialchars(
            $event['venue'],
            ENT_QUOTES,
            'UTF-8'
        ) .
        '</strong>
        has been cancelled.
        </p>

        <p>
        Please check the Campus Event System
        for other available events.
        </p>';

    if (!send_email_deferred(
        $student['email'],
        'Event Cancelled - ' . $event['title'],
        $message
    )) {

        error_log(
            "Student cancellation email failed for user " .
            $student['user_id']
        );

    }

}

/*
|--------------------------------------------------------------------------
| ADMIN EMAILS
|--------------------------------------------------------------------------
*/

foreach ($admin_recipients as $admin) {

    if (empty($admin['email'])) {
        continue;
    }

    $message =
        '<h2>Event Cancellation Notice</h2>

        <p>Hello ' .
        htmlspecialchars(
            $admin['full_name'],
            ENT_QUOTES,
            'UTF-8'
        ) .
        ',</p>

        <p>
        The organizer
        <strong>' .
        htmlspecialchars(
            $event['organizer_name'],
            ENT_QUOTES,
            'UTF-8'
        ) .
        '</strong>
        has cancelled the event
        <strong>' .
        htmlspecialchars(
            $event['title'],
            ENT_QUOTES,
            'UTF-8'
        ) .
        '</strong>.
        </p>

        <p>
        Event Date:
        <strong>' .
        htmlspecialchars(
            $event['event_date'],
            ENT_QUOTES,
            'UTF-8'
        ) .
        '</strong>
        </p>

        <p>
        Venue:
        <strong>' .
        htmlspecialchars(
            $event['venue'],
            ENT_QUOTES,
            'UTF-8'
        ) .
        '</strong>
        </p>';

    if (!send_email_deferred(
        $admin['email'],
        'Event Cancelled - Admin Notification',
        $message
    )) {

        error_log(
            "Admin cancellation email failed for user " .
            $admin['user_id']
        );

    }

}

/*
|--------------------------------------------------------------------------
| REDIRECT
|--------------------------------------------------------------------------
*/

header("Location: dashboard.php?cancelled=1");
exit();

?>