<?php
require_once __DIR__ . '/security.php'; include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['delete_id']) || !isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$del_id   = (int)$_POST['delete_id'];
$uid      = (int)$_SESSION['user_id'];
$is_admin = (($_SESSION['role'] ?? '') === 'admin');

// ศิลปินเจ้าของภาพ หรือแอดมิน เท่านั้น
$st = $conn->prepare("SELECT id, preview_image, file_path FROM products WHERE id = ? AND (artist_id = ? OR ? = 1)");
$flag = $is_admin ? 1 : 0;
$st->bind_param("iii", $del_id, $uid, $flag); $st->execute();
$prod = $st->get_result()->fetch_assoc();

if ($prod) {
    // ห้ามลบถ้ามีออเดอร์ที่ยังดำเนินการอยู่ (ลูกค้าจะเสียประวัติการซื้อ)
    $st = $conn->prepare("SELECT 1 FROM product_sales WHERE product_id = ? AND status IN ('pending','paid') LIMIT 1");
    $st->bind_param("i", $del_id); $st->execute();
    if ($st->get_result()->num_rows > 0) {
        $_SESSION['swal_error'] = 'ลบไม่ได้ เพราะมีออเดอร์ที่ยังดำเนินการอยู่';
    } else {
        $imgs = $conn->prepare("SELECT image_path FROM product_images WHERE product_id = ?");
        $imgs->bind_param("i", $del_id); $imgs->execute();
        $r = $imgs->get_result();
        while ($i = $r->fetch_assoc()) delete_upload($i['image_path']);
        delete_upload($prod['preview_image']);
        delete_upload($prod['file_path']);
        $d = $conn->prepare("DELETE FROM products WHERE id = ?");
        $d->bind_param("i", $del_id); $d->execute();
        $_SESSION['swal_success'] = 'ลบภาพสำเร็จรูปเรียบร้อยแล้ว!';
    }
}
header($is_admin ? "Location: admin.php?p=products" : "Location: profile.php");
exit;
