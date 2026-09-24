<?php
/*
|--------------------------------------------------------------------------
| RMC Events — Footer + Shared JavaScript
|--------------------------------------------------------------------------
| Closes the page content container and <main> opened by partials/header.php,
| renders the footer, and loads the shared JS (mobile menu, notification
| panel, animated counters, ESC close) plus the dark-mode partial.
|--------------------------------------------------------------------------
*/
?>

<!-- =========================================================
     FOOTER
     ========================================================= -->

<footer
    class="py-8 text-center text-xs text-slate-400"
>

    <p>
        <?= t('footer_line1'); ?>
    </p>

    <p class="mt-1">
        <?= t('footer_line2'); ?>
    </p>

</footer>


</div>

</main>


<!-- =========================================================
     JAVASCRIPT
     ========================================================= -->

<script>

/* =========================================================
   MOBILE MENU
   ========================================================= */

function openMobileMenu() {

    const sidebar =
        document.getElementById('mobileSidebar');

    const overlay =
        document.getElementById('mobileOverlay');

    if (sidebar) {
        sidebar.classList.add('open');
    }

    if (overlay) {
        overlay.classList.add('open');
    }

    document.body.style.overflow = 'hidden';
}


function closeMobileMenu() {

    const sidebar =
        document.getElementById('mobileSidebar');

    const overlay =
        document.getElementById('mobileOverlay');

    if (sidebar) {
        sidebar.classList.remove('open');
    }

    if (overlay) {
        overlay.classList.remove('open');
    }

    document.body.style.overflow = '';
}


/* =========================================================
   NOTIFICATION PANEL
   ========================================================= */

function toggleNotificationPanel() {

    const panel =
        document.getElementById('notificationPanel');

    if (!panel) {
        return;
    }

    panel.classList.toggle('hidden');
}

document.addEventListener('click', function (event) {

    const panel =
        document.getElementById('notificationPanel');

    if (!panel) {
        return;
    }

    if (!panel.parentElement.contains(event.target)) {
        panel.classList.add('hidden');
    }
});


/* =========================================================
   ANIMATED COUNTERS
   ========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const counters =
            document.querySelectorAll('.counter');


        counters.forEach(counter => {

            const target =
                parseInt(
                    counter.dataset.value || '0',
                    10
                );

            let current = 0;

            const duration = 700;

            const startTime =
                performance.now();


            function animate(time) {

                const progress =
                    Math.min(
                        (time - startTime) /
                        duration,
                        1
                    );

                current =
                    Math.floor(
                        progress * target
                    );

                counter.textContent =
                    current;

                if (progress < 1) {

                    requestAnimationFrame(
                        animate
                    );

                } else {

                    counter.textContent =
                        target;
                }
            }

            requestAnimationFrame(
                animate
            );
        });
    }
);


/* =========================================================
   CLOSE MOBILE MENU WITH ESC
   ========================================================= */

document.addEventListener(
    'keydown',
    function (event) {

        if (event.key === 'Escape') {

            closeMobileMenu();
        }
    }
);

</script>

<!-- =========================================================
     GLOBAL LOADING OVERLAY
     ========================================================= -->

<div id="rmcLoadingOverlay" class="hidden" role="alert" aria-live="assertive">
    <div id="rmcLoadingBox">
        <div id="rmcLoadingSpinner"></div>
        <p id="rmcLoadingText">Processing...</p>
        <p id="rmcLoadingSubtext">Please wait.</p>
    </div>
</div>

