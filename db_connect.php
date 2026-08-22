<?php

/*
|--------------------------------------------------------------------------
| PRODUCTION CONFIGURATION
|--------------------------------------------------------------------------
| In production, set environment variables or create /etc/rmc/config.php.
| Locally the defaults below keep XAMPP working without extra setup.
|
| Environment variables (checked first):
|   RMC_DB_HOST, RMC_DB_PORT, RMC_DB_NAME, RMC_DB_USER, RMC_DB_PASS
|   RMC_HMAC_KEY
|--------------------------------------------------------------------------
*/

if (!function_exists('rmc_config')) {

function rmc_config()
{
    $defaults = [
        'db_host'   => '127.0.0.1',
        'db_port'   => '5432',
        'db_name'   => 'campus_event_db',
        'db_user'   => 'postgres',
        'db_pass'   => 'Jhoncarl@01172002',
        'hmac_key'  => '3c67c9d914541b8cfe8a870e773fc911b13b2bb96c386056e9048f131c74c8a8',
    ];

    $paths = [
        '/etc/rmc/config.php',
        dirname(__DIR__) . '/rmc_config.php',
        'C:/xampp/rmc_config.php',
    ];

    $env_map = [
        'db_host'  => 'RMC_DB_HOST',
        'db_port'  => 'RMC_DB_PORT',
        'db_name'  => 'RMC_DB_NAME',
        'db_user'  => 'RMC_DB_USER',
        'db_pass'  => 'RMC_DB_PASS',
        'hmac_key' => 'RMC_HMAC_KEY',
    ];

    $config = $defaults;

    foreach ($paths as $path) {
        if (is_file($path)) {
            $loaded = @include $path;
            if (is_array($loaded)) {
                $config = array_merge($config, $loaded);
                break;
            }
        }
    }

    foreach ($env_map as $key => $env) {
        $val = getenv($env);
        if (is_string($val) && $val !== '') {
            $config[$key] = $val;
        }
    }

    return $config;
}

} // end if (!function_exists('rmc_config'))

$__rmc_cfg = rmc_config();

$host     = $__rmc_cfg['db_host'];
$port     = $__rmc_cfg['db_port'];
$dbname   = $__rmc_cfg['db_name'];
$user     = $__rmc_cfg['db_user'];
$password = $__rmc_cfg['db_pass'];

/*
|--------------------------------------------------------------------------
| QR ATTENDANCE SIGNING KEY
|--------------------------------------------------------------------------
*/

if (!defined('QR_HMAC_KEY')) {
    define('QR_HMAC_KEY', $__rmc_cfg['hmac_key']);
}

$conn_string = "host=$host port=$port dbname=$dbname user=$user password=$password";
$conn = @pg_connect($conn_string);

if (!$conn) {
    error_log("Database connection failed: " . pg_last_error());
    die("Something went wrong. Please try again later.");
}

/*
|--------------------------------------------------------------------------
| PRODUCTION ERROR HANDLING
|--------------------------------------------------------------------------
*/

