<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';
require_once 'db_connect.php';
require_once 'email_templates.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* =========================================================
   LOAD SMTP CONFIGURATION
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

    $paths[] = __DIR__ . '/email_config.local.php';
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
   SEND NOTIFICATION EMAIL (immediate send)
   ========================================================= */

function send_notification_email($to_email, $subject, $message_body)
{
    global $pdo;

    $mail = new PHPMailer(true);

    try {
        $smtp = rmc_smtp_config();

        $mail->isSMTP();
        $mail->Host       = $smtp['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp['username'];
        $mail->Password   = $smtp['password'];
        $mail->SMTPSecure = $smtp['encryption'] === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) $smtp['port'];

        $mail->setFrom($smtp['from_email'], $smtp['from_name']);
        $mail->addAddress($to_email);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;

        if (strpos($message_body, RMC_BRANDED_MARKER) === false) {
            $message_body = rmc_email_wrapper($message_body);
        }

        $mail->Body    = $message_body;
        $mail->AltBody = strip_tags($message_body);

        $mail->send();

        if ($pdo) {
            $stmt = $pdo->prepare(
                "INSERT INTO email_logs (recipient_email, subject, message, status, created_at)
                 VALUES (?, ?, ?, 'Sent', NOW())"
            );
            $stmt->execute([$to_email, $subject, $message_body]);
        }

        return true;

    } catch (Exception $e) {

        if ($pdo) {
            $stmt = $pdo->prepare(
                "INSERT INTO email_logs (recipient_email, subject, message, status, created_at)
                 VALUES (?, ?, ?, 'Failed', NOW())"
            );
            $stmt->execute([$to_email, $subject, $message_body]);
        }

        error_log("Email failed to {$to_email}: " . $mail->ErrorInfo);

        return false;
    }
}

/* =========================================================
   DEFERRED EMAIL QUEUE
   ========================================================= */

function send_email_deferred($to_email, $subject, $message_body)
{
    global $pdo;

    if (!$pdo) {
        error_log('send_email_deferred: no DB connection, falling back to immediate send');
        return send_notification_email($to_email, $subject, $message_body);
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO email_queue
                (recipient_email, subject, message_body, status, attempts, scheduled_at, created_at)
             VALUES (?, ?, ?, 'pending', 0, NOW(), NOW())"
        );
        $result = $stmt->execute([$to_email, $subject, $message_body]);
    } catch (\PDOException $e) {
        error_log('send_email_deferred: queue insert failed - ' . $e->getMessage());
        $result = false;
    }

    if (!$result) {
        error_log('send_email_deferred: queue insert failed, falling back');
        return send_notification_email($to_email, $subject, $message_body);
    }

    return true;
}

/* =========================================================
   PROCESS QUEUE (run by email_worker.php / cron)
   MySQL-compatible version (no RETURNING clause)
   ========================================================= */

function rmc_process_email_queue($limit = 10, $queue_id = null)
{
    global $pdo;

    if (!$pdo) {
        return 0;
    }

    $limit = (int) $limit;

    $where = "status = 'pending' AND attempts < max_attempts AND scheduled_at <= NOW()";
    $params = [];

    if ($queue_id !== null) {
        $where .= " AND queue_id = ?";
        $params[] = (int) $queue_id;
    }

    $stmt = $pdo->prepare(
        "SELECT queue_id, recipient_email, subject, message_body
         FROM email_queue
         WHERE $where
         ORDER BY scheduled_at ASC
         LIMIT $limit"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        return 0;
    }

    $processed = 0;

    foreach ($rows as $row) {
        $qid = (int) $row['queue_id'];

        $pdo->prepare("UPDATE email_queue SET status = 'processing' WHERE queue_id = ?")
            ->execute([$qid]);

        $ok = send_notification_email($row['recipient_email'], $row['subject'], $row['message_body']);

        if ($ok) {
            $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE queue_id = ?")
                ->execute([$qid]);
        } else {
            $pdo->prepare(
                "UPDATE email_queue
                 SET status = 'pending',
                     attempts = attempts + 1,
                     last_error = 'Send failed',
                     scheduled_at = DATE_ADD(NOW(), INTERVAL 5 * (attempts + 1) MINUTE)
                 WHERE queue_id = ?"
            )->execute([$qid]);
        }

        $processed++;
    }

    return $processed;
}