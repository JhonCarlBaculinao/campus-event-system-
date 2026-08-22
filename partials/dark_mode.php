<?php
/*
|--------------------------------------------------------------------------
| RMC Events — Appearance (account-synced dark mode)
|--------------------------------------------------------------------------
| Resolves the user's appearance preference:
|   1. Logged-in session   -> users.appearance (light / dark / system)
|   2. appearance cookie   -> cache persisted on this browser
|   3. localStorage        -> optional browser-only cache/fallback
|   4. system              -> matchMedia (prefers-color-scheme)
| Outputs the CSS + an early, render-blocking script (idempotent, safe to
| include more than once).
|--------------------------------------------------------------------------
*/

$rmc_appearance = 'system';

if (
    isset($_SESSION['appearance']) &&
    in_array($_SESSION['appearance'], ['light', 'dark', 'system'], true)
) {
    $rmc_appearance = $_SESSION['appearance'];

} elseif (
    isset($_COOKIE['appearance']) &&
    in_array($_COOKIE['appearance'], ['light', 'dark', 'system'], true)
) {
    $rmc_appearance = $_COOKIE['appearance'];
}

$rmc_appearance_js =
    htmlspecialchars(
        $rmc_appearance,
        ENT_QUOTES,
        'UTF-8'
    );
?>

<style>

html.dark-mode {
    filter: invert(1) hue-rotate(180deg);
}

html.dark-mode img,
html.dark-mode video,
html.dark-mode canvas {
    filter: invert(1) hue-rotate(180deg);
}

</style>

<script>
(function () {

    if (window.__RMC_APPEARANCE_LOADED) {
        return;
    }

    window.__RMC_APPEARANCE_LOADED = true;

    var serverPref = '<?= $rmc_appearance_js ?>';

    var pref = serverPref || 'system';

    function systemIsDark() {
        return (
            window.matchMedia &&
            window.matchMedia('(prefers-color-scheme: dark)').matches
        );
    }

    function resolveMode(mode) {
        if (mode === 'dark') return 'dark';
        if (mode === 'light') return 'light';
        return systemIsDark() ? 'dark' : 'light';
    }

    function apply(mode) {
        document.documentElement.classList.toggle(
            'dark-mode',
            resolveMode(mode) === 'dark'
        );
    }

    /* Persist an explicit preference to this device (best-effort) so it is
       remembered before login and survives cookie expiry. */
    function persist(mode) {
        try {
            localStorage.setItem('appearance', mode);
        } catch (e) { }
    }

    function init() {

        /* Resolution priority:
             1. server pref (logged-in account / appearance cookie)
             2. device localStorage (pre-login memory)
             3. system preference
        */
        if (pref === 'system') {
            try {
                var cached = localStorage.getItem('appearance');
                if (
                    cached === 'dark' ||
                    cached === 'light' ||
                    cached === 'system'
                ) {
                    pref = cached;
                }
            } catch (e) { }
        }

        apply(pref);

        /* Mirror the resolved preference back to the device so future
           pre-login visits apply the same appearance without a flash. */
        persist(serverPref === 'system' ? pref : serverPref);

        /* Track OS-level changes; they only matter when pref === 'system'. */
        if (window.matchMedia) {
            var mq = window.matchMedia('(prefers-color-scheme: dark)');
            var onSystemChange = function () {
                apply(pref);
            };
            if (mq.addEventListener) {
                mq.addEventListener('change', onSystemChange);
            } else if (mq.addListener) {
                mq.addListener(onSystemChange);
            }
        }
    }

    window.RMCApplyAppearance = function (mode) {
        pref = mode;
        apply(mode);
        persist(mode);
    };

    init();

})();
</script>
