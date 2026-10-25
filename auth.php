<?php
require_once __DIR__ . '/security.php'; include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: index.php"); exit; }

function alert_and_back(string $msg): void {
    echo "<script>alert(" . js_str($msg) . "); window.location='index.php';</script>";
    exit;
}

// ตารางบันทึกการล็อกอินผิด (กันเดาสุ่มรหัสผ่าน)
$conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, username VARCHAR(100) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (ip, attempted_at))");
$ip = substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);

// ================= สมัครสมาชิก =================
if (isset($_POST['register'])) {
    $full_name = mb_substr(trim($_POST['full_name'] ?? ''), 0, 100);
    $user      = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $pass      = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';
    $role_type = (($_POST['role_type'] ?? 'member') === 'artist') ? 'artist' : 'member';

    if ($full_name === '' || !preg_match('/^[\p{L}\p{N}_.-]{3,30}$/u', $user)) {
        alert_and_back('Username ต้องมี 3-30 ตัวอักษร (ตัวอักษร ตัวเลข _ . - เท่านั้น) และต้องกรอกชื่อ');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) alert_and_back('รูปแบบอีเมลไม่ถูกต้องครับ');
    if ($pass !== $confirm) alert_and_back('รหัสผ่านและการยืนยันรหัสผ่านไม่ตรงกันครับ');
    if (strlen($pass) < 8 || strlen($pass) > 72) alert_and_back('รหัสผ่านต้องยาว 8-72 ตัวอักษรครับ');

    $st = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $st->bind_param("ss", $user, $email); $st->execute();
    if ($st->get_result()->num_rows > 0) alert_and_back('Username หรือ Email นี้มีคนใช้แล้วครับ');

    $pass_hash = password_hash($pass, PASSWORD_DEFAULT);
    $role = ($role_type === 'artist') ? 'artist' : 'member';

    $conn->begin_transaction();
    try {
        $st = $conn->prepare("INSERT INTO users (full_name, username, email, password_hash, role) VALUES (?, ?, ?, ?, ?)");
        $st->bind_param("sssss", $full_name, $user, $email, $pass_hash, $role); $st->execute();
        $new_id = (int)$conn->insert_id;
        if ($role_type === 'artist') {
            $st = $conn->prepare("INSERT INTO artist_profiles (user_id, display_name) VALUES (?, ?)");
            $st->bind_param("is", $new_id, $full_name); $st->execute();
        }
        $conn->commit();
    } catch (Throwable $ex) {
        $conn->rollback();
        error_log('register: ' . $ex->getMessage());
        alert_and_back('เกิดข้อผิดพลาดในการสมัครครับ');
    }
    alert_and_back('ลงทะเบียนสำเร็จ! กรุณาเข้าสู่ระบบครับ');
}

// ================= เข้าสู่ระบบ =================
if (isset($_POST['login'])) {
    $login_user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    // ล็อกอินผิดเกิน 5 ครั้งใน 15 นาที (ต่อ IP) -> รอก่อน
    $st = $conn->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)");
    $st->bind_param("s", $ip); $st->execute();
    if ((int)$st->get_result()->fetch_row()[0] >= 5) alert_and_back('ล็อกอินผิดหลายครั้งเกินไป กรุณารอ 15 นาทีแล้วลองใหม่ครับ');

    $st = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $st->bind_param("s", $login_user); $st->execute();
    $row = $st->get_result()->fetch_assoc();

    // ใช้เวลาตรวจใกล้เคียงกันไม่ว่าจะมี username นี้หรือไม่ (กันการเดาชื่อผู้ใช้จากเวลาตอบสนอง)
    if ($row) { $valid = password_verify($pass, $row['password_hash']); }
    else      { password_hash($pass, PASSWORD_DEFAULT); $valid = false; }

    if ($valid) {
        $st = $conn->prepare("DELETE FROM login_attempts WHERE ip = ?");
        $st->bind_param("s", $ip); $st->execute();

        session_regenerate_id(true);       // กัน session fixation
        // จำ username (ใช้เติมในช่องล็อกอินเท่านั้น ไม่ใช้ยืนยันตัวตน)
        $cookie_opts = ['expires' => time() + 86400 * 30, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
                        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'];
        if ($remember) setcookie("remember_user", $login_user, $cookie_opts);
        else { $cookie_opts['expires'] = time() - 3600; setcookie("remember_user", "", $cookie_opts); }

        $uid = (int)$row['id'];
        $_SESSION['user_id']  = $uid;
        $_SESSION['username'] = $row['username'];
        $_SESSION['role']     = $row['role'];

        $st = $conn->prepare("INSERT INTO login_logs (user_id, action) VALUES (?, 'login')");
        $st->bind_param("i", $uid); $st->execute();

        if ($row['role'] === 'admin') {
            $_SESSION['is_admin'] = true;
            header("Location: admin.php"); exit;
        }
        $st = $conn->prepare("SELECT display_name FROM artist_profiles WHERE user_id = ?");
        $st->bind_param("i", $uid); $st->execute();
        $art = $st->get_result()->fetch_assoc();
        if ($art) { $_SESSION['is_artist'] = true;  $_SESSION['display_name'] = $art['display_name']; }
        else      { $_SESSION['is_artist'] = false; $_SESSION['display_name'] = $row['full_name'] ?: $row['username']; }
        header("Location: index.php"); exit;
    }

    $st = $conn->prepare("INSERT INTO login_attempts (ip, username) VALUES (?, ?)");
    $short = mb_substr($login_user, 0, 100);
    $st->bind_param("ss", $ip, $short); $st->execute();
    alert_and_back('Username หรือรหัสผ่านไม่ถูกต้องครับ');   // ข้อความเดียวกัน ไม่บอกว่าผิดที่ช่องไหน
}
header("Location: index.php");
