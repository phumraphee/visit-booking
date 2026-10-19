<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/reminders.php';
require_once __DIR__ . '/../lib/booking.php';
require_once __DIR__ . '/../lib/sms.php';
require_once __DIR__ . '/../lib/waitlist.php';

try {
    $slots = bookable_slots();
} catch (Throwable $e) {
    error_log('slots: ' . $e->getMessage());
    json_fail(500, 'server', 'ไม่สามารถดึงข้อมูลรอบเข้าชมได้ในขณะนี้');
}

// อีเมลเตือนก่อนวันเข้าชม — ทำงานหลังตอบผู้ใช้ ชั่วโมงละครั้ง (สำรองกรณีไม่ได้ตั้ง cron)
register_shutdown_function(function () {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    try {
        expire_pending();   // คืนที่ของการจองที่ไม่ยืนยันอีเมลภายในเวลา (แบบเดิม)
        waitlist_expire();
        maybe_run_reminders();
    } catch (Throwable $e) {
        error_log('reminders: ' . $e->getMessage());
    }
});

json_out(200, [
    'ok'            => true,
    'max_per_order' => (int) setting('max_per_order', '10'),
    'test_mode'     => test_mode(),
    'test_until'    => test_mode_until(),
    'sms_verify'    => sms_verify_on(),
    'email_verify'  => email_verify_on(),
    'waitlist'      => waitlist_on(),
    // รอบที่ผ่านไปแล้วในเดือนนี้ — กดดูได้ ขึ้น "จบไปแล้ว" (สอดคล้องกับ "เปิดทุกอาทิตย์ที่ 2 และ 4")
    'past'          => past_session_slots(),
    'generated_at'  => now_iso(),
    // ไม่เปิดเผยจำนวนที่ว่าง: left → null, few = เหลือ ≤ 5, max = จองได้สูงสุดต่อครั้งในรอบนี้
    'slots'         => array_map(fn($s) => [
        'id' => $s['id'], 'date' => $s['date'], 'start' => $s['start'], 'end' => $s['end'], 'available' => $s['available'], 'closed' => $s['closed'],
        'few' => $s['left'] !== null && $s['left'] > 0 && $s['left'] <= 5,
        'max' => $s['left'] === null ? (int) setting('max_per_order', '10') : min((int) setting('max_per_order', '10'), $s['left']),
        'left' => null,
    ], $slots),
]);
