<?php
/*
|--------------------------------------------------------------------------
| RMC Events — Top Header / Topbar
|--------------------------------------------------------------------------
| Expected variables (all optional):
|   $role                 (string)  student | organizer | admin
|   $full_name            (string)  Logged-in user's full name
|   $first_name           (string)  User's first name
|   $greeting             (string)  Greeting message (auto if empty)
|   $unread_count         (int)     Unread notifications count
|   $recent_notifications (result)  pg result of recent notifications
|   $conn                 (resource) pg connection (used by the dropdown)
|
| Opens <main>, renders the sticky top header (hamburger, greeting,
| notification dropdown, profile/settings button), and opens the page
| content container. Partial footer.php closes what this partial opens.
|--------------------------------------------------------------------------
*/

$role         = $role         ?? '';
$full_name    = $full_name    ?? '';
$first_name   = $first_name   ?? (explode(' ', trim($full_name))[0] ?? '');
$unread_count = $unread_count ?? ($stats['notifications'] ?? 0);

if (empty($greeting)) {

    $hour = (int) date('H');

    if ($hour < 12) {
        $greeting = t('good_morning');
    } elseif ($hour < 18) {
        $greeting = t('good_afternoon');
    } else {
        $greeting = t('good_evening');
    }
}
?>

<main
    class="flex-1 min-w-0 lg:ml-72"
>


<!-- =========================================================
     TOP HEADER
     ========================================================= -->

<header
    class="top-header sticky top-0 z-20 bg-white/95 border-b border-slate-200"
