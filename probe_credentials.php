<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Credential probing is CLI-only.');
}

$pdo = new PDO('mysql:host=localhost;port=3306;dbname=campus_event_db;charset=utf8mb4', 'root', '');
$users = $pdo->query("SELECT user_id, student_id, role, password FROM users WHERE role IN ('admin','organizer','student') ORDER BY user_id")->fetchAll(PDO::FETCH_ASSOC);
$candidates = [
    'admin123','Admin123','admin_only','Admin@123','admin@123','admin1234','Admin1234','admin_123','admin1',
    'org_123','org123','organizer123','Organizer123','org1234','Org1234',
    'Password123','Password1','Pass1234','Pass@123','password','welcome123','Welcome123',
    'RMC123','RMC2026','rmc123','rmc2026','student123','Student123','123456','12345678','qwerty',
    'Qwerty123','RMC@2026','campus2026','regis123','Regis123','Regis@123','abc123','Welcome1','2026','2024','2025','2023'
];
$matches = [];
foreach ($users as $u) {
    foreach ($candidates as $pw) {
        if (password_verify($pw, $u['password'])) {
            $matches[] = [$u['role'], $u['student_id'], $pw];
        }
    }
}
if ($matches) {
    foreach ($matches as $m) {
        echo implode(' | ', $m) . PHP_EOL;
    }
} else {
    echo "NO_MATCHES\n";
}
