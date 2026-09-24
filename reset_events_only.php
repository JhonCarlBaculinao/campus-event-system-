<?php
/**
 * ==========================================================================
 *  ONE-TIME EVENTS-ONLY RESET
 * ==========================================================================
 *  Deletes ALL events and everything tied to them (registrations,
 *  attendance records, feedback, uploaded event photos), while leaving
 *  every user account (students, organizers, admins) completely intact.
 *
 *  HOW TO USE:
 *    1. Upload this file to the same folder as your other .php files.
 *    2. Visit it in your browser with the confirm flag, e.g.:
 *         https://baculinao.infinityfreeapp.com/reset_events_only.php?confirm=yes
 *    3. Read the summary it prints out.
 *    4. DELETE THIS FILE from the server right after running it —
 *       it is destructive and should never stay on a live site.
 * ==========================================================================
 */

require_once __DIR__ . '/db_connect.php';

// Destructive reset is CLI-only. It cannot be triggered from a public browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This maintenance script is CLI-only.');
}

$summary = [];

try {
    $pdo->beginTransaction();

    // 1. Attendance records (children of registrations)
    $summary['attendance_deleted'] = $pdo->exec("DELETE FROM attendance");

    // 2. Registrations (children of events)
    $summary['registrations_deleted'] = $pdo->exec("DELETE FROM registrations");

    // 3. Feedback (children of events)
    $summary['feedback_deleted'] = $pdo->exec("DELETE FROM feedback");

    // 4. Event photos / gallery images (children of events)
    $summary['event_photos_deleted'] = $pdo->exec("DELETE FROM event_photos");

    // 5. Audit log: keep the log entries (who did what), but clear the
    //    dangling reference to events that are about to be deleted.
    $summary['audit_log_unlinked'] = $pdo->exec("UPDATE audit_log SET target_event_id = NULL WHERE target_event_id IS NOT NULL");

    // 6. The events themselves
    $summary['events_deleted'] = $pdo->exec("DELETE FROM events");

    // 7. Reset AUTO_INCREMENT counters so new events/registrations start
    //    from a clean 1 again (cosmetic, but nice for a fresh demo).
    foreach (['events', 'registrations', 'attendance', 'feedback', 'event_photos'] as $table) {
        $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    die('Reset failed, nothing was changed: ' . htmlspecialchars($e->getMessage()));
}

$remaining_users = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

echo "<h2>Events reset complete</h2>";
echo "<ul>";
echo "<li>Events deleted: {$summary['events_deleted']}</li>";
echo "<li>Registrations deleted: {$summary['registrations_deleted']}</li>";
echo "<li>Attendance records deleted: {$summary['attendance_deleted']}</li>";
echo "<li>Feedback entries deleted: {$summary['feedback_deleted']}</li>";
echo "<li>Event photos deleted: {$summary['event_photos_deleted']}</li>";
echo "<li>Audit log entries unlinked (kept, reference cleared): {$summary['audit_log_unlinked']}</li>";
echo "<li><strong>Users untouched — {$remaining_users} accounts still in the system.</strong></li>";
echo "</ul>";
echo "<p><strong>Remember to delete this file from the server now.</strong></p>";
