<?php
declare(strict_types=1);
/**
 * ยืนยันการจอง
 * 1) อีเมล: จองแล้วเป็นสถานะ 'w' (กันที่ไว้) → กดปุ่มในอีเมลภายใน confirm_by → 'p' + ส่งตั๋ว; เลยเวลา = ยกเลิกอัตโนมัติ คืนที่
 *    confirm_by = เร็วสุดของ (จอง + email_confirm_hours), (00:00 ของวันก่อนวันเข้าชม แต่ให้อย่างน้อย 2 ชม.), เวลาเริ่มรอบ
 * 2) ปุ่ม "ยืนยันว่าจะมา" ในอีเมลเตือน → tickets.attend_at (หลังบ้านเห็นว่าใครยังเงียบ จะได้โทรตาม)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/emails.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/sms.php';

function email_confirm_on(): bool
{
    return setting('email_confirm', '0') === '1';
}

function confirm_deadline(DateTimeImmutable $created, string $startsAt): string
{
    $start = (new DateTimeImmutable($startsAt))->setTimezone(tz());
    $hours = max(1, (int) setting('email_confirm_hours', '24'));
    $a = $created->modify("+$hours hours");
    $dayBefore = $start->setTime(0, 0)->modify('-1 day');
    $b = max($dayBefore, $created->modify('+2 hours'));
    return min($a, $b, $start)->setTimezone(tz())->format(DATE_ATOM);
}

function order_manage_url(array $o): string
{
    return public_url('manage.php?o=' . rawurlencode((string) $o['code']) . '&s=' . rawurlencode((string) $o['secret']));
}

/** ตั๋วในรูปแบบที่ template อีเมลใช้ */
function mail_tickets(array $ts): array
{
    return array_map(fn($t) => ['n' => (int) $t['n'], 'name' => trim($t['given'] . ' ' . $t['family']), 'secret' => $t['secret'], 'email' => (string) $t['email'], 'msecret' => $t['msecret']], $ts);
}

/** อีเมลตั๋ว (ผู้ติดต่อ = ทุกใบ, ผู้เข้าชมที่มีอีเมลตัวเอง = ใบของตัวเอง) + Ghost — เรียกหลังตอบผู้ใช้แล้ว */
function send_booking_emails(array $o): void
{
    $ts = array_values(array_filter(order_tickets((int) $o['id']), fn($t) => !$t['canceled']));
    if (!$ts) {
        return;
    }
    $slot = session_slot($ts[0]);
    $tickets = mail_tickets($ts);
    $lang = $o['lang'] === 'th' ? 'th' : 'en';
    mail_use_outbox((bool) $o['is_test'], $o['code']);
    [$subj, $html, $img] = booking_email($lang, $o['code'], $slot, $tickets, order_manage_url($o), count($ts));
    if (!send_mail($o['email'], $subj, $html, $img)) {
        error_log("booking mail failed for {$o['code']}");
    }
    foreach ($tickets as $t) {
        if ($t['email'] !== '' && mb_strtolower($t['email']) !== mb_strtolower($o['email'])) {
            [$subj, $html, $img] = booking_email($lang, $o['code'], $slot, [$t], ticket_manage_url($t), count($ts), true);
            send_mail($t['email'], $subj, $html, $img);
        }
    }
    mail_use_outbox(false);
    if (isset($GLOBALS['mail_capture'])) {
        return;   // ดูตัวอย่าง: ไม่สมัครรับข่าวสาร
    }
    if (!$o['is_test']) {
        $opt = [];
        if ($o['news']) {
            $opt[$o['email']] = trim($o['given'] . ' ' . $o['family']);
        }
        foreach ($ts as $t) {
            if ($t['news'] && $t['email'] && !isset($opt[$t['email']])) {
                $opt[$t['email']] = trim($t['given'] . ' ' . $t['family']);
            }
        }
        foreach ($opt as $em => $name) {
            ghost_subscribe($em, $name, $lang, 'Visit booking ' . $o['code']);
        }
    }
}

/** อีเมลขอให้กดยืนยัน (ยังไม่มี QR) */
function send_confirm_request(array $o): void
{
    $ts = array_values(array_filter(order_tickets((int) $o['id']), fn($t) => !$t['canceled']));
    if (!$ts) {
        return;
    }
    mail_use_outbox((bool) $o['is_test'], $o['code']);
    [$subj, $html, $img] = confirm_request_email($o['lang'] === 'th' ? 'th' : 'en', $o['code'], session_slot($ts[0]), count($ts),
        array_map(fn($t) => trim($t['given'] . ' ' . $t['family']), $ts), order_manage_url($o) . '&confirm=1&lang=' . ($o['lang'] === 'th' ? 'th' : 'en'), (string) $o['confirm_by']);
    send_mail($o['email'], $subj, $html, $img);
    mail_use_outbox(false);
}

