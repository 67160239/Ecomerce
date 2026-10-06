<?php
/**
 * public/checkout.php - ชำระเงิน / สร้างคำสั่งซื้อ
 * ขั้นตอน: เลือกที่อยู่ -> เลือกวิธีชำระเงิน -> กดสั่งซื้อ
 * ตอนกดสั่งซื้อ ระบบใช้ transaction + ล็อกแถวสินค้า ตรวจสต็อกและราคาจริงอีกครั้ง
 * แล้วจึงสร้างออเดอร์ ตัดสต็อก ใช้คูปอง และล้างตะกร้า (สำเร็จทั้งหมดหรือไม่ทำเลย)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cart_helpers.php';

/** ข้อผิดพลาดที่แสดงให้ผู้ใช้อ่านได้ พร้อมหน้าที่จะพากลับไป */
class CheckoutException extends RuntimeException
{
    public function __construct(string $message, public string $redirect = 'checkout.php')
    {
        parent::__construct($message);
    }
}

/**
 * สร้างคำสั่งซื้อจากตะกร้าของผู้ใช้ คืนค่าเลขออเดอร์
 * @throws CheckoutException เมื่อสั่งซื้อไม่ได้ด้วยเหตุผลที่ผู้ใช้แก้ไขได้ (สต็อกไม่พอ คูปองใช้ไม่ได้ ฯลฯ)
 */
