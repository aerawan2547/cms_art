<?php
require_once __DIR__ . '/security.php';
include 'db.php';

// ตั้งค่า Timezone ให้เป็นเวลาประเทศไทย
date_default_timezone_set('Asia/Bangkok');

// 1. ตรวจสอบสิทธิ์ Admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit;
}

$page = $_GET['p'] ?? 'overview';
$uid = (int)$_SESSION['user_id'];

// การลบผ่านลิงก์ (GET) ต้องมาจากหน้าแอดมินของเราเอง กัน CSRF
if (isset($_GET['delete_service']) || isset($_GET['delete_gallery']) || isset($_GET['delete_product'])) { require_same_origin(); }

// --- ดึงข้อมูลการแจ้งเตือน ---
$unread_count = 0;
$notifs = [];
$notif_q = $conn->query("SELECT * FROM notifications WHERE user_id = $uid ORDER BY created_at DESC LIMIT 6");
if($notif_q) {
    while($n = $notif_q->fetch_assoc()) $notifs[] = $n;
    $unread_count = $conn->query("SELECT COUNT(*) FROM notifications WHERE user_id = $uid AND is_read = 0")->fetch_row()[0];
}
// -----------------------------

// --- ตัวแปรกรองวันที่ ---
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
// อนุญาตเฉพาะรูปแบบ YYYY-MM-DD ที่เป็นวันที่จริง (กัน SQL injection / error)
foreach (['start_date' => 'Y-m-01', 'end_date' => 'Y-m-t'] as $k => $def) {
    $d = DateTime::createFromFormat('Y-m-d', $$k);
    if (!$d || $d->format('Y-m-d') !== $$k) $$k = date($def);
}
if ($start_date > $end_date) { $tmp = $start_date; $start_date = $end_date; $end_date = $tmp; }

// SQL Conditions
$date_sql_created = "AND DATE(created_at) BETWEEN '$start_date' AND '$end_date'";
$date_sql_purchased = "AND DATE(updated_at) BETWEEN '$start_date' AND '$end_date'"; 

// ================= ACTION ZONE =================

// จัดการงานจ้าง
if (isset($_GET['delete_service'])) {
    $del_id = (int)$_GET['delete_service'];
    
    // ดึงข้อมูลศิลปินเพื่อส่งแจ้งเตือนก่อนลบ
    $q = $conn->query("SELECT artist_id, title FROM commission_services WHERE id = $del_id");
    if($q && $q->num_rows > 0){
        $data = $q->fetch_assoc();
        $artist_id = $data['artist_id'];
        $title = $conn->real_escape_string($data['title']);
        $msg = "ระบบได้ทำการลบงานจ้าง '{$title}' ของคุณ เนื่องจากอาจขัดต่อกฎของเว็บไซต์ครับ";
        $safe_msg = $conn->real_escape_string($msg); // ป้องกัน Error เครื่องหมาย '
        $conn->query("INSERT INTO notifications (user_id, message, type) VALUES ($artist_id, '$safe_msg', 'system')");
    }

    $conn->query("DELETE FROM commission_services WHERE id = $del_id");
    $_SESSION['swal_success'] = 'ลบงานจ้างสำเร็จครับ';
    header("Location: admin.php?p=posts"); exit;
}
if (isset($_POST['toggle_service_censor'])) {
    $sid = (int)$_POST['service_id'];
    $cur = $_POST['current_status'];
    $new = ($cur == 'censored') ? 'active' : 'censored';
    $conn->query("UPDATE commission_services SET status = '$new' WHERE id = $sid");
    
    // แจ้งเตือนการเซ็นเซอร์
    $q = $conn->query("SELECT artist_id, title FROM commission_services WHERE id = $sid");
    if($q && $q->num_rows > 0){
        $data = $q->fetch_assoc();
        $artist_id = $data['artist_id'];
        $title = $conn->real_escape_string($data['title']);
        $msg = ($new == 'censored') ? "งานจ้าง '{$title}' ของคุณถูกระงับการแสดงผล (Censored) โดยผู้ดูแลระบบครับ" : "งานจ้าง '{$title}' ของคุณได้รับการปลดระงับแล้วครับ";
        $safe_msg = $conn->real_escape_string($msg); // ป้องกัน Error เครื่องหมาย '
        $conn->query("INSERT INTO notifications (user_id, message, type) VALUES ($artist_id, '$safe_msg', 'system')");
    }

    $_SESSION['swal_success'] = 'เปลี่ยนสถานะการเซ็นเซอร์สำเร็จครับ';
    header("Location: admin.php?p=posts"); exit;
}

// จัดการแกลเลอรี
if (isset($_GET['delete_gallery'])) {
    $del_id = (int)$_GET['delete_gallery'];
    
    // แจ้งเตือนก่อนลบ
    $q = $conn->query("SELECT artist_id FROM artist_posts WHERE id = $del_id");
    if($q && $q->num_rows > 0){
        $data = $q->fetch_assoc();
        $artist_id = $data['artist_id'];
        $msg = "ระบบได้ทำการลบโพสต์ผลงานในแกลเลอรีของคุณ เนื่องจากอาจขัดต่อกฎของเว็บไซต์ครับ";
        $safe_msg = $conn->real_escape_string($msg);
        $conn->query("INSERT INTO notifications (user_id, message, type) VALUES ($artist_id, '$safe_msg', 'system')");
    }

    $conn->query("DELETE FROM artist_posts WHERE id = $del_id");
    $_SESSION['swal_success'] = 'ลบผลงานในแกลเลอรีสำเร็จครับ';
    header("Location: admin.php?p=gallery"); exit;
}
if (isset($_POST['toggle_image_censor'])) {
    $img_id = (int)$_POST['image_id'];
    $cur = $_POST['current_status'];
    $new = ($cur == 'active') ? 'censored' : 'active';
    $conn->query("UPDATE post_images SET status = '$new' WHERE id = $img_id");
    
    // แจ้งเตือน (ต้อง Join ตารางเพื่อหาว่ารูปนี้เป็นของใคร)
    $q = $conn->query("SELECT p.artist_id FROM post_images i JOIN artist_posts p ON i.post_id = p.id WHERE i.id = $img_id");
    if($q && $q->num_rows > 0){
        $data = $q->fetch_assoc();
        $artist_id = $data['artist_id'];
        $msg = ($new == 'censored') ? "รูปภาพในแกลเลอรีของคุณถูกเบลอเซ็นเซอร์โดยผู้ดูแลระบบครับ" : "รูปภาพในแกลเลอรีของคุณได้รับการปลดเซ็นเซอร์แล้วครับ";
        $safe_msg = $conn->real_escape_string($msg);
        $conn->query("INSERT INTO notifications (user_id, message, type) VALUES ($artist_id, '$safe_msg', 'system')");
    }

    $_SESSION['swal_success'] = 'เปลี่ยนสถานะการเซ็นเซอร์รูปภาพสำเร็จครับ';
    header("Location: admin.php?p=gallery"); exit;
}

// จัดการภาพสำเร็จรูป (Products)
if (isset($_GET['delete_product'])) {
    $del_id = (int)$_GET['delete_product'];
    
    // แจ้งเตือนก่อนลบ
    $q = $conn->query("SELECT artist_id, title FROM products WHERE id = $del_id");
    if($q && $q->num_rows > 0){
        $data = $q->fetch_assoc();
        $artist_id = $data['artist_id'];
        $title = $conn->real_escape_string($data['title']);
        $msg = "ระบบได้ทำการลบภาพสำเร็จรูป '{$title}' ของคุณ เนื่องจากอาจขัดต่อกฎของเว็บไซต์ครับ";
        $safe_msg = $conn->real_escape_string($msg); // ป้องกัน Error เครื่องหมาย '
        $conn->query("INSERT INTO notifications (user_id, message, type) VALUES ($artist_id, '$safe_msg', 'system')");
    }

    $conn->query("DELETE FROM products WHERE id = $del_id");
    $_SESSION['swal_success'] = 'ลบภาพสำเร็จรูปสำเร็จครับ';
    header("Location: admin.php?p=products"); exit;
}
if (isset($_POST['toggle_product_censor'])) {
    $pid = (int)$_POST['product_id'];
    $cur = $_POST['current_status'];
    $new = ($cur == 'censored') ? 'available' : 'censored';
    $conn->query("UPDATE products SET status = '$new' WHERE id = $pid");
    
    // แจ้งเตือนการระงับขาย
    $q = $conn->query("SELECT artist_id, title FROM products WHERE id = $pid");
    if($q && $q->num_rows > 0){
        $data = $q->fetch_assoc();
        $artist_id = $data['artist_id'];
        $title = $conn->real_escape_string($data['title']);
        $msg = ($new == 'censored') ? "ภาพสำเร็จรูป '{$title}' ของคุณถูกระงับการขายโดยผู้ดูแลระบบครับ" : "ภาพสำเร็จรูป '{$title}' ของคุณได้รับการปลดระงับให้ขายได้ตามปกติแล้วครับ";
        $safe_msg = $conn->real_escape_string($msg); // ป้องกัน Error เครื่องหมาย '
        $conn->query("INSERT INTO notifications (user_id, message, type) VALUES ($artist_id, '$safe_msg', 'system')");
    }

    $_SESSION['swal_success'] = 'เปลี่ยนสถานะภาพสำเร็จรูปสำเร็จครับ';
    header("Location: admin.php?p=products"); exit;
}

