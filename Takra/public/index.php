<?php
/**
 * public/index.php - หน้าแรก
 * แสดง: ส่วนต้อนรับ, หมวดหมู่, สินค้าขายดี, สินค้ามาใหม่
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

/** ดึงสินค้าที่เปิดขายอยู่ (เฉพาะร้านที่อนุมัติแล้ว) เรียงตามที่กำหนด */
function fetch_home_products(string $orderBy, int $limit): array
{
    $allowed = ['p.sold_count DESC, p.id DESC', 'p.created_at DESC, p.id DESC'];
    if (!in_array($orderBy, $allowed, true)) {   // ป้องกันการใส่ค่าอื่นเข้า ORDER BY
        $orderBy = $allowed[1];
    }
    $sql = "SELECT p.id, p.name, p.price, p.stock, p.avg_rating, p.review_count, p.sold_count,
                   s.shop_name,
                   (SELECT image_path FROM product_images
                     WHERE product_id = p.id ORDER BY sort_order, id LIMIT 1) AS image
            FROM products p
            JOIN shops s ON s.id = p.shop_id AND s.status = 'approved'
            WHERE p.status = 'active'
            ORDER BY $orderBy
            LIMIT " . (int)$limit;
    return db()->query($sql)->fetchAll();
}

$categories  = db()->query('SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name')->fetchAll();
$bestSellers = fetch_home_products('p.sold_count DESC, p.id DESC', 8);
$newArrivals = fetch_home_products('p.created_at DESC, p.id DESC', 8);

$pageTitle = 'ตลาดของทุกคน';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="tk-hero mb-5">
  <div class="row align-items-center g-4">
    <div class="col-lg-7">
      <h1 class="display-5 fw-bold mb-3">ซื้อของที่ใช่ ขายของที่รัก<br>ในตะกร้าใบเดียว</h1>
      <p class="lead mb-4">ตลาดออนไลน์ที่ใครก็เปิดร้านได้ ชำระเงินสะดวก รีวิวจากคนที่ซื้อจริง</p>
      <div class="d-flex flex-wrap gap-2">
        <a href="<?= e(BASE_URL) ?>/products.php" class="btn btn-lg tk-btn-primary">เลือกซื้อสินค้า</a>
        <?php if (!has_role(['seller', 'admin'])): ?>
          <a href="<?= e(BASE_URL) ?>/seller_apply.php" class="btn btn-lg btn-outline-light">เปิดร้านฟรี</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-lg-5 d-none d-lg-block text-center">
      <svg width="220" height="220" viewBox="0 0 120 120" role="img" aria-label="ตะกร้า Takra">
        <rect width="120" height="120" rx="30" fill="#F2A20C"/>
        <path d="M30 52 C30 12, 90 12, 90 52" fill="none" stroke="#0F4C4A" stroke-width="8" stroke-linecap="round"/>
        <path d="M16 52 H104 L92 101 Q90 106 85 106 H35 Q30 106 28 101 Z" fill="#0F4C4A"/>
        <path d="M24 70 H96 M28 88 H92 M47 56 L43 104 M60 56 V104 M73 56 L77 104"
              stroke="#F2A20C" stroke-width="4.5" stroke-linecap="round" fill="none"/>
      </svg>
    </div>
  </div>
</section>

<?php if ($categories): ?>
<section class="mb-5" aria-labelledby="cat-title">
  <h2 class="h4 fw-bold mb-3" id="cat-title">หมวดหมู่</h2>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($categories as $c): ?>
      <a class="tk-chip" href="<?= e(BASE_URL) ?>/products.php?category=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if (!$bestSellers && !$newArrivals): ?>
  <section class="text-center py-5">
    <h2 class="h4 fw-bold">ยังไม่มีสินค้าในตลาด</h2>
    <p class="text-secondary">เป็นคนแรกที่นำสินค้ามาวางขายบน Takra</p>
    <a href="<?= e(BASE_URL) ?>/seller_apply.php" class="btn tk-btn-green">เปิดร้านค้า</a>
  </section>
<?php else: ?>

  <?php if ($bestSellers): ?>
  <section class="mb-5" aria-labelledby="best-title">
    <div class="d-flex justify-content-between align-items-baseline mb-3">
      <h2 class="h4 fw-bold mb-0" id="best-title">สินค้าขายดี</h2>
      <a href="<?= e(BASE_URL) ?>/products.php?sort=popular">ดูทั้งหมด</a>
    </div>
    <div class="row g-3">
      <?php foreach ($bestSellers as $p) { include __DIR__ . '/../includes/product_card.php'; } ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($newArrivals): ?>
  <section class="mb-5" aria-labelledby="new-title">
    <div class="d-flex justify-content-between align-items-baseline mb-3">
      <h2 class="h4 fw-bold mb-0" id="new-title">สินค้ามาใหม่</h2>
      <a href="<?= e(BASE_URL) ?>/products.php?sort=newest">ดูทั้งหมด</a>
    </div>
    <div class="row g-3">
      <?php foreach ($newArrivals as $p) { include __DIR__ . '/../includes/product_card.php'; } ?>
    </div>
  </section>
  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>