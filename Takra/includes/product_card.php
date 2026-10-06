<?php
/**
 * includes/product_card.php
 * การ์ดสินค้า 1 ใบ ใช้ซ้ำทั้งเว็บ (หน้าแรก, ค้นหา, รายการโปรด, หน้าร้าน)
 *
 * วิธีใช้:  foreach ($products as $p) { include __DIR__ . '/../includes/product_card.php'; }
 * ต้องมีคีย์ใน $p: id, name, price, avg_rating, review_count, sold_count, stock, shop_name, image
 */
declare(strict_types=1);

$pUrl = BASE_URL . '/product.php?id=' . (int)$p['id'];
?>
<div class="col-6 col-md-4 col-lg-3">
  <a class="tk-product d-block text-decoration-none text-reset" href="<?= e($pUrl) ?>">
    <div class="position-relative">
      <img src="<?= e(upload_url($p['image'] ?? null)) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
      <?php if ((int)$p['stock'] <= 0): ?>
        <span class="badge text-bg-dark position-absolute top-0 start-0 m-2">สินค้าหมด</span>
      <?php endif; ?>
    </div>
    <div class="p-3">
      <div class="tk-card-title mb-1"><?= e($p['name']) ?></div>
      <div class="small text-secondary mb-2"><?= e($p['shop_name']) ?></div>
      <div class="tk-price"><?= e(money($p['price'])) ?></div>
      <div class="small mt-1 d-flex justify-content-between">
        <span>
          <?php if ((int)$p['review_count'] > 0): ?>
            <span class="tk-stars" aria-hidden="true">★</span>
            <?= number_format((float)$p['avg_rating'], 1) ?>
            <span class="text-secondary">(<?= (int)$p['review_count'] ?>)</span>
          <?php else: ?>
            <span class="text-secondary">ยังไม่มีรีวิว</span>
          <?php endif; ?>
        </span>
        <span class="text-secondary">ขายแล้ว <?= number_format((int)$p['sold_count']) ?></span>
      </div>
    </div>
  </a>
</div>