<?php
/**
 * includes/functions.php
 * ฟังก์ชันช่วยที่ใช้ทั่วทั้งเว็บ
 * (ต้อง require config/app.php มาก่อน เพราะใช้ค่าคงที่จากไฟล์นั้น)
 */
declare(strict_types=1);

/* ------------------------------------------------------------------
 * 1) แสดงผลอย่างปลอดภัย
 * ------------------------------------------------------------------ */

/** escape ข้อความก่อนแสดงใน HTML (กัน XSS) ใช้ทุกครั้งที่ echo ข้อมูลจากผู้ใช้ */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** ค่าที่ผู้ใช้เพิ่งกรอก (ใช้เติมกลับในฟอร์มเมื่อ validate ไม่ผ่าน) */
function old(string $name, string $default = ''): string
{
    return e($_POST[$name] ?? $default);
}

/** แสดงราคา เช่น ฿1,250.00 */
function money(float|int|string $amount): string
{
    return CURRENCY_SYMBOL . number_format((float)$amount, 2);
}

/** แสดงวันเวลา เช่น 05/10/2026 14:30 */
function format_datetime(?string $datetime): string
{
    return $datetime ? date('d/m/Y H:i', strtotime($datetime)) : '-';
}

/** แสดงดาวรีวิว เช่น ★★★★☆ (รับค่า 0-5) */
function render_stars(float $rating): string
{
    $full = (int)round($rating);
    $full = max(0, min(5, $full));
    return str_repeat('★', $full) . str_repeat('☆', 5 - $full);
}

/* ------------------------------------------------------------------
 * 2) URL และการเปลี่ยนหน้า
 * ------------------------------------------------------------------ */

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** URL ของไฟล์ใน public/assets เช่น asset('css/style.css') */
function asset(string $path): string
{
    return BASE_URL . '/assets/' . ltrim($path, '/');
}

/** URL ของรูปที่ผู้ใช้อัปโหลด เช่น upload_url('products/abc.jpg') */
function upload_url(?string $path): string
{
    return $path ? UPLOAD_URL . '/' . ltrim($path, '/') : asset('img/no-image.png');
}

/** ตอบกลับเป็น JSON (ใช้ในโฟลเดอร์ api/) */
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------
 * 3) ข้อความแจ้งเตือนครั้งเดียว (flash message)
 * ------------------------------------------------------------------ */

/** ตั้งข้อความ: flash('success', 'บันทึกแล้ว') ประเภท: success | error | info */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** ดึงข้อความทั้งหมดไปแสดง (แล้วลบทิ้งทันที) */
function get_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/* ------------------------------------------------------------------
 * 4) CSRF (กันคนอื่นหลอกให้ผู้ใช้กดส่งฟอร์มโดยไม่รู้ตัว)
 * ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** ใส่ในทุกฟอร์ม POST:  <?= csrf_field() ?> */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** ตรวจ token จากฟอร์มหรือ header (สำหรับ AJAX ส่ง X-CSRF-Token) */
function csrf_verify(?string $token = null): bool
{
    $token ??= $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** เรียกบนสุดของหน้าที่รับ POST ถ้าไม่ผ่านจะหยุดทันที */
function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
        http_response_code(419);
        exit('คำขอไม่ถูกต้องหรือหมดอายุ กรุณากลับไปโหลดหน้าใหม่แล้วลองอีกครั้ง');
    }
}

/* ------------------------------------------------------------------
 * 5) อัปโหลดรูปภาพอย่างปลอดภัย
 * ------------------------------------------------------------------ */

/**
 * อัปโหลดรูป 1 ไฟล์
 * @param array  $file    $_FILES['ชื่อช่อง'] (ไฟล์เดียว)
 * @param string $subdir  โฟลเดอร์ย่อยใน uploads/ เช่น 'products', 'slips', 'avatars'
 * @return array ['ok'=>bool, 'path'=>string|null, 'error'=>string|null]
 *               path คือ path แบบสัมพัทธ์ เช่น 'products/3fa9...jpg' (เก็บลงฐานข้อมูล)
 */
