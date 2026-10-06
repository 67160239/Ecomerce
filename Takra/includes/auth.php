<?php
/**
 * includes/auth.php
 * ระบบยืนยันตัวตนและตรวจสิทธิ์ (buyer / seller / admin)
 *
 * ต้องใช้ร่วมกับ includes/functions.php (ไฟล์ถัดไป) ซึ่งมี redirect(), flash()
 * วิธีใช้ในแต่ละหน้า:
 *   require_once __DIR__ . '/../config/app.php';
 *   require_once __DIR__ . '/../includes/auth.php';
 *   require_login();            // หน้าที่ต้องล็อกอิน
 *   require_role('admin');      // หน้าที่เฉพาะแอดมิน
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// กติกาป้องกันเดารหัสผ่าน
const LOGIN_MAX_ATTEMPTS = 5;     // ผิดได้กี่ครั้ง
const LOGIN_LOCK_SECONDS = 300;   // ล็อก 5 นาที

/* ------------------------------------------------------------------
 * สถานะผู้ใช้
 * ------------------------------------------------------------------ */

function is_logged_in(): bool
{
    return current_user() !== null;
}

/**
 * ดึงข้อมูลผู้ใช้ปัจจุบันจากฐานข้อมูลทุกครั้ง (cache ไว้ใน request เดียว)
 * ไม่เชื่อ role ที่เก็บใน session เพื่อให้การเปลี่ยนสิทธิ์หรือแบนมีผลทันที
 */
function current_user(): ?array
{
    static $user = false;       // false = ยังไม่เคยโหลด

    if ($user !== false) {
        return $user;
    }

    if (empty($_SESSION['user_id'])) {
        return $user = null;
    }

    $stmt = db()->prepare(
        'SELECT id, name, email, phone, role, avatar, is_verified, is_banned
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([(int)$_SESSION['user_id']]);
    $row = $stmt->fetch();

    // ผู้ใช้ถูกลบหรือถูกแบน -> เคลียร์ session ทันที
    if (!$row || (int)$row['is_banned'] === 1) {
        unset($_SESSION['user_id']);
        return $user = null;
    }

    return $user = $row;
}

function current_user_id(): ?int
{
    $u = current_user();
    return $u ? (int)$u['id'] : null;
}

/** ตรวจ role (รับ string เดียวหรือ array ก็ได้) */
function has_role(string|array $roles): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    return in_array($u['role'], (array)$roles, true);
}

/** ร้านของผู้ใช้ปัจจุบัน (null ถ้ายังไม่มีร้าน) */
function current_shop(): ?array
{
    static $shop = false;

    if ($shop !== false) {
        return $shop;
    }
    $uid = current_user_id();
    if (!$uid) {
        return $shop = null;
    }
    $stmt = db()->prepare('SELECT * FROM shops WHERE user_id = ? LIMIT 1');
    $stmt->execute([$uid]);
    return $shop = ($stmt->fetch() ?: null);
}

/* ------------------------------------------------------------------
 * ล็อกอิน / ล็อกเอาต์
 * ------------------------------------------------------------------ */

/**
 * ตรวจอีเมลและรหัสผ่าน
 * @return array ['ok' => bool, 'error' => string|null, 'user' => array|null]
 */
function attempt_login(string $email, string $password): array
{
    // 1) ตรวจว่าถูกล็อกชั่วคราวหรือไม่
    $lockUntil = (int)($_SESSION['login_lock_until'] ?? 0);
    if ($lockUntil > time()) {
        $min = (int)ceil(($lockUntil - time()) / 60);
        return ['ok' => false, 'error' => "ลองผิดหลายครั้งเกินไป กรุณารออีกประมาณ {$min} นาที", 'user' => null];
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([mb_strtolower(trim($email))]);
    $user = $stmt->fetch();

    // 2) ตรวจรหัสผ่าน (ถ้าไม่พบผู้ใช้ ให้ตรวจกับ hash หลอก เพื่อให้เวลาตอบสนองใกล้เคียงกัน)
    $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    $hash      = $user['password_hash'] ?? $dummyHash;
    $valid     = password_verify($password, $hash) && $user;

    if (!$valid) {
        $_SESSION['login_fail'] = (int)($_SESSION['login_fail'] ?? 0) + 1;
        if ($_SESSION['login_fail'] >= LOGIN_MAX_ATTEMPTS) {
            $_SESSION['login_lock_until'] = time() + LOGIN_LOCK_SECONDS;
            $_SESSION['login_fail'] = 0;
        }
        // ข้อความกลาง ๆ ไม่บอกว่าอีเมลหรือรหัสผ่านอย่างใดผิด
        return ['ok' => false, 'error' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง', 'user' => null];
    }

    if ((int)$user['is_banned'] === 1) {
        return ['ok' => false, 'error' => 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแล', 'user' => null];
    }

    // 3) อัปเกรด hash อัตโนมัติถ้าอัลกอริทึมเก่า
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $upd = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    return ['ok' => true, 'error' => null, 'user' => $user];
}

/** บันทึกว่าล็อกอินสำเร็จ */
function login_user(array $user): void
{
    session_regenerate_id(true);               // เปลี่ยน session id กัน session fixation
    $_SESSION['user_id'] = (int)$user['id'];
    unset($_SESSION['login_fail'], $_SESSION['login_lock_until']);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ------------------------------------------------------------------
 * ด่านตรวจสิทธิ์ (เรียกบนสุดของแต่ละหน้า)
 * ------------------------------------------------------------------ */

/** ต้องล็อกอินก่อน ไม่งั้นส่งไปหน้า login และจำหน้าเดิมไว้กลับมา */
function require_login(): void
{
    if (is_logged_in()) {
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? '';
    }
    flash('error', 'กรุณาเข้าสู่ระบบก่อน');
    redirect(BASE_URL . '/login.php');
}

/** หน้าสำหรับผู้ที่ยังไม่ล็อกอิน (login/register) ถ้าล็อกอินแล้วให้เด้งออก */
function require_guest(): void
{
    if (is_logged_in()) {
        redirect(BASE_URL . '/index.php');
    }
}

/** ต้องมี role ที่กำหนด */
function require_role(string|array $roles): void
{
    require_login();
    if (!has_role($roles)) {
        http_response_code(403);
        exit('คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    }
}

/** หน้าหลังบ้านผู้ขาย: ต้องเป็น seller และร้านได้รับอนุมัติแล้ว */
function require_seller(): array
{
    require_role('seller');
    $shop = current_shop();

    if (!$shop || $shop['status'] !== 'approved') {
        flash('error', 'ร้านค้าของคุณยังไม่ได้รับการอนุมัติ');
        redirect(BASE_URL . '/index.php');
    }
    return $shop;
}

/** ไปหน้าที่ผู้ใช้ตั้งใจจะเข้าก่อนล็อกอิน (ตรวจว่าเป็น path ภายในเว็บเท่านั้น กัน open redirect) */
function redirect_after_login(): void
{
    $target = $_SESSION['intended'] ?? '';
    unset($_SESSION['intended']);

    $isLocalPath = is_string($target)
        && $target !== ''
        && $target[0] === '/'
        && !str_starts_with($target, '//')
        && !str_contains($target, '\\');

    redirect($isLocalPath ? $target : BASE_URL . '/index.php');
}