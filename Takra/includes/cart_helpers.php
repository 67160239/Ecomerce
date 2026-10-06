<?php
/**
 * includes/cart_helpers.php
 * ตรรกะตะกร้าที่ใช้ร่วมกัน: cart.php, checkout.php, api/cart_add.php
 * (เก็บแยกไว้ที่เดียวเพื่อให้ราคา ค่าส่ง และส่วนลดคำนวณตรงกันทุกหน้า)
 */
declare(strict_types=1);

/**
 * ปรับจำนวนในตะกร้าที่เกินสต็อกคงเหลือให้เท่าสต็อก (เฉพาะรายการที่ยังมีของ)
 * @return int จำนวนรายการที่ถูกปรับ
 */
function sync_cart_stock(int $userId): int
{
    $sql = "UPDATE cart_items ci
            JOIN products p ON p.id = ci.product_id
            LEFT JOIN product_variants v ON v.id = ci.variant_id
            SET ci.quantity = IF(ci.variant_id IS NULL, p.stock, COALESCE(v.stock, 0))
            WHERE ci.user_id = ?
              AND IF(ci.variant_id IS NULL, p.stock, COALESCE(v.stock, 0)) > 0
              AND ci.quantity > IF(ci.variant_id IS NULL, p.stock, COALESCE(v.stock, 0))";
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId]);
    return $stmt->rowCount();
}

/**
 * ดึงรายการในตะกร้าพร้อมราคา สต็อก และสถานะความพร้อมขาย
 * แต่ละรายการมีคีย์เพิ่ม: unit_price, stock, available, reason, line_total
 */
function get_cart_items(int $userId): array
{
    $sql = "SELECT ci.id, ci.product_id, ci.variant_id, ci.quantity,
                   p.name, p.price AS base_price, p.stock AS product_stock, p.status AS product_status,
                   s.id AS shop_id, s.shop_name, s.status AS shop_status,
                   v.variant_name, v.price AS variant_price, v.stock AS variant_stock,
                   (SELECT image_path FROM product_images
                     WHERE product_id = p.id ORDER BY sort_order, id LIMIT 1) AS image
            FROM cart_items ci
            JOIN products p ON p.id = ci.product_id
            JOIN shops s    ON s.id = p.shop_id
            LEFT JOIN product_variants v ON v.id = ci.variant_id
            WHERE ci.user_id = ?
            ORDER BY s.shop_name, ci.id";
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll();

    foreach ($items as &$it) {
        $hasVariant      = $it['variant_id'] !== null;
        $it['unit_price'] = ($hasVariant && $it['variant_price'] !== null) ? (float)$it['variant_price'] : (float)$it['base_price'];
        $it['stock']      = $hasVariant ? (int)($it['variant_stock'] ?? 0) : (int)$it['product_stock'];
        $it['quantity']   = (int)$it['quantity'];

        $it['reason'] = null;
        if ($it['product_status'] !== 'active' || $it['shop_status'] !== 'approved') {
            $it['reason'] = 'สินค้านี้ปิดการขายแล้ว';
        } elseif ($hasVariant && $it['variant_name'] === null) {
            $it['reason'] = 'ตัวเลือกนี้ไม่มีแล้ว';
        } elseif ($it['stock'] <= 0) {
            $it['reason'] = 'สินค้าหมด';
        }
        $it['available']  = $it['reason'] === null;
        $it['line_total'] = $it['available'] ? round($it['unit_price'] * $it['quantity'], 2) : 0.0;
    }
    unset($it);

    return $items;
}

/**
 * ตรวจคูปอง
 * @return array ['ok'=>bool, 'coupon'=>array|null, 'discount'=>float, 'error'=>string|null]
 */
function validate_coupon(string $code, float $subtotal): array
{
    $fail = fn(string $msg) => ['ok' => false, 'coupon' => null, 'discount' => 0.0, 'error' => $msg];

    $code = strtoupper(trim($code));
    if ($code === '') {
        return $fail('กรุณากรอกรหัสคูปอง');
    }

    $stmt = db()->prepare('SELECT * FROM coupons WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    $c = $stmt->fetch();

    if (!$c) {
        return $fail('ไม่พบรหัสคูปองนี้');
    }
    if ($c['expires_at'] !== null && strtotime($c['expires_at']) < time()) {
        return $fail('คูปองนี้หมดอายุแล้ว');
    }
    if ($c['usage_limit'] !== null && (int)$c['used_count'] >= (int)$c['usage_limit']) {
        return $fail('คูปองนี้ถูกใช้ครบจำนวนแล้ว');
    }
    if ($subtotal < (float)$c['min_order']) {
        return $fail('ยอดสั่งซื้อขั้นต่ำสำหรับคูปองนี้ ' . money($c['min_order']));
    }

    $discount = $c['discount_type'] === 'percent'
        ? $subtotal * (float)$c['discount_value'] / 100
        : (float)$c['discount_value'];
    $discount = round(min($discount, $subtotal), 2);   // ส่วนลดต้องไม่เกินยอดสินค้า

    return ['ok' => true, 'coupon' => $c, 'discount' => $discount, 'error' => null];
}

/**
 * คำนวณยอดรวมของตะกร้า (นับเฉพาะรายการที่พร้อมขาย)
 * ค่าส่ง: เหมาจ่ายต่อออเดอร์ ส่งฟรีเมื่อยอดสินค้า (ก่อนหักคูปอง) ถึงเกณฑ์
 */
function calc_cart_totals(array $items, float $discount = 0.0): array
{
    $subtotal = 0.0;
    $count    = 0;
    $hasUnavailable = false;

    foreach ($items as $it) {
        if ($it['available']) {
            $subtotal += $it['line_total'];
            $count    += $it['quantity'];
        } else {
            $hasUnavailable = true;
        }
    }
    $subtotal = round($subtotal, 2);
    $discount = min($discount, $subtotal);

    $shipping = 0.0;
    if ($subtotal > 0 && $subtotal < FREE_SHIPPING_MIN) {
        $shipping = SHIPPING_FLAT_FEE;
    }

    return [
        'subtotal'        => $subtotal,
        'discount'        => $discount,
        'shipping'        => $shipping,
        'total'           => round($subtotal - $discount + $shipping, 2),
        'item_count'      => $count,
        'has_unavailable' => $hasUnavailable,
        'free_ship_gap'   => ($subtotal > 0 && $subtotal < FREE_SHIPPING_MIN) ? round(FREE_SHIPPING_MIN - $subtotal, 2) : 0.0,
    ];
}