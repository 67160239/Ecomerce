<?php
/**
 * includes/footer.php
 * ปิด <main> ที่ header.php เปิดไว้ + ส่วนท้ายเว็บ + โหลด JavaScript
 * (header.php ต้องถูกเรียกก่อนเสมอ)
 */
declare(strict_types=1);
?>
</main>

<footer class="tk-footer mt-5 py-5">
  <div class="container">
    <div class="row g-4">
      <div class="col-md-5">
        <div class="h4 fw-bold text-white mb-2">takra</div>
        <p class="mb-0"><?= e(APP_TAGLINE) ?> ซื้อได้ ขายได้ ในที่เดียว</p>
      </div>
      <div class="col-6 col-md-3">
        <div class="fw-semibold text-white mb-2">ช้อปปิ้ง</div>
        <ul class="list-unstyled mb-0">
          <li><a href="<?= e(BASE_URL) ?>/products.php">สินค้าทั้งหมด</a></li>
          <li><a href="<?= e(BASE_URL) ?>/orders.php">ติดตามคำสั่งซื้อ</a></li>
          <li><a href="<?= e(BASE_URL) ?>/wishlist.php">รายการโปรด</a></li>
        </ul>
      </div>
      <div class="col-6 col-md-4">
        <div class="fw-semibold text-white mb-2">ขายกับเรา</div>
        <ul class="list-unstyled mb-0">
          <li><a href="<?= e(BASE_URL) ?>/seller_apply.php">เปิดร้านค้า</a></li>
          <li><a href="<?= e(SELLER_URL) ?>/dashboard.php">จัดการร้านค้า</a></li>
        </ul>
      </div>
    </div>
    <hr class="border-light opacity-25 my-4">
    <div class="small">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. สงวนลิขสิทธิ์</div>
  </div>
</footer>

<!-- พื้นที่แสดง toast แจ้งเตือนมุมจอ (ใช้โดย app.js) -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="tk-toasts" aria-live="polite" aria-atomic="true"></div>

<script>
  // ค่ากลางให้ JavaScript ใช้เรียก API
  window.TAKRA = {
    baseUrl: <?= json_encode(BASE_URL) ?>,
    apiUrl:  <?= json_encode(API_URL) ?>,
    csrf:    <?= json_encode(csrf_token()) ?>,
    loggedIn: <?= is_logged_in() ? 'true' : 'false' ?>
  };
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php if (!empty($extraScripts) && is_array($extraScripts)): ?>
  <?php foreach ($extraScripts as $src): ?>
    <script src="<?= e(asset($src)) ?>"></script>
  <?php endforeach; ?>
<?php endif; ?>
</body>
</html>