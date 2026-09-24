<?php
/**
 * Landing Page — Campus Event Management & Data Analytics System
 * Regis Marie College — matches the live RMC Events design system
 */

require 'db_connect.php';
require 'lang.php';
require 'csrf.php';

// If user is already logged in, send them straight to their dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$page_title = 'RMC Events — Campus Event Management';
?>
<?php include 'partials/head.php'; ?>

<style>
    /* ---------------------------------------------------------
       Landing-page-only styles. Colors below reference the SAME
       CSS variables the rest of the system uses (variables.css) —
       nothing here redefines the brand palette.
       --------------------------------------------------------- */

    .skip-link {
        position: absolute;
        left: -9999px;
        top: 0;
        z-index: 100;
        background: var(--color-primary-900);
        color: #fff;
        padding: 0.75rem 1.25rem;
        border-radius: 0 0 0.75rem 0;
    }
    .skip-link:focus { left: 0; }

    #mobileSidebarLanding { transition: transform 0.3s ease-in-out; }
    #mobileSidebarLanding.open { transform: translateX(0); }
    #mobileOverlayLanding.open { display: block; }

    #landingNav {
        background-color: rgba(255, 255, 255, 0.7);
        box-shadow: none;
        transition: background-color 300ms ease, box-shadow 300ms ease, backdrop-filter 300ms ease;
    }
    #landingNav.nav-scrolled {
        background-color: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(8px);
        box-shadow: 0 1px 2px rgba(11, 31, 58, 0.06), 0 4px 16px rgba(11, 31, 58, 0.08);
    }

    .nav-link { position: relative; }
    .nav-link::after {
        content: '';
        position: absolute;
        left: 0; right: 100%; bottom: -4px;
        height: 2px;
        background: var(--color-primary-800);
        transition: right 220ms ease;
    }
    .nav-link:hover::after { right: 0; }

    [data-animate] {
        opacity: 0;
        transform: translateY(24px) scale(.985);
        transition: opacity 560ms cubic-bezier(.22,.75,.2,1), transform 560ms cubic-bezier(.22,.75,.2,1);
    }
    [data-animate="scale"] { transform: translateY(18px) scale(.94); }
    [data-animate].in { opacity: 1; transform: none; }

    /* Scroll motion: soft fade + scale for sections, then a restrained
       stagger on their children and a small drop-down entrance for headings. */
    .reveal {
        opacity: 0;
        transform: translateY(30px) scale(.975);
        transform-origin: center top;
        transition: opacity 620ms cubic-bezier(.22,.75,.2,1), transform 620ms cubic-bezier(.22,.75,.2,1);
    }
    .reveal.visible { opacity: 1; transform: none; }

    .rmc-stagger-item {
        opacity: 0;
        transform: translateY(22px) scale(.975);
        transition: opacity 520ms cubic-bezier(.22,.75,.2,1), transform 520ms cubic-bezier(.22,.75,.2,1);
        transition-delay: var(--rmc-stagger-delay, 0ms);
    }
    .reveal.visible .rmc-stagger-item {
        opacity: 1;
        transform: none;
    }

    .rmc-drop-item {
        opacity: 0;
        transform: translateY(-24px) scale(.98);
        transition: opacity 540ms cubic-bezier(.22,.75,.2,1), transform 540ms cubic-bezier(.22,.75,.2,1);
        transition-delay: var(--rmc-drop-delay, 80ms);
    }
    .reveal.visible .rmc-drop-item {
        opacity: 1;
        transform: none;
    }

    .rmc-scroll-pop {
        opacity: 0;
        transform: translateY(18px) scale(.96);
        transition: opacity 620ms cubic-bezier(.22,.75,.2,1), transform 620ms cubic-bezier(.22,.75,.2,1);
    }
    .rmc-scroll-pop.is-visible { opacity: 1; transform: none; }

    /* First-section cinematic scroll transition: the hero stays in view briefly,
       then gently fades, scales down, and lifts away as the next section arrives.
       (Restored to the exact original implementation.) */
    #home.hero-scroll-scene {
        position: sticky;
        top: 0;
        min-height: 100vh;
        z-index: 2;
        transform-origin: center center;
        will-change: transform, opacity, filter;
        transition: opacity 120ms linear, transform 120ms linear, filter 120ms linear;
    }
    #home.hero-scroll-scene + .landing-scroll-cover {
        position: relative;
        z-index: 3;
        background: #fff;
    }

    .hero-scroll-spacer {
        position: absolute;
        inset: 0;
        pointer-events: none;
    }

    /* Hero — same grid/orb treatment used on dashboard.php for brand consistency */
    .hero-card {
        position: relative;
        overflow: hidden;
        background: linear-gradient(135deg, var(--color-primary-50) 0%, #eaf1fb 55%, var(--color-primary-100) 100%);
        border: 1px solid rgba(11, 31, 58, .10);
    }
    .hero-grid {
        background-image:
            linear-gradient(rgba(11,31,58,.05) 1px, transparent 1px),
            linear-gradient(90deg, rgba(11,31,58,.05) 1px, transparent 1px);
        background-size: 32px 32px;
    }
    .hero-orb { position: absolute; border-radius: 9999px; filter: blur(2px); pointer-events: none; }
    .hero-orb-one { width: 330px; height: 330px; right: -100px; top: -150px; background: rgba(11,31,58,.07); }
    .hero-orb-two { width: 250px; height: 250px; left: 45%; bottom: -180px; background: rgba(11,31,58,.05); }

    /* Gallery carousel — centered active image with soft side previews. */
    .rmc-gallery {
        position: relative;
        min-height: 440px;
        overflow: hidden;
        border-radius: 30px;
        border: 1px solid rgba(255,255,255,.9);
        background: #f2f0ed;
        box-shadow: 0 18px 45px rgba(72, 45, 52, .10);
        isolation: isolate;
    }
    .rmc-gallery-backdrop {
        position: absolute;
        inset: -28px;
        background-image: var(--gallery-bg, url('img/image%20for%20landing%20page.jpg'));
        background-size: cover;
        background-position: center;
        filter: blur(18px) saturate(.8);
        transform: scale(1.08);
        opacity: .42;
    }
    .rmc-gallery-backdrop::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(255,255,255,.48), rgba(255,255,255,.78));
    }
    .rmc-gallery-track {
        position: relative;
        z-index: 2;
        min-height: 440px;
        height: 440px;
    }
    .rmc-gallery-slide {
        position: absolute;
        left: 50%;
        top: 50%;
        width: 150px;
        height: 250px;
        overflow: hidden;
        padding: 0;
        border: 1px solid rgba(255,255,255,.92);
        border-radius: 22px;
        background: #fff;
        box-shadow: 0 14px 30px rgba(49,31,37,.14);
        opacity: 0;
        transform: translate(-50%, -50%) scale(.84);
        pointer-events: none;
        filter: saturate(.78);
        transition: left 520ms cubic-bezier(.22,.75,.2,1), transform 520ms cubic-bezier(.22,.75,.2,1), opacity 420ms ease, filter 420ms ease;
    }
    .rmc-gallery-slide img { width: 100%; height: 100%; object-fit: cover; }
    .rmc-gallery-slide.is-active {
        width: min(58vw, 570px);
        height: 330px;
        opacity: 1;
        transform: translate(-50%, -50%) scale(1);
        z-index: 3;
        pointer-events: auto;
        filter: none;
        box-shadow: 0 24px 48px rgba(49,31,37,.20);
    }
    .rmc-gallery-slide.is-prev,
    .rmc-gallery-slide.is-next {
        opacity: .72;
        z-index: 2;
        pointer-events: auto;
    }
    .rmc-gallery-slide.is-prev { left: 16%; transform: translate(-50%, -50%) scale(.88); }
    .rmc-gallery-slide.is-next { left: 84%; transform: translate(-50%, -50%) scale(.88); }
    .rmc-gallery-slide.is-hidden { opacity: 0; }
    .rmc-gallery-slide.is-prev:hover,
    .rmc-gallery-slide.is-next:hover { opacity: .9; transform: translate(-50%, -50%) scale(.92); }
    .rmc-gallery-controls {
        position: absolute;
        z-index: 5;
        left: 50%;
        bottom: 34px;
        transform: translateX(-50%);
        display: flex;
        gap: 10px;
    }
    .rmc-gallery-arrow {
        width: 42px;
        height: 42px;
        display: grid;
        place-items: center;
        border-radius: 12px;
        background: rgba(255,255,255,.9);
        color: #7f1734;
        border: 1px solid rgba(127,23,52,.10);
        box-shadow: 0 8px 18px rgba(49,31,37,.12);
        transition: transform 180ms ease, background 180ms ease;
    }
    .rmc-gallery-arrow:hover { transform: translateY(-2px); background: #fff; }
    .rmc-gallery-dots {
        position: absolute;
        z-index: 5;
        right: 26px;
        bottom: 28px;
        display: flex;
        gap: 7px;
    }
    .rmc-gallery-dot {
        width: 7px;
        height: 7px;
        padding: 0;
        border-radius: 999px;
        background: rgba(127,23,52,.22);
        transition: width 220ms ease, background 220ms ease;
    }
    .rmc-gallery-dot.is-active { width: 22px; background: #7f1734; }
    .rmc-gallery {
        overscroll-behavior: contain;
        touch-action: pan-y;
    }
    .rmc-gallery.is-scroll-ready .rmc-gallery-slide {
        transition-duration: 680ms;
    }
    .rmc-gallery.is-scroll-changing .rmc-gallery-slide.is-active {
        animation: rmcGallerySlideIn 680ms cubic-bezier(.16,1,.3,1);
    }
    @keyframes rmcGallerySlideIn {
        0% { opacity: .45; transform: translate(-50%, -50%) scale(.94); filter: blur(2px) saturate(.85); }
        100% { opacity: 1; transform: translate(-50%, -50%) scale(1); filter: none; }
    }

    @media (max-width: 767px) {
        .rmc-gallery { min-height: 340px; border-radius: 22px; }
        .rmc-gallery-track { min-height: 340px; height: 340px; }
        .rmc-gallery-slide { width: 82px; height: 180px; border-radius: 16px; }
        .rmc-gallery-slide.is-active { width: min(68vw, 330px); height: 240px; }
        .rmc-gallery-slide.is-prev { left: 8%; transform: translate(-50%, -50%) scale(.84); }
        .rmc-gallery-slide.is-next { left: 92%; transform: translate(-50%, -50%) scale(.84); }
        .rmc-gallery-controls { bottom: 20px; }
        .rmc-gallery-arrow { width: 38px; height: 38px; }
        .rmc-gallery-dots { right: 18px; bottom: 24px; }
    }

    /* =========================================================
       PREMIUM LANDING MOTION SYSTEM — landing page only
       ========================================================= */
    body {
        overflow-x: hidden;
    }
    /* Uploaded RMC background image — fixed, slow ambient parallax tied to scroll. */
    body::before {
        content: '';
        position: fixed;
        inset: -7vh -4vw;
        z-index: 0;
        pointer-events: none;
        background:
            linear-gradient(180deg, rgba(255,255,255,.76), rgba(248,250,252,.88)),
            url("img/landing-bg-animation.jpg") center calc(50% + var(--rmc-bg-scroll, 0px)) / cover no-repeat;
        opacity: .42;
        transform: translate3d(0, var(--rmc-bg-y, 0px), 0) scale(var(--rmc-bg-scale, 1.07));
        transform-origin: center center;
        will-change: transform, background-position;
        animation: rmcAmbientBackground 24s ease-in-out infinite alternate;
        transition: opacity 500ms ease;
    }
    /* A second soft layer makes the uploaded image feel alive while scrolling. */
    body::after {
        content: '';
        position: fixed;
        inset: -10vh -6vw;
        z-index: 0;
        pointer-events: none;
        background: url("img/landing-bg-animation.jpg") center / cover no-repeat;
        opacity: .055;
        transform: translate3d(0, var(--rmc-bg-layer-y, 0px), 0) scale(1.11);
        filter: blur(1px) saturate(.9);
        mix-blend-mode: multiply;
        will-change: transform;
    }
    #landingNav, #main, .skip-link { position: relative; z-index: 1; }

    .landing-ambient-orb {
        position: fixed;
        width: 360px;
        height: 360px;
        border-radius: 999px;
        pointer-events: none;
        z-index: 0;
        filter: blur(50px);
        opacity: .18;
        background: radial-gradient(circle, rgba(127,23,52,.55), rgba(127,23,52,0));
        animation: rmcOrbFloat 18s ease-in-out infinite alternate;
    }
    .landing-ambient-orb.orb-a { top: 12%; left: -130px; }
    .landing-ambient-orb.orb-b { right: -130px; bottom: 10%; animation-delay: -7s; transform: scale(.82); }

    .landing-loader {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: grid;
        place-items: center;
        background: rgba(255,255,255,.97);
        backdrop-filter: blur(16px);
        transition: opacity 700ms cubic-bezier(.22,.75,.2,1), visibility 700ms ease;
    }
    .landing-loader.is-hidden { opacity: 0; visibility: hidden; pointer-events: none; }
    .landing-loader-inner { text-align: center; animation: rmcLoaderPop 800ms cubic-bezier(.16,1,.3,1) both; }
    .landing-loader-logo {
        width: 68px; height: 68px; margin: 0 auto 14px; padding: 7px;
        border-radius: 20px; background: #fff; border: 1px solid rgba(127,23,52,.12);
        box-shadow: 0 18px 45px rgba(49,31,37,.14);
    }
    .landing-loader-logo img { width: 100%; height: 100%; object-fit: contain; }
    .landing-loader-line { width: 90px; height: 3px; margin: 0 auto; overflow: hidden; border-radius: 999px; background: #eadfe3; }
    .landing-loader-line::after { content: ''; display: block; width: 45%; height: 100%; border-radius: inherit; background: #7f1734; animation: rmcLoaderLine 1.15s ease-in-out infinite; }

    .hero-card { isolation: isolate; }
    .hero-card::before {
        content: '';
        position: absolute;
        inset: 0;
        z-index: -1;
        background: linear-gradient(115deg, rgba(255,255,255,.18), rgba(255,255,255,0) 46%, rgba(255,255,255,.32));
        pointer-events: none;
    }
    .hero-media-frame {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        border: 1px solid rgba(255,255,255,.9);
        background: #0b1f3a;
        box-shadow: 0 30px 70px rgba(11,31,58,.18);
        animation: rmcHeroZoomOut 1100ms cubic-bezier(.16,1,.3,1) 420ms both;
    }
    .hero-media-frame::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(11,31,58,.03), rgba(11,31,58,.24));
        pointer-events: none;
    }
    .hero-media-frame video {
        display: block;
        width: 100%;
        height: 380px;
        object-fit: cover;
        transform: scale(1.04);
        animation: rmcHeroVideoReveal 1500ms cubic-bezier(.16,1,.3,1) 500ms both;
    }
    .hero-floating-card {
        animation: rmcPopup 900ms cubic-bezier(.16,1,.3,1) 900ms both;
    }
    .hero-copy .hero-seq {
        opacity: 0;
        transform: translateY(22px) scale(.98);
        animation: rmcStaggerUp 760ms cubic-bezier(.16,1,.3,1) var(--delay, 0ms) both;
    }
    .hero-copy .hero-seq.drop {
        transform: translateY(-22px) scale(.98);
        animation-name: rmcDropDown;
    }

    .premium-reveal {
        opacity: 0;
        transform: translateY(34px) scale(.965);
        transition: opacity 800ms cubic-bezier(.16,1,.3,1), transform 800ms cubic-bezier(.16,1,.3,1);
        will-change: opacity, transform;
    }
    .premium-reveal.visible { opacity: 1; transform: none; }
    .premium-reveal.zoom-out { transform: scale(1.055); }
    .premium-reveal.zoom-out.visible { transform: scale(1); }
    .premium-reveal.popup { transform: translateY(24px) scale(.86); }
    .premium-reveal.popup.visible { transform: translateY(0) scale(1); }
    .premium-reveal.drop-down { transform: translateY(-34px) scale(.98); }
    .premium-reveal.drop-down.visible { transform: none; }

    .premium-stagger > * {
        opacity: 0;
        transform: translateY(26px) scale(.96);
        transition: opacity 650ms cubic-bezier(.16,1,.3,1), transform 650ms cubic-bezier(.16,1,.3,1);
        transition-delay: calc(var(--i, 0) * 75ms);
    }
    .premium-stagger.visible > * { opacity: 1; transform: none; }

    .premium-stagger-scale > * {
        opacity: 0;
        transform: scale(.86);
        transition: opacity 700ms cubic-bezier(.16,1,.3,1), transform 700ms cubic-bezier(.16,1,.3,1);
        transition-delay: calc(var(--i, 0) * 80ms);
    }
    .premium-stagger-scale.visible > * { opacity: 1; transform: scale(1); }

    @keyframes rmcAmbientBackground {
        0% { transform: scale(1.06) translate3d(-1%, -1%, 0); background-position: 48% 50%; }
        50% { transform: scale(1.12) translate3d(1%, 1%, 0); background-position: 53% 47%; }
        100% { transform: scale(1.07) translate3d(-.5%, 1%, 0); background-position: 47% 53%; }
    }
    @keyframes rmcOrbFloat { 0% { transform: translate3d(0,0,0) scale(1); } 100% { transform: translate3d(70px,-45px,0) scale(1.12); } }
    @keyframes rmcLoaderPop { from { opacity: 0; transform: translateY(18px) scale(.88); } to { opacity: 1; transform: none; } }
    @keyframes rmcLoaderLine { 0% { transform: translateX(-120%); } 50% { transform: translateX(80%); } 100% { transform: translateX(230%); } }
    @keyframes rmcStaggerUp { from { opacity: 0; transform: translateY(22px) scale(.98); } to { opacity: 1; transform: none; } }
    @keyframes rmcDropDown { from { opacity: 0; transform: translateY(-22px) scale(.98); } to { opacity: 1; transform: none; } }
    @keyframes rmcPopup { from { opacity: 0; transform: translateY(24px) scale(.82); } 70% { opacity: 1; transform: translateY(-3px) scale(1.015); } to { opacity: 1; transform: none; } }
    @keyframes rmcHeroZoomOut { from { opacity: 0; transform: scale(1.10); } to { opacity: 1; transform: scale(1); } }
    @keyframes rmcHeroVideoReveal { from { opacity: 0; transform: scale(1.12); } to { opacity: 1; transform: scale(1.04); } }

    @media (prefers-reduced-motion: reduce) {
        html { scroll-behavior: auto; }
        *, *::before, *::after {
            animation-duration: 0.001ms !important;
            transition-duration: 0.001ms !important;
        }
        [data-animate], .reveal, .rmc-stagger-item, .rmc-drop-item, .rmc-scroll-pop, .premium-reveal, .premium-stagger > *, .premium-stagger-scale > * { opacity: 1 !important; transform: none !important; }
        #home.hero-scroll-scene { position: relative !important; min-height: auto !important; opacity: 1 !important; transform: none !important; filter: none !important; }
        body::before, body::after, .landing-ambient-orb { animation: none !important; }
        .landing-loader { transition: none !important; }
    }
</style>

<div class="landing-loader" id="landingLoader" aria-label="Loading Regis Marie College Events" aria-live="polite">
    <div class="landing-loader-inner">
        <div class="landing-loader-logo"><img src="img/logo.webp" alt="Regis Marie College"></div>
        <div class="text-sm font-semibold text-slate-700 mb-3">RMC Events</div>
        <div class="landing-loader-line"></div>
    </div>
</div>
<div class="landing-ambient-orb orb-a" aria-hidden="true"></div>
<div class="landing-ambient-orb orb-b" aria-hidden="true"></div>
<a href="#main" class="skip-link">Skip to main content</a>

<!-- =========================================================
     NAVIGATION
     ========================================================= -->
<nav id="landingNav" class="sticky top-0 z-50 border-b border-slate-200" data-animate>
    <div class="container px-5 sm:px-6 lg:px-10 py-4 lg:py-5">
        <div class="flex items-center justify-between gap-4">

            <a href="#home" class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-white border border-rmc-100 shadow-sm flex items-center justify-center overflow-hidden shrink-0">
                    <img src="img/logo.webp" alt="Regis Marie College logo" class="w-full h-full object-contain p-1">
                </div>
                <div>
                    <h1 class="text-lg sm:text-2xl font-bold text-slate-900">RMC Events</h1>
                    <p class="text-xs text-slate-500">Regis Marie College</p>
                </div>
            </a>

            <button
                onclick="openLandingMobileMenu()"
                class="lg:hidden w-10 h-10 rounded-xl border border-slate-200 bg-white text-slate-600 flex items-center justify-center focus:outline-none focus-visible:ring-2 focus-visible:ring-rmc-500 focus-visible:ring-offset-2"
                aria-label="Open menu"
            >
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="hidden lg:flex items-center gap-8">
                <a href="#about" class="nav-link text-slate-600 hover:text-rmc-800 transition">About</a>
                <a href="#gallery" class="nav-link text-slate-600 hover:text-rmc-800 transition">Gallery</a>
                <a href="#features" class="nav-link text-slate-600 hover:text-rmc-800 transition">Features</a>
                <a href="#contact" class="nav-link text-slate-600 hover:text-rmc-800 transition">Contact</a>
                <a
                        href="account.php"
                    class="bg-rmc-800 hover:bg-rmc-900 text-white px-5 py-2.5 rounded-xl font-semibold transition hover:-translate-y-0.5 hover:shadow-lg active:translate-y-px active:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-rmc-500 focus-visible:ring-offset-2"
                >
                    Login
                </a>
            </div>
        </div>
    </div>
</nav>

<div id="mobileSidebarLanding" class="fixed inset-0 z-40 bg-white left-0 top-0 transform translate-x-full lg:hidden">
    <div class="flex flex-col h-full px-6 py-8">
        <button
            onclick="closeLandingMobileMenu()"
            class="absolute top-4 right-4 w-8 h-8 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center focus:outline-none focus-visible:ring-2 focus-visible:ring-rmc-500"
            aria-label="Close menu"
        >
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div class="flex items-center gap-3 mb-8">
            <div class="w-10 h-10 rounded-xl bg-white border border-rmc-100 flex items-center justify-center overflow-hidden">
                <img src="img/logo.webp" alt="Regis Marie College logo" class="w-full h-full object-contain p-1">
            </div>
            <h2 class="font-bold text-slate-900">RMC Events</h2>
        </div>
        <nav class="flex-1 flex flex-col gap-2">
            <a href="#about" onclick="closeLandingMobileMenu()" class="block py-3 px-4 rounded-lg hover:bg-rmc-50 text-slate-700 transition">About</a>
            <a href="#gallery" onclick="closeLandingMobileMenu()" class="block py-3 px-4 rounded-lg hover:bg-rmc-50 text-slate-700 transition">Gallery</a>
            <a href="#features" onclick="closeLandingMobileMenu()" class="block py-3 px-4 rounded-lg hover:bg-rmc-50 text-slate-700 transition">Features</a>
            <a href="#contact" onclick="closeLandingMobileMenu()" class="block py-3 px-4 rounded-lg hover:bg-rmc-50 text-slate-700 transition">Contact</a>
            <a href="account.php" class="mt-4 block py-3 px-4 rounded-lg bg-rmc-800 text-white text-center font-semibold transition">Login</a>
        </nav>
    </div>
</div>
<div id="mobileOverlayLanding" class="fixed inset-0 z-30 bg-black/20 hidden lg:hidden" onclick="closeLandingMobileMenu()"></div>

<main id="main">

    <!-- =========================================================
         HERO
         ========================================================= -->
    <section id="home" class="hero-card hero-grid hero-scroll-scene border-b border-rmc-200/60 overflow-hidden">
        <div class="hero-orb hero-orb-one"></div>
        <div class="hero-orb hero-orb-two"></div>

        <div class="container mx-auto px-5 sm:px-6 lg:px-10 pt-16 pb-16 lg:pt-24 lg:pb-24 relative z-10">
            <div class="grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">

                <div class="hero-copy">
                    <div class="inline-flex items-center gap-2 bg-rmc-800 text-white border border-rmc-800 rounded-full px-3.5 py-2 text-[11px] font-bold tracking-wide mb-5 hero-seq drop" style="--delay:120ms">
                        <span class="w-2 h-2 bg-emerald-400 rounded-full"></span>
                        RMC EVENTS
                    </div>

                    <h2 class="text-4xl sm:text-5xl lg:text-6xl font-bold text-slate-900 leading-tight hero-seq" style="--delay:260ms">
                        Where campus events come together.
                    </h2>
                    <p class="mt-6 text-lg text-slate-600 leading-relaxed max-w-lg hero-seq" style="--delay:390ms">
                        RMC Events is Regis Marie College's home for campus activities —
                        register in a tap, get your QR code, and check in without the paperwork.
                    </p>

                    <div class="mt-8 flex flex-wrap gap-3 hero-seq" style="--delay:520ms">
                        <a
                                href="account.php"
                            class="bg-rmc-800 hover:bg-rmc-900 text-white px-6 py-3 rounded-xl font-semibold transition hover:-translate-y-0.5 hover:shadow-lg active:translate-y-px active:shadow-sm inline-flex items-center gap-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-rmc-500 focus-visible:ring-offset-2"
                            data-animate
                        >
                            <i class="fa-solid fa-right-to-bracket"></i>
                            Login to Get Started
                        </a>
                        <a
                                href="#features"
                            class="bg-white hover:bg-rmc-50 text-rmc-800 border border-rmc-200 px-6 py-3 rounded-xl font-semibold transition hover:-translate-y-0.5 hover:shadow-md active:translate-y-px inline-flex items-center gap-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-rmc-500 focus-visible:ring-offset-2"
                            data-animate
                        >
                            See what's inside
                        </a>
                    </div>
                </div>

                <div class="relative">
                    <div class="hero-media-frame">
                        <video
                            autoplay
                            muted
                            loop
                            playsinline
                            preload="metadata"
                            poster="img/image%20for%20landing%20page%203.jpg"
                            aria-label="Regis Marie College campus video"
                        >
                            <source src="video/rmc-campus-video.mp4" type="video/mp4">
                        </video>
                    </div>

                    <div class="absolute -bottom-5 -left-5 sm:-bottom-6 sm:-left-6 bg-white rounded-2xl shadow-lg border border-slate-200 px-5 py-4 flex items-center gap-3 hero-floating-card">
                        <div class="w-11 h-11 rounded-xl bg-rmc-50 flex items-center justify-center shrink-0 overflow-hidden">
                            <img src="img/logo.webp" alt="RMC logo" class="w-full h-full object-contain p-1">
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-slate-800">Regis Marie College</p>
                            <p class="text-xs text-slate-500">Campus Events</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- =========================================================
         CAPABILITIES STRIP
         ========================================================= -->
    <section class="landing-scroll-cover py-6 bg-white border-b border-slate-200">
        <div class="container mx-auto px-5 sm:px-6 lg:px-10">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 reveal">
                <div class="flex items-center gap-2 p-3 rounded-lg bg-rmc-50 hover:bg-rmc-100 transition">
                    <i class="fa-solid fa-magnifying-glass text-rmc-700"></i>
                    <div>
                        <p class="text-xs font-medium text-slate-500">Browse</p>
                        <p class="text-sm font-medium text-slate-800">Campus events</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 p-3 rounded-lg bg-rmc-50 hover:bg-rmc-100 transition">
                    <i class="fa-solid fa-user-check text-rmc-700"></i>
                    <div>
                        <p class="text-xs font-medium text-slate-500">Register</p>
                        <p class="text-sm font-medium text-slate-800">Reserve a spot</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 p-3 rounded-lg bg-rmc-50 hover:bg-rmc-100 transition">
                    <i class="fa-solid fa-qrcode text-rmc-700"></i>
                    <div>
                        <p class="text-xs font-medium text-slate-500">Check in</p>
                        <p class="text-sm font-medium text-slate-800">With a QR code</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 p-3 rounded-lg bg-rmc-50 hover:bg-rmc-100 transition">
                    <i class="fa-solid fa-chart-bar text-rmc-700"></i>
                    <div>
                        <p class="text-xs font-medium text-slate-500">Analyze</p>
                        <p class="text-sm font-medium text-slate-800">Track turnout</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- =========================================================
         ABOUT
         ========================================================= -->
    <section id="about" class="py-16 lg:py-20 bg-white">
        <div class="container mx-auto px-5 sm:px-6 lg:px-10">

            <div class="grid lg:grid-cols-2 gap-12 items-center mb-16">
                <div class="reveal order-2 lg:order-1">
                    <h2 class="text-3xl lg:text-4xl font-bold text-slate-900 mb-5">
                        One platform, the whole campus.
                    </h2>
                    <p class="text-slate-600 leading-relaxed mb-4">
                        Before RMC Events, organizing a campus activity meant paper sign-up
                        sheets, manual attendance counts, and no easy way to know who actually
                        showed up. This platform brings students, organizers, and administrators
                        onto the same system — so every event has one clear record from
                        registration to attendance.
                    </p>
                    <p class="text-slate-600 leading-relaxed">
                        Log in with your RMC account to browse what's happening on campus,
                        reserve your spot, and get a QR code for fast check-in at the door.
                    </p>
                </div>
                <div class="order-1 lg:order-2 reveal">
                    <div class="rounded-[26px] overflow-hidden shadow-lg border border-slate-200 group">
                        <img
                            src="img/image%20for%20landing%20page.jpg"
                            alt="Regis Marie College students on campus"
                            class="w-full h-80 object-cover object-top transition duration-300 group-hover:scale-[1.03]"
                        >
                    </div>
                </div>
            </div>

            <!-- Roles -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-8">
                <div class="reveal">
                    <div class="w-12 h-12 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-user-graduate"></i>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-3">Students</h3>
                    <p class="text-slate-600 text-sm mb-4">
                        Discover events, register online, receive QR codes, and take part in campus life.
                    </p>
                    <ul class="space-y-2 text-sm text-slate-500">
                        <li>Browse upcoming events</li>
                        <li>Register in one click</li>
                        <li>Get your event QR code</li>
                        <li>Track your participation</li>
                    </ul>
                </div>
                <div class="reveal" style="transition-delay:70ms">
                    <div class="w-12 h-12 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-clipboard-list"></i>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-3">Organizers</h3>
                    <p class="text-slate-600 text-sm mb-4">
                        Create events, manage registrations, monitor attendance, and follow up with feedback.
                    </p>
                    <ul class="space-y-2 text-sm text-slate-500">
                        <li>Create and publish events</li>
                        <li>Manage registrations</li>
                        <li>Monitor attendance live</li>
                        <li>Review event feedback</li>
                    </ul>
                </div>
                <div class="reveal" style="transition-delay:140ms">
                    <div class="w-12 h-12 rounded-xl bg-rmc-50 text-rmc-800 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-3">Administrators</h3>
                    <p class="text-slate-600 text-sm mb-4">
                        Manage users, oversee all events campus-wide, and monitor system-level analytics.
                    </p>
                    <ul class="space-y-2 text-sm text-slate-500">
                        <li>Manage users and roles</li>
                        <li>Oversee all events</li>
                        <li>Access analytics dashboards</li>
                        <li>Configure system settings</li>
                    </ul>
                </div>
            </div>

        </div>
    </section>

    <!-- =========================================================
         GALLERY — centered carousel inspired by the approved reference
         ========================================================= -->
    <section id="gallery" class="py-16 lg:py-20 bg-rmc-50/40">
        <div class="container mx-auto px-5 sm:px-6 lg:px-10">
            <div class="mb-10 reveal">
                <h2 class="text-3xl lg:text-4xl font-bold text-slate-900 mb-3">Life on campus.</h2>
                <p class="text-slate-600 max-w-xl">A few glimpses of what's been happening around Regis Marie College.</p>
            </div>

            <div class="rmc-gallery reveal" data-gallery-carousel aria-label="Regis Marie College gallery">
                <div class="rmc-gallery-backdrop" aria-hidden="true"></div>
                <div class="rmc-gallery-track">
                    <?php
                    $landing_gallery = [
                        ['img' => 'image for landing page.jpg', 'alt' => 'Regis Marie College campus'],
                        ['img' => 'image for landing page 2.jpg', 'alt' => 'Campus life at Regis Marie College'],
                        ['img' => 'image for landing page 3.jpg', 'alt' => 'Students at Regis Marie College'],
                        ['img' => 'image for landing page 4.png', 'alt' => 'Campus event at Regis Marie College'],
                        ['img' => 'image for landing page 5.png', 'alt' => 'Campus activity at Regis Marie College'],
                        ['img' => 'image for landing page 6.webp', 'alt' => 'Campus scene at Regis Marie College'],
                        ['img' => 'image for landing page 7.jpg', 'alt' => 'Students at Regis Marie College'],
                        ['img' => 'image for landing page 8.jpg', 'alt' => 'Campus event highlight at Regis Marie College'],
                    ];
                    foreach ($landing_gallery as $index => $item):
                    ?>
                        <button type="button" class="rmc-gallery-slide<?= $index === 0 ? ' is-active' : ''; ?>" data-gallery-index="<?= $index; ?>" aria-label="View gallery image <?= $index + 1; ?>">
                            <img src="img/<?= rawurlencode($item['img']); ?>" alt="<?= htmlspecialchars($item['alt']); ?>">
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="rmc-gallery-controls">
                    <button type="button" class="rmc-gallery-arrow" data-gallery-prev aria-label="Previous gallery image"><i class="fa-solid fa-arrow-left"></i></button>
                    <button type="button" class="rmc-gallery-arrow" data-gallery-next aria-label="Next gallery image"><i class="fa-solid fa-arrow-right"></i></button>
                </div>
                <div class="rmc-gallery-dots" role="tablist" aria-label="Gallery image selection">
                    <?php foreach ($landing_gallery as $index => $_): ?>
                        <button type="button" class="rmc-gallery-dot<?= $index === 0 ? ' is-active' : ''; ?>" data-gallery-index="<?= $index; ?>" role="tab" aria-label="Gallery image <?= $index + 1; ?>" aria-selected="<?= $index === 0 ? 'true' : 'false'; ?>"></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- =========================================================
         FEATURES
         ========================================================= -->
    <section id="features" class="py-16 lg:py-20 bg-white">
        <div class="container mx-auto px-5 sm:px-6 lg:px-10">
            <div class="mb-12 reveal">
                <h2 class="text-3xl lg:text-4xl font-bold text-slate-900 mb-3">Everything an event needs.</h2>
                <p class="text-slate-600 max-w-xl">From the first announcement to the final feedback form, RMC Events keeps every step in one place.</p>
            </div>
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <div class="reveal bg-white rounded-2xl p-6 border border-slate-200 transition duration-200 hover:-translate-y-1 hover:shadow-lg hover:border-rmc-200">
                    <div class="h-12 w-12 rounded-xl bg-rmc-50 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-calendar-day text-rmc-700"></i>
                    </div>
                    <h3 class="font-semibold text-slate-900">Event Management</h3>
                    <p class="text-sm text-slate-500 mt-2">
                        Create, publish, archive, and organize campus events from one dashboard.
                    </p>
                </div>
                <div class="reveal bg-white rounded-2xl p-6 border border-slate-200 transition duration-200 hover:-translate-y-1 hover:shadow-lg hover:border-rmc-200" style="transition-delay:60ms">
                    <div class="h-12 w-12 rounded-xl bg-rmc-50 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-user-plus text-rmc-700"></i>
                    </div>
                    <h3 class="font-semibold text-slate-900">Smart Registration</h3>
                    <p class="text-sm text-slate-500 mt-2">
                        Students register online and instantly receive their event QR code.
                    </p>
                </div>
                <div class="reveal bg-white rounded-2xl p-6 border border-slate-200 transition duration-200 hover:-translate-y-1 hover:shadow-lg hover:border-rmc-200" style="transition-delay:120ms">
                    <div class="h-12 w-12 rounded-xl bg-rmc-50 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-qrcode text-rmc-700"></i>
                    </div>
                    <h3 class="font-semibold text-slate-900">QR Attendance</h3>
                    <p class="text-sm text-slate-500 mt-2">
                        Fast, paperless attendance verification right at the venue.
                    </p>
                </div>
                <div class="reveal bg-white rounded-2xl p-6 border border-slate-200 transition duration-200 hover:-translate-y-1 hover:shadow-lg hover:border-rmc-200">
                    <div class="h-12 w-12 rounded-xl bg-rmc-50 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-bell text-rmc-700"></i>
                    </div>
                    <h3 class="font-semibold text-slate-900">Notifications</h3>
                    <p class="text-sm text-slate-500 mt-2">
                        Stay informed with timely updates on events that matter to you.
                    </p>
                </div>
                <div class="reveal bg-white rounded-2xl p-6 border border-slate-200 transition duration-200 hover:-translate-y-1 hover:shadow-lg hover:border-rmc-200" style="transition-delay:60ms">
                    <div class="h-12 w-12 rounded-xl bg-rmc-50 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-chart-bar text-rmc-700"></i>
                    </div>
                    <h3 class="font-semibold text-slate-900">Analytics</h3>
                    <p class="text-sm text-slate-500 mt-2">
                        Understand turnout and satisfaction trends across every event.
                    </p>
                </div>
                <div class="reveal bg-white rounded-2xl p-6 border border-slate-200 transition duration-200 hover:-translate-y-1 hover:shadow-lg hover:border-rmc-200" style="transition-delay:120ms">
                    <div class="h-12 w-12 rounded-xl bg-rmc-50 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-users text-rmc-700"></i>
                    </div>
                    <h3 class="font-semibold text-slate-900">User Management</h3>
                    <p class="text-sm text-slate-500 mt-2">
                        Manage students, organizers, and administrators from one place.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- =========================================================
         HOW IT WORKS
         ========================================================= -->
    <section class="py-16 lg:py-20 bg-white">
        <div class="container mx-auto px-5 sm:px-6 lg:px-10">
            <h2 class="text-3xl lg:text-4xl font-bold text-slate-900 mb-10 reveal">How it works</h2>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-8 max-w-5xl">
                <div class="reveal">
                    <div class="w-12 h-12 rounded-xl bg-rmc-50 flex items-center justify-center mb-4 text-rmc-700 font-bold">1</div>
                    <h3 class="font-semibold text-slate-900 mb-2">Sign in</h3>
                    <p class="text-slate-500 text-sm">Log in with your RMC student or organizer account.</p>
                </div>
                <div class="reveal" style="transition-delay:70ms">
                    <div class="w-12 h-12 rounded-xl bg-rmc-50 flex items-center justify-center mb-4 text-rmc-700 font-bold">2</div>
                    <h3 class="font-semibold text-slate-900 mb-2">Register</h3>
                    <p class="text-slate-500 text-sm">Browse what's happening and reserve your spot.</p>
                </div>
                <div class="reveal" style="transition-delay:140ms">
                    <div class="w-12 h-12 rounded-xl bg-rmc-50 flex items-center justify-center mb-4 text-rmc-700 font-bold">3</div>
                    <h3 class="font-semibold text-slate-900 mb-2">Get your QR</h3>
                    <p class="text-slate-500 text-sm">Receive a unique code for check-in at the venue.</p>
                </div>
                <div class="reveal" style="transition-delay:210ms">
                    <div class="w-12 h-12 rounded-xl bg-rmc-50 flex items-center justify-center mb-4 text-rmc-700 font-bold">4</div>
                    <h3 class="font-semibold text-slate-900 mb-2">Track results</h3>
                    <p class="text-slate-500 text-sm">Organizers see attendance and feedback in real time.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- =========================================================
         ANALYTICS PREVIEW
         ========================================================= -->
    <section class="py-16 lg:py-20 bg-rmc-50/40">
        <div class="container mx-auto px-5 sm:px-6 lg:px-10">
            <div class="mb-10 reveal">
                <h2 class="text-3xl lg:text-4xl font-bold text-slate-900 mb-3">
                    Turn participation into insight.
                </h2>
                <p class="text-slate-600 max-w-xl">Organizers and admins get a clear picture of what's working, straight from the dashboard.</p>
            </div>
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
                <div class="reveal bg-white rounded-2xl p-6 border border-slate-200">
                    <div class="w-10 h-10 rounded-xl bg-rmc-100 text-rmc-800 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-chart-column"></i>
                    </div>
                    <h3 class="text-sm font-semibold text-slate-700 mb-1">Event participation</h3>
                    <p class="text-xs text-slate-500">Registrations vs. attendance, per event.</p>
                </div>
                <div class="reveal bg-white rounded-2xl p-6 border border-slate-200" style="transition-delay:70ms">
                    <div class="w-10 h-10 rounded-xl bg-rmc-100 text-rmc-800 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <h3 class="text-sm font-semibold text-slate-700 mb-1">Attendance rate</h3>
                    <p class="text-xs text-slate-500">See how many registrants actually showed up.</p>
                </div>
                <div class="reveal bg-white rounded-2xl p-6 border border-slate-200" style="transition-delay:140ms">
                    <div class="w-10 h-10 rounded-xl bg-rmc-100 text-rmc-800 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-arrow-trend-up"></i>
                    </div>
                    <h3 class="text-sm font-semibold text-slate-700 mb-1">Registration trends</h3>
                    <p class="text-xs text-slate-500">Track sign-up activity over time.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- =========================================================
         FINAL CTA
         ========================================================= -->
    <section class="py-16 lg:py-20 bg-rmc-950 text-white">
        <div class="container mx-auto px-5 sm:px-6 lg:px-10">
            <div class="max-w-2xl mx-auto text-center reveal">
                <h2 class="text-3xl font-bold mb-4">Your next campus event starts here.</h2>
                <p class="text-white/80 mb-8">
                    Log in to discover events, register, and get involved.
                </p>
                <a
                        href="account.php"
                    class="bg-white text-rmc-800 hover:bg-rmc-50 px-6 py-3 rounded-xl font-semibold transition hover:-translate-y-0.5 hover:shadow-lg active:translate-y-px inline-flex items-center gap-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-rmc-950"
                >
                    Login
                </a>
            </div>
        </div>
    </section>

    <!-- =========================================================
         CONTACT
         ========================================================= -->
    <section id="contact" class="py-16 lg:py-20 bg-white">
        <div class="container mx-auto px-5 sm:px-6 lg:px-10">
            <div class="max-w-2xl reveal">
                <h2 class="text-3xl lg:text-4xl font-bold text-slate-900 mb-4">Get in touch</h2>
                <p class="text-slate-600 mb-8">
                    Questions about an event or the platform? Reach Regis Marie College through
                    our official channels below.
                </p>
            </div>
            <div class="flex flex-wrap gap-4 reveal" style="transition-delay:80ms">
                <a
                        href="https://www.facebook.com/share/1DYsPW9g8S/?mibextid=wwXIfr"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="Regis Marie College on Facebook"
                    class="w-14 h-14 rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center text-xl transition duration-200 hover:scale-[1.08] hover:-translate-y-[2px] hover:bg-rmc-100 active:scale-100 active:translate-y-px focus:outline-none focus-visible:ring-2 focus-visible:ring-rmc-500 focus-visible:ring-offset-2"
                >
                    <i class="fa-brands fa-facebook-f"></i>
                </a>
                <a
                        href="https://www.tiktok.com/@regismariecollege?_r=1&_t=ZS-99ZKx2WnCSO"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="Regis Marie College on TikTok"
                    class="w-14 h-14 rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center text-xl transition duration-200 hover:scale-[1.08] hover:-translate-y-[2px] hover:bg-rmc-100 active:scale-100 active:translate-y-px focus:outline-none focus-visible:ring-2 focus-visible:ring-rmc-500 focus-visible:ring-offset-2"
                >
                    <i class="fa-brands fa-tiktok"></i>
                </a>
                <a
                        href="https://youtube.com/@regismariecollege2737?si=0p0r_6aCOLre_Oop"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="Regis Marie College on YouTube"
                    class="w-14 h-14 rounded-2xl bg-rmc-50 text-rmc-800 flex items-center justify-center text-xl transition duration-200 hover:scale-[1.08] hover:-translate-y-[2px] hover:bg-rmc-100 active:scale-100 active:translate-y-px focus:outline-none focus-visible:ring-2 focus-visible:ring-rmc-500 focus-visible:ring-offset-2"
                >
                    <i class="fa-brands fa-youtube"></i>
                </a>
            </div>
        </div>
    </section>

</main>

<!-- =========================================================
     FOOTER
     ========================================================= -->
<footer class="py-12 bg-rmc-950 text-white reveal">
    <div class="container mx-auto px-5 sm:px-6 lg:px-10">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center overflow-hidden shrink-0">
                    <img src="img/logo.webp" alt="RMC logo" class="w-full h-full object-contain p-1">
                </div>
                <div>
                    <h3 class="font-bold text-white mb-1">Regis Marie College</h3>
                    <p class="text-white/60 text-sm">
                        Campus Event Management and Data Analytics System
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                <a href="#about" class="text-white/60 hover:text-white hover:-translate-y-px transition inline-block">About</a>
                <a href="#gallery" class="text-white/60 hover:text-white hover:-translate-y-px transition inline-block">Gallery</a>
                <a href="#features" class="text-white/60 hover:text-white hover:-translate-y-px transition inline-block">Features</a>
                <a href="#contact" class="text-white/60 hover:text-white hover:-translate-y-px transition inline-block">Contact</a>
                <a href="account.php" class="text-white/60 hover:text-white hover:-translate-y-px transition inline-block">Login</a>
                <a
                        href="https://www.facebook.com/share/1DYsPW9g8S/?mibextid=wwXIfr"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="Facebook"
                    class="text-white/60 hover:text-white hover:-translate-y-px transition inline-block"
                >
                    <i class="fa-brands fa-facebook-f"></i>
                </a>
                <a
                        href="https://www.tiktok.com/@regismariecollege?_r=1&_t=ZS-99ZKx2WnCSO"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="TikTok"
                    class="text-white/60 hover:text-white hover:-translate-y-px transition inline-block"
                >
                    <i class="fa-brands fa-tiktok"></i>
                </a>
                <a
                        href="https://youtube.com/@regismariecollege2737?si=0p0r_6aCOLre_Oop"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="YouTube"
                    class="text-white/60 hover:text-white hover:-translate-y-px transition inline-block"
                >
                    <i class="fa-brands fa-youtube"></i>
                </a>
            </div>
        </div>
        <div class="mt-8 pt-6 border-t border-white/10 text-white/40 text-xs">
            &copy; <?= date('Y'); ?> Regis Marie College. All rights reserved.
        </div>
    </div>
</footer>

<!-- =========================================================
     JAVASCRIPT (scoped to landing page only — does not
     conflict with the sidebar/header JS used on other pages)
     ========================================================= -->
<script>
    function openLandingMobileMenu() {
        document.getElementById('mobileSidebarLanding').classList.add('open');
        document.getElementById('mobileOverlayLanding').classList.remove('hidden');
        document.getElementById('mobileOverlayLanding').classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeLandingMobileMenu() {
        document.getElementById('mobileSidebarLanding').classList.remove('open');
        document.getElementById('mobileOverlayLanding').classList.remove('open');
        document.getElementById('mobileOverlayLanding').classList.add('hidden');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
        anchor.addEventListener('click', function (e) {
            var id = this.getAttribute('href');
            if (id.length < 2) return;
            var target = document.querySelector(id);
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    /* Premium landing-only motion: section reveals, staggered children, zoom and pop effects. */
    (function initPremiumLandingMotion() {
        var loader = document.getElementById('landingLoader');
        function hideLoader() {
            if (loader) loader.classList.add('is-hidden');
        }
        if (document.readyState === 'complete') setTimeout(hideLoader, 180);
        else window.addEventListener('load', function () { setTimeout(hideLoader, 220); }, { once: true });
        setTimeout(hideLoader, 2200);

        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var sections = Array.prototype.slice.call(document.querySelectorAll('#main > section'));
        sections.forEach(function (section, sectionIndex) {
            var headingBlock = section.querySelector('.mb-10, .mb-12');
            if (headingBlock) {
                headingBlock.classList.add('premium-reveal', 'drop-down');
            }
            var grid = section.querySelector('.grid');
            if (grid && !grid.closest('.hero-card')) {
                grid.classList.add('premium-stagger');
                Array.prototype.forEach.call(grid.children, function (child, i) { child.style.setProperty('--i', i); });
            }
            var major = section.querySelector('.rmc-gallery, .rounded-2xl, .rounded-3xl');
            if (major && !major.closest('.hero-card')) major.classList.add('premium-reveal', sectionIndex % 2 ? 'zoom-out' : 'popup');
        });

        var revealNodes = document.querySelectorAll('.premium-reveal, .premium-stagger, .premium-stagger-scale');
        if (reduce) {
            revealNodes.forEach(function (el) { el.classList.add('visible'); });
            return;
        }
        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (entries, observer) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
            revealNodes.forEach(function (el) { io.observe(el); });
        } else {
            revealNodes.forEach(function (el) { el.classList.add('visible'); });
        }
    })();

    var landingReduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var landingNav = document.getElementById('landingNav');
    function updateLandingNavOnScroll() {
        if (window.scrollY > 8) {
            landingNav.classList.add('nav-scrolled');
        } else {
            landingNav.classList.remove('nav-scrolled');
        }
    }
    window.addEventListener('scroll', updateLandingNavOnScroll, { passive: true });
    updateLandingNavOnScroll();

    // Scroll-linked background parallax using the uploaded landing-page image.
    // The image moves at a slower rate than the page, creating a gentle depth effect.
    (function initLandingBackgroundParallax() {
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduce) return;
        var ticking = false;
        function updateBackgroundParallax() {
            var y = window.scrollY || window.pageYOffset || 0;
            var bgShift = Math.round(y * -0.055);
            var layerShift = Math.round(y * 0.025);
            var foregroundScale = Math.min(1.095, 1.07 + (Math.min(y, 1800) / 1800) * 0.025);
            document.documentElement.style.setProperty('--rmc-bg-scroll', bgShift + 'px');
            document.documentElement.style.setProperty('--rmc-bg-y', layerShift + 'px');
            document.documentElement.style.setProperty('--rmc-bg-layer-y', Math.round(y * -0.018) + 'px');
            document.documentElement.style.setProperty('--rmc-bg-scale', foregroundScale.toFixed(4));
            ticking = false;
        }
        function requestBackgroundUpdate() {
            if (ticking) return;
            ticking = true;
            window.requestAnimationFrame(updateBackgroundParallax);
        }
        window.addEventListener('scroll', requestBackgroundUpdate, { passive: true });
        window.addEventListener('resize', requestBackgroundUpdate, { passive: true });
        updateBackgroundParallax();
    })();

    document.addEventListener('DOMContentLoaded', function () {
        var items = document.querySelectorAll('[data-animate]');
        items.forEach(function (el, i) {
            if (landingReduceMotion) {
                el.classList.add('in');
                return;
            }
            var delay = i * 90;
            setTimeout(function () {
                requestAnimationFrame(function () {
                    el.classList.add('in');
                });
            }, delay);
        });
    });

    function prepareLandingScrollMotion() {
        document.querySelectorAll('.reveal').forEach(function (el) {
            var children = Array.prototype.slice.call(el.children || []);
            children.forEach(function (child, index) {
                if (!child.classList.contains('rmc-gallery-backdrop')) {
                    child.classList.add('rmc-stagger-item');
                    child.style.setProperty('--rmc-stagger-delay', Math.min(index * 70, 420) + 'ms');
                }
            });

            var heading = el.querySelector('h1, h2, h3');
            if (heading && !heading.closest('.rmc-gallery')) {
                heading.classList.add('rmc-drop-item');
                heading.style.setProperty('--rmc-drop-delay', '60ms');
            }
        });
    }

    prepareLandingScrollMotion();

    if ('IntersectionObserver' in window) {
        var landingRevealObserver = new IntersectionObserver(function (entries, obs) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    obs.unobserve(entry.target);
                }
            });
        }, { threshold: 0.14, rootMargin: '0px 0px -6% 0px' });

        document.querySelectorAll('.reveal').forEach(function (el) {
            landingRevealObserver.observe(el);
        });
    } else {
        document.querySelectorAll('.reveal').forEach(function (el) {
            el.classList.add('visible');
        });
    }

    // First hero cinematic scroll: slow vanish/zoom, then complete disappearance
    // before the next section. (Restored to the exact original implementation —
    // it sits at the very top of the page, so raw window.scrollY works directly
    // as its progress measurement with no offset needed.)
    (function initHeroScrollScene() {
        var hero = document.getElementById('home');
        var cover = document.querySelector('.landing-scroll-cover');
        if (!hero || !cover) return;
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduce) return;
        var ticking = false;
        function update() {
            var h = hero.offsetHeight || window.innerHeight;
            var y = Math.max(0, window.scrollY || 0);
            // Use the first viewport-to-hero boundary as the complete transition range.
            // The hero reaches ZERO opacity before the next section fully replaces it.
            var transitionDistance = Math.max(1, h * 0.92);
            var progress = Math.max(0, Math.min(1, y / transitionDistance));
            var eased = progress * progress * (3 - 2 * progress); // smoothstep
            var opacity = 1 - eased;
            var scale = 1 - (eased * 0.10);
            var lift = eased * -42;
            var blur = eased * 5;
            hero.style.opacity = opacity.toFixed(3);
            hero.style.transform = 'translate3d(0,' + lift.toFixed(1) + 'px,0) scale(' + scale.toFixed(4) + ')';
            hero.style.filter = 'blur(' + blur.toFixed(2) + 'px)';
            hero.style.pointerEvents = progress >= 0.98 ? 'none' : '';
            hero.setAttribute('aria-hidden', progress >= 0.98 ? 'true' : 'false');
            // Fully remove the hero visually before the next section settles into place.
            ticking = false;
        }
        function request() {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(update);
        }
        window.addEventListener('scroll', request, { passive: true });
        window.addEventListener('resize', request, { passive: true });
        request();
    })();

    // Gallery carousel: side previews + centered active image.
    (function initRmcGallery() {
        var gallery = document.querySelector('[data-gallery-carousel]');
        if (!gallery) return;
        var slides = Array.prototype.slice.call(gallery.querySelectorAll('.rmc-gallery-slide'));
        var dots = Array.prototype.slice.call(gallery.querySelectorAll('.rmc-gallery-dot'));
        var backdrop = gallery.querySelector('.rmc-gallery-backdrop');
        var current = 0;
        var timer = null;
        var wheelLock = false;
        var touchStartX = 0;
        var touchStartY = 0;
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        gallery.classList.add('is-scroll-ready');

        function update(index, fromScroll) {
            current = (index + slides.length) % slides.length;
            slides.forEach(function (slide, i) {
                slide.classList.remove('is-active', 'is-prev', 'is-next', 'is-hidden');
                if (i === current) slide.classList.add('is-active');
                else if (i === (current - 1 + slides.length) % slides.length) slide.classList.add('is-prev');
                else if (i === (current + 1) % slides.length) slide.classList.add('is-next');
                else slide.classList.add('is-hidden');
                slide.setAttribute('aria-current', i === current ? 'true' : 'false');
            });
            dots.forEach(function (dot, i) {
                var active = i === current;
                dot.classList.toggle('is-active', active);
                dot.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            if (fromScroll && !reduceMotion) {
                gallery.classList.remove('is-scroll-changing');
                void gallery.offsetWidth;
                gallery.classList.add('is-scroll-changing');
                window.setTimeout(function () { gallery.classList.remove('is-scroll-changing'); }, 720);
            }
            if (backdrop && slides[current]) {
                var image = slides[current].querySelector('img');
                if (image) backdrop.style.setProperty('--gallery-bg', "url('" + image.getAttribute('src') + "')");
            }
        }

        function next(fromScroll) { update(current + 1, !!fromScroll); }
        function prev(fromScroll) { update(current - 1, !!fromScroll); }
        function startAuto() {
            if (reduceMotion) return;
            window.clearInterval(timer);
            timer = window.setInterval(next, 5200);
        }

        gallery.querySelector('[data-gallery-next]')?.addEventListener('click', function () { next(); startAuto(); });
        gallery.querySelector('[data-gallery-prev]')?.addEventListener('click', function () { prev(); startAuto(); });
        slides.forEach(function (slide, i) { slide.addEventListener('click', function () { update(i); startAuto(); }); });
        dots.forEach(function (dot, i) { dot.addEventListener('click', function () { update(i); startAuto(); }); });
        gallery.addEventListener('mouseenter', function () { window.clearInterval(timer); });
        gallery.addEventListener('mouseleave', startAuto);
        gallery.addEventListener('focusin', function () { window.clearInterval(timer); });
        gallery.addEventListener('focusout', function () { startAuto(); });

        // Scroll over the gallery advances the horizontal-style carousel one image at a time.
        // This makes mouse-wheel/trackpad scrolling feel like sliding sideways without
        // changing the page's normal vertical scroll outside the gallery.
        gallery.addEventListener('wheel', function (event) {
            if (Math.abs(event.deltaY) < Math.abs(event.deltaX)) return;
            if (wheelLock) { event.preventDefault(); return; }
            if (Math.abs(event.deltaY) < 10) return;
            event.preventDefault();
            wheelLock = true;
            if (event.deltaY > 0) next(true);
            else prev(true);
            startAuto();
            window.setTimeout(function () { wheelLock = false; }, 720);
        }, { passive: false });

        // Touch swipe support for phones/tablets.
        gallery.addEventListener('touchstart', function (event) {
            if (!event.touches || !event.touches[0]) return;
            touchStartX = event.touches[0].clientX;
            touchStartY = event.touches[0].clientY;
        }, { passive: true });
        gallery.addEventListener('touchend', function (event) {
            if (!event.changedTouches || !event.changedTouches[0]) return;
            var dx = event.changedTouches[0].clientX - touchStartX;
            var dy = event.changedTouches[0].clientY - touchStartY;
            if (Math.abs(dx) < 45 || Math.abs(dx) < Math.abs(dy)) return;
            if (dx < 0) next(true); else prev(true);
            startAuto();
        }, { passive: true });

        update(0);
        startAuto();
    })();

</script>

</body>
</html>