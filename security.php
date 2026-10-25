<?php
/**
 * security.php  — โหลดเป็นบรรทัดแรกของทุกหน้า (แทน session_start())
 * - ตั้งค่า session cookie ให้ปลอดภัย
 * - ใส่ security headers (CSP ฯลฯ)
 * - บล็อกคำขอ POST ที่มาจากต่างเว็บไซต์ (CSRF)
 * - รวมฟังก์ชันช่วย: e(), upload ภาพ/ไฟล์แบบปลอดภัย, แจ้งเตือน ฯลฯ
 */

// ---------- Session ----------
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $https,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------- Security headers ----------
if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
        . "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; "
        . "img-src 'self' data: blob: https:; connect-src 'self'; object-src 'none'; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
}

// ---------- Helpers ----------
/** escape สำหรับแสดงผลใน HTML / attribute */
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** ตรวจว่าคำขอนี้มาจากเว็บเราเอง (ใช้ Sec-Fetch-Site / Origin / Referer) */
function is_same_origin(): bool {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!empty($_SERVER['HTTP_SEC_FETCH_SITE'])) {
        return in_array($_SERVER['HTTP_SEC_FETCH_SITE'], ['same-origin', 'none'], true);
    }
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        if (!empty($_SERVER[$h])) {
            return strcasecmp((string)parse_url($_SERVER[$h], PHP_URL_HOST) . (parse_url($_SERVER[$h], PHP_URL_PORT) ? ':' . parse_url($_SERVER[$h], PHP_URL_PORT) : ''), $host) === 0
                || strcasecmp((string)parse_url($_SERVER[$h], PHP_URL_HOST), preg_replace('/:\d+$/', '', $host)) === 0;
        }
    }
    return false;
}

/** เรียกในสคริปต์ที่แก้ข้อมูลผ่าน GET (ไลค์, ลบ, ใส่ตะกร้า ฯลฯ) เพื่อกัน CSRF */
function require_same_origin(): void {
    if (!is_same_origin()) {
        http_response_code(403);
        die('Forbidden (cross-site request)');
    }
}

// POST ทุกอันต้องมาจากเว็บเราเอง
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') { require_same_origin(); }

/** ต้องล็อกอิน ไม่งั้นเด้งกลับหน้าแรก */
function require_login(): int {
    if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
    return (int)$_SESSION['user_id'];
}

/** ส่งกลับหน้าเดิมแบบปลอดภัย (รับเฉพาะ URL ในเว็บเดียวกัน) */
function safe_return_url(string $fallback = 'index.php'): string {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref !== '') {
        $p = parse_url($ref);
        $h = ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (strcasecmp($h, $_SERVER['HTTP_HOST'] ?? '') === 0) {
            return ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '') . (isset($p['fragment']) ? '#' . $p['fragment'] : '');
        }
    }
    return $fallback;
}

/** ข้อความแจ้งเตือน (SweetAlert) — escape ให้ปลอดภัยในสคริปต์ JS */
function js_str($v): string { return json_encode((string)$v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); }

// ---------- Upload ที่ปลอดภัย ----------
const IMG_MIME_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

/**
 * บันทึกรูปที่อัปโหลด: ตรวจ error/ขนาด/ชนิดไฟล์จริง (MIME) แล้วตั้งชื่อใหม่แบบสุ่ม
 * @return string|null ชื่อไฟล์ใหม่ หรือ null ถ้าไม่ผ่าน
 */
function save_image_upload(string $tmp, int $error, string $prefix = 'img', int $maxBytes = 8388608): ?string {
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp) || filesize($tmp) > $maxBytes) return null;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!isset(IMG_MIME_EXT[$mime]) || @getimagesize($tmp) === false) return null;
    $name = preg_replace('/[^a-z0-9_]/i', '', $prefix) . '_' . bin2hex(random_bytes(10)) . '.' . IMG_MIME_EXT[$mime];
    $dir = __DIR__ . '/uploads/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return move_uploaded_file($tmp, $dir . $name) ? $name : null;
}

/** อัปโหลดไฟล์งานส่งมอบ (รูป/ไฟล์บีบอัด/PDF/ไฟล์โปรแกรมวาด) — อนุญาตเฉพาะนามสกุลที่กำหนด */
function save_deliverable_upload(string $origName, string $tmp, int $error, string $prefix = 'work', int $maxBytes = 52428800): ?string {
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp) || filesize($tmp) > $maxBytes) return null;
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp','pdf','zip','rar','7z','psd','clip','ai','sai','kra'], true)) return null;
    $name = preg_replace('/[^a-z0-9_]/i', '', $prefix) . '_' . bin2hex(random_bytes(10)) . '.' . $ext;
    $dir = __DIR__ . '/uploads/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return move_uploaded_file($tmp, $dir . $name) ? $name : null;
}

/** ลบไฟล์ใน uploads/ อย่างปลอดภัย (กัน path traversal) */
function delete_upload(?string $name): void {
    if (!$name) return;
    $name = basename($name);
    $p = __DIR__ . '/uploads/' . $name;
    if ($name !== '' && is_file($p)) @unlink($p);
}

/** เพิ่มแจ้งเตือนแบบ prepared statement */
function notify(mysqli $conn, int $to, ?int $from, string $type, string $msg, string $link = '#'): void {
    $st = $conn->prepare("INSERT INTO notifications (user_id, sender_id, type, message, link) VALUES (?, ?, ?, ?, ?)");
    $st->bind_param("iisss", $to, $from, $type, $msg, $link);
    $st->execute();
}

/** ข้อความระบบในแชท */
function system_message(mysqli $conn, int $cid, int $sender, string $text): void {
    $st = $conn->prepare("INSERT INTO messages (conversation_id, sender_id, message_text, is_system_message) VALUES (?, ?, ?, 1)");
    $st->bind_param("iis", $cid, $sender, $text);
    $st->execute();
}

/** ผู้ใช้นี้เป็นผู้เข้าร่วมห้องแชทนี้หรือไม่ (หรือเป็นแอดมิน) */
function is_conversation_member(mysqli $conn, int $cid, int $uid): bool {
    if (($_SESSION['role'] ?? '') === 'admin') return true;
    $st = $conn->prepare("SELECT 1 FROM conversation_participants WHERE conversation_id = ? AND user_id = ? LIMIT 1");
    $st->bind_param("ii", $cid, $uid);
    $st->execute();
    return $st->get_result()->num_rows > 0;
}

/** ชื่อไฟล์สำหรับใส่ใน src / href / onclick (ตัดพาธ + เข้ารหัส URL กันหลุด attribute และหลุด JS string) */
function f($v): string { return rawurlencode(basename((string)$v)); }
