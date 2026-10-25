<?php
require_once __DIR__ . '/security.php'; include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['is_artist'])) { header("Location: index.php"); exit; }

$uid   = (int)$_SESSION['user_id'];
$pid   = (int)($_POST['product_id'] ?? 0);
$title = mb_substr(trim($_POST['title'] ?? ''), 0, 150);
$desc  = mb_substr(trim($_POST['desc'] ?? ''), 0, 3000);
$price = (float)($_POST['price'] ?? 0);

$st = $conn->prepare("SELECT id, preview_image FROM products WHERE id = ? AND artist_id = ? LIMIT 1");
$st->bind_param("ii", $pid, $uid); $st->execute();
$current = $st->get_result()->fetch_assoc();
if (!$current) { $_SESSION['swal_error'] = 'สิทธิ์ไม่ถูกต้อง'; header("Location: profile.php"); exit; }
if ($title === '' || $price <= 0 || $price > 10000000) { $_SESSION['swal_error'] = 'กรุณากรอกชื่อและราคาให้ถูกต้อง'; header("Location: profile.php"); exit; }

$new_files = [];
if (isset($_FILES['new_preview_images']['name']) && is_array($_FILES['new_preview_images']['name'])) {
    $n = min(count($_FILES['new_preview_images']['name']), 10);
    for ($i = 0; $i < $n; $i++) {
        if (($_FILES['new_preview_images']['name'][$i] ?? '') === '') continue;
        $f = save_image_upload($_FILES['new_preview_images']['tmp_name'][$i], (int)$_FILES['new_preview_images']['error'][$i], 'prod');
        if ($f) $new_files[] = $f;
    }
}

$files_to_remove = [];
$conn->begin_transaction();
try {
    $st = $conn->prepare("UPDATE products SET title = ?, description = ?, price = ? WHERE id = ? AND artist_id = ?");
    $st->bind_param("ssdii", $title, $desc, $price, $pid, $uid); $st->execute();

    // ลบรูปที่ติ๊กเลือก (เฉพาะรูปของสินค้านี้)
    if (!empty($_POST['delete_previews']) && is_array($_POST['delete_previews'])) {
        $sel = $conn->prepare("SELECT image_path FROM product_images WHERE id = ? AND product_id = ?");
        $del = $conn->prepare("DELETE FROM product_images WHERE id = ? AND product_id = ?");
        foreach ($_POST['delete_previews'] as $img_id) {
            $img_id = (int)$img_id;
            $sel->bind_param("ii", $img_id, $pid); $sel->execute();
            if ($f = $sel->get_result()->fetch_assoc()) {
                $files_to_remove[] = $f['image_path'];
                $del->bind_param("ii", $img_id, $pid); $del->execute();
            }
        }
    }

    $ins = $conn->prepare("INSERT INTO product_images (product_id, image_path) VALUES (?, ?)");
    foreach ($new_files as $f) { $ins->bind_param("is", $pid, $f); $ins->execute(); }

    // อัปเดตรูปปกให้เป็นรูปแรกที่เหลืออยู่
    $st = $conn->prepare("SELECT image_path FROM product_images WHERE product_id = ? ORDER BY id ASC LIMIT 1");
    $st->bind_param("i", $pid); $st->execute();
    $first = $st->get_result()->fetch_assoc();
    $cover = $first ? $first['image_path'] : '';
    $st = $conn->prepare("UPDATE products SET preview_image = ? WHERE id = ?");
    $st->bind_param("si", $cover, $pid); $st->execute();

    $conn->commit();
    foreach ($files_to_remove as $f) { if ($f !== $cover) delete_upload($f); }
    $_SESSION['swal_success'] = 'แก้ไขข้อมูลเรียบร้อยแล้ว!';
} catch (Throwable $ex) {
    $conn->rollback();
    foreach ($new_files as $f) delete_upload($f);
    error_log('product_edit_action: ' . $ex->getMessage());
    $_SESSION['swal_error'] = 'เกิดข้อผิดพลาดในการแก้ไข';
}
header("Location: profile.php");
exit;
