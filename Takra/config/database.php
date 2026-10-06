<?php
/**
 * config/database.php
 * เชื่อมต่อฐานข้อมูล MySQL ผ่าน PDO
 * เรียกใช้: require_once __DIR__ . '/../config/database.php';  แล้วใช้ db()
 */
declare(strict_types=1);

// ค่าเชื่อมต่อ (XAMPP/Laragon ค่าเริ่มต้น user=root รหัสผ่านว่าง)
// ตอนขึ้นเซิร์ฟเวอร์จริง ให้เปลี่ยนเป็น user เฉพาะที่มีสิทธิ์เท่าที่จำเป็น และตั้งรหัสผ่านที่ยาก
const DB_HOST = 'localhost';
const DB_NAME = 's67160239';
const DB_USER = 's67160239';
const DB_PASS = 'uRyxPg2f';
const DB_CHARSET = 'utf8mb4';

/**
 * คืนค่า PDO ตัวเดียวตลอดการทำงานของ request (singleton)
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // error แล้วโยน Exception
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // ดึงข้อมูลเป็น array ชื่อคอลัมน์
            PDO::ATTR_EMULATE_PREPARES   => false,                  // ใช้ prepared statement จริง กัน SQL Injection
        ]);
        $pdo->exec("SET time_zone = '+07:00'");                     // เวลาไทย
    } catch (PDOException $e) {
        // ห้ามแสดงรายละเอียด error ให้ผู้ใช้เห็น (อาจเปิดเผยรหัสผ่าน/โครงสร้าง DB)
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('ขออภัย ระบบขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง');
    }

    return $pdo;
}