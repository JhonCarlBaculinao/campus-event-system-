<?php

/*
|--------------------------------------------------------------------------
| RMC Events — Event Reminder Processor (cron-ready)
|--------------------------------------------------------------------------
| Sends automatic reminders to registered students 24 hours and 1 hour
| before an approved event starts.
|
| SAFETY / IDEMPOTENCY
|   Each reminder is claimed atomically with
|       INSERT ... ON CONFLICT (registration_id, reminder_type) DO NOTHING
|   so this script can be run as often as you like without ever sending a
|   duplicate reminder. Concurrent runs are also safe.
|
| DEPLOYMENT
|   Trigger it on a schedule (every 5-15 minutes is fine):
|     Linux cron:
|       0,5,10,15,20,25,30,35,40,45,50,55 * * * * /usr/bin/php /path/to/campus_event_system/cron/send_reminders.php
|     Windows Task Scheduler:
|       php C:\xampp\htdocs\campus_event_system\cron\send_reminders.php
|   The script refuses to run over HTTP (CLI only), so it cannot be
|   triggered from the browser.
|
| LANGUAGE NOTE
|   Notifications are rendered in the default application language (en)
|   because the processor has no user session. To localize reminder text
|   per user, add a `lang` column to users and set it at login — the keys
|   used here are already available in all 7 languages.
|--------------------------------------------------------------------------
*/

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__ . '/../db_connect.php';
require __DIR__ . '/../lang.php';
require __DIR__ . '/../send_email.php';

date_default_timezone_set('Asia/Manila');

session_start();

/*
|--------------------------------------------------------------------------
| Message templates (rendered in the default app language 'en')
|--------------------------------------------------------------------------
*/

$subject_24h = t('reminder_subject') . ' - ' . t('reminder_24h_badge');
$subject_1h  = t('reminder_subject') . ' - ' . t('reminder_1h_badge');

/*
|--------------------------------------------------------------------------
| Candidates: approved events, confirmed registrations, still upcoming
|--------------------------------------------------------------------------
*/

$candidates = pg_query(
    $conn,
    "SELECT
        r.registration_id,
        r.user_id,
        e.event_id,
        e.title,
        e.event_date,
        e.start_time,
        e.venue,
        u.full_name,
        u.email,
        u.email_notifications,
        (e.event_date + e.start_time) AS starts_at
     FROM registrations r
     JOIN events e ON e.event_id = r.event_id
     JOIN users u ON u.user_id = r.user_id
     WHERE e.status = 'approved'
       AND r.status = 'registered'
       AND (e.event_date + e.start_time) > NOW()"
);

if (!$candidates) {
    fwrite(STDERR, "Query failed: " . pg_last_error($conn) . PHP_EOL);
    exit(1);
}

$sent_count = 0;
$email_count = 0;

while ($event = pg_fetch_assoc($candidates)) {

    $now_ts = time();
    $start_ts = strtotime($event['starts_at']);
    $diff_seconds = $start_ts - $now_ts;

    $types = [];

    /* 24-hour reminder: fires when between 1h and 24h remain */
    if ($diff_seconds > 3600 && $diff_seconds <= 86400) {
        $types[] = '24h';
    }

    /* 1-hour reminder: fires when 1 hour or less remains (still future) */
    if ($diff_seconds > 0 && $diff_seconds <= 3600) {
        $types[] = '1h';
    }

    foreach ($types as $reminder_type) {

        /*
        |--------------------------------------------------------------------------
        | Atomically claim this reminder
        |--------------------------------------------------------------------------
        */

        $claim = pg_query_params(
            $conn,
            "INSERT INTO event_reminders (registration_id, reminder_type)
             VALUES ($1, $2)
             ON CONFLICT (registration_id, reminder_type) DO NOTHING",
            [$event['registration_id'], $reminder_type]
        );

        if (!$claim) {
            fwrite(STDERR, "Claim failed: " . pg_last_error($conn) . PHP_EOL);
            continue;
        }

        if (pg_affected_rows($claim) !== 1) {
            /* Already sent (or claimed by a concurrent run) — skip. */
            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Build the message
        |--------------------------------------------------------------------------
        */

        $time_str = date('g:i A', strtotime($event['start_time']));

        if ($reminder_type === '24h') {
            $message = str_replace(
                ['{title}', '{time}', '{venue}'],
                [$event['title'], $time_str, $event['venue']],
                t('reminder_24h_msg')
            );
            $subject = $subject_24h;
        } else {
            $message = str_replace(
                ['{title}', '{time}', '{venue}'],
                [$event['title'], $time_str, $event['venue']],
                t('reminder_1h_msg')
            );
            $subject = $subject_1h;
        }

        /*
        |--------------------------------------------------------------------------
        | In-app notification (always)
        |--------------------------------------------------------------------------
        */

        $notify = pg_query_params(
            $conn,
            "INSERT INTO notifications (user_id, message, type, is_read)
             VALUES ($1, $2, 'event_reminder', FALSE)",
            [$event['user_id'], $message]
        );

        if (!$notify) {
            fwrite(STDERR, "Notification insert failed: " . pg_last_error($conn) . PHP_EOL);
            continue;
        }

        $sent_count++;

        /*
        |--------------------------------------------------------------------------
        | Email (only when the student enabled email notifications)
        |--------------------------------------------------------------------------
        */

        $email_enabled =
            ($event['email_notifications'] ?? 'f') === 't';

        if ($email_enabled && !empty($event['email'])) {

            $email_html =
                '<!DOCTYPE html>
                <html>
                <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">

                    <h2>' .
                    htmlspecialchars(t('reminder_email_hello') . ' ' . $event['full_name'] . '!') .
                    '</h2>

                    <p>' .
                    htmlspecialchars($message) .
                    '</p>

                    <p>
                        <strong>' .
                    htmlspecialchars(t('reminder_email_event')) .
                    ':</strong> ' .
                    htmlspecialchars($event['title']) .
                    '<br>
                        <strong>' .
                    htmlspecialchars(t('reminder_email_date')) .
                    ':</strong> ' .
                    htmlspecialchars(date('M d, Y', strtotime($event['event_date']))) .
                    '<br>
                        <strong>' .
                    htmlspecialchars(t('reminder_email_time')) .
                    ':</strong> ' .
                    htmlspecialchars($time_str) .
                    '<br>
                        <strong>' .
                    htmlspecialchars(t('reminder_email_venue')) .
                    ':</strong> ' .
                    htmlspecialchars($event['venue']) .
                    '</p>

                    <hr>

                    <p>
                        <strong>Regis Marie College</strong><br>
                        Campus Event Management System
                    </p>

                </body>
                </html>';

            $email_ok = send_notification_email(
                $event['email'],
                $subject,
                $email_html
            );

            if ($email_ok) {
                $email_count++;
            }
        }
    }
}

echo "Reminders processed: $sent_count notifications, $email_count emails." . PHP_EOL;
