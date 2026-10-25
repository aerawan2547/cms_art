<?php
require_once __DIR__ . '/security.php'; include 'db.php';
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$sale_id = (int)($_GET['sale_id'] ?? 0);
$uid = (int)$_SESSION['user_id'];
$is_admin_viewer = (($_SESSION['role'] ?? '') === 'admin');

$st = $conn->prepare("
    SELECT s.*, p.title, p.preview_image, p.file_path, p.artist_id, u.username as buyer_name, a.display_name as artist_name
    FROM product_sales s
    JOIN products p ON s.product_id = p.id
    JOIN users u ON s.buyer_id = u.id
    JOIN artist_profiles a ON p.artist_id = a.user_id
    WHERE s.id = ?");
$st->bind_param("i", $sale_id); $st->execute();
$sale_q = $st->get_result();
if ($sale_q->num_rows == 0) { http_response_code(404); die("Sale not found"); }
$sale_info = $sale_q->fetch_assoc();

$is_artist = ($uid === (int)$sale_info['artist_id']);
$is_buyer  = ($uid === (int)$sale_info['buyer_id']);
if (!$is_artist && !$is_buyer && !$is_admin_viewer) { http_response_code(403); die("คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้"); }
$can_post = ($is_artist || $is_buyer);

// ห้องแชท (ถ้ายังไม่มี เช่นออเดอร์เก่า ให้สร้างพร้อมผู้เข้าร่วม)
$st = $conn->prepare("SELECT id FROM conversations WHERE related_product_sale_id = ? LIMIT 1");
$st->bind_param("i", $sale_id); $st->execute();
$conv = $st->get_result()->fetch_assoc();
if (!$conv) {
    $st = $conn->prepare("INSERT INTO conversations (related_product_sale_id, type) VALUES (?, 'product')");
    $st->bind_param("i", $sale_id); $st->execute();
    $cid = (int)$conn->insert_id;
    $b = (int)$sale_info['buyer_id']; $a = (int)$sale_info['artist_id'];
    $st = $conn->prepare("INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?, ?), (?, ?)");
    $st->bind_param("iiii", $cid, $b, $cid, $a); $st->execute();
} else { $cid = (int)$conv['id']; }

// ---- ผู้ซื้อแนบสลิป ----
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_slip']) && $is_buyer && in_array($sale_info['status'], ['pending', 'paid'], true)) {
    if (!empty($_FILES['slip_image']['name'])) {
        $slip_name = save_image_upload($_FILES['slip_image']['tmp_name'], (int)$_FILES['slip_image']['error'], 'slip');
        if ($slip_name) {
            $st = $conn->prepare("UPDATE product_sales SET slip_image = ?, status = 'paid' WHERE id = ? AND buyer_id = ?");
            $st->bind_param("sii", $slip_name, $sale_id, $uid); $st->execute();
            system_message($conn, $cid, $uid, "[SYSTEM_MSG] แนบสลิปชำระเงินเรียบร้อยแล้ว โปรดตรวจสอบ");
            notify($conn, (int)$sale_info['artist_id'], $uid, 'payment', 'มีหลักฐานการชำระเงินใหม่', "product_chat.php?sale_id=$sale_id");
        } else {
            $_SESSION['swal_error'] = 'ไฟล์สลิปไม่ถูกต้อง (jpg, png, gif, webp ไม่เกิน 8MB)';
        }
    }
    header("Location: product_chat.php?sale_id=$sale_id"); exit;
}

// ---- ศิลปินยืนยันสลิป ----
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify_slip']) && $is_artist && $sale_info['status'] === 'paid') {
    $prod_id = (int)$sale_info['product_id'];
    $conn->begin_transaction();
    try {
        $st = $conn->prepare("UPDATE product_sales SET status = 'completed' WHERE id = ? AND status = 'paid'");
        $st->bind_param("i", $sale_id); $st->execute();
        $st = $conn->prepare("UPDATE products SET status = 'sold' WHERE id = ? AND artist_id = ?");
        $st->bind_param("ii", $prod_id, $uid); $st->execute();
        $conn->commit();
        system_message($conn, $cid, $uid, "[SYSTEM_MSG] ยืนยันยอดเงินสำเร็จ ศิลปินสามารถส่งมอบลิงก์หรือไฟล์งานในแชทได้");
    } catch (Throwable $ex) { $conn->rollback(); error_log('verify_slip: ' . $ex->getMessage()); }
    header("Location: product_chat.php?sale_id=$sale_id"); exit;
}

