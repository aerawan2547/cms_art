<?php
require_once __DIR__ . '/security.php'; include 'db.php';
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$oid = (int)($_GET['order_id'] ?? 0);
$uid = (int)$_SESSION['user_id'];
$is_admin_viewer = (($_SESSION['role'] ?? '') === 'admin');

$st = $conn->prepare("
    SELECT o.*, s.title, u.username as buyer_name, a.display_name as artist_name
    FROM commission_orders o
    LEFT JOIN commission_services s ON o.service_id = s.id
    LEFT JOIN users u ON o.buyer_id = u.id
    LEFT JOIN artist_profiles a ON o.artist_id = a.user_id
    WHERE o.id = ?");
$st->bind_param("i", $oid); $st->execute();
$ord_q = $st->get_result();
if ($ord_q->num_rows == 0) { http_response_code(404); die("Order not found"); }
$order_info = $ord_q->fetch_assoc();

$is_artist_of_this_order = ($uid === (int)$order_info['artist_id']);
$is_buyer_of_this_order  = ($uid === (int)$order_info['buyer_id']);
// เฉพาะผู้ซื้อ ศิลปิน และแอดมิน เท่านั้นที่เข้าห้องนี้ได้
if (!$is_artist_of_this_order && !$is_buyer_of_this_order && !$is_admin_viewer) {
    http_response_code(403); die("คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้");
}
$can_post = ($is_artist_of_this_order || $is_buyer_of_this_order);

// ห้องแชทของออเดอร์ (ถ้ายังไม่มีให้สร้าง)
$st = $conn->prepare("SELECT id FROM conversations WHERE related_order_id = ? LIMIT 1");
$st->bind_param("i", $oid); $st->execute();
$conv = $st->get_result()->fetch_assoc();
if (!$conv) {
    $st = $conn->prepare("INSERT INTO conversations (related_order_id, type) VALUES (?, 'order')");
    $st->bind_param("i", $oid); $st->execute();
    $cid = (int)$conn->insert_id;
    $b = (int)$order_info['buyer_id']; $a = (int)$order_info['artist_id'];
    $st = $conn->prepare("INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?, ?), (?, ?)");
    $st->bind_param("iiii", $cid, $b, $cid, $a); $st->execute();
} else { $cid = (int)$conv['id']; }

const ORDER_STATUSES = ['pending', 'accepted', 'paid', 'working', 'review', 'completed', 'cancelled'];

// ---- ศิลปินอัปเดตสถานะงาน ----
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status']) && $is_artist_of_this_order) {
    $new_status = $_POST['new_status'] ?? '';
    if (in_array($new_status, ORDER_STATUSES, true)) {
        $st = $conn->prepare("UPDATE commission_orders SET status = ? WHERE id = ? AND artist_id = ?");
        $st->bind_param("sii", $new_status, $oid, $uid); $st->execute();
        system_message($conn, $cid, $uid, "[SYSTEM_MSG] สถานะงานถูกอัปเดตเป็น: " . strtoupper($new_status));
        if ($new_status === 'cancelled') {
            notify($conn, (int)$order_info['buyer_id'], $uid, 'cancel_order', "รายการสั่งซื้อ (Order #$oid) ถูกยกเลิก", "chat.php?order_id=$oid");
        }
    }
    header("Location: chat.php?order_id=$oid"); exit;
}

