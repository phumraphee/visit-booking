<?php
declare(strict_types=1);
/** ดาวน์โหลด Apple Wallet pass ของตั๋ว 1 ใบ — ?o=CODE&s=ORDER_SECRET&n=ลำดับตั๋ว */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/emails.php';

if (is_demo_link((string) ($_GET['o'] ?? ''), (string) ($_GET['s'] ?? ''))) {   // ลิงก์ในอีเมลตัวอย่าง → ไฟล์ตัวอย่าง (ต้องล็อกอินหลังบ้าน)
    header('Location: admin.php?action=sample_pass&lang=' . (($_GET['lang'] ?? 'th') === 'en' ? 'en' : 'th'), true, 302);
    exit;
}
if (!wallet_enabled()) {
    http_response_code(404);
    exit('Wallet is not available');
}
$code = strtoupper((string) ($_GET['o'] ?? ''));
$secret = (string) ($_GET['s'] ?? '');
$n = (int) ($_GET['n'] ?? 0);
if (rate_limited('pass')) {
    http_response_code(429);
    exit('Too many requests');
}
$order = preg_match('/^[A-Z0-9]{5,16}$/', $code) ? find_order($code) : null;
if (!$order || !hash_equals((string) $order['secret'], $secret) || $order['status'] !== 'p') {
    http_response_code(404);
    exit('Not found');
}
$ticket = null;
foreach (order_tickets((int) $order['id']) as $t) {
    if ((int) $t['n'] === $n && !$t['canceled']) {
        $ticket = $t;
    }
}
if (!$ticket) {
    http_response_code(404);
    exit('Not found');
}
try {
    $pk = build_pkpass([
        'code' => $order['code'], 'n' => (int) $ticket['n'], 'name' => trim($ticket['given'] . ' ' . $ticket['family']),
        'secret' => $ticket['secret'], 'slot' => session_slot($ticket), 'starts_at' => $ticket['starts_at'],
        'ends_at' => $ticket['ends_at'], 'lang' => ($_GET['lang'] ?? $order['lang']) === 'th' ? 'th' : 'en',
        'manage_url' => public_url('manage.php?o=' . rawurlencode($order['code']) . '&s=' . rawurlencode($order['secret'])),
    ]);
} catch (Throwable $e) {
    error_log('pass: ' . $e->getMessage());
    http_response_code(500);
    exit('Could not create pass');
}
header('Content-Type: application/vnd.apple.pkpass');
header('Content-Disposition: attachment; filename="' . venue_slug() . '-' . $order['code'] . '-' . $ticket['n'] . '.pkpass"');
header('Cache-Control: no-store');
echo $pk;
