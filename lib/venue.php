<?php
declare(strict_types=1);
/**
 * ข้อมูลสถานที่ (ชื่อ ที่อยู่ แผนที่ ติดต่อ เว็บไซต์ นโยบายความเป็นส่วนตัว โลโก้) — แก้ได้ในหลังบ้าน (ตั้งค่า → ข้อมูลสถานที่)
 * เก็บใน settings (venue_*) — ยังไม่ตั้ง = ใช้ค่าเริ่มต้นด้านล่าง
 * ใช้ทั้งหน้าเว็บ อีเมล ตั๋ว PDF Apple/Google Wallet ปฏิทิน
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/texts.php';
require_once __DIR__ . '/texts-default.php';   // VENUE_DEFAULTS

const VENUE_KEYS = ['name_th', 'name_en', 'address_th', 'address_en', 'maps', 'email', 'group_email', 'phone', 'website', 'privacy'];

function venue(): array
{
    static $v = null;
    if ($v !== null && empty($GLOBALS['__venue_dirty'])) {
        return $v;
    }
    unset($GLOBALS['__venue_dirty']);
    $v = [];
    foreach (VENUE_KEYS as $k) {
        $s = setting('venue_' . $k);
        $v[$k] = $s !== null && $s !== '' ? $s : VENUE_DEFAULTS[$k];
    }
    return $v;
}

function venue_name(string $lang): string
{
    return venue()[$lang === 'th' ? 'name_th' : 'name_en'];
}

function venue_address(string $lang): string
{
    return venue()[$lang === 'th' ? 'address_th' : 'address_en'];
}

/**
 * ใส่ข้อมูลสถานที่ในข้อความ: {venue} = ชื่อ (ภาษานั้น), {group_email} = อีเมลนัดหมายหมู่คณะ
 * อังกฤษ: ชื่อที่ขึ้นต้น "The " อยู่กลางประโยค → "the " (เช่น "a visit to the … Museum")
 */
function venue_fill(string $s, string $lang): string
{
    if (!str_contains($s, '{')) {
        return $s;
    }
    $name = venue_name($lang);
    $s = preg_replace_callback('/(\b\p{Ll}+,? )\{venue\}/u', fn($m) => $m[1] . (str_starts_with($name, 'The ') ? 'the ' . substr($name, 4) : $name), $s);
    return strtr($s, ['{venue}' => $name, '{group_email}' => venue()['group_email']]);
}

/** คำนำหน้าชื่อไฟล์ดาวน์โหลด (ตั๋ว PDF / ปฏิทิน / Wallet) */
function venue_slug(): string
{
    return VENUE_DEFAULTS['slug'] ?? 'booking';
}

/** เบอร์สำหรับลิงก์ tel: (ตัดช่องว่าง/ขีด) */
function venue_tel(): string
{
    return preg_replace('/[^\d+]/', '', venue()['phone']);
}

/** เว็บไซต์แบบสั้นสำหรับแสดง (ไม่มี https://) */
function venue_site_label(): string
{
    return preg_replace('#^https?://|/$#', '', venue()['website']);
}

/** ไฟล์โลโก้ (PNG/JPEG bytes) — อัปในหลังบ้าน หรือไฟล์เดิม assets/brand/logo.png */
function venue_logo_bytes(): string
{
    $b = setting('venue_logo');
    if ($b) {
        $d = base64_decode($b, true);
        if ($d !== false && $d !== '') {
            return $d;
        }
    }
    return (string) @file_get_contents(__DIR__ . '/../assets/brand/logo.png');
}

/** URL โลโก้สำหรับหน้าเว็บ (สัมพัทธ์จาก root) — ไฟล์เดิม = static แคชได้, อัปเอง = api/logo.php?v=hash */
function venue_logo_path(): string
{
    $b = setting('venue_logo');
    return $b ? 'api/logo.php?v=' . substr(hash('sha256', $b), 0, 10) : 'assets/brand/logo.png';
}
