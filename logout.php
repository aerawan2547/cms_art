<?php
require_once __DIR__ . '/security.php'; include 'db.php';
require_same_origin();
if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $st = $conn->prepare("INSERT INTO login_logs (user_id, action) VALUES (?, 'logout')");
    $st->bind_param("i", $uid); $st->execute();
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header("Location: index.php"); exit;
