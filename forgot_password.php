<?php
require_once __DIR__ . '/security.php'; include 'db.php';

// ตารางโทเคนรีเซ็ตรหัสผ่าน (เก็บเฉพาะ hash ของโทเคน ใช้ได้ครั้งเดียว หมดอายุ 30 นาที)
$conn->query("CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (token_hash), INDEX (user_id)) ");

$step = 1;
$error_msg = '';
$notice = '';
$dev_link = '';
$token = preg_match('/^[a-f0-9]{64}$/', $_GET['token'] ?? '') ? $_GET['token'] : '';

function find_valid_reset(mysqli $conn, string $token): ?array {
    if ($token === '') return null;
    $h = hash('sha256', $token);
    $st = $conn->prepare("SELECT id, user_id FROM password_resets WHERE token_hash = ? AND expires_at > NOW() LIMIT 1");
    $st->bind_param("s", $h); $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

// ขั้นตอน 1: ขอลิงก์ตั้งรหัสผ่านใหม่
if (isset($_POST['check_email'])) {
    $email = trim($_POST['email'] ?? '');
    // ตอบข้อความเดียวกันเสมอ เพื่อไม่ให้ใครเดาได้ว่าอีเมลไหนมีในระบบ
    $notice = 'หากอีเมลนี้มีอยู่ในระบบ เราได้สร้างลิงก์สำหรับตั้งรหัสผ่านใหม่ให้แล้ว (ลิงก์ใช้ได้ 30 นาที)';

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $st = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $st->bind_param("s", $email); $st->execute();
        if ($u = $st->get_result()->fetch_assoc()) {
            $uid = (int)$u['id'];
            // จำกัด 3 คำขอ/ชั่วโมง ต่อบัญชี
            $st = $conn->prepare("SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
            $st->bind_param("i", $uid); $st->execute();
            if ((int)$st->get_result()->fetch_row()[0] < 3) {
                $raw = bin2hex(random_bytes(32));
                $h = hash('sha256', $raw);
                $st = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 30 MINUTE)");
                $st->bind_param("is", $uid, $h); $st->execute();

                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $link = $scheme . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') . '?token=' . $raw;
                $sent = @mail($email, '=?UTF-8?B?' . base64_encode('ตั้งรหัสผ่านใหม่ - CMS ART') . '?=',
                    "เปิดลิงก์นี้เพื่อตั้งรหัสผ่านใหม่ (ใช้ได้ 30 นาที):\n$link\n\nหากคุณไม่ได้ขอ ให้ละเว้นอีเมลนี้",
                    "Content-Type: text/plain; charset=UTF-8\r\n");
                // โหมดทดสอบในเครื่อง (ยังไม่ได้ตั้งค่าอีเมลเซิร์ฟเวอร์): แสดงลิงก์บนหน้าจอ
                if (APP_ENV === 'dev' && !$sent) $dev_link = $link;
            }
        }
    }
}

// ขั้นตอน 2: เปิดลิงก์จากอีเมล -> ตั้งรหัสผ่านใหม่
if ($token !== '') {
    if (find_valid_reset($conn, $token)) { $step = 2; }
    else { $error_msg = 'ลิงก์นี้หมดอายุหรือถูกใช้ไปแล้ว กรุณาขอลิงก์ใหม่อีกครั้ง'; }
}

