<?php
if (!defined('BASE_URL')) {
    $script_path = parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH) ?: '';
    $base_path = rtrim(str_replace('\\', '/', dirname($script_path)), '/');
    define('BASE_URL', ($base_path === '/' || $base_path === '.') ? '' : $base_path);
}
/*
 |--------------------------------------------------------------------------
 | ERROR REPORTING
 |--------------------------------------------------------------------------
 | Log PHP errors server-side; never expose internal details to visitors.
 */
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

/*
 |--------------------------------------------------------------------------
 | AUTO-DETECT ENVIRONMENT (Local XAMPP vs InfinityFree live hosting)
 |--------------------------------------------------------------------------
 | This checks the domain name the site is being visited from. If it's
 | your live InfinityFree domain, it uses the live database credentials.
 | Otherwise (localhost, 127.0.0.1, etc.) it falls back to your local
 | XAMPP database settings. No manual switching needed.
 |--------------------------------------------------------------------------
 */

if (!function_exists('rmc_config')) {

function rmc_config()
{
    $host_header = $_SERVER['HTTP_HOST'] ?? '';

    $is_live = (strpos($host_header, 'infinityfreeapp.com') !== false)
        || (strpos($host_header, 'baculinao') !== false);

    if ($is_live) {
        // ---- LIVE HOSTING ----
        // Live credentials and signing keys must come from environment variables.
        $defaults = [
            'db_host'   => '',
            'db_port'   => '3306',
            'db_name'   => '',
            'db_user'   => '',
            'db_pass'   => '',
            'hmac_key'  => '',
        ];
    } else {
        // ---- LOCAL (XAMPP) DATABASE ----
        $defaults = [
            'db_host'   => 'localhost',
            'db_port'   => '3306',
            'db_name'   => 'campus_event_db',
            'db_user'   => 'root',
            'db_pass'   => '',
            'hmac_key'  => '',
        ];
    }

    $env_map = [
        'db_host'  => 'RMC_DB_HOST',
        'db_port'  => 'RMC_DB_PORT',
        'db_name'  => 'RMC_DB_NAME',
        'db_user'  => 'RMC_DB_USER',
        'db_pass'  => 'RMC_DB_PASS',
        'hmac_key' => 'RMC_HMAC_KEY',
    ];

    $config = $defaults;

    // Optional per-installation config file, used ONLY on live hosting.
    // This lets one codebase be deployed to both XAMPP (local defaults)
    // and InfinityFree (config.local.php credentials) without editing
    // anything by hand on either side.
    if ($is_live) {
        $local_config_file = __DIR__ . '/config.local.php';
        if (is_file($local_config_file)) {
            $local_config = require $local_config_file;
            if (is_array($local_config)) {
                foreach ($env_map as $key => $env) {
                    if (isset($local_config[$key]) && is_string($local_config[$key]) && $local_config[$key] !== '') {
                        $config[$key] = $local_config[$key];
                    }
                }
            }
        }
    }

    foreach ($env_map as $key => $env) {
        $val = getenv($env);
        if (is_string($val) && $val !== '') {
            $config[$key] = $val;
        }
    }

    if ($is_live) {
        foreach (['db_host', 'db_name', 'db_user', 'hmac_key'] as $required_key) {
            if ($config[$required_key] === '') {
                error_log('RMC live configuration is incomplete: missing ' . $required_key);
                die('Something went wrong. Please try again later.');
            }
        }
    }

    if ($config['hmac_key'] === '') {
        // Generate a stable development-only key when local config has none.
        $config['hmac_key'] = hash('sha256', 'RMC-local-development-key-' . php_uname('n'));
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

/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION - PDO MySQL
|--------------------------------------------------------------------------
*/

$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
];

try {
    $pdo = new PDO($dsn, $user, $password, $options);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Something went wrong. Please try again later.");
}

// Keep $conn for backward compatibility (some code might check it)
$conn = $pdo;

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

    function rmc_rate_ensure_table($pdo)
    {
        static $done = false;
        if ($done) return;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                rate_key VARCHAR(255) NOT NULL,
                attempt_time DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                KEY idx_rate_key (rate_key),
                KEY idx_attempt_time (attempt_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done = true;
    }

    function rmc_rate_is_blocked($pdo, $key, $max_attempts, $window_seconds)
    {
        rmc_rate_ensure_table($pdo);
        $pdo->exec("DELETE FROM rate_limits WHERE attempt_time < NOW() - INTERVAL " . (int) $window_seconds . " SECOND");

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM rate_limits WHERE rate_key = ? AND attempt_time > NOW() - INTERVAL ? SECOND"
        );
        $stmt->execute([$key, $window_seconds]);
        $cnt = (int) $stmt->fetchColumn();
        return $cnt >= $max_attempts;
    }

    function rmc_rate_record_fail($pdo, $key, $window_seconds = 900)
    {
        rmc_rate_ensure_table($pdo);
        $stmt = $pdo->prepare(
            "INSERT INTO rate_limits (rate_key, attempt_time) VALUES (?, NOW())"
        );
        $stmt->execute([$key]);
    }

    function rmc_rate_clear($pdo, $key)
    {
        rmc_rate_ensure_table($pdo);
        $stmt = $pdo->prepare(
            "DELETE FROM rate_limits WHERE rate_key = ?"
        );
        $stmt->execute([$key]);
    }

    function rmc_rate_remaining($pdo, $key, $window_seconds)
    {
        rmc_rate_ensure_table($pdo);
        $stmt = $pdo->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(attempt_time) + INTERVAL ? SECOND) AS secs_left FROM rate_limits WHERE rate_key = ?"
        );
        $stmt->execute([$window_seconds, $key]);
        $secs = (int) $stmt->fetchColumn();
        return max(0, $secs);
    }

}

