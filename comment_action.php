<?php
require_once __DIR__ . '/security.php'; include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$uid = (int)$_SESSION['user_id'];
$post_id = (int)($_POST['post_id'] ?? 0);
$comment_text = mb_substr(trim($_POST['comment_text'] ?? ''), 0, 1000);
$back = safe_return_url('index.php?tab=gallery#post-' . $post_id);

// โพสต์ต้องมีอยู่จริง
$st = $conn->prepare("SELECT artist_id FROM artist_posts WHERE id = ?");
$st->bind_param("i", $post_id); $st->execute();
$post = $st->get_result()->fetch_assoc();
if (!$post) { header("Location: index.php?tab=gallery"); exit; }

$image_path = null;
if (!empty($_FILES['comment_image']['name'])) {
    $image_path = save_image_upload($_FILES['comment_image']['tmp_name'], (int)$_FILES['comment_image']['error'], 'cmt');
}

if ($comment_text !== '' || $image_path !== null) {
    $st = $conn->prepare("INSERT INTO post_comments (post_id, user_id, comment_text, image_path) VALUES (?, ?, ?, ?)");
    $st->bind_param("iiss", $post_id, $uid, $comment_text, $image_path);
    if ($st->execute() && (int)$post['artist_id'] !== $uid) {
        notify($conn, (int)$post['artist_id'], $uid, 'comment', 'มีคอมเมนต์ใหม่', "index.php?tab=gallery#post-$post_id");
    }
}
header("Location: " . $back);
exit;
