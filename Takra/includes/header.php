<?php
/**
 * includes/header.php
 * ส่วนหัวของทุกหน้า (เปิด <html> และ <body> ไว้ ไปปิดใน footer.php)
 *
 * วิธีใช้ในแต่ละหน้า:
 *   require_once __DIR__ . '/../config/app.php';
 *   require_once __DIR__ . '/../includes/auth.php';
 *   $pageTitle = 'สินค้าทั้งหมด';          // ไม่ใส่ก็ได้
 *   require_once __DIR__ . '/../includes/header.php';
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/auth.php';

$user      = current_user();
$cartCount = 0;
$notiCount = 0;

if ($user) {
    $stmt = db()->prepare('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $cartCount = (int)$stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$user['id']]);
    $notiCount = (int)$stmt->fetchColumn();
}

// หมวดหมู่หลักสำหรับเมนู
$navCategories = db()->query(
    'SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name LIMIT 12'
)->fetchAll();

$fullTitle = isset($pageTitle) ? $pageTitle . ' | ' . APP_NAME : APP_NAME . ' - ' . APP_TAGLINE;
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e($fullTitle) ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= e(asset('css/style.css')) ?>" rel="stylesheet">
</head>
<body>

<header class="tk-header sticky-top">
  <nav class="navbar navbar-expand-lg">
    <div class="container">

      <!-- โลโก้ -->
      <a class="navbar-brand tk-brand" href="<?= e(BASE_URL) ?>/index.php" aria-label="<?= e(APP_NAME) ?>">
        <svg width="34" height="34" viewBox="0 0 120 120" aria-hidden="true">
          <rect width="120" height="120" rx="30" fill="#F2A20C"/>
          <path d="M30 52 C30 12, 90 12, 90 52" fill="none" stroke="#0F4C4A" stroke-width="8" stroke-linecap="round"/>
          <path d="M16 52 H104 L92 101 Q90 106 85 106 H35 Q30 106 28 101 Z" fill="#0F4C4A"/>
          <path d="M24 70 H96 M28 88 H92 M47 56 L43 104 M60 56 V104 M73 56 L77 104"
                stroke="#F2A20C" stroke-width="4.5" stroke-linecap="round" fill="none"/>
        </svg>
        <span>takra</span>
      </a>

      <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
              data-bs-target="#tkNav" aria-controls="tkNav" aria-expanded="false" aria-label="เปิดเมนู">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse" id="tkNav">

        <!-- ค้นหา -->
        <form class="tk-search mx-lg-3 my-3 my-lg-0" action="<?= e(BASE_URL) ?>/products.php" method="get" role="search">
          <input class="form-control" type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>"
                 placeholder="ค้นหาสินค้า ร้านค้า หรือหมวดหมู่" aria-label="ค้นหาสินค้า">
          <button class="btn tk-btn-primary" type="submit">ค้นหา</button>
        </form>

        <ul class="navbar-nav ms-lg-auto align-items-lg-center gap-lg-1">

          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">หมวดหมู่</a>
            <ul class="dropdown-menu">
              <?php foreach ($navCategories as $cat): ?>
                <li><a class="dropdown-item" href="<?= e(BASE_URL) ?>/products.php?category=<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?></a></li>
              <?php endforeach; ?>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="<?= e(BASE_URL) ?>/products.php">สินค้าทั้งหมด</a></li>
            </ul>
          </li>

          <?php if ($user): ?>
            <li class="nav-item">
              <a class="nav-link" href="<?= e(BASE_URL) ?>/wishlist.php">รายการโปรด</a>
            </li>
            <li class="nav-item">
              <a class="nav-link tk-icon-link" href="<?= e(BASE_URL) ?>/cart.php">
                ตะกร้า
                <?php if ($cartCount > 0): ?>
                  <span class="badge tk-badge" id="cart-count"><?= $cartCount ?></span>
                <?php else: ?>
                  <span class="badge tk-badge d-none" id="cart-count">0</span>
                <?php endif; ?>
              </a>
            </li>

            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <?= e($user['name']) ?>
                <?php if ($notiCount > 0): ?><span class="badge tk-badge"><?= $notiCount ?></span><?php endif; ?>
              </a>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="<?= e(BASE_URL) ?>/profile.php">ข้อมูลของฉัน</a></li>
                <li><a class="dropdown-item" href="<?= e(BASE_URL) ?>/addresses.php">ที่อยู่จัดส่ง</a></li>
                <li><a class="dropdown-item" href="<?= e(BASE_URL) ?>/orders.php">คำสั่งซื้อของฉัน</a></li>
                <li><hr class="dropdown-divider"></li>

                <?php if ($user['role'] === 'seller'): ?>
                  <li><a class="dropdown-item" href="<?= e(SELLER_URL) ?>/dashboard.php">จัดการร้านค้า</a></li>
                <?php elseif ($user['role'] === 'buyer'): ?>
                  <li><a class="dropdown-item" href="<?= e(BASE_URL) ?>/seller_apply.php">เปิดร้านขายของ</a></li>
                <?php elseif ($user['role'] === 'admin'): ?>
                  <li><a class="dropdown-item" href="<?= e(ADMIN_URL) ?>/dashboard.php">หลังบ้านแอดมิน</a></li>
                <?php endif; ?>

                <li><hr class="dropdown-divider"></li>
                <li>
                  <!-- ออกจากระบบด้วย POST + CSRF กันคนแอบสั่งให้ผู้ใช้หลุดจากระบบ -->
                  <form action="<?= e(BASE_URL) ?>/logout.php" method="post" class="px-3">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-link p-0 text-decoration-none">ออกจากระบบ</button>
                  </form>
                </li>
              </ul>
            </li>

          <?php else: ?>
            <li class="nav-item"><a class="nav-link" href="<?= e(BASE_URL) ?>/login.php">เข้าสู่ระบบ</a></li>
            <li class="nav-item ms-lg-1">
              <a class="btn tk-btn-primary" href="<?= e(BASE_URL) ?>/register.php">สมัครสมาชิก</a>
            </li>
          <?php endif; ?>

        </ul>
      </div>
    </div>
  </nav>
</header>

<?php $flashes = get_flashes(); ?>
<?php if ($flashes): ?>
  <div class="container mt-3" role="status">
    <?php foreach ($flashes as $f):
        $cls = ['success' => 'alert-success', 'error' => 'alert-danger', 'info' => 'alert-info'][$f['type']] ?? 'alert-info';
    ?>
      <div class="alert <?= $cls ?> alert-dismissible fade show" role="alert">
        <?= e($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="ปิด"></button>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<main class="tk-main container py-4">