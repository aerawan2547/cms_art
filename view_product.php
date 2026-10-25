<?php
require_once __DIR__ . '/security.php';
include 'db.php';
require_once __DIR__ . '/commerce.php';

date_default_timezone_set('Asia/Bangkok');

if(!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$product_id = (int)$_GET['id'];
$uid = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

$my_display_name = "Guest";
$my_role = ''; 
$unread_count = 0;
$notifs = [];
$cart_count = 0;
$cart_items = [];

if($uid > 0){
    $my_display_name = $_SESSION['display_name'] ?? $_SESSION['username'];
    
    $user_q = $conn->query("SELECT role FROM users WHERE id = $uid");
    if($user_q->num_rows > 0) {
        $my_role = $user_q->fetch_assoc()['role'];
    }

    $notif_q = $conn->query("SELECT * FROM notifications WHERE user_id = $uid ORDER BY created_at DESC LIMIT 6");
    if($notif_q){
        while($n = $notif_q->fetch_assoc()) $notifs[] = $n;
        $unread_count = $conn->query("SELECT COUNT(*) FROM notifications WHERE user_id = $uid AND is_read = 0")->fetch_row()[0];
    }
    
    $conn->query("CREATE TABLE IF NOT EXISTS cart_items (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        user_id INT, 
        product_id INT NULL, 
        service_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $cart_q = $conn->query("
        SELECT c.id as cart_id, p.id as product_id, p.title, p.price, p.preview_image, p.status 
        FROM cart_items c 
        JOIN products p ON c.product_id = p.id 
        WHERE c.user_id = $uid 
        ORDER BY c.created_at DESC
    ");
    if($cart_q) {
        while($c = $cart_q->fetch_assoc()) $cart_items[] = $c;
        $cart_count = count($cart_items);
    }
}

$sql = "SELECT p.*, a.display_name AS artist_name 
        FROM products p 
        JOIN artist_profiles a ON p.artist_id = a.user_id 
        WHERE p.id = $product_id";
$result = $conn->query($sql);

if($result->num_rows == 0) {
    echo '<!DOCTYPE html><html lang="th"><head>
    <script src="theme.js"></script><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1"><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script><link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;600&display=swap" rel="stylesheet"><style>body { font-family: "Prompt", sans-serif; background-color: #f4f6f9; }</style></head><body>';
    echo "<script>
        Swal.fire({
            icon: 'warning', title: '<span style=\"font-family: Prompt; font-weight: bold; color: #BF092F;\">แจ้งเตือน</span>',
            text: 'ไม่พบข้อมูลสินค้า หรือสินค้านี้ถูกลบออกจากระบบแล้ว', confirmButtonText: 'กลับหน้าหลัก', confirmButtonColor: '#16476A',
            allowOutsideClick: false, customClass: { popup: 'rounded-4 shadow-lg border-0', confirmButton: 'btn rounded-pill px-4 fw-bold shadow-sm text-white' }
        }).then((result) => { if (result.isConfirmed) { window.location.href = 'index.php'; } });
    </script></body></html>";
    exit;
}

$product = $result->fetch_assoc();
$is_owner = ($uid == $product['artist_id']);
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] == 'admin');

if (($product['status'] == 'censored' || $product['status'] == 'sold') && !$is_owner && !$is_admin) {
    $msg_text = ($product['status'] == 'sold') ? 'สินค้านี้ถูกจำหน่ายแล้ว' : 'สินค้านี้ถูกระงับการแสดงผลชั่วคราว';
    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script><link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;600&display=swap" rel="stylesheet"><style>body { font-family: "Prompt", sans-serif; background-color: #f4f6f9; }</style></head><body>';
    echo "<script>
        Swal.fire({
            icon: 'warning', title: '<span style=\"font-family: Prompt; font-weight: bold; color: #132440;\">ไม่สามารถเข้าถึงได้</span>',
            text: '" . addslashes($msg_text) . "', confirmButtonText: 'กลับหน้าหลัก', confirmButtonColor: '#3B9797', allowOutsideClick: false,
            customClass: { popup: 'rounded-4 border-0 shadow', confirmButton: 'btn rounded-pill px-4 fw-bold text-white' }
        }).then((result) => { if (result.isConfirmed) { window.location.href = 'index.php'; } });
    </script></body></html>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['buy_product'])) {
    if ($uid == 0) { header("Location: index.php"); exit; }
    [$buy_ok, $buy_err, $sale_id] = create_product_sale($conn, $product_id, (int)$uid);
    if ($buy_ok) { header("Location: product_chat.php?sale_id=$sale_id"); exit; }
    $error_msg = $buy_err;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($product['title']); ?> - CMS ART</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ================= ระบบ Dark/Light Mode ================= */
        :root {
            --bg-body-dark: linear-gradient(135deg, var(--ink) 0%, var(--accent) 100%);
            --bg-body-light: var(--paper); 
            --nav-bg-dark: var(--primary-purple, var(--accent));
            --nav-bg-light: var(--accent); 
            --text-logo-dark: #ffffff;
            --text-logo-light: #ffffff; 
        }

        body {
            background: var(--bg-body-dark) !important;
            transition: background 0.4s ease;
            font-family: 'Prompt', sans-serif;
        }
        .navbar {
            background: var(--nav-bg-dark) !important;
            transition: background 0.4s ease;
        }

        /* Light Theme Overrides */
        body[data-theme="light"] { background: var(--bg-body-light) !important; }
        body[data-theme="light"] .navbar { background: var(--nav-bg-light) !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05) !important; }
        body[data-theme="light"] .navbar-brand, 
        body[data-theme="light"] .navbar-nav .nav-link,
        body[data-theme="light"] .navbar-nav .text-white-50 { color: var(--text-logo-light) !important; }

        /* ปุ่มสลับโหมด */
        .theme-toggle-btn {
            background: rgba(255,255,255,0.2); color: white; border: none; width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; transition: 0.3s ease; cursor: pointer;
        }
        .theme-toggle-btn:hover { background: rgba(255,255,255,0.4); transform: rotate(15deg); }
        body[data-theme="light"] .theme-toggle-btn { background: rgba(255, 255, 255, 0.2); color: #ffffff; }
        body[data-theme="light"] .theme-toggle-btn:hover { background: rgba(255, 255, 255, 0.4); }

        .product-img-container { min-height: 75vh; }
        .desc-scroll::-webkit-scrollbar { width: 6px; }
        .desc-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .desc-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .cart-dropdown-toggle::after { display: none; }
    </style>
    <link rel="stylesheet" href="style.css?v=2">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark shadow-sm sticky-top">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="index.php"><i class="fas fa-palette me-2"></i>CMS ART</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse d-lg-flex" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">
                <?php if($uid > 0): ?>
                    <li class="nav-item me-3 text-white-50 small d-none d-lg-block">Welcome, <strong><?php echo htmlspecialchars($my_display_name); ?></strong></li>
                    
                    <!-- สลับโหมด Dark/Light -->
                    <li class="nav-item me-2">
                        <button id="themeToggle" class="theme-toggle-btn shadow-sm" title="สลับโหมดการแสดงผล">
                            <i class="fas fa-sun" id="themeIcon"></i>
                        </button>
                    </li>

                    <!-- ตะกร้าสินค้า -->
                    <li class="nav-item dropdown me-1">
                        <a class="nav-link rounded-circle d-flex align-items-center justify-content-center position-relative cart-dropdown-toggle" href="#" id="cartDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 40px; height: 40px; padding: 0; color: #ffffff; transition: background-color 0.3s ease;" onmouseover="this.style.backgroundColor='rgba(255,255,255,0.2)'" onmouseout="this.style.backgroundColor='transparent'" title="ตะกร้าสินค้า">
                            <i class="fas fa-shopping-cart" style="font-size: 1.1rem;"></i>
                            <?php if($cart_count > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem; border: 2px solid var(--primary-purple);">
                                    <?php echo $cart_count; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        
                        <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-2" aria-labelledby="cartDropdown" style="width: 320px; border-radius: 12px; margin-top: 10px;">
                            <li><h6 class="dropdown-header fw-bold text-dark border-bottom pb-2 mb-2"><i class="fas fa-shopping-basket me-2" style="color:#3B9797;"></i>ตะกร้าสินค้า (<?php echo $cart_count; ?>)</h6></li>
                            
                            <?php if(empty($cart_items)): ?>
                                <li><span class="dropdown-item text-center text-muted small py-3">ไม่มีรายการในตะกร้า</span></li>
                            <?php else: ?>
                                <div style="max-height: 280px; overflow-y: auto;">
                                <?php 
                                $total_price = 0;
                                foreach($cart_items as $item): 
                                    $is_avail = ($item['status'] == 'available');
                                    if($is_avail) $total_price += $item['price']; 
                                ?>
                                    <li class="dropdown-item d-flex align-items-center py-2 mb-1 border-bottom px-2" style="white-space: normal; <?php echo !$is_avail ? 'opacity: 0.6; background-color: #f8f9fa;' : ''; ?>">
                                        <img src="uploads/<?php echo f($item['preview_image']); ?>" class="rounded-2 me-2 border shadow-sm" style="width: 50px; height: 50px; object-fit: cover;">
                                        <div class="flex-grow-1" style="min-width: 0;">
                                            <a href="view_product.php?id=<?php echo $item['product_id']; ?>" class="text-dark text-decoration-none fw-bold" style="font-size: 0.85rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; white-space: normal;">
                                                <?php echo htmlspecialchars($item['title']); ?>
                                            </a>
                                            <?php if($is_avail): ?>
                                                <span class="text-success fw-bold small">฿<?php echo number_format($item['price']); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-danger small mt-1">สินค้าหมด</span>
                                            <?php endif; ?>
                                        </div>
                                        <a href="cart_remove.php?cart_id=<?php echo $item['cart_id']; ?>" class="btn btn-sm text-danger ms-2 rounded-circle" style="background: #fee2e2; border: none; font-size: 1.1rem; width: 35px; height: 35px; display: flex; align-items: center; justify-content: center;" title="ลบออก" onclick="return confirm('ยืนยันการลบรายการออกจากตะกร้า');">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                                </div>
                                <li>
                                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top px-2 mb-2">
                                        <span class="fw-bold text-muted small">ยอดรวม:</span>
                                        <span class="fw-bold text-success fs-5">฿<?php echo number_format($total_price); ?></span>
                                    </div>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </li>

                    <!-- แจ้งเตือน -->
                    <li class="nav-item dropdown me-2">
                        <a class="nav-link rounded-circle d-flex align-items-center justify-content-center position-relative" href="#" id="notifDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 40px; height: 40px; padding: 0; color: #ffffff; transition: background-color 0.3s ease;" onmouseover="this.style.backgroundColor='rgba(255,255,255,0.2)'" onmouseout="this.style.backgroundColor='transparent'" title="การแจ้งเตือน">
                            <i class="fas fa-bell" style="font-size: 1.1rem;"></i>
                            <?php if($unread_count > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem; border: 2px solid var(--primary-purple);">
                                    <?php echo $unread_count; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" aria-labelledby="notifDropdown" style="width: 300px; border-radius: 12px; margin-top: 10px;">
                            <li><h6 class="dropdown-header fw-bold text-dark border-bottom pb-2 mb-2">การแจ้งเตือน</h6></li>
                            <?php if(empty($notifs)): ?>
                                <li><span class="dropdown-item text-center text-muted small">ไม่มีการแจ้งเตือนใหม่</span></li>
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
                    </li>
                    
                    <?php if($my_role === 'admin'): ?>
                        <li class="nav-item me-2">
                            <a href="admin.php" class="btn btn-warning btn-sm rounded-pill px-3 fw-bold text-dark shadow-sm">
                                <i class="fas fa-cog me-1"></i> แดชบอร์ด
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- ปุ่มกลับหน้าหลัก -->
                <li class="nav-item ms-2">
                    <a href="index.php" class="btn btn-light btn-sm rounded-pill px-3 fw-bold text-purple shadow-sm">
                        <i class="fas fa-home me-1"></i> กลับหน้าหลัก
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid px-3 px-md-5 mt-4 mb-5">
    <div class="row justify-content-center">
        <div class="col-xl-10 col-lg-12">
            
            <?php if(isset($error_msg)): ?>
                <div class="alert alert-danger rounded-4 fw-bold shadow-sm mb-4"><i class="fas fa-exclamation-circle me-2"></i> <?php echo $error_msg; ?></div>
            <?php endif; ?>

            <?php if($product['status'] == 'censored'): ?>
                <div class="alert alert-danger rounded-4 fw-bold shadow-sm mb-4 text-center border-0" style="background-color: #fff0f0; color: #BF092F;">
                    <i class="fas fa-exclamation-triangle fa-lg me-2"></i> 
                    ผลงานนี้ถูกระงับการแสดงผล (เฉพาะคุณและผู้ดูแลระบบที่มองเห็นเนื้อหานี้)
                </div>
            <?php elseif($product['status'] == 'sold'): ?>
                <div class="alert alert-secondary rounded-4 fw-bold shadow-sm mb-4 text-center border-0">
                    <i class="fas fa-check-circle fa-lg me-2 text-success"></i> 
                    ผลงานนี้ถูกจำหน่ายแล้ว
                </div>
            <?php endif; ?>
            
            <div class="card border-0 shadow-lg rounded-4 overflow-hidden bg-white">
                <div class="row g-0">
                    <div class="col-md-7 bg-dark d-flex align-items-center justify-content-center p-4 position-relative product-img-container">
                        <img src="uploads/<?php echo f($product['preview_image']); ?>" class="img-fluid rounded shadow" alt="Preview" style="max-height: 85vh; width: 100%; object-fit: contain;">
                    </div>
                    
                    <div class="col-md-5">
                        <div class="card-body p-4 p-lg-5 d-flex flex-column h-100">
                            <h2 class="fw-bold mb-3 text-dark" style="line-height: 1.4;"><?php echo htmlspecialchars($product['title']); ?></h2>
                            <div class="d-flex align-items-center mb-4 pb-4 border-bottom">
                                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 50px; height: 50px;">
                                    <i class="fas fa-palette fa-lg" style="color: #3B9797;"></i>
                                </div>
                                <div>
                                    <span class="text-muted d-block small mb-1 fw-bold">สร้างสรรค์โดย</span>
                                    <h6 class="mb-0 fw-bold text-dark fs-5"><?php echo htmlspecialchars($product['artist_name']); ?></h6>
                                </div>
                            </div>
                            
                            <div class="mb-4 flex-grow-1 desc-scroll" style="overflow-y: auto; max-height: 250px; padding-right: 15px;">
                                <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-info-circle me-2"></i>รายละเอียด / เงื่อนไขการนำไปใช้</h6>
                                <div class="text-dark" style="line-height: 1.7; font-size: 0.95rem; white-space: pre-line;">
                                    <?php echo !empty($product['description']) ? htmlspecialchars($product['description']) : '<span class="text-muted fst-italic">ไม่ได้ระบุรายละเอียดเพิ่มเติม</span>'; ?>
                                </div>
                            </div>
                            
                            <div class="mt-auto border-top pt-4">
                                <div class="mb-4 d-flex justify-content-between align-items-center">
                                    <span class="text-muted fw-bold" style="font-size: 1.1rem;">ราคาผลงาน</span>
                                    <h1 class="fw-bold text-success mb-0">฿<?php echo number_format($product['price']); ?></h1>
                                </div>
                                
                                <?php if($uid == 0): ?>
                                    <a href="index.php" class="btn w-100 py-3 fw-bold btn-light border text-muted rounded-pill shadow-sm" style="font-size: 1.1rem;">เข้าสู่ระบบเพื่อสั่งซื้อ</a>
                                <?php elseif($uid == $product['artist_id']): ?>
                                    <div class="alert py-3 text-center rounded-pill mb-0 fw-bold shadow-sm" style="background-color: #e2e8f0; color: #16476A; font-size: 1.1rem;">
                                        <i class="fas fa-crown me-2 text-warning"></i> นี่คือผลงานของคุณ
                                    </div>
                                <?php elseif($product['status'] == 'available'): ?>
                                    <div class="d-flex gap-3">
                                        <a href="cart_action.php?id=<?php echo $product_id; ?>&type=product" class="btn w-50 py-3 fw-bold rounded-pill shadow-sm d-flex justify-content-center align-items-center" style="font-size: 1.1rem; color: #3B9797; background: white; border: 2px solid #3B9797; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                                            <i class="fas fa-cart-plus me-2"></i> ใส่ตะกร้า
                                        </a>
                                        <form method="POST" id="buyForm" class="w-50 m-0">
                                            <button type="submit" name="buy_product" class="btn w-100 py-3 fw-bold text-white rounded-pill shadow d-flex justify-content-center align-items-center" style="background-color: #3B9797; font-size: 1.1rem; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                                                <i class="fas fa-shopping-cart me-2"></i> ซื้อทันที
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    </script>

<?php if(isset($_SESSION['swal_success'])): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            title: '<span style="font-family: Prompt; font-weight: bold; color: #3B9797;">สำเร็จ</span>',
            text: <?php echo js_str($_SESSION['swal_success']); ?>,
            icon: 'success', iconColor: '#3B9797', timer: 2500, timerProgressBar: true, showConfirmButton: false,
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