function place_order(int $userId, array $addr, string $method, ?string $couponCode): string
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        // 1) ล็อกแถวตะกร้าของผู้ใช้ คำขอซ้ำ (กดสั่งซื้อรัว ๆ) จะรอจนคำขอแรกเสร็จแล้วเจอตะกร้าว่าง
        $pdo->prepare('SELECT id FROM cart_items WHERE user_id = ? FOR UPDATE')->execute([$userId]);

        $items = get_cart_items($userId);
        if (!$items) {
            throw new CheckoutException('ตะกร้าของคุณว่างเปล่า', 'cart.php');
        }
        // ล็อกสินค้าตามลำดับ id เดียวกันเสมอ ป้องกัน deadlock เมื่อหลายคนสั่งพร้อมกัน
        usort($items, fn($a, $b) => [(int)$a['product_id'], (int)$a['variant_id']] <=> [(int)$b['product_id'], (int)$b['variant_id']]);

        // 2) ล็อกแถวสินค้า/ตัวเลือก แล้วตรวจสถานะ ราคา สต็อก จากข้อมูลจริงล่าสุด
        $lockProduct = $pdo->prepare('SELECT name, price, stock, status, shop_id FROM products WHERE id = ? FOR UPDATE');
        $lockVariant = $pdo->prepare('SELECT variant_name, price, stock FROM product_variants WHERE id = ? AND product_id = ? FOR UPDATE');
        $shopStatus  = $pdo->prepare('SELECT status FROM shops WHERE id = ?');

        $lines = [];
        foreach ($items as $it) {
            $lockProduct->execute([$it['product_id']]);
            $p = $lockProduct->fetch();
            if (!$p || $p['status'] !== 'active') {
                throw new CheckoutException('สินค้า "' . $it['name'] . '" ปิดการขายแล้ว กรุณาลบออกจากตะกร้า', 'cart.php');
            }
            $shopStatus->execute([$p['shop_id']]);
            if ($shopStatus->fetchColumn() !== 'approved') {
                throw new CheckoutException('ร้านของสินค้า "' . $it['name'] . '" ไม่พร้อมขายในขณะนี้', 'cart.php');
            }

            $variantName = null;
            if ($it['variant_id'] !== null) {
                $lockVariant->execute([$it['variant_id'], $it['product_id']]);
                $v = $lockVariant->fetch();
                if (!$v) {
                    throw new CheckoutException('ตัวเลือกของ "' . $it['name'] . '" ไม่มีแล้ว กรุณาลบออกจากตะกร้า', 'cart.php');
                }
                $price       = $v['price'] !== null ? (float)$v['price'] : (float)$p['price'];
                $stock       = (int)$v['stock'];
                $variantName = $v['variant_name'];
            } else {
                $price = (float)$p['price'];
                $stock = (int)$p['stock'];
            }

            $qty = (int)$it['quantity'];
            if ($qty < 1 || $qty > $stock) {
                throw new CheckoutException('"' . $it['name'] . '" มีไม่พอ (เหลือ ' . $stock . ' ชิ้น) กรุณาปรับจำนวน', 'cart.php');
            }

            $lines[] = [
                'product_id'   => (int)$it['product_id'],
                'variant_id'   => $it['variant_id'] !== null ? (int)$it['variant_id'] : null,
                'shop_id'      => (int)$p['shop_id'],
                'name'         => $p['name'],
                'variant_name' => $variantName,
                'unit_price'   => $price,
                'quantity'     => $qty,
                'line_total'   => round($price * $qty, 2),
                'available'    => true,
            ];
        }

        // 3) คูปอง: ตรวจใหม่จากยอดจริง แล้วนับการใช้แบบ atomic (กันใช้เกินจำนวนเมื่อหลายคนใช้พร้อมกัน)
        $discount = 0.0;
        $couponId = null;
        if ($couponCode) {
            $res = validate_coupon($couponCode, calc_cart_totals($lines)['subtotal']);
            if (!$res['ok']) {
                throw new CheckoutException('คูปองใช้ไม่ได้: ' . $res['error'], 'cart.php');
            }
            $upd = $pdo->prepare('UPDATE coupons SET used_count = used_count + 1
                                  WHERE id = ? AND (usage_limit IS NULL OR used_count < usage_limit)');
            $upd->execute([$res['coupon']['id']]);
            if ($upd->rowCount() !== 1) {
                throw new CheckoutException('คูปองนี้ถูกใช้ครบจำนวนแล้ว', 'cart.php');
            }
            $discount = $res['discount'];
            $couponId = (int)$res['coupon']['id'];
        }
        $totals = calc_cart_totals($lines, $discount);

        // 4) สร้างออเดอร์ (เก็บที่อยู่ ณ ตอนสั่งไว้เป็น snapshot)
        $snapshot = json_encode([
            'recipient'    => $addr['recipient'],
            'phone'        => $addr['phone'],
            'address_line' => $addr['address_line'],
            'subdistrict'  => $addr['subdistrict'],
            'district'     => $addr['district'],
            'province'     => $addr['province'],
            'postcode'     => $addr['postcode'],
        ], JSON_UNESCAPED_UNICODE);

        $orderId = 0;
        $orderNo = '';
        for ($try = 0; $try < 5 && $orderId === 0; $try++) {
            $orderNo = generate_order_no();
            try {
                $ins = $pdo->prepare(
                    "INSERT INTO orders (order_no, buyer_id, address_snapshot, subtotal, shipping_fee, discount, total, coupon_id, payment_status, order_status)
                     VALUES (?,?,?,?,?,?,?,?, 'unpaid', 'pending')"
                );
                $ins->execute([$orderNo, $userId, $snapshot, $totals['subtotal'], $totals['shipping'], $totals['discount'], $totals['total'], $couponId]);
                $orderId = (int)$pdo->lastInsertId();
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }   // 1062 = เลขออเดอร์ซ้ำ -> สุ่มใหม่
            }
        }
        if ($orderId === 0) {
            throw new RuntimeException('สร้างเลขออเดอร์ไม่สำเร็จ');
        }

        // 5) รายการสินค้า (เก็บชื่อและราคา ณ ตอนซื้อ) + ตัดสต็อก
        $insItem = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, shop_id, product_name, variant_name, price, quantity)
             VALUES (?,?,?,?,?,?,?)'
        );
        $cutVariant = $pdo->prepare('UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?');
        $cutProduct = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');

        foreach ($lines as $l) {
            $insItem->execute([$orderId, $l['product_id'], $l['shop_id'], $l['name'], $l['variant_name'], $l['unit_price'], $l['quantity']]);
            $cut = $l['variant_id'] !== null ? $cutVariant : $cutProduct;
            $cut->execute([$l['quantity'], $l['variant_id'] ?? $l['product_id'], $l['quantity']]);
            if ($cut->rowCount() !== 1) {
                throw new CheckoutException('สต็อก "' . $l['name'] . '" ไม่พอ กรุณาลองใหม่', 'cart.php');
            }
        }

        // 6) บันทึกการชำระเงิน (รอชำระ), แจ้งเตือนร้านค้า, ล้างตะกร้า
        $pdo->prepare("INSERT INTO payments (order_id, method, amount, status) VALUES (?,?,?, 'pending')")
            ->execute([$orderId, $method, $totals['total']]);

        $ownerStmt = $pdo->prepare('SELECT user_id FROM shops WHERE id = ?');
        $notify    = $pdo->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?,?,?)');
        foreach (array_unique(array_column($lines, 'shop_id')) as $shopId) {
            $ownerStmt->execute([$shopId]);
            if ($ownerId = $ownerStmt->fetchColumn()) {
                $notify->execute([$ownerId, 'มีคำสั่งซื้อใหม่ ' . $orderNo, SELLER_URL . '/orders.php']);
            }
        }

        $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$userId]);

        $pdo->commit();
        return $orderNo;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* ------------------------------------------------------------------ */

