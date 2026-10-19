<?php
declare(strict_types=1);
/**
 * แจ้งเตือนแอดมินแบบเรียลไทม์: จองใหม่ / ยกเลิก / ผู้เข้าชมยกเลิกตั๋ว / คืนการจอง / ลงชื่อรอ (waiting list)
 * ช่องทาง (เปิด/ปิดในหลังบ้าน → ตั้งค่า):
 *   - อีเมล: settings.notify_email (หลายอีเมลคั่นด้วย ,) + notify_events
 *   - Slack: settings.slack_webhook (Incoming Webhook URL) — เนื้อหาแบบเดียวกับที่ตั้งใน Pretix: จำนวนคน, อีเมล, รายชื่อผู้เข้าชม
 * การจองทดสอบไม่แจ้ง (ยกเว้นปุ่ม "ส่งทดสอบ" ในหลังบ้าน)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/venue.php';
require_once __DIR__ . '/emails.php';

const NOTIFY_EVENTS = ['booked', 'canceled', 'ticket_canceled', 'edited', 'restored', 'waitlist'];
const NOTIFY_LABEL = [
    'booked' => 'จองใหม่', 'canceled' => 'ยกเลิกการจอง', 'ticket_canceled' => 'ผู้เข้าชมยกเลิกตั๋ว',
    'restored' => 'คืนการจอง', 'edited' => 'ผู้จองแก้ไขข้อมูล', 'waitlist' => 'ลงชื่อรอ (รอบเต็ม)', 'test' => 'ทดสอบการแจ้งเตือน',
];

function notify_event_on(string $event): bool
{
    $on = setting('notify_events', implode(',', NOTIFY_EVENTS));
    return in_array($event, explode(',', (string) $on), true);
}

/** ข้อความแจ้งเตือน → [หัวเรื่อง, บรรทัด[], ลิงก์หลังบ้าน] */
function notify_build(string $event, array $d): array
{
    $slot = $d['slot'] ?? null;
    $when = '';
    if ($slot) {
        [$date, $time] = mail_fmt_slot($slot, 'th');
        $when = "$date $time";
    }
    $title = NOTIFY_LABEL[$event] . (isset($d['code']) ? ' · ' . $d['code'] : '');
    $lines = [];
    if ($when) {
        $lines[] = $when . (isset($d['qty']) ? ' · ' . $d['qty'] . ' คน' : '');
    }
    if (!empty($d['contact'])) {
        $lines[] = 'ผู้ติดต่อ: ' . $d['contact'] . (!empty($d['email']) ? ' <' . $d['email'] . '>' : '') . (!empty($d['phone']) ? ' · ' . $d['phone'] : '');
    }
    if (!empty($d['names'])) {
        $lines[] = 'ผู้เข้าชม: ' . implode(', ', $d['names']);
    }
    if (!empty($d['note'])) {
        $lines[] = $d['note'];
    }
    if (isset($d['booked'])) {
        $lines[] = 'ยอดจองรอบนี้ตอนนี้: ' . $d['booked'] . ' คน';
    }
    $link = public_url($d['admin_path'] ?? (isset($d['code']) ? 'admin/#/order/' . $d['code'] : 'admin/'));
    return [$title, $lines, $link];
}