// ---- ศิลปินส่งมอบงาน ----
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['deliver_work']) && $is_artist && in_array($sale_info['status'], ['paid', 'completed'], true)) {
    $work_link = trim($_POST['work_link'] ?? '');
    $work_file_name = '';
    $href = '';

    if (!empty($_FILES['work_file']['name'])) {
        $work_file_name = (string)save_deliverable_upload($_FILES['work_file']['name'], $_FILES['work_file']['tmp_name'], (int)$_FILES['work_file']['error'], 'delivered');
        if ($work_file_name === '') $_SESSION['swal_error'] = 'ชนิดไฟล์ไม่รองรับหรือไฟล์ใหญ่เกินไป (สูงสุด 50MB)';
    }
    if ($work_link !== '') {
        $cand = preg_match('#^https?://#i', $work_link) ? $work_link : 'https://' . $work_link;
        if (filter_var($cand, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $cand)) $href = $cand;
        else $_SESSION['swal_error'] = 'ลิงก์ผลงานไม่ถูกต้อง';
    }

    if ($work_file_name !== '' || $href !== '') {
        $dl_html = '';
        if ($work_file_name !== '') {
            $file_ext = strtolower(pathinfo($work_file_name, PATHINFO_EXTENSION));
            $fu = 'uploads/' . f($work_file_name);
            if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $dl_html .= "<div class='mt-2 mb-2 text-center'><img src='$fu' class='rounded-3 border shadow-sm' style='max-width: 220px; cursor: zoom-in;' onclick=\"viewImage('$fu')\"></div>";
                $dl_html .= "<small class='text-muted d-block text-center mb-2'><i class='fas fa-search-plus'></i> แตะที่ภาพเพื่อตรวจสอบและดาวน์โหลด</small>";
            } else {
                $dl_html .= "<a href='$fu' download class='btn btn-success mt-3 d-block text-center fw-bold shadow-sm py-2'><i class='fas fa-download me-1'></i> ดาวน์โหลดไฟล์ (" . e($file_ext) . ")</a>";
            }
        }
        if ($href !== '') {
            $dl_html .= "<a href='" . e($href) . "' target='_blank' rel='noopener noreferrer' class='btn btn-info mt-3 d-block text-white text-center fw-bold shadow-sm py-2'><i class='fas fa-external-link-alt me-1'></i> เปิดลิงก์ผลงาน</a>";
        }
        system_message($conn, $cid, $uid, "[SYSTEM_MSG] 🎁 <b>ศิลปินส่งมอบผลงานฉบับสมบูรณ์แล้ว</b><br>" . $dl_html);
        notify($conn, (int)$sale_info['buyer_id'], $uid, 'delivered', 'ได้รับผลงานฉบับสมบูรณ์เรียบร้อย', "product_chat.php?sale_id=$sale_id");
    }
    header("Location: product_chat.php?sale_id=$sale_id"); exit;
}

// ---- ส่งข้อความ / รูป ----
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message']) && $can_post) {
    $msg = mb_substr((string)($_POST['message'] ?? ''), 0, 2000);
    $type = 'text'; $file_path = null;
    if (!empty($_FILES['img']['name'])) {
        $file_path = save_image_upload($_FILES['img']['tmp_name'], (int)$_FILES['img']['error'], 'chat');
        if ($file_path) $type = 'image';
    }
    if ($type === 'image' || trim($msg) !== '') {
        $st = $conn->prepare("INSERT INTO messages (conversation_id, sender_id, message_text, message_type, file_path) VALUES (?, ?, ?, ?, ?)");
        $st->bind_param("iisss", $cid, $uid, $msg, $type, $file_path);
        $st->execute();
    }
    header("Location: product_chat.php?sale_id=$sale_id"); exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <script src="theme.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chat - ซื้อภาพสำเร็จรูป</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        body { background-color: #e2e8f0; height: 100vh; overflow: hidden; }
        .viewer-close-btn { position: absolute; top: 15px; right: 20px; z-index: 1060; background-color: rgba(255, 255, 255, 0.2); border-radius: 50%; padding: 12px; transition: 0.3s; }
        .viewer-close-btn:hover { background-color: rgba(255, 255, 255, 0.5); transform: scale(1.1); }
        
        .chat-layout-wrapper { height: calc(100vh - 60px); }
        .detail-col { height: 100%; }
        .chat-col { height: 100%; }
    </style>
    <link rel="stylesheet" href="style.css?v=2">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark shadow-sm" style="background: var(--primary-purple) !important; padding: 0.5rem 0;">
    <div class="container-fluid px-3">
        <a class="navbar-brand fw-bold" href="index.php"><i class="fas fa-palette me-2"></i>CMS ART</a>
        <a href="profile.php" class="btn btn-outline-light btn-sm rounded-pill px-3">กลับหน้าโปรไฟล์</a>
    </div>
