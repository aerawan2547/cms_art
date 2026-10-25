<?php
require_once __DIR__ . '/security.php'; include 'db.php';
require_same_origin();

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) { header("Location: index.php"); exit; }

$uid = (int)$_SESSION['user_id'];
$service_id = (int)$_GET['id'];
$return_url = safe_return_url('index.php');

$conn->query("CREATE TABLE IF NOT EXISTS service_favorites (
    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, service_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

$st = $conn->prepare("SELECT id FROM commission_services WHERE id = ?");
$st->bind_param("i", $service_id); $st->execute();
if ($st->get_result()->num_rows === 0) { $_SESSION['swal_error'] = 'ไม่พบประกาศนี้'; header("Location: " . $return_url); exit; }

$st = $conn->prepare("SELECT id FROM service_favorites WHERE user_id = ? AND service_id = ?");
$st->bind_param("ii", $uid, $service_id); $st->execute();

if ($st->get_result()->num_rows > 0) {
    $d = $conn->prepare("DELETE FROM service_favorites WHERE user_id = ? AND service_id = ?");
    $_SESSION['swal_success'] = "ลบออกจากรายการโปรดแล้ว";
} else {
    $d = $conn->prepare("INSERT INTO service_favorites (user_id, service_id) VALUES (?, ?)");
    $_SESSION['swal_success'] = "เพิ่มลงในรายการโปรดแล้ว";
}
$d->bind_param("ii", $uid, $service_id); $d->execute();

header("Location: " . $return_url);
exit;
