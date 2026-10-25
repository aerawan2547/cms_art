<?php
require_once __DIR__ . '/security.php'; include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['is_artist'])) { header("Location: index.php"); exit; }

$uid = (int)$_SESSION['user_id'];
$caption = mb_substr(trim($_POST['caption'] ?? ''), 0, 2000);

// รวบรวมรูปที่ผ่านการตรวจสอบก่อน แล้วค่อยสร้างโพสต์ (กันโพสต์ว่างเปล่า)
$saved = [];
if (isset($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
    $n = min(count($_FILES['images']['name']), 10);   // สูงสุด 10 รูปต่อโพสต์
    for ($i = 0; $i < $n; $i++) {
        $name = save_image_upload($_FILES['images']['tmp_name'][$i], (int)$_FILES['images']['error'][$i], 'post');
        if ($name) $saved[] = $name;
    }
}
if (empty($saved)) {
    $_SESSION['swal_error'] = 'กรุณาเลือกรูปภาพ (jpg, png, gif, webp ขนาดไม่เกิน 8MB)';
    header("Location: profile.php?tab=gallery"); exit;
}

$conn->begin_transaction();
try {
    $st = $conn->prepare("INSERT INTO artist_posts (artist_id, caption) VALUES (?, ?)");
    $st->bind_param("is", $uid, $caption); $st->execute();
    $post_id = (int)$conn->insert_id;
    $ins = $conn->prepare("INSERT INTO post_images (post_id, image_path) VALUES (?, ?)");
    foreach ($saved as $f) { $ins->bind_param("is", $post_id, $f); $ins->execute(); }
    $conn->commit();
    $_SESSION['swal_success'] = 'โพสต์ผลงานเรียบร้อยแล้ว';
} catch (Throwable $ex) {
    $conn->rollback();
    foreach ($saved as $f) delete_upload($f);
    error_log('post_action: ' . $ex->getMessage());
    $_SESSION['swal_error'] = 'เกิดข้อผิดพลาดในการโพสต์';
}
header("Location: index.php?tab=gallery");
exit;
