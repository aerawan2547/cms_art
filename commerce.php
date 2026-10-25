<?php
/**
 * commerce.php — ฟังก์ชันสร้างออเดอร์ซื้อภาพสำเร็จรูป (ใช้ร่วมกันทุกหน้า)
 * ใช้ transaction + ล็อกแถวสินค้า เพื่อกันคนกดซื้อพร้อมกันจนได้หลายออเดอร์
 */
require_once __DIR__ . '/security.php';

/** @return array [bool ok, string message, int sale_id] */
function create_product_sale(mysqli $conn, int $product_id, int $buyer_id): array {
    $conn->begin_transaction();
    try {
        $st = $conn->prepare("SELECT id, artist_id, price, status FROM products WHERE id = ? FOR UPDATE");
        $st->bind_param("i", $product_id);
        $st->execute();
        $prod = $st->get_result()->fetch_assoc();

        if (!$prod || $prod['status'] !== 'available') { $conn->rollback(); return [false, 'สินค้านี้ถูกซื้อไปแล้ว หรือไม่พร้อมขายครับ', 0]; }
        if ((int)$prod['artist_id'] === $buyer_id)        { $conn->rollback(); return [false, 'ไม่สามารถซื้อผลงานของตัวเองได้ครับ', 0]; }

        // มีคนสั่งซื้อค้างอยู่แล้วหรือยัง (ภาพนี้เป็นภาพชิ้นเดียว)
        $st = $conn->prepare("SELECT id, buyer_id FROM product_sales WHERE product_id = ? AND status IN ('pending','paid','completed') LIMIT 1");
        $st->bind_param("i", $product_id);
        $st->execute();
        if ($open = $st->get_result()->fetch_assoc()) {
            $conn->rollback();
            if ((int)$open['buyer_id'] === $buyer_id) return [true, '', (int)$open['id']];   // ของเราเอง พาไปห้องแชทเดิม
            return [false, 'มีผู้สั่งซื้อภาพนี้อยู่ระหว่างดำเนินการ กรุณากลับมาดูใหม่ภายหลังครับ', 0];
        }

        $price = (float)$prod['price'];
        $artist_id = (int)$prod['artist_id'];

        $st = $conn->prepare("INSERT INTO product_sales (product_id, buyer_id, price_paid, status) VALUES (?, ?, ?, 'pending')");
        $st->bind_param("iid", $product_id, $buyer_id, $price);
        $st->execute();
        $sale_id = (int)$conn->insert_id;

        $st = $conn->prepare("INSERT INTO conversations (related_product_sale_id, type) VALUES (?, 'product')");
        $st->bind_param("i", $sale_id);
        $st->execute();
        $cid = (int)$conn->insert_id;

        $st = $conn->prepare("INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?, ?), (?, ?)");
        $st->bind_param("iiii", $cid, $buyer_id, $cid, $artist_id);
        $st->execute();

        notify($conn, $artist_id, $buyer_id, 'new_order', "มีลูกค้าสั่งซื้อภาพสำเร็จรูปของคุณ (Sale #$sale_id)", "product_chat.php?sale_id=$sale_id");

        $conn->commit();
        return [true, '', $sale_id];
    } catch (Throwable $ex) {
        $conn->rollback();
        error_log('create_product_sale: ' . $ex->getMessage());
        return [false, 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง', 0];
    }
}
