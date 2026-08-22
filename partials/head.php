<?php
/*
|--------------------------------------------------------------------------
| RMC Events — Shared <head> + opening <body>
|--------------------------------------------------------------------------
| Expected variables (all optional):
|   $page_title   (string)  Page title shown in the browser tab.
|
| Loads Tailwind CSS (CDN) with the RMC maroon palette, Font Awesome, and
| the shared design-system styles. This partial opens the <body> tag;
| every page must include partials/footer.php at the end to close it.
|--------------------------------------------------------------------------
*/

$page_title = $page_title ?? 'RMC Events';
?>
<!DOCTYPE html>

<html lang="en">

<head>

<?php include_once __DIR__ . '/dark_mode.php'; ?>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title><?= htmlspecialchars($page_title); ?></title>

<script src="https://cdn.tailwindcss.com"></script>

<script>
tailwind.config = {
    theme: {
        extend: {
            colors: {
                rmc: {
                    50:  '#fbf4f4',
                    100: '#f6e6e6',
                    200: '#edd0d0',
                    300: '#e0afaf',
                    400: '#cf8585',
                    500: '#bb6060',
                    600: '#a24444',
                    700: '#873131',
                    800: '#7a0c0c',
                    900: '#640909',
                    950: '#3c0404'
                }
            }
        }
    }
};
</script>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
>

<style>

/* =========================================================
   RMC EVENTS DESIGN SYSTEM — BASE
   ========================================================= */

* {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {
    font-family:
        Inter,
        ui-sans-serif,
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}

/* =========================================================
   ANIMATIONS
   ========================================================= */

@keyframes pageFade {

    from {
        opacity: 0;
        transform: translateY(8px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }

}

@keyframes fadeUp {

    from {
        opacity: 0;
        transform: translateY(18px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }

}

@keyframes pulseSoft {

    0%, 100% {
        transform: scale(1);
    }

    50% {
        transform: scale(1.08);
    }

}

.page-animation {
    animation: pageFade .45s ease-out;
}

.animate-up {
    animation: fadeUp .55s ease-out both;
}

.delay-1 {
    animation-delay: .08s;
}

.delay-2 {
    animation-delay: .16s;
}

.delay-3 {
    animation-delay: .24s;
}

.delay-4 {
    animation-delay: .32s;
}

@media (prefers-reduced-motion: reduce) {
    .page-animation,
    .animate-up {
        animation: none;
        opacity: 1;
    }
}

.modal-enter {
    transition: opacity .2s ease-out, transform .2s ease-out;
}

.modal-enter-start {
    opacity: 0;
    transform: scale(.95);
}

.modal-enter-end {
    opacity: 1;
    transform: scale(1);
}

/* =========================================================
   SIDEBAR
   ========================================================= */

.sidebar-link {
    position: relative;

    transition:
        background-color .2s ease,
        color .2s ease,
        transform .2s ease;
}

.sidebar-link:hover {
    transform: translateX(3px);
}

.sidebar-link.active {
    background: rgba(255,255,255,.10);
    color: white;

    box-shadow:
        inset 3px 0 0 #e0afaf;
}

.sidebar-link.active i {
    color: #e0afaf;
}

.sidebar-section-label {
    letter-spacing: .13em;
}

/* =========================================================
   TOP HEADER
   ========================================================= */

.top-header {
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}

/* =========================================================
   CARDS
   ========================================================= */

.stat-card,
.content-card,
.event-card,
.quick-card {

    transition:
        transform .22s ease,
        box-shadow .22s ease,
        border-color .22s ease;
}

.stat-card:hover {
    transform: translateY(-4px);

    box-shadow:
        0 16px 35px rgba(15,23,42,.08);
}

.event-card:hover {

    transform: translateY(-2px);

    border-color: #e0afaf;

    box-shadow:
        0 14px 30px rgba(15,23,42,.07);
}

.quick-card:hover {

    transform: translateY(-3px);

    box-shadow:
        0 14px 30px rgba(15,23,42,.07);
}

/* =========================================================
   ICON BOX
   ========================================================= */

.icon-box {
    display: flex;
    align-items: center;
    justify-content: center;
}

/* =========================================================
   PROGRESS
   ========================================================= */

.progress-bar {
    transition: width 1s ease-in-out;
}

/* =========================================================
   NOTIFICATION
   ========================================================= */

.notification-badge {
    animation: pulseSoft 2s infinite;
}

.notification-panel {
    animation: notificationDrop .18s ease-out;
}

@keyframes notificationDrop {

    from {
        opacity: 0;
        transform: translateY(-6px) scale(.98);
    }

    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }

}

/* =========================================================
   MOBILE SIDEBAR
   ========================================================= */

.mobile-sidebar {
    transform: translateX(-100%);
    transition: transform .3s ease;
}

.mobile-sidebar.open {
    transform: translateX(0);
}

.mobile-overlay {
    opacity: 0;
    pointer-events: none;
    transition: opacity .3s ease;
}

.mobile-overlay.open {
    opacity: 1;
    pointer-events: auto;
}

/* =========================================================
   SCROLLBAR
   ========================================================= */

::-webkit-scrollbar {
    width: 8px;
}

::-webkit-scrollbar-track {
    background: #f1f5f9;
}

::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 999px;
}

::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* =========================================================
   RESPONSIVE
   ========================================================= */

/* =========================================================
   LOADING OVERLAY
   ========================================================= */

#rmcLoadingOverlay {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(15, 23, 42, .55);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    transition: opacity .2s ease;
}

#rmcLoadingOverlay.hidden {
    opacity: 0;
    pointer-events: none;
}

#rmcLoadingBox {
    background: white;
    border-radius: 24px;
    padding: 2rem 2.5rem;
    text-align: center;
    box-shadow: 0 25px 60px rgba(0,0,0,.25);
    max-width: 340px;
    width: 90%;
    transform: scale(.92);
    transition: transform .25s ease;
}

#rmcLoadingOverlay:not(.hidden) #rmcLoadingBox {
    transform: scale(1);
}

@media (prefers-color-scheme: dark) {
    #rmcLoadingBox { background: #1e293b; color: #e2e8f0; }
    #rmcLoadingOverlay { background: rgba(0,0,0,.7); }
    #rmcLoadingText { color: #e2e8f0 !important; }
    #rmcLoadingSubtext { color: #94a3b8 !important; }
    #rmcLoadingSpinner { border-color: #334155; border-top-color: #7a0c0c; }
}

.dark #rmcLoadingBox { background: #1e293b; color: #e2e8f0; }
.dark #rmcLoadingOverlay { background: rgba(0,0,0,.7); }
.dark #rmcLoadingText { color: #e2e8f0 !important; }
.dark #rmcLoadingSubtext { color: #94a3b8 !important; }

@keyframes rmcSpin {
    to { transform: rotate(360deg); }
}

#rmcLoadingSpinner {
    width: 44px;
    height: 44px;
    border: 4px solid #f1f5f9;
    border-top-color: #7a0c0c;
    border-radius: 50%;
    animation: rmcSpin .7s linear infinite;
    margin: 0 auto 1rem;
}

#rmcLoadingText {
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: .25rem;
}

#rmcLoadingSubtext {
    font-size: .8rem;
    color: #94a3b8;
}

@media (prefers-reduced-motion: reduce) {
    #rmcLoadingSpinner { animation-duration: 1.4s; }
}

@media (max-width: 1023px) {

    .desktop-sidebar {
        display: none;
    }

}

</style>

</head>

<body class="bg-slate-50 text-slate-800 page-animation">
