<?php
declare(strict_types=1);
/** ดาวน์โหลดตั๋ว PDF (A6 คนละหน้า) — ?o=CODE&s=ORDER_SECRET (ทุกใบ) หรือ ?t=TICKET_MSECRET (ใบเดียว) */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/booking.php';
require_once __DIR__ . '/../lib/ticketpdf.php';

if (is_demo_link((string) ($_GET['o'] ?? ''), (string) ($_GET['s'] ?? ''))) {   // ลิงก์ในอีเมลตัวอย่าง → ไฟล์ตัวอย่าง (ต้องล็อกอินหลังบ้าน)
    header('Location: admin.php?action=sample_pdf&lang=' . (($_GET['lang'] ?? 'th') === 'en' ? 'en' : 'th'), true, 302);
    exit;
}
$ts = [];
$order = null;
if (!empty($_GET['t']) && preg_match('/^[A-Za-z0-9]{16,64}$/', (string) $_GET['t'])) {
    $st = db()->prepare('SELECT * FROM tickets WHERE msecret = ? AND canceled = 0');
    $st->execute([(string) $_GET['t']]);
    if ($t = $st->fetch()) {
        $st = db()->prepare('SELECT * FROM orders WHERE id = ?');
        $st->execute([$t['order_id']]);
        $order = $st->fetch() ?: null;
        $ts = array_values(array_filter(order_tickets((int) $t['order_id']), fn($x) => (int) $x['id'] === (int) $t['id']));
    }
} else {
    $code = strtoupper((string) ($_GET['o'] ?? ''));
    $order = preg_match('/^[A-Z0-9]{5,16}$/', $code) ? find_order($code) : null;
    if ($order && !hash_equals((string) $order['secret'], (string) ($_GET['s'] ?? ''))) {
        $order = null;
    }
    $ts = $order ? array_values(array_filter(order_tickets((int) $order['id']), fn($t) => !$t['canceled'])) : [];
}
if (!$order || $order['status'] !== 'p' || !$ts) {
    http_response_code(404);
    exit('Not found');
}
$lang = ($_GET['lang'] ?? $order['lang']) === 'th' ? 'th' : 'en';
$pdf = ticket_pdf($lang, $order['code'], session_slot($ts[0]), mail_tickets($ts));
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . venue_slug() . '-ticket-' . $order['code'] . (count($ts) === 1 && !empty($_GET['t']) ? '-' . $ts[0]['n'] : '') . '.pdf"');
header('Cache-Control: private, no-store');
echo $pdf;
