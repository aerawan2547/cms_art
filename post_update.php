<?php
require_once __DIR__ . '/security.php'; include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['user_id']) || empty($_SESSION['is_artist'])) { header("Location: index.php"); exit; }

$uid = (int)$_SESSION['user_id'];
$post_id = (int)($_POST['post_id'] ?? 0);
$caption = mb_substr(trim($_POST['caption'] ?? ''), 0, 2000);

$st = $conn->prepare("SELECT id FROM artist_posts WHERE id = ? AND artist_id = ?");
$st->bind_param("ii", $post_id, $uid); $st->execute();
if ($st->get_result()->num_rows === 0) {
    $_SESSION['swal_error'] = 'คุณไม่มีสิทธิ์แก้ไขโพสต์นี้';
    header("Location: profile.php?tab=gallery"); exit;
}

// รูปใหม่ที่ผ่านการตรวจสอบ
$new_files = [];
if (isset($_FILES['new_images']['name']) && is_array($_FILES['new_images']['name'])) {
    $n = min(count($_FILES['new_images']['name']), 10);
    for ($i = 0; $i < $n; $i++) {
        if (($_FILES['new_images']['name'][$i] ?? '') === '') continue;
        $f = save_image_upload($_FILES['new_images']['tmp_name'][$i], (int)$_FILES['new_images']['error'][$i], 'post');
        if ($f) $new_files[] = $f;
    }
}
// รูปเดิมที่ผู้ใช้กดลบ (เฉพาะรูปของโพสต์นี้)
$del_ids = array_values(array_unique(array_map('intval', (array)($_POST['delete_images'] ?? []))));

$st = $conn->prepare("SELECT id, image_path FROM post_images WHERE post_id = ?");
$st->bind_param("i", $post_id); $st->execute();
$existing = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$to_delete = array_values(array_filter($existing, fn($r) => in_array((int)$r['id'], $del_ids, true)));

// ต้องเหลือรูปอย่างน้อย 1 รูปเสมอ
if (count($existing) - count($to_delete) + count($new_files) < 1) {
    foreach ($new_files as $f) delete_upload($f);
    $_SESSION['swal_error'] = 'โพสต์ต้องมีรูปอย่างน้อย 1 รูป';
    header("Location: profile.php?tab=gallery"); exit;
}

$conn->begin_transaction();
try {
    $st = $conn->prepare("UPDATE artist_posts SET caption = ? WHERE id = ? AND artist_id = ?");
    $st->bind_param("sii", $caption, $post_id, $uid); $st->execute();

    $del = $conn->prepare("DELETE FROM post_images WHERE id = ? AND post_id = ?");
    foreach ($to_delete as $r) { $rid = (int)$r['id']; $del->bind_param("ii", $rid, $post_id); $del->execute(); }

    $ins = $conn->prepare("INSERT INTO post_images (post_id, image_path) VALUES (?, ?)");
    foreach ($new_files as $f) { $ins->bind_param("is", $post_id, $f); $ins->execute(); }

    $conn->commit();
    foreach ($to_delete as $r) delete_upload($r['image_path']);   // ลบไฟล์จริงหลัง commit สำเร็จ
    $_SESSION['swal_success'] = 'อัปเดตโพสต์เรียบร้อยแล้ว';
} catch (Throwable $ex) {
    $conn->rollback();
    foreach ($new_files as $f) delete_upload($f);
    error_log('post_update: ' . $ex->getMessage());
    $_SESSION['swal_error'] = 'เกิดข้อผิดพลาดในการอัปเดตโพสต์';
}
header("Location: profile.php?tab=gallery");
exit;
