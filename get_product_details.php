<?php
require_once __DIR__ . '/security.php'; include 'db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) { http_response_code(401); echo json_encode(['error' => 'Unauthorized or missing ID']); exit; }

$uid = (int)$_SESSION['user_id'];
$pid = (int)$_GET['id'];

$st = $conn->prepare("SELECT * FROM products WHERE id = ? AND artist_id = ? LIMIT 1");
$st->bind_param("ii", $pid, $uid); $st->execute();
$prod = $st->get_result()->fetch_assoc();
if (!$prod) { http_response_code(404); echo json_encode(['error' => 'Product not found or access denied']); exit; }

$st = $conn->prepare("SELECT id, image_path FROM product_images WHERE product_id = ?");
$st->bind_param("i", $pid); $st->execute();
$imgs = [];
$r = $st->get_result();
while ($img = $r->fetch_assoc()) { $imgs[] = ['id' => $img['id'], 'path' => $img['image_path']]; }
$prod['images'] = $imgs;
unset($prod['file_path']);   // ไม่ส่งพาธไฟล์ต้นฉบับออกไป

echo json_encode($prod, JSON_UNESCAPED_UNICODE);
