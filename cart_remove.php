<?php
require_once __DIR__ . '/security.php'; include 'db.php';
require_same_origin();

if (!isset($_SESSION['user_id']) || !isset($_GET['cart_id'])) { header("Location: index.php"); exit; }

$uid = (int)$_SESSION['user_id'];
$cart_id = (int)$_GET['cart_id'];

$st = $conn->prepare("DELETE FROM cart_items WHERE id = ? AND user_id = ?");
$st->bind_param("ii", $cart_id, $uid); $st->execute();

$_SESSION['swal_success'] = "ลบสินค้าออกจากตะกร้าแล้วครับ";
header("Location: " . safe_return_url('index.php'));
exit;
