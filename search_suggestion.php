<?php
require_once __DIR__ . '/security.php'; include 'db.php';
header('Content-Type: application/json; charset=utf-8');

$keyword = trim($_POST['keyword'] ?? '');
if ($keyword === '' || mb_strlen($keyword) > 50) { echo '[]'; exit; }

// escape ตัวอักษรพิเศษของ LIKE แล้วใช้ prepared statement
$like = '%' . addcslashes($keyword, '%_\\') . '%';
$suggestions = [];

$st = $conn->prepare("SELECT title FROM commission_services WHERE status = 'active' AND title LIKE ? LIMIT 5");
$st->bind_param("s", $like); $st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) $suggestions[] = ['label' => $row['title'], 'type' => 'service'];

$st = $conn->prepare("SELECT display_name FROM artist_profiles WHERE display_name LIKE ? LIMIT 5");
$st->bind_param("s", $like); $st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) $suggestions[] = ['label' => $row['display_name'], 'type' => 'artist'];

// เฉพาะชื่อผู้ใช้ของศิลปิน (โปรไฟล์สาธารณะ) — ไม่เปิดเผย username ของสมาชิกทั่วไป/แอดมิน
$st = $conn->prepare("SELECT u.username FROM users u JOIN artist_profiles a ON a.user_id = u.id WHERE u.username LIKE ? LIMIT 5");
$st->bind_param("s", $like); $st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) $suggestions[] = ['label' => $row['username'], 'type' => 'user'];

echo json_encode($suggestions, JSON_UNESCAPED_UNICODE);