if (isset($_POST['save_new_password'])) {
    $post_token = preg_match('/^[a-f0-9]{64}$/', $_POST['token'] ?? '') ? $_POST['token'] : '';
    $reset = find_valid_reset($conn, $post_token);
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_new_password'] ?? '';
    $token = $post_token;

    if (!$reset) { $error_msg = 'ลิงก์นี้หมดอายุหรือถูกใช้ไปแล้ว กรุณาขอลิงก์ใหม่อีกครั้ง'; $step = 1; }
    elseif ($new_pass !== $confirm_pass) { $error_msg = 'รหัสผ่านไม่ตรงกันครับ กรุณาลองใหม่'; $step = 2; }
    elseif (strlen($new_pass) < 8 || strlen($new_pass) > 72) { $error_msg = 'รหัสผ่านต้องยาว 8-72 ตัวอักษรครับ'; $step = 2; }
    else {
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $uid = (int)$reset['user_id'];
        $st = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $st->bind_param("si", $hash, $uid);
        if ($st->execute()) {
            $st = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");   // ใช้ได้ครั้งเดียว ลบทุกลิงก์ของบัญชีนี้
            $st->bind_param("i", $uid); $st->execute();
            echo "<script>alert(" . js_str('เปลี่ยนรหัสผ่านสำเร็จแล้ว! กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่ครับ') . "); window.location='index.php';</script>";
            exit;
        }
        $error_msg = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง'; $step = 2;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ลืมรหัสผ่าน · CMS ART</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <a href="index.php" class="auth-brand"><i class="fas fa-palette"></i> CMS ART</a>

        <?php if ($step == 1): ?>
        <h1 class="auth-title">ลืมรหัสผ่าน</h1>
        <p class="auth-lead">กรอกอีเมลที่ใช้สมัครสมาชิก เราจะสร้างลิงก์สำหรับตั้งรหัสผ่านใหม่ให้</p>

        <?php if ($error_msg): ?><div class="alert alert-danger small fw-bold rounded-3"><?php echo e($error_msg); ?></div><?php endif; ?>
        <?php if ($notice): ?><div class="alert alert-success small rounded-3"><?php echo e($notice); ?></div><?php endif; ?>
        <?php if ($dev_link): ?>
            <div class="alert alert-warning small rounded-3">
                <strong>โหมดทดสอบ (เครื่องนี้ยังไม่ได้ตั้งค่าส่งอีเมล)</strong><br>
                เปิดลิงก์นี้เพื่อตั้งรหัสผ่านใหม่:<br><a href="<?php echo e($dev_link); ?>" class="text-break"><?php echo e($dev_link); ?></a>
            </div>
        <?php endif; ?>

        <form method="POST">
            <label class="form-label small fw-bold" for="email">อีเมล</label>
            <input type="email" id="email" name="email" class="form-control mb-4" required autocomplete="email">
            <button type="submit" name="check_email" class="btn btn-ink w-100 mb-3">ขอลิงก์ตั้งรหัสผ่านใหม่</button>
            <div class="text-center"><a href="index.php" class="small text-muted text-decoration-none"><i class="fas fa-arrow-left me-1"></i> กลับไปหน้าเข้าสู่ระบบ</a></div>
        </form>

        <?php else: ?>
        <h1 class="auth-title">ตั้งรหัสผ่านใหม่</h1>
        <p class="auth-lead">กำหนดรหัสผ่านใหม่ อย่างน้อย 8 ตัวอักษร</p>
        <?php if ($error_msg): ?><div class="alert alert-danger small fw-bold rounded-3"><?php echo e($error_msg); ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="token" value="<?php echo e($token); ?>">
            <label class="form-label small fw-bold" for="np">รหัสผ่านใหม่</label>
            <input type="password" id="np" name="new_password" class="form-control mb-3" required minlength="8" maxlength="72" autocomplete="new-password">
            <label class="form-label small fw-bold" for="cp">ยืนยันรหัสผ่านใหม่</label>
            <input type="password" id="cp" name="confirm_new_password" class="form-control mb-4" required minlength="8" maxlength="72" autocomplete="new-password">
            <button type="submit" name="save_new_password" class="btn btn-ink w-100 mb-3">บันทึกรหัสผ่านใหม่</button>
            <div class="text-center"><a href="index.php" class="small text-muted text-decoration-none">ยกเลิกและกลับหน้าหลัก</a></div>
        </form>
        <?php endif; ?>
    </main>
</body>
</html>
