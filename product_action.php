<?php
require_once __DIR__ . '/security.php'; include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['is_artist'])) { header("Location: index.php"); exit; }

$uid   = (int)$_SESSION['user_id'];
$title = mb_substr(trim($_POST['title'] ?? ''), 0, 150);
$desc  = mb_substr(trim($_POST['desc'] ?? ''), 0, 3000);
$price = (float)($_POST['price'] ?? 0);

if ($title === '' || $price <= 0 || $price > 10000000) {
    $_SESSION['swal_error'] = 'กรุณากรอกชื่อผลงานและราคาให้ถูกต้อง';
    header("Location: profile.php"); exit;
}

$saved = [];
if (isset($_FILES['preview_images']['name']) && is_array($_FILES['preview_images']['name'])) {
    $n = min(count($_FILES['preview_images']['name']), 10);
    for ($i = 0; $i < $n; $i++) {
        $f = save_image_upload($_FILES['preview_images']['tmp_name'][$i], (int)$_FILES['preview_images']['error'][$i], 'prod');
        if ($f) $saved[] = $f;
    }
}
if (empty($saved)) {
    $_SESSION['swal_error'] = 'กรุณาเลือกรูปตัวอย่าง (jpg, png, gif, webp ขนาดไม่เกิน 8MB)';
    header("Location: profile.php"); exit;
}

$conn->begin_transaction();
try {
    $empty = '';
    $first = $saved[0];
    $st = $conn->prepare("INSERT INTO products (artist_id, title, description, price, preview_image, file_path, status) VALUES (?, ?, ?, ?, ?, ?, 'available')");
    $st->bind_param("issdss", $uid, $title, $desc, $price, $first, $empty);
    $st->execute();
    $pid = (int)$conn->insert_id;
    $ins = $conn->prepare("INSERT INTO product_images (product_id, image_path) VALUES (?, ?)");
    foreach ($saved as $f) { $ins->bind_param("is", $pid, $f); $ins->execute(); }
    $conn->commit();
    $_SESSION['swal_success'] = 'วางจำหน่ายภาพสำเร็จรูปเรียบร้อยแล้ว!';
} catch (Throwable $ex) {
    $conn->rollback();
    foreach ($saved as $f) delete_upload($f);
    error_log('product_action: ' . $ex->getMessage());
    $_SESSION['swal_error'] = 'เกิดข้อผิดพลาดในการลงขาย';
}
header("Location: profile.php");
exit;