require_login();
$userId = (int)current_user_id();

// วิธีชำระเงินที่เปิดให้เลือก
$methods = [
    'promptpay'     => ['PromptPay QR',          'สแกนจ่ายผ่านแอปธนาคาร แล้วแนบสลิป', true],
    'bank_transfer' => ['โอนเงินผ่านบัญชีธนาคาร', 'โอนตามเลขบัญชีที่แจ้ง แล้วแนบสลิป',  true],
    'cod'           => ['เก็บเงินปลายทาง',        'ชำระเงินสดเมื่อได้รับสินค้า',         true],
    'credit_card'   => ['บัตรเครดิต/เดบิต',       'เร็ว ๆ นี้',                         false],
];

/* ---------- ตรวจตะกร้าก่อนเข้าหน้านี้ ---------- */
sync_cart_stock($userId);
$items  = get_cart_items($userId);
$totals = calc_cart_totals($items);
if (!$items || $totals['has_unavailable'] || $totals['subtotal'] <= 0) {
    flash('error', $items ? 'มีสินค้าที่ซื้อไม่ได้ในตะกร้า กรุณาลบออกก่อน' : 'ตะกร้าของคุณว่างเปล่า');
    redirect('cart.php');
}

// คูปองที่เลือกไว้จากหน้าตะกร้า
$couponCode = null;
$discount   = 0.0;
if (!empty($_SESSION['coupon_code'])) {
    $res = validate_coupon((string)$_SESSION['coupon_code'], $totals['subtotal']);
    if ($res['ok']) {
        $couponCode = $res['coupon']['code'];
        $discount   = $res['discount'];
    } else {
        unset($_SESSION['coupon_code']);
        flash('error', 'คูปองถูกยกเลิก: ' . $res['error']);
    }
}
$totals = calc_cart_totals($items, $discount);

// ที่อยู่ของผู้ใช้
$addrStmt = db()->prepare('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC');
$addrStmt->execute([$userId]);
$addresses = $addrStmt->fetchAll();

