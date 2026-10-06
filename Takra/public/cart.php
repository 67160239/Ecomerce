<?php
/**
 * public/cart.php - ตะกร้าสินค้า
 * แก้จำนวน, ลบรายการ, ใช้/ยกเลิกคูปอง, สรุปยอด, ไปหน้าชำระเงิน
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cart_helpers.php';

require_login();
$userId = (int)current_user_id();

/* ---------- 1) จัดการ POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)($_POST['action'] ?? '');

    // ลบ 1 รายการ (ปุ่ม name="remove" value="id")
    if (isset($_POST['remove'])) {
        $del = db()->prepare('DELETE FROM cart_items WHERE id = ? AND user_id = ?');
        $del->execute([(int)$_POST['remove'], $userId]);
        flash('success', 'ลบสินค้าออกจากตะกร้าแล้ว');

    // อัปเดตจำนวนทั้งหมด
    } elseif ($action === 'update') {
        $changed = false;
        $byId = [];                                      // ใช้ข้อมูลจริงจากฐานข้อมูล ไม่เชื่อค่าจากฟอร์ม
        foreach (get_cart_items($userId) as $it) {
            $byId[(int)$it['id']] = $it;
        }
        foreach ((array)($_POST['qty'] ?? []) as $cartId => $qty) {
            $cartId = (int)$cartId;
            $qty    = (int)$qty;
            $found  = $byId[$cartId] ?? null;            // ไม่ใช่ของผู้ใช้คนนี้ = ไม่พบ
            if (!$found || !$found['available']) { continue; }

            if ($qty <= 0) {
                db()->prepare('DELETE FROM cart_items WHERE id = ? AND user_id = ?')->execute([$cartId, $userId]);
                $changed = true;
                continue;
            }
            $newQty = min($qty, $found['stock']);
            if ($newQty !== $qty) {
                flash('info', '"' . $found['name'] . '" เหลือในสต็อก ' . $found['stock'] . ' ชิ้น ระบบปรับจำนวนให้แล้ว');
            }
            if ($newQty !== $found['quantity']) {
                db()->prepare('UPDATE cart_items SET quantity = ? WHERE id = ? AND user_id = ?')->execute([$newQty, $cartId, $userId]);
                $changed = true;
            }
        }
        if ($changed) { flash('success', 'อัปเดตตะกร้าแล้ว'); }

    // ใช้คูปอง
    } elseif ($action === 'apply_coupon') {
        $totals = calc_cart_totals(get_cart_items($userId));
        $res    = validate_coupon((string)($_POST['coupon'] ?? ''), $totals['subtotal']);
        if ($res['ok']) {
            $_SESSION['coupon_code'] = $res['coupon']['code'];
            flash('success', 'ใช้คูปอง ' . $res['coupon']['code'] . ' แล้ว');
        } else {
            flash('error', $res['error']);
        }

    // ยกเลิกคูปอง
    } elseif ($action === 'remove_coupon') {
        unset($_SESSION['coupon_code']);
        flash('info', 'ยกเลิกคูปองแล้ว');
    }

    redirect('cart.php');
}

/* ---------- 2) เตรียมข้อมูลแสดงผล ---------- */
if (sync_cart_stock($userId) > 0) {
    flash('info', 'ระบบปรับจำนวนสินค้าบางรายการตามสต็อกที่เหลืออยู่');
}
$items = get_cart_items($userId);

// ตรวจคูปองที่เลือกไว้ซ้ำทุกครั้ง (ยอดอาจเปลี่ยน หรือคูปองหมดอายุระหว่างนั้น)
$coupon   = null;
$discount = 0.0;
if (!empty($_SESSION['coupon_code'])) {
    $sub = calc_cart_totals($items)['subtotal'];
    $res = validate_coupon((string)$_SESSION['coupon_code'], $sub);
    if ($res['ok']) {
        $coupon   = $res['coupon'];
        $discount = $res['discount'];
    } else {
        unset($_SESSION['coupon_code']);
        flash('error', 'คูปองถูกยกเลิก: ' . $res['error']);
    }
}
$totals = calc_cart_totals($items, $discount);

$byShop = [];
foreach ($items as $it) {
    $byShop[$it['shop_name']][] = $it;
}

$pageTitle = 'ตะกร้าสินค้า';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="h3 fw-bold mb-4">ตะกร้าสินค้า</h1>

<?php if (!$items): ?>
  <div class="text-center py-5">
    <h2 class="h5 fw-bold">ตะกร้าของคุณยังว่างอยู่</h2>
    <p class="text-secondary">เลือกสินค้าที่ถูกใจแล้วใส่ตะกร้าได้เลย</p>
    <a class="btn tk-btn-primary" href="products.php">เลือกซื้อสินค้า</a>
  </div>
