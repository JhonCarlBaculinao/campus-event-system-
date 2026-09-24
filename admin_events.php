<?php


include 'db_connect.php';
require 'send_email.php';
require 'lang.php';
require 'csrf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die("Access denied. Admins only.");
}

$action_msg = '';


/* =========================================================
   APPROVE / REJECT EVENT
   ========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_verify();


    /* =====================================================
       APPROVE EVENT
       ===================================================== */

    if (isset($_POST['approve_id'])) {

        $event_id = (int)$_POST['approve_id'];


        /* Get event */

        $event_result = $pdo->prepare("SELECT
                e.title, e.event_date, e.start_time, e.end_time, e.venue,
                e.organizer_id, u.full_name AS organizer_name,
                u.email AS organizer_email, u.email_notifications AS organizer_email_notif
             FROM events e
             JOIN users u
             ON e.organizer_id = u.user_id
             WHERE e.event_id = ?");

        $event_result->execute([$event_id]);

        $event = $event_result->fetch(PDO::FETCH_ASSOC);


        if ($event) {

            /* Update status */

            $pdo->prepare("UPDATE events
                 SET status = 'approved'
                 WHERE event_id = ?
                 AND status = 'pending'")->execute([$event_id]);


            /* Admin success notification */

            $action_msg = 'Event approved successfully.';


            /* =================================================
               ORGANIZER NOTIFICATION
               ================================================= */

            $organizer_message =
                'Your event "' .
                $event['title'] .
                '" has been approved and is now available to students.';


            $pdo->prepare("INSERT INTO notifications
                (
                    user_id, message, type
                )
                VALUES
                (
                    ?,
                    ?,
                    ?
                )")->execute([
                    $event['organizer_id'],
                    $organizer_message,
                    'event_approval'
                ]);


            /* Organizer Gmail */

            $org_email_on = ($event['organizer_email_notif'] ?? 'f') == 1;

            if ($org_email_on && !empty($event['organizer_email'])) {

                send_email_deferred(

                    $event['organizer_email'],

                    'Event Approved',

                    '<h2>Event Approved</h2>

                    <p>Hello
                    <strong>' .
                    htmlspecialchars($event['organizer_name']) .
                    '</strong>!</p>

                    <p>Your event
                    <strong>' .
                    htmlspecialchars($event['title']) .
                    '</strong>
                    has been approved by the administrator.</p>

                    <p>The event is now visible to students.</p>

                    <p>
                    <strong>Date:</strong>
                    ' .
                    htmlspecialchars($event['event_date']) .
                    '
                    </p>

                    <p>
                    <strong>Venue:</strong>
                    ' .
                    htmlspecialchars($event['venue']) .
                    '
                    </p>'
                );
            }


            /* =================================================
               ALL STUDENTS
               ================================================= */

            $students = $pdo->query("SELECT
                    user_id,
                    full_name,
                    email,
                    email_notifications
                 FROM users
                 WHERE role = 'student'");


            while ($student = $students->fetch(PDO::FETCH_ASSOC)) {

                $student_message =
                    'A new event "' .
                    $event['title'] .
                    '" is now available for registration.';


                /* Site notification */

                $pdo->prepare("INSERT INTO notifications
                    (
                        user_id, message, type
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?
                    )")->execute([
                        $student['user_id'],
                        $student_message,
                        'new_event'
                    ]);


                /* Gmail */

                $stud_email_on = ($student['email_notifications'] ?? 'f') == 1;

                if ($stud_email_on && !empty($student['email'])) {

                    send_email_deferred(

                        $student['email'],

                        'New Campus Event Available',

                        '<h2>New Campus Event</h2>

                        <p>Hello
                        <strong>' .
                        htmlspecialchars($student['full_name']) .
                        '</strong>!</p>

                        <p>A new campus event is now available.</p>

                        <p>
                        <strong>Event:</strong>
                        ' .
                        htmlspecialchars($event['title']) .
                        '
                        </p>

                        <p>
                        <strong>Date:</strong>
                        ' .
                        htmlspecialchars($event['event_date']) .
                        '
                        </p>

                        <p>
                        <strong>Venue:</strong>
                        ' .
                        htmlspecialchars($event['venue']) .
                        '
                        </p>

                        <p>Please log in to the Campus Event Management System to register.</p>'
                    );
                }
            }


            /* =================================================
               OTHER ADMINS
               ================================================= */

            $admins = $pdo->prepare("SELECT user_id, email, full_name FROM users WHERE role = 'admin' AND user_id != ?");
            $admins->execute([$_SESSION['user_id']]);

            while ($admin = $admins->fetch(PDO::FETCH_ASSOC)) {

                $pdo->prepare("INSERT INTO notifications
                    (
                        user_id, message, type
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?
                    )")->execute([
                        $admin['user_id'],

                        'Event "' .
                        $event['title'] .
                        '" has been approved.',


                        'event_approval'
                    ]);


                if (!empty($admin['email'])) {

                    send_email_deferred(

                        $admin['email'],

                        'Event Approved',

                        '<h2>Event Approved</h2>

                        <p>The event
                        <strong>' .
                        htmlspecialchars($event['title']) .
                        '</strong>
                        has been approved by an administrator.</p>'
                    );
                }
            }
        }
    }


    /* =====================================================
       REJECT EVENT
       ===================================================== */

    if (isset($_POST['reject_id'])) {

        $event_id = (int)$_POST['reject_id'];


        /* Get event */

        $event_result = $pdo->prepare("SELECT
                e.title, e.organizer_id, u.full_name AS organizer_name,
                u.email AS organizer_email, u.email_notifications AS organizer_email_notif
             FROM events e
             JOIN users u
             ON e.organizer_id = u.user_id
             WHERE e.event_id = ?");

        $event_result->execute([$event_id]);

        $event = $event_result->fetch(PDO::FETCH_ASSOC);


        if ($event) {

            /* Update status */

            $pdo->prepare("UPDATE events
                 SET status = 'rejected'
                 WHERE event_id = ?
                 AND status = 'pending'")->execute([$event_id]);


            /* Admin rejection notification (red error style) */

            $reject_msg = 'Event rejected successfully.';


            /* =================================================
               ONLY ORGANIZER GETS REJECTION
               ================================================= */

            $message =
                'Your event "' .
                $event['title'] .
                '" has been rejected by the administrator.';


            $pdo->prepare("INSERT INTO notifications
                (
                    user_id, message, type
                )
                VALUES
                (
                    ?,
                    ?,
                    ?
                )")->execute([
                    $event['organizer_id'],
                    $message,
                    'event_rejection'
                ]);


            /* Gmail */

            $org_email_on = ($event['organizer_email_notif'] ?? 'f') == 1;

            if ($org_email_on && !empty($event['organizer_email'])) {

                    send_email_deferred(

                    $event['organizer_email'],

                    'Event Rejected',

                    '<h2>Event Rejected</h2>

                    <p>Hello
                    <strong>' .
                    htmlspecialchars($event['organizer_name']) .
                    '</strong>!</p>

                    <p>Your event
                    <strong>' .
                    htmlspecialchars($event['title']) .
                    '</strong>
                    was rejected by the administrator.</p>

                    <p>Please contact the administrator if you need additional information.</p>'
                );
            }
        }
    }


    /* =====================================================
       ARCHIVE EVENT  (soft-hide from students)
       ===================================================== */

    if (isset($_POST['archive_id'])) {

        $event_id = (int) $_POST['archive_id'];

        $pdo->prepare("UPDATE events
             SET status = 'archived'
             WHERE event_id = ?
               AND status = 'approved'")->execute([$event_id]);

        $action_msg = t('event_archived_msg');
    }


    /* =====================================================
       UNARCHIVE EVENT  (restore to students)
       ===================================================== */

    if (isset($_POST['unarchive_id'])) {

        $event_id = (int) $_POST['unarchive_id'];

        $pdo->prepare("UPDATE events
             SET status = 'approved'
             WHERE event_id = ?
               AND status = 'archived'")->execute([$event_id]);

        $action_msg = t('event_unarchived_msg');
    }


    /* =====================================================
       DELETE EVENT  (soft-delete, analytics preserved)
       Saves the current status into previous_status so it
       can be restored later.
       ===================================================== */

    if (isset($_POST['delete_id'])) {

        $event_id = (int) $_POST['delete_id'];

        $pdo->prepare("UPDATE events
             SET previous_status = status,
                 status = 'deleted'
             WHERE event_id = ?
               AND status != 'deleted'")->execute([$event_id]);

        $action_msg = t('event_deleted_msg');
    }


    /* =====================================================
       RESTORE EVENT  (undo soft-delete)
       Puts status back to whatever it was before delete
       (pending / approved / archived / rejected / cancelled).
       Falls back to 'pending' if previous_status was never set.
       ===================================================== */

    if (isset($_POST['restore_id'])) {

        $event_id = (int) $_POST['restore_id'];

        $pdo->prepare("UPDATE events
             SET status = COALESCE(previous_status, 'pending'),
                 previous_status = NULL
             WHERE event_id = ?
               AND status = 'deleted'")->execute([$event_id]);

        $action_msg = t('event_restored_msg');
    }


    /* =====================================================
       BULK ACTIONS
       ===================================================== */

    if (isset($_POST['bulk_action']) && isset($_POST['event_ids'])) {

        $action = $_POST['bulk_action'];
        $event_ids = array_map('intval', $_POST['event_ids']);
        $event_ids = array_filter($event_ids, function ($id) { return $id > 0; });
        $event_ids = array_values($event_ids);

        if (empty($event_ids)) {
            $action_msg = t('no_items_selected');
        } else {

            $processed = 0;
            $skipped = 0;

            foreach ($event_ids as $eid) {

                if ($action === 'bulk_archive') {
                    $r = $pdo->prepare("UPDATE events SET status = 'archived' WHERE event_id = ? AND status = 'approved'"); $r->execute([$eid]);
                } elseif ($action === 'bulk_delete') {
                    $r = $pdo->prepare("UPDATE events SET previous_status = status, status = 'deleted' WHERE event_id = ? AND status != 'deleted'"); $r->execute([$eid]);
                } elseif ($action === 'bulk_restore') {
                    $r = $pdo->prepare("UPDATE events SET status = COALESCE(previous_status, 'pending'), previous_status = NULL WHERE event_id = ? AND status = 'deleted'"); $r->execute([$eid]);
                } else {
                    continue;
                }

                if ($r && $r->rowCount() > 0) {
                    $processed++;
                } else {
                    $skipped++;
                }
            }

            if ($skipped === 0) {
                $action_msg = sprintf(t('bulk_action_success'), $processed);
            } else {
                $action_msg = sprintf(t('bulk_action_partial'), $processed, $skipped);
            }
        }
    }
}


/* =========================================================
   SEARCH EVENTS
   ========================================================= */

$search = isset($_GET['search'])
    ? trim($_GET['search'])
    : '';

$filter = isset($_GET['filter'])
    ? trim($_GET['filter'])
    : 'all';

$valid_filters = [
    'all',
    'pending',
    'approved',
    'archived',
    'rejected',
    'cancelled',
    'deleted'
];

if (!in_array($filter, $valid_filters, true)) {
    $filter = 'all';
}

$filter_sql = ($filter !== 'all' && $filter !== 'deleted')
    ? "AND e.status = ?"
    : '';

/* Only exclude deleted events when we are NOT specifically
   viewing the "deleted" tab. */
$exclude_deleted_sql = ($filter !== 'deleted')
    ? "AND e.status != 'deleted'"
    : "AND e.status = 'deleted'";


if (!empty($search)) {

    $events = $pdo->prepare("SELECT e.*, u.full_name AS organizer_name FROM events e JOIN users u ON e.organizer_id = u.user_id WHERE e.title LIKE ? $exclude_deleted_sql $filter_sql ORDER BY e.created_at DESC");

    $params = ['%' . $search . '%'];
    if ($filter_sql !== '') {
        $params[] = $filter;
    }

    $events->execute($params);

} else {

    $events = $pdo->prepare("SELECT e.*, u.full_name AS organizer_name FROM events e JOIN users u ON e.organizer_id = u.user_id WHERE 1=1 $exclude_deleted_sql $filter_sql ORDER BY e.created_at DESC");
    $events->execute($filter_sql !== '' ? [$filter] : []);
}




/* =========================================================
   SHARED PARTIAL VARIABLES
   ========================================================= */

$user_id = (int) $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? '';
$first_name = explode(' ', trim($full_name))[0];

$unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_stmt->execute([$user_id]);
$unread_count = (int) $unread_stmt->fetchColumn();

$recent_notifications = $pdo->prepare("SELECT notification_id, type, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$recent_notifications->execute([$user_id]);

$role_label  = 'Administrator';
$page_title  = t('title_manage_events');
$active_page = 'admin_events';

?>

<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     EVENT MANAGEMENT HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 mb-8 animate-up"
>

    <div class="flex items-center gap-4 sm:gap-5">

        <div
            class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
        >

            <i class="fa-solid fa-calendar-check text-2xl"></i>

        </div>

        <div>

            <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                <?= t('event_approval_pipeline'); ?>
            </h2>

            <p class="text-slate-600 mt-1 text-sm sm:text-base">
                <?= t('manage_events_hero_desc'); ?>
            </p>

        </div>

    </div>

</div>


<?php if (!empty($action_msg)): ?>

    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 mb-8 animate-up">

        <i class="fa-solid fa-circle-check mr-2"></i>

        <?= htmlspecialchars($action_msg); ?>

    </div>

<?php endif; ?>

<?php if (!empty($reject_msg)): ?>

    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 mb-8 animate-up">

        <i class="fa-solid fa-circle-xmark mr-2"></i>

        <?= htmlspecialchars($reject_msg); ?>

    </div>

<?php endif; ?>


<!-- =========================================================
     EVENTS TABLE
     ========================================================= -->

<div class="bg-white rounded-[26px] border border-slate-200 shadow-sm overflow-hidden animate-up delay-1">

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 px-6 py-5 border-b border-slate-200">

        <div>

            <h2 class="text-xl font-bold text-slate-900">
                <?= t('submitted_events'); ?>
            </h2>

            <p class="text-slate-500 text-sm mt-1">
                <?= t('submitted_events_desc'); ?>
            </p>

        </div>


        <form method="GET" class="flex gap-2">

            <input
                type="text"
                name="search"
                value="<?= htmlspecialchars($search); ?>"
                placeholder="<?= t('search_event_placeholder'); ?>"
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
                        href="admin_events.php"
                    class="border border-slate-200 px-4 py-2 rounded-xl text-sm text-slate-600 hover:bg-rmc-50 transition"
                >

                    <?= t('clear'); ?>

                </a>

            <?php endif; ?>

        </form>

    </div>


    <!-- STATUS FILTER TABS -->

    <div class="flex flex-wrap items-center gap-2 px-6 py-4 border-b border-slate-200 bg-rmc-50/40">

<?php foreach (['all', 'pending', 'approved', 'archived', 'rejected', 'cancelled', 'deleted'] as $fk): ?>

    <a href="admin_events.php?filter=<?= $fk; ?>"
       class="px-4 py-2 rounded-xl text-sm font-semibold transition <?= $filter === $fk ? 'bg-rmc-800 text-white' : 'bg-white text-slate-600 border border-slate-200 hover:bg-rmc-100'; ?>">

        <?= htmlspecialchars($fk === 'all' ? t('filter_all') : ucfirst($fk)); ?>

    </a>

<?php endforeach; ?>

    </div>


    <!-- BULK ACTION TOOLBAR (hidden by default) -->

    <div
        id="bulkEventBar"
        class="hidden px-6 py-3 border-b border-slate-200 bg-rmc-50/60"
    >

        <div class="flex items-center gap-4 flex-wrap">

            <span id="bulkEventCount" class="text-sm font-semibold text-rmc-800"></span>

            <form method="POST" id="bulkEventForm" class="flex items-center gap-3 flex-wrap">

                <?= csrf_field(); ?>

                <input type="hidden" name="bulk_action" id="bulkEventAction" value="">

                <?php if ($filter === 'deleted'): ?>

                    <button
                        type="button"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-emerald-100 text-emerald-700 hover:bg-emerald-200 transition inline-flex items-center gap-2"
                        onclick="submitBulkEvent('bulk_restore')"
                    >
                        <i class="fa-solid fa-rotate-left"></i>
                        <?= t('bulk_restore_events'); ?>
                    </button>

                <?php else: ?>

                    <button
                        type="button"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-amber-100 text-amber-700 hover:bg-amber-200 transition inline-flex items-center gap-2"
                        onclick="submitBulkEvent('bulk_archive')"
                    >
                        <i class="fa-solid fa-box-archive"></i>
                        <?= t('bulk_archive_events'); ?>
                    </button>

                    <button
                        type="button"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-red-100 text-red-700 hover:bg-red-200 transition inline-flex items-center gap-2"
                        onclick="submitBulkEvent('bulk_delete')"
                    >
                        <i class="fa-solid fa-trash"></i>
                        <?= t('bulk_delete_events'); ?>
                    </button>

                <?php endif; ?>

            </form>

        </div>

    </div>


    <div class="overflow-x-auto">

        <table class="min-w-full">

            <thead class="bg-rmc-950 text-white">

                <tr>

                    <th class="px-6 py-4 text-center w-10">
                        <input
                            type="checkbox"
                            id="selectAllEvents"
                            class="w-4 h-4 rounded border-slate-300 text-rmc-600 focus:ring-rmc-500 cursor-pointer"
                            aria-label="<?= t('select_all'); ?>"
                            onchange="toggleSelectAll(this, 'event-checkbox'); updateBulkBar();"
                        >
                    </th>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('title'); ?>
                    </th>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('organizer'); ?>
                    </th>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('date'); ?>
                    </th>

                    <th class="px-6 py-4 text-left text-xs uppercase tracking-wide">
                        <?= t('venue'); ?>
                    </th>

                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">
                        <?= t('status'); ?>
                    </th>

                    <th class="px-6 py-4 text-center text-xs uppercase tracking-wide">
                        <?= t('actions'); ?>
                    </th>

                </tr>

            </thead>

            <tbody>

                <?php if ($events->rowCount() === 0): ?>

                    <tr>

                        <td colspan="7" class="px-6 py-16 text-center">

                            <div class="flex flex-col items-center">

                                <div class="w-16 h-16 rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-4">

                                    <i class="fa-solid fa-calendar-xmark text-3xl"></i>

                                </div>

                                <p class="font-semibold text-slate-700">
                                    <?= t('no_events_found'); ?>
                                </p>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>


                <?php while ($row = $events->fetch(PDO::FETCH_ASSOC)): ?>

                    <tr class="border-b border-slate-100 hover:bg-rmc-50/40 transition <?= $row['status'] === 'archived' ? 'bg-slate-50 opacity-75' : ''; ?> <?= $row['status'] === 'deleted' ? 'bg-red-50/50 opacity-75' : ''; ?>">

                        <td class="px-6 py-5 text-center">
                            <input
                                type="checkbox"
                                name="event_ids[]"
                                value="<?= (int) $row['event_id']; ?>"
                                data-title="<?= htmlspecialchars($row['title'], ENT_QUOTES); ?>"
                                class="event-checkbox w-4 h-4 rounded border-slate-300 text-rmc-600 focus:ring-rmc-500 cursor-pointer"
                                onchange="updateBulkBar();"
                                aria-label="<?= htmlspecialchars($row['title']); ?>"
                            >
                        </td>

                        <td class="px-6 py-5 font-semibold text-slate-800">
                            <?= htmlspecialchars($row['title']); ?>
                        </td>

                        <td class="px-6 py-5 text-slate-600">
                            <?= htmlspecialchars($row['organizer_name']); ?>
                        </td>

                        <td class="px-6 py-5 text-slate-600 whitespace-nowrap">
                            <?= htmlspecialchars($row['event_date']); ?>
                        </td>

                        <td class="px-6 py-5 text-slate-600">
                            <?= htmlspecialchars($row['venue']); ?>
                        </td>

                        <td class="text-center">

                            <span class="px-3 py-1 rounded-full text-xs font-semibold <?= status_badge($row['status']); ?>">

                                <?= ucfirst(htmlspecialchars($row['status'])); ?>

                            </span>

                        </td>

                        <td class="px-6 py-5">

                            <div class="flex justify-center gap-2">

                                <?php if ($row['status'] === "pending"): ?>

                                    <!-- APPROVE -->

                                    <form method="POST">

                                        <?= csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="approve_id"
                                            value="<?= $row['event_id']; ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-sm transition"
                                            title="<?= t('approve_event'); ?>"
                                        >

                                            <i class="fa-solid fa-check"></i>

                                        </button>

                                    </form>


                                    <!-- REJECT -->

                                    <form method="POST" data-action-form="<?= $row['event_id']; ?>-reject">

                                        <?= csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="reject_id"
                                            value="<?= $row['event_id']; ?>"
                                        >

                                        <button
                                            type="button"
                                            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-xl text-sm transition"
                                            title="<?= t('reject_event'); ?>"
                                            onclick="openConfirmModal({form: this.closest('form'), title: <?= htmlspecialchars(json_encode(t('reject_event')), ENT_QUOTES) ?>, message: <?= htmlspecialchars(json_encode(t('reject_event_confirm')), ENT_QUOTES) ?>, itemName: <?= htmlspecialchars(json_encode(htmlspecialchars($row['title'], ENT_QUOTES)), ENT_QUOTES) ?>, itemLabel: <?= htmlspecialchars(json_encode(t('event')), ENT_QUOTES) ?>, actionText: <?= htmlspecialchars(json_encode(t('reject_event')), ENT_QUOTES) ?>, color: 'red', icon: 'fa-solid fa-xmark'});"
                                        >

                                            <i class="fa-solid fa-xmark"></i>

                                        </button>

                                    </form>

                                <?php elseif ($row['status'] === "approved"): ?>

                                    <!-- ARCHIVE -->

                                    <form method="POST" data-action-form="<?= $row['event_id']; ?>-archive">

                                        <?= csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="archive_id"
                                            value="<?= $row['event_id']; ?>"
                                        >

                                        <button
                                            type="button"
                                            class="bg-slate-600 hover:bg-slate-700 text-white px-4 py-2 rounded-xl text-sm transition"
                                            title="<?= t('archive_event'); ?>"
                                            onclick="openConfirmModal({form: this.closest('form'), title: <?= htmlspecialchars(json_encode(t('archive_event')), ENT_QUOTES) ?>, message: <?= htmlspecialchars(json_encode(t('archive_confirm')), ENT_QUOTES) ?>, itemName: <?= htmlspecialchars(json_encode(htmlspecialchars($row['title'], ENT_QUOTES)), ENT_QUOTES) ?>, itemLabel: <?= htmlspecialchars(json_encode(t('event')), ENT_QUOTES) ?>, actionText: <?= htmlspecialchars(json_encode(t('archive_event')), ENT_QUOTES) ?>, color: 'slate', icon: 'fa-solid fa-box-archive'});"
                                        >

                                            <i class="fa-solid fa-box-archive"></i>

                                        </button>

                                    </form>

                                <?php elseif ($row['status'] === "archived"): ?>

                                    <!-- UNARCHIVE -->

                                    <form method="POST" data-action-form="<?= $row['event_id']; ?>-unarchive">

                                        <?= csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="unarchive_id"
                                            value="<?= $row['event_id']; ?>"
                                        >

                                        <button
                                            type="button"
                                            class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-sm transition"
                                            title="<?= t('unarchive_event'); ?>"
                                            onclick="openConfirmModal({form: this.closest('form'), title: <?= htmlspecialchars(json_encode(t('unarchive_event')), ENT_QUOTES) ?>, message: <?= htmlspecialchars(json_encode(t('unarchive_confirm')), ENT_QUOTES) ?>, itemName: <?= htmlspecialchars(json_encode(htmlspecialchars($row['title'], ENT_QUOTES)), ENT_QUOTES) ?>, itemLabel: <?= htmlspecialchars(json_encode(t('event')), ENT_QUOTES) ?>, actionText: <?= htmlspecialchars(json_encode(t('unarchive_event')), ENT_QUOTES) ?>, color: 'emerald', icon: 'fa-solid fa-rotate-left'});"
                                        >

                                            <i class="fa-solid fa-rotate-left"></i>

                                        </button>

                                    </form>

                                <?php elseif ($row['status'] === "deleted"): ?>

                                    <!-- RESTORE -->

                                    <form method="POST" data-action-form="<?= $row['event_id']; ?>-restore">

                                        <?= csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="restore_id"
                                            value="<?= $row['event_id']; ?>"
                                        >

                                        <button
                                            type="button"
                                            class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-sm transition"
                                            title="<?= t('restore_event'); ?>"
                                            onclick="openConfirmModal({form: this.closest('form'), title: <?= htmlspecialchars(json_encode(t('restore_event')), ENT_QUOTES) ?>, message: <?= htmlspecialchars(json_encode(t('restore_confirm')), ENT_QUOTES) ?>, itemName: <?= htmlspecialchars(json_encode(htmlspecialchars($row['title'], ENT_QUOTES)), ENT_QUOTES) ?>, itemLabel: <?= htmlspecialchars(json_encode(t('event')), ENT_QUOTES) ?>, actionText: <?= htmlspecialchars(json_encode(t('restore_event')), ENT_QUOTES) ?>, color: 'emerald', icon: 'fa-solid fa-rotate-left'});"
                                        >

                                            <i class="fa-solid fa-rotate-left"></i>

                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span class="text-slate-400 text-sm italic px-2">

                                        <?= t('no_action_needed'); ?>

                                    </span>

                                <?php endif; ?>


                                <!-- DELETE (available for every non-deleted status) -->

                                <?php if ($row['status'] !== 'deleted'): ?>

                                    <form method="POST" data-action-form="<?= $row['event_id']; ?>-delete">

                                        <?= csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="delete_id"
                                            value="<?= $row['event_id']; ?>"
                                        >

                                        <button
                                            type="button"
                                            class="bg-red-100 hover:bg-red-200 text-red-700 px-4 py-2 rounded-xl text-sm transition"
                                            title="<?= t('delete_event'); ?>"
                                            onclick="openConfirmModal({form: this.closest('form'), title: <?= htmlspecialchars(json_encode(t('delete_event')), ENT_QUOTES) ?>, message: <?= htmlspecialchars(json_encode(t('delete_confirm')), ENT_QUOTES) ?>, itemName: <?= htmlspecialchars(json_encode(htmlspecialchars($row['title'], ENT_QUOTES)), ENT_QUOTES) ?>, itemLabel: <?= htmlspecialchars(json_encode(t('event')), ENT_QUOTES) ?>, actionText: <?= htmlspecialchars(json_encode(t('delete_event')), ENT_QUOTES) ?>, color: 'red', icon: 'fa-solid fa-trash'});"
                                        >

                                            <i class="fa-solid fa-trash"></i>

                                        </button>

                                    </form>

                                <?php endif; ?>

                            </div>

                        </td>

                    </tr>

                <?php endwhile; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- =========================================================
     GENERIC CONFIRMATION MODAL
     ========================================================= -->

<div
    id="confirmModal"
    class="hidden fixed inset-0 z-[80] bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4 transition-opacity duration-200"
    onclick="if (event.target === this) closeConfirmModal();"
    role="dialog"
    aria-modal="true"
    aria-labelledby="confirmModalTitle"
>

    <div class="bg-white rounded-3xl w-full max-w-md shadow-2xl overflow-hidden transform transition-transform duration-200 scale-95 opacity-0" id="confirmModalInner">

        <div id="confirmModalHeader" class="bg-red-700 text-white px-6 py-5 flex items-center gap-3">

            <div class="w-11 h-11 rounded-2xl bg-white/15 flex items-center justify-center shrink-0">

                <i id="confirmModalIcon" class="fa-solid fa-trash text-lg"></i>

            </div>

            <div class="min-w-0">

                <h3 id="confirmModalTitle" class="font-bold text-lg leading-snug">
                    <?= htmlspecialchars(t('confirm')); ?>
                </h3>

            </div>

        </div>

        <div class="p-6">

            <p id="confirmModalMessage" class="text-sm text-slate-600 leading-relaxed">
                <?= htmlspecialchars(t('delete_confirm')); ?>
            </p>

            <div id="confirmModalItemBox" class="mt-4 bg-red-50 border border-red-200 rounded-2xl px-4 py-3">

                <p id="confirmModalItemLabel" class="text-[10px] font-bold uppercase tracking-wide text-red-500 mb-1">
                    <?= htmlspecialchars(t('event')); ?>
                </p>

                <p id="confirmModalItemName" class="font-bold text-slate-900 break-words">
                    —
                </p>

            </div>

        </div>

        <div class="px-6 pb-6 flex flex-col-reverse sm:flex-row gap-3">

            <button
                type="button"
                onclick="closeConfirmModal();"
                class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold px-5 py-3 rounded-xl transition"
            >
                <?= htmlspecialchars(t('cancel')); ?>
            </button>

            <button
                type="button"
                id="confirmModalConfirmBtn"
                class="flex-1 bg-red-700 hover:bg-red-800 text-white font-bold px-5 py-3 rounded-xl transition inline-flex items-center justify-center gap-2"
            >
                <i id="confirmModalBtnIcon" class="fa-solid fa-trash"></i>
                <span id="confirmModalBtnText"><?= htmlspecialchars(t('confirm')); ?></span>
            </button>

        </div>

    </div>

</div>


<script>

/* =========================================================
   GENERIC CONFIRMATION MODAL
   ========================================================= */

var CONFIRM_PENDING_FORM = null;

var COLOR_MAP = {
    red:    { header: 'bg-red-700',    itemBg: 'bg-red-50',    itemBorder: 'border-red-200',    itemLabel: 'text-red-500',    btn: 'bg-red-700 hover:bg-red-800' },
    amber:  { header: 'bg-amber-700',  itemBg: 'bg-amber-50',  itemBorder: 'border-amber-200',  itemLabel: 'text-amber-500',  btn: 'bg-amber-700 hover:bg-amber-800' },
    slate:  { header: 'bg-slate-700',  itemBg: 'bg-slate-50',  itemBorder: 'border-slate-200',  itemLabel: 'text-slate-500',  btn: 'bg-slate-700 hover:bg-slate-800' },
    emerald:{ header: 'bg-emerald-700',itemBg: 'bg-emerald-50',itemBorder: 'border-emerald-200',itemLabel: 'text-emerald-500',btn: 'bg-emerald-700 hover:bg-emerald-800' },
    blue:   { header: 'bg-blue-700',   itemBg: 'bg-blue-50',   itemBorder: 'border-blue-200',   itemLabel: 'text-blue-500',   btn: 'bg-blue-700 hover:bg-blue-800' }
};

function openConfirmModal(opts) {

    CONFIRM_PENDING_FORM = opts.form || null;

    var c = COLOR_MAP[opts.color] || COLOR_MAP.red;

    document.getElementById('confirmModalHeader').className = c.header + ' text-white px-6 py-5 flex items-center gap-3';
    document.getElementById('confirmModalIcon').className = (opts.icon || 'fa-solid fa-trash') + ' text-lg';
    document.getElementById('confirmModalTitle').textContent = opts.title || '';
    document.getElementById('confirmModalMessage').textContent = opts.message || '';

    var itemBox = document.getElementById('confirmModalItemBox');
    itemBox.className = 'mt-4 ' + c.itemBg + ' border ' + c.itemBorder + ' rounded-2xl px-4 py-3';

    document.getElementById('confirmModalItemLabel').className = 'text-[10px] font-bold uppercase tracking-wide ' + c.itemLabel + ' mb-1';
    document.getElementById('confirmModalItemLabel').textContent = opts.itemLabel || '';
    document.getElementById('confirmModalItemName').textContent = opts.itemName || '—';

    var btn = document.getElementById('confirmModalConfirmBtn');
    btn.className = 'flex-1 ' + c.btn + ' text-white font-bold px-5 py-3 rounded-xl transition inline-flex items-center justify-center gap-2';
    document.getElementById('confirmModalBtnIcon').className = (opts.icon || 'fa-solid fa-trash');
    document.getElementById('confirmModalBtnText').textContent = opts.actionText || '';

    var modal = document.getElementById('confirmModal');
    var inner = document.getElementById('confirmModalInner');
    modal.classList.remove('hidden');
    inner.style.transform = 'scale(0.95)';
    inner.style.opacity = '0';

    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            inner.style.transform = 'scale(1)';
            inner.style.opacity = '1';
        });
    });

    document.body.style.overflow = 'hidden';

    setTimeout(function() { btn.focus(); }, 200);
}

function closeConfirmModal() {

    var modal = document.getElementById('confirmModal');
    var inner = document.getElementById('confirmModalInner');
    inner.style.transform = 'scale(0.95)';
    inner.style.opacity = '0';

    setTimeout(function() {
        modal.classList.add('hidden');
        inner.style.transform = '';
        inner.style.opacity = '';
        document.body.style.overflow = '';
    }, 150);

    CONFIRM_PENDING_FORM = null;
}

document.getElementById('confirmModalConfirmBtn')
    .addEventListener('click', function () {

        if (CONFIRM_PENDING_FORM) {
            CONFIRM_PENDING_FORM.submit();
        }
    });


/* =========================================================
   SELECT ALL + BULK ACTIONS
   ========================================================= */

function toggleSelectAll(master, groupClass) {

    var boxes = document.querySelectorAll('.' + groupClass);
    for (var i = 0; i < boxes.length; i++) {
        boxes[i].checked = master.checked;
    }
}

function updateBulkBar() {

    var checked = document.querySelectorAll('.event-checkbox:checked');
    var count = checked.length;

    var bar = document.getElementById('bulkEventBar');
    var countEl = document.getElementById('bulkEventCount');
    var master = document.getElementById('selectAllEvents');
    var allBoxes = document.querySelectorAll('.event-checkbox');

    if (count > 0) {
        bar.classList.remove('hidden');
        countEl.textContent = count + ' ' + <?= json_encode(t('selected_count')); ?>.replace('%d', count);
    } else {
        bar.classList.add('hidden');
    }

    if (master && allBoxes.length > 0) {
        master.checked = count === allBoxes.length;
        master.indeterminate = count > 0 && count < allBoxes.length;
    }
}

function submitBulkEvent(action) {

    var checked = document.querySelectorAll('.event-checkbox:checked');
    if (checked.length === 0) {
        return;
    }

    var titles = [];
    for (var i = 0; i < checked.length; i++) {
        titles.push(checked[i].getAttribute('data-title'));
    }

    var confirmKey, actionKey, icon, color;

    if (action === 'bulk_archive') {
        confirmKey = <?= json_encode(t('bulk_archive_confirm')) ?>;
        actionKey = <?= json_encode(t('bulk_archive_events')) ?>;
        icon = 'fa-solid fa-box-archive';
        color = 'amber';
    } else if (action === 'bulk_restore') {
        confirmKey = <?= json_encode(t('bulk_restore_confirm')) ?>;
        actionKey = <?= json_encode(t('bulk_restore_events')) ?>;
        icon = 'fa-solid fa-rotate-left';
        color = 'emerald';
    } else {
        confirmKey = <?= json_encode(t('bulk_delete_confirm')) ?>;
        actionKey = <?= json_encode(t('bulk_delete_events')) ?>;
        icon = 'fa-solid fa-trash';
        color = 'red';
    }

    var form = document.getElementById('bulkEventForm');
    var actionInput = document.getElementById('bulkEventAction');
    actionInput.value = action;

    var oldInputs = form.querySelectorAll('.bulk-event-id-input');
    for (var j = 0; j < oldInputs.length; j++) { oldInputs[j].remove(); }

    for (var k = 0; k < checked.length; k++) {
        var hid = document.createElement('input');
        hid.type = 'hidden';
        hid.name = 'event_ids[]';
        hid.value = checked[k].value;
        hid.className = 'bulk-event-id-input';
        form.appendChild(hid);
    }

    openConfirmModal({
        form: form,
        title: actionKey,
        message: confirmKey.replace('%d', checked.length),
        itemName: titles.join(', '),
        itemLabel: <?= json_encode(t('selected_count')); ?>.replace('%d', checked.length),
        actionText: actionKey,
        color: color,
        icon: icon
    });
}


/* =========================================================
   KEYBOARD: ESC TO CLOSE
   ========================================================= */

document.addEventListener('keydown', function (event) {

    if (event.key === 'Escape') {
        closeConfirmModal();
    }

    if (event.key === 'Tab' && !document.getElementById('confirmModal').classList.contains('hidden')) {
        var modal = document.getElementById('confirmModalInner');
        var focusable = modal.querySelectorAll('button:not([disabled])');
        if (focusable.length === 0) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey) {
            if (document.activeElement === first) { event.preventDefault(); last.focus(); }
        } else {
            if (document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    }
});

</script>


<?php include 'partials/footer.php'; ?>