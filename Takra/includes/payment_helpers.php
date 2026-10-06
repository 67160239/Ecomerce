<?php
/**
 * includes/payment_helpers.php
 * ตัวช่วยเกี่ยวกับการชำระเงิน: สร้างข้อมูล PromptPay QR (มาตรฐาน EMVCo / Thai QR Payment)
 * ตัว QR ภาพจะถูกวาดฝั่งเบราว์เซอร์ในหน้า payment.php
 */
declare(strict_types=1);

/** CRC16-CCITT (poly 0x1021, init 0xFFFF) คืนเป็นเลขฐาน 16 ตัวใหญ่ 4 หลัก */
function crc16_ccitt(string $data): string
{
    $crc = 0xFFFF;
    for ($i = 0, $n = strlen($data); $i < $n; $i++) {
        $crc ^= ord($data[$i]) << 8;
        for ($bit = 0; $bit < 8; $bit++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
            $crc &= 0xFFFF;
        }
    }
    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

/** ประกอบฟิลด์ EMV แบบ id + ความยาว 2 หลัก + ค่า */
function emv_field(string $id, string $value): string
{
    return $id . str_pad((string)strlen($value), 2, '0', STR_PAD_LEFT) . $value;
}

/**
 * สร้างข้อความสำหรับทำ PromptPay QR
 * @param string $target เบอร์โทร 10 หลัก, เลขบัตรประชาชน 13 หลัก หรือ e-Wallet ID 15 หลัก
 * @param float  $amount ยอดเงิน (0 = ให้ผู้จ่ายกรอกเอง)
 * @return string|null null ถ้ารูปแบบ $target ไม่ถูกต้อง
 */
function promptpay_payload(string $target, float $amount): ?string
{
    $digits = preg_replace('/\D/', '', $target);

    if (strlen($digits) === 13) {                       // เลขบัตรประชาชน / เลขผู้เสียภาษี
        $subTag = '02';
        $value  = $digits;
    } elseif (strlen($digits) === 10 && $digits[0] === '0') {   // เบอร์โทร -> 0066xxxxxxxxx
        $subTag = '01';
        $value  = '0066' . substr($digits, 1);
    } elseif (strlen($digits) === 15) {                 // e-Wallet
        $subTag = '03';
        $value  = $digits;
    } else {
        return null;
    }

    $merchant = emv_field('00', 'A000000677010111') . emv_field($subTag, $value);

    $payload  = emv_field('00', '01');
    $payload .= emv_field('01', $amount > 0 ? '12' : '11');   // 12 = ใช้ครั้งเดียว (ระบุยอด)
    $payload .= emv_field('29', $merchant);
    $payload .= emv_field('53', '764');                        // สกุลเงินบาท
    if ($amount > 0) {
        $payload .= emv_field('54', number_format($amount, 2, '.', ''));
    }
    $payload .= emv_field('58', 'TH');
    $payload .= '6304';                                        // CRC คิดรวมส่วนหัวนี้ด้วย

    return $payload . crc16_ccitt($payload);
}