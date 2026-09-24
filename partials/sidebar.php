<?php
/*
|--------------------------------------------------------------------------
| RMC Events — Mobile + Desktop Sidebar
|--------------------------------------------------------------------------
| Expected variables (all optional):
|   $role          (string)  student | organizer | admin
|   $active_page   (string)  Key of the currently active menu item
|   $full_name     (string)  Logged-in user's full name
|   $first_name    (string)  User's first name (for the avatar initial)
|   $role_label    (string)  Human-readable role label
|   $unread_count  (int)     Unread notifications count
|
| Renders the mobile overlay, the slide-in mobile sidebar, and the fixed
| desktop sidebar (static width — no hover expand). Both sidebars share
| one navigation data source.
|--------------------------------------------------------------------------
*/

$role         = $role         ?? '';
$active_page  = $active_page  ?? '';
$full_name    = $full_name    ?? '';
$first_name   = $first_name   ?? (explode(' ', trim($full_name))[0] ?? '');
$role_label   = $role_label   ?? ucfirst($role);
$unread_count = $unread_count ?? ($stats['notifications'] ?? 0);

$nav_items = array();

$nav_items['dashboard'] = array(
    'href'  => 'dashboard.php',
    'icon'  => 'fa-solid fa-table-columns',
    'label' => t('dashboard')
);

if ($role === 'student') {

    $nav_items['events'] = array(
        'href'  => 'events.php',
        'icon'  => 'fa-solid fa-calendar-days',
        'label' => t('browse_events')
    );

    $nav_items['calendar'] = array(
        'href'  => 'calendar.php',
        'icon'  => 'fa-solid fa-calendar',
        'label' => t('calendar')
    );

    $nav_items['my_qr'] = array(
        'href'  => 'my_qr.php',
        'icon'  => 'fa-solid fa-qrcode',
        'label' => t('my_qr_codes')
    );

    $nav_items['my_activities'] = array(
        'href'  => 'my_activities.php',
        'icon'  => 'fa-solid fa-user-clock',
        'label' => t('my_activities')
    );

} elseif ($role === 'organizer') {

    $nav_items['create_event'] = array(
        'href'  => 'create_event.php',
        'icon'  => 'fa-solid fa-plus',
        'label' => t('create_event')
    );

    $nav_items['scan_attendance'] = array(
        'href'  => 'scan_attendance.php',
        'icon'  => 'fa-solid fa-qrcode',
        'label' => t('scan_attendance')
    );

    $nav_items['reports'] = array(
        'href'  => 'reports.php',
        'icon'  => 'fa-solid fa-chart-column',
        'label' => t('reports')
    );

} elseif ($role === 'admin') {

    $nav_items['admin_events'] = array(
        'href'  => 'admin_events.php',
        'icon'  => 'fa-solid fa-calendar-check',
        'label' => t('manage_events')
    );

    $nav_items['admin_users'] = array(
        'href'  => 'admin_users.php',
        'icon'  => 'fa-solid fa-users',
        'label' => t('manage_users')
    );

    $nav_items['admin_manage_admins'] = array(
        'href'  => 'admin_manage_admins.php',
        'icon'  => 'fa-solid fa-user-shield',
        'label' => 'Manage Admins'
    );

    $nav_items['admin_manage_organizers'] = array(
        'href'  => 'admin_manage_organizers.php',
        'icon'  => 'fa-solid fa-clipboard-user',
        'label' => 'Manage Organizers'
    );

    $nav_items['reports'] = array(
        'href'  => 'reports.php',
        'icon'  => 'fa-solid fa-chart-pie',
        'label' => t('reports')
    );

    $nav_items['admin_notifications'] = array(
        'href'  => 'admin_notifications.php',
        'icon'  => 'fa-solid fa-bell',
        'label' => t('all_notifications')
    );

    $nav_items['admin_settings'] = array(
        'href'  => 'admin_settings.php',
        'icon'  => 'fa-solid fa-shield-halved',
        'label' => 'System Settings'
    );

    $nav_items['email_logs'] = array(
        'href'  => 'admin_email_logs.php',
        'icon'  => 'fa-solid fa-envelope',
        'label' => t('email_logs')
    );

}

