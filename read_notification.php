<?php
require_once __DIR__ . '/security.php'; include 'db.php';

if (isset($_GET['id']) && isset($_SESSION['user_id'])) {
    $notif_id = (int)$_GET['id'];
    $uid = (int)$_SESSION['user_id'];

    $st = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $st->bind_param("ii", $notif_id, $uid); $st->execute();

    $st = $conn->prepare("SELECT link FROM notifications WHERE id = ? AND user_id = ?");
    $st->bind_param("ii", $notif_id, $uid); $st->execute();
    if ($row = $st->get_result()->fetch_assoc()) {
        $link = (string)($row['link'] ?? '');
        // อนุญาตเฉพาะลิงก์ภายในเว็บ (กัน open redirect)
        if ($link !== '' && $link !== '#' && !preg_match('#^([a-z][a-z0-9+.-]*:|//|\\\\)#i', $link)) {
            header("Location: " . $link);
            exit;
        }
    }
}
header("Location: profile.php");
exit;
