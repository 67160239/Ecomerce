<?php
/**
 * public/products.php - รายการสินค้า / ค้นหา / กรอง / เรียงลำดับ / แบ่งหน้า
 * พารามิเตอร์ (GET): q, category, min_price, max_price, sort, page
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

/* ---------- 1) รับและตรวจค่าที่ส่งมา ---------- */
$q          = trim((string)($_GET['q'] ?? ''));
$q          = mb_substr($q, 0, 100);
$categoryId = (int)($_GET['category'] ?? 0);
$minPrice   = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? max(0.0, (float)$_GET['min_price']) : null;
$maxPrice   = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? max(0.0, (float)$_GET['max_price']) : null;
if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
    [$minPrice, $maxPrice] = [$maxPrice, $minPrice];   // สลับถ้ากรอกกลับกัน
}

// เรียงลำดับ: อนุญาตเฉพาะค่าในรายการนี้ (กัน SQL Injection ผ่าน ORDER BY)
$sortOptions = [
    'newest'     => ['ใหม่ล่าสุด',        'p.created_at DESC, p.id DESC'],
    'popular'    => ['ขายดี',             'p.sold_count DESC, p.id DESC'],
    'rating'     => ['คะแนนสูงสุด',       'p.avg_rating DESC, p.review_count DESC, p.id DESC'],
    'price_asc'  => ['ราคา: ต่ำไปสูง',     'p.price ASC, p.id DESC'],
    'price_desc' => ['ราคา: สูงไปต่ำ',     'p.price DESC, p.id DESC'],
];
$sort = $_GET['sort'] ?? 'newest';
if (!isset($sortOptions[$sort])) {
    $sort = 'newest';
}

/* ---------- 2) สร้างเงื่อนไข WHERE ---------- */
$where  = ["p.status = 'active'", "s.status = 'approved'"];
$params = [];

if ($q !== '') {
    // ใช้ LIKE เพราะ FULLTEXT ของ MySQL ตัดคำภาษาไทยไม่ได้ (ไม่มีช่องว่างคั่นคำ)
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $where[]  = '(p.name LIKE ? OR p.description LIKE ? OR s.shop_name LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($categoryId > 0) {
    // รวมหมวดย่อยของหมวดที่เลือกด้วย
    $where[]  = 'p.category_id IN (SELECT id FROM categories WHERE id = ? OR parent_id = ?)';
    array_push($params, $categoryId, $categoryId);
}
if ($minPrice !== null) {
    $where[]  = 'p.price >= ?';
    $params[] = $minPrice;
}
if ($maxPrice !== null) {
    $where[]  = 'p.price <= ?';
    $params[] = $maxPrice;
}
$whereSql = implode(' AND ', $where);
$fromSql  = "FROM products p JOIN shops s ON s.id = p.shop_id";

