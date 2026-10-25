<?php 
require_once __DIR__ . '/security.php'; 
include 'db.php'; 

date_default_timezone_set('Asia/Bangkok');

if (!isset($_GET['id']) || empty($_GET['id'])) { header("Location: index.php"); exit; }
$sid = (int)$_GET['id'];
$uid = $_SESSION['user_id'] ?? 0;

$cart_count = 0;
$cart_items = [];
$total_price = 0;
if($uid > 0){
    $conn->query("CREATE TABLE IF NOT EXISTS cart_items (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, product_id INT NULL, service_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $check_col = $conn->query("SHOW COLUMNS FROM cart_items LIKE 'service_id'");
    if($check_col->num_rows == 0){
        $conn->query("ALTER TABLE cart_items ADD COLUMN service_id INT NULL AFTER product_id");
        $conn->query("ALTER TABLE cart_items MODIFY COLUMN product_id INT NULL");
    }

    $q_prod = $conn->query("SELECT c.id as cart_id, p.id as item_id, p.title, p.price, p.preview_image as image, p.status, 'product' as type, c.created_at FROM cart_items c JOIN products p ON c.product_id = p.id WHERE c.user_id = $uid");
    if($q_prod) { while($row = $q_prod->fetch_assoc()) $cart_items[] = $row; }
    
    $q_srv = $conn->query("SELECT c.id as cart_id, s.id as item_id, s.title, s.base_price as price, (SELECT image_path FROM service_images WHERE service_id = s.id LIMIT 1) as image, s.status, 'service' as type, c.created_at FROM cart_items c JOIN commission_services s ON c.service_id = s.id WHERE c.user_id = $uid");
    if($q_srv) { while($row = $q_srv->fetch_assoc()) $cart_items[] = $row; }
    
    usort($cart_items, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
    $cart_count = count($cart_items);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_review']) && $uid > 0) {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $rating = max(1, min(5, (int)($_POST['rating'] ?? 5)));
    $review = mb_substr(trim($_POST['review_text'] ?? ''), 0, 1000);
    // รีวิวได้เฉพาะผู้ซื้อของออเดอร์นี้ ที่เป็นงานจากประกาศนี้ และงานเสร็จสมบูรณ์แล้ว
    $st = $conn->prepare("SELECT id FROM commission_orders WHERE id = ? AND buyer_id = ? AND service_id = ? AND status = 'completed'");
    $st->bind_param("iii", $order_id, $uid, $sid); $st->execute();
    if ($st->get_result()->num_rows > 0) {
        $st = $conn->prepare("SELECT id FROM service_reviews WHERE order_id = ?");
        $st->bind_param("i", $order_id); $st->execute();
        if ($st->get_result()->num_rows == 0) {
            $st = $conn->prepare("INSERT INTO service_reviews (service_id, user_id, order_id, rating, review_text) VALUES (?, ?, ?, ?, ?)");
            $st->bind_param("iiiis", $sid, $uid, $order_id, $rating, $review);
            $st->execute();
        }
    }
    header("Location: view_service.php?id=$sid"); exit;
}

$sql = "SELECT s.*, u.username, u.id as artist_uid, u.profile_image FROM commission_services s JOIN artist_profiles a ON s.artist_id = a.user_id JOIN users u ON a.user_id = u.id WHERE s.id = $sid";
$srv_result = $conn->query($sql);

if ($srv_result->num_rows == 0) {
    echo '<!DOCTYPE html><html lang="th"><head>
    <script src="theme.js"></script><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script></head><body>';
    echo "<script>Swal.fire({icon: 'error', title: 'ไม่พบข้อมูล', text: 'ไม่พบข้อมูลงานจ้าง หรือประกาศนี้ถูกลบออกจากระบบแล้ว', confirmButtonColor: '#BF092F'}).then(() => { window.location.href = 'index.php'; });</script></body></html>";
    exit;
}
$srv = $srv_result->fetch_assoc();
$is_owner = ($uid == $srv['artist_uid']);
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] == 'admin');

