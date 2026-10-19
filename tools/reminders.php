<?php
declare(strict_types=1);
/**
 * ส่งอีเมลเตือนก่อนวันเข้าชม + ยกเลิกการจองที่ไม่ยืนยันอีเมลภายในเวลา (สำหรับ cron)
 *   CLI:  php tools/reminders.php
 *   เว็บ: https://<โดเมน>/tools/reminders.php?key=<cron_key ใน config>  → Plesk Scheduled Tasks "Fetch a URL" ทุกชั่วโมง
 */
require_once __DIR__ . '/../lib/reminders.php';
require_once __DIR__ . '/../lib/booking.php';
if (PHP_SAPI !== 'cli') {
    $key = (string) (cfg()['cron_key'] ?? '');
    if ($key === '' || !hash_equals($key, trim((string) ($_GET['key'] ?? '')))) {   // trim: กันช่องว่าง/ขึ้นบรรทัดที่ติดมาตอนคัดลอก
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}
@set_time_limit(300);
setting_set('cron_ran_at', now_iso());   // หลังบ้าน → ตั้งค่า แสดงว่างานรายชั่วโมงทำงานล่าสุดเมื่อไร
echo 'expired unconfirmed: ' . expire_pending() . "\n";
echo 'reminders sent: ' . (setting('reminder_on', '1') === '1' ? run_reminders() : 0) . "\n";
echo 'push summaries: ' . run_push_summary() . "\n";
run_wallet_expiry_check();
echo 'backup: ' . (run_daily_backup() ?? '-') . "\n";
