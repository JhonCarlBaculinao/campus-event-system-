<?php
/*
|--------------------------------------------------------------------------
| RMC Events — Shared <head> + opening <body>
|--------------------------------------------------------------------------
| Expected variables (all optional):
|   $page_title   (string)  Page title shown in the browser tab.
|
| Loads the design system CSS, Tailwind CSS (CDN), Font Awesome, and
| the shared JavaScript. This partial opens the <body> tag;
| every page must include partials/footer.php at the end to close it.
|
| NOTE: BASE_URL should already be defined by this point (e.g. in
| db_connect.php, which every page loads before head.php). If it
| isn't defined yet for some reason, we fall back to a safe default
| here so nothing breaks.
|--------------------------------------------------------------------------
*/

$page_title = $page_title ?? 'RMC Events';

if (!defined('BASE_URL')) {
    $script_path = parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH) ?: '';
    $base_path = rtrim(str_replace('\\', '/', dirname($script_path)), '/');
    define('BASE_URL', ($base_path === '/' || $base_path === '.') ? '' : $base_path);
}
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

<!-- Design System CSS (CSS Variables + Components) -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/variables.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/base.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/layout.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/components.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/utilities.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/responsive.css">

<!-- Tailwind CSS CDN (for utility classes not in design system) -->
<script src="https://cdn.tailwindcss.com"></script>

<script>
if (window.tailwind) {
    window.tailwind.config = {
        theme: {
            extend: {
                colors: {
                    rmc: {
                        50:  '#EFF6FF',
                        100: '#DBEAFE',
                        200: '#BFDBFE',
                        300: '#93C5FD',
                        400: '#60A5FA',
                        500: '#3B82F6',
                        600: '#2563EB',
                        700: '#1D4ED8',
                        800: '#1E3A5F',
                        900: '#172A4A',
                        950: '#0B1F3A'
                    }
                }
            }
        }
    };
}
</script>

<!-- Font Awesome -->
<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
>

<!-- Main Application JavaScript -->
<script src="<?= BASE_URL ?>/assets/js/app.js" defer></script>

</head>

<?php
$public_ui_pages = ['landing.php', 'account.php', 'login.php', 'login_2fa.php', 'register.php', 'forgot_password.php', 'reset_password.php', 'verify_email.php', 'index.php'];
$current_ui_page = basename(parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH) ?: '');
$internal_ui_class = in_array($current_ui_page, $public_ui_pages, true) ? '' : ' app-page';
?>
<body class="bg-primary text-primary page-animation<?= $internal_ui_class; ?>" data-rmc-role="<?= htmlspecialchars($_SESSION['active_role'] ?? ($_SESSION['role'] ?? '')); ?>">