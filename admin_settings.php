<?php


include 'db_connect.php';
require 'lang.php';
require 'csrf.php';

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    http_response_code(403);
    die("Access denied. Admins only.");
}

$admin_id = (int) $_SESSION['user_id'];

$error = '';
$success = '';


/*
|--------------------------------------------------------------------------
| Ensure system_settings table exists
|--------------------------------------------------------------------------
*/

$pdo->query("
    CREATE TABLE IF NOT EXISTS system_settings (
        setting_key   VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at    TIMESTAMP DEFAULT NOW()
    )
");


/*
|--------------------------------------------------------------------------
| Ensure default settings exist
|--------------------------------------------------------------------------
*/

$pdo->query("
    INSERT INTO system_settings (setting_key, setting_value)
    VALUES ('student_access', '1')
    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
");

$pdo->query("
    INSERT INTO system_settings (setting_key, setting_value)
    VALUES ('organizer_access', '1')
    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
");


/*
|--------------------------------------------------------------------------
| Toggle Setting
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST['toggle_setting'])
) {

    csrf_verify();

    $setting_key = $_POST['toggle_setting'];

    $allowed_keys = ['student_access', 'organizer_access'];

    if (!in_array($setting_key, $allowed_keys, true)) {

        $error = 'Invalid setting key.';

    } else {

        $current_result = $pdo->prepare("SELECT setting_value
             FROM system_settings
             WHERE setting_key = ?"); $current_result->execute(array($setting_key));

        $current = $current_result->fetch(PDO::FETCH_ASSOC);

        if (!$current) {

            $error = 'Setting not found.';

        } else {

            $new_value = ($current['setting_value'] === '1') ? '0' : '1';

$setting_update = $pdo->prepare("UPDATE system_settings
     SET setting_value = ?,
         updated_at = NOW()
     WHERE setting_key = ?");
$setting_update->execute([$new_value, $setting_key]);

            /*
            | Audit log
            */
            $label = ($setting_key === 'student_access')
                ? 'Student Access'
                : 'Organizer Access';

            $state = ($new_value === '1') ? 'enabled' : 'disabled';

            $notification_stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, ?)");
            $notification_stmt->execute([
                $admin_id,
                'System setting "' . $label . '" was ' . $state . ' by administrator.',
                'admin_action'
            ]);

            $success = $label . ' has been ' . $state . '.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Read current settings
|--------------------------------------------------------------------------
*/

$settings_result = $pdo->query("SELECT setting_key, setting_value
     FROM system_settings
     ORDER BY setting_key");

$settings = [];
while ($row = $settings_result->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$student_access  = $settings['student_access']  ?? '1';
$organizer_access = $settings['organizer_access'] ?? '1';


/*
|--------------------------------------------------------------------------
| System stats
|--------------------------------------------------------------------------
*/

$db_status = ($conn) ? 'Connected' : 'Disconnected';

$total_users_result = $pdo->query("SELECT COUNT(*) AS cnt FROM users");
$total_users = (int) ($total_users_result->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

$total_events_result = $pdo->query("SELECT COUNT(*) AS cnt FROM events");
$total_events = (int) ($total_events_result->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

$total_students_result = $pdo->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'student'");
$total_students = (int) ($total_students_result->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

$total_organizers_result = $pdo->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'organizer'");
$total_organizers = (int) ($total_organizers_result->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);


/*
|--------------------------------------------------------------------------
| Shared Partial Variables
|--------------------------------------------------------------------------
*/

$full_name = $_SESSION['full_name'] ?? '';
$first_name = explode(' ', trim($full_name))[0];

$unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_stmt->execute([$admin_id]);
$unread_count = (int) $unread_stmt->fetchColumn();

$recent_notifications = $pdo->prepare("SELECT notification_id, type, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5"); $recent_notifications->execute([$admin_id]);

$role_label  = 'Administrator';
$page_title  = 'System Settings — RMC Events';
$active_page = 'admin_settings';

?>
<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     SETTINGS HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up"
>

    <div class="flex items-center gap-4 sm:gap-5">

        <div
            class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
        >

            <i class="fa-solid fa-gear text-2xl"></i>

        </div>

        <div>

            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                System Settings
            </h2>

            <p class="text-slate-600 mt-1 text-sm sm:text-base">
                Manage system-wide access controls and view platform statistics.
            </p>

        </div>

    </div>

</div>


<!-- =========================================================
     MESSAGES
     ========================================================= -->

<?php if ($error): ?>

    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 mb-6 animate-up delay-1">

        <i class="fa-solid fa-circle-exclamation mr-2"></i>

        <?= htmlspecialchars($error); ?>

    </div>

<?php endif; ?>


<?php if ($success): ?>

    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 mb-6 animate-up delay-1">

        <i class="fa-solid fa-circle-check mr-2"></i>

        <?= htmlspecialchars($success); ?>

    </div>

<?php endif; ?>


<!-- =========================================================
     ACCESS CONTROL
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm overflow-hidden mb-8 animate-up delay-1">

    <div class="px-6 py-5 border-b border-slate-200">

        <h2 class="text-xl font-bold text-slate-900">
            <i class="fa-solid fa-shield-halved mr-2 text-rmc-600"></i>
            Access Control
        </h2>

        <p class="text-slate-500 text-sm mt-1">
            Enable or disable access for specific user roles across the platform.
        </p>

    </div>


    <!-- Student Access Toggle -->

    <div class="flex items-center justify-between px-6 py-5 border-b border-slate-100">

        <div class="min-w-0">

            <p class="font-semibold text-slate-800">
                <i class="fa-solid fa-user-graduate mr-2 text-slate-400"></i>
                Student Access
            </p>

            <p class="text-slate-500 text-sm mt-1">
                When disabled, students cannot log in or access the system.
            </p>

        </div>

        <form method="POST" class="shrink-0 ml-4">

            <?= csrf_field(); ?>

            <input type="hidden" name="toggle_setting" value="student_access">

            <button
                type="submit"
                class="toggle-btn relative inline-flex h-8 w-14 items-center rounded-full transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-rmc-300 focus:ring-offset-2 cursor-pointer <?= $student_access === '1' ? 'bg-emerald-500' : 'bg-slate-300'; ?>"
                aria-label="Toggle Student Access"
            >

                <span
                    class="inline-block h-6 w-6 transform rounded-full bg-white shadow-lg transition-transform duration-200 ease-in-out <?= $student_access === '1' ? 'translate-x-7' : 'translate-x-1'; ?>"
                ></span>

            </button>

        </form>

    </div>


    <!-- Organizer Access Toggle -->

    <div class="flex items-center justify-between px-6 py-5">

        <div class="min-w-0">

            <p class="font-semibold text-slate-800">
                <i class="fa-solid fa-calendar-plus mr-2 text-slate-400"></i>
                Organizer Access
            </p>

            <p class="text-slate-500 text-sm mt-1">
                When disabled, organizers cannot log in or access the system.
            </p>

        </div>

        <form method="POST" class="shrink-0 ml-4">

            <?= csrf_field(); ?>

            <input type="hidden" name="toggle_setting" value="organizer_access">

            <button
                type="submit"
                class="toggle-btn relative inline-flex h-8 w-14 items-center rounded-full transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-rmc-300 focus:ring-offset-2 cursor-pointer <?= $organizer_access === '1' ? 'bg-emerald-500' : 'bg-slate-300'; ?>"
                aria-label="Toggle Organizer Access"
            >

                <span
                    class="inline-block h-6 w-6 transform rounded-full bg-white shadow-lg transition-transform duration-200 ease-in-out <?= $organizer_access === '1' ? 'translate-x-7' : 'translate-x-1'; ?>"
                ></span>

            </button>

        </form>

    </div>

</div>


<!-- =========================================================
     SYSTEM INFO
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm overflow-hidden mb-8 animate-up delay-2">

    <div class="px-6 py-5 border-b border-slate-200">

        <h2 class="text-xl font-bold text-slate-900">
            <i class="fa-solid fa-circle-info mr-2 text-rmc-600"></i>
            System Information
        </h2>

        <p class="text-slate-500 text-sm mt-1">
            Read-only overview of the platform status and statistics.
        </p>

    </div>


    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-px bg-slate-100">

        <!-- Database Status -->

        <div class="bg-white px-6 py-5 text-center">

            <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto mb-3">

                <i class="fa-solid fa-database text-xl"></i>

            </div>

            <p class="text-2xl font-bold text-slate-900">
                <?php if ($db_status === 'Connected'): ?>
                    <span class="text-emerald-600">Online</span>
                <?php else: ?>
                    <span class="text-red-600">Offline</span>
                <?php endif; ?>
            </p>

            <p class="text-slate-500 text-sm mt-1">
                Database Status
            </p>

        </div>


        <!-- Total Users -->

        <div class="bg-white px-6 py-5 text-center">

            <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mx-auto mb-3">

                <i class="fa-solid fa-users text-xl"></i>

            </div>

            <p class="text-2xl font-bold text-slate-900">
                <?= number_format($total_users); ?>
            </p>

            <p class="text-slate-500 text-sm mt-1">
                Total Users
            </p>

        </div>


        <!-- Total Events -->

        <div class="bg-white px-6 py-5 text-center">

            <div class="w-12 h-12 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center mx-auto mb-3">

                <i class="fa-solid fa-calendar-days text-xl"></i>

            </div>

            <p class="text-2xl font-bold text-slate-900">
                <?= number_format($total_events); ?>
            </p>

            <p class="text-slate-500 text-sm mt-1">
                Total Events
            </p>

        </div>


        <!-- Students / Organizers -->

        <div class="bg-white px-6 py-5 text-center">

            <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center mx-auto mb-3">

                <i class="fa-solid fa-user-group text-xl"></i>

            </div>

            <p class="text-2xl font-bold text-slate-900">
                <?= number_format($total_students); ?> / <?= number_format($total_organizers); ?>
            </p>

            <p class="text-slate-500 text-sm mt-1">
                Students / Organizers
            </p>

        </div>

    </div>

</div>


<?php include 'partials/footer.php'; ?>