/* ---------- กดสั่งซื้อ ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $method = (string)($_POST['payment_method'] ?? '');
        if (!isset($methods[$method]) || !$methods[$method][2]) {
            throw new CheckoutException('กรุณาเลือกวิธีชำระเงิน');
        }
        $addr = null;
        foreach ($addresses as $a) {                      // ต้องเป็นที่อยู่ของผู้ใช้คนนี้เท่านั้น
            if ((int)$a['id'] === (int)($_POST['address_id'] ?? 0)) { $addr = $a; break; }
        }
        if (!$addr) {
            throw new CheckoutException('กรุณาเลือกที่อยู่จัดส่ง');
        }

        $orderNo = place_order($userId, $addr, $method, $couponCode);
        unset($_SESSION['coupon_code']);
        flash('success', 'สั่งซื้อสำเร็จ เลขที่คำสั่งซื้อ ' . $orderNo);
        redirect('payment.php?order=' . urlencode($orderNo));

    } catch (CheckoutException $e) {
        flash('error', $e->getMessage());
        redirect($e->redirect);
    } catch (Throwable $e) {
        error_log('Checkout failed: ' . $e->getMessage());
        flash('error', 'ระบบขัดข้อง ไม่สามารถสร้างคำสั่งซื้อได้ กรุณาลองใหม่อีกครั้ง');
        redirect('checkout.php');
    }
}

$pageTitle = 'ชำระเงิน';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="h3 fw-bold mb-4">ชำระเงิน</h1>

<form method="post" action="checkout.php" id="checkout-form">
  <?= csrf_field() ?>
  <div class="row g-4">

    <div class="col-lg-8">

      <section class="mb-4" aria-labelledby="addr-title">
        <div class="d-flex justify-content-between align-items-baseline mb-2">
          <h2 class="h5 fw-bold mb-0" id="addr-title">ที่อยู่จัดส่ง</h2>
          <a href="addresses.php?return=checkout">จัดการที่อยู่</a>
        </div>

        <?php if (!$addresses): ?>
          <div class="alert alert-warning mb-0">
            คุณยังไม่มีที่อยู่จัดส่ง <a href="addresses.php?return=checkout">เพิ่มที่อยู่ใหม่</a> แล้วกลับมาสั่งซื้อต่อได้เลย (ตะกร้าจะยังอยู่)
          </div>
        <?php else: ?>
          <div class="d-grid gap-2" role="radiogroup">
            <?php foreach ($addresses as $i => $a): ?>
              <label class="tk-option">
                <input class="form-check-input me-2" type="radio" name="address_id" value="<?= (int)$a['id'] ?>" required
                       <?= ((int)$a['is_default'] === 1 || ($i === 0 && !array_filter($addresses, fn($x) => (int)$x['is_default'] === 1))) ? 'checked' : '' ?>>
                <strong><?= e($a['recipient']) ?></strong> <span class="text-secondary">(<?= e($a['phone']) ?>)</span>
                <?php if ((int)$a['is_default'] === 1): ?><span class="badge tk-badge ms-1">ค่าเริ่มต้น</span><?php endif; ?>
                <div class="small ms-4">
                  <?= e($a['address_line']) ?> <?= e($a['subdistrict']) ?> <?= e($a['district']) ?> <?= e($a['province']) ?> <?= e($a['postcode']) ?>
                </div>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="mb-4" aria-labelledby="pay-title">
        <h2 class="h5 fw-bold mb-2" id="pay-title">วิธีชำระเงิน</h2>
        <div class="d-grid gap-2" role="radiogroup">
          <?php foreach ($methods as $key => [$label, $desc, $enabled]): ?>
            <label class="tk-option <?= $enabled ? '' : 'tk-option-off' ?>">
              <input class="form-check-input me-2" type="radio" name="payment_method" value="<?= e($key) ?>"
                     <?= $enabled ? 'required' : 'disabled' ?>>
              <strong><?= e($label) ?></strong>
              <span class="small text-secondary ms-1"><?= e($desc) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </section>

      <section aria-labelledby="items-title">
        <h2 class="h5 fw-bold mb-2" id="items-title">รายการสินค้า</h2>
        <?php foreach ($items as $it): ?>
          <div class="d-flex gap-3 py-2 border-bottom align-items-center">
            <img class="tk-cart-img" style="width:56px;height:56px" src="<?= e(upload_url($it['image'])) ?>" alt="">
            <div class="flex-grow-1">
              <div><?= e($it['name']) ?></div>
              <div class="small text-secondary">
                <?= $it['variant_name'] ? e($it['variant_name']) . ' · ' : '' ?>ร้าน <?= e($it['shop_name']) ?> · <?= e(money($it['unit_price'])) ?> × <?= (int)$it['quantity'] ?>
              </div>
            </div>
            <div class="fw-bold"><?= e(money($it['line_total'])) ?></div>
          </div>
        <?php endforeach; ?>
        <a class="small" href="cart.php">แก้ไขตะกร้า</a>
      </section>
    </div>

    <aside class="col-lg-4">
      <div class="tk-box">
        <h2 class="h6 fw-bold mb-3">สรุปคำสั่งซื้อ</h2>
        <dl class="row mb-2 small">
          <dt class="col-7 fw-normal">ยอดสินค้า (<?= (int)$totals['item_count'] ?> ชิ้น)</dt>
          <dd class="col-5 text-end"><?= e(money($totals['subtotal'])) ?></dd>
          <?php if ($totals['discount'] > 0): ?>
            <dt class="col-7 fw-normal">ส่วนลด (<?= e((string)$couponCode) ?>)</dt>
            <dd class="col-5 text-end text-success">-<?= e(money($totals['discount'])) ?></dd>
          <?php endif; ?>
          <dt class="col-7 fw-normal">ค่าจัดส่ง</dt>
          <dd class="col-5 text-end"><?= $totals['shipping'] > 0 ? e(money($totals['shipping'])) : 'ส่งฟรี' ?></dd>
        </dl>
        <div class="d-flex justify-content-between border-top pt-3 mb-3">
          <span class="fw-bold">ยอดรวมสุทธิ</span>
          <span class="fw-bold fs-5 tk-price"><?= e(money($totals['total'])) ?></span>
        </div>
        <button type="submit" id="place-btn" class="btn tk-btn-primary w-100 btn-lg" <?= $addresses ? '' : 'disabled' ?>>ยืนยันการสั่งซื้อ</button>
        <p class="small text-secondary mt-2 mb-0">ราคาและสต็อกจะถูกตรวจสอบอีกครั้งตอนยืนยันคำสั่งซื้อ</p>
      </div>
    </aside>
  </div>
</form>

<script>
  // กันกดซ้ำ: ปิดปุ่มทันทีเมื่อกดส่ง
  document.getElementById('checkout-form').addEventListener('submit', function () {
    var b = document.getElementById('place-btn');
    if (b) { setTimeout(function () { b.disabled = true; b.textContent = 'กำลังสร้างคำสั่งซื้อ...'; }, 0); }
  });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>