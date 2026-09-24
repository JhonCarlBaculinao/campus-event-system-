<?php
/**
 * ==========================================================================
 *  ONE-TIME DEMO DATA SEEDER
 * ==========================================================================
 *  Registers every active student to every approved event (respecting
 *  registration limits), then marks a realistic portion of those
 *  registrations as "attended" so reports/analytics show real numbers.
 *
 *  HOW TO USE:
 *    1. Upload this file to the same folder as your other .php files
 *       (both locally and/or on InfinityFree).
 *    2. Visit it in your browser, e.g.:
 *         http://localhost/campus_event_system/seed_demo_data.php?confirm=yes
 *       or on InfinityFree:
 *         https://baculinao.infinityfreeapp.com/seed_demo_data.php?confirm=yes
 *    3. Read the summary it prints out.
 *    4. DELETE THIS FILE from the server afterward (local and live) —
 *       it is not meant to stay on a live site.
 *
 *  SETTINGS you can tweak below:
 *    $attendance_rate   -> % of registrations marked as attended (0-100)
 *    $registration_rate -> % of students registered per event (0-100)
 * ==========================================================================
 */

require_once __DIR__ . '/db_connect.php';

// Demo seeding is CLI-only to prevent accidental public data modification.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Demo data seeding is available from the command line only.');
}

// ---- SETTINGS ----
$registration_rate = 90; // % of students registered per event
$attendance_rate    = 75; // % of those registrations marked attended

// ---- FETCH ALL ACTIVE STUDENTS ----
$students = $pdo->query("SELECT user_id FROM users WHERE role = 'student' AND status = 'active'")
    ->fetchAll(PDO::FETCH_COLUMN);

if (empty($students)) {
    die('No active students found. Create/approve some student accounts first.');
}

// ---- FETCH ALL APPROVED EVENTS ----
$events = $pdo->query("SELECT event_id, registration_limit FROM events WHERE status = 'approved'")
    ->fetchAll(PDO::FETCH_ASSOC);

if (empty($events)) {
    die('No approved events found. Create and approve some events first.');
}

$reg_stmt = $pdo->prepare(
    "INSERT INTO registrations (event_id, user_id, qr_code, status) VALUES (?, ?, ?, 'registered')"
);

$check_stmt = $pdo->prepare(
    "SELECT registration_id FROM registrations WHERE event_id = ? AND user_id = ?"
);

$att_stmt = $pdo->prepare("
    INSERT INTO attendance
        (registration_id, verified, scanned_at, checked_in_at, scan_method, scanned_by, token_hash)
    VALUES
        (?, TRUE, NOW(), NOW(), 'manual', NULL, ?)
");

$total_registered = 0;
$total_attended    = 0;
$total_skipped     = 0;

foreach ($events as $event) {
    $event_id = $event['event_id'];
    $limit    = (int) $event['registration_limit'];

    // Shuffle students so each event gets a different random subset
    $pool = $students;
    shuffle($pool);

    // How many students to register for this event
    $target = (int) ceil(count($pool) * ($registration_rate / 100));
    if ($limit > 0) {
        $target = min($target, $limit);
    }

    $registered_this_event = 0;

    foreach ($pool as $student_id) {
        if ($registered_this_event >= $target) {
            break;
        }

        // Skip if already registered (safe to re-run)
        $check_stmt->execute([$event_id, $student_id]);
        if ($check_stmt->fetch()) {
            $total_skipped++;
            continue;
        }

        $qr_code = bin2hex(random_bytes(16));

        try {
            $reg_stmt->execute([$event_id, $student_id, $qr_code]);
        } catch (PDOException $e) {
            $total_skipped++;
            continue;
        }

        $registration_id = $pdo->lastInsertId();
        $registered_this_event++;
        $total_registered++;

        // Randomly mark a portion of registrations as attended
        if (mt_rand(1, 100) <= $attendance_rate) {
            $token_hash = hash('sha256', $qr_code);
            try {
                $att_stmt->execute([$registration_id, $token_hash]);
                $total_attended++;
            } catch (PDOException $e) {
                // ignore, non-critical for demo purposes
            }
        }
    }
}

echo "<h2>Demo data seeding complete</h2>";
echo "<ul>";
echo "<li>Students found: " . count($students) . "</li>";
echo "<li>Approved events found: " . count($events) . "</li>";
echo "<li>New registrations created: {$total_registered}</li>";
echo "<li>Marked as attended: {$total_attended}</li>";
echo "<li>Skipped (already registered): {$total_skipped}</li>";
echo "</ul>";
echo "<p><strong>Remember to delete this file from the server now.</strong></p>";