<?php else: ?>
<div class="row g-4">

  <div class="col-lg-8">
    <form method="post" action="cart.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">

      <?php foreach ($byShop as $shopName => $shopItems): ?>
        <section class="mb-4" aria-label="สินค้าจากร้าน <?= e($shopName) ?>">
          <h2 class="h6 fw-bold border-bottom pb-2">ร้าน <?= e($shopName) ?></h2>

          <?php foreach ($shopItems as $it): ?>
            <div class="d-flex gap-3 py-3 border-bottom <?= $it['available'] ? '' : 'tk-unavailable' ?>">
              <a href="product.php?id=<?= (int)$it['product_id'] ?>">
                <img class="tk-cart-img" src="<?= e(upload_url($it['image'])) ?>" alt="<?= e($it['name']) ?>">
              </a>

              <div class="flex-grow-1">
                <a class="fw-medium text-decoration-none" href="product.php?id=<?= (int)$it['product_id'] ?>"><?= e($it['name']) ?></a>
                <?php if ($it['variant_name']): ?><div class="small text-secondary"><?= e($it['variant_name']) ?></div><?php endif; ?>
                <div class="small"><?= e(money($it['unit_price'])) ?></div>

                <?php if ($it['available']): ?>
                  <div class="d-flex align-items-center gap-2 mt-2">
                    <label class="visually-hidden" for="q<?= (int)$it['id'] ?>">จำนวน</label>
                    <input class="form-control form-control-sm" style="width:80px" type="number"
                           id="q<?= (int)$it['id'] ?>" name="qty[<?= (int)$it['id'] ?>]"
                           value="<?= (int)$it['quantity'] ?>" min="0" max="<?= (int)$it['stock'] ?>">
                    <span class="small text-secondary">คงเหลือ <?= (int)$it['stock'] ?></span>
                  </div>
                <?php else: ?>
                  <div class="small text-danger mt-2"><?= e($it['reason']) ?> (ไม่นับรวมในยอดชำระ กรุณาลบออก)</div>
                <?php endif; ?>
              </div>

              <div class="text-end">
                <div class="fw-bold"><?= $it['available'] ? e(money($it['line_total'])) : '-' ?></div>
                <button type="submit" name="remove" value="<?= (int)$it['id'] ?>" class="btn btn-link btn-sm text-danger p-0 mt-2">ลบ</button>
              </div>
            </div>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>

      <button type="submit" class="btn btn-outline-secondary">อัปเดตจำนวน</button>
    </form>
  </div>

  <aside class="col-lg-4">
    <div class="tk-box">
      <h2 class="h6 fw-bold mb-3">สรุปคำสั่งซื้อ</h2>

      <!-- คูปอง -->
      <?php if ($coupon): ?>
        <form method="post" action="cart.php" class="d-flex justify-content-between align-items-center mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="remove_coupon">
          <span class="small">คูปอง <strong><?= e($coupon['code']) ?></strong></span>
          <button class="btn btn-link btn-sm text-danger p-0" type="submit">ยกเลิก</button>
        </form>
      <?php else: ?>
        <form method="post" action="cart.php" class="d-flex gap-2 mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="apply_coupon">
          <label class="visually-hidden" for="coupon">รหัสคูปอง</label>
          <input class="form-control form-control-sm" id="coupon" name="coupon" placeholder="รหัสคูปอง" maxlength="30" autocomplete="off">
          <button class="btn btn-sm tk-btn-green" type="submit">ใช้</button>
        </form>
      <?php endif; ?>

      <dl class="row mb-2 small">
        <dt class="col-7 fw-normal">ยอดสินค้า (<?= (int)$totals['item_count'] ?> ชิ้น)</dt>
        <dd class="col-5 text-end"><?= e(money($totals['subtotal'])) ?></dd>

        <?php if ($totals['discount'] > 0): ?>
          <dt class="col-7 fw-normal">ส่วนลด</dt>
          <dd class="col-5 text-end text-success">-<?= e(money($totals['discount'])) ?></dd>
        <?php endif; ?>

        <dt class="col-7 fw-normal">ค่าจัดส่ง</dt>
        <dd class="col-5 text-end"><?= $totals['shipping'] > 0 ? e(money($totals['shipping'])) : 'ส่งฟรี' ?></dd>
      </dl>

      <?php if ($totals['free_ship_gap'] > 0): ?>
        <div class="small text-secondary mb-2">ซื้อเพิ่มอีก <?= e(money($totals['free_ship_gap'])) ?> ส่งฟรี</div>
      <?php endif; ?>

      <div class="d-flex justify-content-between border-top pt-3 mb-3">
        <span class="fw-bold">ยอดรวมสุทธิ</span>
        <span class="fw-bold fs-5 tk-price"><?= e(money($totals['total'])) ?></span>
      </div>

      <?php if ($totals['has_unavailable']): ?>
        <div class="alert alert-warning small">มีสินค้าที่ซื้อไม่ได้ในตะกร้า กรุณาลบออกก่อนชำระเงิน</div>
        <button class="btn tk-btn-primary w-100" disabled>ไปชำระเงิน</button>
      <?php elseif ($totals['subtotal'] <= 0): ?>
        <button class="btn tk-btn-primary w-100" disabled>ไปชำระเงิน</button>
      <?php else: ?>
        <a class="btn tk-btn-primary w-100" href="checkout.php">ไปชำระเงิน</a>
      <?php endif; ?>
    </div>
  </aside>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>