/*
|--------------------------------------------------------------------------
| PER-ROLE INDEPENDENT SESSIONS
|--------------------------------------------------------------------------
| Auth data is stored per role under $_SESSION['rmc_auth'][ROLE]. Each
| browser tab keeps its own role in sessionStorage and mirrors it into the
| rmc_tab_role cookie; login redirects carry ?rmc_role=. The matching
| role's data is hydrated into the legacy flat $_SESSION keys so every
| existing page continues to work unchanged.
|--------------------------------------------------------------------------
*/

if (!function_exists('rmc_active_role')) {

    function rmc_allowed_roles()
    {
        return array('student', 'organizer', 'admin');
    }

    function rmc_detect_requested_role()
    {
        $candidates = array(
            $_GET['rmc_role'] ?? null,
            $_POST['rmc_role'] ?? null,
            $_COOKIE['rmc_tab_role'] ?? null,
        );

        foreach ($candidates as $candidate) {
            if (
                is_string($candidate) &&
                in_array($candidate, rmc_allowed_roles(), true)
            ) {
                return $candidate;
            }
        }

        return null;
    }

    function rmc_hydrate_role_session()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $role = rmc_detect_requested_role();

        if (
            $role === null &&
            isset($_SESSION['active_role']) &&
            in_array($_SESSION['active_role'], rmc_allowed_roles(), true)
        ) {
            $role = $_SESSION['active_role'];
        }

        if (
            $role !== null &&
            isset($_SESSION['rmc_auth'][$role]) &&
            is_array($_SESSION['rmc_auth'][$role]) &&
            !empty($_SESSION['rmc_auth'][$role]['user_id'])
        ) {
            foreach ($_SESSION['rmc_auth'][$role] as $key => $value) {
                $_SESSION[$key] = $value;
            }
            $_SESSION['active_role'] = $role;

            if (!isset($_COOKIE['rmc_tab_role']) || $_COOKIE['rmc_tab_role'] !== $role) {
                setcookie('rmc_tab_role', $role, [
                    'expires'  => 0,
                    'path'     => '/',
                    'samesite' => 'Lax',
                ]);
            }
        }
    }

}

/*
|--------------------------------------------------------------------------
| SINGLE-DEVICE LOGIN ENFORCEMENT (per active role)
|--------------------------------------------------------------------------
*/

