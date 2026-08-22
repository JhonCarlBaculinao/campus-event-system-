<?php
session_start();
require_once 'db_connect.php';
header('Content-Type: application/json');

if (
    !isset($_SESSION['user_id']) ||
    !isset($_POST['nid'])
) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$nid = (int) $_POST['nid'];
$uid = (int) $_SESSION['user_id'];

if ($nid <= 0 || $uid <= 0) {
    echo json_encode(['ok' => false]);
    exit;
}

pg_query_params(
    $conn,
    "UPDATE notifications SET is_read = true
     WHERE notification_id = $1 AND user_id = $2",
    [$nid, $uid]
);

$res = pg_query_params(
    $conn,
    "SELECT COUNT(*) AS cnt FROM notifications
     WHERE user_id = $1 AND is_read = false",
    [$uid]
);

$unread = 0;
if ($res) {
    $row = pg_fetch_assoc($res);
    $unread = (int) $row['cnt'];
}

echo json_encode(['ok' => true, 'unread' => $unread]);