if ($srv['status'] == 'censored' && !$is_owner && !$is_admin) {
    echo '<!DOCTYPE html><html lang="th"><head><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script><link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;600&display=swap" rel="stylesheet"><style>body { font-family: "Prompt", sans-serif; background-color: #f4f6f9; }</style></head><body>';
    echo "<script>Swal.fire({icon: 'warning', title: '<span style=\"font-family: Prompt; font-weight: bold; color: #132440;\">ถูกระงับการแสดงผล</span>', text: 'ประกาศรับงานนี้ถูกระงับการแสดงผลชั่วคราว', confirmButtonText: 'กลับหน้าหลัก', confirmButtonColor: '#3B9797', allowOutsideClick: false, customClass: { popup: 'rounded-4 border-0 shadow', confirmButton: 'btn rounded-pill px-4 fw-bold text-white' }}).then((result) => { if (result.isConfirmed) { window.location.href = 'index.php'; } });</script></body></html>";
    exit;
}

$service_images = [];
$img_q = $conn->query("SELECT id, image_path FROM service_images WHERE service_id=$sid");
while($row = $img_q->fetch_assoc()) { $service_images[] = ['id' => $row['id'], 'url' => "uploads/" . f($row['image_path'])]; }
$display_images = empty($service_images) ? [['id'=>0, 'url'=>"assets/placeholder.svg"]] : $service_images;

$avg_rating = 0; $total_reviews = 0;
$chk_table = $conn->query("SHOW TABLES LIKE 'service_reviews'");
if($chk_table && $chk_table->num_rows > 0) {
    $rate_data = $conn->query("SELECT AVG(rating) as avg_rate, COUNT(*) as total FROM service_reviews WHERE service_id = $sid")->fetch_assoc();
    $avg_rating = number_format((float)($rate_data['avg_rate']??0), 1);
    $total_reviews = $rate_data['total'];
}

