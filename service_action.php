<?php
require_once __DIR__ . '/security.php'; include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['is_artist'])) { header("Location: index.php"); exit; }

$uid   = (int)$_SESSION['user_id'];
$title = mb_substr(trim($_POST['title'] ?? ''), 0, 150);
$desc  = mb_substr(trim($_POST['desc'] ?? ''), 0, 3000);
$price = (float)($_POST['price'] ?? 0);

if ($title === '' || $price <= 0 || $price > 10000000) {
    $_SESSION['swal_error'] = 'กรุณากรอกหัวข้อและราคาให้ถูกต้อง';
    header("Location: profile.php?tab=services"); exit;
}
$img = null;
if (!empty($_FILES['image']['name'])) {
    $img = save_image_upload($_FILES['image']['tmp_name'], (int)$_FILES['image']['error'], 'srv');
    if (!$img) { $_SESSION['swal_error'] = 'ไฟล์รูปไม่ถูกต้อง (jpg, png, gif, webp ไม่เกิน 8MB)'; header("Location: profile.php?tab=services"); exit; }
}

$conn->begin_transaction();
try {
    $st = $conn->prepare("INSERT INTO commission_services (artist_id, title, description, base_price) VALUES (?, ?, ?, ?)");
    $st->bind_param("issd", $uid, $title, $desc, $price); $st->execute();
    $sid = (int)$conn->insert_id;
    if ($img) {
        $st = $conn->prepare("INSERT INTO service_images (service_id, image_path) VALUES (?, ?)");
        $st->bind_param("is", $sid, $img); $st->execute();
    }
    $conn->commit();
    $_SESSION['swal_success'] = 'ลงประกาศรับงานเรียบร้อยแล้ว';
} catch (Throwable $ex) {
    $conn->rollback();
    delete_upload($img);
    error_log('service_action: ' . $ex->getMessage());
    $_SESSION['swal_error'] = 'เกิดข้อผิดพลาดในการลงประกาศ';
}
header("Location: profile.php?tab=services");
exit;
