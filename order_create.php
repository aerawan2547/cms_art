<?php
require_once __DIR__ . '/security.php'; include 'db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: index.php"); exit; }
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$buyer   = (int)$_SESSION['user_id'];
$service = (int)($_POST['service_id'] ?? 0);

// ราคาและศิลปินต้องมาจากฐานข้อมูลเท่านั้น (ห้ามเชื่อค่าจากฟอร์ม)
$st = $conn->prepare("SELECT artist_id, base_price, status FROM commission_services WHERE id = ?");
$st->bind_param("i", $service); $st->execute();
$srv = $st->get_result()->fetch_assoc();

if (!$srv || $srv['status'] !== 'active') {
    $_SESSION['swal_error'] = 'ประกาศรับงานนี้ไม่พร้อมให้จ้างในขณะนี้';
    header("Location: index.php"); exit;
}
$artist = (int)$srv['artist_id'];
$price  = (float)$srv['base_price'];
if ($artist === $buyer) {
    $_SESSION['swal_error'] = 'ไม่สามารถจ้างงานตนเองได้';
    header("Location: view_service.php?id=$service"); exit;
}

$conn->begin_transaction();
try {
    $st = $conn->prepare("INSERT INTO commission_orders (service_id, buyer_id, artist_id, agreed_price, status) VALUES (?, ?, ?, ?, 'pending')");
    $st->bind_param("iiid", $service, $buyer, $artist, $price);
    $st->execute();
    $oid = (int)$conn->insert_id;

    $st = $conn->prepare("INSERT INTO conversations (related_order_id, type) VALUES (?, 'order')");
    $st->bind_param("i", $oid); $st->execute();
    $cid = (int)$conn->insert_id;

    $st = $conn->prepare("INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?, ?), (?, ?)");
    $st->bind_param("iiii", $cid, $buyer, $cid, $artist); $st->execute();

    notify($conn, $artist, $buyer, 'new_order', "ออเดอร์ใหม่! มีคนจ้างงานคุณครับ (Order #$oid)", "chat.php?order_id=$oid");
    $conn->commit();
} catch (Throwable $ex) {
    $conn->rollback();
    error_log('order_create: ' . $ex->getMessage());
    $_SESSION['swal_error'] = 'เกิดข้อผิดพลาดในการสร้างออเดอร์ กรุณาลองใหม่';
    header("Location: view_service.php?id=$service"); exit;
}
header("Location: chat.php?order_id=$oid");
exit;
