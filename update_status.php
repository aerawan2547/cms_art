<?php
require_once __DIR__ . '/security.php'; include 'db.php';
require_same_origin();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$oid    = (int)($_GET['id'] ?? 0);
$status = $_GET['s'] ?? '';
$uid    = (int)$_SESSION['user_id'];

if (in_array($status, ['working', 'completed'], true)) {     // อนุญาตเฉพาะสถานะที่กำหนด
    $st = $conn->prepare("UPDATE commission_orders SET status = ? WHERE id = ? AND artist_id = ?");   // และต้องเป็นศิลปินเจ้าของงาน
    $st->bind_param("sii", $status, $oid, $uid); $st->execute();
}
header("Location: profile.php");
exit;
