<?php
declare(strict_types=1);
/**
 * อีเมลเตือนก่อนวันเข้าชม — ค่าเริ่มต้น 2 วันก่อน เวลา 09:00 (เช้าวันศุกร์สำหรับรอบวันอาทิตย์ ทันก่อนปิดรอบว่างเสาร์ 00:00)
 * เรียกจาก: api/slots.php (ทุกครั้งที่มีคนเปิดหน้า — ทำงานจริงชั่วโมงละครั้ง) และ tools/reminders.php (cron ของ Plesk)
 * ส่งต่อการจองครั้งเดียว (orders.reminded_at): ผู้ติดต่อ = ตั๋วทุกใบ, ผู้เข้าชมที่มีอีเมลตัวเอง = ใบของตัวเอง
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/venue.php';
require_once __DIR__ . '/emails.php';

function maybe_run_reminders(): void
{
    $hourKey = (new DateTimeImmutable('now', tz()))->format('Y-m-d H');
    if (setting('reminders_ran_at') === $hourKey || !setting_claim('reminders_ran_at', $hourKey)) {
        return;
    }
    @set_time_limit(300);
    if (setting('reminder_on', '1') === '1') {
        run_reminders();
    }
    run_push_summary();
    run_wallet_expiry_check();
    run_daily_backup();
}

/**
 * สำรองฐานข้อมูลวันละครั้ง (VACUUM INTO — ได้ไฟล์ที่สมบูรณ์แม้ระบบกำลังใช้งาน) → <โฟลเดอร์ DB>/backups/visit-YYYY-MM-DD.sqlite เก็บ 30 วัน
 * คืนชื่อไฟล์ที่สร้าง หรือ null ถ้าวันนี้สำรองแล้ว/ไม่สำเร็จ
 */
function run_daily_backup(): ?string
{
    try {
        $today = (new DateTimeImmutable('now', tz()))->format('Y-m-d');
        if (setting('backup_day') === $today || !setting_claim('backup_day', $today)) {
            return null;
        }
        $dir = dirname(db_file()) . '/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            throw new RuntimeException("mkdir $dir");
        }
        $file = "$dir/visit-$today.sqlite";
        @unlink($file);
        db()->exec('VACUUM INTO ' . db()->quote($file));
        foreach (glob("$dir/visit-*.sqlite") ?: [] as $f) {
            if (preg_match('/visit-(\d{4}-\d{2}-\d{2})\.sqlite$/', $f, $m) && $m[1] < (new DateTimeImmutable("-30 days", tz()))->format('Y-m-d')) {
                @unlink($f);
            }
        }
        setting_set('backup_last', now_iso() . ' ' . basename($file) . ' ' . (int) @filesize($file));
        return $file;
    } catch (Throwable $e) {
        setting_set('backup_day', '');   // ลองใหม่ชั่วโมงหน้า
        error_log('backup: ' . $e->getMessage());
        return null;
    }
}

/** เตือนผู้ดูแลสูงสุดเมื่อใบรับรอง Apple Wallet ใกล้หมดอายุ (เหลือ 60/30/14/7/1 วัน · ครั้งละ 1 ครั้งต่อช่วง) — อีเมล + แจ้งเตือนเว็บแอป */
function run_wallet_expiry_check(): void
{
    try {
        $i = wallet_cert_info();
        if ($i['days_left'] === null) {
            return;
        }
        if ($i['days_left'] > 60) {   // ต่ออายุแล้ว → รีเซ็ตให้เตือนรอบหน้าได้
            if ((string) setting('wallet_expiry_warned', '') !== '') {
                setting_set('wallet_expiry_warned', '');
            }
            return;
        }
        $step = null;
        foreach ([60, 30, 14, 7, 1, 0] as $d) {
            if ($i['days_left'] <= $d) {
                $step = $d;
            }
        }
        if ($step === null || setting('wallet_expiry_warned') === (string) $step) {
            return;
        }
        setting_set('wallet_expiry_warned', (string) $step);
        $msg = $i['days_left'] > 0 ? "ใบรับรอง Apple Wallet จะหมดอายุในอีก {$i['days_left']} วัน" : 'ใบรับรอง Apple Wallet หมดอายุแล้ว — ปุ่ม Apple Wallet ใช้ไม่ได้';
        $url = public_url('admin/#/settings');
        $supers = db()->query("SELECT id, email FROM admins WHERE active = 1 AND role = 'admin' AND super = 1")->fetchAll();
        require_once __DIR__ . '/webpush.php';
        $ids = array_map('intval', array_column($supers, 'id'));
        push_to(fn($r) => in_array((int) $r['admin_id'], $ids, true), ['title' => $msg, 'body' => 'ต่ออายุได้ที่ ตั้งค่า → บริการภายนอก → Apple Wallet', 'url' => $url, 'tag' => 'vb-wallet-exp']);
        $html = mail_layout('th', mail_esc($msg), '<p style="margin:0;font-size:15px;line-height:1.7;text-align:center">' . mail_esc($msg . ' — ต่ออายุได้ที่หลังบ้าน: ตั้งค่า → บริการภายนอก → Apple Wallet') . '</p>' . mail_button($url, 'เปิดหลังบ้าน'));
        foreach ($supers as $a) {
            mail_no_bcc(fn() => send_mail($a['email'], '[ระบบจอง] ' . $msg, $html, ['logo' => venue_logo_bytes()]));
        }
    } catch (Throwable $e) {
        error_log('wallet expiry: ' . $e->getMessage());
    }
}

