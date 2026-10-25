<?php
require_once __DIR__ . '/security.php'; include 'db.php';
require_same_origin();

if (!isset($_GET['id']) || empty($_SESSION['user_id']) || empty($_SESSION['is_artist'])) { header("Location: index.php"); exit; }

$uid = (int)$_SESSION['user_id'];
$post_id = (int)$_GET['id'];

$st = $conn->prepare("SELECT id FROM artist_posts WHERE id = ? AND artist_id = ?");
$st->bind_param("ii", $post_id, $uid); $st->execute();

if ($st->get_result()->num_rows > 0) {
    $st = $conn->prepare("SELECT image_path FROM post_images WHERE post_id = ?");
    $st->bind_param("i", $post_id); $st->execute();
    $r = $st->get_result();
    while ($img = $r->fetch_assoc()) delete_upload($img['image_path']);

    // post_images / post_comments / post_likes ถูกลบตามอัตโนมัติด้วย ON DELETE CASCADE
    $st = $conn->prepare("DELETE FROM artist_posts WHERE id = ? AND artist_id = ?");
    $st->bind_param("ii", $post_id, $uid); $st->execute();
    $_SESSION['swal_success'] = 'ลบโพสต์เรียบร้อยแล้ว';
} else {
    $_SESSION['swal_error'] = 'ไม่สามารถลบได้ คุณไม่ใช่เจ้าของโพสต์';
}
header("Location: profile.php?tab=gallery");
exit;