</nav>

<div class="container-fluid px-3 py-2 chat-layout-wrapper">
    <div class="row h-100 g-3">
        <div class="col-lg-4 d-flex flex-column detail-col">
            <div class="card border-0 shadow-lg rounded-4 flex-grow-1 overflow-hidden text-white" style="background-color: #000000;">
                <div class="card-header border-bottom p-2" style="background-color: #4c2cea; border-color: #334155 !important;"><h6 class="fw-bold mb-0">รายละเอียดสินค้า</h6></div>
                <div class="card-body" style="overflow-y: auto;">
                    <div class="text-center mb-3">
                        <img src="uploads/<?php echo f($sale_info['preview_image']); ?>" class="img-fluid rounded-3 border" style="cursor: pointer; max-height: 120px;" onclick="viewImage('uploads/<?php echo f($sale_info['preview_image']); ?>')">
                    </div>
                    
                    <h6 class="fw-bold text-white"><?php echo htmlspecialchars($sale_info['title']); ?></h6>
                    <p class="small text-white-50 mb-2">ศิลปิน: <?php echo htmlspecialchars($sale_info['artist_name']); ?> | ผู้ซื้อ: <?php echo htmlspecialchars($sale_info['buyer_name']); ?></p>
                    <h5 class="fw-bold text-white mb-3">฿<?php echo number_format($sale_info['price_paid']); ?></h5>

                    <h6 class="fw-bold text-white border-bottom border-secondary pb-1 small">สถานะ</h6>
                    <?php if($sale_info['status'] == 'pending'): ?>
                        <div class="btn btn-warning btn-sm w-100 py-1 fw-bold text-dark rounded-pill mb-2" style="pointer-events: none;">รอการชำระเงิน</div>
                        <?php if(!$is_artist): ?>
                        <form method="POST" enctype="multipart/form-data" class="mt-2 p-2 rounded-4" style="background-color: #125ebb; border: 1px dashed #ffffff;">
                            <label class="text-white small fw-bold mb-1">แนบสลิปชำระเงิน (เต็มจำนวน)</label>
                            <input type="file" name="slip_image" class="form-control form-control-sm mb-2 border-0" required accept="image/*">
                            <button type="submit" name="upload_slip" class="btn btn-info text-white btn-sm w-100 fw-bold shadow-sm">แจ้งชำระเงิน</button>
                        </form>
                        <?php endif; ?>
                    <?php elseif($sale_info['status'] == 'paid'): ?>
                        <div class="btn btn-info btn-sm w-100 py-1 fw-bold text-white rounded-pill mb-2" style="pointer-events: none;">รอตรวจสอบการชำระเงิน</div>
                        <button onclick="viewImage('uploads/<?php echo f($sale_info['slip_image']); ?>')" class="btn btn-sm btn-outline-light w-100 mb-2 py-1"><i class="fas fa-search me-1"></i> ดูหลักฐาน</button>
                        <?php if($is_artist): ?>
                        <form method="POST"><button type="submit" name="verify_slip" class="btn btn-success btn-sm w-100 fw-bold shadow">ตรวจสอบและยืนยัน</button></form>
                        <?php endif; ?>
                    <?php elseif($sale_info['status'] == 'completed'): ?>
                        <div class="btn btn-success btn-sm w-100 py-1 fw-bold text-white rounded-pill mb-2" style="pointer-events: none;"><i class="fas fa-check-circle me-1"></i> ดำเนินการสำเร็จ</div>
                        <button onclick="viewImage('uploads/<?php echo f($sale_info['slip_image']); ?>')" class="btn btn-sm btn-outline-light w-100 mb-2 py-1"><i class="fas fa-search me-1"></i> ดูหลักฐาน</button>
                    <?php endif; ?>

                    <?php if($is_artist && in_array($sale_info['status'], ['paid', 'completed'])): ?>
                    <hr class="border-secondary my-3 opacity-50">
                    <h6 class="fw-bold mb-2 text-white small"><i class="fas fa-box-open me-2 text-warning"></i>ส่งมอบผลงานฉบับสมบูรณ์</h6>
                    <div class="p-2 rounded-4" style="background-color: #3f1bbe; border: 1px dashed #ffffff;">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="text" name="work_link" class="form-control form-control-sm border-0 mb-1 bg-light text-dark" placeholder="แนบลิงก์ (เช่น Google Drive)">
                            <div class="text-center text-white-50 small mb-1">- หรือ -</div>
                            <input type="file" name="work_file" class="form-control form-control-sm border-0 mb-2 bg-light text-dark" accept="image/*,.zip,.rar,.pdf">
                            <button type="submit" name="deliver_work" class="btn btn-warning btn-sm w-100 fw-bold shadow-sm text-dark">ส่งมอบผลงาน</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8 d-flex flex-column chat-col">
            <div class="card border-0 shadow-lg rounded-4 flex-grow-1 overflow-hidden d-flex flex-column">
                <div class="card-header bg-white border-bottom p-2 p-md-3"><h6 class="fw-bold mb-0 text-dark"><i class="fas fa-comments text-purple me-2"></i>ห้องสนทนา (Sale #<?php echo $sale_id; ?>)</h6></div>
                <div class="chat-box-container bg-light flex-grow-1 p-3 p-md-4" id="chatBox" style="overflow-y: auto;">
                    <?php 
                    $msgs = $conn->query("SELECT * FROM messages WHERE conversation_id = $cid ORDER BY created_at ASC");
                    while($m = $msgs->fetch_assoc()):
                        if($m['is_system_message']):
                            $sys_text = str_replace('[SYSTEM_MSG]', '', $m['message_text']);
                    ?>
                        <div class="text-center my-3 w-100">
                            <div class="bg-white text-dark rounded-4 px-3 py-2 shadow-sm border mx-auto" style="max-width: 90%; display: inline-block; text-align: left;">
                                <div class="fw-bold text-primary mb-1 border-bottom pb-1 small"><i class="fas fa-bell me-1"></i> แจ้งเตือนระบบ</div>
                                <div style="font-size: 0.85rem; line-height: 1.4;">
                                    <?php echo $sys_text; ?>
                                </div>
                            </div>
                        </div>
                    <?php continue; endif;
                    
                        $is_me = ($m['sender_id'] == $uid);
                        $align = $is_me ? 'align-items-end' : 'align-items-start';
                        $bubble = $is_me ? 'me' : 'other';
                    ?>
                    <div class="d-flex flex-column <?php echo $align; ?> mb-3">
                        <div class="chat-msg <?php echo $bubble; ?> shadow-sm px-3 py-2">
                            <?php if($m['message_type'] == 'image'): ?>
                                <div class="mb-1">
                                    <img src="uploads/<?php echo f($m['file_path']); ?>" class="rounded-3 border shadow-sm" style="max-width: 200px; cursor: zoom-in;" onclick="viewImage('uploads/<?php echo f($m['file_path']); ?>')">
                                </div>
                            <?php endif; ?>
                            <span style="font-size: 0.95rem;"><?php if(!empty($m['message_text'])) echo nl2br(htmlspecialchars($m['message_text'])); ?></span>
                        </div>
                        <small class="text-muted mx-1 mt-1" style="font-size: 0.65rem;"><?php echo date('H:i', strtotime($m['created_at'])); ?></small>
                    </div>
                    <?php endwhile; ?>
                </div>
                <div class="card-footer bg-white p-2 p-md-3 border-top">
                    <form method="POST" enctype="multipart/form-data" class="d-flex align-items-center gap-2 m-0">
                        <input type="hidden" name="send_message" value="1">
                        <label class="btn btn-light rounded-circle text-secondary d-flex align-items-center justify-content-center shadow-sm border" style="width: 40px; height: 40px; cursor: pointer;">
                            <i class="fas fa-image text-purple"></i><input type="file" name="img" hidden onchange="this.form.submit()" accept="image/*">
                        </label>
                        <input type="text" name="message" class="form-control rounded-pill bg-light border-0 py-2 px-3" placeholder="พิมพ์ข้อความ..." autocomplete="off">
                        <button type="submit" class="btn btn-purple rounded-circle shadow-sm d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;"><i class="fas fa-paper-plane"></i></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="imageViewerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content bg-transparent border-0 position-relative">
            <button type="button" class="btn-close btn-close-white viewer-close-btn shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            <div class="modal-body text-center p-0">
                <img id="viewerImage" src="" class="img-fluid rounded-4 shadow-lg mb-3" style="max-height: 80vh; object-fit: contain;">
                <div>
                    <a id="viewerDownloadBtn" href="" download class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-lg border border-white border-opacity-25" style="font-size: 1rem;">
                        <i class="fas fa-download me-2"></i> ดาวน์โหลดลงเครื่อง
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script> 
    var c = document.getElementById('chatBox'); 
    if(c) c.scrollTop = c.scrollHeight; 

    function viewImage(imgSrc) {
        document.getElementById('viewerImage').src = imgSrc;
        document.getElementById('viewerDownloadBtn').href = imgSrc;
        var imageModal = new bootstrap.Modal(document.getElementById('imageViewerModal'));
        imageModal.show();
    }
</script>
</body>
</html>