// อนุมัติสลิปงานจ้าง
if (isset($_POST['approve_slip'])) {
    $order_id = (int)$_POST['order_id'];
    $type = (($_POST['slip_type'] ?? '') === 'deposit') ? 'deposit' : 'final';
    if ($type == 'deposit') {
        $conn->query("UPDATE commission_orders SET deposit_status = 'verified', status = 'working' WHERE id = $order_id AND deposit_slip IS NOT NULL AND deposit_slip <> ''");
        $sys_msg = "[SYSTEM_MSG] แอดมินตรวจสอบสลิปมัดจำแล้ว สถานะงาน: กำลังดำเนินการ";
    } else {
        $conn->query("UPDATE commission_orders SET final_status = 'verified', status = 'completed' WHERE id = $order_id AND final_slip IS NOT NULL AND final_slip <> ''");
        $sys_msg = "[SYSTEM_MSG] แอดมินตรวจสอบสลิปส่วนที่เหลือแล้ว สถานะงาน: เสร็จสิ้น";
    }
    $q = $conn->query("SELECT id FROM conversations WHERE related_order_id = $order_id LIMIT 1");
    if($q->num_rows > 0) {
        $cid = $q->fetch_assoc()['id'];
        $safe_msg = $conn->real_escape_string($sys_msg);
        $conn->query("INSERT INTO messages (conversation_id, sender_id, message_text, is_system_message) VALUES ($cid, {$_SESSION['user_id']}, '$safe_msg', 1)");
    }
    $_SESSION['swal_success'] = 'อนุมัติสลิปชำระเงินเรียบร้อยครับ';
    header("Location: admin.php?p=slips"); exit;
}

