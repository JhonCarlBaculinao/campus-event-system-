<?php


require 'db_connect.php';
require 'lang.php';

require 'csrf.php';

if (!isset($_SESSION['user_id'])) {

    header("Location: login.php");
    exit();

}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$full_name = $_SESSION['full_name'] ?? '';
$first_name = explode(' ', trim($full_name))[0];

$action_msg = '';

/* =========================================================
   HANDLE POST ACTIONS
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    // Mark single notification as read (via AJAX or button)
    if (isset($_POST['mark_single_read'])) {

        $nid = (int) $_POST['mark_single_read'];

        if ($nid > 0) {
            $pdo->prepare("UPDATE notifications SET is_read = 1
                 WHERE notification_id = ? AND user_id = ?")->execute([$nid, $user_id]);
        }
    }

    // Delete single notification
    if (isset($_POST['delete_single'])) {

        $nid = (int) $_POST['delete_single'];

        if ($nid > 0) {
            $pdo->prepare("DELETE FROM notifications
                 WHERE notification_id = ? AND user_id = ?")->execute([$nid, $user_id]);
        }
    }

    // Delete selected notifications
    if (isset($_POST['delete_selected']) && !empty($_POST['notif_ids'])) {

        $ids = array_map('intval', $_POST['notif_ids']);
        $ids = array_filter($ids, function ($id) { return $id > 0; });
        $ids = array_values($ids);

        if (!empty($ids)) {

            $placeholders = [];
            $params = [];

            foreach ($ids as $i => $id) {
                $placeholders[] = '?';
                $params[] = $id;
            }

            $params[] = $user_id;
            $uid_idx = count($ids) + 1;

            $pdo->prepare("DELETE FROM notifications
                 WHERE notification_id IN (" . implode(',', $placeholders) . ")
                  AND user_id = ?")
                ->execute($params);
        }
    }

    // Delete ALL notifications for this user
    if (isset($_POST['delete_all'])) {
        $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute(array($user_id));
    }

    // Mark selected as read
    if (isset($_POST['mark_read']) && !empty($_POST['notif_ids'])) {

        $ids = array_map('intval', $_POST['notif_ids']);
        $ids = array_filter($ids, function ($id) { return $id > 0; });
        $ids = array_values($ids);

        if (!empty($ids)) {

            $placeholders = [];
            $params = [];

            foreach ($ids as $i => $id) {
                $placeholders[] = '?';
                $params[] = $id;
            }

            $params[] = $user_id;
            $uid_idx = count($ids) + 1;

$pdo->prepare("UPDATE notifications SET is_read = 1
                 WHERE notification_id IN (" . implode(',', $placeholders) . ") AND user_id = ?")
                ->execute($params);
        }
    }

    // Mark ALL as read
    if (isset($_POST['mark_all_read'])) {
        $pdo->prepare("UPDATE notifications SET is_read = 1
             WHERE user_id = ? AND is_read = 0")->execute(array($user_id));
    }
}


/* =========================================================
   UNREAD COUNT (shared header badge)
   ========================================================= */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unread_count = (int)$stmt->fetchColumn();


/* =========================================================
   GET NOTIFICATIONS
   ========================================================= */