<script>
(function() {
    var overlay = document.getElementById('rmcLoadingOverlay');
    var textEl  = document.getElementById('rmcLoadingText');
    var subEl   = document.getElementById('rmcLoadingSubtext');
    var spinner = document.getElementById('rmcLoadingSpinner');
    if (!overlay) return;

    var actionMap = {
        'create_event':     { text: 'Submitting event...',    sub: 'Please wait.' },
        'approve_id':      { text: 'Approving event...',     sub: 'Please wait.' },
        'reject_id':       { text: 'Rejecting event...',     sub: 'Please wait.' },
        'archive_id':      { text: 'Archiving event...',     sub: 'Please wait.' },
        'unarchive_id':    { text: 'Restoring event...',     sub: 'Please wait.' },
        'delete_id':       { text: 'Deleting event...',      sub: 'This may take a moment.' },
        'toggle_status':   { text: 'Updating user...',       sub: 'Please wait.' },
        'unlock_user_id':  { text: 'Unlocking account...',   sub: 'Please wait.' },
        'delete_notification_id': { text: 'Deleting...',     sub: 'Please wait.' },
        'bulk_action':     { text: 'Processing bulk action...', sub: 'This may take a moment.' },
        'toggle_setting':  { text: 'Saving settings...',     sub: 'Please wait.' },
        'bulk_delete_ids': { text: 'Deleting...',            sub: 'Please wait.' },
        'mark_all_read':   { text: 'Marking as read...',     sub: 'Please wait.' },
        'mark_read':       { text: 'Marking as read...',     sub: 'Please wait.' },
        'register_event_id': { text: 'Registering...',       sub: 'Please wait.' }
    };

    var defaultAction = { text: 'Processing...', sub: 'Please wait.' };

    function showLoading(msg) {
        if (msg) {
            textEl.textContent = msg.text || 'Processing...';
            subEl.textContent  = msg.sub  || 'Please wait.';
        }
        overlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function hideLoading() {
        overlay.classList.add('hidden');
        document.body.style.overflow = '';
    }

    window.rmcShowLoading  = showLoading;
    window.rmcHideLoading  = hideLoading;

    function detectAction(formData) {
        var keys = Object.keys(actionMap);
        for (var i = 0; i < keys.length; i++) {
            if (formData.has(keys[i])) return actionMap[keys[i]];
        }
        return defaultAction;
    }

    var delayedActions = { 'create_event': 1, 'approve_id': 1, 'register_event_id': 1 };

    /* Save native submit BEFORE prototype override */
    var nativeSubmit = HTMLFormElement.prototype.submit;

    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (form.method && form.method.toUpperCase() !== 'POST') return;
        if (form.closest('#rmcLoadingOverlay')) return;

        var fd = new FormData(form);
        var msg = detectAction(fd);
        showLoading(msg);

        var btn = form.querySelector('button[type="submit"], button:not([type="button"])');
        if (btn) {
            btn.disabled = true;
            btn.setAttribute('data-rmc-disabled', '1');
        }

        var keys = Object.keys(delayedActions);
        for (var i = 0; i < keys.length; i++) {
            if (fd.has(keys[i])) {
                e.preventDefault();
                e.stopImmediatePropagation();
                setTimeout(function() { nativeSubmit.call(form); }, 1500);
                return;
            }
        }
    }, true);

    window.addEventListener('pageshow', function() { hideLoading(); });
    window.addEventListener('beforeunload', function() { hideLoading(); });

    /* Override form.submit() so that JS-initiated submits
       still fire the submit event → loading overlay appears */
    HTMLFormElement.prototype.submit = function() {
        var evt = new Event('submit', { bubbles: true, cancelable: true });
        this.dispatchEvent(evt);
        nativeSubmit.call(this);
    };
})();
</script>


<?php include_once __DIR__ . '/dark_mode.php'; ?>

<script>
/* =========================================================
   PER-TAB ROLE BRIDGE — independent multi-role sessions
   Each tab remembers its own role in sessionStorage and
   tags navigations so the server activates the matching
   role's auth data. No visual/behavioral change otherwise.
   ========================================================= */
(function() {
    var serverRole = <?= json_encode($_SESSION['active_role'] ?? ($_SESSION['role'] ?? '')) ?>;

    try {
        var stored = sessionStorage.getItem('rmc_role');

        if (!stored && serverRole) {
            stored = serverRole;
            sessionStorage.setItem('rmc_role', stored);
        }

        function writeCookie() {
            if (!stored) return;
            document.cookie = 'rmc_tab_role=' + encodeURIComponent(stored) +
                ';path=/;samesite=Lax';
        }

        writeCookie();

        /* Self-heal: this tab shows another role's content → bounce once */
        if (
            serverRole && stored &&
            serverRole !== stored &&
            location.pathname.indexOf('login') === -1 &&
            location.search.indexOf('rmc_role=') === -1
        ) {
            writeCookie();
            var sep = location.search ? '&' : '?';
            location.replace(location.pathname + location.search + sep + 'rmc_role=' + encodeURIComponent(stored));
            return;
        }

        /* Tag link clicks with this tab's role */
        document.addEventListener('click', function(e) {
            var link = e.target.closest && e.target.closest('a[href]');
            if (!link) return;

            var href = link.getAttribute('href');
            if (
                !href || href.charAt(0) === '#' ||
                /^(https?:)?\/\//i.test(href) ||
                /^(mailto|tel|javascript):/i.test(href) ||
                href.indexOf('rmc_role=') !== -1 ||
                href.indexOf('logout') !== -1
            ) return;

            if (link.dataset.rmcTagged === '1') return;
            link.dataset.rmcTagged = '1';

            link.href = href + (href.indexOf('?') === -1 ? '?' : '&') +
                'rmc_role=' + encodeURIComponent(stored || serverRole || '');
        }, true);

        /* Tag form posts with this tab's role */
        document.addEventListener('submit', function(e) {
            var form = e.target;
            if (!form || !form.elements || form.elements['rmc_role']) return;
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'rmc_role';
            input.value = stored || serverRole || '';
            form.appendChild(input);
        }, true);

        /* Keep the shared cookie pointing at this tab right before leaving */
        window.addEventListener('pagehide', writeCookie);
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                var cur = sessionStorage.getItem('rmc_role');
                if (cur && cur !== stored) {
                    stored = cur;
                    if (serverRole && stored !== serverRole &&
                        location.pathname.indexOf('login') === -1 &&
                        location.search.indexOf('rmc_role=') === -1) {
                        var sep2 = location.search ? '&' : '?';
                        location.replace(location.pathname + location.search + sep2 + 'rmc_role=' + encodeURIComponent(stored));
                    }
                }
                writeCookie();
            }
        });
    } catch (err) { /* sessionStorage unavailable — legacy behavior */ }
})();
</script>

</body>

</html>