/** สรุปจำนวนผู้เข้าชมทางแจ้งเตือนเว็บแอป (ตามที่แต่ละคนตั้ง) */
function run_push_summary(): int
{
    try {
        require_once __DIR__ . '/webpush.php';
        return push_summary_tick();
    } catch (Throwable $e) {
        error_log('push summary: ' . $e->getMessage());
        return 0;
    }
}

/** ส่งอีเมลเตือนที่ถึงเวลา — คืนจำนวนการจองที่ส่ง */
function run_reminders(?DateTimeImmutable $now = null): int
{
    $now = $now ?? new DateTimeImmutable('now', tz());
    // โหมดทดลอง: เตือนเฉพาะการจองทดสอบ (เข้ากล่องทดสอบ) — กันระบบที่ยังทดสอบส่งเตือนถึงคนจองจริงที่นำเข้าจาก Pretix
    $onlyTest = test_mode() ? ' AND o.is_test = 1' : '';
    $days = (int) setting('reminder_days', '1');
    $hour = (int) setting('reminder_hour', '9');
    $st = db()->prepare("SELECT o.*, MIN(s.starts_at) AS first_start FROM orders o JOIN tickets t ON t.order_id = o.id AND t.canceled = 0
                         JOIN sessions s ON s.id = t.session_id WHERE o.status = 'p' AND o.reminded_at IS NULL$onlyTest
                         GROUP BY o.id HAVING first_start > ? ORDER BY first_start LIMIT 200");
    $st->execute([$now->format(DATE_ATOM)]);
    $sent = 0;
    foreach ($st->fetchAll() as $o) {
        $visitDay = (new DateTimeImmutable($o['first_start']))->setTimezone(tz())->setTime(0, 0);
        $sendAt = $visitDay->modify("-$days days")->setTime($hour, 0);
        // จองหลังเวลาส่งเตือนไปแล้ว (เช่นจองวันเสาร์) = เพิ่งได้อีเมลยืนยัน ไม่ต้องเตือนซ้ำ
        if ($now < $sendAt || new DateTimeImmutable($o['created_at']) >= $sendAt) {
            if ($now >= $sendAt) {
                db()->prepare('UPDATE orders SET reminded_at = ? WHERE id = ? AND reminded_at IS NULL')->execute(['skip:' . now_iso(), $o['id']]);
            }
            continue;
        }
        $ts = array_values(array_filter(order_tickets((int) $o['id']), fn($t) => !$t['canceled']));
        if (!$ts) {
            continue;
        }
        // จองสิทธิ์ส่งแบบ atomic: ถ้ามีอีกงานส่งไปแล้ว (cron กับคนเปิดเว็บพร้อมกัน) ข้าม
        $claim = db()->prepare('UPDATE orders SET reminded_at = ? WHERE id = ? AND reminded_at IS NULL');
        $claim->execute([now_iso(), $o['id']]);
        if ($claim->rowCount() !== 1) {
            continue;
        }
        $slot = session_slot($ts[0]);
        $manage = public_url('manage.php?o=' . rawurlencode($o['code']) . '&s=' . rawurlencode($o['secret']));
        $tickets = array_map(fn($t) => ['n' => (int) $t['n'], 'name' => trim($t['given'] . ' ' . $t['family']), 'secret' => $t['secret'], 'email' => (string) $t['email'], 'msecret' => $t['msecret']], $ts);
        mail_use_outbox((bool) $o['is_test'], $o['code']);
        [$subj, $html, $img] = booking_email($o['lang'], $o['code'], $slot, $tickets, $manage, count($ts), false, true);
        send_mail($o['email'], $subj, $html, $img);
        foreach ($tickets as $t) {
            if ($t['email'] !== '' && mb_strtolower($t['email']) !== mb_strtolower($o['email'])) {
                [$subj, $html, $img] = booking_email($o['lang'], $o['code'], $slot, [$t], ticket_manage_url($t), count($ts), true, true);
                send_mail($t['email'], $subj, $html, $img);
            }
        }
        mail_use_outbox(false);
        $sent++;
    }
    return $sent;
}
