<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function csrf_token(): string
{
    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token'])
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') .
        '">';
}

function csrf_verify(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $session_token   = $_SESSION['csrf_token'] ?? '';
    $submitted_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($session_token) ||
        !is_string($submitted_token) ||
        $session_token === '' ||
        $submitted_token === '' ||
        !hash_equals($session_token, $submitted_token)
    ) {
        http_response_code(403);
        die("Invalid security token. Please refresh the page and try again.");
    }
}