// ---- ผู้ซื้อแนบสลิป ----
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_slip']) && $is_buyer_of_this_order) {
    $payment_type = (($_POST['payment_type'] ?? '') === 'deposit') ? 'deposit' : 'final';
    if (!empty($_FILES['slip_image']['name'])) {
        $slip_name = save_image_upload($_FILES['slip_image']['tmp_name'], (int)$_FILES['slip_image']['error'], 'slip');
        if ($slip_name) {
            if ($payment_type === 'deposit') {
                $st = $conn->prepare("UPDATE commission_orders SET deposit_slip = ?, deposit_status = 'pending' WHERE id = ? AND buyer_id = ?");
                $sys_msg = "[SYSTEM_MSG] แนบสลิปชำระเงิน (มัดจำ 50%) เรียบร้อยแล้ว โปรดตรวจสอบ";
            } else {
                $st = $conn->prepare("UPDATE commission_orders SET final_slip = ?, final_status = 'pending' WHERE id = ? AND buyer_id = ?");
                $sys_msg = "[SYSTEM_MSG] แนบสลิปชำระเงิน (ส่วนที่เหลือ 50%) เรียบร้อยแล้ว โปรดตรวจสอบ";
            }
            $st->bind_param("sii", $slip_name, $oid, $uid); $st->execute();
            system_message($conn, $cid, $uid, $sys_msg);
            notify($conn, (int)$order_info['artist_id'], $uid, 'payment', "หลักฐานการชำระเงินใหม่สำหรับ Order #$oid", "chat.php?order_id=$oid");
        } else {
            $_SESSION['swal_error'] = 'ไฟล์สลิปไม่ถูกต้อง (jpg, png, gif, webp ไม่เกิน 8MB)';
        }
    }
    header("Location: chat.php?order_id=$oid"); exit;
}

// ---- ศิลปินยืนยันสลิป ----
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify_slip']) && $is_artist_of_this_order) {
    if (($_POST['payment_type'] ?? '') === 'deposit') {
        $st = $conn->prepare("UPDATE commission_orders SET deposit_status = 'verified' WHERE id = ? AND artist_id = ? AND deposit_slip IS NOT NULL AND deposit_slip <> ''");
        $sys_msg = "[SYSTEM_MSG] ยืนยันยอดเงิน (มัดจำ 50%) สำเร็จ";
    } else {
        $st = $conn->prepare("UPDATE commission_orders SET final_status = 'verified', status = 'completed' WHERE id = ? AND artist_id = ? AND final_slip IS NOT NULL AND final_slip <> ''");
        $sys_msg = "[SYSTEM_MSG] ยืนยันยอดเงิน (ส่วนที่เหลือ 50%) สำเร็จ สถานะงาน: ส่งมอบสำเร็จ";
    }
    $st->bind_param("ii", $oid, $uid); $st->execute();
    if ($st->affected_rows > 0) system_message($conn, $cid, $uid, $sys_msg);
    header("Location: chat.php?order_id=$oid"); exit;
}

