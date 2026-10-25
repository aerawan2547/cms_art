<?php
require_once __DIR__ . '/security.php'; include 'db.php';
require_same_origin();

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$pid = (int)($_GET['id'] ?? 0);
$uid = (int)$_SESSION['user_id'];

$st = $conn->prepare("SELECT id FROM artist_posts WHERE id = ?");
$st->bind_param("i", $pid); $st->execute();
if ($st->get_result()->num_rows === 0) { header("Location: index.php?tab=gallery"); exit; }

$st = $conn->prepare("SELECT id FROM post_likes WHERE post_id = ? AND user_id = ?");
$st->bind_param("ii", $pid, $uid); $st->execute();

if ($st->get_result()->num_rows > 0) {          // เคยไลค์แล้ว -> เลิกไลค์
    $d = $conn->prepare("DELETE FROM post_likes WHERE post_id = ? AND user_id = ?");
} else {                                        // ยังไม่เคย -> ไลค์
    $d = $conn->prepare("INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)");
}
$d->bind_param("ii", $pid, $uid); $d->execute();

header("Location: index.php?tab=gallery#post-$pid");
exit;
