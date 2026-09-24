<?php
/*
|--------------------------------------------------------------------------
| login.php was an older, disconnected duplicate of the combined
| sign-in / sign-up page. It predates organizer sign-up support, so
| anyone who reached it (e.g. via landing.php's "Login" links) never
| saw the option to register as an organizer.
|
| account.php is the current, correct version. This file is now a
| redirect stub — same pattern as register.php — so any existing
| links to login.php across the system (bookmarks, other pages,
| the ?2fa=locked flow, etc.) keep working and always land on the
| up-to-date page.
|--------------------------------------------------------------------------
*/

$qs = $_GET;

// account.php defaults to sign-in mode already; nothing else to map.
$target = 'account.php';

if (!empty($qs)) {
    $target .= '?' . http_build_query($qs);
}

header("Location: " . $target);
exit();