>

    <div
        class="px-5 sm:px-6 lg:px-10 py-4 lg:py-5"
    >

        <div class="flex items-center justify-between gap-4">


            <!-- LEFT -->

            <div class="flex items-center gap-4">

                <button
                    onclick="openMobileMenu()"
                    class="lg:hidden w-10 h-10 rounded-xl border border-slate-200 bg-white text-slate-600 flex items-center justify-center"
                    aria-label="<?= t('open_menu'); ?>"
                >

                    <i class="fa-solid fa-bars"></i>

                </button>


                <div>

                    <p class="text-xs sm:text-sm text-slate-500 mb-1">

                        <?php if ($role === 'student'): ?>

                            <?= t('student_dashboard'); ?>

                        <?php elseif ($role === 'organizer'): ?>

                            <?= t('organizer_dashboard'); ?>

                        <?php else: ?>

                            <?= t('administration'); ?>

                        <?php endif; ?>

                    </p>


                    <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold text-slate-900">

                        <?= htmlspecialchars($greeting); ?>,

                        <span class="text-rmc-800">
                            <?= htmlspecialchars($first_name); ?>
                        </span>

                        <i class="fa-solid fa-hand-wave text-rmc-600 text-xl align-middle"></i>

                    </h1>

                    <p class="hidden md:block text-sm text-slate-500 mt-1">

                        <?php if ($role === 'student'): ?>

                            <?= t('today_line'); ?>

                        <?php elseif ($role === 'organizer'): ?>

                            <?= t('organizer_line'); ?>

                        <?php else: ?>

                            <?= t('admin_line'); ?>

                        <?php endif; ?>

                    </p>

                </div>

            </div>


            <!-- RIGHT -->

            <div class="flex items-center gap-2 sm:gap-3">


                <!-- NOTIFICATION DROPDOWN -->

                <div class="relative">

                    <button
                        type="button"
                        onclick="toggleNotificationPanel()"
                        class="relative w-10 h-10 sm:w-11 sm:h-11 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 flex items-center justify-center text-slate-600 transition"
                        title="<?= t('notifications'); ?>"
                        aria-label="<?= t('notifications'); ?>"
                    >

                        <i class="fa-regular fa-bell text-lg"></i>

                        <?php if ($unread_count > 0): ?>

                            <span
                                class="notification-badge absolute -top-1.5 -right-1.5 bg-rmc-600 text-white text-[10px] font-bold rounded-full min-w-5 h-5 px-1 flex items-center justify-center border-2 border-white"
                            >

                                <?= $unread_count; ?>

                            </span>

                        <?php endif; ?>

                    </button>


                    <div
                        id="notificationPanel"
                        class="notification-panel hidden absolute right-0 top-14 w-[min(360px,calc(100vw-2rem))] bg-white border border-slate-200 rounded-2xl shadow-2xl overflow-hidden z-50"
                    >

                        <div class="px-4 py-4 border-b border-slate-100 flex items-center justify-between">

                            <div>

                                <h3 class="font-bold text-slate-900">
                                    <?= t('notifications'); ?>
                                </h3>

                                <p class="text-xs text-slate-400 mt-0.5">

                                    <?php if ($unread_count > 0): ?>

                                        <?= $unread_count; ?> <?= t('unread'); ?>

                                    <?php else: ?>

                                        <?= t('all_caught_up'); ?>

                                    <?php endif; ?>

                                </p>

                            </div>

                            <span class="w-9 h-9 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center">
                                <i class="fa-regular fa-bell"></i>
                            </span>

                        </div>


                        <div class="max-h-80 overflow-y-auto">

                            <?php if (
                                isset($recent_notifications) &&
                                $recent_notifications &&
                                pg_num_rows($recent_notifications) > 0
                            ): ?>

                                <?php while ($notification = pg_fetch_assoc($recent_notifications)): ?>

                                    <?php

                                        $notification_type =
                                            $notification['type'] ?? 'notification';

                                        $notification_icon = 'fa-bell';
                                        $notification_icon_bg =
                                            'bg-rmc-50 text-rmc-800';
                                        $notification_title = t('notification');

                                        switch ($notification_type) {

                                            case 'event_reminder':

                                                $notification_icon = 'fa-clock';
                                                $notification_icon_bg =
                                                    'bg-amber-50 text-amber-700';
                                                $notification_title =
                                                    t('event_reminder');
                                                break;

                                            case 'registration':

                                                $notification_icon = 'fa-circle-check';
                                                $notification_icon_bg =
                                                    'bg-emerald-50 text-emerald-700';
                                                $notification_title =
                                                    t('registration_confirmed');
                                                break;

                                            case 'event_approval':

                                                $notification_icon = 'fa-circle-check';
                                                $notification_icon_bg =
                                                    'bg-emerald-50 text-emerald-700';
                                                $notification_title =
                                                    t('event_approved');
                                                break;

                                            case 'event_rejection':

                                                $notification_icon = 'fa-circle-xmark';
                                                $notification_icon_bg =
                                                    'bg-red-50 text-red-700';
                                                $notification_title =
                                                    t('event_rejected');
                                                break;

                                            case 'event_cancelled':

                                                $notification_icon = 'fa-circle-xmark';
                                                $notification_icon_bg =
                                                    'bg-red-50 text-red-700';
                                                $notification_title =
                                                    t('event_cancelled');
                                                break;

                                            case 'new_event':

                                                $notification_icon = 'fa-calendar-plus';
                                                $notification_icon_bg =
                                                    'bg-rmc-50 text-rmc-800';
                                                $notification_title =
                                                    t('new_event');
                                                break;

                                            case 'attendance':

                                                $notification_icon = 'fa-user-check';
                                                $notification_icon_bg =
                                                    'bg-emerald-50 text-emerald-700';
                                                $notification_title =
                                                    t('attendance_verified');
                                                break;

                                            default:

                                                $notification_icon = 'fa-bell';
                                                $notification_icon_bg =
                                                    'bg-rmc-50 text-rmc-800';
                                                $notification_title =
                                                    t('notification');
                                        }

                                        $is_unread = (
                                            $notification['is_read'] === 'f' ||
                                            $notification['is_read'] === false ||
                                            $notification['is_read'] === '0'
                                        );

                                    ?>

                                    <div
                                        class="px-4 py-3.5 border-b border-slate-100 hover:bg-slate-50 transition <?= $is_unread ? 'bg-rmc-50/40' : ''; ?>"
                                    >

                                        <a href="#" class="flex gap-3 no-underline" onclick="markHeaderNotifRead(event, <?= (int) $notification['notification_id']; ?>);">

                                            <div
                                                class="w-9 h-9 rounded-xl <?= $notification_icon_bg; ?> flex items-center justify-center shrink-0"
                                            >

                                                <i class="fa-solid <?= $notification_icon; ?> text-sm"></i>

                                            </div>

                                            <div class="min-w-0 flex-1">

                                                <p class="text-sm font-semibold text-slate-800">

                                                    <?= htmlspecialchars($notification_title); ?>

                                                </p>

                                                <p class="text-xs text-slate-500 mt-1 leading-relaxed line-clamp-2">
                                                    <?= htmlspecialchars(
                                                        $notification['message'] ??
                                                        t('you_have_new_notification')
                                                    ); ?>
                                                </p>

                                                <p class="text-[10px] text-slate-400 mt-1.5">
                                                    <?= !empty($notification['created_at'])
                                                        ? date(
                                                            'M d, Y · g:i A',
                                                            strtotime($notification['created_at'])
                                                        )
                                                        : '';
                                                    ?>
                                                </p>

                                            </div>

                                            <?php if ($is_unread): ?>

                                                <span
                                                    data-notif-dot="<?= (int) $notification['notification_id']; ?>"
                                                    class="w-2 h-2 rounded-full bg-rmc-600 shrink-0 mt-1.5"
                                                    title="<?= t('unread_short'); ?>"
                                                ></span>

                                            <?php endif; ?>

                                        </div>

                                        </a>

                                    </div>

                                <?php endwhile; ?>

                            <?php else: ?>

                                <div class="px-5 py-10 text-center">

                                    <div class="w-12 h-12 mx-auto rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mb-3">
                                        <i class="fa-regular fa-bell-slash"></i>
                                    </div>

                                    <p class="text-sm font-semibold text-slate-700">
                                        <?= t('all_caught_up'); ?>
                                    </p>

                                    <p class="text-xs text-slate-400 mt-1">
                                        <?= t('no_notifications_yet'); ?>
                                    </p>

                                </div>

                            <?php endif; ?>

                        </div>


                        <a
                            href="notifications.php"
                            class="flex items-center justify-center gap-2 px-4 py-3.5 text-sm font-semibold text-rmc-800 hover:bg-rmc-50 border-t border-slate-100 transition"
                        >

                            <?= t('view_all_notifications'); ?>

                            <i class="fa-solid fa-arrow-right text-xs"></i>

                        </a>

                    </div>

                </div>


                <!-- PROFILE / SETTINGS -->

                <a
                    href="settings.php"
                    class="hidden sm:flex w-10 h-10 sm:w-11 sm:h-11 rounded-xl bg-rmc-50 text-rmc-800 items-center justify-center font-bold border border-rmc-100 hover:bg-rmc-100 transition"
                    title="<?= t('profile_settings'); ?>"
                    aria-label="<?= t('profile_settings'); ?>"
                >

                    <?= strtoupper(substr($first_name, 0, 1)); ?>

                </a>

            </div>

        </div>

    </div>

</header>

<script>
function markHeaderNotifRead(e, nid) {
    e.preventDefault();
    var form = new FormData();
    form.append('nid', nid);

    fetch('ajax_mark_read.php', {
        method: 'POST',
        body: form,
        credentials: 'same-origin'
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var dot = document.querySelector('[data-notif-dot="' + nid + '"]');
        if (dot) dot.remove();
        if (data.ok && data.unread >= 0) {
            var badge = document.querySelector('.notification-badge');
            if (data.unread === 0) {
                if (badge) badge.remove();
            } else if (badge) {
                badge.textContent = data.unread;
            }
        }
    });
}
</script>


<!-- =========================================================
     PAGE CONTENT
     ========================================================= -->

<div class="p-5 sm:p-6 lg:p-10 max-w-[1600px] mx-auto">
