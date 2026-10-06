<?php
/**
 * public/payment.php - หน้าชำระเงินของออเดอร์
 * ?order=เลขออเดอร์
 * แสดงวิธีชำระ (PromptPay QR / เลขบัญชี / เก็บเงินปลายทาง) และรับการแนบสลิป
 * แอดมินจะตรวจสลิปและเปลี่ยนสถานะเป็น "ชำระแล้ว" ที่ admin/payments.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payment_helpers.php';

require_login();
$userId  = (int)current_user_id();
$orderNo = (string)($_GET['order'] ?? '');

/* ---------- 1) โหลดออเดอร์ (ต้องเป็นของผู้ใช้คนนี้เท่านั้น) ---------- */
$stmt = db()->prepare(
    "SELECT o.*, p.id AS payment_id, p.method, p.slip_image
     FROM orders o JOIN payments p ON p.order_id = o.id
     WHERE o.order_no = ? AND o.buyer_id = ?
     ORDER BY p.id DESC LIMIT 1"
);
$stmt->execute([$orderNo, $userId]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    flash('error', 'ไม่พบคำสั่งซื้อนี้');
    redirect('orders.php');
}

$isTransfer = in_array($order['method'], ['promptpay', 'bank_transfer'], true);
$canPay     = $isTransfer
    && in_array($order['payment_status'], ['unpaid', 'waiting_verify'], true)
    && $order['order_status'] !== 'cancelled';

/* ---------- 2) แนบสลิป (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_slip') {
    require_csrf();
    $back = 'payment.php?order=' . urlencode($orderNo);

    if (!$canPay) {
        flash('error', 'คำสั่งซื้อนี้ไม่สามารถแนบสลิปได้');
        redirect($back);
    }

    $up = upload_image($_FILES['slip'] ?? [], 'slips');
    if (!$up['ok']) {
        flash('error', $up['error']);
        redirect($back);
    }

    try {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE payments SET slip_image = ?, status = 'pending' WHERE id = ?")
            ->execute([$up['path'], $order['payment_id']]);
        $pdo->prepare("UPDATE orders SET payment_status = 'waiting_verify' WHERE id = ? AND payment_status IN ('unpaid','waiting_verify')")
            ->execute([$order['id']]);

        // แจ้งแอดมินทุกคนให้ตรวจสลิป
        $notify = $pdo->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?,?,?)');
        foreach ($pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_banned = 0")->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            $notify->execute([$adminId, 'มีสลิปรอตรวจสอบ ออเดอร์ ' . $orderNo, ADMIN_URL . '/payments.php']);
        }
        $pdo->commit();

        delete_upload($order['slip_image']);          // ลบสลิปเก่าหลังบันทึกใหม่สำเร็จ
        flash('success', 'ส่งสลิปแล้ว ทีมงานจะตรวจสอบและยืนยันการชำระเงินโดยเร็ว');
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        delete_upload($up['path']);                   // บันทึกไม่สำเร็จ ลบไฟล์ใหม่ทิ้ง
        error_log('Slip upload failed: ' . $e->getMessage());
        flash('error', 'ระบบขัดข้อง กรุณาลองใหม่อีกครั้ง');
    }
    redirect($back);
}

/* ---------- 3) ข้อมูลประกอบ ---------- */
$itemStmt = db()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
$itemStmt->execute([$order['id']]);
$items = $itemStmt->fetchAll();

$addr = json_decode((string)$order['address_snapshot'], true) ?: [];

$qrPayload = null;
if ($order['method'] === 'promptpay' && $canPay) {
    $qrPayload = promptpay_payload(PROMPTPAY_ID, (float)$order['total']);
}

$payLabels = [
    'unpaid'         => ['รอชำระเงิน',        'text-bg-warning'],
    'waiting_verify' => ['รอตรวจสอบสลิป',     'text-bg-info'],
    'paid'           => ['ชำระเงินแล้ว',       'text-bg-success'],
    'refunded'       => ['คืนเงินแล้ว',        'text-bg-secondary'],
];
$methodLabels = [
    'promptpay' => 'PromptPay QR', 'bank_transfer' => 'โอนเงินผ่านบัญชีธนาคาร',
    'credit_card' => 'บัตรเครดิต/เดบิต', 'cod' => 'เก็บเงินปลายทาง',
];
[$payText, $payClass] = $payLabels[$order['payment_status']] ?? [$order['payment_status'], 'text-bg-secondary'];