if (php_sapi_name() !== 'cli') {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

/*
|--------------------------------------------------------------------------
| SECURE SESSION CONFIGURATION
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {

    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '1800');

    session_start();
}

/*
|--------------------------------------------------------------------------
| RATE LIMITING HELPER
|--------------------------------------------------------------------------
| Database-backed rate limiter. Only counts FAILED attempts.
| Counter is cleared on successful login. Cooldown expires automatically.
|
| Functions:
|   rmc_rate_is_blocked($conn, $key, $max, $window)  — check lockout
|   rmc_rate_record_fail($conn, $key, $window)        — record failure
|   rmc_rate_clear($conn, $key)                        — clear on success
|   rmc_rate_remaining($conn, $key, $window)           — seconds left
|--------------------------------------------------------------------------
*/

if (!function_exists('rmc_rate_is_blocked')) {

    function rmc_rate_ensure_table($conn)
    {
        static $done = false;
        if ($done) return;
        @pg_query($conn, "
            CREATE TABLE IF NOT EXISTS rate_limits (
                id SERIAL PRIMARY KEY,
                rate_key VARCHAR(255) NOT NULL,
                attempt_time TIMESTAMP DEFAULT NOW()
            )
        ");
        @pg_query($conn, "CREATE INDEX IF NOT EXISTS idx_rate_key ON rate_limits(rate_key)");
        $done = true;
    }

    function rmc_rate_is_blocked($conn, $key, $max_attempts, $window_seconds)
    {
        rmc_rate_ensure_table($conn);
        @pg_query($conn, "DELETE FROM rate_limits WHERE attempt_time < NOW() - INTERVAL '" . (int) $window_seconds . " seconds'");

        $res = pg_query_params(
            $conn,
            "SELECT COUNT(*) AS cnt FROM rate_limits WHERE rate_key = $1 AND attempt_time > NOW() - INTERVAL '" . (int) $window_seconds . " seconds'",
            [$key]
        );

        $cnt = $res ? (int) pg_fetch_result($res, 0, 'cnt') : 0;
        return $cnt >= $max_attempts;
    }

    function rmc_rate_record_fail($conn, $key, $window_seconds = 900)
    {
        rmc_rate_ensure_table($conn);
        pg_query_params(
            $conn,
            "INSERT INTO rate_limits (rate_key, attempt_time) VALUES ($1, NOW())",
            [$key]
        );
    }

    function rmc_rate_clear($conn, $key)
    {
        rmc_rate_ensure_table($conn);
        pg_query_params(
            $conn,
            "DELETE FROM rate_limits WHERE rate_key = $1",
            [$key]
        );
    }

    function rmc_rate_remaining($conn, $key, $window_seconds)
    {
        rmc_rate_ensure_table($conn);
        $res = pg_query_params(
            $conn,
            "SELECT EXTRACT(EPOCH FROM (MAX(attempt_time) + INTERVAL '" . (int) $window_seconds . " seconds' - NOW()))::int AS secs_left FROM rate_limits WHERE rate_key = $1",
            [$key]
        );
        if (!$res || pg_num_rows($res) === 0) return 0;
        $secs = (int) pg_fetch_result($res, 0, 'secs_left');
        return max(0, $secs);
    }

}

/*
|--------------------------------------------------------------------------
| SINGLE-DEVICE LOGIN ENFORCEMENT
|--------------------------------------------------------------------------
*/

if (!function_exists('rmc_enforce_session_token')) {

    function rmc_enforce_session_token($conn)
    {
        if (
            session_status() !== PHP_SESSION_ACTIVE ||
            empty($_SESSION['user_id']) ||
            empty($_SESSION['role']) ||
            empty($_SESSION['auth_token'])
        ) {
            return;
        }

        $res = pg_query_params(
            $conn,
            "SELECT session_token
             FROM users
             WHERE user_id = $1
               AND role = $2
               AND status = 'active'
             LIMIT 1",
            array(
                (int) $_SESSION['user_id'],
                $_SESSION['role']
            )
        );

        if (!$res) {
            return;
        }

        $row = pg_fetch_assoc($res);

        $expected = $row['session_token'] ?? null;

        if ($expected === null || $expected === '') {
            $_SESSION = array();
            header("Location: login.php");
            exit();
        }

        $provided = hash('sha256', (string) $_SESSION['auth_token']);

        if (!hash_equals($expected, $provided)) {
            $_SESSION = array();
            header("Location: login.php");
            exit();
        }

        /*
        | Session idle timeout: 30 minutes of inactivity
        */
        $last_activity = $_SESSION['last_activity'] ?? 0;
        if (time() - $last_activity > 1800) {
            $_SESSION = array();
            header("Location: login.php?timeout=1");
            exit();
        }
        $_SESSION['last_activity'] = time();
    }

}

rmc_enforce_session_token($conn);
?>