/** แจ้งทุกช่องทางที่เปิด — เรียกหลังตอบผู้ใช้แล้ว (ช้า/ล่มไม่กระทบ) */
function notify_admin(string $event, array $d, bool $force = false): array
{
    $sent = ['email' => false, 'slack' => false, 'push' => 0];
    if (!$force && !empty($d['is_test'])) {
        return $sent;
    }
    [$title, $lines, $link] = notify_build($event, $d);
    // แจ้งเตือนในเว็บแอป — ตั้งรายคนเอง (ไม่ขึ้นกับ notify_events ของอีเมล/Slack)
    if ($event !== 'test') {
        try {
            require_once __DIR__ . '/webpush.php';
            $sent['push'] = push_event($event, $title, $lines, $link);
        } catch (Throwable $e) {
            error_log('push: ' . $e->getMessage());
        }
    }
    if (!$force && !notify_event_on($event)) {
        return $sent;
    }
    $prevOutbox = $GLOBALS['mail_outbox'] ?? false;
    $GLOBALS['mail_outbox'] = false;   // แจ้งเตือนแอดมินส่งจริงเสมอ (การจองทดสอบถูกกรองไว้ข้างบนแล้ว)
    // อีเมล
    $to = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) setting('notify_email', ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
    if ($to) {
        $content = '<h1 style="font-size:20px;margin:0 0 14px;font-weight:700">' . mail_esc($title) . '</h1>'
            . '<p style="margin:0;font-size:15px;line-height:1.7">' . implode('<br>', array_map('mail_esc', $lines)) . '</p>'
            . mail_button($link, 'เปิดในหลังบ้าน');
        $html = mail_layout('th', mail_esc($title), $content);
        $images = ['logo' => venue_logo_bytes()];
        foreach ($to as $addr) {
            $sent['email'] = mail_no_bcc(fn() => send_mail($addr, '[จองเข้าชม] ' . $title, $html, $images)) || $sent['email'];
        }
    }
    // Slack (Incoming Webhook)
    $hook = (string) setting('slack_webhook', '');
    if (preg_match('#^https://hooks\.slack\.com/#', $hook)) {
        $text = '*' . $title . '*' . "\n" . implode("\n", $lines) . "\n<" . $link . '|เปิดในหลังบ้าน>';
        $ch = curl_init($hook);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE)]);
        $res = curl_exec($ch);
        $sent['slack'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        if (!$sent['slack']) {
            error_log('slack notify failed: ' . substr((string) $res, 0, 200));
        }
    }
    $GLOBALS['mail_outbox'] = $prevOutbox;
    return $sent;
}

/** ข้อมูลแจ้งเตือนจากออเดอร์ */
function notify_order(string $event, array $o, string $note = ''): void
{
    $ts = order_tickets((int) $o['id']);
    $active = array_values(array_filter($ts, fn($t) => !$t['canceled']));
    $shown = $event === 'canceled' ? $ts : $active;
    $sid = $shown ? (int) $shown[0]['session_id'] : 0;
    $cap = null;
    $booked = null;
    if ($sid) {
        $st = db()->prepare('SELECT * FROM sessions WHERE id = ?');
        $st->execute([$sid]);
        $s = $st->fetch();
        $cap = $s ? session_capacity($s) : null;
        $booked = session_counts([$sid])[$sid]['booked'] ?? 0;
    }
    notify_admin($event, [
        'code' => $o['code'], 'slot' => $shown ? session_slot($shown[0]) : null, 'qty' => count($shown),
        'contact' => trim($o['given'] . ' ' . $o['family']), 'email' => $o['email'], 'phone' => $o['phone'],
        'names' => array_map(fn($t) => trim($t['given'] . ' ' . $t['family']), $shown), 'note' => $note,
        'booked' => $booked, 'capacity' => $cap, 'is_test' => (bool) $o['is_test'],
    ]);
}

/** เหตุผลที่ยกเลิก (orders.canceled_by) เป็นข้อความ */
function cancel_reason_th(?string $by): string
{
    return match (true) {
        $by === 'customer' => 'ผู้จองยกเลิกเอง',
        $by === 'attendee' => 'ผู้เข้าชมยกเลิกตั๋วของตัวเอง',
        $by === 'unconfirmed' => 'ระบบยกเลิก: ไม่ได้ยืนยันอีเมลภายในเวลา',
        $by !== null && str_starts_with($by, 'admin:') => 'แอดมินยกเลิก (' . substr($by, 6) . ')',
        default => 'ยกเลิก',
    };
}