function upload_image(array $file, string $subdir): array
{
    $fail = fn(string $msg) => ['ok' => false, 'path' => null, 'error' => $msg];

    // ตรวจ subdir ให้เป็นตัวอักษรธรรมดา กัน path traversal
    if (!preg_match('/^[a-z0-9_-]+$/', $subdir)) {
        return $fail('ปลายทางอัปโหลดไม่ถูกต้อง');
    }

    if (!isset($file['error']) || is_array($file['error'])) {
        return $fail('ข้อมูลไฟล์ไม่ถูกต้อง');
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return $fail('กรุณาเลือกไฟล์');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return $fail('อัปโหลดไม่สำเร็จ (รหัส ' . $file['error'] . ')');
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        return $fail('ไฟล์ใหญ่เกิน ' . (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return $fail('ไฟล์ไม่ได้มาจากการอัปโหลดที่ถูกต้อง');
    }

    // ตรวจชนิดไฟล์จากเนื้อหาจริง ไม่เชื่อนามสกุลหรือ type ที่เบราว์เซอร์ส่งมา
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_IMAGE_MIME, true)) {
        return $fail('รองรับเฉพาะไฟล์ JPG, PNG, WEBP');
    }
    if (@getimagesize($file['tmp_name']) === false) {
        return $fail('ไฟล์ภาพไม่ถูกต้อง');
    }

    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];

    $dir = UPLOAD_PATH . '/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return $fail('สร้างโฟลเดอร์เก็บไฟล์ไม่ได้');
    }

    // ตั้งชื่อใหม่แบบสุ่ม ไม่ใช้ชื่อเดิมของผู้ใช้
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return $fail('บันทึกไฟล์ไม่สำเร็จ');
    }

    return ['ok' => true, 'path' => $subdir . '/' . $name, 'error' => null];
}

/**
 * แปลง $_FILES แบบหลายไฟล์ (input name="images[]") ให้เป็นรายการไฟล์เดี่ยว
 * ใช้คู่กับ upload_image() ในลูป
 */
function normalize_files(array $files): array
{
    $out = [];
    if (!isset($files['name']) || !is_array($files['name'])) {
        return $out;
    }
    foreach ($files['name'] as $i => $_) {
        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = [
            'name'     => $files['name'][$i],
            'type'     => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error'    => $files['error'][$i],
            'size'     => $files['size'][$i],
        ];
    }
    return $out;
}

/** ลบไฟล์ที่เคยอัปโหลด (ใช้ตอนลบสินค้า/เปลี่ยนรูป) */
function delete_upload(?string $path): void
{
    if (!$path || !preg_match('#^[a-z0-9_-]+/[a-f0-9]{32}\.(jpg|png|webp)$#', $path)) {
        return; // path ไม่ตรงรูปแบบที่ระบบสร้างเอง = ไม่ลบ
    }
    $full = UPLOAD_PATH . '/' . $path;
    if (is_file($full)) {
        unlink($full);
    }
}

/* ------------------------------------------------------------------
 * 6) แบ่งหน้า
 * ------------------------------------------------------------------ */

/**
 * คำนวณข้อมูลแบ่งหน้า
 * ใช้: $pg = paginate($total, PRODUCTS_PER_PAGE, (int)($_GET['page'] ?? 1));
 *      แล้ว SQL: LIMIT {$pg['per_page']} OFFSET {$pg['offset']}
 */
function paginate(int $total, int $perPage, int $page): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = max(1, min($page, $pages));
    return [
        'page'     => $page,
        'pages'    => $pages,
        'per_page' => $perPage,
        'offset'   => ($page - 1) * $perPage,
        'total'    => $total,
    ];
}

/* ------------------------------------------------------------------
 * 7) อื่น ๆ
 * ------------------------------------------------------------------ */

/** สร้างเลขออเดอร์ เช่น TK261006A1B2C3 */
function generate_order_no(): string
{
    return 'TK' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
}

/** ทำ slug รองรับภาษาไทย เช่น "เสื้อ ยืด" -> "เสื้อ-ยืด" */
function slugify(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
    return trim((string)$text, '-');
}

/** ตรวจรูปแบบอีเมล */
function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** ตรวจเบอร์โทรไทย (0 ตามด้วย 8-9 หลัก) */
function is_valid_phone(string $phone): bool
{
    return (bool)preg_match('/^0\d{8,9}$/', preg_replace('/[\s-]/', '', $phone));
}