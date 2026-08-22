<?php

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';
require_once 'db_connect.php';
require_once 'email_templates.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


/* =========================================================
   LOAD SMTP CONFIGURATION  (never hardcoded in source)
   =========================================================
   Looks for the SMTP settings in this order:
     1. $RMC_SMTP_CONFIG environment variable -> file path
     2. C:\xampp\email_config.php (outside the web root)
   If none is found, sends fail gracefully and are logged.
   ========================================================= */

function rmc_smtp_config()
{
    $defaults = [
        'host'       => 'smtp.gmail.com',
        'port'       => 587,
        'encryption' => 'tls',
        'username'   => '',
        'password'   => '',
        'from_email' => '',
        'from_name'  => 'Regis Marie College Event System',
    ];

    $paths = [];

    $env_path = getenv('RMC_SMTP_CONFIG');

    if (is_string($env_path) && $env_path !== '') {
        $paths[] = $env_path;
    }

    $paths[] = '/etc/rmc/email_config.php';
    $paths[] = dirname(__DIR__) . '/rmc_email_config.php';
    $paths[] = 'C:/xampp/email_config.php';

    foreach ($paths as $path) {

        if (is_file($path)) {

            $config = @include $path;

            if (is_array($config)) {
                return array_merge($defaults, $config);
            }
        }
    }

    return $defaults;
}


/* =========================================================
   SEND NOTIFICATION EMAIL
   ========================================================= */

function send_notification_email(
    $to_email,
    $subject,
    $message_body
) {

    global $conn;

    $mail = new PHPMailer(true);


    try {


        /* =================================================
           SMTP SETTINGS  (loaded from out-of-root config)
           ================================================= */

        $smtp = rmc_smtp_config();

        $mail->isSMTP();

        $mail->Host = $smtp['host'];

        $mail->SMTPAuth = true;

        $mail->Username = $smtp['username'];

        $mail->Password = $smtp['password'];

        $mail->SMTPSecure =
            $smtp['encryption'] === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;

        $mail->Port = (int) $smtp['port'];


        /* =================================================
           EMAIL DETAILS
           ================================================= */

        $mail->setFrom(
            $smtp['from_email'],
            $smtp['from_name']
        );

        $mail->addAddress($to_email);

        $mail->isHTML(true);

        $mail->CharSet = 'UTF-8';

        $mail->Subject = $subject;

        /*
         * Wrap any un-branded message in the RMC maroon header/footer.
         * Messages produced by the email_templates.php builders already
         * contain the <!-- RMC_BRANDED --> marker and are left as-is.
         */

        if (strpos($message_body, RMC_BRANDED_MARKER) === false) {
            $message_body = rmc_email_wrapper($message_body);
        }

        $mail->Body = $message_body;

        /*
         * Plain-text fallback.
         */

        $mail->AltBody = strip_tags($message_body);


        /* =================================================
           SEND
           ================================================= */

        $mail->send();


        /* =================================================
           SUCCESS LOG
           ================================================= */

        pg_query_params(
            $conn,

            "INSERT INTO email_logs
            (
                recipient_email,
                subject,
                message,
                status,
                created_at
            )
            VALUES
            (
                $1,
                $2,
                $3,
                'Sent',
                NOW()
            )",

            array(
                $to_email,
                $subject,
                $message_body
            )
        );


        return true;


    } catch (Exception $e) {


        /* =================================================
           FAILED EMAIL LOG
           ================================================= */

        pg_query_params(
            $conn,

            "INSERT INTO email_logs
            (
                recipient_email,
                subject,
                message,
                status,
                created_at
            )
            VALUES
            (
                $1,
                $2,
                $3,
                'Failed',
                NOW()
            )",

            array(
                $to_email,
                $subject,
                $message_body
            )
        );


        error_log(
            "Email failed to {$to_email}: " .
            $mail->ErrorInfo
        );


        return false;

    }

}


/* =========================================================
   DEFERRED EMAIL QUEUE  (database-backed with retry)
   =========================================================
   Emails are inserted into the email_queue table for reliable
   delivery with automatic retry. A worker script
   (email_worker.php) processes the queue.
   ========================================================= */

function send_email_deferred($to_email, $subject, $message_body)
{
    global $conn;

    if (!$conn) {
        error_log('send_email_deferred: no DB connection, falling back to immediate send');
        return send_notification_email($to_email, $subject, $message_body);
    }

    $result = @pg_query_params(
        $conn,

        "INSERT INTO email_queue
        (
            recipient_email,
            subject,
            message_body,
            status,
            attempts,
            scheduled_at,
            created_at
        )
        VALUES
        (
            $1,
            $2,
            $3,
            'pending',
            0,
            NOW(),
            NOW()
        )",

        array($to_email, $subject, $message_body)
    );

    if (!$result) {
        error_log('send_email_deferred: queue insert failed, falling back');
        return send_notification_email($to_email, $subject, $message_body);
    }

    return true;
}


function rmc_process_email_queue($limit = 10)
{
    global $conn;

    if (!$conn) {
        return 0;
    }

    $result = @pg_query_params(
        $conn,

        "UPDATE email_queue
        SET status = 'processing'
        WHERE queue_id IN (
            SELECT queue_id
            FROM email_queue
            WHERE status = 'pending'
              AND attempts < max_attempts
              AND scheduled_at <= NOW()
            ORDER BY scheduled_at ASC
            LIMIT $1
            FOR UPDATE SKIP LOCKED
        )
        RETURNING queue_id, recipient_email, subject, message_body",

        array($limit)
    );

    if (!$result || pg_num_rows($result) === 0) {
        return 0;
    }

    $processed = 0;

    while ($row = pg_fetch_assoc($result)) {
        $qid      = (int) $row['queue_id'];
        $email    = $row['recipient_email'];
        $subj     = $row['subject'];
        $body     = $row['message_body'];

        $ok = @send_notification_email($email, $subj, $body);

        if ($ok) {
            @pg_query_params(
                $conn,
                "UPDATE email_queue
                SET status = 'sent', sent_at = NOW()
                WHERE queue_id = $1",
                array($qid)
            );
        } else {
            @pg_query_params(
                $conn,
                "UPDATE email_queue
                SET status = 'pending',
                    attempts = attempts + 1,
                    last_error = 'Send failed',
                    scheduled_at = NOW() + (INTERVAL '5 minutes' * (attempts + 1))
                WHERE queue_id = $1",
                array($qid)
            );
        }

        $processed++;
    }

    return $processed;
}

?>