$notifs = $pdo->prepare("SELECT *
      FROM notifications
      WHERE user_id = ?
      ORDER BY created_at DESC"); $notifs->execute(array($user_id));


/* =========================================================
   NOTIFICATION STYLE
   ========================================================= */

function notif_style($type)
{

    switch ($type) {

        case 'event_reminder':
            return ['🔔', 'border-yellow-400', 'bg-yellow-50'];

        case 'registration':
            return ['✅', 'border-green-400', 'bg-green-50'];

        case 'event_approval':
            return ['📋', 'border-rmc-400', 'bg-rmc-50'];

        case 'event_rejection':
            return ['❌', 'border-red-400', 'bg-red-50'];

        case 'new_event':
            return ['🎉', 'border-purple-400', 'bg-purple-50'];

        case 'event_cancelled':
            return ['🚫', 'border-red-500', 'bg-red-50'];

        case 'attendance':
            return ['📷', 'border-green-500', 'bg-green-50'];

        default:
            return ['📬', 'border-gray-300', 'bg-gray-50'];

    }

}


/* =========================================================
   FIND EVENT RELATED TO NOTIFICATION
   ========================================================= */

function get_notification_link($pdo, $notification, $role)
{

    $type = $notification['type'];
    $message = $notification['message'];


    /*
     * Extract event title from messages such as:
     *
     * Your event "Sample Event" has been approved.
     *
     * A new event "Sample Event" is now available.
     *
     * Your event "Sample Event" was rejected.
     *
     * Event "Sample Event" has been cancelled.
     */

    preg_match('/"([^"]+)"/', $message, $matches);

    $event_title = $matches[1] ?? '';


    if (!empty($event_title)) {

        $event_result = $pdo->prepare("SELECT event_id
             FROM events
             WHERE title = ?
             ORDER BY event_id DESC
             LIMIT 1"); $event_result->execute(array($event_title));

        if ($event_result && $event_result->rowCount() > 0) {

            $event = $event_result->fetch(PDO::FETCH_ASSOC);

            $event_id = $event['event_id'];


            /*
             * Organizer notifications
             */

            if (
                $role === 'organizer' &&
                (
                    $type === 'event_approval' ||
                    $type === 'event_rejection' ||
                    $type === 'event_cancelled'
                )
            ) {

                return "edit_event.php?id=" . urlencode($event_id);

            }


            /*
             * Student event notifications
             */

            if (
                $role === 'student' &&
                (
                    $type === 'new_event' ||
                    $type === 'event_reminder' ||
                    $type === 'event_cancelled' ||
                    $type === 'registration'
                )
            ) {

                return "events.php?event_id=" . urlencode($event_id);

            }


            /*
             * Admin event notifications
             */

            if ($role === 'admin') {

                return "admin_events.php?event_id=" . urlencode($event_id);

            }

        }

    }


    /*
     * Fallback pages
     */

    if ($type === 'event_approval' || $type === 'event_rejection') {

        if ($role === 'organizer') {
            return 'dashboard.php';
        }

        if ($role === 'admin') {
            return 'admin_events.php';
        }

    }


    if ($type === 'new_event' || $type === 'event_reminder') {

        return 'events.php';

    }


    if ($type === 'registration') {

        if ($role === 'organizer') {
            return 'dashboard.php';
        }

        return 'events.php';

    }


    if ($type === 'event_cancelled') {

        if ($role === 'student') {
            return 'events.php';
        }

        if ($role === 'organizer') {
            return 'dashboard.php';
        }

        if ($role === 'admin') {
            return 'admin_events.php';
        }

    }


    return '#';

}


/* =========================================================
   RECENT NOTIFICATIONS (shared header dropdown)
   ========================================================= */

$recent_notifications = $pdo->prepare("SELECT notification_id, type, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$recent_notifications->execute([$user_id]);


/* =========================================================
   PAGE VARIABLES (shared partials)
   ========================================================= */

$role_label = ucfirst($role);

if ($role === 'admin') {
    $role_label = 'Administrator';
} elseif ($role === 'organizer') {
    $role_label = 'Event Organizer';
}

$page_title  = t('title_notifications');
$active_page = 'notifications';

?>


<?php include 'partials/head.php'; ?>

<?php include 'partials/sidebar.php'; ?>

<?php include 'partials/header.php'; ?>


<!-- =========================================================
     NOTIFICATIONS HERO
     ========================================================= -->

<div
    class="bg-gradient-to-br from-rmc-50 via-rmc-100 to-rmc-200 border border-rmc-200/60 rounded-[26px] px-6 py-8 sm:p-10 flex flex-col sm:flex-row sm:items-center gap-6 mb-8 animate-up"
>

    <div
        class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-rmc-800 text-white flex items-center justify-center shadow-lg shrink-0"
    >

        <i class="fa-solid fa-bell text-2xl"></i>

    </div>

    <div class="min-w-0">

        <h2 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
            <?= t('notifications'); ?>
        </h2>

        <p class="text-slate-600 mt-1 text-sm sm:text-base">
            <?= t('notifications_hero_desc'); ?>
        </p>

    </div>

</div>


<!-- =========================================================
     NOTIFICATIONS LIST
     ========================================================= -->

<?php if ($notifs->rowCount() > 0): ?>

<form method="POST" id="notifForm">

<?= csrf_field(); ?>

<div class="flex flex-wrap items-center gap-3 mb-5 animate-up delay-1">
    <button type="submit" name="mark_read" value="1" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-blue-50 text-blue-700 hover:bg-blue-100 text-sm font-semibold transition">
        <i class="fa-solid fa-check-double"></i> Mark Selected Read
    </button>
    <button type="submit" name="mark_all_read" value="1" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-green-50 text-green-700 hover:bg-green-100 text-sm font-semibold transition">
        <i class="fa-solid fa-check"></i> Mark All Read
    </button>
    <button type="submit" name="delete_selected" value="1" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-red-50 text-red-700 hover:bg-red-100 text-sm font-semibold transition">
        <i class="fa-solid fa-trash"></i> Delete Selected
    </button>
    <button type="submit" name="delete_all" value="1" onclick="return confirm('Delete ALL notifications? This cannot be undone.');" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-red-100 text-red-800 hover:bg-red-200 text-sm font-semibold transition">
        <i class="fa-solid fa-trash-can"></i> Delete All
    </button>
    <label class="inline-flex items-center gap-2 text-sm text-slate-600 ml-auto cursor-pointer">
        <input type="checkbox" id="selectAllNotifs" onchange="toggleAllNotifs(this)" class="rounded">
        Select All
    </label>
</div>

</form>

<?php endif; ?>

<?php if ($notifs->rowCount() === 0): ?>

    <div
        class="bg-white rounded-[26px] border border-slate-200 shadow-sm px-6 py-20 text-center animate-up delay-1"
    >

        <div
            class="w-16 h-16 mx-auto rounded-2xl bg-rmc-50 text-rmc-300 flex items-center justify-center mb-5"
        >

            <i class="fa-regular fa-bell-slash text-3xl"></i>

        </div>

        <h2 class="text-xl font-bold text-slate-800">
            <?= t('no_notifications_title'); ?>
        </h2>

        <p class="text-slate-500 mt-2 max-w-sm mx-auto">
            <?= t('no_notifications_desc'); ?>
        </p>

    </div>

<?php else: ?>

    <div class="space-y-5">

        <?php while ($row = $notifs->fetch(PDO::FETCH_ASSOC)): ?>

            <?php

            list($icon, $border, $bg) = notif_style($row['type']);

            $link = get_notification_link(
                $pdo,
                $row,
                $role
            );

            ?>

            <div data-notif="<?= (int) $row['notification_id']; ?>" class="flex items-start gap-3 <?= $bg; ?> <?= $border; ?> border-l-4 rounded-[26px] shadow-sm p-5 sm:p-6 hover:shadow-lg transition animate-up <?= $row['is_read'] ? 'opacity-70' : ''; ?>">

                <input type="checkbox" name="notif_ids[]" value="<?= $row['notification_id']; ?>" form="notifForm" class="notif-checkbox mt-2 rounded">

                <a href="<?= htmlspecialchars($link); ?>" class="flex-1 min-w-0 flex gap-4 sm:gap-5" onclick="markNotifRead(event, this, <?= (int) $row['notification_id']; ?>);">

                    <div
                        class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl bg-white flex items-center justify-center text-2xl shadow-sm shrink-0"
                    >

                        <?= $icon; ?>

                    </div>

                    <div class="flex-1 min-w-0">

                        <div class="flex justify-between items-start gap-3">

                            <h3
                                class="text-base sm:text-lg font-bold capitalize text-slate-800"
                            >

                                <?= htmlspecialchars(
                                    str_replace("_", " ", $row['type'])
                                ); ?>

                            </h3>

                            <span
                                class="text-xs text-slate-500 whitespace-nowrap shrink-0"
                            >

                                <?= htmlspecialchars($row['created_at']); ?>

                            </span>

                        </div>

                        <p class="text-slate-600 mt-2 leading-relaxed text-sm sm:text-base">
                            <?= htmlspecialchars($row['message']); ?>
                        </p>

                        <?php if ($link !== '#'): ?>

                            <p
                                class="text-rmc-800 text-sm font-semibold mt-3 inline-flex items-center gap-2"
                            >

                                <?= t('view_related_item'); ?>

                                <i class="fa-solid fa-arrow-right text-xs"></i>

                            </p>

                        <?php endif; ?>

                    </div>

                </a>

                <div class="flex flex-col gap-1 shrink-0 ml-2">

                    <?php if (!$row['is_read']): ?>

                        <form method="POST" class="inline">

                            <?= csrf_field(); ?>

                            <input type="hidden" name="mark_single_read" value="<?= (int) $row['notification_id']; ?>">

                            <button type="submit" class="text-blue-500 hover:text-blue-700 hover:bg-blue-50 rounded-lg p-1.5 transition" title="Mark as read">

                                <i class="fa-solid fa-check text-xs"></i>

                            </button>

                        </form>

                    <?php endif; ?>

                    <form method="POST" class="inline">

                        <?= csrf_field(); ?>

                        <input type="hidden" name="delete_single" value="<?= (int) $row['notification_id']; ?>">

                        <button type="submit" class="text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg p-1.5 transition" title="Delete">

                            <i class="fa-solid fa-trash text-xs"></i>

                        </button>

                    </form>

                </div>

            </div>

        <?php endwhile; ?>

    </div>

<?php endif; ?>

<script>
function toggleAllNotifs(master) {
    var boxes = document.querySelectorAll('.notif-checkbox');
    for (var i = 0; i < boxes.length; i++) {
        boxes[i].checked = master.checked;
    }
}

function markNotifRead(event, link, notifId) {
    if (event) event.preventDefault();
    var destination = link ? link.href : '';
    var form = new FormData();
    form.append('mark_single_read', notifId);

    var csrfInput = document.querySelector('#notifForm input[name="csrf_token"]');
    if (csrfInput) {
        form.append('csrf_token', csrfInput.value);
    }

    fetch('notifications.php', {
        method: 'POST',
        body: form,
        credentials: 'same-origin'
    }).then(function(r) { return r.text(); }).then(function() {
        var card = document.querySelector('[data-notif="' + notifId + '"]');
        if (card) {
            card.classList.remove('opacity-70');
        }
        var dot = document.querySelector('[data-notif-dot="' + notifId + '"]');
        if (dot) dot.remove();
        if (destination && destination !== window.location.href && destination !== '#') {
            window.location.href = destination;
        }
    }).catch(function() {
        if (destination && destination !== window.location.href && destination !== '#') {
            window.location.href = destination;
        }
    });
}
</script>


<?php include 'partials/footer.php'; ?>