if (!function_exists('rmc_enforce_session_token')) {

    function rmc_enforce_session_token($pdo)
    {
        if (
            session_status() !== PHP_SESSION_ACTIVE ||
            empty($_SESSION['user_id']) ||
            empty($_SESSION['role'])
        ) {
            return;
        }

        $role = (string) $_SESSION['role'];
        $role_auth = $_SESSION['rmc_auth'][$role] ?? null;
        $auth_token = is_array($role_auth) ? ($role_auth['auth_token'] ?? '') : '';

        if ($auth_token === '') {
            $auth_token = (string) ($_SESSION['auth_token'] ?? '');
        }
        if ($auth_token === '') {
            return;
        }

        $stmt = $pdo->prepare(
            "SELECT session_token
             FROM users
             WHERE user_id = ?
               AND role = ?
               AND status = 'active'
             LIMIT 1"
        );
        $stmt->execute([
            (int) $_SESSION['user_id'],
            $role
        ]);

        $row = $stmt->fetch();

        if (!$row) {
            rmc_destroy_role_session();
        }

        $expected = $row['session_token'] ?? null;

        if ($expected === null || $expected === '') {
            rmc_destroy_role_session();
        }

        $provided = hash('sha256', $auth_token);

        if (isset($expected) && $expected !== '' && !hash_equals($expected, $provided)) {
            rmc_destroy_role_session();
        }

        /*
        | Session idle timeout: 30 minutes of inactivity (tracked per role)
        */
        $last_activity = $_SESSION['last_activity'] ?? 0;
        if (time() - $last_activity > 1800) {
            rmc_destroy_role_session(true);
        }
        $_SESSION['last_activity'] = time();

        if (
            isset($_SESSION['rmc_auth'][$role]) &&
            is_array($_SESSION['rmc_auth'][$role])
        ) {
            $_SESSION['rmc_auth'][$role]['last_activity'] = time();
            $_SESSION['rmc_auth'][$role]['auth_token'] = $auth_token;
        }
    }

}

if (!function_exists('rmc_destroy_role_session')) {

    function rmc_destroy_role_session($timeout = false)
    {
        $role = $_SESSION['role'] ?? ($_SESSION['active_role'] ?? null);

        if (
            $role !== null &&
            isset($_SESSION['rmc_auth'][$role])
        ) {
            unset($_SESSION['rmc_auth'][$role]);
        }

        foreach (array('user_id', 'role', 'full_name', 'auth_token', 'last_activity', 'login_time') as $key) {
            unset($_SESSION[$key]);
        }

        if (!empty($_SESSION['rmc_auth']) && count($_SESSION['rmc_auth']) > 0) {
            header("Location: login.php" . ($timeout ? "?timeout=1" : ""));
            exit();
        }

        $_SESSION = array();
        header("Location: login.php" . ($timeout ? "?timeout=1" : ""));
        exit();
    }

}


/*
|--------------------------------------------------------------------------
| SHARED APPLICATION HELPERS
|--------------------------------------------------------------------------
*/
if (!function_exists('rmc_is_role_allowed')) {
    function rmc_is_role_allowed($conn, $role) {
        if ($role === 'admin') return true;
        $key = $role . '_access';
        $res = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $res->execute([$key]);
        $row = $res->fetch(PDO::FETCH_NUM);
        if ($row !== false) {
            return ((string) $row[0] === '1');
        }
        return true;
    }
}

if (!function_exists('status_badge')) {
    function status_badge($status) {
        switch (strtolower((string)$status)) {
            case 'sent':
            case 'approved':
            case 'active':
                return 'bg-emerald-100 text-emerald-700';
            case 'archived':
                return 'bg-slate-100 text-slate-700';
            case 'rejected':
            case 'failed':
            case 'deleted':
                return 'bg-red-100 text-red-700';
            case 'cancelled':
            case 'pending':
                return 'bg-amber-100 text-amber-700';
            default:
                return 'bg-slate-100 text-slate-700';
        }
    }
}

rmc_hydrate_role_session();
rmc_enforce_session_token($pdo);