// ---- ส่งข้อความ / รูป / สเก็ตช์ ----
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message']) && $can_post) {
    $msg = mb_substr((string)($_POST['message'] ?? ''), 0, 2000);
    $type = 'text'; $file_path = null; $should_save = false;

    if (!empty($_FILES['img']['name'])) {
        $file_path = save_image_upload($_FILES['img']['tmp_name'], (int)$_FILES['img']['error'], 'chat');
        if ($file_path) { $type = 'image'; $should_save = true; }
    } elseif (!empty($_FILES['sketch_img']['name'])) {
        $file_path = save_image_upload($_FILES['sketch_img']['tmp_name'], (int)$_FILES['sketch_img']['error'], 'sketch');
        if ($file_path) { $type = 'image'; $msg = "[SKETCH_MSG] " . $msg; $should_save = true; }
    } elseif (trim($msg) !== '') {
        $should_save = true;
    }

    if ($should_save) {
        $st = $conn->prepare("INSERT INTO messages (conversation_id, sender_id, message_text, message_type, file_path) VALUES (?, ?, ?, ?, ?)");
        $st->bind_param("iisss", $cid, $uid, $msg, $type, $file_path);
        $st->execute();
    }
    header("Location: chat.php?order_id=$oid"); exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <script src="theme.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chat Room & Progress</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        body { background-color: #e2e8f0; height: 100vh; overflow: hidden; }
        
        .progress-panel::-webkit-scrollbar { width: 6px; }
        .progress-panel::-webkit-scrollbar-thumb { background: #4c2cea; border-radius: 10px; }

        /* Responsive Chat Layout */
        .chat-layout-wrapper { height: calc(100vh - 60px); }
        .detail-col { height: 100%; }
        .chat-col { height: 100%; }
        
        @media (max-width: 991.98px) {
            body { overflow-y: hidden; }
            .chat-layout-wrapper { display: flex; flex-direction: column; }
            .detail-col { height: 35vh !important; margin-bottom: 10px; } /* ปรับความสูงบนมือถือ */
            .chat-col { height: calc(65vh - 70px) !important; flex-grow: 1; }
            .navbar-brand { font-size: 1.2rem; }
            .btn-sm { font-size: 0.8rem; padding: 0.25rem 0.5rem; }
        }

        #chatLightboxModal { z-index: 99999 !important; }
        #chatLightboxModal .modal-content { background-color: rgba(0, 0, 0, 0.9) !important; border: none; }
        #chatLightboxModal .modal-body { display: flex; align-items: center; justify-content: center; height: 100vh; padding: 0; position: relative; }
        #chatLightboxModal img { max-height: 90vh; max-width: 95vw; object-fit: contain; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); user-select: none; }
        
        .chat-close-btn { position: absolute; top: 20px; right: 20px; background-color: rgba(255, 255, 255, 0.8); color: #333; border: none; border-radius: 50%; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; font-size: 22px; z-index: 1060; cursor: pointer; transition: 0.2s; backdrop-filter: blur(5px); box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .chat-close-btn:hover { background-color: #fff; color: #dc3545; transform: scale(1.1); }
        
        .chat-image-clickable { cursor: zoom-in !important; transition: transform 0.2s; }
        .chat-image-clickable:hover { transform: scale(1.02); opacity: 0.9; }
    </style>
    <link rel="stylesheet" href="style.css?v=2">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark shadow-sm bg-dark" style="background: var(--primary-purple) !important; padding: 0.5rem 0;">
    <div class="container-fluid px-3">
        <a class="navbar-brand fw-bold" href="index.php"><i class="fas fa-palette me-2"></i>CMS ART</a>
        <a href="profile.php" class="btn btn-outline-light btn-sm rounded-pill px-3"><i class="fas fa-arrow-left me-1"></i> กลับหน้าโปรไฟล์</a>
    </div>
</nav>

<div class="container-fluid px-3 py-2 chat-layout-wrapper">
    <div class="row h-100 g-3">
        
        <div class="col-lg-4 d-flex flex-column detail-col">
            <div class="card border-0 shadow-lg rounded-4 flex-grow-1 overflow-hidden text-white" style="background-color: #000000;">
                <div class="card-header border-bottom p-3" style="background-color: #4c2cea; border-color: #334155 !important;">
                    <h5 class="fw-bold mb-0 fs-6"><i class="fas fa-tasks me-2" style="color: #ffffff;"></i>รายละเอียดและความคืบหน้า</h5>
                </div>
                <div class="card-body progress-panel" style="overflow-y: auto;">
                    <h6 class="fw-bold text-white"><?php echo htmlspecialchars($order_info['title'] ?? 'งานจ้างวาด (Custom)'); ?></h6>
                    <p class="small mb-3 text-white-50">ศิลปิน: <?php echo htmlspecialchars($order_info['artist_name']); ?> | ผู้ว่าจ้าง: <?php echo htmlspecialchars($order_info['buyer_name']); ?></p>
                    
                    <div class="p-2 rounded-4 mb-3 border text-center" style="background-color: #125ebb; border-color: #ffffff !important;">
                        <span class="d-block small fw-bold text-white-50">ราคาที่ตกลง</span>
                        <span class="fw-bold fs-5" style="color: #ffffff;">฿<?php echo number_format($order_info['agreed_price']); ?></span>
                    </div>

                    <?php 
                        $status_colors = [
                            'pending' => 'warning', 'accepted' => 'info', 'paid' => 'primary',
                            'working' => 'primary', 'review' => 'warning', 'completed' => 'success', 'cancelled' => 'danger'
                        ];
                        $status_labels = [
                            'pending' => 'รอการตอบรับ', 'accepted' => 'รับงานแล้ว', 'paid' => 'ชำระเงินแล้ว',
                            'working' => 'กำลังดำเนินการ', 'review' => 'รอตรวจสอบ', 'completed' => 'ส่งมอบงานสำเร็จ', 'cancelled' => 'ยกเลิก'
                        ];
                        $cur_status = $order_info['status'];
                        $cur_color = $status_colors[$cur_status] ?? 'secondary';
                        $cur_label = $status_labels[$cur_status] ?? $cur_status;
                    ?>
                    <div class="alert alert-<?php echo $cur_color; ?> fw-bold text-center rounded-pill shadow-sm mb-3 py-2 border-0 small">
                        <i class="fas fa-circle ms-2" style="font-size: 0.6rem;"></i> <?php echo $cur_label; ?>
                    </div>

                    <?php if($is_artist_of_this_order): ?>
                    <div class="p-2 rounded-4 mb-3" style="background-color: #3f1bbe; border: 1px dashed #ffffff;">
                        <form method="POST">
                            <label class="small fw-bold mb-2 text-white"><i class="fas fa-edit me-1"></i> อัปเดตสถานะงาน:</label>
                            <div class="input-group input-group-sm">
                                <select name="new_status" class="form-select border-0 shadow-sm text-dark bg-light">
                                    <?php foreach($status_labels as $k => $v): ?>
                                        <option value="<?php echo e($k); ?>" <?php echo $cur_status == $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="update_status" class="btn btn-purple shadow-sm fw-bold px-3">บันทึก</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <hr class="border-secondary my-3 opacity-50">

                    <h6 class="fw-bold mb-2 text-white small"><i class="fas fa-file-invoice-dollar me-2 text-success"></i>สถานะการชำระเงิน</h6>

                    <?php 
                        function getPayBadge($status) {
                            if($status == 'verified') return '<span class="badge bg-success rounded-pill">ชำระแล้ว</span>';
                            if($status == 'pending') return '<span class="badge bg-warning text-dark rounded-pill">รอตรวจสอบ</span>';
                            return '<span class="badge bg-danger rounded-pill">ยังไม่ชำระ</span>';
                        }
                        $dep_status = $order_info['deposit_status'] ?? 'unpaid';
                        $fin_status = $order_info['final_status'] ?? 'unpaid';
                    ?>

                    <div class="mb-2 p-2 rounded-4 border" style="background-color: #125ebb; border-color: #ffffff !important;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-bold text-white">มัดจำ (50%)</span>
                            <?php echo getPayBadge($dep_status); ?>
                        </div>
                        
                        <?php if(!empty($order_info['deposit_slip'])): ?>
                            <button type="button" onclick="viewSlip('uploads/<?php echo f($order_info['deposit_slip']); ?>')" class="btn btn-sm btn-outline-light w-100 mb-1 py-1" style="font-size: 0.75rem;"><i class="fas fa-search me-1"></i> ตรวจสอบสลิป</button>
                            <?php if($is_artist_of_this_order && $dep_status == 'pending'): ?>
                                <form method="POST">
                                    <input type="hidden" name="payment_type" value="deposit">
                                    <button type="submit" name="verify_slip" class="btn btn-sm btn-success w-100 fw-bold py-1" style="font-size: 0.75rem;">ยืนยันยอดชำระ</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div class="mb-2 p-2 rounded-4 border" style="background-color: #125ebb; border-color: #ffffff !important;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-bold text-white">ส่วนที่เหลือ (50%)</span>
                            <?php echo getPayBadge($fin_status); ?>
                        </div>
                        
                        <?php if(!empty($order_info['final_slip'])): ?>
                            <button type="button" onclick="viewSlip('uploads/<?php echo f($order_info['final_slip']); ?>')" class="btn btn-sm btn-outline-light w-100 mb-1 py-1" style="font-size: 0.75rem;"><i class="fas fa-search me-1"></i> ตรวจสอบสลิป</button>
                            <?php if($is_artist_of_this_order && $fin_status == 'pending'): ?>
                                <form method="POST">
                                    <input type="hidden" name="payment_type" value="final">
                                    <button type="submit" name="verify_slip" class="btn btn-sm btn-success w-100 fw-bold py-1" style="font-size: 0.75rem;">ยืนยันยอดชำระ</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php if(!$is_artist_of_this_order && ($dep_status != 'verified' || $fin_status != 'verified')): ?>
                    <div class="p-2 rounded-4 mt-2" style="background-color: #3f1bbe; border: 1px dashed #ffffff;">
                        <form method="POST" enctype="multipart/form-data">
                            <label class="small fw-bold mb-2 text-white"><i class="fas fa-upload me-1 text-info"></i> แจ้งชำระเงิน:</label>
                            <select name="payment_type" class="form-select form-select-sm border-0 shadow-sm mb-2 text-dark bg-light" required>
                                <option value="">-- เลือกรายการ --</option>
                                <?php if($dep_status != 'verified'): ?>
                                    <option value="deposit">ชำระมัดจำ (50%)</option>
                                <?php endif; ?>
                                <?php if($dep_status == 'verified' && $fin_status != 'verified'): ?>
                                    <option value="final">ชำระส่วนที่เหลือ (50%)</option>
                                <?php endif; ?>
                            </select>
                            <input type="file" name="slip_image" class="form-control form-control-sm border-0 mb-2 bg-light text-dark" accept="image/*" required>
                            <button type="submit" name="upload_slip" class="btn btn-info text-white btn-sm w-100 shadow-sm fw-bold">ส่งหลักฐาน</button>
                        </form>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

        <div class="col-lg-8 d-flex flex-column chat-col">
            <div class="card border-0 shadow-lg rounded-4 flex-grow-1 overflow-hidden d-flex flex-column">
                
                <div class="card-header bg-white border-bottom p-2 p-md-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-comments text-purple me-2"></i>Order #<?php echo $oid; ?></h6>
                    </div>
                </div>
                
                <div class="chat-box-container bg-light flex-grow-1 p-3 p-md-4" id="chatBox" style="overflow-y: auto;">
                    <?php 
                    $msgs = $conn->query("SELECT * FROM messages WHERE conversation_id = $cid ORDER BY created_at ASC");
                    if($msgs->num_rows == 0): ?>
                        <div class="text-center mt-5 opacity-50">
                            <i class="fas fa-paper-plane fa-3x mb-3 text-secondary"></i>
                            <p class="small">ระบบพร้อมใช้งาน เริ่มการสนทนาได้เลย</p>
                        </div>
                    <?php endif;
                    
                    while($m = $msgs->fetch_assoc()):
                        if($m['is_system_message']):
                            $sys_text = str_replace('[SYSTEM_MSG]', '', $m['message_text']);
                    ?>
                        <div class="text-center my-3"><span class="badge bg-secondary bg-opacity-25 text-dark rounded-pill px-3 py-2 small fw-bold shadow-sm"><i class="fas fa-bell text-warning me-1"></i> <?php echo htmlspecialchars($sys_text); ?></span></div>
                    <?php continue; endif;

                        $is_me = ($m['sender_id'] == $uid);
                        $align = $is_me ? 'align-items-end' : 'align-items-start';
                        $bubble = $is_me ? 'me' : 'other';
                        
                        $is_sketch = false;
                        $display_text = htmlspecialchars($m['message_text']);
                        if (strpos($m['message_text'], '[SKETCH_MSG]') !== false) {
                            $is_sketch = true;
                            $display_text = htmlspecialchars(trim(str_replace('[SKETCH_MSG]', '', $m['message_text'])));
                        }
                    ?>
                    <div class="d-flex flex-column <?php echo $align; ?> mb-3">
                        <div class="chat-msg <?php echo $bubble; ?> shadow-sm <?php echo $is_sketch ? 'border border-warning' : ''; ?>" <?php echo $is_sketch ? 'style="background-color: #fffdf5;"' : ''; ?>>
                            
                            <?php if($is_sketch): ?>
                                <div class="small fw-bold text-warning mb-2 border-bottom border-warning pb-1">
                                    <i class="fas fa-pencil-ruler me-1"></i> ร่างผลงาน (Sketch)
                                </div>
                            <?php endif; ?>

                            <?php if($m['message_type'] == 'image'): ?>
                                <img src="uploads/<?php echo f($m['file_path']); ?>" class="chat-img d-block rounded-3 chat-image-clickable <?php echo !empty($display_text) ? 'mb-2' : ''; ?>">
                            <?php endif; ?>

                            <?php if(!empty($display_text)): ?>
                                <span style="font-size: 0.95rem;"><?php echo nl2br($display_text); ?></span>
                            <?php endif; ?>

                        </div>
                        <small class="text-muted mx-1" style="font-size: 0.65rem;">
                            <?php echo date('H:i', strtotime($m['created_at'])); ?>
                        </small>
                    </div>
                    <?php endwhile; ?>
                </div>

                <div class="card-footer bg-white p-2 p-md-3 border-top">
                    <form method="POST" enctype="multipart/form-data" class="d-flex align-items-center gap-2 m-0">
                        <input type="hidden" name="send_message" value="1">
                        
                        <?php if($is_artist_of_this_order): ?>
                        <label class="btn rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 40px; height: 40px; cursor: pointer; background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba;" title="ส่งร่างผลงาน">
                            <i class="fas fa-pencil-ruler"></i>
                            <input type="file" name="sketch_img" hidden onchange="this.form.submit()" accept="image/*">
                        </label>
                        <?php endif; ?>

                        <label class="btn btn-light rounded-circle text-secondary d-flex align-items-center justify-content-center shadow-sm border" style="width: 40px; height: 40px; cursor: pointer;" title="แนบรูปภาพ">
                            <i class="fas fa-image text-purple"></i>
                            <input type="file" name="img" hidden onchange="this.form.submit()" accept="image/*">
                        </label>

                        <input type="text" name="message" class="form-control rounded-pill bg-light border-0 py-2 px-3" placeholder="พิมพ์ข้อความ..." autocomplete="off">
                        
                        <button type="submit" class="btn btn-purple rounded-circle shadow-sm d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>
</div>

<div class="modal fade p-0" id="chatLightboxModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen p-0 m-0">
        <div class="modal-content rounded-0">
            <div class="modal-body" data-bs-dismiss="modal">
                <button type="button" class="chat-close-btn shadow" data-bs-dismiss="modal" title="ปิดหน้าต่าง">
                    <i class="fas fa-times"></i>
                </button>
                <img id="chatLightboxImage" src="" alt="Enlarged Image" onclick="event.stopPropagation();">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var c = document.getElementById('chatBox');
        if(c) c.scrollTop = c.scrollHeight;

        const chatModalEl = document.getElementById('chatLightboxModal');
        if(chatModalEl) {
            const chatLightboxModal = new bootstrap.Modal(chatModalEl);
            const chatLightboxImage = document.getElementById('chatLightboxImage');

            document.body.addEventListener('click', function(e) {
                if (e.target.classList.contains('chat-image-clickable')) {
                    e.preventDefault(); 
                    e.stopPropagation();
                    chatLightboxImage.src = e.target.src;
                    chatLightboxModal.show();
                }
            });
        }
    });

    function viewSlip(url) {
        Swal.fire({
            imageUrl: url,
            imageAlt: 'Slip Image',
            confirmButtonText: 'ปิดหน้าต่าง',
            confirmButtonColor: '#6c757d',
            width: 'auto',
            imageHeight: 500
        });
    }
</script>

</body>
</html>