/** w → p (ครั้งเดียว) — คืน true ถ้าเพิ่งยืนยัน */
function confirm_order(array $o): bool
{
    $st = db()->prepare("UPDATE orders SET status = 'p', confirmed_at = ? WHERE id = ? AND status = 'w'");
    $st->execute([now_iso(), $o['id']]);
    return $st->rowCount() === 1;
}

/** ยกเลิกการจองที่ไม่ยืนยันภายในเวลา + แจ้งทางอีเมล — คืนจำนวน */
function expire_pending(): int
{
    $st = db()->prepare("SELECT * FROM orders WHERE status = 'w' AND confirm_by IS NOT NULL AND confirm_by < ? LIMIT 100");
    $st->execute([(new DateTimeImmutable('now', tz()))->format(DATE_ATOM)]);
    $n = 0;
    foreach ($st->fetchAll() as $o) {
        // เทียบเวลาแบบ DateTime (สตริง ISO ต่าง timezone เทียบกันตรง ๆ ไม่ได้)
        if (new DateTimeImmutable($o['confirm_by']) > new DateTimeImmutable('now', tz())) {
            continue;
        }
        $ts = array_values(array_filter(order_tickets((int) $o['id']), fn($t) => !$t['canceled']));
        $upd = db()->prepare("UPDATE orders SET status = 'c', canceled_at = ?, canceled_by = 'unconfirmed' WHERE id = ? AND status = 'w'");
        $upd->execute([now_iso(), $o['id']]);
        if ($upd->rowCount() !== 1) {
            continue;
        }
        db()->prepare('UPDATE tickets SET canceled = 1 WHERE order_id = ?')->execute([$o['id']]);
        if ($ts) {
            mail_use_outbox((bool) $o['is_test'], $o['code']);
            [$subj, $html, $img] = expired_email($o['lang'] === 'th' ? 'th' : 'en', $o['code'], session_slot($ts[0]), count($ts));
            send_mail($o['email'], $subj, $html, $img);
            mail_use_outbox(false);
        }
        notify_order('canceled', $o, 'ระบบยกเลิก: ไม่ได้ยืนยันอีเมลภายในเวลา');
        log_event('canceled', $o, null, 'ไม่ได้ยืนยันอีเมลภายในเวลา', 'system');
        $n++;
    }
    return $n;
}

/** ยืนยันว่าจะมา: ทุกใบที่ยังใช้ได้ในการจอง หรือเฉพาะใบเดียว */
function mark_attend(int $orderId, ?int $ticketId = null): void
{
    if ($ticketId) {
        db()->prepare('UPDATE tickets SET attend_at = COALESCE(attend_at, ?) WHERE id = ? AND canceled = 0')->execute([now_iso(), $ticketId]);
    } else {
        db()->prepare('UPDATE tickets SET attend_at = COALESCE(attend_at, ?) WHERE order_id = ? AND canceled = 0')->execute([now_iso(), $orderId]);
    }
}

/** ปุ่ม "ยืนยันว่าจะมา" เปิดตั้งแต่เวลาส่งอีเมลเตือน (reminder_days ก่อน เวลา reminder_hour) จนถึงเวลาเริ่มรอบ */
function attend_window_open(string $startsAt): bool
{
    $start = new DateTimeImmutable($startsAt);
    $now = new DateTimeImmutable('now', tz());
    $days = (int) setting('reminder_days', '1');
    $from = $start->setTimezone(tz())->setTime(0, 0)->modify("-$days days")->setTime((int) setting('reminder_hour', '9'), 0);
    return $now >= $from && $now < $start;
}

/**
 * ผู้จอง/ผู้เข้าชมแก้ข้อมูลเอง (แบบ Pretix allow_modifications) — คืนรายการที่เปลี่ยน หรือ throw RuntimeException(ข้อความ)
 * $rows = [ticket_id => ['given','family','phone','email','org']] ; $contact = ['given','family','phone','org'] หรือ null (อีเมลผู้ติดต่อแก้ไม่ได้)
 */
