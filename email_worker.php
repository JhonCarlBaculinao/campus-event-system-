<?php
/*
|--------------------------------------------------------------------------
| Email Queue Worker
|--------------------------------------------------------------------------
| Processes pending emails from the email_queue table.
| Run via CLI:  php email_worker.php
| Run via cron: * * * * * php /path/to/campus_event_system/email_worker.php
|
| Options:
|   --limit=N    Max emails per batch (default: 10)
|   --once       Process one batch then exit
|   --status     Show queue statistics
|--------------------------------------------------------------------------
*/

require_once 'db_connect.php';
require_once 'send_email.php';

$limit = 10;
$once  = false;
$queue_id = null;

foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(100, (int) $m[1]));
    }
    if ($arg === '--once') {
        $once = true;
    }
    if (preg_match('/^--queue-id=(\d+)$/', $arg, $m)) {
        $queue_id = (int) $m[1];
    }
    if ($arg === '--status') {
        $r = $pdo->query("
            SELECT status, COUNT(*) AS cnt
            FROM email_queue
            GROUP BY status
            ORDER BY status
        ");
        echo "=== Email Queue Status ===\n";
        while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
            printf("  %-12s: %d\n", $row['status'], (int) $row['cnt']);
        }
        $total = (($pdo->query("SELECT COUNT(*) FROM email_queue"))->fetch(PDO::FETCH_NUM) ?: [null])[0];
        echo "  Total: $total\n";
        exit(0);
    }
}

echo date('Y-m-d H:i:s') . " — Email worker starting (limit=$limit)...\n";

$totalProcessed = 0;

do {
    $count = rmc_process_email_queue($limit, $queue_id);
    $totalProcessed += $count;

    if ($count > 0) {
        echo date('Y-m-d H:i:s') . " — Processed $count email(s)\n";
    }

    if (!$once && $count === 0) {
        sleep(30);
    }

} while (!$once || $count > 0);

echo date('Y-m-d H:i:s') . " — Done. Total processed: $totalProcessed\n";
