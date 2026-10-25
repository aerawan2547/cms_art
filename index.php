<?php 
require_once __DIR__ . '/security.php'; 
include 'db.php'; 

$my_display_name = "Guest";
$my_role = ''; 

// --- เตรียมตัวแปรสำหรับแจ้งเตือนและตะกร้าสินค้า ---
$unread_count = 0;
$notifs = [];
$cart_count = 0;
$cart_items = [];

if(isset($_SESSION['user_id'])){
    $my_id = $_SESSION['user_id'];
    $my_display_name = $_SESSION['display_name'] ?? $_SESSION['username'];
    
    // Get user role for admin button check
    $user_q = $conn->query("SELECT role FROM users WHERE id = $my_id");
    if($user_q->num_rows > 0) {
        $my_role = $user_q->fetch_assoc()['role'];
    }

    // ดึงข้อมูลแจ้งเตือนของ user คนนี้
    $notif_q = $conn->query("SELECT * FROM notifications WHERE user_id = $my_id ORDER BY created_at DESC LIMIT 6");
    if($notif_q){
        while($n = $notif_q->fetch_assoc()) $notifs[] = $n;
        $unread_count = $conn->query("SELECT COUNT(*) FROM notifications WHERE user_id = $my_id AND is_read = 0")->fetch_row()[0];
    }
    
    // --- ป้องกัน Error: สร้างตารางตะกร้าสินค้าอัตโนมัติหากยังไม่มี ---
    $conn->query("CREATE TABLE IF NOT EXISTS cart_items (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        user_id INT, 
        product_id INT NULL, 
        service_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // ดึงข้อมูลตะกร้าสินค้าของ user คนนี้
    $cart_q = $conn->query("
        SELECT c.id as cart_id, p.id as product_id, p.title, p.price, p.preview_image, p.status 
        FROM cart_items c 
        JOIN products p ON c.product_id = p.id 
        WHERE c.user_id = $my_id 
        ORDER BY c.created_at DESC
    ");
    if($cart_q) {
        while($c = $cart_q->fetch_assoc()) $cart_items[] = $c;
        $cart_count = count($cart_items);
    }
}

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'services';
if (!in_array($active_tab, ['services', 'gallery', 'products'])) {
    $active_tab = 'services';
}

$saved_user = isset($_COOKIE['remember_user']) ? $_COOKIE['remember_user'] : ''; 
$is_remembered = !empty($saved_user) ? 'checked' : '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <script src="theme.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Art Commission Market</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* ================= ระบบ Dark/Light Mode ================= */
        :root {
            --bg-body-dark: linear-gradient(135deg, var(--ink) 0%, var(--accent) 100%);
            --bg-body-light: var(--paper); 
            --nav-bg-dark: var(--primary-purple, var(--accent));
            --nav-bg-light: var(--accent); 
            --text-logo-dark: #ffffff;
            --text-logo-light: #ffffff; 
            --hero-bg-dark: rgba(35, 11, 69, 0.7);
            --hero-bg-light: var(--wash); 
            --hero-text-dark: #ffffff;
            --hero-text-light: var(--ink);
        }

        body {
            background: var(--bg-body-dark) !important;
            transition: background 0.4s ease;
        }
        .navbar {
            background: var(--nav-bg-dark) !important;
            transition: background 0.4s ease;
        }
        .hero-banner {
            background: var(--hero-bg-dark);
            transition: background 0.4s ease;
        }
        .hero-banner h1, .hero-banner p {
            color: var(--hero-text-dark);
            transition: color 0.4s ease;
        }

        /* Light Theme Overrides */
        body[data-theme="light"] { background: var(--bg-body-light) !important; }
        body[data-theme="light"] .navbar { background: var(--nav-bg-light) !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05) !important; }
        body[data-theme="light"] .navbar-brand, 
        body[data-theme="light"] .navbar-nav .nav-link,
        body[data-theme="light"] .navbar-nav .text-white-50 { color: var(--text-logo-light) !important; }
        body[data-theme="light"] .navbar-toggler-icon { filter: invert(1); }
        body[data-theme="light"] .hero-banner { background: var(--hero-bg-light); box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08); }
        body[data-theme="light"] .hero-banner h1, 
        body[data-theme="light"] .hero-banner p { color: var(--hero-text-light); }
        body[data-theme="light"] .nav-pills .nav-link { color: var(--primary-purple); border: 1px solid var(--primary-purple); background-color: transparent;}
        body[data-theme="light"] .nav-pills .nav-link:hover:not(.active) { background-color: rgba(93, 47, 119, 0.1); }
        body[data-theme="light"] .nav-pills .nav-link.active { background-color: var(--accent-pink); color: #ffffff; border-color: transparent; }
        body[data-theme="light"] .empty-state { color: #6c757d; }
        body[data-theme="light"] .navbar-search-group { border: 1px solid #ced4da; }
        body[data-theme="light"] .navbar-search-group:focus-within { border-color: var(--accent-pink, var(--hot)); }

        /* ปุ่มสลับโหมด */
        .theme-toggle-btn {
            background: rgba(255,255,255,0.2); color: white; border: none; width: 40px; height: 40px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; transition: 0.3s ease; cursor: pointer;
        }
        .theme-toggle-btn:hover { background: rgba(255,255,255,0.4); transform: rotate(15deg); }
        body[data-theme="light"] .theme-toggle-btn { background: rgba(255, 255, 255, 0.2); color: #ffffff; }
        body[data-theme="light"] .theme-toggle-btn:hover { background: rgba(255, 255, 255, 0.4); }

        /* ส่วนอื่นๆ (Gallery, Modals, ฯลฯ) */
        .gallery-img-wrapper { position: relative; overflow: hidden; background: #000; border-radius: 10px; }
        .img-censored { filter: blur(25px); pointer-events: none; user-select: none; transition: 0.3s; opacity: 0.7; transform: scale(1.1); }
        .censored-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; background: rgba(0,0,0,0.4); color: white; z-index: 10; cursor: pointer; transition: 0.3s; }
        .censored-overlay:hover { background: rgba(0,0,0,0.6); }
        .close-eye-btn { position: absolute; top: 15px; right: 15px; z-index: 20; background: rgba(255, 255, 255, 0.9); color: #333; border: none; border-radius: 50%; width: 35px; height: 35px; display: none; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.3); transition: 0.2s;}
        .close-eye-btn:hover { background: #fff; transform: scale(1.1); color: var(--accent-pink, var(--hot)); }
        .is-revealed .img-censored { filter: none; opacity: 1; transform: scale(1); pointer-events: auto; }
        .is-revealed .censored-overlay { opacity: 0; pointer-events: none; }
        .is-revealed .close-eye-btn { display: flex; }

        .auth-modal-content { border-radius: 24px; border: none; box-shadow: 0 25px 50px rgba(0,0,0,0.2); }
        .auth-tabs { background-color: #f0f2f5; border-radius: 50px; padding: 6px; display: flex; gap: 5px; border: none; }
        .auth-tabs .nav-link { border-radius: 50px; color: #6c757d; font-weight: 700; border: none; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); padding: 12px 20px; }
        .auth-tabs .nav-link:hover:not(.active) { color: var(--primary-purple, var(--accent)); background-color: rgba(255,255,255,0.5); }
        .auth-tabs .nav-link.active { background-color: white; color: var(--primary-purple, var(--accent)); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .auth-input-group { background-color: #f4f5f9; border: 2px solid transparent; border-radius: 16px; overflow: hidden; transition: 0.3s; }
        .auth-input-group:focus-within { border-color: var(--primary-purple, var(--accent)); background-color: #ffffff; box-shadow: 0 0 0 4px rgba(93, 47, 119, 0.1); }
        .auth-input-group input { background-color: transparent !important; border: none; box-shadow: none !important; color: #333 !important; font-weight: 600; padding: 14px 15px; }
        .auth-input-group .input-group-text { background-color: transparent !important; border: none; color: var(--primary-purple, var(--accent)); padding-left: 1.5rem; }
        .artist-check-box { background: #fff5f8; border: 2px dashed var(--accent-pink, var(--hot)); border-radius: 16px; transition: 0.3s; }
        .artist-check-box:hover { background: #ffeaf1; }
        .btn-auth { border-radius: 16px; font-size: 1.1rem; padding: 12px; transition: 0.3s; border: none; }
        .btn-auth:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.15); }

        #backToTopBtn { position: fixed; bottom: 30px; right: 30px; z-index: 99; border: none; outline: none; background-color: var(--accent-pink, var(--hot)); color: white; cursor: pointer; width: 50px; height: 50px; border-radius: 50%; font-size: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); transition: all 0.3s ease; opacity: 0; visibility: hidden; display: flex; align-items: center; justify-content: center; }
        #backToTopBtn.show { opacity: 1; visibility: visible; }
        #backToTopBtn:hover { background-color: var(--primary-purple, var(--accent)); transform: translateY(-5px); box-shadow: 0 6px 15px rgba(0,0,0,0.3); }

        .navbar-search-form { flex-grow: 1; margin: 0 3vw; }
        .navbar-search-group { background-color: #fff; border-radius: 30px; overflow: hidden; border: 2px solid transparent; transition: all 0.3s ease; }
        .navbar-search-group:focus-within { border-color: var(--accent-pink, var(--hot)); box-shadow: 0 0 0 4px rgba(228, 90, 146, 0.2); }
        .navbar-search-group input { background-color: transparent !important; border: none; box-shadow: none !important; font-size: 1rem; padding: 12px 20px 12px 5px; color: #333; }
        .navbar-search-group .input-group-text { background-color: transparent !important; border: none; color: #6c757d; padding-left: 20px; padding-right: 10px; }
        .cart-dropdown-toggle::after { display: none; }
    </style>
    <link rel="stylesheet" href="style.css?v=2">
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark shadow-sm sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php"><i class="fas fa-palette me-2"></i>CMS ART</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse d-lg-flex" id="navbarNav">
                
                <form class="d-flex navbar-search-form my-2 my-lg-0" onsubmit="return false;">
                    <div class="input-group shadow-sm navbar-search-group w-100">
                        <span class="input-group-text bg-transparent border-0 text-muted">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" id="liveSearchInput" class="form-control" placeholder="ค้นหาผลงาน, ชื่อศิลปิน, คำอธิบาย...">
                    </div>
                </form>
                <ul class="navbar-nav ms-auto align-items-center">
                    <?php if(isset($_SESSION['user_id'])): ?>
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

                        <li class="nav-item"><a href="profile.php" class="btn btn-light btn-sm rounded-pill px-3 me-2 fw-bold text-purple shadow-sm"><i class="fas fa-user-circle me-1"></i> โปรไฟล์</a></li>
                        <li class="nav-item"><a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3"><i class="fas fa-sign-out-alt"></i></a></li>
                    <?php else: ?>
                        <li class="nav-item"><button class="btn btn-light rounded-pill px-4 shadow-sm fw-bold text-purple" data-bs-toggle="modal" data-bs-target="#authModal"><i class="fas fa-sign-in-alt me-2"></i>เข้าสู่ระบบ</button></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <?php
    // ผนังแกลเลอรีในหน้าแรก: ดึงผลงานล่าสุดจากแกลเลอรีจริง (ไม่รวมภาพที่ถูกปิดกั้น)
    $wall = [];
    $wq = $conn->query("SELECT i.image_path FROM post_images i WHERE i.status <> 'censored' ORDER BY i.id DESC LIMIT 6");
    if ($wq) { while ($w = $wq->fetch_assoc()) $wall[] = $w['image_path']; }
    ?>
    <header class="hero-banner">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <h1 class="hero-title">ผลงานจากมือศิลปิน จ้างวาดหรือเลือกซื้อได้ในที่เดียว</h1>
                    <p class="hero-lead">เปิดแกลเลอรี สั่งงานตามใจ หรือรับภาพสำเร็จรูปจากศิลปินอิสระ</p>
                    <div class="hero-actions">
                        <?php if(!isset($_SESSION['user_id'])): ?>
                            <button class="btn btn-light-main btn-lg px-4" data-bs-toggle="modal" data-bs-target="#authModal"><i class="fas fa-right-to-bracket me-2"></i>เข้าสู่ระบบ</button>
                            <a href="#explore" class="btn btn-outline-secondary btn-lg px-4">ดูผลงาน</a>
                        <?php else: ?>
                            <a href="#explore" class="btn btn-light-main btn-lg px-4">ดูผลงาน <i class="fas fa-arrow-down ms-2"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="hero-wall" aria-label="ผลงานล่าสุดจากแกลเลอรี">
                        <?php for ($w = 0; $w < 6; $w++): ?>
                            <figure class="wall-piece wall-<?php echo $w + 1; ?><?php echo isset($wall[$w]) ? '' : ' wall-empty'; ?>">
                                <?php if (isset($wall[$w])): ?><img src="uploads/<?php echo f($wall[$w]); ?>" alt="ผลงานล่าสุด" loading="lazy"><?php endif; ?>
                            </figure>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="container pb-5" id="explore">
        <ul class="nav nav-pills mb-4 justify-content-center" id="pills-tab">
            <li class="nav-item">
                <button class="nav-link rounded-pill px-4 <?php echo ($active_tab=='services')?'active':''; ?>" data-bs-toggle="pill" data-bs-target="#pills-services">
                    <i class="fas fa-search-dollar me-2"></i>งานจ้าง (Services)
                </button>
            </li>
            <li class="nav-item ms-2">
                <button class="nav-link rounded-pill px-4 <?php echo ($active_tab=='products')?'active':''; ?>" data-bs-toggle="pill" data-bs-target="#pills-products">
                    <i class="fas fa-shopping-cart me-2"></i>ภาพสำเร็จรูป (Products)
                </button>
            </li>
            <li class="nav-item ms-2">
                <button class="nav-link rounded-pill px-4 <?php echo ($active_tab=='gallery')?'active':''; ?>" data-bs-toggle="pill" data-bs-target="#pills-gallery">
                    <i class="fas fa-images me-2"></i>แกลเลอรี (Showcase)
                </button>
            </li>
        </ul>

        <div class="tab-content" id="pills-tabContent">
            
            <div class="tab-pane fade <?php echo ($active_tab=='services')?'show active':''; ?>" id="pills-services">
                <div class="row g-4">
                    <?php
                    $sql = "SELECT s.*, a.display_name, u.profile_image FROM commission_services s JOIN artist_profiles a ON s.artist_id = a.user_id JOIN users u ON a.user_id = u.id WHERE s.status = 'active' ORDER BY s.created_at DESC";
                    $result = $conn->query($sql);
                    if($result->num_rows > 0):
                        while($row = $result->fetch_assoc()):
                            $img = $conn->query("SELECT image_path FROM service_images WHERE service_id = {$row['id']} LIMIT 1")->fetch_assoc();
                            $cover = $img ? "uploads/" . f($img['image_path']) : "assets/placeholder.svg";
                            $artist_avatar = !empty($row['profile_image']) ? "uploads/" . f($row['profile_image']) : "assets/placeholder.svg";
                    ?>
                    <div class="col-md-6 col-lg-3">
                        <div class="service-card h-100 position-relative">
                            <div class="service-img-container">
                                <img src="<?php echo $cover; ?>" class="service-img-top">
                                <div class="artist-badge"><img src="<?php echo $artist_avatar; ?>" class="artist-mini-avatar"><span class="text-truncate" style="max-width: 100px;"><?php echo htmlspecialchars($row['display_name']); ?></span></div>
                            </div>
                            <div class="card-body p-3">
                                <h5 class="card-title fw-bold text-dark mb-1 text-truncate"><?php echo htmlspecialchars($row['title']); ?></h5>
                                <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                                    <span class="price-tag">฿<?php echo number_format($row['base_price']); ?></span>
                                    <a href="view_service.php?id=<?php echo $row['id']; ?>" class="btn btn-outline-purple rounded-pill btn-sm px-3 fw-bold">จ้างงาน</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; else: echo '<div class="col-12 text-center py-5 text-muted fw-bold">ยังไม่มีประกาศรับงานจ้าง</div>'; endif; ?>
                </div>
            </div>

            <div class="tab-pane fade <?php echo ($active_tab=='products')?'show active':''; ?>" id="pills-products">
                <div class="row g-4">
                    <?php
                    $prod_sql = "SELECT p.*, a.display_name, u.profile_image 
                                 FROM products p 
                                 JOIN artist_profiles a ON p.artist_id = a.user_id 
                                 JOIN users u ON a.user_id = u.id 
                                 WHERE p.status = 'available' 
                                 ORDER BY p.created_at DESC";
                    $prod_res = $conn->query($prod_sql);
                    if($prod_res && $prod_res->num_rows > 0):
                        while($prod = $prod_res->fetch_assoc()):
                            $artist_avatar = !empty($prod['profile_image']) ? "uploads/" . f($prod['profile_image']) : "assets/placeholder.svg";
                    ?>
                    <div class="col-md-6 col-lg-3">
                        <div class="service-card h-100 position-relative">
                            <a href="view_product.php?id=<?php echo $prod['id']; ?>" class="text-decoration-none">
                                <div class="service-img-container">
                                    <img src="uploads/<?php echo f($prod['preview_image']); ?>" class="service-img-top" style="object-fit: cover;">
                                    <div class="artist-badge"><img src="<?php echo $artist_avatar; ?>" class="artist-mini-avatar"><span class="text-truncate" style="max-width: 100px; text-decoration: none; color: var(--dark-purple);"><?php echo htmlspecialchars($prod['display_name']); ?></span></div>
                                </div>
                            </a>
                            <div class="card-body p-3">
                                <a href="view_product.php?id=<?php echo $prod['id']; ?>" class="text-decoration-none text-dark">
                                    <h5 class="card-title fw-bold mb-1 text-truncate hover-pink"><?php echo htmlspecialchars($prod['title']); ?></h5>
                                </a>
                                <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                                    <span class="price-tag">฿<?php echo number_format($prod['price']); ?></span>
                                    <a href="view_product.php?id=<?php echo $prod['id']; ?>" class="btn btn-pink rounded-pill btn-sm px-3 fw-bold shadow-sm"><i class="fas fa-shopping-cart me-1"></i> ดูรายละเอียด</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; else: echo '<div class="col-12 text-center py-5 text-muted fw-bold">ยังไม่มีภาพสำเร็จรูปวางจำหน่าย</div>'; endif; ?>
                </div>
            </div>

            <div class="tab-pane fade <?php echo ($active_tab=='gallery')?'show active':''; ?>" id="pills-gallery">
                <div class="row g-4 justify-content-center">
                    <?php
                    $post_sql = "SELECT p.*, a.display_name, u.profile_image 
                                 FROM artist_posts p 
                                 JOIN artist_profiles a ON p.artist_id = a.user_id 
                                 JOIN users u ON a.user_id = u.id 
                                 ORDER BY p.created_at DESC";
                    $post_res = $conn->query($post_sql);

                    if($post_res && $post_res->num_rows > 0):
                        while($post = $post_res->fetch_assoc()):
                            $pid = $post['id'];
                            $artist_avatar = !empty($post['profile_image']) ? "uploads/" . f($post['profile_image']) : "assets/placeholder.svg";
                            $img_res = $conn->query("SELECT * FROM post_images WHERE post_id = $pid");
                            $images = []; while($r = $img_res->fetch_assoc()) $images[] = $r;
                            $count = count($images);

                            $likes_count = $conn->query("SELECT COUNT(*) FROM post_likes WHERE post_id = $pid")->fetch_row()[0];
                            $is_liked = false;
                            if(isset($_SESSION['user_id'])){
                                $chk_like = $conn->query("SELECT id FROM post_likes WHERE post_id = $pid AND user_id = {$_SESSION['user_id']}");
                                if($chk_like->num_rows > 0) $is_liked = true;
                            }
                            $heart_icon = $is_liked ? "fas fa-heart text-danger" : "far fa-heart";
                            $heart_class = $is_liked ? "text-danger fw-bold" : "text-muted";

                            $comments_q = $conn->query("SELECT c.*, u.username, u.profile_image FROM post_comments c JOIN users u ON c.user_id = u.id WHERE c.post_id = $pid ORDER BY c.created_at ASC");
                            $comment_count = $comments_q->num_rows;
                    ?>
                    
                    <div class="col-12 col-lg-8">
                        <div class="card border-0 shadow-sm rounded-4 mb-5 overflow-hidden" id="post-<?php echo $pid; ?>">
                            <div class="p-3 bg-white d-flex align-items-center border-bottom">
                                <img src="<?php echo $artist_avatar; ?>" class="rounded-circle me-3" width="45" height="45" style="object-fit:cover;">
                                <div><h6 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($post['display_name']); ?></h6><small class="text-muted"><?php echo date('d M Y • H:i', strtotime($post['created_at'])); ?></small></div>
                            </div>
                            
                            <?php if($post['caption']): ?>
                            <div class="px-3 pt-3 pb-2 bg-white">
                                <p class="mb-0 text-dark" style="font-size: 1rem;"><?php echo nl2br(htmlspecialchars($post['caption'])); ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($count > 0): ?>
                            <div id="carouselPost<?php echo $pid; ?>" class="carousel slide bg-light" data-bs-interval="false">
                                <?php if($count > 1): ?>
                                <div class="carousel-indicators">
                                    <?php for($i=0; $i<$count; $i++): ?>
                                        <button type="button" data-bs-target="#carouselPost<?php echo $pid; ?>" data-bs-slide-to="<?php echo $i; ?>" class="<?php echo ($i==0)?'active':''; ?>"></button>
                                    <?php endfor; ?>
                                </div>
                                <?php endif; ?>

                                <div class="carousel-inner">
                                    <?php foreach($images as $idx => $img_data): 
                                        $img_path = "uploads/" . f($img_data['image_path']);
                                        $is_censored = ($img_data['status'] == 'censored');
                                        $uniq_id = "img_" . $img_data['id'];
                                    ?>
                                    <div class="carousel-item <?php echo ($idx==0)?'active':''; ?>">
                                        <div class="gallery-img-wrapper" id="wrap_<?php echo $uniq_id; ?>">
                                            
                                            <?php if($is_censored): ?>
                                                <button class="close-eye-btn" onclick="toggleBlur('wrap_<?php echo $uniq_id; ?>')" title="กลับไปเบลอภาพ"><i class="fas fa-eye-slash"></i></button>
                                                <div class="censored-overlay" onclick="toggleBlur('wrap_<?php echo $uniq_id; ?>')">
                                                    <i class="fas fa-eye fa-2x mb-2"></i>
                                                    <span class="fw-bold small">เนื้อหาถูกซ่อน</span>
                                                    <small style="font-size: 0.7rem; opacity: 0.8;">แตะเพื่อดูรูป</small>
                                                </div>
                                                <img src="<?php echo $img_path; ?>" class="d-block gallery-img img-censored">
                                            <?php else: ?>
                                                <img src="<?php echo $img_path; ?>" class="d-block gallery-img">
                                            <?php endif; ?>
                                            
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if($count > 1): ?>
                                <button class="carousel-control-prev" type="button" data-bs-target="#carouselPost<?php echo $pid; ?>" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span></button>
                                <button class="carousel-control-next" type="button" data-bs-target="#carouselPost<?php echo $pid; ?>" data-bs-slide="next"><span class="carousel-control-next-icon"></span></button>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- ฟังก์ชันแสดงจำนวนไลก์และปุ่มคอมเมนต์ -->
                            <div class="p-2 bg-light border-top d-flex justify-content-between align-items-center px-3">
                                <a href="like_action.php?id=<?php echo $pid; ?>" class="btn btn-light rounded-pill btn-sm px-3 <?php echo $heart_class; ?>">
                                    <i class="<?php echo $heart_icon; ?> me-1"></i> <?php echo $likes_count; ?> ถูกใจ
                                </a>
                                <button class="btn btn-light text-muted rounded-pill btn-sm px-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapseComments<?php echo $pid; ?>">
                                    <i class="far fa-comment me-1"></i> <?php echo $comment_count; ?> คอมเมนต์
                                </button>
                            </div>

                            <!-- ส่วนแสดงลิสต์คอมเมนต์และฟอร์มพิมพ์คอมเมนต์ -->
                            <div class="collapse bg-white border-top" id="collapseComments<?php echo $pid; ?>">
                                <div class="p-3">
                                    <div class="comment-list mb-3" style="max-height: 250px; overflow-y: auto; padding-right: 5px;">
                                        <?php if($comment_count > 0): while($com = $comments_q->fetch_assoc()): ?>
                                            <div class="d-flex mb-3">
                                                <img src="<?php echo !empty($com['profile_image'])?"uploads/" . f($com['profile_image']):"assets/placeholder.svg"; ?>" class="rounded-circle me-2 shadow-sm" width="35" height="35" style="object-fit:cover;">
                                                <div class="d-flex flex-column">
                                                    <div class="bg-light p-2 px-3 rounded-4 shadow-sm" style="width: fit-content; max-width: 100%;">
                                                        <span class="fw-bold small d-block text-dark mb-1"><?php echo htmlspecialchars($com['username']); ?></span>
                                                        <?php if(!empty($com['comment_text'])): ?>
                                                            <span class="small text-dark d-block"><?php echo htmlspecialchars($com['comment_text']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if(!empty($com['image_path'])): ?>
                                                            <div class="mt-2">
                                                                <img src="uploads/<?php echo f($com['image_path']); ?>" class="rounded-3 border shadow-sm" style="max-height: 150px; max-width: 100%; object-fit: contain; cursor: pointer;" onclick="window.open(this.src)">
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <small class="text-muted ms-2 mt-1" style="font-size: 0.65rem;"><?php echo date('d/m/Y H:i', strtotime($com['created_at'])); ?></small>
                                                </div>
                                            </div>
                                        <?php endwhile; else: echo '<small class="text-muted d-block text-center mb-2">ไม่มีข้อความแสดงความคิดเห็น</small>'; endif; ?>
                                    </div>

                                    <?php if(isset($_SESSION['user_id'])): ?>
                                    <form action="comment_action.php" method="POST" enctype="multipart/form-data" class="mt-2">
                                        <input type="hidden" name="post_id" value="<?php echo $pid; ?>">
                                        <div id="preview_container_<?php echo $pid; ?>" class="mb-2 position-relative" style="display: none; width: fit-content;">
                                            <img id="preview_img_<?php echo $pid; ?>" src="" class="rounded-3 border shadow-sm" style="max-height: 100px; object-fit: cover;">
                                            <button type="button" class="btn btn-danger btn-sm rounded-circle position-absolute top-0 start-100 translate-middle shadow" onclick="cancelCommentImage(<?php echo $pid; ?>)" style="width: 22px; height: 22px; padding: 0; display: flex; align-items: center; justify-content: center; font-size: 10px;" title="ลบรูปภาพ">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <label class="btn btn-light rounded-circle text-secondary d-flex align-items-center justify-content-center shadow-sm border m-0" style="width: 38px; height: 38px; cursor: pointer; flex-shrink: 0;" title="แนบรูปภาพ">
                                                <i class="fas fa-image"></i>
                                                <input type="file" name="comment_image" id="cmt_img_<?php echo $pid; ?>" hidden accept="image/*" onchange="previewCommentImage(this, <?php echo $pid; ?>)">
                                            </label>
                                            <input type="text" name="comment_text" class="form-control rounded-pill py-2 px-3 fw-bold" style="border: 2px solid #aeb8ff; background-color: #f8f9ff; font-size: 0.9rem;" placeholder="แสดงความคิดเห็น..." autocomplete="off">
                                            <button type="submit" class="btn rounded-circle shadow-sm d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; background-color: var(--primary-purple); color: white; border: none; flex-shrink: 0;">
                                                <i class="fas fa-paper-plane"></i>
                                            </button>
                                        </div>
                                    </form>
                                    <?php else: ?>
                                        <div class="text-center mt-3 border-top pt-3">
                                            <small class="text-muted"><a href="#" data-bs-toggle="modal" data-bs-target="#authModal" class="fw-bold text-purple text-decoration-none">เข้าสู่ระบบ</a> เพื่อร่วมแสดงความคิดเห็น</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>
                    </div>
                    <?php endwhile; else: echo '<div class="col-12 text-center py-5 text-muted fw-bold">ยังไม่มีผลงานแสดงในแกลเลอรี</div>'; endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Auth Modal -->
    <div class="modal fade" id="authModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content auth-modal-content bg-white">
                <div class="modal-body p-4 p-md-5 position-relative">
                    
                    <button type="button" class="btn-close position-absolute top-0 end-0 m-4 shadow-none" data-bs-dismiss="modal"></button>
                    
                    <ul class="nav nav-pills nav-fill auth-tabs mb-4" id="pills-tab">
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#login-tab">เข้าสู่ระบบ</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#register-tab">สมัครสมาชิก</button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="login-tab">
                            <h3 class="fw-bold text-center mb-4" style="color: var(--dark-purple, #3E1E68);">เข้าสู่ระบบ</h3>
                            <form action="auth.php" method="POST">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold mb-1" style="color: #6c757d;">ชื่อผู้ใช้ (Username)</label>
                                    <div class="input-group auth-input-group">
                                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                                        <input type="text" name="username" class="form-control" placeholder="กรอกชื่อผู้ใช้งาน" value="<?php echo htmlspecialchars($saved_user); ?>" required>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold mb-1" style="color: #6c757d;">รหัสผ่าน (Password)</label>
                                    <div class="input-group auth-input-group">
                                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                        <input type="password" name="password" class="form-control" placeholder="กรอกรหัสผ่าน" required>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-4 px-2">
                                    <div class="form-check">
                                        <input class="form-check-input shadow-none" type="checkbox" name="remember" id="rememberMe" <?php echo $is_remembered; ?> style="cursor: pointer;">
                                        <label class="form-check-label small fw-bold text-dark" for="rememberMe" style="cursor: pointer;">จำรหัสผ่าน</label>
                                    </div>
                                    <a href="forgot_password.php" class="small text-decoration-none fw-bold" style="color: var(--accent-pink, var(--hot));">ลืมรหัสผ่าน?</a>
                                </div>
                                <button type="submit" name="login" class="btn btn-auth w-100 text-white fw-bold shadow-sm" style="background-color: var(--primary-purple, var(--accent));">
                                    <i class="fas fa-sign-in-alt me-2"></i> เข้าสู่ระบบ
                                </button>
                            </form>
                        </div>

                        <div class="tab-pane fade" id="register-tab">
                            <h3 class="fw-bold text-center mb-4" style="color: var(--dark-purple, #3E1E68);">สร้างบัญชีใหม่</h3>
                            <form action="auth.php" method="POST">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold mb-1 text-dark">ชื่อ-นามสกุล</label>
                                    <div class="input-group auth-input-group">
                                        <span class="input-group-text"><i class="fas fa-id-badge"></i></span>
                                        <input type="text" name="full_name" class="form-control" placeholder="ระบุชื่อ-นามสกุล" required>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold mb-1 text-dark">Username (สำหรับล็อกอิน)</label>
                                    <div class="input-group auth-input-group">
                                        <span class="input-group-text"><i class="fas fa-user-circle"></i></span>
                                        <input type="text" name="username" class="form-control" placeholder="ภาษาอังกฤษ เช่น somchai01" required>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold mb-1 text-dark">อีเมล</label>
                                    <div class="input-group auth-input-group">
                                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                        <input type="email" name="email" class="form-control" placeholder="example@email.com" required>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold mb-1 text-dark">รหัสผ่าน</label>
                                    <div class="input-group auth-input-group">
                                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                        <input type="password" name="password" class="form-control" placeholder="ตั้งรหัสผ่าน อย่างน้อย 8 ตัวอักษร" required minlength="8" maxlength="72" autocomplete="new-password">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold mb-1 text-dark">ยืนยันรหัสผ่าน</label>
                                    <div class="input-group auth-input-group">
                                        <span class="input-group-text"><i class="fas fa-check-circle"></i></span>
                                        <input type="password" name="confirm_password" class="form-control" placeholder="กรอกรหัสผ่านอีกครั้ง" required minlength="8" maxlength="72" autocomplete="new-password">
                                    </div>
                                </div>
                                <div class="form-check mb-4 p-3 artist-check-box d-flex align-items-center">
                                    <input class="form-check-input ms-1 shadow-none me-3" type="checkbox" name="role_type" value="artist" id="isArtist" style="cursor: pointer; transform: scale(1.3);">
                                    <label class="form-check-label small fw-bold m-0" for="isArtist" style="color: var(--accent-pink, var(--hot)); cursor: pointer;">
                                        🎨 สมัครเป็นศิลปิน <span class="text-dark fw-normal">(เพื่อเปิดรับงานจ้าง)</span>
                                    </label>
                                </div>
                                <button type="submit" name="register" class="btn btn-auth w-100 text-white fw-bold shadow-sm" style="background-color: var(--accent-pink, var(--hot));">
                                    <i class="fas fa-user-plus me-2"></i> สมัครสมาชิก
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <button id="backToTopBtn" title="กลับขึ้นด้านบน">
        <i class="fas fa-chevron-up"></i>
    </button>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // ฟังก์ชันระบบแกลเลอรีและการแสดงคอมเมนต์
        function previewCommentImage(input, postId) {
            const previewContainer = document.getElementById('preview_container_' + postId);
            const previewImg = document.getElementById('preview_img_' + postId);
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    previewContainer.style.display = 'block';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function cancelCommentImage(postId) {
            document.getElementById('cmt_img_' + postId).value = '';
            document.getElementById('preview_container_' + postId).style.display = 'none';
            document.getElementById('preview_img_' + postId).src = '';
        }

        function toggleBlur(wrapperId) {
            var wrapper = document.getElementById(wrapperId);
            if(wrapper) {
                wrapper.classList.toggle('is-revealed');
            }
        }

        const backToTopBtn = document.getElementById("backToTopBtn");
        
        window.addEventListener('scroll', function() {
            if (window.scrollY > 300) { 
                backToTopBtn.classList.add("show");
            } else {
                backToTopBtn.classList.remove("show");
            }
        });

        backToTopBtn.addEventListener("click", function() {
            window.scrollTo({ top: 0, behavior: "smooth" });
        });

        // ค้นหาข้อมูล (Live Search)
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('liveSearchInput');
            if(searchInput) {
                searchInput.addEventListener('input', function(e) {
                    const term = e.target.value.toLowerCase().trim();

                    document.querySelectorAll('#pills-services > .row > div').forEach(item => {
                        const text = item.innerText.toLowerCase();
                        item.style.display = text.includes(term) ? '' : 'none';
                    });

                    document.querySelectorAll('#pills-products > .row > div').forEach(item => {
                        const text = item.innerText.toLowerCase();
                        item.style.display = text.includes(term) ? '' : 'none';
                    });

                    document.querySelectorAll('#pills-gallery > .row > div').forEach(item => {
                        const text = item.innerText.toLowerCase();
                        item.style.display = text.includes(term) ? '' : 'none';
                    });
                });
            }
        });
    </script>

    <?php if(isset($_SESSION['swal_success'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                title: '<span style="font-family: Prompt; font-weight: bold; color: #3B9797;">สำเร็จ</span>',
                text: <?php echo js_str($_SESSION['swal_success']); ?>,
                icon: 'success',
                iconColor: '#3B9797',
                customClass: { popup: 'rounded-4 shadow-lg border-0' },
                timer: 2500,
                timerProgressBar: true,
                showConfirmButton: false
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
                icon: 'warning',
                iconColor: '#BF092F',
                customClass: { popup: 'rounded-4 shadow-lg border-0' }
            });
        });
    </script>
    <?php unset($_SESSION['swal_error']); endif; ?>

</body>
</html>