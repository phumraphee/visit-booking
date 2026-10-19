<?php
declare(strict_types=1);
/** โลโก้ที่อัปในหลังบ้าน (ตั้งค่า → ข้อมูลสถานที่) — ?v=hash ใช้แคชยาว */
require_once __DIR__ . '/../lib/venue.php';

$b = venue_logo_bytes();
$type = str_starts_with($b, "\x89PNG") ? 'image/png' : (str_starts_with($b, "\xFF\xD8") ? 'image/jpeg' : 'image/png');
header('Content-Type: ' . $type);
header('Cache-Control: public, max-age=' . (!empty($_GET['v']) ? 31536000 : 3600));
echo $b;