$nav_account_items = array(
    'notifications' => array(
        'href'  => 'notifications.php',
        'icon'  => 'fa-solid fa-bell',
        'label' => t('notifications'),
        'badge' => $unread_count
    ),
    'settings' => array(
        'href'  => 'settings.php',
        'icon'  => 'fa-solid fa-gear',
        'label' => t('settings')
    )
);

/* =========================================================
   MOBILE NAV RENDERER (unchanged behavior)
   ========================================================= */

if (!function_exists('rmc_sidebar_nav')) {

    function rmc_sidebar_nav($items, $active_page)
    {
        $html = '';

        foreach ($items as $key => $item) {

            $active = ($active_page === $key) ? ' active' : '';

            $badge = (int) ($item['badge'] ?? 0);

            if ($badge > 0) {

                $classes =
                    'sidebar-link flex items-center justify-between ' .
                    'px-4 py-3 rounded-xl text-sm text-slate-300 ' .
                    'hover:bg-white/10 hover:text-white' . $active;

                $inner =
                    '<span class="flex items-center gap-3">' .
                        '<i class="' . htmlspecialchars($item['icon']) . ' w-5 text-center"></i>' .
                        htmlspecialchars($item['label']) .
                    '</span>' .
                    '<span class="notification-badge bg-rmc-600 text-white text-[10px] ' .
                    'font-bold rounded-full min-w-5 h-5 px-1.5 flex items-center justify-center">' .
                        $badge .
                    '</span>';

            } else {

                $classes =
                    'sidebar-link flex items-center gap-3 ' .
                    'px-4 py-3 rounded-xl text-sm text-slate-300 ' .
                    'hover:bg-white/10 hover:text-white' . $active;

                $inner =
                    '<i class="' . htmlspecialchars($item['icon']) . ' w-5 text-center"></i>' .
                    htmlspecialchars($item['label']);
            }

            $html .=
                '<a href="' . htmlspecialchars($item['href']) . '" ' .
                'class="' . $classes . '">' . $inner . '</a>';
        }

        return $html;
    }
}

/* =========================================================
   DESKTOP SIDEBAR RENDERER (static — icon + label always shown)
   ========================================================= */

if (!function_exists('rmc_rail_nav')) {

    function rmc_rail_nav($items, $active_page)
    {
        $html = '';

        foreach ($items as $key => $item) {

            $active = ($active_page === $key) ? ' active' : '';
            $badge  = (int) ($item['badge'] ?? 0);
            $label  = htmlspecialchars($item['label']);

            $badge_html = '';

            if ($badge > 0) {
                $badge_html =
                    '<span class="nav-rail-badge">' . $badge . '</span>';
            }

            $html .=
                '<a href="' . htmlspecialchars($item['href']) . '" ' .
                'class="nav-rail-link' . $active . '">' .
                    '<span class="nav-rail-icon">' .
                        '<i class="' . htmlspecialchars($item['icon']) . '"></i>' .
                        $badge_html .
                    '</span>' .
                    '<span class="nav-rail-label">' . $label . '</span>' .
                '</a>';
        }

        return $html;
    }
}
?>

<!-- =========================================================
     MOBILE OVERLAY
     ========================================================= -->

<div
    id="mobileOverlay"
    class="mobile-overlay fixed inset-0 bg-slate-950/50 z-40 lg:hidden"
    onclick="closeMobileMenu()"
></div>


<!-- =========================================================
     MOBILE SIDEBAR
     ========================================================= -->

<aside
    id="mobileSidebar"
    class="mobile-sidebar fixed left-0 top-0 bottom-0 w-72 bg-rmc-950 text-white z-50 lg:hidden flex flex-col shadow-2xl"
