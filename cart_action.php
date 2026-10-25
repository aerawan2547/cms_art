<?php
require_once __DIR__ . '/security.php'; include 'db.php';
require_same_origin();

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) { header("Location: index.php"); exit; }

$uid = (int)$_SESSION['user_id'];
$item_id = (int)$_GET['id'];
$type = (($_GET['type'] ?? 'product') === 'service') ? 'service' : 'product';
$return_url = safe_return_url('index.php');

// เตรียมตาราง (ทำครั้งเดียวต่อ session)
if (empty($_SESSION['cart_schema_ok'])) {
    $conn->query("CREATE TABLE IF NOT EXISTS cart_items (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, product_id INT NULL, service_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $col = $conn->query("SHOW COLUMNS FROM cart_items LIKE 'service_id'");
    if ($col && $col->num_rows == 0) {
        $conn->query("ALTER TABLE cart_items ADD COLUMN service_id INT NULL AFTER product_id");
        $conn->query("ALTER TABLE cart_items MODIFY COLUMN product_id INT NULL");
    }
    $_SESSION['cart_schema_ok'] = true;
}

function back_with($key, $text, $url) { $_SESSION[$key] = $text; header("Location: " . $url); exit; }

if ($type === 'product') {
    $st = $conn->prepare("SELECT artist_id, status FROM products WHERE id = ?");
    $st->bind_param("i", $item_id); $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) back_with('swal_error', 'ไม่พบสินค้านี้', $return_url);
    if ((int)$row['artist_id'] === $uid) back_with('swal_error', 'ไม่สามารถเพิ่มผลงานของตนเองลงตะกร้าได้', $return_url);
    if ($row['status'] !== 'available') back_with('swal_error', 'สินค้านี้ไม่พร้อมจำหน่าย', $return_url);

    $st = $conn->prepare("SELECT id FROM cart_items WHERE user_id = ? AND product_id = ?");
    $st->bind_param("ii", $uid, $item_id); $st->execute();
    if ($st->get_result()->num_rows > 0) back_with('swal_error', 'สินค้านี้อยู่ในตะกร้าแล้ว', $return_url);

    $st = $conn->prepare("INSERT INTO cart_items (user_id, product_id) VALUES (?, ?)");
    $st->bind_param("ii", $uid, $item_id); $st->execute();
    back_with('swal_success', 'เพิ่มลงตะกร้าเรียบร้อย', $return_url);
} else {
    $st = $conn->prepare("SELECT artist_id, status FROM commission_services WHERE id = ?");
    $st->bind_param("i", $item_id); $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) back_with('swal_error', 'ไม่พบประกาศรับงานนี้', $return_url);
    if ((int)$row['artist_id'] === $uid) back_with('swal_error', 'ไม่สามารถจ้างงานตนเองได้', $return_url);
    if ($row['status'] !== 'active') back_with('swal_error', 'ประกาศรับงานนี้ปิดรับชั่วคราว', $return_url);

    $st = $conn->prepare("SELECT id FROM cart_items WHERE user_id = ? AND service_id = ?");
    $st->bind_param("ii", $uid, $item_id); $st->execute();
    if ($st->get_result()->num_rows > 0) back_with('swal_error', 'ประกาศนี้อยู่ในตะกร้าแล้ว', $return_url);

    $st = $conn->prepare("INSERT INTO cart_items (user_id, service_id) VALUES (?, ?)");
    $st->bind_param("ii", $uid, $item_id); $st->execute();
    back_with('swal_success', 'เพิ่มลงตะกร้าเรียบร้อย', $return_url);
}
