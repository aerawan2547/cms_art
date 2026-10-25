<?php
require_once __DIR__ . '/security.php'; include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$uid = (int)$_SESSION['user_id'];
$sid = (int)($_POST['service_id'] ?? 0);

$st = $conn->prepare("SELECT id FROM commission_services WHERE id = ? AND artist_id = ?");
$st->bind_param("ii", $sid, $uid); $st->execute();
if ($st->get_result()->num_rows === 0) { http_response_code(403); die("Unauthorized Access"); }

$title = mb_substr(trim($_POST['title'] ?? ''), 0, 150);
$desc  = mb_substr(trim($_POST['desc'] ?? ''), 0, 3000);
$price = (float)($_POST['price'] ?? 0);
if ($title === '' || $price <= 0 || $price > 10000000) {
    $_SESSION['swal_error'] = 'กรุณากรอกหัวข้อและราคาให้ถูกต้อง';
    header("Location: view_service.php?id=$sid"); exit;
}

// รูปใหม่
$new_files = [];
if (!empty($_FILES['images']['name'][0])) {
    $total = min(count($_FILES['images']['name']), 10);
    for ($i = 0; $i < $total; $i++) {
        $f = save_image_upload($_FILES['images']['tmp_name'][$i], (int)$_FILES['images']['error'][$i], 'srv');
        if ($f) $new_files[] = $f;
    }
}

$files_to_remove = [];
$conn->begin_transaction();
try {
    $st = $conn->prepare("UPDATE commission_services SET title = ?, description = ?, base_price = ? WHERE id = ? AND artist_id = ?");
    $st->bind_param("ssdii", $title, $desc, $price, $sid, $uid); $st->execute();

    if (isset($_POST['delete_ids']) && is_array($_POST['delete_ids'])) {
        $sel = $conn->prepare("SELECT image_path FROM service_images WHERE id = ? AND service_id = ?");
        $del = $conn->prepare("DELETE FROM service_images WHERE id = ? AND service_id = ?");
        foreach ($_POST['delete_ids'] as $del_id) {
            $del_id = (int)$del_id;
            $sel->bind_param("ii", $del_id, $sid); $sel->execute();
            if ($row = $sel->get_result()->fetch_assoc()) {
                $files_to_remove[] = $row['image_path'];
                $del->bind_param("ii", $del_id, $sid); $del->execute();
            }
        }
    }
    $ins = $conn->prepare("INSERT INTO service_images (service_id, image_path) VALUES (?, ?)");
    foreach ($new_files as $f) { $ins->bind_param("is", $sid, $f); $ins->execute(); }

    $conn->commit();
    foreach ($files_to_remove as $f) delete_upload($f);
    $_SESSION['swal_success'] = 'บันทึกการแก้ไขเรียบร้อย';
} catch (Throwable $ex) {
    $conn->rollback();
    foreach ($new_files as $f) delete_upload($f);
    error_log('service_update: ' . $ex->getMessage());
    $_SESSION['swal_error'] = 'เกิดข้อผิดพลาดในการบันทึก';
}
header("Location: view_service.php?id=$sid");
exit;
