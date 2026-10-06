<?php
/**
 * public/product.php - หน้ารายละเอียดสินค้า
 * แกลเลอรีรูป, ตัวเลือกสินค้า, ใส่ตะกร้า, รายการโปรด, รีวิวและคะแนน, ถาม-ตอบ, สินค้าที่เกี่ยวข้อง
 * พารามิเตอร์: ?id=สินค้า  (&rp=หน้ารีวิว)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

$productId = (int)($_GET['id'] ?? 0);

/* ---------- 1) โหลดสินค้า ---------- */
$stmt = db()->prepare(
    "SELECT p.*, s.shop_name, s.user_id AS shop_owner_id, s.status AS shop_status,
            c.name AS category_name
     FROM products p
     JOIN shops s ON s.id = p.shop_id
     JOIN categories c ON c.id = p.category_id
     WHERE p.id = ? LIMIT 1"
);
$stmt->execute([$productId]);
$product = $stmt->fetch();

$user = current_user();
$isOwner = $user && $product && (int)$product['shop_owner_id'] === (int)$user['id'];
$isAdmin = $user && $user['role'] === 'admin';

// คนทั่วไปเห็นเฉพาะสินค้าที่เปิดขายและร้านอนุมัติแล้ว (เจ้าของร้านและแอดมินดูตัวอย่างได้)
$visible = $product && (
    ($product['status'] === 'active' && $product['shop_status'] === 'approved') || $isOwner || $isAdmin
);
if (!$visible) {
    http_response_code(404);
    $pageTitle = 'ไม่พบสินค้า';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="text-center py-5"><h1 class="h4 fw-bold">ไม่พบสินค้านี้</h1>'
       . '<p class="text-secondary">สินค้าอาจถูกลบหรือปิดการขายแล้ว</p>'
       . '<a class="btn tk-btn-green" href="products.php">ดูสินค้าอื่น</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* ---------- 2) ส่งคำถาม / ตอบกลับ (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ask') {
    require_login();
    require_csrf();

    $content  = trim((string)($_POST['content'] ?? ''));
    $parentId = (int)($_POST['parent_id'] ?? 0);
    $len      = mb_strlen($content);

    if ($len < 3 || $len > 1000) {
        flash('error', 'ข้อความต้องยาว 3-1,000 ตัวอักษร');
    } else {
        $parentAuthor = null;
        if ($parentId > 0) {
            // ตอบได้เฉพาะคำถามหลักของสินค้านี้ (ซ้อนได้ 1 ชั้น)
            $chk = db()->prepare('SELECT user_id FROM product_questions WHERE id = ? AND product_id = ? AND parent_id IS NULL');
            $chk->execute([$parentId, $productId]);
            $parentAuthor = $chk->fetchColumn();
            if ($parentAuthor === false) {
                flash('error', 'ไม่พบคำถามที่ต้องการตอบ');
                redirect('product.php?id=' . $productId . '#qa');
            }
        }
        $ins = db()->prepare('INSERT INTO product_questions (product_id, user_id, parent_id, content) VALUES (?,?,?,?)');
        $ins->execute([$productId, $user['id'], $parentId > 0 ? $parentId : null, $content]);

        // แจ้งเจ้าของร้าน (และคนที่ถูกตอบ) ถ้าไม่ใช่ตัวเอง
        $notify = db()->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?,?,?)');
        $link   = 'product.php?id=' . $productId . '#qa';
        $targets = [(int)$product['shop_owner_id']];
        if ($parentAuthor) {
            $targets[] = (int)$parentAuthor;
        }
        foreach (array_unique($targets) as $t) {
            if ($t !== (int)$user['id']) {
                $notify->execute([$t, ($parentId > 0 ? 'มีการตอบกลับ' : 'มีคำถามใหม่') . 'ในสินค้า ' . mb_substr($product['name'], 0, 60), $link]);
            }
        }
        flash('success', 'ส่งข้อความเรียบร้อยแล้ว');
    }
    redirect('product.php?id=' . $productId . '#qa');
}