>

    <div class="p-6 border-b border-white/10">

        <div class="flex items-center gap-3">

            <div
                class="w-11 h-11 rounded-xl bg-white flex items-center justify-center shadow-lg shrink-0"
            >

                <img
                    src="img/logo.webp"
                    class="w-9 h-9 object-contain"
                    alt="RMC Logo"
                >

            </div>

            <div class="min-w-0">

                <h2 class="font-bold text-lg tracking-tight">
                    RMC Events
                </h2>

                <p class="text-xs text-rmc-300">
                    <?= t('discover_participate_connect'); ?>
                </p>

            </div>

            <button
                onclick="closeMobileMenu()"
                class="ml-auto text-rmc-300 hover:text-white"
                aria-label="<?= t('close_menu'); ?>"
            >

                <i class="fa-solid fa-xmark text-lg"></i>

            </button>

        </div>

    </div>


    <div class="flex-1 px-5 py-7 overflow-y-auto">

        <p
            class="sidebar-section-label uppercase text-[10px] font-bold text-rmc-300 mb-4 px-3"
        >
            <?= t('main_menu'); ?>
        </p>

        <nav class="space-y-1">

            <?= rmc_sidebar_nav($nav_items, $active_page); ?>

        </nav>


        <div class="pt-5 pb-3">

            <p class="sidebar-section-label uppercase text-[10px] font-bold text-rmc-300 px-3">
                <?= t('account'); ?>
            </p>

        </div>

        <nav class="space-y-1">

            <?= rmc_sidebar_nav($nav_account_items, $active_page); ?>

        </nav>

    </div>


    <div class="p-5 border-t border-white/10">

        <div class="flex items-center gap-3">

            <div
                class="w-10 h-10 rounded-full bg-rmc-700 flex items-center justify-center font-bold shrink-0"
            >

                <?= strtoupper(substr($first_name, 0, 1)); ?>

            </div>

            <div class="min-w-0">

                <p class="text-sm font-semibold truncate">
                    <?= htmlspecialchars($full_name); ?>
                </p>

                <p class="text-xs text-rmc-300">
                    <?= htmlspecialchars($role_label); ?>
                </p>

            </div>

            <a
                    href="logout.php"
                class="ml-auto text-rmc-300 hover:text-red-400 transition"
                title="<?= t('logout'); ?>"
                aria-label="<?= t('logout'); ?>"
            >

                <i class="fa-solid fa-right-from-bracket"></i>

            </a>

        </div>

    </div>

</aside>


<!-- =========================================================
     DESKTOP SIDEBAR (static width — always expanded, no hover)
     ========================================================= -->

<aside class="nav-rail nav-rail-static" id="desktopSidebar">

    <!-- BRAND -->

    <div class="nav-rail-brand">

        <div class="nav-rail-brand-icon">

            <img
                src="img/logo.webp"
                alt="RMC Logo"
            >

        </div>

        <div class="nav-rail-brand-text">

            <h2 class="nav-rail-brand-name">
                RMC Events
            </h2>

            <p class="nav-rail-brand-tagline">
                <?= t('discover_participate_connect'); ?>
            </p>

        </div>

    </div>


    <!-- NAVIGATION -->

    <nav class="nav-rail-nav">

        <div class="nav-rail-section">

            <p class="nav-rail-section-label">
                <?= t('main_menu'); ?>
            </p>

            <?= rmc_rail_nav($nav_items, $active_page); ?>

        </div>

        <div class="nav-rail-section">

            <p class="nav-rail-section-label">
                <?= t('account'); ?>
            </p>

            <?= rmc_rail_nav($nav_account_items, $active_page); ?>

        </div>

    </nav>


    <!-- USER -->

    <div class="nav-rail-footer">

        <div class="nav-rail-user">

            <div class="nav-rail-user-avatar">
                <?= strtoupper(substr($first_name, 0, 1)); ?>
            </div>

            <div class="nav-rail-user-info">

                <p class="nav-rail-user-name">
                    <?= htmlspecialchars($full_name); ?>
                </p>

                <p class="nav-rail-user-role">
                    <?= htmlspecialchars($role_label); ?>
                </p>

            </div>

            <a
                    href="logout.php"
                class="nav-rail-logout"
                title="<?= t('logout'); ?>"
                aria-label="<?= t('logout'); ?>"
            >

                <i class="fa-solid fa-right-from-bracket"></i>

            </a>

        </div>

    </div>

</aside>