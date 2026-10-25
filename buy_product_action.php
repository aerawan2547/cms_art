<?php
require_once __DIR__ . '/security.php'; include 'db.php'; require_once __DIR__ . '/commerce.php';
require_same_origin();   // การสั่งซื้อผ่านลิงก์ต้องมาจากเว็บเราเท่านั้น

if (!isset($_SESSION['user_id'])) {
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนซื้อสินค้าครับ'); window.location='index.php';</script>"; exit;
}
$pid = (int)($_GET['id'] ?? 0);
if ($pid <= 0) { header("Location: index.php"); exit; }

[$ok, $msg, $sale_id] = create_product_sale($conn, $pid, (int)$_SESSION['user_id']);
if (!$ok) {
    $_SESSION['swal_error'] = $msg;
    header("Location: index.php?tab=products"); exit;
}
header("Location: product_chat.php?sale_id=$sale_id");
exit;