function self_edit(array $o, array $rows, ?array $contact): array
{
    $trim = fn($v) => is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : '';
    $lbl = ['given' => 'ชื่อ', 'family' => 'นามสกุล', 'email' => 'อีเมล', 'phone' => 'เบอร์', 'org' => 'หน่วยงาน'];
    $all = [];
    foreach (order_tickets((int) $o['id']) as $t) {
        $all[(int) $t['id']] = $t;
    }
    $changes = [];
    $newContact = null;
    if ($contact !== null) {
        $newContact = ['given' => mb_substr($trim($contact['given'] ?? ''), 0, 100), 'family' => mb_substr($trim($contact['family'] ?? ''), 0, 100),
            'phone' => normalize_phone($trim($contact['phone'] ?? '')), 'org' => mb_substr($trim($contact['org'] ?? ''), 0, 190)];
        if ($newContact['given'] === '' || $newContact['family'] === '') {
            throw new RuntimeException('กรุณากรอกชื่อและนามสกุลของผู้ติดต่อ');
        }
        if ($newContact['phone'] === null) {
            throw new RuntimeException('กรุณากรอกเบอร์โทรของผู้ติดต่อให้ถูกต้อง');
        }
        foreach ($newContact as $k => $v) {
            if ((string) $o[$k] !== (string) $v) {
                $changes[] = "ผู้ติดต่อ {$lbl[$k]}: " . ($o[$k] ?: '–') . ' → ' . ($v ?: '–');
            }
        }
    }
    $new = [];
    foreach ($rows as $id => $r) {
        $t = $all[(int) $id] ?? null;
        if (!$t || $t['canceled']) {
            continue;
        }
        $n = ['given' => mb_substr($trim($r['given'] ?? ''), 0, 100), 'family' => mb_substr($trim($r['family'] ?? ''), 0, 100),
            'phone' => $trim($r['phone'] ?? '') === '' ? '' : normalize_phone($trim($r['phone'])), 'email' => mb_strtolower($trim($r['email'] ?? $t['email'] ?? '')),
            'org' => mb_substr($trim($r['org'] ?? ''), 0, 190)];
        if ($n['given'] === '' || $n['family'] === '') {
            throw new RuntimeException('กรุณากรอกชื่อและนามสกุลของผู้เข้าชมคนที่ ' . $t['n']);
        }
        if ($n['phone'] === null) {
            throw new RuntimeException('กรุณากรอกเบอร์โทรของผู้เข้าชมคนที่ ' . $t['n'] . ' ให้ถูกต้อง');
        }
        if ($n['email'] !== '' && !filter_var($n['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('อีเมลของผู้เข้าชมคนที่ ' . $t['n'] . ' ไม่ถูกต้อง');
        }
        $new[(int) $id] = $n;
    }
    // เบอร์/อีเมลของผู้เข้าชมห้ามซ้ำกัน (ว่างได้) — รวมใบที่ไม่ได้แก้
    $seenP = $seenE = [];
    foreach ($all as $id => $t) {
        if ($t['canceled']) {
            continue;
        }
        $v = $new[$id] ?? ['phone' => (string) $t['phone'], 'email' => (string) $t['email']];
        if ($v['phone'] !== '' && isset($seenP[$v['phone']])) {
            throw new RuntimeException('เบอร์โทรของผู้เข้าชมแต่ละคนต้องไม่ซ้ำกัน — ถ้าไม่มีเบอร์ของตัวเอง เว้นว่างไว้ได้');
        }
        if ($v['email'] !== '' && isset($seenE[$v['email']])) {
            throw new RuntimeException('อีเมลของผู้เข้าชมแต่ละคนต้องไม่ซ้ำกัน — ถ้าไม่มีอีเมลของตัวเอง เว้นว่างไว้ได้');
        }
        $seenP[$v['phone']] = $seenE[$v['email']] = true;
        unset($seenP[''], $seenE['']);
    }
    $pdo = db();
    $pdo->beginTransaction();
    if ($newContact) {
        $pdo->prepare('UPDATE orders SET given = ?, family = ?, phone = ?, org = ? WHERE id = ?')
            ->execute([$newContact['given'], $newContact['family'], $newContact['phone'], $newContact['org'] ?: null, $o['id']]);
    }
    $newEmails = [];
    foreach ($new as $id => $n) {
        $t = $all[$id];
        foreach ($n as $k => $v) {
            if ((string) $t[$k] !== (string) $v) {
                $changes[] = "ผู้เข้าชม #{$t['n']} {$lbl[$k]}: " . ($t[$k] ?: '–') . ' → ' . ($v ?: '–');
            }
        }
        if ($n['email'] !== '' && $n['email'] !== mb_strtolower((string) $t['email']) && $n['email'] !== mb_strtolower($o['email'])) {
            $newEmails[] = $id;
        }
        $pdo->prepare('UPDATE tickets SET given = ?, family = ?, phone = ?, email = ?, org = ? WHERE id = ?')
            ->execute([$n['given'], $n['family'], $n['phone'] ?: null, $n['email'] ?: null, $n['org'] ?: null, $id]);
    }
    $pdo->commit();
    return [$changes, $newEmails];
}

/** หลังแก้ข้อมูล: ส่งตั๋วถึงอีเมลผู้เข้าชมที่เพิ่งใส่/เปลี่ยน */
function send_ticket_to(array $o, array $ticketIds): void
{
    $ts = array_values(array_filter(order_tickets((int) $o['id']), fn($t) => !$t['canceled']));
    if (!$ts) {
        return;
    }
    $slot = session_slot($ts[0]);
    mail_use_outbox((bool) $o['is_test'], $o['code']);
    foreach (mail_tickets(array_values(array_filter($ts, fn($t) => in_array((int) $t['id'], $ticketIds, true)))) as $t) {
        [$subj, $html, $img] = booking_email($o['lang'] === 'th' ? 'th' : 'en', $o['code'], $slot, [$t], ticket_manage_url($t), count($ts), true);
        send_mail($t['email'], $subj, $html, $img);
    }
    mail_use_outbox(false);
}
