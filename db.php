<?php
// ตั้งค่าฐานข้อมูล — บนเซิร์ฟเวอร์จริงให้เปลี่ยน user/password และอย่าใช้ root
$servername = getenv('DB_HOST') ?: "localhost";
$username   = getenv('DB_USER') ?: "root";
$password   = getenv('DB_PASS') !== false ? getenv('DB_PASS') : "";
$dbname     = getenv('DB_NAME') ?: "art_commission_pj";

// 'dev' = เครื่องตัวเอง (แสดงลิงก์รีเซ็ตรหัสผ่านบนหน้าจอ) / 'prod' = ใช้งานจริง
if (!defined('APP_ENV')) define('APP_ENV', getenv('APP_ENV') ?: 'dev');

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    error_log('DB connection failed: ' . $conn->connect_error);   // เก็บรายละเอียดไว้ใน log เท่านั้น
    http_response_code(500);
    die('ระบบขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง');
}
$conn->set_charset("utf8mb4");
require_once __DIR__ . '/security.php';