/* ---------- 3) นับจำนวน + ดึงข้อมูลตามหน้า ---------- */
$stmt = db()->prepare("SELECT COUNT(*) $fromSql WHERE $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$pg = paginate($total, PRODUCTS_PER_PAGE, (int)($_GET['page'] ?? 1));

$sql = "SELECT p.id, p.name, p.price, p.stock, p.avg_rating, p.review_count, p.sold_count,
               s.shop_name,
               (SELECT image_path FROM product_images
                 WHERE product_id = p.id ORDER BY sort_order, id LIMIT 1) AS image
        $fromSql
        WHERE $whereSql
        ORDER BY {$sortOptions[$sort][1]}
        LIMIT " . (int)$pg['per_page'] . " OFFSET " . (int)$pg['offset'];
$stmt = db()->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

/* ---------- 4) ข้อมูลประกอบหน้า ---------- */
$allCats = db()->query('SELECT id, parent_id, name FROM categories ORDER BY name')->fetchAll();
$parents = [];
$children = [];
foreach ($allCats as $c) {
    if ($c['parent_id'] === null) {
        $parents[] = $c;
    } else {
        $children[(int)$c['parent_id']][] = $c;
    }
}
$currentCatName = null;
foreach ($allCats as $c) {
    if ((int)$c['id'] === $categoryId) {
        $currentCatName = $c['name'];
    }
}

/** สร้าง query string เดิม แต่เปลี่ยนเลขหน้า */
function page_url(int $page): string
{
    $query = $_GET;
    $query['page'] = $page;
    return '?' . http_build_query($query);
}

$pageTitle = $q !== '' ? 'ค้นหา: ' . $q : ($currentCatName ?? 'สินค้าทั้งหมด');
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="h3 fw-bold mb-1">
  <?php if ($q !== ''): ?>ผลการค้นหา "<?= e($q) ?>"
  <?php elseif ($currentCatName): ?><?= e($currentCatName) ?>
  <?php else: ?>สินค้าทั้งหมด<?php endif; ?>
</h1>
<p class="text-secondary mb-4">พบ <?= number_format($total) ?> รายการ</p>

<form method="get" class="tk-filter row g-2 align-items-end mb-4">
  <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>

  <div class="col-12 col-md-3">
    <label class="form-label small mb-1" for="f-cat">หมวดหมู่</label>
    <select class="form-select" id="f-cat" name="category">
      <option value="0">ทุกหมวดหมู่</option>
      <?php foreach ($parents as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $categoryId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php foreach ($children[(int)$c['id']] ?? [] as $sub): ?>
          <option value="<?= (int)$sub['id'] ?>" <?= $categoryId === (int)$sub['id'] ? 'selected' : '' ?>>&nbsp;&nbsp;– <?= e($sub['name']) ?></option>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-6 col-md-2">
    <label class="form-label small mb-1" for="f-min">ราคาต่ำสุด</label>
    <input class="form-control" type="number" min="0" step="1" id="f-min" name="min_price" value="<?= $minPrice !== null ? e((string)$minPrice) : '' ?>">
  </div>
  <div class="col-6 col-md-2">
    <label class="form-label small mb-1" for="f-max">ราคาสูงสุด</label>
    <input class="form-control" type="number" min="0" step="1" id="f-max" name="max_price" value="<?= $maxPrice !== null ? e((string)$maxPrice) : '' ?>">
  </div>

  <div class="col-12 col-md-3">
    <label class="form-label small mb-1" for="f-sort">เรียงตาม</label>
    <select class="form-select" id="f-sort" name="sort">
      <?php foreach ($sortOptions as $key => $opt): ?>
        <option value="<?= e($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= e($opt[0]) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-12 col-md-2 d-flex gap-2">
    <button class="btn tk-btn-green flex-grow-1" type="submit">กรอง</button>
    <a class="btn btn-outline-secondary" href="products.php">ล้าง</a>
  </div>
</form>

<?php if (!$products): ?>
  <div class="text-center py-5">
    <h2 class="h5 fw-bold">ไม่พบสินค้าที่ตรงกับเงื่อนไข</h2>
    <p class="text-secondary">ลองเปลี่ยนคำค้นหา ลดตัวกรอง หรือเลือกหมวดหมู่อื่น</p>
    <a class="btn tk-btn-primary" href="products.php">ดูสินค้าทั้งหมด</a>
  </div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($products as $p) { include __DIR__ . '/../includes/product_card.php'; } ?>
  </div>

  <?php if ($pg['pages'] > 1): ?>
    <nav class="mt-4" aria-label="แบ่งหน้า">
      <ul class="pagination justify-content-center flex-wrap">
        <li class="page-item <?= $pg['page'] <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= e(page_url($pg['page'] - 1)) ?>">ก่อนหน้า</a>
        </li>
        <?php
        $start = max(1, $pg['page'] - 2);
        $end   = min($pg['pages'], $pg['page'] + 2);
        if ($start > 1): ?>
          <li class="page-item"><a class="page-link" href="<?= e(page_url(1)) ?>">1</a></li>
          <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <?php endif; ?>
        <?php for ($i = $start; $i <= $end; $i++): ?>
          <li class="page-item <?= $i === $pg['page'] ? 'active' : '' ?>">
            <a class="page-link" href="<?= e(page_url($i)) ?>" <?= $i === $pg['page'] ? 'aria-current="page"' : '' ?>><?= $i ?></a>
          </li>
        <?php endfor; ?>
        <?php if ($end < $pg['pages']): ?>
          <?php if ($end < $pg['pages'] - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
          <li class="page-item"><a class="page-link" href="<?= e(page_url($pg['pages'])) ?>"><?= $pg['pages'] ?></a></li>
        <?php endif; ?>
        <li class="page-item <?= $pg['page'] >= $pg['pages'] ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= e(page_url($pg['page'] + 1)) ?>">ถัดไป</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>