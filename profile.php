<?php 
require_once __DIR__ . '/security.php'; 
include 'db.php'; 

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$conn->query("CREATE TABLE IF NOT EXISTS service_favorites (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, service_id INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

$uid = $_SESSION['user_id'];
$user = $conn->query("SELECT * FROM users WHERE id = $uid")->fetch_assoc();
$artist_q = $conn->query("SELECT * FROM artist_profiles WHERE user_id = $uid");
$is_artist = ($artist_q->num_rows > 0);
$artist_data = $is_artist ? $artist_q->fetch_assoc() : null;
$display_name = $is_artist ? $artist_data['display_name'] : ($user['full_name'] ?? $user['username']);
$bio_text = $is_artist ? ($artist_data['bio'] ?? '') : '';
$avatar = !empty($user['profile_image']) ? "uploads/" . f($user['profile_image']) : "assets/placeholder.svg";
$cover_bg = !empty($user['cover_image']) ? "url('uploads/".$user['cover_image']."')" : "linear-gradient(135deg, var(--primary-purple), var(--accent-pink))"; 

$unread_count = 0; $notifs = [];
$notif_q = $conn->query("SELECT * FROM notifications WHERE user_id = $uid ORDER BY created_at DESC LIMIT 6");
if($notif_q) { while($n = $notif_q->fetch_assoc()) $notifs[] = $n; $unread_count = $conn->query("SELECT COUNT(*) FROM notifications WHERE user_id = $uid AND is_read = 0")->fetch_row()[0]; }

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'favorites';
if (!in_array($active_tab, ['favorites', 'services', 'gallery'])) $active_tab = 'favorites';
if (!$is_artist && in_array($active_tab, ['services', 'gallery'])) $active_tab = 'favorites';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <script src="theme.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profile - CMS ART</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root { --bg-body-dark: linear-gradient(135deg, var(--ink) 0%, var(--accent) 100%); --bg-body-light: var(--paper); --nav-bg-dark: var(--accent); --nav-bg-light: var(--accent); --text-light-mode: var(--ink); }
        body { background: var(--bg-body-dark) !important; transition: background 0.4s ease; font-family: 'Prompt', sans-serif; }
        .navbar { background: var(--nav-bg-dark) !important; transition: background 0.4s ease; }
        
        body[data-theme="light"] { background: var(--bg-body-light) !important; }
        body[data-theme="light"] .navbar { background: var(--nav-bg-light) !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05) !important; }
        .theme-toggle-btn { background: rgba(255,255,255,0.2); color: white; border: none; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        body[data-theme="light"] .theme-toggle-btn { background: rgba(255, 255, 255, 0.2); color: #ffffff; }

        body[data-theme="light"] .fb-profile-info h2, body[data-theme="light"] .fb-profile-info p { color: var(--text-light-mode) !important; text-shadow: none !important; }
        body[data-theme="light"] h4.text-white, body[data-theme="light"] h5.text-white { color: var(--text-light-mode) !important; text-shadow: none !important; }

        .fb-profile-header { background: transparent; padding-bottom: 2rem; margin-bottom: 2rem; }
        .fb-cover-photo { height: 350px; background-size: cover; background-position: center; position: relative; border-radius: 0 0 20px 20px; background-image: <?php echo $cover_bg; ?>; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .fb-profile-container { max-width: 1100px; margin: 0 auto; padding: 0 1.5rem; position: relative; }
        .fb-profile-avatar-wrapper { position: absolute; bottom: -60px; left: 40px; padding: 5px; background: rgba(255,255,255,0.2); backdrop-filter: blur(10px); border-radius: 50%; z-index: 10; box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
        .fb-profile-avatar { width: 160px; height: 160px; border-radius: 50%; object-fit: cover; border: 4px solid white; }
        .fb-profile-info { padding-top: 1.5rem; padding-left: 230px; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; }
        
        .camera-btn { position: absolute; background: rgba(255,255,255,0.9); color: var(--primary-purple); border: none; border-radius: 50%; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.3s; box-shadow: 0 4px 10px rgba(0,0,0,0.15); }
        .cover-camera { bottom: 20px; right: 20px; }
        .avatar-camera { bottom: 5px; right: 5px; }

        .btn-heart { background-color: white; color: #ccc; border: 1px solid #eee; transition: 0.3s; display: flex; align-items: center; justify-content: center;}
        .btn-heart.liked { color: var(--accent-pink, var(--hot)); border-color: var(--accent-pink, var(--hot)); background-color: #fff0f5; }
        
        .custom-pills .nav-link { background-color: transparent; border: 1px solid rgba(255,255,255,0.3); color: white; font-weight: bold; transition: 0.3s; }
        .custom-pills .nav-link.active { background-color: var(--accent-pink, var(--hot)); border-color: var(--accent-pink, var(--hot)); color: white; }
        body[data-theme="light"] .custom-pills .nav-link { color: var(--text-light-mode) !important; border-color: var(--text-light-mode) !important; }
        body[data-theme="light"] .custom-pills .nav-link.active { color: white !important; border-color: var(--accent-pink, var(--hot)) !important; }

        /* Style สำหรับปุ่มเมนูศิลปินให้เหมือนในรูปต้นฉบับเป๊ะ */
        .artist-menu-btn {
            background-color: transparent;
            border: 1px solid transparent;
            color: #132440;
            border-radius: 8px;
            padding: 1rem 1.5rem;
            text-align: left;
            font-weight: 700;
            transition: all 0.3s ease;
        }
        .artist-menu-btn i {
            color: #132440;
            margin-right: 10px;
            transition: all 0.3s ease;
        }
        .artist-menu-btn:hover {
            border-color: #e63946; 
            color: #e63946;
        }
        .artist-menu-btn:hover i {
            color: #e63946;
        }

        /* Responsive สำหรับมือถือ */
        @media (max-width: 768px) {
            html { font-size: 14px; }
            .fb-cover-photo { height: 200px; }
            .fb-profile-avatar-wrapper { left: 50%; transform: translateX(-50%); bottom: -50px; }
            .fb-profile-avatar { width: 120px; height: 120px; border-width: 3px; }
            .fb-profile-info { padding-left: 0; padding-top: 60px; text-align: center; justify-content: center; flex-direction: column; align-items: center; }
            .camera-btn.cover-camera { top: 10px; bottom: auto; right: 10px; }
            .table-responsive { border: 0; overflow-x: auto; -webkit-overflow-scrolling: touch; }
            td, th { white-space: nowrap; } 
            .nav-pills { flex-direction: column; width: 100%; gap: 10px; }
            .nav-pills .nav-item { width: 100%; margin: 0 !important; }
            .nav-pills .nav-link { width: 100%; text-align: center; }
        }
        @media (min-width: 769px) and (max-width: 1024px) {
            html { font-size: 15px; }
            .fb-profile-avatar-wrapper { left: 20px; }
            .fb-profile-info { padding-left: 200px; }
        }
    </style>
    <link rel="stylesheet" href="style.css?v=2">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark shadow-sm sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php"><i class="fas fa-palette me-2"></i>CMS ART</a>
            <div class="d-flex gap-2 align-items-center ms-auto">
                <?php if(isset($user['role']) && $user['role'] === 'admin'): ?>
                    <a href="admin.php" class="btn btn-warning btn-sm rounded-pill px-3 fw-bold text-dark shadow-sm d-none d-md-inline-block"><i class="fas fa-cog me-1"></i> แดชบอร์ด</a>
                <?php endif; ?>
                <button id="themeToggle" class="theme-toggle-btn shadow-sm"><i class="fas fa-sun" id="themeIcon"></i></button>
                <div class="dropdown">
                    <a class="nav-link rounded-circle d-flex align-items-center justify-content-center position-relative" href="#" data-bs-toggle="dropdown" style="width: 32px; height: 32px; color: #ffffff;">
                        <i class="fas fa-bell"></i>
                        <?php if($unread_count > 0): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem; border: 2px solid var(--primary-purple);"><?php echo $unread_count; ?></span><?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" style="width: 300px; border-radius: 12px;">
                        <li><h6 class="dropdown-header fw-bold text-dark border-bottom pb-2 mb-2">การแจ้งเตือน</h6></li>
                        <?php if(empty($notifs)): ?><li><span class="dropdown-item text-center text-muted small">ไม่มีการแจ้งเตือนใหม่</span></li>
                        <?php else: foreach($notifs as $n): ?>
                            <li><a class="dropdown-item py-2 fw-bold text-dark bg-light rounded-3 mb-1" href="read_notification.php?id=<?php echo $n['id']; ?>" style="white-space: normal; font-size: 0.85rem;"><?php echo htmlspecialchars($n['message']); ?></a></li>
                        <?php endforeach; endif; ?>
                    </ul>
                </div>
                <a href="index.php" class="btn btn-light-main btn-sm rounded-pill px-3 fw-bold"><i class="fas fa-home me-1"></i> <span class="d-none d-sm-inline">หน้าแรก</span></a>
                <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3 d-none d-sm-inline"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
    </nav>

    <div class="fb-profile-header">
        <div class="fb-profile-container">
            <div class="position-relative">
                <div class="fb-cover-photo">
                    <button class="camera-btn cover-camera fw-bold px-3 w-auto rounded-pill" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fas fa-camera me-1"></i> <span class="d-none d-md-inline">แก้ไขรูปปก</span></button>
                </div>
                <div class="fb-profile-avatar-wrapper">
                    <img src="<?php echo $avatar; ?>" class="fb-profile-avatar">
                    <button class="camera-btn avatar-camera" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fas fa-camera"></i></button>
                </div>
            </div>
            
            <div class="fb-profile-info">
                <div>
                    <h2 class="fw-bold mb-0 text-white text-shadow" style="font-size: clamp(1.5rem, 4vw, 2.2rem);"><?php echo htmlspecialchars($display_name); ?></h2>
                    <?php if($is_artist && !empty($bio_text)): ?>
                        <p class="text-white mt-2 mb-0 fw-bold text-shadow" style="font-size: 0.95rem; opacity: 0.9;"><?php echo nl2br(htmlspecialchars($bio_text)); ?></p>
                    <?php else: ?>
                        <p class="text-white mt-1 mb-0 small fw-bold text-shadow" style="opacity: 0.8;"><i class="fas fa-envelope me-1"></i> <?php echo e($user['email']); ?></p>
                    <?php endif; ?>
                </div>
                <button class="btn btn-pink rounded-pill shadow-lg px-4 py-2 fw-bold mt-3 mt-md-0 border border-white border-opacity-25" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fas fa-pen me-2"></i> แก้ไขโปรไฟล์</button>
            </div>
        </div>
    </div>

    <div class="container pb-5" style="max-width: 1100px;">
        <!-- รายการที่ฉันจ้าง -->
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="content-card p-3 p-md-4 border-0 shadow-sm rounded-4 bg-white">
                    <h5 class="fw-bold mb-3 text-purple"><i class="fas fa-shopping-bag me-2"></i>รายการที่ฉันจ้าง (My Orders)</h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light"><tr><th class="text-nowrap">รายการ</th><th class="text-nowrap">ศิลปิน</th><th class="text-nowrap">ราคา</th><th class="text-nowrap">สถานะ</th><th class="text-end text-nowrap">จัดการ</th></tr></thead>
                            <tbody>
                                <?php 
                                $my_orders = $conn->query("SELECT o.id, s.title, a.display_name AS artist_name, o.agreed_price as price, o.status, 'commission' as type, o.created_at FROM commission_orders o JOIN commission_services s ON o.service_id = s.id JOIN artist_profiles a ON o.artist_id = a.user_id WHERE o.buyer_id = $uid");
                                $my_purchases = $conn->query("SELECT s.id, p.title, a.display_name AS artist_name, s.price_paid as price, s.status, 'product' as type, s.purchased_at as created_at FROM product_sales s JOIN products p ON s.product_id = p.id JOIN artist_profiles a ON p.artist_id = a.user_id WHERE s.buyer_id = $uid");
                                $all_my_items = [];
                                if($my_orders) { while($ord = $my_orders->fetch_assoc()) $all_my_items[] = $ord; }
                                if($my_purchases) { while($pur = $my_purchases->fetch_assoc()) $all_my_items[] = $pur; }
                                usort($all_my_items, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });

                                if(!empty($all_my_items)): foreach($all_my_items as $item):
                                    $is_prod = ($item['type'] == 'product');
                                    $status_color = match($item['status']) { 'pending' => 'badge-peach', 'paid' => 'bg-info text-white', 'working' => 'badge-purple', 'review' => 'badge-purple', 'completed' => 'badge-pink', 'cancelled' => 'bg-danger text-white', default => 'bg-secondary text-white' };
                                    $chat_link = $is_prod ? "product_chat.php?sale_id=".$item['id'] : "chat.php?order_id=".$item['id'];
                                ?>
                                <tr>
                                    <td class="fw-bold text-dark" style="min-width: 200px; white-space: normal;"><?php if($is_prod): ?><span class="badge bg-success me-1">สินค้า</span><?php endif; ?><?php echo htmlspecialchars($item['title']); ?></td>
                                    <td><span class="badge bg-light text-dark border shadow-sm"><?php echo htmlspecialchars($item['artist_name']); ?></span></td>
                                    <td class="text-pink fw-bold text-nowrap">฿<?php echo number_format($item['price']); ?></td>
                                    <td><span class="badge rounded-pill shadow-sm <?php echo $status_color; ?> text-nowrap"><?php echo $item['status']; ?></span></td>
                                    <td class="text-end text-nowrap"><a href="<?php echo $chat_link; ?>" class="btn btn-purple btn-sm rounded-pill px-3 shadow-sm fw-bold">อัปเดต</a></td>
                                </tr>
                                <?php endforeach; else: ?><tr><td colspan="5" class="text-center py-4 text-muted fw-bold">ไม่มีรายการ</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php if($is_artist): ?>
        <hr class="my-4 opacity-25">
        <h4 class="fw-bold mb-3 text-white text-shadow"><i class="fas fa-paint-brush me-2"></i>โซนศิลปิน</h4>
        
        <div class="row g-3 mb-4">
            <!-- เมนูการจัดการศิลปิน (สไตล์ตรงตามต้นฉบับ 100%) -->
            <div class="col-lg-4">
                <div class="content-card h-100 p-3 p-md-4 border-0 shadow-sm rounded-4 bg-white">
                    <h6 class="fw-bold mb-3 text-dark">เมนูการจัดการ</h6>
                    <div class="d-grid gap-2">
                        <button class="btn artist-menu-btn w-100" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                            <i class="fas fa-briefcase"></i> รับงานจ้าง
                        </button>
                        <button class="btn artist-menu-btn w-100" data-bs-toggle="modal" data-bs-target="#addProductModal">
                            <i class="fas fa-box-open"></i> ลงขายภาพ
                        </button>
                        <button class="btn artist-menu-btn w-100" data-bs-toggle="modal" data-bs-target="#addPostModal">
                            <i class="fas fa-image"></i> ลงผลงานโชว์
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="content-card h-100 p-3 p-md-4 shadow-sm rounded-4 border-0 bg-white">
                    <h6 class="fw-bold mb-3 text-dark"><i class="fas fa-inbox me-2"></i>ออเดอร์ลูกค้า</h6>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light"><tr><th class="text-nowrap">ลูกค้า</th><th class="text-nowrap">ประเภท</th><th class="text-nowrap">สถานะ</th><th class="text-end text-nowrap">จัดการ</th></tr></thead>
                            <tbody>
                                <?php 
                                $ords = $conn->query("SELECT o.id, o.status, u.username, 'commission' as type, o.created_at FROM commission_orders o JOIN users u ON o.buyer_id = u.id WHERE o.artist_id = $uid");
                                $sales = $conn->query("SELECT s.id, s.status, u.username, 'product' as type, s.purchased_at AS created_at FROM product_sales s JOIN users u ON s.buyer_id = u.id JOIN products p ON s.product_id = p.id WHERE p.artist_id = $uid");
                                $all_incoming = [];
                                if($ords) { while($o = $ords->fetch_assoc()) $all_incoming[] = $o; }
                                if($sales) { while($s = $sales->fetch_assoc()) $all_incoming[] = $s; }
                                usort($all_incoming, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });

                                if(!empty($all_incoming)): foreach($all_incoming as $inc): 
                                    $link_inc = ($inc['type'] == 'product') ? "product_chat.php?sale_id=".$inc['id'] : "chat.php?order_id=".$inc['id'];
                                    $badge = ($inc['type'] == 'product') ? '<span class="badge bg-success">สินค้า</span>' : '<span class="badge bg-primary">จ้างวาด</span>';
                                ?>
                                    <tr>
                                        <td class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($inc['username']); ?></td>
                                        <td><?php echo $badge; ?></td>
                                        <td><span class="badge bg-secondary rounded-pill text-nowrap"><?php echo $inc['status']; ?></span></td>
                                        <td class="text-end text-nowrap"><a href="<?php echo $link_inc; ?>" class="btn btn-sm btn-purple rounded-pill px-3 fw-bold">อัปเดต</a></td>
                                    </tr>
                                <?php endforeach; else: echo "<tr><td colspan='4' class='text-center py-4 text-muted fw-bold'>ไม่มีออเดอร์</td></tr>"; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <h5 class="fw-bold mb-3 mt-4 text-white text-shadow"><i class="fas fa-box-open me-2"></i>จัดการภาพสำเร็จรูป</h5>
        <div class="content-card p-3 p-md-4 border-0 shadow-sm rounded-4 bg-white mb-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="bg-light"><tr><th>รูป</th><th>ชื่อ</th><th>ราคา</th><th>สถานะ</th><th class="text-end text-nowrap">จัดการ</th></tr></thead>
                    <tbody>
                        <?php 
                        $my_prods = $conn->query("SELECT * FROM products WHERE artist_id = $uid ORDER BY created_at DESC");
                        if($my_prods && $my_prods->num_rows > 0): while($prod = $my_prods->fetch_assoc()):
                            $prod_json = htmlspecialchars(json_encode(['id' => $prod['id'], 'title' => $prod['title'], 'description' => $prod['description'], 'price' => $prod['price']]), ENT_QUOTES, 'UTF-8');
                            $p_imgs = []; $p_imgs_q = $conn->query("SELECT id, image_path FROM product_images WHERE product_id = " . $prod['id']);
                            if($p_imgs_q){ while($pi = $p_imgs_q->fetch_assoc()) $p_imgs[] = $pi; }
                            $p_imgs_json = htmlspecialchars(json_encode($p_imgs), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr>
                            <td><img src="uploads/<?php echo f($prod['preview_image']); ?>" class="rounded-3 border" style="width: 50px; height: 50px; object-fit: cover;"></td>
                            <td class="fw-bold text-dark" style="min-width: 150px; white-space: normal;"><?php echo htmlspecialchars($prod['title']); ?></td>
                            <td class="text-pink fw-bold text-nowrap">฿<?php echo number_format($prod['price']); ?></td>
                            <td><span class="badge rounded-pill bg-success text-nowrap"><?php echo $prod['status']; ?></span></td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm rounded-pill px-3 fw-bold shadow-sm me-1 mb-1" style="background-color: #ffb3c6; color: #7a0026; border: none;" data-prod="<?php echo $prod_json; ?>" data-imgs="<?php echo $p_imgs_json; ?>" onclick="editProduct(this.dataset.prod, this.dataset.imgs)" data-bs-toggle="modal" data-bs-target="#editProductModal"><i class="fas fa-edit"></i> แก้ไข</button>
                                <form action="product_manage.php" method="POST" class="d-inline">
                                    <input type="hidden" name="delete_id" value="<?php echo $prod['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm rounded-pill px-3 shadow-sm fw-bold mb-1"><i class="fas fa-trash"></i> ลบ</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; else: ?><tr><td colspan="5" class="text-center py-4 text-muted fw-bold">ไม่มีรายการ</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <ul class="nav nav-pills custom-pills mb-4 mt-4" id="pills-tab">
            <li class="nav-item"><button class="nav-link rounded-pill px-4 <?php echo ($active_tab=='favorites')?'active':''; ?>" data-bs-toggle="pill" data-bs-target="#pills-favorites">รายการโปรด</button></li>
            <?php if($is_artist): ?>
            <li class="nav-item"><button class="nav-link rounded-pill px-4 <?php echo ($active_tab=='services')?'active':''; ?>" data-bs-toggle="pill" data-bs-target="#pills-services">งานจ้างของฉัน</button></li>
            <li class="nav-item"><button class="nav-link rounded-pill px-4 <?php echo ($active_tab=='gallery')?'active':''; ?>" data-bs-toggle="pill" data-bs-target="#pills-gallery">แกลเลอรี</button></li>
            <?php endif; ?>
        </ul>

        <div class="tab-content" id="pills-tabContent">
            <div class="tab-pane fade <?php echo ($active_tab=='favorites')?'show active':''; ?>" id="pills-favorites">
                <div class="row g-3 g-md-4">
                    <?php 
                    $fav_sql = "SELECT s.*, a.display_name FROM commission_services s JOIN artist_profiles a ON s.artist_id = a.user_id JOIN service_favorites f ON s.id = f.service_id WHERE f.user_id = $uid AND s.status = 'active'";
                    $fav_res = $conn->query($fav_sql);
                    if($fav_res && $fav_res->num_rows > 0): while($fav = $fav_res->fetch_assoc()):
                        $img = $conn->query("SELECT image_path FROM service_images WHERE service_id = {$fav['id']} LIMIT 1")->fetch_assoc();
                        $cover = $img ? "uploads/" . f($img['image_path']) : "assets/placeholder.svg";
                    ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="service-card h-100 border rounded-4 overflow-hidden shadow-sm bg-white">
                            <img src="<?php echo $cover; ?>" style="height: 140px; width: 100%; object-fit: cover;">
                            <div class="p-2">
                                <h6 class="fw-bold text-dark text-truncate mb-1" style="font-size: 0.9rem;"><?php echo htmlspecialchars($fav['title']); ?></h6>
                                <a href="view_service.php?id=<?php echo $fav['id']; ?>" class="btn btn-sm btn-outline-purple w-100 rounded-pill mt-2">ดูรายละเอียด</a>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; else: ?><div class="col-12 text-center text-white py-4">ไม่มีรายการโปรด</div><?php endif; ?>
                </div>
            </div>

            <?php if($is_artist): ?>
            <div class="tab-pane fade <?php echo ($active_tab=='services')?'show active':''; ?>" id="pills-services">
                <div class="row g-3 g-md-4">
                    <?php
                    $sql = "SELECT * FROM commission_services WHERE artist_id = $uid ORDER BY created_at DESC";
                    $result = $conn->query($sql);
                    if($result->num_rows > 0): while($row = $result->fetch_assoc()):
                        $img = $conn->query("SELECT image_path FROM service_images WHERE service_id = {$row['id']} LIMIT 1")->fetch_assoc();
                        $cover = $img ? "uploads/" . f($img['image_path']) : "assets/placeholder.svg";
                    ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="service-card h-100 border rounded-4 shadow-sm overflow-hidden bg-white">
                            <img src="<?php echo $cover; ?>" style="height: 140px; width: 100%; object-fit: cover;">
                            <div class="p-2">
                                <h6 class="fw-bold text-dark text-truncate mb-1" style="font-size: 0.9rem;"><?php echo htmlspecialchars($row['title']); ?></h6>
                                <a href="view_service.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-purple w-100 rounded-pill mt-2">ดูรายละเอียด</a>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; else: ?><div class="col-12 text-center text-white py-4">ไม่มีประกาศ</div><?php endif; ?>
                </div>
            </div>
            
            <div class="tab-pane fade <?php echo ($active_tab=='gallery')?'show active':''; ?>" id="pills-gallery">
                <div class="row g-3 g-md-4 justify-content-center">
                    <?php
                    $my_posts = $conn->query("SELECT * FROM artist_posts WHERE artist_id = $uid ORDER BY created_at DESC");
                    if($my_posts && $my_posts->num_rows > 0): while($p = $my_posts->fetch_assoc()):
                        $pid = $p['id'];
                        $img_q = $conn->query("SELECT image_path FROM post_images WHERE post_id = $pid LIMIT 1")->fetch_assoc();
                        $cover = $img_q ? "uploads/" . f($img_q['image_path']) : "assets/placeholder.svg";
                        
                        $all_imgs = [];
                        $img_q_all = $conn->query("SELECT id, image_path FROM post_images WHERE post_id = $pid");
                        if($img_q_all) { while($r = $img_q_all->fetch_assoc()) $all_imgs[] = $r; }
                        $img_json = htmlspecialchars(json_encode($all_imgs), ENT_QUOTES, 'UTF-8');
                    ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100 bg-white">
                            <img src="<?php echo $cover; ?>" class="card-img-top" style="height: 160px; object-fit: cover;">
                            <div class="card-body p-2 d-flex flex-column text-center">
                                <button class="btn btn-purple btn-sm rounded-pill w-100 mb-1 fw-bold" data-post-id="<?php echo (int)$pid; ?>" data-caption="<?php echo e($p['caption']); ?>" data-imgs="<?php echo $img_json; ?>" onclick="editPost(this.dataset.postId, this.dataset.caption, this.dataset.imgs)" data-bs-toggle="modal" data-bs-target="#editPostModal"><i class="fas fa-edit"></i> แก้ไข</button>
                                <a href="post_delete.php?id=<?php echo $pid; ?>" class="btn btn-danger btn-sm rounded-pill w-100 fw-bold"><i class="fas fa-trash"></i> ลบ</a>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; else: ?><div class="col-12 text-center text-white py-4">ไม่มีผลงาน</div><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modals ทั้งหมด (โครงสร้างครบถ้วน 100%) -->
    <div class="modal fade" id="editProfileModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg rounded-4 p-3"><form action="update_profile.php" method="POST" enctype="multipart/form-data"><div class="modal-header border-0 pb-0 d-flex justify-content-between align-items-center"><h5 class="modal-title fw-bold" style="color: var(--primary-purple);">แก้ไขโปรไฟล์</h5><button type="button" class="btn-close m-0 shadow-none" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="small fw-bold mb-1 text-muted">ชื่อที่แสดง</label><input type="text" name="display_name" class="form-control bg-light border-0 py-2 rounded-3 text-dark fw-bold" value="<?php echo htmlspecialchars($display_name); ?>" required></div><?php if($is_artist): ?><div class="mb-4"><label class="small fw-bold mb-1 text-muted">รายละเอียด (Bio)</label><textarea name="bio" class="form-control bg-light border-0 py-2 rounded-3 text-dark fw-bold" rows="3"><?php echo htmlspecialchars($bio_text); ?></textarea></div><?php endif; ?><hr class="my-4 opacity-25"><h6 class="fw-bold text-dark mb-3">รูปภาพโปรไฟล์และหน้าปก</h6><div class="mb-3 p-3 bg-light rounded-4 border border-dashed text-center position-relative"><label class="form-label small fw-bold mb-3 d-block"><i class="fas fa-image me-1"></i> เปลี่ยนรูปโปรไฟล์</label><img id="profile_preview" src="<?php echo $avatar; ?>" class="rounded-circle mb-3 border shadow-sm" style="width: 100px; height: 100px; object-fit: cover;"><input type="file" id="profile_image_input" class="form-control border-0 bg-white rounded-pill shadow-sm" accept="image/*"><input type="hidden" name="cropped_image" id="cropped_image"></div><div class="mb-3 p-3 bg-light rounded-4 border border-dashed text-center"><label class="form-label small fw-bold mb-2 d-block"><i class="fas fa-panorama me-1"></i> เปลี่ยนหน้าปก</label><input type="file" name="cover_image" class="form-control border-0 bg-white rounded-pill" accept="image/*"></div></div><div class="modal-footer border-0 pt-0"><button type="submit" class="btn btn-purple w-100 rounded-pill py-2 fw-bold shadow-sm">บันทึกการเปลี่ยนแปลง</button></div></form></div></div></div>
    <div class="modal fade" id="cropModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg rounded-4"><div class="modal-header border-bottom"><h5 class="modal-title fw-bold text-dark">ปรับตำแหน่งรูปโปรไฟล์</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-0" style="background: #222;"><div style="max-height: 60vh; width: 100%; overflow: hidden;"><img id="image_to_crop" src="" style="max-width: 100%; display: block;"></div></div><div class="modal-footer border-top bg-light rounded-bottom-4"><button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ยกเลิก</button><button type="button" class="btn btn-pink rounded-pill px-4 fw-bold" id="btn_crop"><i class="fas fa-crop-alt me-1"></i> ตัดรูป</button></div></div></div></div>
    <div class="modal fade" id="addServiceModal"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg rounded-4 p-2"><form action="service_action.php" method="POST" enctype="multipart/form-data"><div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold" style="color: var(--primary-purple);">ลงประกาศรับงานจ้าง</h5><button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" name="title" class="form-control bg-light border-0 mb-3 py-2 fw-bold text-dark" placeholder="หัวข้องาน" required><textarea name="desc" class="form-control bg-light border-0 mb-3 py-2 fw-bold text-dark" rows="3" placeholder="รายละเอียด"></textarea><input type="number" name="price" class="form-control bg-light border-0 mb-3 py-2 fw-bold text-dark" placeholder="ราคาเริ่มต้น" required><label class="small fw-bold mb-1 text-muted">รูปภาพปก</label><input type="file" name="image" class="form-control bg-light border-0 fw-bold text-dark" required><input type="hidden" name="add_service" value="1"></div><div class="modal-footer border-0 pt-0"><button class="btn btn-pink w-100 rounded-pill py-2 fw-bold">ลงประกาศ</button></div></form></div></div></div>
    <div class="modal fade" id="addPostModal"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg rounded-4 p-2"><form action="post_action.php" method="POST" enctype="multipart/form-data"><div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold text-dark">ลงผลงาน</h5><button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label small fw-bold text-muted">เลือกรูปภาพ</label><input type="file" name="images[]" class="form-control bg-light border-0 py-2 fw-bold text-dark" required accept="image/*" multiple></div><div class="mb-3"><label class="form-label small fw-bold text-muted">คำอธิบาย</label><textarea name="caption" class="form-control bg-light border-0 py-2 fw-bold text-dark" rows="3"></textarea></div></div><div class="modal-footer border-0 pt-0"><button class="btn btn-purple w-100 rounded-pill py-2 fw-bold">โพสต์</button></div></form></div></div></div>
    <div class="modal fade" id="addProductModal"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg rounded-4 p-2"><form action="product_action.php" method="POST" enctype="multipart/form-data"><div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold" style="color: var(--hot);">ลงขายภาพสำเร็จรูป</h5><button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" name="title" class="form-control bg-light border-0 mb-3 py-2 fw-bold text-dark" placeholder="ชื่อผลงาน" required><textarea name="desc" class="form-control bg-light border-0 mb-3 py-2 fw-bold text-dark" rows="3" placeholder="รายละเอียด"></textarea><input type="number" name="price" class="form-control bg-light border-0 mb-3 py-2 fw-bold text-dark" placeholder="ราคา (บาท)" required><label class="small fw-bold mb-1 text-muted">รูปภาพตัวอย่าง</label><input type="file" name="preview_images[]" class="form-control bg-light border-0 mb-3 fw-bold text-dark" accept="image/*" multiple required></div><div class="modal-footer border-0 pt-0"><button type="submit" class="btn btn-pink w-100 rounded-pill py-2 fw-bold shadow-sm">จำหน่าย</button></div></form></div></div></div>
    
    <div class="modal fade" id="editProductModal"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg rounded-4 p-2"><form action="product_edit_action.php" method="POST" enctype="multipart/form-data"><input type="hidden" name="product_id" id="edit_prod_id"><div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold" style="color: var(--primary-purple);">แก้ไขข้อมูลสินค้า</h5><button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" name="title" id="edit_prod_title" class="form-control bg-light border-0 mb-3 py-2 fw-bold text-dark" required><textarea name="desc" id="edit_prod_desc" class="form-control bg-light border-0 mb-3 py-2 fw-bold text-dark" rows="3"></textarea><input type="number" name="price" id="edit_prod_price" class="form-control bg-light border-0 mb-3 py-2 fw-bold text-dark" required><label class="small fw-bold mb-1 text-muted">รูปลบเดิม (ติ๊กเพื่อลบ)</label><div id="current_previews" class="row g-2 mb-3"></div><label class="small fw-bold mb-1 text-muted">อัปโหลดใหม่</label><input type="file" name="new_preview_images[]" class="form-control bg-light border-0 mb-3 fw-bold text-dark" accept="image/*" multiple></div><div class="modal-footer border-0 pt-0"><button type="submit" class="btn btn-purple w-100 rounded-pill py-2 fw-bold shadow-sm">บันทึก</button></div></form></div></div></div>
    
    <div class="modal fade" id="editPostModal"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg rounded-4 p-2"><form action="post_update.php" method="POST" enctype="multipart/form-data"><input type="hidden" name="post_id" id="edit_post_id"><div id="delete_images_container"></div><div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold">แก้ไขโพสต์</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><textarea name="caption" id="edit_caption" class="form-control bg-light border-0 mb-3 py-2 fw-bold text-dark" rows="3"></textarea><div class="row g-2 mb-2" id="edit_existing_images"></div><div class="row g-2 mb-3" id="new_images_preview"></div><label class="btn btn-light rounded-3 w-100 border border-dashed py-2"><i class="fas fa-image text-purple"></i> อัปโหลดรูปเพิ่ม<input type="file" name="new_images[]" id="new_image_input" hidden multiple accept="image/*" onchange="previewNewImages(this)"></label></div><div class="modal-footer border-0 pt-0"><button type="submit" class="btn btn-purple w-100 rounded-pill fw-bold py-2">บันทึก</button></div></form></div></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
    
    <script>
        // ระบบตัดรูปภาพ Profile
        let cropper;
        document.getElementById('profile_image_input').addEventListener('change', function(e) {
            if (e.target.files && e.target.files.length > 0) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    document.getElementById('image_to_crop').src = event.target.result;
                    bootstrap.Modal.getInstance(document.getElementById('editProfileModal')).hide();
                    setTimeout(() => new bootstrap.Modal(document.getElementById('cropModal')).show(), 400);
                };
                reader.readAsDataURL(e.target.files[0]);
            }
        });

        document.getElementById('cropModal').addEventListener('shown.bs.modal', function () {
            cropper = new Cropper(document.getElementById('image_to_crop'), { aspectRatio: 1, viewMode: 1, autoCropArea: 1, guides: false });
        });
        document.getElementById('cropModal').addEventListener('hidden.bs.modal', function () {
            if (cropper) { cropper.destroy(); cropper = null; }
            new bootstrap.Modal(document.getElementById('editProfileModal')).show();
        });

        document.getElementById('btn_crop').addEventListener('click', function() {
            const canvas = cropper.getCroppedCanvas({ width: 400, height: 400 });
            const base64img = canvas.toDataURL('image/jpeg');
            document.getElementById('cropped_image').value = base64img;
            document.getElementById('profile_preview').src = base64img;
            bootstrap.Modal.getInstance(document.getElementById('cropModal')).hide();
        });

        // ระบบดึงข้อมูลใส่ฟอร์ม Edit Product
        function editProduct(prodJson, imagesJson) {
            const data = JSON.parse(prodJson);
            document.getElementById('edit_prod_id').value = data.id;
            document.getElementById('edit_prod_title').value = data.title;
            document.getElementById('edit_prod_desc').value = data.description;
            document.getElementById('edit_prod_price').value = data.price;
            const previewContainer = document.getElementById('current_previews');
            previewContainer.innerHTML = '';
            JSON.parse(imagesJson).forEach(img => {
                const col = document.createElement('div');
                col.className = 'col-4 col-md-3 position-relative';
                col.innerHTML = `<img src="uploads/${encodeURIComponent(img.image_path)}" class="img-fluid rounded border" style="height: 60px; object-fit: cover;"><input type="checkbox" name="delete_previews[]" value="${parseInt(img.id, 10)}" class="position-absolute top-0 end-0 m-1">`;
                previewContainer.appendChild(col);
            });
        }

        // ระบบแก้ไขโพสต์
        function editPost(id, caption, imagesJson) {
            document.getElementById('edit_post_id').value = id;
            document.getElementById('edit_caption').value = caption;
            const existingImagesContainer = document.getElementById('edit_existing_images');
            document.getElementById('delete_images_container').innerHTML = '';
            existingImagesContainer.innerHTML = '';
            
            JSON.parse(imagesJson).forEach((img) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'col-4 position-relative';
                wrapper.id = 'existing_img_' + img.id;
                wrapper.innerHTML = `<img src="uploads/${encodeURIComponent(img.image_path)}" class="w-100 rounded-3 border" style="height: 80px; object-fit: cover;"><button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1 p-1" onclick="removeExistingImage(${parseInt(img.id, 10)})"><i class="fas fa-times"></i></button>`;
                existingImagesContainer.appendChild(wrapper);
            });
        }

        function removeExistingImage(imageId) {
            document.getElementById('existing_img_' + imageId).style.display = 'none';
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'delete_images[]'; input.value = imageId;
            document.getElementById('delete_images_container').appendChild(input);
        }

        function previewNewImages(input) {
            const previewContainer = document.getElementById('new_images_preview');
            previewContainer.innerHTML = ''; 
            if(input.files && input.files.length > 0) {
                Array.from(input.files).forEach(file => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const div = document.createElement('div');
                        div.className = 'col-4 position-relative mt-2';
                        div.innerHTML = `<img src="${e.target.result}" class="w-100 rounded-3 border" style="height: 80px; object-fit: cover;"><span class="badge bg-success position-absolute bottom-0 start-0 m-1" style="font-size: 0.6rem;">รูปใหม่</span>`;
                        previewContainer.appendChild(div);
                    }
                    reader.readAsDataURL(file);
                });
            }
        }

        // แจ้งเตือน SweetAlert สำหรับการยืนยันข้อมูลและการลบ
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('a[href*="post_delete"], form[action="product_manage.php"] button[type="submit"]').forEach(el => {
                el.addEventListener('click', function(e) {
                    e.preventDefault(); 
                    let targetAction = '';
                    let isForm = false;
                    let parentForm = null;

                    if(this.tagName === 'A'){ targetAction = this.getAttribute('href'); } 
                    else if(this.tagName === 'BUTTON'){ isForm = true; parentForm = this.closest('form'); }
                    
                    Swal.fire({
                        title: '<span style="font-family: var(--font-heading); font-weight: 700; color: #dc3545;">ยืนยันการลบ</span>',
                        html: '<span class="text-muted">ข้อมูลที่ถูกลบจะไม่สามารถกู้คืนได้</span>',
                        icon: 'warning', iconColor: '#dc3545',
                        showCancelButton: true, confirmButtonText: 'ลบทิ้ง', cancelButtonText: 'ยกเลิก',
                        customClass: { popup: 'rounded-4 shadow-lg border-0', confirmButton: 'btn btn-danger rounded-pill px-4 mx-2 fw-bold shadow-sm', cancelButton: 'btn btn-light rounded-pill px-4 mx-2 fw-bold border shadow-sm' },
                        buttonsStyling: false, reverseButtons: true
                    }).then((result) => {
                        if (result.isConfirmed) {
                            if(isForm) { parentForm.submit(); } else { window.location.href = targetAction; }
                        }
                    });
                });
            });
        });
    </script>
    
    <?php if(isset($_SESSION['swal_success'])): ?><script>Swal.fire({title: 'สำเร็จ', text: <?php echo js_str($_SESSION['swal_success']); ?>, icon: 'success', timer: 2500, showConfirmButton: false});</script><?php unset($_SESSION['swal_success']); endif; ?>
    <?php if(isset($_SESSION['swal_error'])): ?><script>Swal.fire({title: 'แจ้งเตือน', text: <?php echo js_str($_SESSION['swal_error']); ?>, icon: 'error'});</script><?php unset($_SESSION['swal_error']); endif; ?>
</body>
</html>