// อนุมัติสลิปภาพสำเร็จรูป
if (isset($_POST['approve_product_slip'])) {
    $sale_id = (int)$_POST['sale_id'];
    $conn->query("UPDATE product_sales SET status = 'completed' WHERE id = $sale_id AND status = 'paid'");
    if ($conn->affected_rows < 1) { $_SESSION['swal_error'] = 'รายการนี้ไม่อยู่ในสถานะรอตรวจสลิป'; header("Location: admin.php?p=slips"); exit; }

    // อัปเดตสถานะตัวรูปภาพให้เป็น 'ขายแล้ว'
    $sale_info = $conn->query("SELECT product_id FROM product_sales WHERE id = $sale_id")->fetch_assoc();
    $prod_id = (int)$sale_info['product_id'];
    $conn->query("UPDATE products SET status = 'sold' WHERE id = $prod_id");
    
    // ส่งข้อความระบบลงในแชท
    $q = $conn->query("SELECT id FROM conversations WHERE related_product_sale_id = $sale_id LIMIT 1");
    if($q->num_rows > 0) {
        $cid = $q->fetch_assoc()['id'];
        $sys_msg = "แอดมินตรวจสอบสลิปและยืนยันยอดเงินให้แล้ว! 🎉 ศิลปินสามารถส่งมอบงานให้ลูกค้าได้เลยครับ";
        $safe_msg = $conn->real_escape_string($sys_msg);
        $conn->query("INSERT INTO messages (conversation_id, sender_id, message_text, is_system_message) VALUES ($cid, {$_SESSION['user_id']}, '$safe_msg', 1)");
    }
    $_SESSION['swal_success'] = 'อนุมัติสลิปภาพสำเร็จรูปเรียบร้อยครับ';
    header("Location: admin.php?p=slips"); exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <script src="theme.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard - CMS ART</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    
    <style>
        body { background-color: #f4f6f9; font-family: 'Prompt', sans-serif; }
        
        /* Admin Color Palette */
        .admin-sidebar { position: fixed; top: 0; left: 0; bottom: 0; width: 250px; background-color: #132440; color: white; padding-top: 20px; z-index: 1000; display: flex; flex-direction: column; overflow-y: visible; }
        .admin-sidebar .nav-link { color: #8a9bb3; padding: 12px 20px; border-radius: 8px; margin-bottom: 8px; font-weight: bold; transition: 0.3s; }
        .admin-sidebar .nav-link:hover, .admin-sidebar .nav-link.active { background-color: #16476A; color: white; }
        .admin-sidebar .brand { font-size: 1.5rem; font-weight: 900; padding: 0 20px 30px 20px; border-bottom: 1px solid #16476A; margin-bottom: 20px; }
        
        .btn-purple { background-color: #3B9797 !important; color: white !important; border: none; }
        .btn-purple:hover { background-color: #2b7474 !important; color: white !important; }
        .text-purple { color: #16476A !important; }
        .bg-purple { background-color: #16476A !important; color: white !important;}

        .content-area { margin-left: 250px; padding: 30px; min-height: 100vh; }
        .admin-card { background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); padding: 20px; margin-bottom: 20px; }
        .stat-card { border-right: 1px solid #eee; text-align: center; padding: 10px; }
        .stat-card:last-child { border-right: none; }
        .table-admin th { background-color: #f8f9fa; color: #555; }
        .table-admin td { vertical-align: middle; }
        
        .censor-wrapper { position: relative; display: inline-block; overflow: hidden; border-radius: 8px; margin-right: 5px; margin-bottom: 5px; }
        .censor-img { transition: 0.3s; display: block; width: 60px; height: 60px; object-fit: cover; }
        .is-censored .censor-img { filter: blur(4px) grayscale(80%); opacity: 0.6; }
        .censor-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; justify-content: center; align-items: center; background: rgba(0,0,0,0.2); opacity: 0; transition: 0.3s; gap: 5px; }
        .censor-wrapper:hover .censor-overlay, .is-censored .censor-overlay { opacity: 1; }
        .tool-btn { width: 28px; height: 28px; border-radius: 50%; border: none; display: flex; align-items: center; justify-content: center; background: white; cursor: pointer; transition: 0.2s; font-size: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        .is-revealed .censor-img { filter: none; opacity: 1; }
        
        /* สไตล์สำหรับ Official Report (รายงาน PDF แบบทางการ) */
        #official-report { 
            font-family: 'Sarabun', sans-serif; 
            color: #000; 
            padding: 40px; 
            background: #fff; 
            font-size: 14pt; 
            line-height: 1.5;
        }
        .formal-header { text-align: center; margin-bottom: 20px; }
        .formal-header h2 { font-size: 18pt; font-weight: bold; margin-bottom: 5px; }
        .formal-header p { font-size: 14pt; margin: 0; }
        .formal-info { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 14pt; font-weight: bold; }

        .formal-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14pt; }
        .formal-table th {
            border-top: 2px solid #000;
            border-bottom: 1px solid #000;
            padding: 8px 5px;
            text-align: center;
            font-weight: bold;
        }
        .formal-table td {
            padding: 6px 5px;
            text-align: center;
            border: none;
        }
        .formal-table tbody tr.section-header td {
            text-align: left;
            font-weight: bold;
            border-bottom: 1px dashed #ccc;
            padding-top: 15px;
        }
        .formal-table tbody tr.summary-row td {
            border-top: 1px solid #000;
            border-bottom: 2px solid #000;
            font-weight: bold;
        }
        .text-left { text-align: left !important; }
        .text-right { text-align: right !important; }
        .signature-area { margin-top: 50px; display: flex; justify-content: flex-end; text-align: center; }
        .signature-box { width: 250px; }
        
        a[onclick], button[onclick*="confirm"] { cursor: pointer; }
    </style>
    <link rel="stylesheet" href="admin.css?v=2">
</head>
<body>

<div class="admin-sidebar shadow-lg">
    <div class="brand"><i class="fas fa-layer-group me-2" style="color: #3B9797;"></i> ART ADMIN</div>
    <div class="px-3 flex-grow-1" style="overflow-y: auto;">
        <a href="?p=overview" class="nav-link <?php echo $page == 'overview' ? 'active' : ''; ?>"><i class="fas fa-chart-line me-2"></i> ภาพรวมและสถิติ</a>
        <a href="?p=posts" class="nav-link <?php echo $page == 'posts' ? 'active' : ''; ?>"><i class="fas fa-briefcase me-2"></i> จัดการงานจ้าง</a>
        <a href="?p=products" class="nav-link <?php echo $page == 'products' ? 'active' : ''; ?>"><i class="fas fa-box-open me-2"></i> จัดการภาพสำเร็จรูป</a>
        <a href="?p=gallery" class="nav-link <?php echo $page == 'gallery' ? 'active' : ''; ?>"><i class="fas fa-images me-2"></i> จัดการแกลเลอรี</a>
        <a href="?p=slips" class="nav-link <?php echo $page == 'slips' ? 'active' : ''; ?>"><i class="fas fa-file-invoice-dollar me-2"></i> ตรวจสอบสลิป</a>
        <a href="?p=history" class="nav-link <?php echo $page == 'history' ? 'active' : ''; ?>"><i class="fas fa-history me-2"></i> ประวัติใช้งาน</a>
    </div>
    
    <div class="p-3 border-top position-relative" style="border-color: #16476A !important;">
        <div class="d-flex align-items-center mb-3 justify-content-between">
            <div class="d-flex align-items-center">
                <div class="bg-light text-dark fw-bold rounded d-flex justify-content-center align-items-center me-2" style="width: 40px; height: 40px;">AD</div>
                <div><div class="fw-bold lh-1 text-white">Admin</div><small style="color: #3B9797;"><i class="fas fa-circle" style="font-size: 8px;"></i> Online</small></div>
            </div>
            <div class="dropdown">
                <a class="btn rounded-circle d-flex align-items-center justify-content-center position-relative shadow-sm" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 35px; height: 35px; background: #16476A; color: white;">
                    <i class="fas fa-bell"></i>
                    <?php if($unread_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill" style="font-size: 0.65rem; background-color: #BF092F;"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a>
                <ul class="dropdown-menu shadow border-0 p-2" style="width: 280px; border-radius: 12px; bottom: 100%; top: auto; left: 100%; transform: translate(-10px, -10px);">
                    <li><h6 class="dropdown-header fw-bold text-dark border-bottom pb-2 mb-2">การแจ้งเตือน</h6></li>
                    <?php if(empty($notifs)): ?>
                        <li><span class="dropdown-item text-center text-muted small">ไม่มีการแจ้งเตือนครับ</span></li>
                    <?php else: foreach($notifs as $n): ?>
                        <li>
                            <a class="dropdown-item py-2 <?php echo $n['is_read'] ? 'text-muted' : 'fw-bold text-dark bg-light'; ?> rounded-3 mb-1" href="read_notification.php?id=<?php echo $n['id']; ?>" style="white-space: normal; font-size: 0.85rem;">
                                <?php 
                                    if($n['type'] == 'comment') echo '<i class="fas fa-comment text-primary me-2"></i>';
                                    else if($n['type'] == 'new_order') echo '<i class="fas fa-briefcase text-success me-2"></i>';
                                    else if($n['type'] == 'cancel_order') echo '<i class="fas fa-times-circle text-danger me-2"></i>';
                                    else echo '<i class="fas fa-bell text-warning me-2"></i>';
                                ?>
                                <?php echo htmlspecialchars($n['message']); ?>
                                <div class="small text-secondary mt-1" style="font-size: 0.7rem;"><i class="far fa-clock"></i> <?php echo date('d/m/Y H:i', strtotime($n['created_at'])); ?></div>
                            </a>
                        </li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
        </div>
        <a href="profile.php" class="btn btn-outline-light btn-sm w-100 mb-2"><i class="fas fa-arrow-left"></i> กลับหน้าโปรไฟล์</a>
        <a href="logout.php" class="btn btn-sm w-100 text-white" style="background-color: #BF092F;"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
    </div>
</div>

<div class="content-area">
    
    <?php if($page == 'overview'): ?>
    <?php
        $dates = []; $sales = []; $cancels = []; $table_sales_data = [];
        $period = new DatePeriod(new DateTime($start_date), new DateInterval('P1D'), (new DateTime($end_date))->modify('+1 day'));
        foreach ($period as $dt) {
            $d = $dt->format("Y-m-d"); $d_show = $dt->format("d M");
            $sales_val = $conn->query("SELECT SUM(agreed_price) FROM commission_orders WHERE status='completed' AND DATE(updated_at)='$d'")->fetch_row()[0] ?? 0;
            $cancel_val = $conn->query("SELECT COUNT(*) FROM commission_orders WHERE status='cancelled' AND DATE(updated_at)='$d'")->fetch_row()[0] ?? 0;
            $dates[] = $d_show; $sales[] = $sales_val; $cancels[] = $cancel_val * 100;
            if($sales_val > 0 || $cancel_val > 0) $table_sales_data[] = ['date' => $dt->format("d/m/Y"), 'sales' => $sales_val, 'cancel' => $cancel_val];
        }

        $st_counts = []; $st_labels_th = ['เสร็จสิ้น', 'ยกเลิก', 'กำลังดำเนินการ', 'รอการตอบรับ']; $statuses = ['completed', 'cancelled', 'working', 'pending'];
        foreach($statuses as $st) $st_counts[] = $conn->query("SELECT COUNT(*) FROM commission_orders WHERE status='$st' $date_sql_created")->fetch_row()[0];

        $ranges = ['0-500', '501-1000', '1001-2000', '2001+']; $price_counts = [0, 0, 0, 0];
        $prices = $conn->query("SELECT agreed_price FROM commission_orders WHERE status='completed' $date_sql_purchased");
        while($p = $prices->fetch_assoc()) { $v=$p['agreed_price']; if($v<=500)$price_counts[0]++; elseif($v<=1000)$price_counts[1]++; elseif($v<=2000)$price_counts[2]++; else $price_counts[3]++; }

        $star_counts = [0,0,0,0,0]; $chk_tbl = $conn->query("SHOW TABLES LIKE 'service_reviews'");
        if($chk_tbl->num_rows > 0) { $reviews = $conn->query("SELECT rating FROM service_reviews WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'"); while($r = $reviews->fetch_assoc()) $star_counts[$r['rating']-1]++; }

        $s_total = $conn->query("SELECT SUM(agreed_price) FROM commission_orders WHERE status = 'completed' $date_sql_purchased")->fetch_row()[0] ?? 0;
        $s_count = $conn->query("SELECT COUNT(*) FROM commission_orders WHERE status = 'completed' $date_sql_purchased")->fetch_row()[0];
        $s_cancel = $conn->query("SELECT COUNT(*) FROM commission_orders WHERE status = 'cancelled' $date_sql_purchased")->fetch_row()[0];
        $s_users = $conn->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'")->fetch_row()[0];
    ?>

    <div class="admin-card py-3">
        <div class="d-flex justify-content-between align-items-end">
            <form method="GET" action="admin.php" class="d-flex gap-2 align-items-end m-0" id="filterDateForm">
                <input type="hidden" name="p" value="overview">
                <div><label class="small fw-bold text-muted">จากวันที่:</label><input type="date" name="start_date" class="form-control form-control-sm" value="<?php echo e($start_date); ?>"></div>
                <div><label class="small fw-bold text-muted">ถึงวันที่:</label><input type="date" name="end_date" class="form-control form-control-sm" value="<?php echo e($end_date); ?>"></div>
                <button type="submit" class="btn btn-purple btn-sm fw-bold px-3 shadow-sm"><i class="fas fa-filter"></i> กรอง</button> 
                <a href="admin.php?p=overview" class="btn btn-outline-secondary btn-sm px-2"><i class="fas fa-sync-alt"></i></a>
            </form>
            <button onclick="downloadOfficialReport()" class="btn btn-sm text-white fw-bold shadow-sm" style="background-color: #BF092F;"><i class="fas fa-file-pdf me-1"></i> พิมพ์รายงาน PDF</button>
        </div>
    </div>

    <div class="admin-card mb-4">
        <div class="row">
            <div class="col-md-3 stat-card"><h6 class="text-muted fw-bold mb-2">ยอดขายรวม</h6><h2 class="fw-bold text-success">฿<?php echo number_format($s_total); ?></h2></div>
            <div class="col-md-3 stat-card"><h6 class="text-muted fw-bold mb-2">งานสำเร็จ</h6><h2 class="fw-bold" style="color: #16476A;"><?php echo number_format($s_count); ?></h2></div>
            <div class="col-md-3 stat-card"><h6 class="text-muted fw-bold mb-2">งานยกเลิก</h6><h2 class="fw-bold" style="color: #BF092F;"><?php echo number_format($s_cancel); ?></h2></div>
            <div class="col-md-3 stat-card"><h6 class="text-muted fw-bold mb-2">สมาชิกใหม่</h6><h2 class="fw-bold text-dark">+<?php echo number_format($s_users); ?></h2></div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-8 mb-4"><div class="admin-card h-100"><h5 class="fw-bold mb-3" style="color: #132440;">ยอดขายรายวัน</h5><div style="height: 300px;"><canvas id="salesLineChart"></canvas></div></div></div>
        <div class="col-md-4 mb-4"><div class="admin-card h-100"><h5 class="fw-bold mb-3 text-dark">สถานะงาน</h5><div style="height: 300px; display: flex; justify-content: center;"><canvas id="statusPieChart"></canvas></div></div></div>
        <div class="col-md-6 mb-4"><div class="admin-card h-100"><h5 class="fw-bold mb-3" style="color: #16476A;">ช่วงราคายอดนิยม</h5><div style="height: 250px;"><canvas id="priceRangeChart"></canvas></div></div></div>
        <div class="col-md-6 mb-4"><div class="admin-card h-100"><h5 class="fw-bold mb-3" style="color: #3B9797;">คะแนนรีวิว</h5><div style="height: 250px;"><canvas id="reviewChart"></canvas></div></div></div>
    </div>

    <div id="official-report" style="display: none; width: 100%; max-width: 900px; margin: auto;">
        <div class="formal-header">
            <h2>รายงานสรุปผลการดำเนินงานภาพรวม</h2>
            <p>(วันที่เอกสาร <?php echo date('d/m/Y', strtotime($start_date)); ?> ถึง <?php echo date('d/m/Y', strtotime($end_date)); ?>)</p>
        </div>
        
        <div class="formal-info">
            <div>
                <div>ชื่อระบบ <span style="margin-left: 20px; font-weight: normal;">CMS ART Platform</span></div>
                <div>ผู้จัดทำ <span style="margin-left: 28px; font-weight: normal;">ผู้ดูแลระบบ (Admin)</span></div>
            </div>
            <div class="text-right">
                <div>วันที่พิมพ์ <span style="margin-left: 10px; font-weight: normal;"><?php echo date('d/m/Y H:i'); ?></span></div>
            </div>
        </div>

        <table class="formal-table">
            <thead>
                <tr>
                    <th class="text-left" style="width: 40%;">รายการ</th>
                    <th class="text-center" style="width: 20%;">จำนวน (รายการ)</th>
                    <th class="text-center" style="width: 20%;">ร้อยละ (%)</th>
                    <th class="text-right" style="width: 20%;">มูลค่า (บาท)</th>
                </tr>
            </thead>
            <tbody>
                <tr class="section-header"><td colspan="4">1. สรุปภาพรวมระบบ (Executive Summary)</td></tr>
                <tr>
                    <td class="text-left" style="padding-left: 20px;">งานที่เสร็จสิ้น (Completed)</td>
                    <td><?php echo number_format($s_count); ?></td>
                    <td>-</td>
                    <td class="text-right"><?php echo number_format($s_total, 2); ?></td>
                </tr>
                <tr>
                    <td class="text-left" style="padding-left: 20px;">งานที่ถูกยกเลิก (Cancelled)</td>
                    <td><?php echo number_format($s_cancel); ?></td>
                    <td>-</td>
                    <td class="text-right">-</td>
                </tr>
                <tr>
                    <td class="text-left" style="padding-left: 20px;">สมาชิกใหม่ (New Users)</td>
                    <td><?php echo number_format($s_users); ?></td>
                    <td>-</td>
                    <td class="text-right">-</td>
                </tr>

                <tr class="section-header"><td colspan="4">2. สถิติสถานะงานจ้าง (Order Status)</td></tr>
                <?php
                $total_orders = array_sum($st_counts);
                for($i=0; $i<4; $i++):
                    $percent = ($total_orders > 0) ? ($st_counts[$i] / $total_orders) * 100 : 0;
                ?>
                <tr>
                    <td class="text-left" style="padding-left: 20px;"><?php echo $st_labels_th[$i]; ?></td>
                    <td><?php echo number_format($st_counts[$i]); ?></td>
                    <td><?php echo number_format($percent, 2); ?></td>
                    <td class="text-right">-</td>
                </tr>
                <?php endfor; ?>

                <tr class="section-header"><td colspan="4">3. สถิติช่วงราคางานที่สำเร็จ (Price Ranges)</td></tr>
                <?php foreach($ranges as $idx => $r): ?>
                <tr>
                    <td class="text-left" style="padding-left: 20px;">ช่วงราคา <?php echo $r; ?> บาท</td>
                    <td><?php echo number_format($price_counts[$idx]); ?></td>
                    <td>-</td>
                    <td class="text-right">-</td>
                </tr>
                <?php endforeach; ?>

                <tr class="summary-row">
                    <td class="text-center">รวมยอดขายสุทธิทั้งสิ้น</td>
                    <td></td>
                    <td></td>
                    <td class="text-right"><?php echo number_format($s_total, 2); ?></td>
                </tr>
            </tbody>
        </table>

        <div style="page-break-before: auto; margin-top: 30px;">
            <div style="font-weight: bold; font-size: 14pt; margin-bottom: 10px; border-bottom: 1px dashed #ccc; padding-bottom: 5px;">4. รายละเอียดการขายรายวัน (Daily Sales)</div>
            <table class="formal-table">
                <thead>
                    <tr>
                        <th class="text-left" style="width: 40%;">วันที่ (Date)</th>
                        <th class="text-center" style="width: 30%;">งานที่ยกเลิก (รายการ)</th>
                        <th class="text-right" style="width: 30%;">ยอดขายสุทธิ (บาท)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($table_sales_data)): foreach($table_sales_data as $row): ?>
                    <tr>
                        <td class="text-left" style="padding-left: 20px;"><?php echo $row['date']; ?></td>
                        <td><?php echo number_format($row['cancel']); ?></td>
                        <td class="text-right"><?php echo number_format($row['sales'], 2); ?></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="3" style="text-align:center;">- ไม่มีการเคลื่อนไหวในช่วงเวลานี้ -</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="signature-area">
            <div class="signature-box">
                <br><br>
                <p style="margin-bottom: 5px; border-bottom: 1px dotted #000; width: 80%; margin-left: auto; margin-right: auto;"></p>
                <p style="margin: 0; font-size: 14pt;">( ....................................................... )</p>
                <p style="margin: 0; font-size: 14pt;">ผู้จัดทำรายงาน / ผู้ดูแลระบบ</p>
            </div>
        </div>
    </div>

    <script>
        new Chart(document.getElementById('salesLineChart'), { type: 'line', data: { labels: <?php echo json_encode($dates); ?>, datasets: [{ label: 'ยอดขาย', data: <?php echo json_encode($sales); ?>, borderColor: '#16476A', backgroundColor: 'rgba(22, 71, 106, 0.1)', fill: true }, { label: 'ยกเลิก (x100)', data: <?php echo json_encode($cancels); ?>, borderColor: '#BF092F', borderDash: [5,5] }] }, options: { responsive: true, maintainAspectRatio: false } });
        new Chart(document.getElementById('statusPieChart'), { type: 'doughnut', data: { labels: ['เสร็จสิ้น', 'ยกเลิก', 'กำลังทำ', 'รอตอบรับ'], datasets: [{ data: <?php echo json_encode($st_counts); ?>, backgroundColor: ['#3B9797', '#BF092F', '#16476A', '#132440'] }] }, options: { responsive: true, maintainAspectRatio: false } });
        new Chart(document.getElementById('priceRangeChart'), { type: 'bar', data: { labels: ['0-500', '501-1000', '1001-2000', '2000+'], datasets: [{ label: 'จำนวนงาน', data: <?php echo json_encode($price_counts); ?>, backgroundColor: '#16476A', borderRadius: 5 }] }, options: { responsive: true, maintainAspectRatio: false } });
        new Chart(document.getElementById('reviewChart'), { type: 'bar', data: { labels: ['1 ดาว', '2 ดาว', '3 ดาว', '4 ดาว', '5 ดาว'], datasets: [{ label: 'คะแนน', data: <?php echo json_encode($star_counts); ?>, backgroundColor: '#3B9797', borderRadius: 5 }] }, options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } } });
    </script>

    <?php elseif($page == 'posts'): ?>
    <div class="admin-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">จัดการงานจ้าง (Services)</h4>
            <form method="GET" action="admin.php" class="d-flex gap-2">
                <input type="hidden" name="p" value="posts">
                <input type="text" name="search_artist" class="form-control form-control-sm" placeholder="ค้นหาชื่อศิลปิน (พิมพ์ปุ๊บกรองปั๊บ)..." value="<?php echo e($_GET['search_artist'] ?? ''); ?>">
                <button type="button" class="btn btn-dark btn-sm" style="background-color:#132440;"><i class="fas fa-search"></i></button>
                <a href="admin.php?p=posts" class="btn btn-outline-secondary btn-sm" title="รีเฟรช"><i class="fas fa-sync-alt"></i></a>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-admin table-hover align-middle">
                <thead><tr><th>ID</th><th>รูปปก</th><th>หัวข้อ</th><th>ศิลปิน</th><th>ราคา</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
                <tbody>
                    <?php
                    $where = "1=1";
                    if (!empty($_GET['search_artist'])) $where .= " AND (a.display_name LIKE '%" . $conn->real_escape_string($_GET['search_artist']) . "%')";
                    $services = $conn->query("SELECT s.*, a.display_name AS artist_name FROM commission_services s JOIN artist_profiles a ON s.artist_id = a.user_id WHERE $where ORDER BY s.created_at DESC");
                    while($s = $services->fetch_assoc()):
                        $img = $conn->query("SELECT image_path FROM service_images WHERE service_id={$s['id']} LIMIT 1")->fetch_assoc();
                        $cover = $img ? "uploads/" . f($img['image_path']) : "assets/placeholder.svg";
                        $is_censored = ($s['status'] == 'censored');
                    ?>
                    <tr class="<?php echo $is_censored?'table-danger':''; ?>">
                        <td>#<?php echo $s['id']; ?></td>
                        <td><div class="censor-wrapper <?php echo $is_censored?'is-censored':''; ?>" id="srv-<?php echo $s['id']; ?>"><img src="<?php echo $cover; ?>" class="censor-img"><?php if($is_censored): ?><div class="censor-overlay"><button type="button" class="tool-btn btn-peek" onclick="toggleReveal('srv-<?php echo $s['id']; ?>')"><i class="fas fa-eye"></i></button></div><?php endif; ?></div></td>
                        <td><?php echo htmlspecialchars($s['title']); ?></td>
                        <td><?php echo htmlspecialchars($s['artist_name']); ?></td>
                        <td>฿<?php echo number_format($s['base_price']); ?></td>
                        <td><?php echo $is_censored?'<span class="badge" style="background-color:#BF092F;">Censored</span>':'<span class="badge" style="background-color:#3B9797;">Active</span>'; ?></td>
                        <td class="text-center">
                            <a href="view_service.php?id=<?php echo $s['id']; ?>" target="_blank" class="btn btn-sm text-white border me-1" style="background-color:#16476A;" title="ไปที่หน้าโพสต์"><i class="fas fa-external-link-alt"></i></a>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="service_id" value="<?php echo $s['id']; ?>">
                                <input type="hidden" name="current_status" value="<?php echo $s['status']; ?>">
                                <button type="submit" name="toggle_service_censor" class="btn btn-sm border me-1" title="เปลี่ยนสถานะการเซ็นเซอร์"><i class="fas <?php echo $is_censored?'fa-check':'fa-eye-slash'; ?>"></i></button>
                            </form>
                            <a href="admin.php?p=posts&delete_service=<?php echo $s['id']; ?>" class="btn btn-sm btn-light border text-danger" title="ลบข้อมูล"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif($page == 'products'): ?>
    <div class="admin-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">จัดการภาพสำเร็จรูป (Products)</h4>
            <form method="GET" action="admin.php" class="d-flex gap-2">
                <input type="hidden" name="p" value="products">
                <input type="text" name="search_product" class="form-control form-control-sm" placeholder="ค้นหาชื่อภาพ/ศิลปิน..." value="<?php echo e($_GET['search_product'] ?? ''); ?>">
                <button type="button" class="btn btn-dark btn-sm" style="background-color:#132440;"><i class="fas fa-search"></i></button>
                <a href="admin.php?p=products" class="btn btn-outline-secondary btn-sm" title="รีเฟรช"><i class="fas fa-sync-alt"></i></a>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-admin table-hover align-middle">
                <thead><tr><th>ID</th><th>รูปตัวอย่าง</th><th>ชื่อภาพ</th><th>ศิลปิน</th><th>ราคา</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
                <tbody>
                    <?php
                    $where = "1=1";
                    if (!empty($_GET['search_product'])) {
                        $sp = $conn->real_escape_string($_GET['search_product']);
                        $where .= " AND (p.title LIKE '%$sp%' OR a.display_name LIKE '%$sp%')";
                    }
                    $products = $conn->query("SELECT p.*, a.display_name AS artist_name FROM products p JOIN artist_profiles a ON p.artist_id = a.user_id WHERE $where ORDER BY p.created_at DESC");
                    while($p = $products->fetch_assoc()):
                        $cover = !empty($p['preview_image']) ? "uploads/" . f($p['preview_image']) : "assets/placeholder.svg";
                        $is_censored = ($p['status'] == 'censored');
                        
                        // เช็คและเตรียมปุ่ม "ตรวจสอบ"
                        $sale_btn = "";
                        if($p['status'] == 'sold' || $p['status'] == 'censored'){
                            $sale_q = $conn->query("SELECT s.*, u.username, u.full_name FROM product_sales s JOIN users u ON s.buyer_id = u.id WHERE s.product_id = {$p['id']} AND s.status IN ('paid', 'completed') ORDER BY s.id DESC LIMIT 1");
                            if($sale_q && $sale_q->num_rows > 0){
                                $sale = $sale_q->fetch_assoc();
                                $sale_id_str = $sale['id'];
                                $prod_title_str = htmlspecialchars($p['title'], ENT_QUOTES);
                                $buyer_str = htmlspecialchars($sale['username'] . (!empty($sale['full_name']) ? " (" . $sale['full_name'] . ")" : ""));
                                $date_str = date('d/m/Y H:i', strtotime($sale['purchased_at'] ?? $sale['created_at'])); 
                                $price_str = number_format($sale['price_paid']);
                                $slip_str = !empty($sale['slip_image']) ? "uploads/" . f($sale['slip_image']) : "";
                                
                                // เปลี่ยนปุ่มเป็นคำว่า 'ตรวจสอบ' ให้ชัดเจนขึ้น
                                $sale_btn = "<button type='button' class='btn btn-sm text-white border me-1 fw-bold px-2' style='background-color:#16476A;' title='ตรวจสอบข้อมูลการขายและหลักฐาน' onclick=\"viewSaleInfo('{$sale_id_str}', '{$prod_title_str}', '{$buyer_str}', '{$date_str}', '{$price_str}', '{$slip_str}')\"><i class='fas fa-search me-1'></i> ตรวจสอบ</button>";
                            }
                        }
                    ?>
                    <tr class="<?php echo $is_censored?'table-danger':''; ?>">
                        <td>#<?php echo $p['id']; ?></td>
                        <td><div class="censor-wrapper <?php echo $is_censored?'is-censored':''; ?>" id="prod-<?php echo $p['id']; ?>"><img src="<?php echo $cover; ?>" class="censor-img"><?php if($is_censored): ?><div class="censor-overlay"><button type="button" class="tool-btn btn-peek" onclick="toggleReveal('prod-<?php echo $p['id']; ?>')"><i class="fas fa-eye"></i></button></div><?php endif; ?></div></td>
                        <td><?php echo htmlspecialchars($p['title']); ?></td>
                        <td><?php echo htmlspecialchars($p['artist_name']); ?></td>
                        <td>฿<?php echo number_format($p['price']); ?></td>
                        <td>
                            <?php 
                            if($p['status'] == 'available') echo '<span class="badge" style="background-color:#3B9797;">พร้อมขาย</span>';
                            elseif($p['status'] == 'sold') echo '<span class="badge bg-secondary">ขายแล้ว</span>';
                            elseif($p['status'] == 'censored') echo '<span class="badge" style="background-color:#BF092F;">Censored</span>';
                            ?>
                        </td>
                        <td class="text-center">
                            <?php echo $sale_btn; ?>
                            <a href="view_product.php?id=<?php echo $p['id']; ?>" target="_blank" class="btn btn-sm text-white border me-1" style="background-color:#3B9797;" title="ไปที่หน้าภาพ"><i class="fas fa-external-link-alt"></i></a>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                <input type="hidden" name="current_status" value="<?php echo $p['status']; ?>">
                                <button type="submit" name="toggle_product_censor" class="btn btn-sm border me-1" title="เปลี่ยนสถานะการเซ็นเซอร์" <?php echo ($p['status'] == 'sold') ? 'disabled' : ''; ?>><i class="fas <?php echo $is_censored?'fa-check':'fa-eye-slash'; ?>"></i></button>
                            </form>
                            <a href="admin.php?p=products&delete_product=<?php echo $p['id']; ?>" class="btn btn-sm btn-light border text-danger" title="ลบข้อมูล"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif($page == 'gallery'): ?>
    <div class="admin-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">จัดการแกลเลอรี</h4>
            <form method="GET" action="admin.php" class="d-flex gap-2">
                <input type="hidden" name="p" value="gallery">
                <input type="text" name="search_gallery" class="form-control form-control-sm" placeholder="ค้นหาศิลปิน/แคปชั่น (พิมพ์ปุ๊บกรองปั๊บ)..." value="<?php echo e($_GET['search_gallery'] ?? ''); ?>">
                <button type="button" class="btn btn-dark btn-sm" style="background-color:#132440;"><i class="fas fa-search"></i></button>
                <a href="admin.php?p=gallery" class="btn btn-outline-secondary btn-sm"><i class="fas fa-sync-alt"></i></a>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-admin table-hover align-middle">
                <thead><tr><th>ID</th><th>รูปภาพ</th><th>แคปชั่น</th><th>ศิลปิน</th><th>ลบ</th></tr></thead>
                <tbody>
                    <?php
                    $where_gal = "1=1";
                    if(!empty($_GET['search_gallery'])) { $sg = $conn->real_escape_string($_GET['search_gallery']); $where_gal .= " AND (a.display_name LIKE '%$sg%' OR p.caption LIKE '%$sg%')"; }
                    $posts = $conn->query("SELECT p.*, a.display_name FROM artist_posts p JOIN artist_profiles a ON p.artist_id = a.user_id WHERE $where_gal ORDER BY p.created_at DESC");
                    while($p = $posts->fetch_assoc()):
                    ?>
                    <tr>
                        <td>#<?php echo $p['id']; ?></td>
                        <td>
                            <div class="d-flex flex-wrap">
                                <?php $imgs = $conn->query("SELECT * FROM post_images WHERE post_id={$p['id']}"); while($img = $imgs->fetch_assoc()): $is_censored = ($img['status'] == 'censored'); ?>
                                <div class="censor-wrapper <?php echo $is_censored?'is-censored':''; ?>" id="img-<?php echo $img['id']; ?>">
                                    <img src="uploads/<?php echo f($img['image_path']); ?>" class="censor-img">
                                    <div class="censor-overlay">
                                        <form method="POST" class="m-0">
                                            <input type="hidden" name="image_id" value="<?php echo $img['id']; ?>">
                                            <input type="hidden" name="current_status" value="<?php echo $img['status']; ?>">
                                            <button type="submit" name="toggle_image_censor" class="tool-btn btn-ban" title="เซ็นเซอร์ภาพนี้"><i class="fas <?php echo $is_censored?'fa-check':'fa-ban'; ?>"></i></button>
                                        </form>
                                        <?php if($is_censored): ?><button type="button" class="tool-btn btn-peek" onclick="toggleReveal('img-<?php echo $img['id']; ?>')"><i class="fas fa-eye"></i></button><?php endif; ?>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($p['caption']); ?></td>
                        <td><?php echo htmlspecialchars($p['display_name']); ?></td>
                        <td><a href="admin.php?p=gallery&delete_gallery=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-danger" title="ลบโพสต์"><i class="fas fa-trash"></i></a></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif($page == 'slips'): ?>
    <div class="admin-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">ตรวจสอบสลิป (งานจ้าง & ภาพสำเร็จรูป)</h4>
            <div class="d-flex gap-2">
                <form method="GET" action="admin.php" class="d-flex gap-2">
                    <input type="hidden" name="p" value="slips">
                    <input type="text" name="search_slip" class="form-control form-control-sm" placeholder="ค้นหา ID/ลูกค้า/ศิลปิน..." value="<?php echo e($_GET['search_slip'] ?? ''); ?>">
                    <button type="button" class="btn btn-dark btn-sm" style="background-color:#132440;"><i class="fas fa-search"></i></button>
                    <a href="admin.php?p=slips" class="btn btn-outline-secondary btn-sm"><i class="fas fa-sync-alt"></i></a>
                </form>
                <button onclick="downloadPDF('slip-table-all', 'report_slips_<?php echo date('Ymd'); ?>')" class="btn text-white btn-sm fw-bold shadow-sm" style="background-color: #BF092F;"><i class="fas fa-file-pdf"></i></button>
            </div>
        </div>
        
        <div id="slip-table-all">
            <h5 class="fw-bold text-dark mt-4 mb-3 border-bottom pb-2"><i class="fas fa-paint-brush me-2 text-primary"></i> สลิปงานจ้าง (Commissions)</h5>
            <div class="table-responsive mb-5" id="slip-table-commission">
                <table class="table table-admin align-middle">
                    <thead><tr><th>Order ID</th><th>ข้อมูล</th><th>มัดจำ</th><th>ส่วนที่เหลือ</th></tr></thead>
                    <tbody>
                        <?php 
                        $where_slip = "(o.deposit_slip IS NOT NULL OR o.final_slip IS NOT NULL)";
                        if(!empty($_GET['search_slip'])) { $ss = $conn->real_escape_string($_GET['search_slip']); $where_slip .= " AND (o.id = '$ss' OR ub.username LIKE '%$ss%' OR a.display_name LIKE '%$ss%')"; }
                        $slips = $conn->query("SELECT o.*, ub.username, a.display_name FROM commission_orders o JOIN users ub ON o.buyer_id=ub.id JOIN artist_profiles a ON o.artist_id=a.user_id WHERE $where_slip ORDER BY o.created_at DESC");
                        while($s = $slips->fetch_assoc()): ?>
                        <tr>
                            <td class="fw-bold" style="color: #16476A;">#<?php echo $s['id']; ?></td>
                            <td><div class="small fw-bold">จ้าง: <?php echo e($s['username']); ?></div><div class="small text-muted">วาด: <?php echo e($s['display_name']); ?></div></td>
                            <td>
                                <?php if($s['deposit_slip']): ?>
                                    <button type="button" onclick="viewSlip('uploads/<?php echo f($s['deposit_slip']); ?>')" class="btn btn-outline-dark btn-sm py-0">ดูสลิป</button> 
                                    <?php if($s['deposit_status']=='pending'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="order_id" value="<?php echo $s['id']; ?>">
                                        <input type="hidden" name="slip_type" value="deposit">
                                        <button name="approve_slip" class="btn text-white btn-sm py-0" style="background-color: #3B9797;">อนุมัติ</button>
                                    </form>
                                    <?php else: ?><span class="badge" style="background-color: #3B9797;">OK</span><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($s['final_slip']): ?>
                                    <button type="button" onclick="viewSlip('uploads/<?php echo f($s['final_slip']); ?>')" class="btn btn-outline-dark btn-sm py-0">ดูสลิป</button> 
                                    <?php if($s['final_status']=='pending'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="order_id" value="<?php echo $s['id']; ?>">
                                        <input type="hidden" name="slip_type" value="final">
                                        <button name="approve_slip" class="btn text-white btn-sm py-0" style="background-color: #3B9797;">อนุมัติ</button>
                                    </form>
                                    <?php else: ?><span class="badge" style="background-color: #3B9797;">OK</span><?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <h5 class="fw-bold text-dark mt-4 mb-3 border-bottom pb-2"><i class="fas fa-box-open me-2 text-success"></i> สลิปภาพสำเร็จรูป (Products)</h5>
            <div class="table-responsive" id="slip-table-product">
                <table class="table table-admin align-middle">
                    <thead><tr><th>Sale ID</th><th>ภาพที่ขาย</th><th>ผู้ซื้อ</th><th>ศิลปิน</th><th>ยอดเงิน</th><th>สลิปชำระเงิน</th></tr></thead>
                    <tbody>
                        <?php 
                        $where_pslip = "s.slip_image IS NOT NULL";
                        if(!empty($_GET['search_slip'])) { 
                            $ss = $conn->real_escape_string($_GET['search_slip']); 
                            $where_pslip .= " AND (s.id = '$ss' OR ub.username LIKE '%$ss%' OR a.display_name LIKE '%$ss%' OR p.title LIKE '%$ss%')"; 
                        }
                        $p_slips = $conn->query("SELECT s.*, p.title, ub.username AS buyer_name, a.display_name AS artist_name 
                                                 FROM product_sales s 
                                                 JOIN products p ON s.product_id = p.id 
                                                 JOIN users ub ON s.buyer_id = ub.id 
                                                 JOIN artist_profiles a ON p.artist_id = a.user_id 
                                                 WHERE $where_pslip ORDER BY s.id DESC");
                        while($ps = $p_slips->fetch_assoc()): ?>
                        <tr>
                            <td class="fw-bold" style="color: #16476A;">#<?php echo $ps['id']; ?></td>
                            <td><div class="small fw-bold text-truncate" style="max-width: 150px;"><?php echo htmlspecialchars($ps['title']); ?></div></td>
                            <td><?php echo htmlspecialchars($ps['buyer_name']); ?></td>
                            <td><?php echo htmlspecialchars($ps['artist_name']); ?></td>
                            <td class="text-success fw-bold">฿<?php echo number_format($ps['price_paid']); ?></td>
                            <td>
                                <?php if($ps['slip_image']): ?>
                                    <button type="button" onclick="viewSlip('uploads/<?php echo f($ps['slip_image']); ?>')" class="btn btn-outline-dark btn-sm py-0">ดูสลิป</button> 
                                    <?php if($ps['status'] == 'paid'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="sale_id" value="<?php echo $ps['id']; ?>">
                                        <button name="approve_product_slip" class="btn text-white btn-sm py-0" style="background-color: #3B9797;">อนุมัติ</button>
                                    </form>
                                    <?php else: ?>
                                        <span class="badge" style="background-color: #3B9797;">OK</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php elseif($page == 'history'): ?>
    <div class="admin-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">ประวัติการใช้งาน</h4>
            <div class="d-flex gap-2">
                <form method="GET" action="admin.php" class="d-flex gap-2">
                    <input type="hidden" name="p" value="history">
                    <input type="text" name="search_user" class="form-control form-control-sm" placeholder="ค้นหาชื่อผู้ใช้..." value="<?php echo e($_GET['search_user'] ?? ''); ?>">
                    
                    <select name="filter_type" class="form-select form-select-sm" style="width: 130px;">
                        <option value="">ทุกประเภท</option>
                        <option value="admin" <?php echo (isset($_GET['filter_type']) && $_GET['filter_type'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                        <option value="artist" <?php echo (isset($_GET['filter_type']) && $_GET['filter_type'] == 'artist') ? 'selected' : ''; ?>>Artist</option>
                        <option value="member" <?php echo (isset($_GET['filter_type']) && $_GET['filter_type'] == 'member') ? 'selected' : ''; ?>>Member</option>
                    </select>

                    <input type="date" name="filter_date" class="form-control form-control-sm" value="<?php echo e($_GET['filter_date'] ?? ''); ?>">
                    <button type="submit" class="btn btn-dark btn-sm" style="background-color:#132440;"><i class="fas fa-search"></i></button>
                    <a href="admin.php?p=history" class="btn btn-outline-secondary btn-sm"><i class="fas fa-sync-alt"></i></a>
                </form>
                <button onclick="downloadPDF('history-table', 'report_history_<?php echo date('Ymd'); ?>')" class="btn text-white btn-sm fw-bold shadow-sm" style="background-color: #BF092F;"><i class="fas fa-file-pdf"></i></button>
            </div>
        </div>
        <div class="table-responsive" id="history-table">
            <table class="table table-admin table-hover align-middle">
                <thead><tr><th>ลำดับ</th><th>ผู้ใช้งาน</th><th class="text-center">ประเภท</th><th>สถานะ</th><th>วัน-เวลา</th></tr></thead>
                <tbody>
                    <?php 
                    $where_sql = "1=1";
                    
                    if (!empty($_GET['search_user'])) { 
                        $su = $conn->real_escape_string($_GET['search_user']); 
                        $where_sql .= " AND (u.username LIKE '%$su%' OR u.full_name LIKE '%$su%')"; 
                    }
                    if (!empty($_GET['filter_date'])) { 
                        $fd = $conn->real_escape_string($_GET['filter_date']); 
                        $where_sql .= " AND DATE(l.timestamp) = '$fd'"; 
                    }
                    if (!empty($_GET['filter_type'])) {
                        $ft = $_GET['filter_type'];
                        if ($ft == 'admin') {
                            $where_sql .= " AND u.role = 'admin'";
                        } elseif ($ft == 'artist') {
                            $where_sql .= " AND a.user_id IS NOT NULL AND u.role != 'admin'";
                        } elseif ($ft == 'member') {
                            $where_sql .= " AND a.user_id IS NULL AND u.role != 'admin'";
                        }
                    }

                    $history_q = $conn->query("SELECT l.*, u.username, u.full_name, u.role, a.user_id AS artist_id FROM login_logs l JOIN users u ON l.user_id = u.id LEFT JOIN artist_profiles a ON u.id = a.user_id WHERE $where_sql ORDER BY l.timestamp DESC LIMIT 100");
                    $i=1; while($log = $history_q->fetch_assoc()):
                        $badge = ($log['role']=='admin') ? '<span class="badge bg-dark" style="background-color: #132440 !important;">Admin</span>' : (!empty($log['artist_id']) ? '<span class="badge" style="background-color:#16476A;">Artist</span>' : '<span class="badge bg-secondary">Member</span>');
                    ?>
                    <tr><td class="text-muted row-number"><?php echo $i++; ?></td><td><div class="fw-bold"><?php echo htmlspecialchars($log['username']); ?></div></td><td class="text-center"><?php echo $badge; ?></td><td><?php echo $log['action']=='login'?'<span class="badge bg-success bg-opacity-10 text-success border border-success rounded-pill px-3">Login</span>':'<span class="badge bg-danger bg-opacity-10 text-danger border border-danger rounded-pill px-3">Logout</span>'; ?></td><td class="small fw-bold text-muted"><?php echo date('d/m/Y H:i', strtotime($log['timestamp'])); ?></td></tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleReveal(id) { document.getElementById(id).classList.toggle('is-revealed'); }
    
    function downloadPDF(elementId, filename) {
        const element = document.getElementById(elementId);
        const opt = {
            margin: 0.5,
            filename: filename + '.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2 },
            jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
        };
        html2pdf().set(opt).from(element).save();
    }

    function downloadOfficialReport() {
        const element = document.getElementById('official-report');
        element.style.display = 'block'; 
        const opt = {
            margin: [0.5, 0.5, 0.5, 0.5], 
            filename: 'Official_Report_<?php echo date('Ymd'); ?>.pdf',
            image: { type: 'jpeg', quality: 1 },
            html2canvas: { scale: 2, useCORS: true },
            jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' },
            pagebreak: { mode: ['avoid-all', 'css', 'legacy'] } 
        };
        html2pdf().set(opt).from(element).save().then(function() {
            element.style.display = 'none';
        });
    }

    function viewSlip(url) {
        Swal.fire({
            imageUrl: url,
            imageAlt: 'Slip Image',
            confirmButtonText: '<i class="fas fa-times me-1"></i> ปิดหน้าต่าง',
            confirmButtonColor: '#132440',
            customClass: {
                popup: 'rounded-4 shadow-lg border-0',
                confirmButton: 'btn rounded-pill px-4 mt-3 fw-bold text-white'
            },
            buttonsStyling: false,
            width: 'auto',
            imageHeight: '60vh',
            backdrop: `rgba(19, 36, 64, 0.8)`
        });
    }

    function viewSaleInfo(saleId, prodTitle, buyer, date, price, slip) {
        let slipHtml = slip ? `<img src="${slip}" style="max-width: 100%; max-height: 280px; border: 1px solid #ced0d4; padding: 4px; background: #fff; cursor: zoom-in; box-shadow: 0 4px 8px rgba(0,0,0,0.1); border-radius: 6px;" onclick="viewSlip('${slip}')">` : '<div class="alert alert-secondary py-2 small">ไม่พบเอกสารแนบ หรือชำระด้วยช่องทางอื่น</div>';
        
        Swal.fire({
            title: '<div style="font-family: var(--font-heading); font-weight: bold; color: #132440; border-bottom: 2px solid #16476A; padding-bottom: 12px; font-size: 1.4rem; text-align: left; display:flex; justify-content:space-between; align-items:center;"><span><i class="fas fa-shield-alt me-2" style="color: #3B9797;"></i> ข้อมูลหลักฐานการสั่งซื้อ</span><span class="text-muted" style="font-size:1rem;">Sale ID: #'+saleId+'</span></div>',
            html: `
                <div style="text-align: left; font-family: 'Sarabun', sans-serif; font-size: 1.1rem; margin-top: 20px;">
                    
                    <div class="p-4 mb-4 rounded border" style="background-color: #f8f9fa; border-left: 5px solid #16476A !important;">
                        <div class="row mb-2">
                            <div class="col-4 text-muted fw-bold">ชื่อผลงาน:</div>
                            <div class="col-8 text-dark fw-bold">${prodTitle}</div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 text-muted fw-bold">บัญชีผู้ซื้อ:</div>
                            <div class="col-8 text-dark">${buyer}</div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 text-muted fw-bold">วัน-เวลาที่ทำรายการ:</div>
                            <div class="col-8 text-dark">${date}</div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 text-muted fw-bold">ยอดชำระเงินสุทธิ:</div>
                            <div class="col-8 text-success fw-bold" style="font-size: 1.25rem;">฿${price}</div>
                        </div>
                        <div class="row">
                            <div class="col-4 text-muted fw-bold">การส่งมอบไฟล์:</div>
                            <div class="col-8 text-primary fw-bold"><i class="fas fa-comment-dots"></i> จัดส่งผ่านห้องสนทนา (แชท)</div>
                        </div>
                    </div>

                    <div class="alert alert-warning py-3 mb-4" style="font-size: 0.95rem; border-left: 4px solid #ffc107; line-height: 1.6;">
                        <i class="fas fa-exclamation-triangle me-1"></i> <strong>สำหรับใช้เป็นหลักฐาน:</strong> ข้อมูลนี้ยืนยันว่าผู้ซื้อได้โอนเงินเรียบร้อยแล้ว หากเกิดข้อพิพาทเรื่องการไม่ได้รับงาน แอดมินสามารถเข้าไปตรวจสอบการแนบไฟล์หรือแนบลิงก์ที่ศิลปินส่งมอบได้จาก <strong>"ห้องสนทนา"</strong> ของ Sale ID: #${saleId}
                    </div>
                    
                    <div class="text-center mt-4">
                        <div class="fw-bold text-dark bg-light border p-2 mb-3 rounded" style="font-size: 1.05rem;"><i class="fas fa-receipt me-1 text-muted"></i> เอกสารหลักฐานการโอนเงิน (Payment Slip)</div>
                        ${slipHtml}
                        <div class="small text-muted mt-2"><i class="fas fa-search-plus"></i> คลิกที่ภาพเพื่อขยายดูสลิป</div>
                    </div>
                </div>
            `,
            confirmButtonText: 'ปิดหน้าต่าง',
            confirmButtonColor: '#132440',
            width: '750px',
            customClass: {
                popup: 'rounded-4 shadow-lg border-0',
                title: 'p-0 m-0',
                htmlContainer: 'm-0 px-3'
            }
        });
    }

    function applyFilters(card) {
        const searchInput = card.querySelector('input[name^="search_"]');
        const roleSelect = card.querySelector('select[name="filter_type"]');
        const tableBody = card.querySelector('table tbody');
        
        if(!tableBody) return;

        let searchValue = searchInput ? searchInput.value.toLowerCase() : '';
        let roleValue = roleSelect ? roleSelect.value.toLowerCase() : '';

        let rows = tableBody.querySelectorAll('tr');
        let visibleIndex = 1; 

        rows.forEach(row => {
            if(row.cells.length === 1) return; 

            let textToSearch = "";
            for(let i=1; i<row.cells.length; i++) {
                textToSearch += row.cells[i].innerText.toLowerCase() + " ";
            }
            let textMatch = textToSearch.includes(searchValue);
            
            let roleMatch = true;
            if(roleValue !== '') {
                let roleCell = row.cells[2]; 
                if(roleCell) {
                    let roleText = roleCell.innerText.toLowerCase().trim();
                    roleMatch = (roleText === roleValue);
                }
            }

            if (textMatch && roleMatch) {
                row.style.display = '';
                let rowNumCell = row.querySelector('.row-number');
                if (rowNumCell) {
                    rowNumCell.innerText = visibleIndex++;
                }
            } else {
                row.style.display = 'none';
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        
        document.querySelectorAll('input[name^="search_"]').forEach(input => {
            input.addEventListener('input', function() {
                applyFilters(this.closest('.admin-card'));
            });
            input.addEventListener('keydown', function(e) {
                if(e.key === 'Enter') e.preventDefault(); 
            });
        });

        document.querySelectorAll('select[name="filter_type"]').forEach(select => {
            select.addEventListener('change', function() {
                applyFilters(this.closest('.admin-card'));
            });
        });

        document.querySelectorAll('form').forEach(form => {
            const isSearchForm = form.querySelector('input[name*="search_"]');
            if(!isSearchForm && form.id !== 'filterDateForm') {
                const submitBtns = form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type="button"])');
                
                submitBtns.forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault(); 
                        
                        Swal.fire({
                            title: '<span style="font-family: var(--font-heading); font-weight: 700; color:#132440;">ยืนยันการทำรายการ?</span>',
                            html: '<span class="text-muted">ต้องการดำเนินการต่อใช่หรือไม่ครับ?</span>',
                            icon: 'question',
                            iconColor: '#3B9797',
                            showCancelButton: true,
                            confirmButtonText: '<i class="fas fa-check me-1"></i> ยืนยัน',
                            cancelButtonText: 'ยกเลิก',
                            customClass: {
                                popup: 'rounded-4 shadow-lg border-0',
                                confirmButton: 'btn rounded-pill px-4 mx-2 fw-bold shadow-sm text-white',
                                cancelButton: 'btn btn-light rounded-pill px-4 mx-2 fw-bold border shadow-sm'
                            },
                            didOpen: () => {
                                const confirmBtn = Swal.getConfirmButton();
                                confirmBtn.style.backgroundColor = '#3B9797';
                            },
                            buttonsStyling: false,
                            reverseButtons: true
                        }).then((result) => {
                            if (result.isConfirmed) {
                                let hiddenInput = document.createElement('input');
                                hiddenInput.type = 'hidden';
                                hiddenInput.name = btn.name;
                                hiddenInput.value = btn.value || '1';
                                form.appendChild(hiddenInput);
                                form.submit(); 
                            }
                        });
                    });
                });
            }
        });

        document.querySelectorAll('a[href*="delete"], a.text-danger').forEach(el => {
            el.removeAttribute('onclick'); 
            
            el.addEventListener('click', function(e) {
                e.preventDefault(); 
                let targetUrl = this.getAttribute('href');
                
                Swal.fire({
                    title: '<span style="font-family: var(--font-heading); font-weight: 700; color: #BF092F;">คำเตือน: ยืนยันการลบ?</span>',
                    html: '<span class="text-muted">ต้องการจะลบข้อมูลนี้แน่หรือไม่ครับ?<br><small class="text-danger">(การกระทำนี้ไม่สามารถย้อนกลับได้)</small></span>',
                    icon: 'warning',
                    iconColor: '#BF092F',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-trash-alt me-1"></i> ยืนยันการลบ',
                    cancelButtonText: 'ยกเลิก',
                    customClass: {
                        popup: 'rounded-4 shadow-lg border-0',
                        confirmButton: 'btn rounded-pill px-4 mx-2 fw-bold shadow-sm text-white',
                        cancelButton: 'btn btn-light rounded-pill px-4 mx-2 fw-bold border shadow-sm'
                    },
                    didOpen: () => {
                        const confirmBtn = Swal.getConfirmButton();
                        confirmBtn.style.backgroundColor = '#BF092F';
                    },
                    buttonsStyling: false,
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = targetUrl; 
                    }
                });
            });
        });
    });
</script>

<?php 
if(isset($_SESSION['swal_success'])): 
?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            title: '<span style="font-family: var(--font-heading); font-weight: 700; color: #3B9797;">สำเร็จ!</span>',
            text: <?php echo js_str($_SESSION['swal_success']); ?>,
            icon: 'success',
            iconColor: '#3B9797',
            customClass: {
                popup: 'rounded-4 shadow-lg border-0'
            },
            timer: 2500,
            timerProgressBar: true,
            showConfirmButton: false,
            backdrop: `rgba(19, 36, 64, 0.4)`
        });
    });
</script>
<?php 
unset($_SESSION['swal_success']); 
endif; 
?>

</body>
</html>