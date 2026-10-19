<?php
declare(strict_types=1);
/** Google Wallet: ตั๋ว 1 ใบ → redirect ไปหน้า "Save to Google Wallet" — ?o=CODE&s=ORDER_SECRET&n=ลำดับตั๋ว */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/gwallet.php';

if (is_demo_link((string) ($_GET['o'] ?? ''), (string) ($_GET['s'] ?? ''))) {   // ลิงก์ในอีเมลตัวอย่าง → ไฟล์ตัวอย่าง (ต้องล็อกอินหลังบ้าน)
    header('Location: admin.php?action=sample_gpass&lang=' . (($_GET['lang'] ?? 'th') === 'en' ? 'en' : 'th'), true, 302);
    exit;
}
if (!gwallet_enabled()) {
    http_response_code(404);
    exit('Google Wallet is not available');
}
if (rate_limited('gpass')) {
    http_response_code(429);
    exit('Too many requests');
}
$code = strtoupper((string) ($_GET['o'] ?? ''));
$order = preg_match('/^[A-Z0-9]{5,16}$/', $code) ? find_order($code) : null;
if (!$order || !hash_equals((string) $order['secret'], (string) ($_GET['s'] ?? '')) || $order['status'] !== 'p') {
    http_response_code(404);
    exit('Not found');
}
$ticket = null;
foreach (order_tickets((int) $order['id']) as $t) {
    if ((int) $t['n'] === (int) ($_GET['n'] ?? 0) && !$t['canceled']) {
        $ticket = $t;
    }
}
if (!$ticket) {
    http_response_code(404);
    exit('Not found');
}
try {
    $st = db()->prepare('SELECT * FROM sessions WHERE id = ?');
    $st->execute([$ticket['session_id']]);
    $url = gw_save_url($ticket, $order, $st->fetch());
} catch (Throwable $e) {
    error_log('gpass: ' . $e->getMessage());
    http_response_code(502);
    exit('Could not create Google Wallet pass. Please try again later.');
}
header('Location: ' . $url, true, 302);
