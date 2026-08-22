<?php
/**
 * DIAGNOSTIC SCRIPT — Login Root Cause Analysis
 * Run this via CLI: php C:\xampp\htdocs\campus_event_system\diag_login.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=== RMC LOGIN DIAGNOSTIC ===\n\n";

// 1. Database connection
echo "--- 1. DATABASE CONNECTION ---\n";
require 'db_connect.php';
echo "Connection: " . (pg_connection_status($conn) === PGSQL_CONNECTION_OK ? 'OK' : 'FAILED') . "\n\n";

// 2. Check rate_limits table
echo "--- 2. RATE_LIMITS TABLE ---\n";
$chk = @pg_query($conn, "SELECT rate_key, COUNT(*) AS cnt, MIN(attempt_time) AS oldest, MAX(attempt_time) AS newest FROM rate_limits GROUP BY rate_key ORDER BY newest DESC");
if ($chk) {
    $rows = pg_fetch_all($chk);
    if ($rows) {
        foreach ($rows as $r) {
            echo "  Key: {$r['rate_key']}  Count: {$r['cnt']}  Oldest: {$r['oldest']}  Newest: {$r['newest']}\n";
        }
    } else {
        echo "  Table empty — no rate limit entries.\n";
    }
} else {
    echo "  Table does not exist or query failed.\n";
}
echo "\n";

// 3. Check all users and password hashes
echo "--- 3. USERS & PASSWORD HASHES ---\n";
$users = pg_query($conn, "SELECT user_id, student_id, role, status, password, twofa_secret, session_token FROM users ORDER BY user_id");
if ($users) {
    while ($u = pg_fetch_assoc($users)) {
        $hash_type = 'unknown';
        if (strpos($u['password'], '$2y$') === 0) $hash_type = 'bcrypt';
        elseif (strpos($u['password'], '$2a$') === 0) $hash_type = 'bcrypt-alt';
        elseif (strpos($u['password'], '$argon2') === 0) $hash_type = 'argon2';

        $has_2fa = !empty($u['twofa_secret']) ? 'YES' : 'no';
        $has_session = !empty($u['session_token']) ? 'YES: ' . substr($u['session_token'], 0, 16) . '...' : 'NULL';

        echo "  ID={$u['user_id']}  Login={$u['student_id']}  Role={$u['role']}  Status={$u['status']}  Hash={$hash_type}  2FA={$has_2fa}  Token={$has_session}\n";
    }
}
echo "\n";

// 4. Test password verification for known accounts
echo "--- 4. PASSWORD VERIFICATION TESTS ---\n";
$test_passwords = [
    'admin_only' => 'AdminRMC2026!',
    'org_123' => 'OrganizerRMC2026!',
    '2026-ANAL-01' => 'Analytics2026!pass',
    '4' => 'TestStudent2026!',
];

foreach ($test_passwords as $login => $pw) {
    $res = pg_query_params($conn, "SELECT user_id, student_id, password FROM users WHERE student_id = $1 LIMIT 1", [$login]);
    if ($res && pg_num_rows($res) > 0) {
        $row = pg_fetch_assoc($res);
        $ok = password_verify($pw, $row['password']);
        echo "  Login='{$login}'  PW='{$pw}'  → " . ($ok ? "PASS (hash matches)" : "FAIL (hash mismatch!)") . "\n";
    } else {
        echo "  Login='{$login}'  → USER NOT FOUND\n";
    }
}
echo "\n";

// 5. Test rate limit function directly
echo "--- 5. RATE LIMIT FUNCTION TEST ---\n";
$result = rmc_rate_check($conn, "diag:test:127.0.0.1", 5, 900);
echo "  rmc_rate_check result: " . ($result ? 'ALLOWED' : 'BLOCKED') . "\n";

// 6. Check if session is working
echo "--- 6. SESSION STATUS ---\n";
echo "  Session status: " . session_status() . " (0=disabled, 1=none, 2=active)\n";
echo "  Session ID: " . session_id() . "\n";
echo "  Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET') . "\n";
echo "  Session role: " . ($_SESSION['role'] ?? 'NOT SET') . "\n";
echo "\n";

// 7. Check pg_query_params behavior
echo "--- 7. PARAMETERIZED QUERY TEST ---\n";
$test = pg_query_params($conn, "SELECT $1::text AS val", ['hello']);
if ($test) {
    echo "  pg_query_params: OK — " . pg_fetch_result($test, 0, 0) . "\n";
} else {
    echo "  pg_query_params: FAILED — " . pg_last_error($conn) . "\n";
}
echo "\n";

// 8. Check CSRF functionality
echo "--- 8. CSRF TOKEN ---\n";
require 'csrf.php';
$token = csrf_token();
echo "  CSRF token generated: " . (strlen($token) > 0 ? 'YES (len=' . strlen($token) . ')' : 'EMPTY') . "\n";
echo "\n";

echo "=== DIAGNOSTIC COMPLETE ===\n";
