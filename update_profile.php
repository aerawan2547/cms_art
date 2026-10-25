<?php
require_once __DIR__ . '/security.php'; include 'db.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: profile.php"); exit; }

$uid = (int)$_SESSION['user_id'];
$display_name = mb_substr(trim($_POST['display_name'] ?? ''), 0, 100);
$bio = mb_substr(trim($_POST['bio'] ?? ''), 0, 1000);
if ($display_name === '') { $_SESSION['swal_error'] = 'กรุณากรอกชื่อที่แสดง'; header("Location: profile.php"); exit; }

// 1. ข้อมูลทั่วไป
$st = $conn->prepare("UPDATE users SET full_name = ? WHERE id = ?");
$st->bind_param("si", $display_name, $uid); $st->execute();

$st = $conn->prepare("SELECT user_id FROM artist_profiles WHERE user_id = ?");
$st->bind_param("i", $uid); $st->execute();
if ($st->get_result()->num_rows > 0) {
    $st = $conn->prepare("UPDATE artist_profiles SET display_name = ?, bio = ? WHERE user_id = ?");
    $st->bind_param("ssi", $display_name, $bio, $uid); $st->execute();
}
$_SESSION['display_name'] = $display_name;

$old = $conn->prepare("SELECT profile_image, cover_image FROM users WHERE id = ?");
$old->bind_param("i", $uid); $old->execute();
$old = $old->get_result()->fetch_assoc() ?: ['profile_image' => null, 'cover_image' => null];

// 2. รูปโปรไฟล์ (ได้จากตัวตัดรูปในเบราว์เซอร์ เป็น data URL)
if (!empty($_POST['cropped_image'])) {
    $parts = explode(';base64,', (string)$_POST['cropped_image'], 2);
    if (count($parts) === 2 && preg_match('#^data:image/(jpeg|png|webp)$#', $parts[0])) {
        $bin = base64_decode($parts[1], true);
        if ($bin !== false && strlen($bin) <= 5 * 1024 * 1024 && @getimagesizefromstring($bin) !== false) {
            $new_name = 'profile_' . $uid . '_' . bin2hex(random_bytes(6)) . '.jpg';
            $path = __DIR__ . '/uploads/' . $new_name;
            $ok = false;
            if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($bin))) {
                $ok = imagejpeg($im, $path, 90);        // แปลงใหม่ทั้งภาพ ตัดข้อมูลแปลกปลอมออก
                imagedestroy($im);
            }
            if ($ok) {
                $st = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                $st->bind_param("si", $new_name, $uid); $st->execute();
                delete_upload($old['profile_image']);
            }
        }
    }
}

// 3. รูปปก
if (!empty($_FILES['cover_image']['name'])) {
    $cover = save_image_upload($_FILES['cover_image']['tmp_name'], (int)$_FILES['cover_image']['error'], 'cover_' . $uid);
    if ($cover) {
        $st = $conn->prepare("UPDATE users SET cover_image = ? WHERE id = ?");
        $st->bind_param("si", $cover, $uid); $st->execute();
        delete_upload($old['cover_image']);
    } else {
        $_SESSION['swal_error'] = 'รูปปกไม่ถูกต้อง (jpg, png, gif, webp ไม่เกิน 8MB)';
        header("Location: profile.php"); exit;
    }
}

$_SESSION['swal_success'] = 'บันทึกข้อมูลโปรไฟล์เรียบร้อย';
header("Location: profile.php");
exit;
