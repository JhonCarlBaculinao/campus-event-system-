<?php
session_start();
include 'db_connect.php';
require 'lang.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die("Access denied. Admins only.");
}

// ==========================
// EMAIL LOGS (with search)
// ==========================

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if (!empty($search)) {
    $email_logs = pg_query_params($conn,
        "SELECT * FROM email_logs WHERE recipient_email ILIKE $1 OR subject ILIKE $1 ORDER BY created_at DESC",
        array('%' . $search . '%')
    );
} else {
    $email_logs = pg_query($conn, "SELECT * FROM email_logs ORDER BY created_at DESC");
}

// ==========================
// DASHBOARD COUNTS
// ==========================

$total_result = pg_query($conn, "
    SELECT COUNT(*) AS total
    FROM email_logs
");

$total_emails = pg_fetch_assoc($total_result)['total'];


$sent_result = pg_query($conn, "
    SELECT COUNT(*) AS total
    FROM email_logs
    WHERE status='Sent'
");

$total_sent = pg_fetch_assoc($sent_result)['total'];


$failed_result = pg_query($conn, "
    SELECT COUNT(*) AS total
    FROM email_logs
    WHERE status='Failed'
");

$total_failed = pg_fetch_assoc($failed_result)['total'];


// ==========================
// STATUS BADGE
// ==========================

function status_badge($status)
{
    if ($status == "Sent") {
        return "bg-green-100 text-green-700";
    }

    return "bg-red-100 text-red-700";
}


// ==========================
// SHARED PARTIAL VARIABLES
// ==========================

$user_id = (int) $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? '';
$first_name = explode(' ', trim($full_name))[0];

$unread_count = (int) pg_fetch_result(
    pg_query_params(
        $conn,
        "SELECT COUNT(*)
         FROM notifications
         WHERE user_id = $1
           AND is_read = false",
        array($user_id)
    ),
    0,
    0
);

$recent_notifications = pg_query_params(
    $conn,
    "SELECT notification_id, type, message, is_read, created_at
     FROM notifications
     WHERE user_id = $1
     ORDER BY created_at DESC
     LIMIT 5",
    array($user_id)
);

$role_label  = 'Administrator';
$page_title  = t('title_email_logs');
$active_page = 'email_logs';

?>

<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     EMAIL LOGS HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up"
>

    <div class="flex items-center gap-4 sm:gap-5">

        <div
            class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
        >

            <i class="fa-solid fa-envelope-circle-check text-2xl"></i>

        </div>

        <div>

            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                <?= t('email_monitoring_center'); ?>
            </h2>

            <p class="text-slate-600 mt-1 text-sm sm:text-base">
                <?= t('email_logs_hero_desc'); ?>
            </p>

        </div>

    </div>

</div>


<!-- =========================================================
     STATISTICS
     ========================================================= -->

<div class="grid grid-cols-1 md:grid-cols-3 gap-5 sm:gap-6 mb-8">

    <!-- TOTAL EMAILS -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 animate-up delay-1">

        <div class="flex justify-between items-start gap-3">

            <div class="min-w-0">

                <p class="text-slate-500 text-sm">
                    <?= t('total_emails'); ?>
                </p>

                <h2 class="text-4xl font-bold mt-2 text-slate-800">
                    <?= $total_emails; ?>
                </h2>

            </div>

            <div class="w-14 h-14 rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center shrink-0">

                <i class="fa-solid fa-envelope text-xl"></i>

            </div>

        </div>

    </div>


    <!-- SUCCESSFULLY SENT -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 animate-up delay-2">

        <div class="flex justify-between items-start gap-3">

            <div class="min-w-0">

                <p class="text-slate-500 text-sm">
                    <?= t('successfully_sent'); ?>
                </p>

                <h2 class="text-4xl font-bold mt-2 text-emerald-600">
                    <?= $total_sent; ?>
                </h2>

            </div>

            <div class="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">

                <i class="fa-solid fa-circle-check text-xl"></i>

            </div>

        </div>

    </div>


    <!-- FAILED EMAILS -->

    <div class="bg-white rounded-[26px] border border-slate-200 shadow-sm p-6 animate-up delay-3">

        <div class="flex justify-between items-start gap-3">

            <div class="min-w-0">

                <p class="text-slate-500 text-sm">
                    <?= t('failed_emails'); ?>
                </p>

                <h2 class="text-4xl font-bold mt-2 text-red-600">
                    <?= $total_failed; ?>
                </h2>

            </div>

            <div class="w-14 h-14 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center shrink-0">

                <i class="fa-solid fa-envelope-circle-xmark text-xl"></i>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     EMAIL HISTORY
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm overflow-hidden animate-up delay-3">

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 px-6 py-5 border-b border-slate-200">

        <div>

            <h2 class="text-xl font-bold text-slate-900">
                <?= t('email_history'); ?>
            </h2>

            <p class="text-slate-500 text-sm mt-1">
                <?= t('email_history_desc'); ?>
            </p>

        </div>


        <form method="GET" class="flex gap-2">

            <input
                type="text"
                name="search"
                value="<?= htmlspecialchars($search); ?>"
                placeholder="<?= t('search_email_logs_placeholder'); ?>"
                class="border border-slate-200 rounded-xl px-4 py-2 w-full sm:w-72 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-rmc-300 focus:border-rmc-300 outline-none transition"
            >

            <button
                type="submit"
                class="bg-rmc-800 hover:bg-rmc-900 text-white px-4 py-2 rounded-xl text-sm transition"
            >

                <i class="fa-solid fa-magnifying-glass"></i>

            </button>

            <?php if (!empty($search)): ?>

                <a
                    href="admin_email_logs.php"
                    class="border border-slate-200 px-4 py-2 rounded-xl text-sm text-slate-600 hover:bg-rmc-50 transition"
                >

                    <?= t('clear'); ?>

                </a>

            <?php endif; ?>

        </form>

    </div>


    <div class="overflow-x-auto">

        <table class="min-w-full">

            <thead class="bg-rmc-950 text-white">

                <tr>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('id'); ?>
                    </th>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('recipient'); ?>
                    </th>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('subject'); ?>
                    </th>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('date_sent'); ?>
                    </th>

                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">
                        <?= t('status'); ?>
                    </th>

                </tr>

            </thead>

            <tbody>

                <?php if (pg_num_rows($email_logs) > 0): ?>

                    <?php while ($row = pg_fetch_assoc($email_logs)): ?>

                        <tr class="border-b border-slate-100 hover:bg-rmc-50/40 transition">

                            <td class="px-6 py-5 text-slate-500">
                                <?= htmlspecialchars($row['log_id']); ?>
                            </td>

                            <td class="px-6 py-5 font-medium text-slate-800">
                                <?= htmlspecialchars($row['recipient_email']); ?>
                            </td>

                            <td class="px-6 py-5 text-slate-600">
                                <?= htmlspecialchars($row['subject']); ?>
                            </td>

                            <td class="px-6 py-5 text-slate-600 whitespace-nowrap">
                                <?= date("M d, Y h:i A", strtotime($row['created_at'])); ?>
                            </td>

                            <td class="px-6 py-5 text-center">

                                <span class="px-3 py-1 rounded-full text-xs font-semibold <?= status_badge($row['status']); ?>">

                                    <?= htmlspecialchars($row['status']); ?>

                                </span>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="5" class="text-center py-16 px-4">

                            <div class="flex flex-col items-center">

                                <div class="w-16 h-16 rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-4">

                                    <i class="fa-solid fa-envelope-open-text text-3xl"></i>

                                </div>

                                <h3 class="text-xl font-bold text-slate-700">

                                    <?= !empty($search) ? t('no_matching_emails_found') : t('no_email_logs_found'); ?>

                                </h3>

                                <p class="text-slate-500 mt-2 text-sm">

                                    <?= !empty($search) ? t('try_different_search') : t('no_email_logs_desc'); ?>

                                </p>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<?php include 'partials/footer.php'; ?>
