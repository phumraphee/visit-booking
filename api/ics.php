<?php
declare(strict_types=1);
/** ไฟล์ปฏิทิน (.ics) ของการจอง — ?o=CODE&s=ORDER_SECRET */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/emails.php';

if (is_demo_link((string) ($_GET['o'] ?? ''), (string) ($_GET['s'] ?? ''))) {   // ลิงก์ในอีเมลตัวอย่าง → ไฟล์ตัวอย่าง (ต้องล็อกอินหลังบ้าน)
    header('Location: admin.php?action=sample_ics&lang=' . (($_GET['lang'] ?? 'th') === 'en' ? 'en' : 'th'), true, 302);
    exit;
}
$code = strtoupper((string) ($_GET['o'] ?? ''));
$secret = (string) ($_GET['s'] ?? '');
$order = preg_match('/^[A-Z0-9]{5,16}$/', $code) ? find_order($code) : null;
if (!$order || !hash_equals((string) $order['secret'], $secret) || $order['status'] !== 'p') {
    http_response_code(404);
    exit('Not found');
}
$ts = array_values(array_filter(order_tickets((int) $order['id']), fn($t) => !$t['canceled']));
if (!$ts) {
    http_response_code(404);
    exit('Not found');
}
$lang = ($_GET['lang'] ?? $order['lang']) === 'th' ? 'th' : 'en';
$manage = public_url('manage.php?o=' . rawurlencode($order['code']) . '&s=' . rawurlencode($order['secret']));
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . venue_slug() . '-visit-' . $order['code'] . '.ics"');
echo booking_ics($order['code'], session_slot($ts[0]), $ts[0]['starts_at'], $ts[0]['ends_at'], count($ts), $manage, $lang);