/* ---------- 3) ข้อมูลประกอบ ---------- */
$images = db()->prepare('SELECT image_path FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
$images->execute([$productId]);
$images = $images->fetchAll(PDO::FETCH_COLUMN);

$variants = db()->prepare('SELECT id, variant_name, price, stock FROM product_variants WHERE product_id = ? ORDER BY id');
$variants->execute([$productId]);
$variants = $variants->fetchAll();

// เลือกตัวเลือกเริ่มต้น: ตัวแรกที่ยังมีของ
$defaultVariant = null;
foreach ($variants as $v) {
    if ((int)$v['stock'] > 0) { $defaultVariant = $v; break; }
}
$defaultVariant ??= $variants[0] ?? null;

$basePrice    = (float)$product['price'];
$shownPrice   = $defaultVariant && $defaultVariant['price'] !== null ? (float)$defaultVariant['price'] : $basePrice;
$shownStock   = $defaultVariant ? (int)$defaultVariant['stock'] : (int)$product['stock'];

// รายการโปรด
$inWishlist = false;
if ($user) {
    $w = db()->prepare('SELECT 1 FROM wishlists WHERE user_id = ? AND product_id = ?');
    $w->execute([$user['id'], $productId]);
    $inWishlist = (bool)$w->fetchColumn();
}

// รีวิว: สรุปคะแนน
$dist = array_fill(1, 5, 0);
$d = db()->prepare('SELECT rating, COUNT(*) FROM reviews WHERE product_id = ? GROUP BY rating');
$d->execute([$productId]);
foreach ($d->fetchAll(PDO::FETCH_KEY_PAIR) as $rating => $cnt) {
    $dist[(int)$rating] = (int)$cnt;
}
$reviewTotal = array_sum($dist);

// รีวิว: รายการ (หน้าละ 5)
$rpg = paginate($reviewTotal, 5, (int)($_GET['rp'] ?? 1));
$r = db()->prepare(
    "SELECT r.id, r.rating, r.comment, r.seller_reply, r.created_at, u.name AS user_name
     FROM reviews r JOIN users u ON u.id = r.user_id
     WHERE r.product_id = ?
     ORDER BY r.created_at DESC, r.id DESC
     LIMIT " . (int)$rpg['per_page'] . " OFFSET " . (int)$rpg['offset']
);
$r->execute([$productId]);
$reviews = $r->fetchAll();

$reviewImages = [];
if ($reviews) {
    $ids = array_column($reviews, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $ri  = db()->prepare("SELECT review_id, image_path FROM review_images WHERE review_id IN ($in)");
    $ri->execute($ids);
    foreach ($ri->fetchAll() as $row) {
        $reviewImages[(int)$row['review_id']][] = $row['image_path'];
    }
}

// สิทธิ์เขียนรีวิว: ซื้อแล้ว ได้รับของแล้ว และยังไม่เคยรีวิวรายการนั้น
$reviewableItemId = null;
if ($user) {
    $q = db()->prepare(
        "SELECT oi.id FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.buyer_id = ? AND oi.product_id = ? AND oi.item_status = 'delivered'
           AND oi.id NOT IN (SELECT order_item_id FROM reviews)
         ORDER BY oi.id DESC LIMIT 1"
    );
    $q->execute([$user['id'], $productId]);
    $reviewableItemId = $q->fetchColumn() ?: null;
}

// ถาม-ตอบ
$qa = db()->prepare(
    "SELECT q.id, q.parent_id, q.content, q.created_at, q.user_id, u.name AS user_name
     FROM product_questions q JOIN users u ON u.id = q.user_id
     WHERE q.product_id = ? ORDER BY q.created_at ASC, q.id ASC"
);
$qa->execute([$productId]);
$questions = [];
$replies   = [];
foreach ($qa->fetchAll() as $row) {
    if ($row['parent_id'] === null) { $questions[] = $row; }
    else { $replies[(int)$row['parent_id']][] = $row; }
}
$questions = array_reverse($questions);   // คำถามใหม่อยู่บนสุด

// สินค้าที่เกี่ยวข้อง (หมวดเดียวกัน)
$rel = db()->prepare(
    "SELECT p.id, p.name, p.price, p.stock, p.avg_rating, p.review_count, p.sold_count, s.shop_name,
            (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY sort_order, id LIMIT 1) AS image
     FROM products p JOIN shops s ON s.id = p.shop_id AND s.status = 'approved'
     WHERE p.category_id = ? AND p.id <> ? AND p.status = 'active'
     ORDER BY p.sold_count DESC LIMIT 4"
);
$rel->execute([$product['category_id'], $productId]);
$related = $rel->fetchAll();

$canBuy = (int)$product['shop_owner_id'] !== (int)($user['id'] ?? 0) && $product['status'] === 'active';

$pageTitle    = $product['name'];
$extraScripts = ['js/product.js'];
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb small mb-0">
    <li class="breadcrumb-item"><a href="index.php">หน้าแรก</a></li>
    <li class="breadcrumb-item"><a href="products.php?category=<?= (int)$product['category_id'] ?>"><?= e($product['category_name']) ?></a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e(mb_substr($product['name'], 0, 40)) ?></li>
  </ol>
</nav>

<?php if ($product['status'] !== 'active'): ?>
  <div class="alert alert-warning">สินค้านี้ยังไม่เปิดขาย (สถานะ: <?= e($product['status']) ?>) คุณเห็นหน้านี้ในฐานะเจ้าของร้านหรือแอดมิน</div>
<?php endif; ?>

<div class="row g-4">
  <!-- แกลเลอรี -->
  <div class="col-md-6">
    <img id="main-image" class="tk-gallery-main" src="<?= e(upload_url($images[0] ?? null)) ?>" alt="<?= e($product['name']) ?>">
    <?php if (count($images) > 1): ?>
      <div class="d-flex gap-2 mt-2 flex-wrap">
        <?php foreach ($images as $i => $img): ?>
          <button type="button" class="tk-thumb <?= $i === 0 ? 'active' : '' ?>" data-src="<?= e(upload_url($img)) ?>" aria-label="ดูรูปที่ <?= $i + 1 ?>">
            <img src="<?= e(upload_url($img)) ?>" alt="">
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ข้อมูลและปุ่มซื้อ -->
  <div class="col-md-6">
    <h1 class="h3 fw-bold"><?= e($product['name']) ?></h1>
    <div class="mb-2 small">
      <?php if ($reviewTotal > 0): ?>
        <a href="#reviews" class="text-decoration-none"><span class="tk-stars"><?= e(render_stars((float)$product['avg_rating'])) ?></span>
        <?= number_format((float)$product['avg_rating'], 1) ?> (<?= $reviewTotal ?> รีวิว)</a>
      <?php else: ?>
        <span class="text-secondary">ยังไม่มีรีวิว</span>
      <?php endif; ?>
      <span class="text-secondary ms-2">ขายแล้ว <?= number_format((int)$product['sold_count']) ?></span>
    </div>

    <div class="tk-price-big mb-3" id="price"><?= e(money($shownPrice)) ?></div>
    <div class="mb-3 small">ขายโดย <strong><?= e($product['shop_name']) ?></strong></div>

    <?php if ($variants): ?>
      <fieldset class="mb-3">
        <legend class="form-label fs-6">เลือกแบบ</legend>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($variants as $v):
              $vPrice = $v['price'] !== null ? (float)$v['price'] : $basePrice; ?>
            <input type="radio" class="btn-check" name="variant" id="v<?= (int)$v['id'] ?>"
                   value="<?= (int)$v['id'] ?>" data-price="<?= e((string)$vPrice) ?>" data-stock="<?= (int)$v['stock'] ?>"
                   <?= (int)$v['stock'] <= 0 ? 'disabled' : '' ?>
                   <?= $defaultVariant && (int)$defaultVariant['id'] === (int)$v['id'] ? 'checked' : '' ?>>
            <label class="btn btn-outline-secondary" for="v<?= (int)$v['id'] ?>"><?= e($v['variant_name']) ?></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
    <?php endif; ?>

    <div class="mb-3 small" id="stock-text"><?= $shownStock > 0 ? 'คงเหลือ ' . number_format($shownStock) . ' ชิ้น' : '<span class="text-danger">สินค้าหมด</span>' ?></div>

    <?php if ($canBuy): ?>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <label class="visually-hidden" for="qty">จำนวน</label>
        <input type="number" id="qty" class="form-control" style="width:90px" value="1" min="1" max="<?= max(1, $shownStock) ?>">
        <button type="button" id="add-btn" class="btn btn-lg tk-btn-primary"
                data-add-to-cart data-product-id="<?= (int)$product['id'] ?>"
                <?= $defaultVariant ? 'data-variant-id="' . (int)$defaultVariant['id'] . '"' : '' ?>
                data-qty-input="#qty" <?= $shownStock <= 0 ? 'disabled' : '' ?>>ใส่ตะกร้า</button>

        <form action="wishlist.php" method="post" class="m-0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
          <input type="hidden" name="return" value="product.php?id=<?= (int)$product['id'] ?>">
          <button class="btn btn-lg btn-outline-secondary" type="submit" aria-pressed="<?= $inWishlist ? 'true' : 'false' ?>">
            <?= $inWishlist ? '♥ อยู่ในรายการโปรด' : '♡ เก็บเป็นรายการโปรด' ?>
          </button>
        </form>
      </div>
    <?php elseif ((int)$product['shop_owner_id'] === (int)($user['id'] ?? 0)): ?>
      <div class="alert alert-info mb-0">นี่คือสินค้าของร้านคุณ ไม่สามารถสั่งซื้อเองได้</div>
    <?php endif; ?>
  </div>
</div>

<section class="mt-5" aria-labelledby="desc-title">
  <h2 class="h5 fw-bold" id="desc-title">รายละเอียดสินค้า</h2>
  <div class="tk-prose"><?= nl2br(e($product['description'] ?: 'ผู้ขายยังไม่ได้ใส่รายละเอียด')) ?></div>
</section>

<!-- รีวิว -->
<section class="mt-5" id="reviews" aria-labelledby="rev-title">
  <h2 class="h5 fw-bold mb-3" id="rev-title">รีวิวจากผู้ซื้อ</h2>

  <div class="row g-4 mb-4">
    <div class="col-md-4">
      <div class="display-4 fw-bold"><?= number_format((float)$product['avg_rating'], 1) ?><span class="fs-5 text-secondary">/5</span></div>
      <div class="tk-stars fs-5"><?= e(render_stars((float)$product['avg_rating'])) ?></div>
      <div class="small text-secondary"><?= $reviewTotal ?> รีวิว</div>
    </div>
    <div class="col-md-8">
      <?php for ($s = 5; $s >= 1; $s--):
          $pct = $reviewTotal ? round($dist[$s] / $reviewTotal * 100) : 0; ?>
        <div class="d-flex align-items-center gap-2 small mb-1">
          <span style="width:2.5rem"><?= $s ?> ดาว</span>
          <div class="progress flex-grow-1" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" style="height:8px">
            <div class="progress-bar tk-bar" style="width: <?= $pct ?>%"></div>
          </div>
          <span class="text-secondary" style="width:2rem"><?= $dist[$s] ?></span>
        </div>
      <?php endfor; ?>
    </div>
  </div>

  <?php if ($reviewableItemId): ?>
    <form class="tk-box mb-4" action="<?= e(API_URL) ?>/review_submit.php" method="post" enctype="multipart/form-data">
      <h3 class="h6 fw-bold">เขียนรีวิวสินค้านี้</h3>
      <?= csrf_field() ?>
      <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
      <input type="hidden" name="order_item_id" value="<?= (int)$reviewableItemId ?>">
      <div class="mb-3 d-flex flex-wrap gap-2" role="radiogroup" aria-label="ให้คะแนน">
        <?php for ($s = 5; $s >= 1; $s--): ?>
          <input type="radio" class="btn-check" name="rating" id="rt<?= $s ?>" value="<?= $s ?>" required>
          <label class="btn btn-outline-warning" for="rt<?= $s ?>"><?= $s ?> ★</label>
        <?php endfor; ?>
      </div>
      <div class="mb-3">
        <label class="form-label" for="rv-comment">ความคิดเห็น</label>
        <textarea class="form-control" id="rv-comment" name="comment" rows="3" maxlength="2000" placeholder="สินค้าเป็นอย่างไรบ้าง"></textarea>
      </div>
      <div class="mb-3">
        <label class="form-label" for="rv-img">แนบรูป (สูงสุด 3 รูป, JPG/PNG/WEBP)</label>
        <input class="form-control" type="file" id="rv-img" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
      </div>
      <button class="btn tk-btn-green" type="submit">ส่งรีวิว</button>
    </form>
  <?php elseif ($user): ?>
    <p class="small text-secondary">รีวิวได้เมื่อคุณซื้อและได้รับสินค้านี้แล้ว</p>
  <?php endif; ?>

  <?php if (!$reviews): ?>
    <p class="text-secondary">ยังไม่มีรีวิว</p>
  <?php else: ?>
    <?php foreach ($reviews as $rv): ?>
      <article class="border-bottom py-3">
        <div class="d-flex justify-content-between">
          <strong><?= e(mb_substr($rv['user_name'], 0, 1) . str_repeat('*', 3)) ?></strong>
          <span class="small text-secondary"><?= e(format_datetime($rv['created_at'])) ?></span>
        </div>
        <div class="tk-stars" aria-label="<?= (int)$rv['rating'] ?> จาก 5 ดาว"><?= e(render_stars((float)$rv['rating'])) ?></div>
        <?php if ($rv['comment']): ?><p class="mb-2"><?= nl2br(e($rv['comment'])) ?></p><?php endif; ?>
        <?php foreach ($reviewImages[(int)$rv['id']] ?? [] as $ip): ?>
          <img class="tk-review-img" src="<?= e(upload_url($ip)) ?>" alt="รูปจากรีวิว" loading="lazy">
        <?php endforeach; ?>
        <?php if ($rv['seller_reply']): ?>
          <div class="tk-reply mt-2"><strong>ร้านค้าตอบกลับ:</strong> <?= nl2br(e($rv['seller_reply'])) ?></div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>

    <?php if ($rpg['pages'] > 1): ?>
      <nav class="mt-3" aria-label="หน้ารีวิว">
        <ul class="pagination pagination-sm">
          <?php for ($i = 1; $i <= $rpg['pages']; $i++): ?>
            <li class="page-item <?= $i === $rpg['page'] ? 'active' : '' ?>">
              <a class="page-link" href="?id=<?= (int)$productId ?>&rp=<?= $i ?>#reviews"><?= $i ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>

<!-- ถาม-ตอบ -->
<section class="mt-5" id="qa" aria-labelledby="qa-title">
  <h2 class="h5 fw-bold mb-3" id="qa-title">ถาม-ตอบเกี่ยวกับสินค้า</h2>

  <?php if ($user): ?>
    <form method="post" action="product.php?id=<?= (int)$productId ?>#qa" class="mb-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="ask">
      <label class="form-label" for="qa-content">ตั้งคำถามหรือแสดงความคิดเห็น</label>
      <textarea class="form-control mb-2" id="qa-content" name="content" rows="2" maxlength="1000" required></textarea>
      <button class="btn tk-btn-green" type="submit">ส่งข้อความ</button>
    </form>
  <?php else: ?>
    <p><a href="login.php">เข้าสู่ระบบ</a> เพื่อตั้งคำถาม</p>
  <?php endif; ?>

  <?php if (!$questions): ?>
    <p class="text-secondary">ยังไม่มีคำถาม</p>
  <?php endif; ?>

  <?php foreach ($questions as $qn): ?>
    <div class="border-bottom py-3">
      <div>
        <strong><?= e($qn['user_name']) ?></strong>
        <?php if ((int)$qn['user_id'] === (int)$product['shop_owner_id']): ?><span class="badge tk-badge">ร้านค้า</span><?php endif; ?>
        <span class="small text-secondary ms-2"><?= e(format_datetime($qn['created_at'])) ?></span>
      </div>
      <p class="mb-2"><?= nl2br(e($qn['content'])) ?></p>

      <?php foreach ($replies[(int)$qn['id']] ?? [] as $rp): ?>
        <div class="tk-reply ms-3 mb-2">
          <strong><?= e($rp['user_name']) ?></strong>
          <?php if ((int)$rp['user_id'] === (int)$product['shop_owner_id']): ?><span class="badge tk-badge">ร้านค้า</span><?php endif; ?>
          <span class="small text-secondary ms-2"><?= e(format_datetime($rp['created_at'])) ?></span>
          <div><?= nl2br(e($rp['content'])) ?></div>
        </div>
      <?php endforeach; ?>

      <?php if ($user): ?>
        <details class="ms-3">
          <summary class="small">ตอบกลับ</summary>
          <form method="post" action="product.php?id=<?= (int)$productId ?>#qa" class="mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ask">
            <input type="hidden" name="parent_id" value="<?= (int)$qn['id'] ?>">
            <label class="visually-hidden" for="rp<?= (int)$qn['id'] ?>">ข้อความตอบกลับ</label>
            <textarea class="form-control mb-2" id="rp<?= (int)$qn['id'] ?>" name="content" rows="2" maxlength="1000" required></textarea>
            <button class="btn btn-sm tk-btn-green" type="submit">ส่งคำตอบ</button>
          </form>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>

<?php if ($related): ?>
<section class="mt-5" aria-labelledby="rel-title">
  <h2 class="h5 fw-bold mb-3" id="rel-title">สินค้าที่คุณอาจสนใจ</h2>
  <div class="row g-3">
    <?php foreach ($related as $p) { include __DIR__ . '/../includes/product_card.php'; } ?>
  </div>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>