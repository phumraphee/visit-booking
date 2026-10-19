<?php
declare(strict_types=1);
/**
 * ดู / ยกเลิกการจองของตัวเอง — ยืนยันตัวด้วย code + secret
 *   GET  ?code=ABC12&secret=xxxx      → รายละเอียด + ตั๋ว (QR) + can_cancel
 *   POST {code, secret, lang}         → ยกเลิก + อีเมลยืนยันการยกเลิก
 *   POST {…, action:'confirm'}        → ยืนยันการจอง (ลิงก์ในอีเมล &confirm=1 — กดผ่าน JS ไม่ใช่ GET กันตัวสแกนลิงก์ในอีเมลกดแทน) + ส่งตั๋ว
 *   POST {…, action:'attend'}         → ยืนยันว่าจะมา (ปุ่มในอีเมลเตือน &attend=1)
 * กติกายกเลิก: settings.cancel_minutes ก่อนรอบเริ่ม (ค่าเดิมจาก Pretix = 600 นาที)
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/booking.php';
require_once __DIR__ . '/../lib/gwallet.php';
require_once __DIR__ . '/../lib/notify.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = $method === 'POST' ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : $_GET;
// เหตุผลที่ผู้จอง/ผู้เข้าชมยกเลิก (ไม่บังคับ) → ข้อความไทยสำหรับหลังบ้าน
const CANCEL_REASONS = ['busy' => 'ติดธุระ', 'sick' => 'ไม่สบาย', 'travel' => 'เปลี่ยนแผนการเดินทาง', 'wrong' => 'จองผิดวันหรือผิดรอบ', 'other' => 'อื่น ๆ'];
$rk = (string) ($in['reason_key'] ?? '');
$rt = mb_substr(trim((string) ($in['reason_text'] ?? '')), 0, 300);
$why = trim((CANCEL_REASONS[$rk] ?? '') . ($rt !== '' ? ((isset(CANCEL_REASONS[$rk]) ? ': ' : '') . $rt) : ''));
$whyLog = $why !== '' ? "\nเหตุผล: $why" : '';
$whyNote = $why !== '' ? " · เหตุผล: $why" : '';

// ---- ลิงก์ตั๋วส่วนตัวของผู้เข้าชม: ดู/ยกเลิกเฉพาะตั๋วใบนี้ ----
$tsec = trim((string) ($in['t'] ?? ''));
if ($tsec !== '') {
    $lang = ($in['lang'] ?? 'en') === 'th' ? 'th' : 'en';
    if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $tsec)) {
        json_fail(400, 'bad_link', 'ลิงก์ไม่ถูกต้อง');
    }
    if (rate_limited($method === 'POST' ? 'order' : 'order_view')) {
        json_fail(429, 'rate_limited', 'คุณส่งคำขอบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่');
    }
    $t = find_ticket_by_msecret($tsec) ?: json_fail(404, 'not_found', 'ไม่พบการจองนี้');
    $status = ($t['canceled'] || $t['order_status'] === 'c') ? 'c' : $t['order_status'];
    [$canCancel, $reason] = order_cancel_check(['status' => $status], [$t]);
    $canAttend = $status === 'p' && attend_window_open($t['starts_at']);
    $canEdit = $status === 'p' && new DateTimeImmutable($t['starts_at']) > new DateTimeImmutable('now', tz());
    if ($method === 'POST' && ($in['action'] ?? '') === 'edit') {
        if (!$canEdit) {
            json_fail(409, 'cannot_edit', 'แก้ไขข้อมูลไม่ได้แล้ว กรุณาติดต่อเรา');
        }
        $o = find_order((string) $t['code']);
        try {
            [$changes] = self_edit($o, [(int) $t['id'] => (array) ($in['ticket'] ?? []) + ['email' => $t['email']]], null);
        } catch (RuntimeException $e) {
            json_fail(422, 'validation', $e->getMessage());
        }
        if ($changes) {
            log_event('edited', $o, null, implode("\n", $changes), 'attendee');
            register_shutdown_function(fn() => notify_order('edited', $o, 'ผู้เข้าชมแก้ข้อมูลตัวเอง: ' . implode(' · ', $changes)));
        }
        json_out(200, ['ok' => true, 'changes' => count($changes)]);
    }
    if ($method === 'POST' && ($in['action'] ?? '') === 'attend') {
        if (!$canAttend) {
            json_fail(409, 'cannot_attend', 'การจองนี้ยืนยันไม่ได้แล้ว');
        }
        mark_attend((int) $t['order_id'], (int) $t['id']);
        log_event('attend', ['id' => (int) $t['order_id'], 'is_test' => $t['is_test']], null, trim($t['given'] . ' ' . $t['family']) . ' ยืนยันว่าจะมา', 'attendee');
        json_out(200, ['ok' => true, 'attend' => true]);
    }
    if ($method === 'POST' && !in_array($in['action'] ?? 'cancel', ['cancel'], true)) {
        json_fail(400, 'bad_action', 'คำขอไม่ถูกต้อง');
    }
    if ($method === 'POST') {
        if (!$canCancel) {
            json_fail(409, 'cannot_cancel', 'การจองนี้ไม่สามารถยกเลิกทางออนไลน์ได้ กรุณาติดต่อเรา', ['reason' => $reason]);
        }
        cancel_ticket($t, 'attendee');
        log_event('ticket_canceled', ['id' => (int) $t['order_id'], 'is_test' => $t['is_test']], null, trim($t['given'] . ' ' . $t['family']) . ' ยกเลิกตั๋วของตัวเอง (#' . $t['n'] . ')' . $whyLog, 'attendee');
        $to = (string) $t['email'];
        $code = $t['code'] . '-' . $t['n'];
        $slot = session_slot($t);
        $isTest = (bool) $t['is_test'];
        // แจ้งผู้ติดต่อด้วย (ภาษาตามที่ผู้ติดต่อใช้ตอนจอง) — บอกว่าเหลือกี่คน
        $left = count(array_filter(order_tickets((int) $t['order_id']), fn($x) => !$x['canceled']));
        $contact = (string) $t['order_email'];
        $contactLang = $t['order_lang'] === 'th' ? 'th' : 'en';
        $orderCode = (string) $t['code'];
        $name = trim($t['given'] . ' ' . $t['family']);
        $orderUrl = public_url('manage.php?o=' . rawurlencode($orderCode) . '&s=' . rawurlencode((string) $t['order_secret']));
        register_shutdown_function(function () use ($lang, $code, $slot, $to, $isTest, $left, $contact, $contactLang, $orderCode, $name, $orderUrl, $whyNote) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            mail_use_outbox($isTest, $orderCode);
            if ($to !== '') {
                [$subj, $html, $img] = cancel_email($lang, $code, $slot, 1);
                send_mail($to, $subj, $html, $img);
            }
            if ($contact !== '' && mb_strtolower($contact) !== mb_strtolower($to)) {
                [$subj, $html, $img] = attendee_cancel_email($contactLang, $orderCode, $name, $slot, $left, $orderUrl);
                send_mail($contact, $subj, $html, $img);
            }
            mail_use_outbox(false);
            $o = find_order($orderCode);
            if ($o) {
                notify_order($left ? 'ticket_canceled' : 'canceled', $o, $name . ' ยกเลิกตั๋วของตัวเอง' . ($left ? ' · เหลือ ' . $left . ' คน' : '') . $whyNote);
            }
        });
        json_out(200, ['ok' => true, 'status' => 'c']);
    }
    json_out(200, [
        'ok' => true, 'mode' => 'ticket', 'code' => $t['code'] . '-' . $t['n'], 'status' => $status, 'qty' => 1,
        'names' => [trim($t['given'] . ' ' . $t['family'])], 'slot' => session_slot($t), 'can_cancel' => $canCancel, 'reason' => $reason,
        'ics' => null, 'gcal' => null, 'attend' => $t['attend_at'] !== null, 'can_attend' => $canAttend, 'can_edit' => $canEdit,
        'edit' => $canEdit ? ['tickets' => [['id' => (int) $t['id'], 'n' => (int) $t['n'], 'given' => $t['given'], 'family' => $t['family'], 'phone' => $t['phone'], 'email' => $t['email'], 'org' => $t['org'], 'own' => true]]] : null,
        'tickets' => $status === 'p' ? [[
            'n' => (int) $t['n'], 'name' => trim($t['given'] . ' ' . $t['family']),
            'qr' => 'data:image/png;base64,' . base64_encode(qr_png((string) $t['secret'], 6)),
            'pass' => null, 'checked_in' => $t['checked_in_at'] !== null,
        ]] : [],
    ]);
}

$code   = strtoupper(trim((string) ($in['code'] ?? '')));
$secret = trim((string) ($in['secret'] ?? ''));
$lang   = ($in['lang'] ?? 'en') === 'th' ? 'th' : 'en';
if (is_demo_link($code, $secret)) {   // การจองตัวอย่างจากอีเมลตัวอย่างของหลังบ้าน — ดูได้ ทำอะไรจริงไม่ได้
    if ($method === 'POST') {
        json_fail(409, 'demo', $lang === 'th' ? 'นี่คือการจองตัวอย่าง ปุ่มนี้ไม่มีผลจริง' : 'This is a sample booking. This button has no real effect.');
    }
    $slot = demo_slot();
    $tk = demo_tickets($lang);
    $q = 'o=PREVW&s=preview';
    json_out(200, ['ok' => true, 'demo' => true, 'code' => 'PREVW', 'status' => 'p', 'qty' => count($tk), 'names' => array_column($tk, 'name'), 'slot' => $slot,
        'can_cancel' => true, 'ics' => "api/ics.php?$q", 'gcal' => google_calendar_url('PREVW', $slot['starts_at'], $slot['ends_at'], count($tk), public_url("manage.php?$q"), $lang),
        'reason' => null, 'confirm_by' => null, 'expired' => false, 'attend' => false, 'can_attend' => true, 'can_edit' => false, 'edit' => null,
        'tickets' => array_map(fn($t) => ['n' => $t['n'], 'name' => $t['name'], 'qr' => 'data:image/png;base64,' . base64_encode(qr_png($t['secret'], 6)),
            'pass' => wallet_enabled() ? "api/pass.php?$q&n={$t['n']}" : null, 'gpass' => gwallet_enabled() ? "api/gpass.php?$q&n={$t['n']}&lang=$lang" : null, 'checked_in' => false], $tk)]);
}
if (!preg_match('/^[A-Z0-9]{5,16}$/', $code) || !preg_match('/^[A-Za-z0-9]{8,64}$/', $secret)) {
    json_fail(400, 'bad_link', 'ลิงก์ไม่ถูกต้อง');
}
if (rate_limited($method === 'POST' ? 'order' : 'order_view')) {
    json_fail(429, 'rate_limited', 'คุณส่งคำขอบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่');
}

$order = find_order($code);
if (!$order || !hash_equals((string) $order['secret'], $secret)) {
    json_fail(404, 'not_found', 'ไม่พบการจองนี้');
}
$tickets = order_tickets((int) $order['id']);
$active  = array_values(array_filter($tickets, fn($t) => !$t['canceled']));
$shown   = $active ?: $tickets;
[$canCancel, $reason] = order_cancel_check($order, $tickets);
$slot = $shown ? session_slot($shown[0]) : null;

$now = new DateTimeImmutable('now', tz());
$canAttend = $order['status'] === 'p' && $active && attend_window_open($active[0]['starts_at']);
$canEdit = in_array($order['status'], ['p', 'w'], true) && $active && new DateTimeImmutable($active[0]['starts_at']) > $now;
$action = $method === 'POST' ? (string) ($in['action'] ?? 'cancel') : '';
if ($method === 'POST' && !in_array($action, ['confirm', 'edit', 'cancel_tickets', 'cancel_ticket', 'attend', 'cancel'], true)) {
    json_fail(400, 'bad_action', 'คำขอไม่ถูกต้อง');   // ไม่ให้คำสั่งที่ไม่รู้จักตกไปเป็น "ยกเลิก"
}
if ($action === 'confirm') {
    if ($order['status'] === 'p') {
        json_out(200, ['ok' => true, 'status' => 'p', 'already' => true]);
    }
    if ($order['status'] !== 'w' || ($order['confirm_by'] && new DateTimeImmutable($order['confirm_by']) <= $now)) {
        json_fail(409, 'expired', 'หมดเวลายืนยันแล้ว การจองนี้ถูกยกเลิก กรุณาจองใหม่');
    }
    if (confirm_order($order)) {
        $order['status'] = 'p';
        log_event('confirmed', $order, null, 'ยืนยันอีเมลด้วยลิงก์', 'customer');
        register_shutdown_function(function () use ($order) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            send_booking_emails($order);
        });
    }
    json_out(200, ['ok' => true, 'status' => 'p']);
}
if ($action === 'edit') {
    if (!$canEdit) {
        json_fail(409, 'cannot_edit', 'แก้ไขข้อมูลไม่ได้แล้ว กรุณาติดต่อเรา');
    }
    $rows = [];
    foreach ((array) ($in['tickets'] ?? []) as $r) {
        $rows[(int) ($r['id'] ?? 0)] = (array) $r;
    }
    try {
        [$changes, $newEmails] = self_edit($order, $rows, (array) ($in['contact'] ?? []));
    } catch (RuntimeException $e) {
        json_fail(422, 'validation', $e->getMessage());
    }
    if ($changes) {
        log_event('edited', $order, null, implode("\n", $changes), 'customer');
        register_shutdown_function(function () use ($order, $changes, $newEmails) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            // ส่งตั๋วถึงอีเมลผู้เข้าชมที่เพิ่งใส่ — รวมไม่เกิน 5 ฉบับต่อการจอง (กันใช้แก้อีเมลวนส่งสแปมในนามสถานที่)
            $left = max(0, 5 - (int) ($order['ticket_mails'] ?? 0));
            $newEmails = array_slice($newEmails, 0, $left);
            if ($newEmails && $order['status'] === 'p') {
                db()->prepare('UPDATE orders SET ticket_mails = ticket_mails + ? WHERE id = ?')->execute([count($newEmails), $order['id']]);
                send_ticket_to($order, $newEmails);
            }
            notify_order('edited', $order, implode(' · ', $changes));
        });
    }
    json_out(200, ['ok' => true, 'changes' => count($changes)]);
}
if ($action === 'cancel_tickets') {
    // ผู้จองเลือกยกเลิกบางคน (หลายคนพร้อมกัน) — ถ้าเลือกครบทุกคน ใช้การยกเลิกทั้งการจองแทน (ฝั่งหน้าเว็บ)
    $ns = array_map('intval', (array) ($in['ns'] ?? []));
    $sel = array_values(array_filter($active, fn($x) => in_array((int) $x['n'], $ns, true)));
    if (!$sel || !$canCancel) {
        json_fail(409, 'cannot_cancel', 'ยกเลิกไม่ได้ กรุณาติดต่อเรา', ['reason' => $reason]);
    }
    foreach ($sel as $t) {
        cancel_ticket($t, 'customer');
    }
    $names = array_map(fn($t) => trim($t['given'] . ' ' . $t['family']), $sel);
    $left = count($active) - count($sel);
    log_event('ticket_canceled', $order, null, 'ผู้จองยกเลิก ' . implode(', ', $names) . " · เหลือ $left คน" . $whyLog, 'customer');
    register_shutdown_function(function () use ($order, $sel, $names, $left, $whyNote) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        mail_use_outbox((bool) $order['is_test'], $order['code']);
        foreach ($sel as $t) {   // แจ้งผู้เข้าชมที่ถูกยกเลิก (ถ้ามีอีเมลของตัวเอง)
            if ($t['email'] && mb_strtolower($t['email']) !== mb_strtolower($order['email'])) {
                [$subj, $html, $img] = cancel_email($order['lang'] === 'th' ? 'th' : 'en', $order['code'] . '-' . $t['n'], session_slot($t), 1);
                send_mail($t['email'], $subj, $html, $img);
            }
        }
        mail_use_outbox(false);
        notify_order($left ? 'ticket_canceled' : 'canceled', find_order($order['code']), 'ผู้จองยกเลิก ' . implode(', ', $names) . ($left ? " · เหลือ $left คน" : '') . $whyNote);
    });
    json_out(200, ['ok' => true, 'left' => $left]);
}
if ($action === 'cancel_ticket') {
    // ผู้จองยกเลิกบางคน (เช่น จอง 5 มา 4) — คนที่เหลือใช้ตั๋วเดิม
    $n = (int) ($in['n'] ?? 0);
    $t = null;
    foreach ($active as $x) {
        if ((int) $x['n'] === $n) {
            $t = $x;
        }
    }
    if (!$t || !$canCancel) {
        json_fail(409, 'cannot_cancel', 'ยกเลิกไม่ได้ กรุณาติดต่อเรา', ['reason' => $reason]);
    }
    cancel_ticket($t, 'customer');
    $name = trim($t['given'] . ' ' . $t['family']);
    $left = count($active) - 1;
    log_event('ticket_canceled', $order, null, "ผู้จองยกเลิก #{$t['n']} $name · เหลือ $left คน" . $whyLog, 'customer');
    register_shutdown_function(function () use ($order, $t, $name, $left, $lang, $whyNote) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if ($t['email'] && mb_strtolower($t['email']) !== mb_strtolower($order['email'])) {   // แจ้งผู้เข้าชมคนนั้น (ถ้ามีอีเมลของตัวเอง)
            mail_use_outbox((bool) $order['is_test'], $order['code']);
            [$subj, $html, $img] = cancel_email($order['lang'] === 'th' ? 'th' : 'en', $order['code'] . '-' . $t['n'], session_slot($t), 1);
            send_mail($t['email'], $subj, $html, $img);
            mail_use_outbox(false);
        }
        notify_order($left ? 'ticket_canceled' : 'canceled', find_order($order['code']), "ผู้จองยกเลิก $name" . ($left ? " · เหลือ $left คน" : '') . $whyNote);
    });
    json_out(200, ['ok' => true, 'left' => $left]);
}
if ($action === 'attend') {
    if (!$canAttend) {
        json_fail(409, 'cannot_attend', 'การจองนี้ยืนยันไม่ได้แล้ว');
    }
    mark_attend((int) $order['id']);
    log_event('attend', $order, null, 'ยืนยันว่าจะมา (ทั้งการจอง)', 'customer');
    json_out(200, ['ok' => true, 'attend' => true]);
}

if ($method === 'POST') {
    if (!$canCancel) {
        json_fail(409, 'cannot_cancel', 'การจองนี้ไม่สามารถยกเลิกทางออนไลน์ได้ กรุณาติดต่อเรา', ['reason' => $reason]);
    }
    cancel_order($order, 'customer');
    log_event('canceled', $order, null, 'ผู้จองยกเลิกเอง' . $whyLog, 'customer');
    $to = (string) $order['email'];
    $qty = count($active);
    $isTest = (bool) $order['is_test'];
    register_shutdown_function(function () use ($lang, $code, $slot, $qty, $to, $isTest, $whyNote) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        mail_use_outbox($isTest, $code);
        [$subj, $html, $img] = cancel_email($lang, $code, $slot, $qty);
        send_mail($to, $subj, $html, $img);
        mail_use_outbox(false);
        if ($o = find_order($code)) {
            notify_order('canceled', $o, 'ผู้จองยกเลิกเอง' . $whyNote);
        }
    });
    json_out(200, ['ok' => true, 'status' => 'c']);
}

json_out(200, [
    'ok'         => true,
    'code'       => $order['code'],
    'status'     => $order['status'],
    'qty'        => count($shown),
    'names'      => array_map(fn($t) => trim($t['given'] . ' ' . $t['family']), $shown),
    'slot'       => $slot,
    'can_cancel' => $canCancel,
    'ics'        => $order['status'] === 'p' && $active ? 'api/ics.php?o=' . rawurlencode($order['code']) . '&s=' . rawurlencode($order['secret']) : null,
    'gcal'       => $order['status'] === 'p' && $active ? google_calendar_url($order['code'], $active[0]['starts_at'], $active[0]['ends_at'], count($active),
                        public_url('manage.php?o=' . rawurlencode($order['code']) . '&s=' . rawurlencode($order['secret'])), $lang) : null,
    'reason'     => $reason,
    'confirm_by' => $order['status'] === 'w' ? $order['confirm_by'] : null,
    'expired'    => $order['canceled_by'] === 'unconfirmed',
    'attend'     => $active && !array_filter($active, fn($t) => $t['attend_at'] === null),
    'can_attend' => $canAttend,
    'can_edit'   => $canEdit,
    'edit'       => $canEdit ? [
        'contact' => ['given' => $order['given'], 'family' => $order['family'], 'email' => $order['email'], 'phone' => $order['phone'], 'org' => $order['org']],
        'tickets' => array_map(fn($t) => ['id' => (int) $t['id'], 'n' => (int) $t['n'], 'given' => $t['given'], 'family' => $t['family'], 'phone' => $t['phone'], 'email' => $t['email'], 'org' => $t['org']], $active),
    ] : null,
    'tickets'    => $order['status'] === 'p' ? array_map(fn($t) => [
        'n'    => (int) $t['n'],
        'name' => trim($t['given'] . ' ' . $t['family']),
        'qr'   => 'data:image/png;base64,' . base64_encode(qr_png((string) $t['secret'], 6)),
        'pass' => wallet_enabled() ? 'api/pass.php?o=' . rawurlencode($order['code']) . '&s=' . rawurlencode($order['secret']) . '&n=' . (int) $t['n'] : null,
        'gpass' => gwallet_enabled() ? 'api/gpass.php?o=' . rawurlencode($order['code']) . '&s=' . rawurlencode($order['secret']) . '&n=' . (int) $t['n'] : null,
        'checked_in' => $t['checked_in_at'] !== null,
    ], $active) : [],
]);
