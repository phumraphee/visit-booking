<?php
declare(strict_types=1);
/**
 * ส่งลิงก์การจองอีกครั้ง (แทนปุ่ม "Resend order link" ของ Pretix เดิม)
 * POST {email, lang} → ส่งอีเมล 1 ฉบับ รวมทุกการจองที่ยังไม่ถึงวัน: อีเมลผู้ติดต่อ = ลิงก์ทั้งการจอง, อีเมลผู้เข้าชม = ลิงก์ตั๋วของตัวเอง
 * ตอบเหมือนกันเสมอ ไม่ว่าจะมีการจองหรือไม่ — กันการเช็คว่าใครจองไว้
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/emails.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail(405, 'method', 'Method not allowed');
}
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$email = mb_strtolower(trim((string) ($in['email'] ?? '')));
$lang = ($in['lang'] ?? 'en') === 'th' ? 'th' : 'en';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_fail(422, 'validation', 'กรุณากรอกอีเมลให้ถูกต้อง');
}
if (rate_limited('resend')) {
    json_fail(429, 'rate_limited', 'คุณส่งคำขอบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่');
}

$now = now_iso();
$st = db()->prepare("SELECT o.*, MIN(s.starts_at) AS first_start FROM orders o JOIN tickets t ON t.order_id = o.id AND t.canceled = 0
                     JOIN sessions s ON s.id = t.session_id
                     WHERE lower(o.email) = ? AND o.status IN ('p', 'w') GROUP BY o.id HAVING first_start > ? ORDER BY first_start");
$st->execute([$email, $now]);
$orders = $st->fetchAll();

// ตั๋วของผู้เข้าชมที่ใช้อีเมลนี้ (ไม่ใช่ผู้ติดต่อของการจองนั้น) → ลิงก์ตั๋วส่วนตัว (ดู/ยกเลิกเฉพาะใบของตัวเอง)
$tq = db()->prepare("SELECT t.*, o.code, o.is_test, s.starts_at, s.ends_at FROM tickets t JOIN orders o ON o.id = t.order_id JOIN sessions s ON s.id = t.session_id
                     WHERE lower(t.email) = ? AND lower(o.email) <> ? AND t.canceled = 0 AND o.status = 'p' AND s.starts_at > ? ORDER BY s.starts_at");
$tq->execute([$email, $email, $now]);
$tickets = $tq->fetchAll();

if ($orders || $tickets) {
    $items = [];
    $test = true;
    foreach ($orders as $o) {
        $ts = array_values(array_filter(order_tickets((int) $o['id']), fn($t) => !$t['canceled']));
        $items[] = ['code' => $o['code'], 'slot' => session_slot($ts[0]), 'qty' => count($ts), 'first' => $ts[0]['starts_at'],
            'url' => public_url('manage.php?o=' . rawurlencode($o['code']) . '&s=' . rawurlencode($o['secret']))];
        $test = $test && (bool) $o['is_test'];
    }
    foreach ($tickets as $t) {
        $items[] = ['code' => $t['code'] . '-' . $t['n'], 'slot' => session_slot($t), 'qty' => 1, 'first' => $t['starts_at'],
            'name' => trim($t['given'] . ' ' . $t['family']), 'ticket' => true, 'url' => ticket_manage_url($t)];
        $test = $test && (bool) $t['is_test'];
    }
    usort($items, fn($a, $b) => strcmp($a['first'], $b['first']));
    $send = function () use ($items, $lang, $email, $test) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        mail_use_outbox($test, $items[0]['code']);   // การจองทดสอบทั้งหมด → กล่องอีเมลทดสอบ
        [$subj, $html, $img] = resend_email($lang, $items);
        send_mail($email, $subj, $html, $img);
    };
    register_shutdown_function($send);
}
json_out(200, ['ok' => true]);
