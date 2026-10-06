<?php
/**
 * config/app.php
 * ค่าตั้งค่ากลางของเว็บ + ตั้งค่า error + เริ่ม session แบบปลอดภัย
 * ทุกหน้าให้ require ไฟล์นี้ไฟล์เดียว (ไฟล์นี้จะเรียก database.php ให้เอง)
 */
declare(strict_types=1);

// ---------- 1) สภาพแวดล้อม ----------
// 'development' = แสดง error ตอนเขียนโค้ด | 'production' = ซ่อน error ตอนเปิดใช้งานจริง
const APP_ENV  = 'development';
const APP_NAME = 'Takra';
const APP_TAGLINE = 'ตลาดของทุกคน';

// ---------- 2) URL และ Path ----------
// ปรับให้ตรงกับที่ตั้งจริง เช่น XAMPP: http://localhost/takra/public
// ตอนขึ้นโฮสต์ เช่น https://takra.shop (ไม่ต้องมี / ท้าย)
const BASE_URL = 'http://localhost/takra/public';

define('ROOT_PATH',   dirname(__DIR__));              // .../takra
define('UPLOAD_PATH', ROOT_PATH . '/uploads');        // ที่เก็บไฟล์จริงบนเครื่อง
const UPLOAD_URL = 'http://localhost/takra/uploads';  // URL สำหรับแสดงรูป
const SELLER_URL = 'http://localhost/takra/seller';   // หลังบ้านผู้ขาย
const ADMIN_URL  = 'http://localhost/takra/admin';    // หลังบ้านแอดมิน
const API_URL    = 'http://localhost/takra/api';      // ปลายทาง AJAX

// ---------- 3) กติกาธุรกิจ ----------
const CURRENCY_SYMBOL   = '฿';
const SHIPPING_FLAT_FEE = 40.00;     // ค่าส่งเหมา
const FREE_SHIPPING_MIN = 500.00;    // ซื้อครบเท่านี้ส่งฟรี
const PRODUCTS_PER_PAGE = 12;        // จำนวนสินค้าต่อหน้า
const COMMISSION_RATE   = 0.05;      // ค่าคอมมิชชันแพลตฟอร์ม 5% (ใช้ตอนทำระบบรายได้ผู้ขาย)

// ---------- 4) การอัปโหลดไฟล์ ----------
const MAX_UPLOAD_BYTES  = 3 * 1024 * 1024;                       // 3 MB ต่อไฟล์
const ALLOWED_IMAGE_MIME = ['image/jpeg', 'image/png', 'image/webp'];

// ---------- 5) ข้อมูลรับชำระเงิน (ใช้กับหน้า payment) ----------
const PROMPTPAY_ID   = '0812345678';           // เบอร์โทร หรือเลขบัตรประชาชนที่ผูก PromptPay (แก้เป็นของจริง)
const BANK_NAME      = 'ธนาคารตัวอย่าง';
const BANK_ACCOUNT   = '000-0-00000-0';
const BANK_ACCT_NAME = 'บริษัท ตะกร้า จำกัด';

// ---------- 6) เวลาและภาษา ----------
date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

// ---------- 7) การแสดง error ----------
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');          // ห้ามโชว์ error ให้ผู้ใช้เห็นบนเว็บจริง
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

// ---------- 8) Session แบบปลอดภัย ----------
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_name('TAKRASESS');
    session_set_cookie_params([
        'lifetime' => 0,            // หมดอายุเมื่อปิดเบราว์เซอร์
        'path'     => '/',
        'secure'   => $isHttps,     // ส่งเฉพาะ HTTPS (เมื่อเว็บเปิด HTTPS)
        'httponly' => true,         // JavaScript อ่านคุกกี้นี้ไม่ได้ (กัน XSS ขโมย session)
        'samesite' => 'Lax',        // ช่วยกัน CSRF
    ]);
    ini_set('session.use_strict_mode', '1'); // ไม่รับ session id ที่เบราว์เซอร์คิดเอง
    session_start();
}

// ---------- 9) เชื่อมฐานข้อมูล ----------
require_once __DIR__ . '/database.php';