$can_review = false; $review_order_id = 0;
if ($uid > 0 && !$is_owner && $chk_table->num_rows > 0) {
    $check_order = $conn->query("SELECT o.id FROM commission_orders o LEFT JOIN service_reviews r ON o.id = r.order_id WHERE o.service_id = $sid AND o.buyer_id = $uid AND o.status = 'completed' AND r.id IS NULL LIMIT 1");
    if ($check_order->num_rows > 0) { $can_review = true; $review_order_id = $check_order->fetch_assoc()['id']; }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($srv['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        body { background-color: #f4f6f9; font-family: 'Prompt', sans-serif; }
        .star-display { color: #ffc107; }
        .rating { display: flex; flex-direction: row-reverse; justify-content: center; gap: 5px; }
        .rating input { display: none; }
        .rating label { cursor: pointer; font-size: 30px; color: #ccc; }
        .rating label:before { content: '\f005'; font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .rating input:checked ~ label, .rating label:hover, .rating label:hover ~ label { color: #ffc107; }
        .carousel-img-wrapper { background: #f8f9fa; height: 500px; display: flex; align-items: center; justify-content: center; border-radius: 16px; overflow: hidden;}
        .carousel-img-wrapper img { max-height: 100%; max-width: 100%; object-fit: contain; }
        .edit-img-thumb { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; border: 1px solid #dee2e6; }
        .img-checkbox-wrapper { position: relative; display: inline-block; cursor: pointer; }
        .img-checkbox-wrapper input[type="checkbox"] { position: absolute; top: 5px; right: 5px; transform: scale(1.2); }
        .cart-dropdown-toggle::after { display: none; }
    </style>
    <link rel="stylesheet" href="style.css?v=2">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark shadow-sm sticky-top" style="background: var(--accent) !important; padding: 0.5rem 0;">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="index.php"><i class="fas fa-palette me-2"></i>CMS ART</a>
        <div class="d-flex align-items-center">
            <?php if($uid > 0): ?>
            <div class="dropdown me-3">
                <a class="nav-link rounded-circle d-flex align-items-center justify-content-center position-relative cart-dropdown-toggle" href="#" id="cartDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 40px; height: 40px; padding: 0; color: #ffffff; transition: background-color 0.3s ease;" onmouseover="this.style.backgroundColor='rgba(255,255,255,0.2)'" onmouseout="this.style.backgroundColor='transparent'" title="ตะกร้าสินค้า">
                    <i class="fas fa-shopping-cart" style="font-size: 1.1rem;"></i>
                    <?php if($cart_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem; border: 2px solid var(--accent);">
                            <?php echo $cart_count; ?>
                        </span>
                    <?php endif; ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-2" aria-labelledby="cartDropdown" style="width: 320px; border-radius: 12px; margin-top: 10px;">
                    <li><h6 class="dropdown-header fw-bold text-dark border-bottom pb-2 mb-2"><i class="fas fa-shopping-basket me-2" style="color:var(--accent);"></i>ตะกร้าสินค้า (<?php echo $cart_count; ?>)</h6></li>
                    <?php if(empty($cart_items)): ?>
                        <li><span class="dropdown-item text-center text-muted small py-4">ไม่มีสินค้าในตะกร้า</span></li>
                    <?php else: ?>
                        <div style="max-height: 300px; overflow-y: auto;">
                        <?php 
                        foreach($cart_items as $item): 
                            $is_avail = ($item['type'] == 'product' && $item['status'] == 'available') || ($item['type'] == 'service' && $item['status'] == 'active');
                            if($is_avail) $total_price += $item['price'];
                            $link = $item['type'] == 'product' ? "view_product.php?id=".$item['item_id'] : "view_service.php?id=".$item['item_id'];
                            $badge = $item['type'] == 'product' ? '<span class="badge bg-success mb-1" style="font-size: 0.6rem;">สินค้า</span>' : '<span class="badge bg-primary mb-1" style="font-size: 0.6rem;">งานจ้าง</span>';
                            $img_src = $item['image'] ? "uploads/" . f($item['image']) : "assets/placeholder.svg";
                        ?>
                            <li class="dropdown-item d-flex align-items-center py-2 mb-1 border-bottom px-2" style="white-space: normal; <?php echo !$is_avail ? 'opacity: 0.6; background-color: #f8f9fa;' : ''; ?>">
                                <img src="<?php echo $img_src; ?>" class="rounded-3 me-2 border shadow-sm" style="width: 55px; height: 55px; object-fit: cover;">
                                <div class="flex-grow-1" style="min-width: 0;">
                                    <?php echo $badge; ?>
                                    <a href="<?php echo $link; ?>" class="text-dark text-decoration-none fw-bold d-block" style="font-size: 0.85rem; display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; white-space: normal;">
                                        <?php echo htmlspecialchars($item['title']); ?>
                                    </a>
                                    <?php if($is_avail): ?>
                                        <span class="text-success fw-bold small">฿<?php echo number_format($item['price']); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-danger small mt-1">ปิดรับ/สินค้าหมด</span>
                                    <?php endif; ?>
                                </div>
                                <a href="cart_remove.php?cart_id=<?php echo $item['cart_id']; ?>" class="btn btn-sm text-danger ms-2 rounded-circle" style="background: #fee2e2; border: none; font-size: 1rem; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;" title="ลบออก" onclick="return confirm('ต้องการลบรายการนี้ออกจากตะกร้าใช่หรือไม่?');">
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        </div>
                        <li>
                            <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top px-2 mb-2">
                                <span class="fw-bold text-muted">ยอดรวม:</span><span class="fw-bold text-success fs-5">฿<?php echo number_format($total_price); ?></span>
                            </div>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
            <a href="index.php" class="btn btn-outline-light btn-sm rounded-pill px-3">กลับหน้าหลัก</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-3 px-md-5 py-5">
    <div class="row justify-content-center">
        <div class="col-xl-10 col-lg-12">
            
            <?php if($srv['status'] == 'censored'): ?>
                <div class="alert alert-danger rounded-4 fw-bold shadow-sm mb-4 text-center border-0" style="background-color: #fff0f0; color: #BF092F;">
                    <i class="fas fa-exclamation-triangle fa-lg me-2"></i> ประกาศรับงานนี้ถูกระงับการแสดงผล (เฉพาะคุณและผู้ดูแลระบบที่มองเห็นเนื้อหานี้)
                </div>
            <?php endif; ?>

            <div class="card border-0 shadow-lg rounded-4 overflow-hidden mb-5">
                <div class="row g-0">
                    <div class="col-md-7 bg-dark p-4 position-relative">
                        <?php if($is_owner): ?>
                            <button class="btn btn-light position-absolute top-0 end-0 m-4 shadow-sm z-3 border fw-bold rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#editSrv"><i class="fas fa-pen me-2"></i> แก้ไขงานจ้าง</button>
                        <?php endif; ?>
                        
                        <div id="serviceGallery" class="carousel slide shadow-sm rounded-4 border overflow-hidden h-100" data-bs-ride="carousel">
                            <?php if(count($display_images) > 1): ?>
                            <div class="carousel-indicators">
                                <?php foreach($display_images as $idx => $img): ?>
                                    <button type="button" data-bs-target="#serviceGallery" data-bs-slide-to="<?php echo $idx; ?>" class="<?php echo $idx==0?'active':''; ?>"></button>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <div class="carousel-inner h-100">
                                <?php foreach($display_images as $idx => $img): ?>
                                <div class="carousel-item h-100 <?php echo $idx==0 ? 'active' : ''; ?>">
                                    <div class="carousel-img-wrapper h-100 bg-dark">
                                        <img src="<?php echo $img['url']; ?>" alt="Service Image">
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if(count($display_images) > 1): ?>
                            <button class="carousel-control-prev" type="button" data-bs-target="#serviceGallery" data-bs-slide="prev"><span class="carousel-control-prev-icon bg-dark rounded-circle p-3" style="background-size: 50%;"></span></button>
                            <button class="carousel-control-next" type="button" data-bs-target="#serviceGallery" data-bs-slide="next"><span class="carousel-control-next-icon bg-dark rounded-circle p-3" style="background-size: 50%;"></span></button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-md-5 bg-white">
                        <div class="card-body p-4 p-lg-5 d-flex flex-column h-100">
                            <h2 class="fw-bold mb-3" style="color: #132440; line-height: 1.4;"><?php echo htmlspecialchars($srv['title']); ?></h2>
                            
                            <div class="d-flex align-items-center mb-4 pb-4 border-bottom justify-content-between">
                                <div class="d-flex align-items-center">
                                    <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 50px; height: 50px;">
                                        <i class="fas fa-user-circle fa-lg" style="color: var(--accent);"></i>
                                    </div>
                                    <div>
                                        <span class="text-muted d-block small mb-1 fw-bold">รับวาดโดย</span>
                                        <h6 class="mb-0 fw-bold text-dark fs-5"><?php echo htmlspecialchars($srv['username']); ?></h6>
                                    </div>
                                </div>
                                <div class="text-warning text-end">
                                    <i class="fas fa-star fa-lg"></i><br>
                                    <strong class="fs-5 text-dark"><?php echo $avg_rating; ?></strong> 
                                    <span class="small text-muted">(<?php echo $total_reviews; ?> รีวิว)</span>
                                </div>
                            </div>
                            
                            <div class="mb-4 flex-grow-1" style="overflow-y: auto; max-height: 250px; padding-right: 15px;">
                                <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-info-circle me-2"></i>รายละเอียดงาน / PRICE LIST</h6>
                                <div class="text-dark" style="line-height: 1.7; font-size: 0.95rem; white-space: pre-line;">
                                    <?php echo htmlspecialchars($srv['description']); ?>
                                </div>
                            </div>
                            
                            <div class="mt-auto border-top pt-4">
                                <div class="mb-4 d-flex justify-content-between align-items-center">
                                    <span class="text-muted fw-bold" style="font-size: 1.1rem;">ราคาเริ่มต้น</span>
                                    <h1 class="fw-bold mb-0" style="color: var(--accent);">฿<?php echo number_format($srv['base_price']); ?></h1>
                                </div>
                                
                                <div class="d-flex gap-3">
                                    <?php if(isset($_SESSION['user_id']) && !$is_owner): ?>
                                        <a href="cart_action.php?id=<?php echo $sid; ?>&type=service" class="btn w-50 py-3 fw-bold rounded-pill shadow-sm d-flex justify-content-center align-items-center" style="font-size: 1.1rem; color: var(--accent); background: white; border: 2px solid var(--accent); transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                                            <i class="fas fa-cart-plus me-2"></i> ใส่ตะกร้า
                                        </a>
                                        <form action="order_create.php" method="POST" class="w-50 m-0">
                                            <input type="hidden" name="service_id" value="<?php echo e($sid); ?>">
                                            <input type="hidden" name="artist_id" value="<?php echo $srv['artist_uid']; ?>">
                                            <input type="hidden" name="price" value="<?php echo $srv['base_price']; ?>">
                                            <button type="submit" class="btn w-100 py-3 fw-bold text-white rounded-pill shadow d-flex justify-content-center align-items-center" style="background-color: var(--accent); border: 2px solid var(--accent); font-size: 1.1rem; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                                                <i class="fas fa-paint-brush me-2"></i> จ้างงานทันที
                                            </button>
                                        </form>
                                    <?php elseif(!isset($_SESSION['user_id'])): ?>
                                        <a href="index.php" class="btn btn-secondary w-100 py-3 rounded-pill shadow-sm fw-bold text-white" style="font-size: 1.1rem;">กรุณาเข้าสู่ระบบเพื่อจ้างงาน</a>
                                    <?php elseif($is_owner): ?>
                                        <div class="alert py-3 w-100 text-center rounded-pill mb-0 fw-bold shadow-sm" style="background-color: #f3e8ff; color: var(--accent); font-size: 1.1rem;">
                                            <i class="fas fa-crown me-2 text-warning"></i> นี่คือประกาศรับงานจ้างของคุณ
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Review Section -->
            <div class="card border-0 shadow-sm rounded-4 p-4 p-lg-5">
                <h4 class="fw-bold mb-4"><i class="fas fa-comments text-warning me-2"></i>รีวิวจากลูกค้า</h4>
                <?php if($can_review): ?>
                <div class="bg-light p-4 rounded-4 mb-4 border border-warning">
                    <form method="POST">
                        <input type="hidden" name="order_id" value="<?php echo e($review_order_id); ?>">
                        <div class="mb-3 text-center"><div class="rating"><input type="radio" name="rating" id="star5" value="5" required><label for="star5"></label><input type="radio" name="rating" id="star4" value="4"><label for="star4"></label><input type="radio" name="rating" id="star3" value="3"><label for="star3"></label><input type="radio" name="rating" id="star2" value="2"><label for="star2"></label><input type="radio" name="rating" id="star1" value="1"><label for="star1"></label></div></div>
                        <div class="mb-3"><textarea name="review_text" class="form-control rounded-3" rows="3" placeholder="เขียนรีวิวผลงาน..."></textarea></div>
                        <button type="submit" name="submit_review" class="btn btn-warning w-100 rounded-pill fw-bold">ส่งรีวิว</button>
                    </form>
                </div>
                <?php endif; ?>
                <div class="review-list">
                    <?php if($chk_table->num_rows > 0) {
                        $reviews = $conn->query("SELECT r.*, u.username, u.profile_image FROM service_reviews r JOIN users u ON r.user_id = u.id WHERE r.service_id = $sid ORDER BY r.created_at DESC");
                        if ($reviews->num_rows > 0): while($rv = $reviews->fetch_assoc()): 
                            $rv_avatar = !empty($rv['profile_image']) ? "uploads/" . f($rv['profile_image']) : "assets/placeholder.svg";
                    ?>
                    <div class="d-flex gap-3 mb-4 pb-3 border-bottom"><img src="<?php echo $rv_avatar; ?>" class="rounded-circle shadow-sm" width="50" height="50" style="object-fit:cover;"><div class="w-100"><div class="d-flex justify-content-between"><span class="fw-bold"><?php echo htmlspecialchars($rv['username']); ?></span><span class="text-muted small"><?php echo date('d/m/Y', strtotime($rv['created_at'])); ?></span></div><div class="mb-2 small text-warning"><?php for($i=1; $i<=5; $i++) echo ($i<=$rv['rating'])?'<i class="fas fa-star"></i>':'<i class="far fa-star text-secondary opacity-25"></i>'; ?></div><p class="text-secondary mb-0 small bg-light p-3 rounded-3"><?php echo !empty($rv['review_text']) ? nl2br(htmlspecialchars($rv['review_text'])) : '-'; ?></p></div></div>
                    <?php endwhile; else: echo '<p class="text-center text-muted py-4">ยังไม่มีรีวิว</p>'; endif; } ?>
                </div>
            </div>

        </div>
    </div>
</div>

<?php if($is_owner): ?>
<div class="modal fade" id="editSrv"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content rounded-4 border-0 shadow-lg"><form action="service_update.php" method="POST" enctype="multipart/form-data">
    <div class="modal-header border-bottom-0 p-4"><h5 class="modal-title fw-bold" style="color: var(--accent);">แก้ไขประกาศงานจ้าง</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body p-4 pt-0">
        <input type="hidden" name="service_id" value="<?php echo e($sid); ?>">
        <div class="mb-3"><label class="fw-bold small mb-1 text-muted">หัวข้อ</label><input type="text" name="title" class="form-control rounded-3 py-2 fw-bold" value="<?php echo htmlspecialchars($srv['title']); ?>"></div>
        <div class="mb-3"><label class="fw-bold small mb-1 text-muted">รายละเอียด / เงื่อนไข</label><textarea name="desc" class="form-control rounded-3 py-2" rows="6"><?php echo htmlspecialchars($srv['description']); ?></textarea></div>
        <div class="mb-4"><label class="fw-bold small mb-1 text-muted">ราคาเริ่มต้น (บาท)</label><input type="number" name="price" class="form-control rounded-3 py-2 fw-bold" value="<?php echo $srv['base_price']; ?>"></div>
        
        <div class="mb-3">
            <label class="fw-bold small mb-2 d-block text-danger">จัดการรูปภาพปัจจุบัน (ติ๊ก ☑ ที่รูปเพื่อลบทิ้ง)</label>
            <div class="d-flex flex-wrap gap-2 p-3 bg-light rounded-4 border">
                <?php if(!empty($service_images)): foreach($service_images as $img): ?>
                <label class="img-checkbox-wrapper shadow-sm rounded-3">
                    <img src="<?php echo $img['url']; ?>" class="edit-img-thumb">
                    <input type="checkbox" name="delete_ids[]" value="<?php echo $img['id']; ?>">
                </label>
                <?php endforeach; else: echo '<small class="text-muted">ยังไม่มีรูปภาพ</small>'; endif; ?>
            </div>
        </div>
        <div class="mb-3"><label class="fw-bold small mb-2 text-primary">เพิ่มรูปภาพใหม่ (เพิ่มได้หลายรูป)</label><input type="file" name="images[]" class="form-control rounded-3 py-2" multiple accept="image/*"></div>
    </div>
    <div class="modal-footer border-top-0 p-4 pt-0"><button class="btn w-100 rounded-pill py-3 fw-bold text-white shadow-sm" style="background-color: var(--accent); font-size: 1.1rem;">บันทึกการเปลี่ยนแปลง</button></div>
</form></div></div></div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php if(isset($_SESSION['swal_success'])): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            title: '<span style="font-family: Prompt; font-weight: bold; color: var(--accent);">สำเร็จ</span>',
            text: <?php echo js_str($_SESSION['swal_success']); ?>,
            icon: 'success', iconColor: '#3D35E8', timer: 2500, timerProgressBar: true, showConfirmButton: false,
            customClass: { popup: 'rounded-4 shadow-lg border-0' }
        });
    });
</script>
<?php unset($_SESSION['swal_success']); endif; ?>

<?php if(isset($_SESSION['swal_error'])): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            title: '<span style="font-family: Prompt; font-weight: bold; color: #BF092F;">แจ้งเตือน</span>',
            text: <?php echo js_str($_SESSION['swal_error']); ?>,
            icon: 'warning', iconColor: '#BF092F',
            customClass: { popup: 'rounded-4 shadow-lg border-0' }
        });
    });
</script>
<?php unset($_SESSION['swal_error']); endif; ?>

</body>
</html>