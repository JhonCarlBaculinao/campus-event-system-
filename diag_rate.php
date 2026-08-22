<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();
include 'db_connect.php';

echo "=== RATE LIMIT STATE ===\n";

// Show current entries
$res = pg_query($conn, "SELECT rate_key, attempt_time FROM rate_limits ORDER BY attempt_time DESC LIMIT 20");
if ($res) {
    while ($r = pg_fetch_assoc($res)) {
        echo "  Key: {$r['rate_key']}  Time: {$r['attempt_time']}\n";
    }
}

// Show count for login:::1
$res2 = pg_query_params($conn, "SELECT COUNT(*) AS cnt FROM rate_limits WHERE rate_key = $1", ['login:::1']);
$cnt = $res2 ? pg_fetch_result($res2, 0, 0) : 'N/A';
echo "\nTotal login:::1 entries: {$cnt}\n";

// Check what REMOTE_ADDR looks like in web context
echo "\nREMOTE_ADDR (from CGI): " . ($_SERVER['REMOTE_ADDR'] ?? 'NOT SET') . "\n";
echo "HTTP_X_FORWARDED_FOR: " . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'NOT SET') . "\n";
