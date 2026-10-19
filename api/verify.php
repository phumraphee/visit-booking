<?php
declare(strict_types=1);
/**
 * ยืนยันอีเมล / เบอร์มือถือของผู้ติดต่อในฟอร์มจอง (ก่อนกดจอง)
 *   POST {action:'send',   channel:'email'|'phone', value, lang} → ส่งรหัส 6 หลัก (โหมดทดลอง: คืน test_code แทนการส่ง)
 *   POST {action:'verify', channel, value, code}                 → {token} แนบตอนกดจอง (api/book.php email_token / phone_token)
 */
require_once __DIR__ . '/../lib/sms.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail(405, 'method', 'Method not allowed');
}
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$channel = ($in['channel'] ?? '') === 'phone' ? 'phone' : 'email';

if ($channel === 'phone') {
    if (!sms_verify_on()) {
        json_fail(404, 'off', 'ไม่ได้เปิดใช้');
    }
    $key = normalize_phone((string) ($in['value'] ?? ''));
    if ($key === null || !thai_mobile($key)) {
        json_fail(422, 'not_mobile', 'กรุณากรอกเบอร์มือถือไทยให้ถูกต้อง');
    }
} else {
    if (!email_verify_on()) {
        json_fail(404, 'off', 'ไม่ได้เปิดใช้');
    }
    $email = mb_strtolower(trim((string) ($in['value'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_fail(422, 'bad_email', 'กรุณากรอกอีเมลให้ถูกต้อง');
    }
    $key = 'mail:' . $email;
}

if (($in['action'] ?? '') === 'send') {
    $r = vc_request($key, ($in['lang'] ?? 'en') === 'th' ? 'th' : 'en');
    if (!$r['ok']) {
        $msg = ['wait' => 'กรุณารอสักครู่ก่อนขอรหัสใหม่', 'too_many' => 'ขอรหัสบ่อยเกินไป กรุณาลองใหม่ภายหลัง', 'unavailable' => 'ระบบส่งรหัสไม่พร้อมใช้งานในขณะนี้', 'send_failed' => 'ส่งรหัสไม่สำเร็จ กรุณาตรวจสอบแล้วลองใหม่'][$r['error']] ?? 'ไม่สำเร็จ';
        json_fail(429, 'vc_' . $r['error'], $msg, ['wait' => $r['wait'] ?? null]);
    }
    json_out(200, ['ok' => true, 'test_code' => $r['test_code'] ?? null]);
}
if (($in['action'] ?? '') === 'verify') {
    $token = vc_verify($key, (string) ($in['code'] ?? ''));
    if (!$token) {
        json_fail(422, 'bad_code', 'รหัสไม่ถูกต้องหรือหมดอายุ');
    }
    json_out(200, ['ok' => true, 'token' => $token]);
}
json_fail(400, 'action', 'Bad request');
