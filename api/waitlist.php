<?php
declare(strict_types=1);
/**
 * Waiting list (สาธารณะ)
 *   POST {session, given, family, email, phone, qty, lang}  → ลงชื่อรอ (เฉพาะรอบที่เต็ม) + อีเมลแจ้ง + แจ้งแอดมิน
 *   GET  ?token=…                                           → ข้อมูลสิทธิ์จอง (หน้าจองใช้เลือกรอบ/จำนวนให้)
 */
require_once __DIR__ . '/../lib/waitlist.php';
require_once __DIR__ . '/../lib/notify.php';
require_once __DIR__ . '/../lib/sms.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    if (($_GET['token'] ?? '') === 'preview') {   // ลิงก์ในอีเมลตัวอย่างของหลังบ้าน — แสดงขั้นตอนได้ จองจริงไม่ได้
        $open = array_values(array_filter(bookable_slots(), fn($s) => !$s['closed']));
        $s = $open[0] ?? json_fail(404, 'invalid', 'ไม่มีรอบที่เปิดอยู่');
        $th = ($_GET['lang'] ?? 'en') === 'th';
        json_out(200, ['ok' => true, 'demo' => true, 'session' => (int) $s['id'], 'qty' => 2, 'slot' => ['date' => $s['date'], 'start' => $s['start'], 'end' => $s['end']],
            'expires_at' => (new DateTimeImmutable('+48 hours', tz()))->format(DATE_ATOM), 'given' => $th ? 'สมชาย' : 'Somchai', 'family' => $th ? 'ใจดี' : 'Jaidee', 'email' => 'somchai@example.com', 'phone' => '']);
    }
    $w = waitlist_by_token((string) ($_GET['token'] ?? '')) ?: json_fail(404, 'invalid', 'ลิงก์หมดอายุหรือใช้ไปแล้ว');
    json_out(200, ['ok' => true, 'session' => (int) $w['session_id'], 'qty' => (int) $w['qty'], 'slot' => session_slot($w), 'expires_at' => $w['expires_at'],
        'given' => $w['given'], 'family' => $w['family'], 'email' => $w['email'], 'phone' => $w['phone']]);
}
if ($method !== 'POST') {
    json_fail(405, 'method', 'Method not allowed');
}
if (!waitlist_on()) {
    json_fail(404, 'off', 'ไม่ได้เปิดใช้');
}
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
if (!empty($in['website'])) {
    json_out(200, ['ok' => true]);   // honeypot
}
$trim = fn($v) => is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : '';
$sid = (int) ($in['session'] ?? 0);
$given = mb_substr($trim($in['given'] ?? ''), 0, 100);
$family = mb_substr($trim($in['family'] ?? ''), 0, 100);
$email = mb_strtolower($trim($in['email'] ?? ''));
$phone = normalize_phone($trim($in['phone'] ?? ''));
$qty = (int) ($in['qty'] ?? 0);
$lang = ($in['lang'] ?? 'en') === 'th' ? 'th' : 'en';
$maxPer = (int) setting('max_per_order', '10');
if ($given === '' || $family === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === null || $qty < 1 || $qty > $maxPer) {
    json_fail(422, 'validation', 'กรุณากรอกข้อมูลให้ครบถ้วน');
}
$slot = null;
foreach (bookable_slots() as $sl) {
    if ($sl['id'] === $sid) {
        $slot = $sl;
    }
}
// ลงชื่อรอได้เฉพาะรอบที่เต็มเพราะความจุ (ไม่ใช่รอบที่ปิดรับ/ผ่านไปแล้ว)
if (!$slot || $slot['closed'] || $slot['available']) {
    json_fail(409, 'not_full', 'รอบนี้ยังจองได้ตามปกติ หรือปิดรับแล้ว');
}
// กันบอท (ถ้าเปิด Turnstile) — ลงชื่อรอส่งอีเมลถึงที่อยู่ที่กรอกโดยไม่ยืนยัน จึงต้องกันการยิงอัตโนมัติ
if (!turnstile_ok(is_string($in['turnstile'] ?? null) ? $in['turnstile'] : '')) {
    json_fail(403, 'bot_check', 'กรุณายืนยันว่าไม่ใช่บอท แล้วลองใหม่');
}
if (rate_limited('waitlist')) {
    json_fail(429, 'rate_limited', 'คุณส่งคำขอบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่');
}
$st = db()->prepare("SELECT 1 FROM waitlist WHERE session_id = ? AND lower(email) = ? AND status IN ('waiting', 'invited')");
$st->execute([$sid, $email]);
if ($st->fetchColumn()) {
    json_out(200, ['ok' => true, 'already' => true]);
}
$isTest = test_mode();
db()->prepare('INSERT INTO waitlist (session_id, given, family, email, phone, qty, lang, is_test, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([$sid, $given, $family, $email, $phone, $qty, $lang, $isTest ? 1 : 0, now_iso()]);
$id = (int) db()->lastInsertId();
register_shutdown_function(function () use ($id, $slot, $isTest, $sid) {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    $st = db()->prepare('SELECT * FROM waitlist WHERE id = ?');
    $st->execute([$id]);
    $w = $st->fetch();
    mail_use_outbox($isTest, 'WL' . $id);
    [$subj, $html, $img] = waitlist_email('joined', $w, $slot);
    send_mail($w['email'], $subj, $html, $img);
    mail_use_outbox(false);
    $cnt = db()->prepare("SELECT COUNT(*), COALESCE(SUM(qty), 0) FROM waitlist WHERE session_id = ? AND status = 'waiting'");
    $cnt->execute([$sid]);
    [$n, $people] = $cnt->fetch(PDO::FETCH_NUM);
    notify_admin('waitlist', ['slot' => $slot, 'qty' => (int) $w['qty'], 'contact' => trim($w['given'] . ' ' . $w['family']), 'email' => $w['email'], 'phone' => $w['phone'],
        'note' => "รายชื่อรอในรอบนี้: $n ราย ($people คน) — ให้สิทธิ์จองได้ที่หน้ารอบในหลังบ้าน", 'admin_path' => 'admin/#/session/' . $sid, 'is_test' => $isTest]);
});
json_out(201, ['ok' => true]);