$pageTitle = 'ชำระเงิน ' . $orderNo;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <div>
    <h1 class="h3 fw-bold mb-1">คำสั่งซื้อ <?= e($orderNo) ?></h1>
    <div class="small text-secondary">สั่งเมื่อ <?= e(format_datetime($order['created_at'])) ?> · <?= e($methodLabels[$order['method']] ?? $order['method']) ?></div>
  </div>
  <span class="badge fs-6 <?= e($payClass) ?>"><?= e($payText) ?></span>
</div>

<?php if ($order['order_status'] === 'cancelled'): ?>
  <div class="alert alert-secondary">คำสั่งซื้อนี้ถูกยกเลิกแล้ว</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-7">

    <?php if ($order['payment_status'] === 'paid'): ?>
      <div class="tk-box">
        <h2 class="h5 fw-bold">ชำระเงินเรียบร้อยแล้ว</h2>
        <p class="mb-0">ขอบคุณที่ใช้บริการ ร้านค้ากำลังเตรียมจัดส่งสินค้าให้คุณ ติดตามสถานะได้ที่ <a href="orders.php">คำสั่งซื้อของฉัน</a></p>
      </div>

    <?php elseif ($order['method'] === 'cod'): ?>
      <div class="tk-box">
        <h2 class="h5 fw-bold">เก็บเงินปลายทาง</h2>
        <p class="mb-1">เตรียมเงินสด <strong><?= e(money($order['total'])) ?></strong> ไว้ชำระกับพนักงานขนส่งเมื่อได้รับสินค้า</p>
        <p class="small text-secondary mb-0">ไม่ต้องโอนเงินล่วงหน้า ร้านค้าจะจัดส่งหลังได้รับคำสั่งซื้อของคุณ</p>
      </div>

    <?php elseif ($canPay): ?>
      <div class="tk-box mb-4">
        <h2 class="h5 fw-bold mb-3">ยอดที่ต้องชำระ <span class="tk-price"><?= e(money($order['total'])) ?></span></h2>

        <?php if ($order['method'] === 'promptpay'): ?>
          <?php if ($qrPayload): ?>
            <p class="mb-2">สแกน QR ด้วยแอปธนาคาร ยอดเงินจะถูกกรอกให้อัตโนมัติ</p>
            <div id="qr" class="bg-white d-inline-block p-3 rounded-3 mb-2" aria-label="PromptPay QR"></div>
            <noscript><p class="text-danger">กรุณาเปิดใช้ JavaScript เพื่อแสดง QR</p></noscript>
            <p class="small text-secondary mb-0" id="qr-fallback" hidden>
              แสดง QR ไม่ได้ ให้โอนเข้า PromptPay หมายเลข <strong><?= e(PROMPTPAY_ID) ?></strong> ยอด <?= e(money($order['total'])) ?>
            </p>
          <?php else: ?>
            <div class="alert alert-warning mb-0">ระบบสร้าง QR ไม่ได้ (ยังไม่ได้ตั้งค่าหมายเลข PromptPay) กรุณาติดต่อผู้ดูแลเว็บไซต์</div>
          <?php endif; ?>

        <?php else: ?>
          <p class="mb-2">โอนเงินเข้าบัญชีต่อไปนี้ ตามยอดที่ระบุ</p>
          <dl class="row mb-0">
            <dt class="col-4 fw-normal">ธนาคาร</dt><dd class="col-8"><?= e(BANK_NAME) ?></dd>
            <dt class="col-4 fw-normal">เลขที่บัญชี</dt><dd class="col-8 fw-bold"><?= e(BANK_ACCOUNT) ?></dd>
            <dt class="col-4 fw-normal">ชื่อบัญชี</dt><dd class="col-8"><?= e(BANK_ACCT_NAME) ?></dd>
          </dl>
        <?php endif; ?>
      </div>

      <div class="tk-box">
        <h2 class="h6 fw-bold">แนบสลิปการโอนเงิน</h2>
        <?php if ($order['slip_image']): ?>
          <p class="small mb-2">สลิปที่ส่งแล้ว (อยู่ระหว่างตรวจสอบ) ส่งใหม่ได้หากแนบผิด</p>
          <img class="mb-3" style="max-width:180px;border-radius:10px" src="<?= e(upload_url($order['slip_image'])) ?>" alt="สลิปที่แนบไว้">
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" action="payment.php?order=<?= e(urlencode($orderNo)) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload_slip">
          <label class="form-label" for="slip">ไฟล์สลิป (JPG, PNG, WEBP ไม่เกิน <?= (int)(MAX_UPLOAD_BYTES / 1024 / 1024) ?> MB)</label>
          <input class="form-control mb-3" type="file" id="slip" name="slip" accept="image/jpeg,image/png,image/webp" required>
          <button class="btn tk-btn-green" type="submit"><?= $order['slip_image'] ? 'ส่งสลิปใหม่' : 'ส่งสลิป' ?></button>
        </form>
      </div>
    <?php endif; ?>

    <p class="mt-4"><a href="orders.php">ดูคำสั่งซื้อทั้งหมด</a> · <a href="products.php">เลือกซื้อสินค้าต่อ</a></p>
  </div>

  <aside class="col-lg-5">
    <div class="tk-box">
      <h2 class="h6 fw-bold mb-3">สรุปคำสั่งซื้อ</h2>
      <?php foreach ($items as $it): ?>
        <div class="d-flex justify-content-between small mb-2">
          <span><?= e($it['product_name']) ?><?= $it['variant_name'] ? ' (' . e($it['variant_name']) . ')' : '' ?> × <?= (int)$it['quantity'] ?></span>
          <span><?= e(money((float)$it['price'] * (int)$it['quantity'])) ?></span>
        </div>
      <?php endforeach; ?>
      <hr>
      <dl class="row small mb-2">
        <dt class="col-7 fw-normal">ยอดสินค้า</dt><dd class="col-5 text-end"><?= e(money($order['subtotal'])) ?></dd>
        <?php if ((float)$order['discount'] > 0): ?>
          <dt class="col-7 fw-normal">ส่วนลด</dt><dd class="col-5 text-end text-success">-<?= e(money($order['discount'])) ?></dd>
        <?php endif; ?>
        <dt class="col-7 fw-normal">ค่าจัดส่ง</dt><dd class="col-5 text-end"><?= (float)$order['shipping_fee'] > 0 ? e(money($order['shipping_fee'])) : 'ส่งฟรี' ?></dd>
      </dl>
      <div class="d-flex justify-content-between border-top pt-2">
        <strong>ยอดรวมสุทธิ</strong><strong class="tk-price"><?= e(money($order['total'])) ?></strong>
      </div>

      <?php if ($addr): ?>
        <hr>
        <div class="small">
          <div class="fw-bold mb-1">จัดส่งไปที่</div>
          <?= e($addr['recipient'] ?? '') ?> (<?= e($addr['phone'] ?? '') ?>)<br>
          <?= e(trim(($addr['address_line'] ?? '') . ' ' . ($addr['subdistrict'] ?? '') . ' ' . ($addr['district'] ?? '') . ' ' . ($addr['province'] ?? '') . ' ' . ($addr['postcode'] ?? ''))) ?>
        </div>
      <?php endif; ?>
    </div>
  </aside>
</div>

<?php if ($qrPayload): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
  (function () {
    var box = document.getElementById('qr');
    if (typeof QRCode === 'undefined') {
      document.getElementById('qr-fallback').hidden = false;
      box.remove();
      return;
    }
    new QRCode(box, {
      text: <?= json_encode($qrPayload) ?>,
      width: 220, height: 220,
      correctLevel: QRCode.CorrectLevel.M
    });
  })();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>