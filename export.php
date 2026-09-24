<?php
if (!defined('BASE_URL')) {
    $script_path = parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH) ?: '';
    $base_path = rtrim(str_replace('\\', '/', dirname($script_path)), '/');
    define('BASE_URL', ($base_path === '/' || $base_path === '.') ? '' : $base_path);
}
include 'db_connect.php';
require 'lang.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    http_response_code(403);
    die("Access denied.");
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

$action = $_GET['action'] ?? '';
$format = 'csv';

/*
|--------------------------------------------------------------------------
| CSV HELPER
|--------------------------------------------------------------------------
*/

function rmc_csv_headers($filename)
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    return $out;
}

function rmc_csv_row(&$out, $row)
{
    fputcsv($out, $row);
}

/*
|--------------------------------------------------------------------------
| ADMIN EXPORTS
|--------------------------------------------------------------------------
*/

if ($role === 'admin') {

    switch ($action) {

        case 'users':
            $res = $pdo->prepare(
                "SELECT user_id, full_name, student_id, email, department, role, status, created_at
                 FROM users
                 ORDER BY user_id ASC"
            );
            $res->execute();

            $out = rmc_csv_headers('rmc_users_' . date('Y-m-d') . '.csv');
            rmc_csv_row($out, ['User ID', 'Full Name', 'Student ID', 'Email', 'Department', 'Role', 'Status', 'Registered']);
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                rmc_csv_row($out, [
                    $row['user_id'],
                    $row['full_name'],
                    $row['student_id'],
                    $row['email'],
                    $row['department'],
                    $row['role'],
                    $row['status'],
                    $row['created_at'],
                ]);
            }
            fclose($out);
            exit();

        case 'events':
            $res = $pdo->prepare(
                "SELECT e.event_id, e.title, e.category, e.event_date, e.start_time, e.end_time,
                        e.venue, e.registration_limit, e.status, e.created_at,
                        u.full_name AS organizer,
                        (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.event_id) AS registrations
                 FROM events e
                 LEFT JOIN users u ON e.organizer_id = u.user_id
                 WHERE e.status != 'deleted'
                 ORDER BY e.event_date DESC"
            );
            $res->execute();

            $out = rmc_csv_headers('rmc_events_' . date('Y-m-d') . '.csv');
            rmc_csv_row($out, ['Event ID', 'Title', 'Category', 'Date', 'Start', 'End', 'Venue', 'Limit', 'Status', 'Organizer', 'Registrations', 'Created']);
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                rmc_csv_row($out, [
                    $row['event_id'],
                    $row['title'],
                    $row['category'],
                    $row['event_date'],
                    $row['start_time'],
                    $row['end_time'],
                    $row['venue'],
                    $row['registration_limit'],
                    $row['status'],
                    $row['organizer'],
                    $row['registrations'],
                    $row['created_at'],
                ]);
            }
            fclose($out);
            exit();

        case 'registrations':
            $res = $pdo->prepare(
                "SELECT r.registration_id, r.status AS reg_status, r.registered_at,
                        e.title AS event_title, e.event_date,
                        u.full_name, u.student_id, u.email, u.department
                 FROM registrations r
                 JOIN events e ON r.event_id = e.event_id
                 JOIN users u ON r.user_id = u.user_id
                 ORDER BY r.registered_at DESC"
            );
            $res->execute();

            $out = rmc_csv_headers('rmc_registrations_' . date('Y-m-d') . '.csv');
            rmc_csv_row($out, ['Registration ID', 'Student', 'Student ID', 'Email', 'Department', 'Event', 'Event Date', 'Status', 'Registered At']);
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                rmc_csv_row($out, [
                    $row['registration_id'],
                    $row['full_name'],
                    $row['student_id'],
                    $row['email'],
                    $row['department'],
                    $row['event_title'],
                    $row['event_date'],
                    $row['reg_status'],
                    $row['registered_at'],
                ]);
            }
            fclose($out);
            exit();

        case 'attendance':
            $res = $pdo->prepare(
                "SELECT a.attendance_id, a.scanned_at, a.verified,
                        e.title AS event_title, e.event_date,
                        u.full_name, u.student_id, u.department
                 FROM attendance a
                 JOIN registrations r ON a.registration_id = r.registration_id
                 JOIN events e ON r.event_id = e.event_id
                 JOIN users u ON r.user_id = u.user_id
                 ORDER BY a.scanned_at DESC"
            );
            $res->execute();

            $out = rmc_csv_headers('rmc_attendance_' . date('Y-m-d') . '.csv');
            rmc_csv_row($out, ['Attendance ID', 'Student', 'Student ID', 'Department', 'Event', 'Event Date', 'Verified', 'Scan Time']);
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                rmc_csv_row($out, [
                    $row['attendance_id'],
                    $row['full_name'],
                    $row['student_id'],
                    $row['department'],
                    $row['event_title'],
                    $row['event_date'],
                    $row['verified'] ? 'Yes' : 'No',
                    $row['scanned_at'],
                ]);
            }
            fclose($out);
            exit();

        case 'feedback':
            $res = $pdo->prepare(
                "SELECT f.feedback_id, f.rating, f.comment, f.created_at,
                        e.title AS event_title,
                        u.full_name, u.student_id
                 FROM feedback f
                 JOIN events e ON f.event_id = e.event_id
                 JOIN users u ON f.user_id = u.user_id
                 ORDER BY f.created_at DESC"
            );
            $res->execute();

            $out = rmc_csv_headers('rmc_feedback_' . date('Y-m-d') . '.csv');
            rmc_csv_row($out, ['Feedback ID', 'Student', 'Student ID', 'Event', 'Rating', 'Comment', 'Date']);
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                rmc_csv_row($out, [
                    $row['feedback_id'],
                    $row['full_name'],
                    $row['student_id'],
                    $row['event_title'],
                    $row['rating'],
                    $row['comment'],
                    $row['created_at'],
                ]);
            }
            fclose($out);
            exit();

        case 'email_logs':
            $res = $pdo->prepare(
                "SELECT log_id, recipient_email, subject, status, created_at
                 FROM email_logs
                 ORDER BY created_at DESC
                 LIMIT 5000"
            );
            $res->execute();

            $out = rmc_csv_headers('rmc_email_logs_' . date('Y-m-d') . '.csv');
            rmc_csv_row($out, ['Log ID', 'Recipient', 'Subject', 'Status', 'Sent At']);
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                rmc_csv_row($out, [
                    $row['log_id'],
                    $row['recipient_email'],
                    $row['subject'],
                    $row['status'],
                    $row['created_at'],
                ]);
            }
            fclose($out);
            exit();

        case 'analytics_summary':
            $out = rmc_csv_headers('rmc_analytics_' . date('Y-m-d') . '.csv');

            rmc_csv_row($out, ['Metric', 'Value']);
            rmc_csv_row($out, ['']);

            $r = $pdo->query("SELECT COUNT(*) FROM events WHERE status != 'deleted'");
            rmc_csv_row($out, ['Total Events', ($r->fetch(PDO::FETCH_NUM) ?: [0])[0]]);

            $r = $pdo->query("SELECT COUNT(*) FROM events WHERE status = 'approved'");
            rmc_csv_row($out, ['Approved Events', ($r->fetch(PDO::FETCH_NUM) ?: [0])[0]]);

            $r = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'");
            rmc_csv_row($out, ['Active Students', ($r->fetch(PDO::FETCH_NUM) ?: [0])[0]]);

            $r = $pdo->query("SELECT COUNT(*) FROM registrations");
            rmc_csv_row($out, ['Total Registrations', ($r->fetch(PDO::FETCH_NUM) ?: [0])[0]]);

            $r = $pdo->query("SELECT COUNT(*) FROM users");
            rmc_csv_row($out, ['Total Users', ($r->fetch(PDO::FETCH_NUM) ?: [0])[0]]);

            $r = $pdo->query("SELECT COUNT(*) FROM attendance WHERE verified = 1");
            rmc_csv_row($out, ['Verified Attendance', ($r->fetch(PDO::FETCH_NUM) ?: [0])[0]]);

            rmc_csv_row($out, ['']);
            rmc_csv_row($out, ['Event', 'Category', 'Date', 'Registrations', 'Attended', 'Attendance Rate', 'Avg Rating']);

            $res = $pdo->query(
                "SELECT e.title, e.category, e.event_date,
                        (SELECT COUNT(*) FROM registrations r2 WHERE r2.event_id = e.event_id) AS reg_count,
                        (SELECT COUNT(*) FROM registrations r3 JOIN attendance a ON r3.registration_id = a.registration_id WHERE r3.event_id = e.event_id) AS att_count,
                        (SELECT ROUND(AVG(f2.rating), 1) FROM feedback f2 WHERE f2.event_id = e.event_id) AS avg_rating
                 FROM events e
                 WHERE e.status = 'approved'
                 ORDER BY e.event_date DESC"
            );

            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $rate = $row['reg_count'] > 0
                    ? round(($row['att_count'] / $row['reg_count']) * 100, 1) . '%'
                    : '0%';
                rmc_csv_row($out, [
                    $row['title'],
                    $row['category'],
                    $row['event_date'],
                    $row['reg_count'],
                    $row['att_count'],
                    $rate,
                    $row['avg_rating'] ?? 'N/A',
                ]);
            }

            fclose($out);
            exit();
    }

} elseif ($role === 'organizer') {

    switch ($action) {

        case 'my_events':
            $res = $pdo->prepare(
                "SELECT e.event_id, e.title, e.category, e.event_date, e.start_time, e.end_time,
                        e.venue, e.status, e.created_at,
                        (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.event_id) AS registrations
                 FROM events e
                 WHERE e.organizer_id = ? AND e.status != 'deleted'
                 ORDER BY e.event_date DESC"
            );
            $res->execute([$user_id]);

            $out = rmc_csv_headers('rmc_my_events_' . date('Y-m-d') . '.csv');
            rmc_csv_row($out, ['Event ID', 'Title', 'Category', 'Date', 'Start', 'End', 'Venue', 'Status', 'Registrations', 'Created']);
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                rmc_csv_row($out, [
                    $row['event_id'],
                    $row['title'],
                    $row['category'],
                    $row['event_date'],
                    $row['start_time'],
                    $row['end_time'],
                    $row['venue'],
                    $row['status'],
                    $row['registrations'],
                    $row['created_at'],
                ]);
            }
            fclose($out);
            exit();

        case 'event_participants':
            $event_id = (int)($_GET['event_id'] ?? 0);
            if ($event_id <= 0) { die("Invalid event."); }

            $chk = $pdo->prepare("SELECT event_id FROM events WHERE event_id = ? AND organizer_id = ? LIMIT 1");
            $chk->execute([$event_id, $user_id]);
            if (!$chk || $chk->fetchColumn() === false) { die("Access denied."); }

            $res = $pdo->prepare(
                "SELECT r.registration_id, r.registered_at,
                        u.full_name, u.student_id, u.email, u.department,
                        CASE WHEN a.attendance_id IS NOT NULL THEN 'Yes' ELSE 'No' END AS attended,
                        a.scanned_at
                 FROM registrations r
                 JOIN users u ON r.user_id = u.user_id
                 LEFT JOIN attendance a ON r.registration_id = a.registration_id
                 WHERE r.event_id = ?
                 ORDER BY u.full_name ASC"
            );
            $res->execute([$event_id]);

            $out = rmc_csv_headers('rmc_event_' . $event_id . '_participants_' . date('Y-m-d') . '.csv');
            rmc_csv_row($out, ['Registration ID', 'Student', 'Student ID', 'Email', 'Department', 'Attended', 'Check-in Time', 'Registered At']);
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                rmc_csv_row($out, [
                    $row['registration_id'],
                    $row['full_name'],
                    $row['student_id'],
                    $row['email'],
                    $row['department'],
                    $row['attended'],
                    $row['scanned_at'] ?? 'N/A',
                    $row['registered_at'],
                ]);
            }
            fclose($out);
            exit();

        case 'event_attendance':
            $event_id = (int)($_GET['event_id'] ?? 0);
            if ($event_id <= 0) { die("Invalid event."); }

            $chk = $pdo->prepare("SELECT event_id FROM events WHERE event_id = ? AND organizer_id = ? LIMIT 1");
            $chk->execute([$event_id, $user_id]);
            if (!$chk || $chk->fetchColumn() === false) { die("Access denied."); }

            $res = $pdo->prepare(
                "SELECT a.attendance_id, a.scanned_at, a.verified,
                        u.full_name, u.student_id, u.department
                 FROM attendance a
                 JOIN registrations r ON a.registration_id = r.registration_id
                 JOIN users u ON r.user_id = u.user_id
                 WHERE r.event_id = ?
                 ORDER BY a.scanned_at DESC"
            );
            $res->execute([$event_id]);

            $out = rmc_csv_headers('rmc_event_' . $event_id . '_attendance_' . date('Y-m-d') . '.csv');
            rmc_csv_row($out, ['Attendance ID', 'Student', 'Student ID', 'Department', 'Verified', 'Scan Time']);
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                rmc_csv_row($out, [
                    $row['attendance_id'],
                    $row['full_name'],
                    $row['student_id'],
                    $row['department'],
                    $row['verified'] ? 'Yes' : 'No',
                    $row['scanned_at'],
                ]);
            }
            fclose($out);
            exit();

        case 'event_feedback':
            $event_id = (int)($_GET['event_id'] ?? 0);
            if ($event_id <= 0) { die("Invalid event."); }

            $chk = $pdo->prepare("SELECT event_id FROM events WHERE event_id = ? AND organizer_id = ? LIMIT 1");
            $chk->execute([$event_id, $user_id]);
            if (!$chk || $chk->fetchColumn() === false) { die("Access denied."); }

            $res = $pdo->prepare(
                "SELECT f.feedback_id, f.rating, f.comment, f.created_at,
                        u.full_name, u.student_id
                 FROM feedback f
                 JOIN users u ON f.user_id = u.user_id
                 WHERE f.event_id = ?
                 ORDER BY f.created_at DESC"
            );
            $res->execute([$event_id]);

            $out = rmc_csv_headers('rmc_event_' . $event_id . '_feedback_' . date('Y-m-d') . '.csv');
            rmc_csv_row($out, ['Feedback ID', 'Student', 'Student ID', 'Rating', 'Comment', 'Date']);
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                rmc_csv_row($out, [
                    $row['feedback_id'],
                    $row['full_name'],
                    $row['student_id'],
                    $row['rating'],
                    $row['comment'],
                    $row['created_at'],
                ]);
            }
            fclose($out);
            exit();
    }

} elseif ($role === 'student') {

    switch ($action) {

        case 'my_registrations':
            $res = $pdo->prepare(
                "SELECT r.registration_id, r.status, r.registered_at,
                        e.title, e.event_date, e.venue, e.category,
                        CASE WHEN a.attendance_id IS NOT NULL THEN 'Yes' ELSE 'No' END AS attended,
                        a.scanned_at
                 FROM registrations r
                 JOIN events e ON r.event_id = e.event_id
                 LEFT JOIN attendance a ON r.registration_id = a.registration_id
                 WHERE r.user_id = ?
                 ORDER BY e.event_date DESC"
            );
            $res->execute([$user_id]);

            $out = rmc_csv_headers('rmc_my_registrations_' . date('Y-m-d') . '.csv');
            rmc_csv_row($out, ['Registration ID', 'Event', 'Category', 'Date', 'Venue', 'Status', 'Attended', 'Check-in Time', 'Registered At']);
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                rmc_csv_row($out, [
                    $row['registration_id'],
                    $row['title'],
                    $row['category'],
                    $row['event_date'],
                    $row['venue'],
                    $row['status'],
                    $row['attended'],
                    $row['scanned_at'] ?? 'N/A',
                    $row['registered_at'],
                ]);
            }
            fclose($out);
            exit();

        case 'my_activity':
            $res = $pdo->prepare(
                "SELECT a.attendance_id, a.scanned_at, a.verified,
                        e.title, e.event_date, e.venue
                 FROM attendance a
                 JOIN registrations r ON a.registration_id = r.registration_id
                 JOIN events e ON r.event_id = e.event_id
                 WHERE r.user_id = ?
                 ORDER BY a.scanned_at DESC"
            );
            $res->execute([$user_id]);

            $out = rmc_csv_headers('rmc_my_activity_' . date('Y-m-d') . '.csv');
            rmc_csv_row($out, ['Attendance ID', 'Event', 'Date', 'Venue', 'Verified', 'Check-in Time']);
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                rmc_csv_row($out, [
                    $row['attendance_id'],
                    $row['title'],
                    $row['event_date'],
                    $row['venue'],
                    $row['verified'] ? 'Yes' : 'No',
                    $row['scanned_at'],
                ]);
            }
            fclose($out);
            exit();
    }
}

die("Invalid export action.");