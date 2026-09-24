<?php
require_once 'db_connect.php';
require_once 'csrf.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id']) || !isset($_POST['nid'])) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

csrf_verify();

$nid = (int) $_POST['nid'];
$uid = (int) $_SESSION['user_id'];

if ($nid <= 0 || $uid <= 0) {
    echo json_encode(['ok' => false]);
    exit;
}

$pdo->prepare("UPDATE notifications SET is_read = true
     WHERE notification_id = ? AND user_id = ?")->execute([$nid, $uid]);

$res = $pdo->prepare("SELECT COUNT(*) AS cnt FROM notifications
     WHERE user_id = ? AND is_read = false"); $res->execute([$uid]);

$unread = 0;
if ($res) {
    $row = $res->fetch(PDO::FETCH_ASSOC);
    $unread = (int) $row['cnt'];
}

echo json_encode(['ok' => true, 'unread' => $unread]);
