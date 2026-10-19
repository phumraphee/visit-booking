<?php
declare(strict_types=1);
/**
 * API หลังบ้าน + หน้าสแกน — POST JSON {action, ...} (ยกเว้น export = GET)
 * สิทธิ์: staff = สแกน/ดูรอบ/ค้นออเดอร์, admin = แก้รอบ/ยกเลิก/ส่งอีเมลซ้ำ/จัดการแอดมิน/ตั้งค่า
 */
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/webauthn.php';
require_once __DIR__ . '/../lib/booking.php';
require_once __DIR__ . '/../lib/sms.php';
require_once __DIR__ . '/../lib/notify.php';
require_once __DIR__ . '/../lib/waitlist.php';
require_once __DIR__ . '/../lib/history.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---- QR ลิงก์หน้าจอง (GET, ใช้ cookie) — ?dl=1 ดาวน์โหลด ----
if ($method === 'GET' && ($_GET['action'] ?? '') === 'qr') {
    require_admin('staff');
    require_once __DIR__ . '/../lib/mail.php';
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=3600');
    if (!empty($_GET['dl'])) {
        header('Content-Disposition: attachment; filename="booking-qr.png"');
    }
    echo qr_png(public_url('?utm_source=qr'), 16);   // สแกน QR นับเป็นช่องทาง "QR" ในหน้าสถิติ
    exit;
}

// ---- ตัวอย่างตั๋ว PDF / Apple Wallet จากข้อมูลสมมติ (GET, ใช้ cookie) — หน้า "อีเมล" ----
if ($method === 'GET' && in_array($_GET['action'] ?? '', ['sample_pdf', 'sample_pass', 'sample_gpass', 'sample_ics'], true)) {
    require_admin('staff');
    $lang = ($_GET['lang'] ?? 'th') === 'en' ? 'en' : 'th';
    $slot = demo_slot();   // รอบเดียวกับอีเมลตัวอย่าง
    if ($_GET['action'] === 'sample_ics') {
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . venue_slug() . '-visit-PREVW.ics"');
        echo booking_ics('PREVW', $slot, $slot['starts_at'], $slot['ends_at'], 2, public_url('manage.php?o=PREVW&s=preview'), $lang);
        exit;
    }
    if ($_GET['action'] === 'sample_pdf') {
        require_once __DIR__ . '/../lib/ticketpdf.php';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="sample-ticket-' . $lang . '.pdf"');
        echo ticket_pdf($lang, 'PREVW', $slot, [['n' => 1, 'name' => $lang === 'th' ? 'สมชาย ใจดี' : 'Somchai Jaidee', 'secret' => 'previewticket0001'], ['n' => 2, 'name' => 'Jane Doe', 'secret' => 'previewticket0002']]);
        exit;
    }
    if ($_GET['action'] === 'sample_gpass') {   // ตัวอย่าง Google Wallet (รอบสมมติ id 999999 — class ทดสอบ)
        require_once __DIR__ . '/../lib/gwallet.php';
        if (!gwallet_enabled()) {
            http_response_code(404);
            exit('Google Wallet is not available');
        }
        header('Location: ' . gw_save_url(['id' => random_int(100000, 999999999), 'n' => 1,   // id สุ่ม: ตัวอย่างใหม่ทุกครั้ง
            'given' => $lang === 'th' ? 'สมชาย' : 'Somchai', 'family' => $lang === 'th' ? 'ใจดี' : 'Jaidee', 'secret' => 'previewticket0001'],
            ['code' => 'PREVW', 'secret' => 'preview', 'lang' => $lang], ['id' => 999999, 'starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at']]), true, 302);
        exit;
    }
    if (!wallet_enabled()) {
        http_response_code(404);
        exit('Wallet is not available');
    }
    header('Content-Type: application/vnd.apple.pkpass');
    header('Content-Disposition: attachment; filename="sample-' . $lang . '.pkpass"');
    echo build_pkpass(['code' => 'PREVW', 'n' => 1, 'name' => $lang === 'th' ? 'สมชาย ใจดี' : 'Somchai Jaidee', 'secret' => 'previewticket0001',
        'slot' => $slot, 'starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at'], 'lang' => $lang, 'manage_url' => public_url('/')]);
    exit;
}

// ---- export CSV (GET, ใช้ cookie) ----
if ($method === 'GET' && ($_GET['action'] ?? '') === 'export') {
    require_admin('staff');
    // session=ID (รอบเดียว) หรือ sessions=ID,ID (ทั้งวัน)
    $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['sessions'] ?? $_GET['session'] ?? '')))));
    $ids = $ids ?: [0];
    $in = implode(',', $ids);
    $st = db()->prepare("SELECT o.code, t.n, t.given, t.family, t.phone, t.email, t.org, o.email AS contact_email, o.given || ' ' || o.family AS contact,
                                CASE WHEN t.canceled = 1 THEN 'canceled' ELSE 'ok' END AS status, t.checked_in_at, o.created_at, s.starts_at, s.ends_at
                         FROM tickets t JOIN orders o ON o.id = t.order_id JOIN sessions s ON s.id = t.session_id
                         WHERE t.session_id IN ($in) AND o.is_test = ? ORDER BY s.starts_at, t.canceled, t.family, t.given");
    $st->execute([test_mode() ? 1 : 0]);
    $rows = $st->fetchAll();
    $s = db()->query("SELECT MIN(starts_at) FROM sessions WHERE id IN ($in)");
    $name = 'visit-' . substr(str_replace([':', 'T'], ['', '-'], (string) $s->fetchColumn()), 0, count($ids) > 1 ? 10 : 15) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM ให้ Excel อ่านภาษาไทยถูก
    // กันสูตร Excel (CSV injection): ช่องที่ขึ้นต้นด้วย = + - @ tab → ใส่ ' นำหน้า
    $csvSafe = fn(array $row) => array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && !preg_match('/^\+[0-9 ]+$/', $v) ? "'" . $v : $v, $row);
    fputcsv($out, ['session', 'code', 'ticket', 'given', 'family', 'phone', 'email', 'organization', 'contact', 'contact_email', 'status', 'checked_in_at', 'booked_at'], ',', '"', '');
    foreach ($rows as $r) {
        $sl = session_slot($r);
        fputcsv($out, $csvSafe([$sl['date'] . ' ' . $sl['start'] . ($sl['end'] ? '-' . $sl['end'] : ''), $r['code'], $r['code'] . '-' . $r['n'], $r['given'], $r['family'], $r['phone'], $r['email'], $r['org'], $r['contact'], $r['contact_email'], $r['status'], $r['checked_in_at'], $r['created_at']]), ',', '"', '');
    }
    exit;
}

if ($method !== 'POST') {
    json_fail(405, 'method', 'Method not allowed');
}
require_same_origin();
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$action = (string) ($in['action'] ?? '');
$pdo = db();
// ดูอีเมลก่อนส่ง: action ที่ส่งอีเมลรับ preview=1 → คืนอีเมลที่จะส่ง (ไม่ส่ง ไม่แก้ข้อมูล)
$preview = !empty($in['preview']);
function preview_out(): never
{
    json_out(200, ['ok' => true, 'preview' => $GLOBALS['mail_capture'] ?? []]);
}
if ($preview) {
    $GLOBALS['mail_capture'] = [];
}
// ยกเลิกโดยแอดมิน: reason = เหตุผลที่แจ้งผู้จอง (อยู่ในอีเมล), note = หมายเหตุภายใน (เห็นเฉพาะหลังบ้าน) — ผู้ใช้กำหนดให้แยกกัน
$mailReason = mb_substr(trim((string) ($in['reason'] ?? '')), 0, 300);
$cancelNote = mb_substr(trim((string) ($in['note'] ?? '')), 0, 300);
$cancelLog = ($mailReason !== '' ? "\nเหตุผล (แจ้งผู้จอง): $mailReason" : '') . ($cancelNote !== '' ? "\nหมายเหตุภายใน: $cancelNote" : '');
$cancelNotify = ($mailReason !== '' ? " · เหตุผล: $mailReason" : '') . ($cancelNote !== '' ? " · หมายเหตุ: $cancelNote" : '');
require_once __DIR__ . '/../lib/audit.php';
if (!$preview && $plan = audit_plan($action, $in)) {
    audit_begin($action, $plan[0], $plan[1]);   // บันทึกหลังตอบสำเร็จ
}

/** รอบ + สถิติ */
/** ไทม์ไลน์ของการจอง: ประวัติ + อีเมลที่ส่ง + เช็คอิน เรียงตามเวลา */
function order_timeline(array $o, array $ts): array
{
    $items = [];
    $st = db()->prepare('SELECT at, type, actor, detail FROM event_log WHERE order_id = ?');
    $st->execute([$o['id']]);
    foreach ($st->fetchAll() as $e) {
        $items[] = ['at' => $e['at'], 'kind' => 'event', 'type' => $e['type'], 'actor' => $e['actor'], 'detail' => $e['detail']];
    }
    $st = db()->prepare('SELECT id, at, to_addr, subject, status FROM mail_log WHERE order_code = ?');
    $st->execute([$o['code']]);
    foreach ($st->fetchAll() as $m) {
        $items[] = ['at' => $m['at'], 'kind' => 'mail', 'id' => (int) $m['id'], 'to' => $m['to_addr'], 'subject' => $m['subject'], 'status' => $m['status']];
    }
    $ids = array_map(fn($t) => (int) $t['id'], $ts);
    if ($ids) {
        $names = [];
        foreach ($ts as $t) {
            $names[(int) $t['id']] = '#' . $t['n'] . ' ' . trim($t['given'] . ' ' . $t['family']);
        }
        foreach (db()->query('SELECT ticket_id, at, by, result FROM checkin_log WHERE ticket_id IN (' . implode(',', $ids) . ") AND result IN ('ok', 'undo')") as $c) {
            $items[] = ['at' => $c['at'], 'kind' => 'checkin', 'type' => $c['result'], 'actor' => $c['by'], 'detail' => $names[(int) $c['ticket_id']] ?? ''];
        }
    }
    if (!array_filter($items, fn($i) => $i['kind'] === 'event' && $i['type'] === 'booked')) {   // การจองก่อนมีประวัติ (เช่นนำเข้าจาก Pretix)
        $items[] = ['at' => $o['created_at'], 'kind' => 'event', 'type' => 'booked', 'actor' => $o['source'] === 'pretix' ? 'pretix' : 'customer', 'detail' => $o['source'] === 'pretix' ? 'นำเข้าจาก Pretix' : ''];
        if ($o['status'] === 'c' && $o['canceled_at']) {
            $items[] = ['at' => $o['canceled_at'], 'kind' => 'event', 'type' => 'canceled', 'actor' => $o['canceled_by'], 'detail' => ''];
        }
    }
    usort($items, fn($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
    return $items;
}

function waitlist_counts(): array
{
    $out = [];
    foreach (db()->query("SELECT session_id, COUNT(*) n FROM waitlist WHERE status IN ('waiting', 'invited') AND is_test = 0 GROUP BY session_id") as $r) {
        $out[(int) $r['session_id']] = (int) $r['n'];
    }
    return $out;
}

function session_row(array $s, array $counts): array
{
    static $wl = null;
    $wl ??= waitlist_counts();   // ครั้งเดียวต่อคำขอ
    $slot = session_slot($s);
    $c = $counts[(int) $s['id']] ?? ['booked' => 0, 'checked' => 0];
    return [
        'id' => (int) $s['id'], 'date' => $slot['date'], 'start' => $slot['start'], 'end' => $slot['end'],
        'starts_at' => $s['starts_at'], 'capacity' => $s['capacity'] === null ? null : (int) $s['capacity'],
        'capacity_eff' => session_capacity($s), 'active' => (bool) $s['active'], 'note' => $s['note'],
        'booked' => $c['booked'], 'checked' => $c['checked'],
        'waiting' => $wl[(int) $s['id']] ?? 0,
    ];
}

function canceled_counts(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_map('intval', $ids));
    $out = [];
    foreach (db()->query("SELECT t.session_id, COUNT(*) n FROM tickets t JOIN orders o ON o.id = t.order_id WHERE t.canceled = 1 AND o.is_test = 0 AND t.session_id IN ($in) GROUP BY t.session_id") as $r) {
        $out[(int) $r['session_id']] = (int) $r['n'];
    }
    return $out;
}

function ticket_row(array $t): array
{
    return [
        'id' => (int) $t['id'], 'code' => $t['code'] ?? null, 'n' => (int) $t['n'], 'given' => $t['given'], 'family' => $t['family'],
        'phone' => $t['phone'], 'email' => $t['email'], 'org' => $t['org'], 'canceled' => (bool) $t['canceled'],
        'checked_in_at' => $t['checked_in_at'], 'checked_in_by' => $t['checked_in_by'], 'session_id' => (int) $t['session_id'],
        'is_test' => !empty($t['is_test']), 'attend_at' => $t['attend_at'] ?? null, 'order_status' => $t['order_status'] ?? null, 'news' => !empty($t['news']),
    ];
}

/** เช็คอินด้วย secret ใน QR — คืนผล + ข้อมูลตั๋ว */
function do_checkin(string $secret, int $sessionId, string $by, bool $force = false, ?string $offlineAt = null): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT t.*, o.code, o.status AS order_status, o.canceled_by AS order_canceled_by, s.starts_at, s.ends_at FROM tickets t JOIN orders o ON o.id = t.order_id JOIN sessions s ON s.id = t.session_id WHERE t.secret = ?');
    $st->execute([trim($secret)]);
    $t = $st->fetch();
    $log = fn(string $r, ?int $tid) => $pdo->prepare('INSERT INTO checkin_log (ticket_id, at, by, result, raw) VALUES (?, ?, ?, ?, ?)')->execute([$tid, now_iso(), $by, $r, substr($secret, 0, 80)]);
    if (!$t) {
        $log('unknown', null);
        return ['result' => 'unknown'];
    }
    $info = ticket_row($t) + ['slot' => session_slot($t)];
    if ($t['canceled'] || $t['order_status'] === 'c') {
        $log('canceled', (int) $t['id']);
        // เหตุผล + คืนได้ไหม (ทั้งการจองถูกยกเลิก ไม่ใช่ผู้เข้าชมยกเลิกใบเดียว)
        return ['result' => 'canceled', 'ticket' => $info, 'reason' => $t['order_status'] === 'c' ? (string) $t['order_canceled_by'] : 'attendee',
                'restorable' => $t['order_status'] === 'c'];
    }
    // ยังไม่ได้ยืนยันอีเมล (จองใกล้เวลา) — แจ้งพนักงาน เช็คอินอยู่ดีได้
    if ($t['order_status'] === 'w' && !$force) {
        $log('unconfirmed', (int) $t['id']);
        return ['result' => 'unconfirmed', 'ticket' => $info];
    }
    // เช็คอินออฟไลน์ที่ส่งขึ้นมาทีหลัง: ใช้เวลาที่สแกนจริง ถ้าสแกนในวันของรอบนั้น (ไม่โดนล็อกเพราะซิงก์หลังเที่ยงคืน)
    $offline = $offlineAt !== null && strtotime($offlineAt) !== false && strtotime($offlineAt) <= time()
        && (new DateTimeImmutable($offlineAt))->setTimezone(tz())->format('Y-m-d') === session_slot($t)['date'];
    if (!$offline && session_locked($t['starts_at'])) {
        $log('locked', (int) $t['id']);
        return ['result' => 'locked', 'ticket' => $info];
    }
    if ((int) $t['session_id'] !== $sessionId && !$force) {
        $log('wrong_session', (int) $t['id']);
        return ['result' => 'wrong_session', 'ticket' => $info];
    }
    if ($t['checked_in_at']) {
        $log('already', (int) $t['id']);
        return ['result' => 'already', 'ticket' => $info];
    }
    $at = $offline ? (new DateTimeImmutable($offlineAt))->setTimezone(tz())->format(DATE_ATOM) : now_iso();
    $u = $pdo->prepare('UPDATE tickets SET checked_in_at = ?, checked_in_by = ? WHERE id = ? AND checked_in_at IS NULL');   // สองเครื่องสแกนพร้อมกัน = ได้ ok เครื่องเดียว
    $u->execute([$at, $by, $t['id']]);
    if ($u->rowCount() !== 1) {
        $log('already', (int) $t['id']);
        return ['result' => 'already', 'ticket' => $info];
    }
    $log('ok', (int) $t['id']);
    $info['checked_in_at'] = $at;
    $info['checked_in_by'] = $by;
    return ['result' => 'ok', 'ticket' => $info];
}

switch ($action) {

    // ---------- auth ----------
    case 'login_request':
        if (rate_limited('login')) {
            json_fail(429, 'rate_limited', 'ขอรหัสบ่อยเกินไป กรุณารอสักครู่');
        }
        auth_send_code((string) ($in['email'] ?? ''), ($in['lang'] ?? 'th') === 'en' ? 'en' : 'th', ($in['from'] ?? '') === 'scan' ? 'scan' : 'admin');
        json_out(200, ['ok' => true]);

    case 'login_verify':
        if (rate_limited('login')) {
            json_fail(429, 'rate_limited', 'ลองบ่อยเกินไป กรุณารอสักครู่');
        }
        $a = auth_verify_code((string) ($in['email'] ?? ''), (string) ($in['code'] ?? ''));
        if (!$a) {
            json_fail(401, 'bad_code', 'รหัสไม่ถูกต้องหรือหมดอายุ');
        }
        auth_login($a);
        json_out(200, ['ok' => true, 'me' => ['email' => $a['email'], 'name' => $a['name'], 'role' => $a['role'], 'super' => is_super($a), 'can_cancel' => can_cancel($a)]]);

    case 'login_link':
        if (rate_limited('login')) {
            json_fail(429, 'rate_limited', 'ลองบ่อยเกินไป กรุณารอสักครู่');
        }
        $a = auth_verify_link((string) ($in['token'] ?? ''));
        if (!$a) {
            json_fail(401, 'bad_link', 'ลิงก์เข้าสู่ระบบหมดอายุหรือถูกใช้ไปแล้ว กรุณาขอใหม่');
        }
        auth_login($a);
        json_out(200, ['ok' => true, 'me' => ['email' => $a['email'], 'name' => $a['name'], 'role' => $a['role'], 'super' => is_super($a), 'can_cancel' => can_cancel($a)]]);

    // ---------- passkey (Face ID / ลายนิ้วมือ) ----------
    case 'passkey_login_options':
        json_out(200, ['ok' => true, 'options' => wa_login_options()]);

    case 'passkey_login':
        if (rate_limited('login')) {
            json_fail(429, 'rate_limited', 'ลองบ่อยเกินไป กรุณารอสักครู่');
        }
        try {
            $a = wa_login_finish($in);
        } catch (Throwable $e) {
            json_fail(401, 'passkey_' . $e->getMessage(), $e->getMessage() === 'unknown'
                ? 'ไม่พบ passkey นี้ในระบบ (อาจถูกลบไปแล้ว) กรุณาเข้าสู่ระบบด้วยอีเมล'
                : 'ยืนยันตัวตนไม่สำเร็จ กรุณาลองใหม่ (รหัส: ' . $e->getMessage() . ')');
        }
        auth_login($a);
        json_out(200, ['ok' => true, 'me' => ['email' => $a['email'], 'name' => $a['name'], 'role' => $a['role'], 'super' => is_super($a), 'can_cancel' => can_cancel($a)]]);

    case 'passkey_register_options':
        json_out(200, ['ok' => true, 'options' => wa_register_options(require_admin())]);

    case 'passkey_register':
        $me = require_admin();
        try {
            wa_register_finish($me, $in);
        } catch (Throwable $e) {
            json_fail(400, 'passkey_' . $e->getMessage(), 'บันทึก passkey ไม่สำเร็จ กรุณาลองใหม่ (รหัส: ' . $e->getMessage() . ')');
        }
        json_out(200, ['ok' => true]);

    case 'passkeys':
        $me = require_admin();
        $st = db()->prepare('SELECT id, name, created_at, last_used_at FROM passkeys WHERE admin_id = ? ORDER BY id');
        $st->execute([$me['id']]);
        json_out(200, ['ok' => true, 'passkeys' => $st->fetchAll()]);

    case 'passkey_delete':
        $me = require_admin();
        db()->prepare('DELETE FROM passkeys WHERE id = ? AND admin_id = ?')->execute([(int) ($in['id'] ?? 0), $me['id']]);
        json_out(200, ['ok' => true]);

    case 'logout':
        auth_logout();
        json_out(200, ['ok' => true]);

    case 'me':
        $me = current_admin();
        json_out(200, ['ok' => true, 'me' => $me ? ['email' => $me['email'], 'name' => $me['name'], 'role' => $me['role'], 'super' => is_super($me), 'can_cancel' => can_cancel($me)] : null]);

    // ---------- รอบ + สถิติ ----------
    case 'sessions':
        require_admin('staff');
        $scope = (string) ($in['scope'] ?? 'upcoming');   // upcoming | past | all
        $today = (new DateTimeImmutable('today', tz()))->format(DATE_ATOM);
        $sql = match ($scope) {
            'past'  => 'SELECT * FROM sessions WHERE starts_at < ? ORDER BY starts_at DESC',
            'all'   => 'SELECT * FROM sessions WHERE ? IS NOT NULL ORDER BY starts_at',
            default => 'SELECT * FROM sessions WHERE starts_at >= ? ORDER BY starts_at',
        };
        $st = $pdo->prepare($sql);
        $st->execute([$today]);
        $rows = $st->fetchAll();
        $ids = array_column($rows, 'id');
        $counts = session_counts($ids);
        $canc = canceled_counts($ids);
        // รอบที่ไม่มีคนจองและเลยเวลา cutoff แล้ว = ปิดอัตโนมัติ (นับรายรอบ — แสดงให้แอดมินเห็นเหตุผล)
        $now = new DateTimeImmutable('now', tz());
        $auto = setting('empty_close', '1') === '1';
        $days = (int) setting('empty_close_days', '1');
        json_out(200, ['ok' => true, 'sessions' => array_map(function ($s) use ($counts, $canc, $now, $auto, $days) {
            $d = session_slot($s)['date'];
            $cut = (new DateTimeImmutable("$d 00:00", tz()))->modify("-$days days");
            return session_row($s, $counts) + ['canceled' => $canc[(int) $s['id']] ?? 0,
                'auto_closed' => $auto && $s['active'] && ($counts[(int) $s['id']]['booked'] ?? 0) === 0 && $now >= $cut && new DateTimeImmutable($s['starts_at']) > $now];
        }, $rows)]);

    case 'session':
        require_admin('staff');
        $sid = (int) ($in['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM sessions WHERE id = ?');
        $st->execute([$sid]);
        $s = $st->fetch() ?: json_fail(404, 'not_found', 'ไม่พบรอบนี้');
        $st = $pdo->prepare('SELECT t.*, o.code, o.is_test, o.status AS order_status, o.given AS c_given, o.family AS c_family, o.email AS c_email FROM tickets t JOIN orders o ON o.id = t.order_id WHERE t.session_id = ? ORDER BY t.canceled, t.family COLLATE NOCASE, t.given COLLATE NOCASE');
        $st->execute([$sid]);
        $tickets = array_map(fn($t) => ticket_row($t) + ['contact' => trim($t['c_given'] . ' ' . $t['c_family']), 'contact_email' => $t['c_email'],
            'hist' => $t['is_test'] ? null : ticket_history($t['email'], $t['phone'], (int) $t['order_id'], $t['given'], $t['family'])], $st->fetchAll());
        waitlist_expire();
        $wst = $pdo->prepare("SELECT id, given, family, email, phone, qty, status, invited_at, expires_at, order_code, is_test, created_at FROM waitlist WHERE session_id = ? AND status <> 'removed' ORDER BY id");
        $wst->execute([$sid]);
        $waitlist = $wst->fetchAll();
        json_out(200, ['ok' => true, 'waitlist' => $waitlist, 'session' => session_row($s, session_counts([$sid])) + ['canceled' => canceled_counts([$sid])[$sid] ?? 0,
            'locked' => session_locked($s['starts_at']), 'started' => new DateTimeImmutable($s['starts_at']) <= new DateTimeImmutable('now', tz())], 'tickets' => $tickets]);

    case 'session_save':
        require_admin('admin');
        $sid = (int) ($in['id'] ?? 0);
        $cap = ($in['capacity'] ?? '') === '' || $in['capacity'] === null ? null : max(0, (int) $in['capacity']);
        $pdo->prepare('UPDATE sessions SET capacity = ?, active = ?, note = ? WHERE id = ?')
            ->execute([$cap, !empty($in['active']) ? 1 : 0, trim((string) ($in['note'] ?? '')) ?: null, $sid]);
        json_out(200, ['ok' => true]);

    case 'session_create':
        require_admin('admin');
        $date = (string) ($in['date'] ?? '');
        $start = (string) ($in['start'] ?? '');
        $end = (string) ($in['end'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $start) || ($end !== '' && !preg_match('/^\d{2}:\d{2}$/', $end))) {
            json_fail(422, 'validation', 'กรุณากรอกวันและเวลาให้ถูกต้อง');
        }
        $sa = (new DateTimeImmutable("$date $start", tz()))->format(DATE_ATOM);
        $ea = $end !== '' ? (new DateTimeImmutable("$date $end", tz()))->format(DATE_ATOM) : null;
        $pdo->prepare('INSERT INTO sessions (starts_at, ends_at, capacity, active, note, created_at) VALUES (?, ?, ?, 1, ?, ?)')
            ->execute([$sa, $ea, ($in['capacity'] ?? '') === '' ? null : (int) $in['capacity'], trim((string) ($in['note'] ?? '')) ?: null, now_iso()]);
        json_out(200, ['ok' => true, 'id' => (int) $pdo->lastInsertId()]);

    case 'sessions_generate':
        // สร้างรอบอาทิตย์ที่ 2 และ 4 ล่วงหน้า N เดือน (ข้ามรอบที่มีอยู่แล้ว/ที่ปิดไว้)
        require_admin('admin');
        json_out(200, ['ok' => true, 'created' => generate_sessions(max(1, min(24, (int) ($in['months'] ?? 3))))]);

    // ---------- ออเดอร์ ----------
    case 'orders_search':
        require_admin('staff');
        $q = trim((string) ($in['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            json_out(200, ['ok' => true, 'orders' => []]);
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $digits = preg_replace('/\D/', '', $q);
        $st = $pdo->prepare("SELECT DISTINCT o.* FROM orders o LEFT JOIN tickets t ON t.order_id = o.id
            WHERE o.code = :code OR o.email LIKE :l ESCAPE '\\' OR (o.given || ' ' || o.family) LIKE :l ESCAPE '\\'
               OR (t.given || ' ' || t.family) LIKE :l ESCAPE '\\' OR t.email LIKE :l ESCAPE '\\' OR o.org LIKE :l ESCAPE '\\' OR t.org LIKE :l ESCAPE '\\'
               OR (:d <> '' AND (replace(o.phone,'+66','0') LIKE :dl OR replace(t.phone,'+66','0') LIKE :dl OR o.phone LIKE :dl OR t.phone LIKE :dl))
            ORDER BY o.created_at DESC LIMIT 50");
        $st->execute([':code' => strtoupper($q), ':l' => $like, ':d' => strlen($digits) >= 4 ? $digits : '', ':dl' => '%' . (strlen($digits) >= 4 ? $digits : '#') . '%']);
        $orders = [];
        foreach ($st->fetchAll() as $o) {
            $ts = order_tickets((int) $o['id']);
            $orders[] = [
                'code' => $o['code'], 'status' => $o['status'], 'is_test' => (bool) $o['is_test'], 'contact' => trim($o['given'] . ' ' . $o['family']), 'email' => $o['email'],
                'qty' => count(array_filter($ts, fn($t) => !$t['canceled'])) ?: count($ts), 'slot' => $ts ? session_slot($ts[0]) : null, 'created_at' => $o['created_at'],
            ];
        }
        json_out(200, ['ok' => true, 'orders' => $orders]);

    case 'order':
        $me = require_admin('staff');
        $o = find_order((string) ($in['code'] ?? '')) ?: json_fail(404, 'not_found', 'ไม่พบการจองนี้');
        $ts = order_tickets((int) $o['id']);
        json_out(200, ['ok' => true, 'order' => [
            'code' => $o['code'], 'status' => $o['status'], 'given' => $o['given'], 'family' => $o['family'], 'email' => $o['email'],
            'phone' => $o['phone'], 'org' => $o['org'], 'lang' => $o['lang'], 'news' => (bool) $o['news'], 'comment' => $o['comment'],
            'source' => $o['source'], 'is_test' => (bool) $o['is_test'], 'created_at' => $o['created_at'], 'canceled_at' => $o['canceled_at'], 'canceled_by' => $o['canceled_by'],
            // ลิงก์ของลูกค้า (ยกเลิก/แก้ได้) — เฉพาะคนที่มีสิทธิ์ยกเลิก
            'manage_url' => can_cancel($me) ? public_url('manage.php?o=' . rawurlencode($o['code']) . '&s=' . rawurlencode($o['secret'])) : null,
            'slot' => $ts ? session_slot($ts[0]) : null, 'session_id' => $ts ? (int) $ts[0]['session_id'] : null,
            'started' => $ts ? new DateTimeImmutable($ts[0]['starts_at']) <= new DateTimeImmutable('now', tz()) : true,
            'ended' => $ts ? new DateTimeImmutable($ts[0]['ends_at'] ?: $ts[0]['starts_at']) <= new DateTimeImmutable('now', tz()) : true,
            'internal_note' => $o['internal_note'],
            'confirm_by' => $o['confirm_by'], 'confirmed_at' => $o['confirmed_at'], 'phone_verified_at' => $o['phone_verified_at'], 'reminded_at' => $o['reminded_at'],
            'contact_hist' => $o['is_test'] ? null : contact_history($o['email'], $o['phone'], (int) $o['id'], $o['given'], $o['family']),
        ], 'tickets' => array_map(fn($t) => ticket_row($t) + ['hist' => $o['is_test'] ? null : ticket_history($t['email'], $t['phone'], (int) $t['order_id'], $t['given'], $t['family'])], $ts), 'timeline' => order_timeline($o, $ts)]);

    case 'order_edit':
        // แก้ข้อมูลผู้ติดต่อ/ผู้เข้าชม (เช่น สะกดชื่อผิด) — บันทึกว่าเปลี่ยนอะไร
        require_admin('admin');
        $o = find_order((string) ($in['code'] ?? '')) ?: json_fail(404, 'not_found', 'ไม่พบการจองนี้');
        $trim = fn($v) => is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : '';
        $normPh = function (string $v): ?string {
            if ($v === '') { return ''; }
            $d = preg_replace('/[^\d+]/', '', $v);
            return $d === '' ? null : ($d[0] === '+' ? $d : (preg_match('/^0\d{8,9}$/', $d) ? '+66' . substr($d, 1) : null));
        };
        $changes = [];
        $lbl = ['given' => 'ชื่อ', 'family' => 'นามสกุล', 'email' => 'อีเมล', 'phone' => 'เบอร์', 'org' => 'หน่วยงาน'];
        $c = (array) ($in['contact'] ?? []);
        $new = ['given' => $trim($c['given'] ?? ''), 'family' => $trim($c['family'] ?? ''), 'email' => mb_strtolower($trim($c['email'] ?? '')), 'phone' => $normPh($trim($c['phone'] ?? '')), 'org' => $trim($c['org'] ?? '')];
        if ($new['given'] === '' || $new['family'] === '' || !filter_var($new['email'], FILTER_VALIDATE_EMAIL) || $new['phone'] === null || $new['phone'] === '') {
            json_fail(422, 'validation', 'ข้อมูลผู้ติดต่อไม่ถูกต้อง (ชื่อ นามสกุล อีเมล เบอร์)');
        }
        $pdo->beginTransaction();
        foreach ($new as $k => $v) {
            if ((string) $o[$k] !== (string) $v) {
                $changes[] = "ผู้ติดต่อ {$lbl[$k]}: " . ($o[$k] ?: '–') . ' → ' . ($v ?: '–');
            }
        }
        $pdo->prepare('UPDATE orders SET given = ?, family = ?, email = ?, phone = ?, org = ? WHERE id = ?')
            ->execute([$new['given'], $new['family'], $new['email'], $new['phone'], $new['org'] ?: null, $o['id']]);
        $old = [];
        foreach (order_tickets((int) $o['id']) as $t) {
            $old[(int) $t['id']] = $t;
        }
        foreach ((array) ($in['tickets'] ?? []) as $t) {
            $id = (int) ($t['id'] ?? 0);
            if (!isset($old[$id])) {
                continue;
            }
            $nt = ['given' => $trim($t['given'] ?? ''), 'family' => $trim($t['family'] ?? ''), 'email' => mb_strtolower($trim($t['email'] ?? '')), 'phone' => $normPh($trim($t['phone'] ?? '')), 'org' => $trim($t['org'] ?? '')];
            if ($nt['given'] === '' || $nt['family'] === '' || ($nt['email'] !== '' && !filter_var($nt['email'], FILTER_VALIDATE_EMAIL)) || $nt['phone'] === null) {
                $pdo->rollBack();
                json_fail(422, 'validation', 'ข้อมูลผู้เข้าชมคนที่ ' . $old[$id]['n'] . ' ไม่ถูกต้อง');
            }
            foreach ($nt as $k => $v) {
                if ((string) $old[$id][$k] !== (string) $v) {
                    $changes[] = "ผู้เข้าชม #{$old[$id]['n']} {$lbl[$k]}: " . ($old[$id][$k] ?: '–') . ' → ' . ($v ?: '–');
                }
            }
            $pdo->prepare('UPDATE tickets SET given = ?, family = ?, email = ?, phone = ?, org = ? WHERE id = ?')
                ->execute([$nt['given'], $nt['family'], $nt['email'] ?: null, $nt['phone'] ?: null, $nt['org'] ?: null, $id]);
        }
        $pdo->commit();
        if ($changes) {
            log_event('edited', $o, null, implode("\n", $changes));
        }
        json_out(200, ['ok' => true, 'changes' => count($changes)]);

    case 'ticket_cancel':
        // แอดมินยกเลิกผู้เข้าชมคนเดียว (คนอื่นในการจองยังใช้ตั๋วได้)
        $me = require_cancel();
        $st = $pdo->prepare('SELECT t.*, s.starts_at, s.ends_at FROM tickets t JOIN sessions s ON s.id = t.session_id WHERE t.id = ? AND t.canceled = 0');
        $st->execute([(int) ($in['id'] ?? 0)]);
        $t = $st->fetch() ?: json_fail(404, 'not_found', 'ไม่พบตั๋วนี้');
        if (new DateTimeImmutable($t['starts_at']) <= new DateTimeImmutable('now', tz())) {
            json_fail(409, 'locked', 'รอบนี้เริ่มหรือผ่านไปแล้ว ยกเลิกย้อนหลังไม่ได้');
        }
        $o = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $o->execute([$t['order_id']]);
        $o = $o->fetch();
        if ($preview) {
            if ($t['email']) {
                [$subj, $html, $img] = cancel_email($o['lang'] === 'th' ? 'th' : 'en', $o['code'] . '-' . $t['n'], session_slot($t), 1, $mailReason);
                send_mail($t['email'], $subj, $html, $img);
            }
            preview_out();
        }
        cancel_ticket($t, 'admin:' . $me['email']);
        $name = trim($t['given'] . ' ' . $t['family']);
        log_event('ticket_canceled', $o, null, "แอดมินยกเลิก #{$t['n']} $name" . $cancelLog);
        if (!empty($in['notify']) && $t['email']) {
            mail_use_outbox((bool) $o['is_test'], $o['code']);
            [$subj, $html, $img] = cancel_email($o['lang'] === 'th' ? 'th' : 'en', $o['code'] . '-' . $t['n'], session_slot($t), 1, $mailReason);
            send_mail($t['email'], $subj, $html, $img);
            mail_use_outbox(false);
        }
        notify_order('ticket_canceled', find_order($o['code']), "แอดมินยกเลิก $name" . $cancelNotify);
        json_out(200, ['ok' => true]);

    case 'ticket_resend':
        // ส่งตั๋วถึงอีเมลของผู้เข้าชมคนนี้
        require_admin('admin');
        $st = $pdo->prepare('SELECT t.*, o.code FROM tickets t JOIN orders o ON o.id = t.order_id WHERE t.id = ? AND t.canceled = 0 AND o.status = ?');
        $st->execute([(int) ($in['id'] ?? 0), 'p']);
        $t = $st->fetch() ?: json_fail(404, 'not_found', 'ไม่พบตั๋วนี้');
        if (!$t['email']) {
            json_fail(422, 'no_email', 'ผู้เข้าชมคนนี้ไม่มีอีเมล');
        }
        $o = find_order($t['code']);
        send_ticket_to($o, [(int) $t['id']]);
        if ($preview) {
            preview_out();
        }
        log_event('resend', $o, null, 'ส่งตั๋วถึง ' . trim($t['given'] . ' ' . $t['family']) . ' <' . $t['email'] . '>');
        json_out(200, ['ok' => true, 'to' => $t['email']]);

    case 'order_mark_test':
        // ทำเป็นการจองทดสอบ (ไม่นับสถิติ/ไม่แจ้งเตือน/ซ่อนจากกิจกรรม) — กดกลับได้; ใช้ตอนทดลองระบบจริงโดยไม่เปิดโหมดทดลอง
        require_admin('admin');
        $o = find_order((string) ($in['code'] ?? '')) ?: json_fail(404, 'not_found', 'ไม่พบการจองนี้');
        $on = !empty($in['on']) ? 1 : 0;
        $pdo->prepare('UPDATE orders SET is_test = ? WHERE id = ?')->execute([$on, $o['id']]);
        $pdo->prepare('UPDATE event_log SET is_test = ? WHERE order_id = ?')->execute([$on, $o['id']]);
        log_event('note', ['id' => $o['id'], 'is_test' => $on], null, $on ? 'ทำเป็นการจองทดสอบ (ไม่นับสถิติ)' : 'ยกเลิกเครื่องหมายทดสอบ (กลับมานับสถิติ)');
        json_out(200, ['ok' => true]);

    case 'order_delete':
        // ลบถาวร — เฉพาะการจองทดสอบเท่านั้น (กันลบของจริง)
        require_admin('admin');
        $o = find_order((string) ($in['code'] ?? '')) ?: json_fail(404, 'not_found', 'ไม่พบการจองนี้');
        if (!$o['is_test']) {
            json_fail(409, 'not_test', 'ลบได้เฉพาะการจองทดสอบ — ทำเป็นการจองทดสอบก่อน');
        }
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM checkin_log WHERE ticket_id IN (SELECT id FROM tickets WHERE order_id = ?)')->execute([$o['id']]);
        $pdo->prepare('DELETE FROM tickets WHERE order_id = ?')->execute([$o['id']]);
        $pdo->prepare('DELETE FROM event_log WHERE order_id = ?')->execute([$o['id']]);
        $pdo->prepare('DELETE FROM mail_log WHERE order_code = ?')->execute([$o['code']]);
        $pdo->prepare('DELETE FROM outbox WHERE order_code = ?')->execute([$o['code']]);
        $pdo->prepare("UPDATE waitlist SET order_code = NULL WHERE order_code = ?")->execute([$o['code']]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$o['id']]);
        $pdo->commit();
        json_out(200, ['ok' => true]);

    case 'order_note':
        require_admin('staff');
        $o = find_order((string) ($in['code'] ?? '')) ?: json_fail(404, 'not_found', 'ไม่พบการจองนี้');
        $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 2000);
        $pdo->prepare('UPDATE orders SET internal_note = ? WHERE id = ?')->execute([$note !== '' ? $note : null, $o['id']]);
        log_event('note', $o, null, $note !== '' ? 'แก้โน้ตภายใน: ' . mb_substr($note, 0, 200) : 'ลบโน้ตภายใน');
        json_out(200, ['ok' => true]);

    case 'activity':
        // ความเคลื่อนไหวล่าสุด: event_log + การจอง/ยกเลิกที่มีก่อนเริ่มบันทึก (เช่น นำเข้าจาก Pretix) + เช็คอิน
        require_admin('staff');
        $test = 1;   // แสดงการจองทดสอบด้วยเสมอ (มีป้าย "ทดสอบ") — ลบการจองทดสอบแล้วจึงหายไป (ผู้ใช้กำหนด)
        $before = (string) ($in['before'] ?? '');
        $before = $before !== '' ? $before : '9999';
        $sql = "SELECT * FROM (
            SELECT e.at, e.type, e.order_id, e.session_id, e.actor, e.detail, e.is_test FROM event_log e
            UNION ALL
            SELECT o.created_at, 'booked', o.id, (SELECT session_id FROM tickets WHERE order_id = o.id LIMIT 1),
                   CASE o.source WHEN 'pretix' THEN 'pretix' ELSE 'customer' END, (SELECT COUNT(*) FROM tickets WHERE order_id = o.id) || ' คน', o.is_test
              FROM orders o WHERE NOT EXISTS (SELECT 1 FROM event_log x WHERE x.order_id = o.id AND x.type = 'booked')
            UNION ALL
            SELECT o.canceled_at, 'canceled', o.id, (SELECT session_id FROM tickets WHERE order_id = o.id LIMIT 1), o.canceled_by, NULL, o.is_test
              FROM orders o WHERE o.status = 'c' AND o.canceled_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM event_log x WHERE x.order_id = o.id AND x.type = 'canceled')
            UNION ALL
            SELECT t.checked_in_at, 'checkin', t.order_id, t.session_id, t.checked_in_by, '#' || t.n || ' ' || t.given || ' ' || t.family, o.is_test
              FROM tickets t JOIN orders o ON o.id = t.order_id WHERE t.checked_in_at IS NOT NULL
        ) a WHERE a.at < ? AND (a.is_test = 0 OR ? = 1) AND (a.order_id IS NULL OR EXISTS (SELECT 1 FROM orders WHERE id = a.order_id)) ORDER BY a.at DESC LIMIT 50";
        $st = $pdo->prepare($sql);
        $st->bindValue(1, $before);
        $st->bindValue(2, $test, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        $orders = [];
        $sess = [];
        foreach ($rows as $r) {
            if ($r['order_id'] && !isset($orders[$r['order_id']])) {
                $q = $pdo->prepare('SELECT code, given, family FROM orders WHERE id = ?');
                $q->execute([$r['order_id']]);
                $orders[$r['order_id']] = $q->fetch() ?: null;
            }
            if ($r['session_id'] && !isset($sess[$r['session_id']])) {
                $q = $pdo->prepare('SELECT starts_at, ends_at FROM sessions WHERE id = ?');
                $q->execute([$r['session_id']]);
                $sess[$r['session_id']] = ($x = $q->fetch()) ? session_slot($x) : null;
            }
        }
        json_out(200, ['ok' => true, 'items' => array_map(function ($e) use ($orders, $sess) {
            $o = $e['order_id'] ? $orders[$e['order_id']] : null;
            return ['at' => $e['at'], 'type' => $e['type'], 'actor' => $e['actor'], 'detail' => $e['detail'], 'is_test' => (bool) $e['is_test'],
                'code' => $o['code'] ?? null, 'contact' => $o ? trim($o['given'] . ' ' . $o['family']) : '', 'session_id' => $e['session_id'] ? (int) $e['session_id'] : null,
                'slot' => $e['session_id'] ? $sess[$e['session_id']] : null];
        }, $rows)]);

    case 'mail_view':
        $me = require_admin('staff');
        $st = $pdo->prepare('SELECT * FROM mail_log WHERE id = ?');
        $st->execute([(int) ($in['id'] ?? 0)]);
        $m = $st->fetch() ?: json_fail(404, 'not_found', 'ไม่พบอีเมล');
        // รูปแนบ (cid) ไม่ได้เก็บไว้: โลโก้ = ไฟล์โลโก้, QR = สร้างใหม่จากตั๋วของการจองนี้ (ไม่งั้นจะเห็นเป็นโลโก้แทน QR)
        $html = preg_replace_callback('/src="cid:([a-z0-9]+)"/', function ($mm) use ($m) {
            if (preg_match('/^qr(\d+)$/', $mm[1], $q) && $m['order_code']) {
                $st = db()->prepare('SELECT t.secret FROM tickets t JOIN orders o ON o.id = t.order_id WHERE o.code = ? AND t.n = ?');
                $st->execute([$m['order_code'], (int) $q[1]]);
                if ($sec = $st->fetchColumn()) {
                    return 'src="data:image/png;base64,' . base64_encode(qr_png((string) $sec, 8)) . '"';
                }
            }
            return 'src="../' . venue_logo_path() . '"';
        }, (string) $m['html']);
        if (!can_cancel($me)) {   // ลิงก์ "การจองของฉัน" ยกเลิกได้ — ซ่อนจากพนักงานที่ไม่มีสิทธิ์ยกเลิก
            $html = preg_replace('#href="[^"]*(manage\.php|api/order\.php)[^"]*"#', 'href="#"', $html);
        }
        json_out(200, ['ok' => true, 'subject' => $m['subject'], 'to' => $m['to_addr'], 'html' => $html]);

    case 'session_mail':
        // ส่งอีเมลถึงทุกคนในรอบ (เช่น ปิดรอบกะทันหัน) — dry = นับผู้รับ/ดูตัวอย่างโดยไม่ส่ง
        require_admin('admin');
        // id = รอบเดียว หรือ "ID,ID" = ทั้งวัน
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($in['id'] ?? '')))));
        if (!$ids) {
            json_fail(404, 'not_found', 'ไม่พบรอบนี้');
        }
        $sid = $ids[0];
        $inIds = implode(',', $ids);
        $subject = mb_substr(trim((string) ($in['subject'] ?? '')), 0, 200);
        $body = mb_substr(trim((string) ($in['body'] ?? '')), 0, 5000);
        $who = ($in['who'] ?? 'contacts') === 'all' ? 'all' : 'contacts';
        $st = $pdo->prepare("SELECT o.*, t.email AS t_email, t.given AS t_given, t.family AS t_family, s.starts_at, s.ends_at FROM orders o JOIN tickets t ON t.order_id = o.id JOIN sessions s ON s.id = t.session_id
                             WHERE t.session_id IN ($inIds) AND t.canceled = 0 AND o.status IN ('p', 'w') AND o.is_test = ?");   // โหมดทดลอง = เฉพาะการจองทดสอบ (กันส่งถึงลูกค้าจริง)
        $st->execute([test_mode() ? 1 : 0]);
        $rcpt = [];   // email => [lang, name, order]
        foreach ($st->fetchAll() as $r) {
            $slotR = session_slot($r);
            $rcpt[mb_strtolower($r['email'])] ??= ['lang' => $r['lang'], 'name' => trim($r['given'] . ' ' . $r['family']), 'order' => $r, 'slot' => $slotR];
            if ($who === 'all' && $r['t_email']) {
                $rcpt[mb_strtolower($r['t_email'])] ??= ['lang' => $r['lang'], 'name' => trim($r['t_given'] . ' ' . $r['t_family']), 'order' => $r, 'slot' => $slotR];
            }
        }
        $build = function (array $r) use ($subject, $body): array {
            $slot = $r['slot'];
            $lang = $r['lang'] === 'th' ? 'th' : 'en';
            $content = mail_p(sprintf(mail_t($lang, 'greet_name'), mail_esc($r['name'])), 'text-align:left;margin-bottom:10px')
                . '<div style="font-size:15px;line-height:1.7;margin:0 0 16px">' . nl2br(mail_esc($body)) . '</div>'
                . mail_summary($lang, $r['order']['code'], $slot, null)
                . mail_closing($lang);
            return [$subject, mail_layout($lang, mail_esc($subject), $content), ['logo' => venue_logo_bytes()]];
        };
        if (!empty($in['dry']) || $subject === '' || $body === '') {
            $first = reset($rcpt);
            json_out(200, ['ok' => true, 'count' => count($rcpt), 'preview' => $first && $subject !== '' ? mail_preview_html(...array_slice($build($first), 1)) : null]);
        }
        $sent = 0;
        foreach ($rcpt as $email => $r) {
            mail_use_outbox((bool) $r['order']['is_test'], $r['order']['code']);
            [$sj, $html, $img] = $build($r);
            $sent += send_mail($email, $sj, $html, $img) ? 1 : 0;
        }
        mail_use_outbox(false);
        log_event('mass_mail', null, $sid, "ส่งอีเมลถึง $sent คน ({$who}" . (count($ids) > 1 ? ', ทั้งวัน ' . count($ids) . ' รอบ' : '') . "): $subject");
        json_out(200, ['ok' => true, 'sent' => $sent, 'count' => count($rcpt)]);

    case 'order_restore':
        // คืนการจองที่ถูกยกเลิก (เช่น ไม่ได้ยืนยัน / ยกเลิกผิด แล้วมาถึงหน้างาน) — ได้จนถึงเวลาจบรอบ, ต้องมีที่ว่างพอ
        $me = require_admin('staff');
        $o = find_order((string) ($in['code'] ?? '')) ?: json_fail(404, 'not_found', 'ไม่พบการจองนี้');
        if ($o['status'] !== 'c') {
            json_fail(409, 'status', 'การจองนี้ไม่ได้ถูกยกเลิก');
        }
        $ts = order_tickets((int) $o['id']);
        if (!$ts) {
            json_fail(409, 'status', 'การจองนี้ไม่มีตั๋ว');
        }
        $st = $pdo->prepare('SELECT * FROM sessions WHERE id = ?');
        $st->execute([$ts[0]['session_id']]);
        $sess = $st->fetch();
        $end = new DateTimeImmutable($sess['ends_at'] ?: $sess['starts_at']);
        if ($end <= new DateTimeImmutable('now', tz())) {
            json_fail(409, 'locked', 'รอบนี้จบไปแล้ว คืนการจองไม่ได้');
        }
        $cap = session_capacity($sess);
        $booked = session_counts([(int) $sess['id']])[(int) $sess['id']]['booked'] ?? 0;
        if ($cap !== null && !$o['is_test'] && $booked + count($ts) > $cap) {
            json_fail(409, 'full', 'รอบนี้เต็มแล้ว คืนการจองไม่ได้ (เพิ่มความจุของรอบก่อน)');
        }
        if ($preview) {   // จำลองหลังคืนแล้วสร้างอีเมล แล้วย้อนทิ้ง
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE tickets SET canceled = 0 WHERE order_id = ?')->execute([$o['id']]);
            send_booking_emails(['status' => 'p'] + $o);
            $pdo->rollBack();
            preview_out();
        }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE orders SET status = 'p', canceled_at = NULL, canceled_by = NULL, confirmed_at = COALESCE(confirmed_at, ?),
                       comment = trim(COALESCE(comment, '') || ?, char(10) || ' ') WHERE id = ?")
            ->execute([now_iso(), "\nRestored " . now_iso() . ' by ' . $me['email'] . ' (was canceled by ' . $o['canceled_by'] . ')', $o['id']]);
        $pdo->prepare('UPDATE tickets SET canceled = 0 WHERE order_id = ?')->execute([$o['id']]);
        $pdo->commit();
        if (!empty($in['notify'])) {
            $o['status'] = 'p';
            send_booking_emails($o);
        }
        log_event('restored', $o, null, 'เดิม: ' . cancel_reason_th($o['canceled_by']) . (!empty($in['notify']) ? ' · ส่งอีเมลตั๋วอีกครั้ง' : ''));
        notify_order('restored', $o, 'คืนโดย ' . $me['email'] . ' · เดิม: ' . cancel_reason_th($o['canceled_by']));
        json_out(200, ['ok' => true, 'qty' => count($ts)]);

    case 'waitlist_invite':
        // ให้สิทธิ์จอง: ลิงก์จองได้แม้รอบเต็ม อายุ waitlist_hours ชม. (ส่งซ้ำ = ต่ออายุ)
        require_admin('admin');
        $st = $pdo->prepare("SELECT w.*, s.starts_at, s.ends_at FROM waitlist w JOIN sessions s ON s.id = w.session_id WHERE w.id = ? AND w.status IN ('waiting', 'invited', 'expired')");
        $st->execute([(int) ($in['id'] ?? 0)]);
        $w = $st->fetch() ?: json_fail(404, 'not_found', 'ไม่พบรายชื่อนี้');
        $start = new DateTimeImmutable($w['starts_at']);
        if ($start <= new DateTimeImmutable('now', tz())) {
            json_fail(409, 'locked', 'รอบนี้เริ่มไปแล้ว');
        }
        $exp = min((new DateTimeImmutable('now', tz()))->modify('+' . max(1, (int) setting('waitlist_hours', '48')) . ' hours'), $start);
        $w['token'] = $w['token'] ?: random_secret(32);
        $w['expires_at'] = $exp->setTimezone(tz())->format(DATE_ATOM);
        if ($preview) {
            [$subj, $html, $img] = waitlist_email('invited', $w, session_slot($w));
            send_mail($w['email'], $subj, $html, $img);
            preview_out();
        }
        $pdo->prepare("UPDATE waitlist SET status = 'invited', token = ?, invited_at = ?, expires_at = ? WHERE id = ?")->execute([$w['token'], now_iso(), $w['expires_at'], $w['id']]);
        log_event('invited', ['id' => null, 'is_test' => $w['is_test']], (int) $w['session_id'], 'ให้สิทธิ์จองแก่ ' . $w['given'] . ' ' . $w['family'] . ' (' . $w['qty'] . ' คน)');
        mail_use_outbox((bool) $w['is_test'], 'WL' . $w['id']);
        [$subj, $html, $img] = waitlist_email('invited', $w, session_slot($w));
        $ok = send_mail($w['email'], $subj, $html, $img);
        mail_use_outbox(false);
        json_out(200, ['ok' => true, 'sent' => $ok, 'expires_at' => $w['expires_at']]);

    case 'waitlist_remove':
        require_admin('admin');
        $pdo->prepare("UPDATE waitlist SET status = 'removed' WHERE id = ?")->execute([(int) ($in['id'] ?? 0)]);
        json_out(200, ['ok' => true]);

    case 'order_confirm':
        // แอดมินยืนยันแทน (เช่นโทรคุยแล้ว อีเมลเข้าสแปม) → ส่งตั๋วทางอีเมล
        require_admin('admin');
        $o = find_order((string) ($in['code'] ?? '')) ?: json_fail(404, 'not_found', 'ไม่พบการจองนี้');
        if ($preview) {
            send_booking_emails($o);
            preview_out();
        }
        if (!confirm_order($o)) {
            json_fail(409, 'status', 'การจองนี้ไม่ได้อยู่ในสถานะรอยืนยัน');
        }
        log_event('confirmed', $o, null, 'แอดมินยืนยันแทน');
        send_booking_emails($o);
        json_out(200, ['ok' => true]);

    case 'order_cancel':
        $me = require_cancel();
        $o = find_order((string) ($in['code'] ?? '')) ?: json_fail(404, 'not_found', 'ไม่พบการจองนี้');
        if ($o['status'] === 'c') {
            json_fail(409, 'status', 'การจองนี้ถูกยกเลิกไปแล้ว');
        }
        $ts = array_values(array_filter(order_tickets((int) $o['id']), fn($t) => !$t['canceled']));
        if ($ts && new DateTimeImmutable($ts[0]['starts_at']) <= new DateTimeImmutable('now', tz())) {
            json_fail(409, 'locked', 'รอบนี้เริ่มหรือผ่านไปแล้ว ยกเลิกการจองย้อนหลังไม่ได้');
        }
        if ($preview) {
            [$subj, $html, $img] = cancel_email($o['lang'], $o['code'], $ts ? session_slot($ts[0]) : null, count($ts), $mailReason);
            send_mail($o['email'], $subj, $html, $img);
            preview_out();
        }
        cancel_order($o, 'admin:' . $me['email']);
        log_event('canceled', $o, null, 'แอดมินยกเลิก' . (!empty($in['notify']) ? ' · ส่งอีเมลแจ้งผู้จอง' : '') . $cancelLog);
        mail_use_outbox((bool) $o['is_test'], $o['code']);
        if (!empty($in['notify'])) {
            [$subj, $html, $img] = cancel_email($o['lang'], $o['code'], $ts ? session_slot($ts[0]) : null, count($ts), $mailReason);
            send_mail($o['email'], $subj, $html, $img);
        }
        mail_use_outbox(false);
        notify_order('canceled', $o, 'แอดมินยกเลิก (' . $me['email'] . ')' . $cancelNotify);
        json_out(200, ['ok' => true]);

    case 'noshow_mail':
        // อีเมลแจ้งผู้ที่จองแต่ไม่มา (รอบผ่านแล้ว · ไม่ยกเลิก · ไม่มีใครในการจองเช็คอิน) — code = การจองเดียว, sessions = "ID,ID" ทั้งรอบ/วัน
        // ส่งถึงอีเมลผู้ติดต่อ ฉบับละครั้งต่อการจอง (เคยส่งแล้วข้าม) · dry = นับ, preview = ดูตัวอย่าง
        require_admin('admin');
        $now = (new DateTimeImmutable('now', tz()))->format(DATE_ATOM);
        $where = "o.status = 'p' AND o.is_test = ? AND NOT EXISTS (SELECT 1 FROM tickets x WHERE x.order_id = o.id AND x.checked_in_at IS NOT NULL)
                  AND NOT EXISTS (SELECT 1 FROM event_log e WHERE e.order_id = o.id AND e.type = 'noshow_mail')";
        $params = [test_mode() ? 1 : 0];
        if (!empty($in['code'])) {
            $where .= ' AND o.code = ?';
            $params[] = strtoupper(trim((string) $in['code']));
        } else {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($in['sessions'] ?? '')))));
            if (!$ids) {
                json_fail(404, 'not_found', 'ไม่พบรอบนี้');
            }
            $where .= ' AND t.session_id IN (' . implode(',', $ids) . ')';
        }
        $st = $pdo->prepare("SELECT o.*, MIN(s.ends_at) ends_at, MIN(s.starts_at) starts_at FROM orders o JOIN tickets t ON t.order_id = o.id AND t.canceled = 0 JOIN sessions s ON s.id = t.session_id
                             WHERE $where GROUP BY o.id HAVING COALESCE(MIN(s.ends_at), MIN(s.starts_at)) <= ?");
        $st->execute([...$params, $now]);
        $rows = $st->fetchAll();
        if (!empty($in['dry'])) {
            json_out(200, ['ok' => true, 'count' => count($rows)]);
        }
        // ภาษาตามผู้จอง + รอบที่ไม่ได้มา
        $mail = function (array $o) use ($pdo): array {
            $ts = array_values(array_filter(order_tickets((int) $o['id']), fn($t) => !$t['canceled']));
            return noshow_email($o['lang'] === 'th' ? 'th' : 'en', $ts ? session_slot($ts[0]) : null, count($ts), $o['code']);
        };
        if ($preview) {   // ตัวอย่าง: ฉบับแรกของแต่ละภาษา
            $seen = [];
            foreach ($rows as $o) {
                $l = $o['lang'] === 'th' ? 'th' : 'en';
                if (!isset($seen[$l])) {
                    $seen[$l] = 1;
                    [$subj, $html, $img] = $mail($o);
                    send_mail($o['email'], $subj, $html, $img);
                }
            }
            if (!$rows) {
                [$subj, $html, $img] = noshow_email('th');
                send_mail('ผู้ติดต่อ', $subj, $html, $img);
            }
            preview_out();
        }
        $sent = 0;
        foreach ($rows as $o) {
            [$subj, $html, $img] = $mail($o);
            mail_use_outbox((bool) $o['is_test'], $o['code']);
            if (send_mail($o['email'], $subj, $html, $img)) {
                $sent++;
                log_event('noshow_mail', $o, null, 'ส่งอีเมลแจ้งไม่มาเข้าชมถึง ' . $o['email']);
            }
            mail_use_outbox(false);
        }
        json_out(200, ['ok' => true, 'sent' => $sent, 'count' => count($rows)]);

    case 'sessions_cancel':
        // ยกเลิกทั้งรอบ/ทั้งวัน (เช่น ไฟดับ) — ทุกการจองที่ยังใช้งานในรอบ → ยกเลิก + อีเมลแจ้ง (พร้อมเหตุผล) + ปิดรับจองรอบนั้น
        $me = require_admin('admin');
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($in['sessions'] ?? '')))));
        if (!$ids) {
            json_fail(404, 'not_found', 'ไม่พบรอบนี้');
        }
        $inIds = implode(',', $ids);
        $started = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id IN ($inIds) AND starts_at <= " . $pdo->quote((new DateTimeImmutable('now', tz()))->format(DATE_ATOM)))->fetchColumn();
        if ($started) {
            json_fail(409, 'locked', 'มีรอบที่เริ่มหรือผ่านไปแล้ว ยกเลิกทั้งรอบไม่ได้');
        }
        $st = $pdo->prepare("SELECT DISTINCT o.* FROM orders o JOIN tickets t ON t.order_id = o.id WHERE t.session_id IN ($inIds) AND t.canceled = 0 AND o.status IN ('p', 'w') AND o.is_test = ?");
        $st->execute([test_mode() ? 1 : 0]);
        $orders = $st->fetchAll();
        $people = 0;
        foreach ($orders as $o) {
            $people += count(array_filter(order_tickets((int) $o['id']), fn($t) => !$t['canceled']));
        }
        if (!empty($in['dry'])) {
            json_out(200, ['ok' => true, 'orders' => count($orders), 'people' => $people]);
        }
        if ($preview) {   // ตัวอย่างฉบับแรกของแต่ละภาษา
            $seen = [];
            foreach ($orders as $o) {
                $l = $o['lang'] === 'th' ? 'th' : 'en';
                if (isset($seen[$l])) {
                    continue;
                }
                $seen[$l] = 1;
                $ts = array_values(array_filter(order_tickets((int) $o['id']), fn($t) => !$t['canceled']));
                [$subj, $html, $img] = cancel_email($l, $o['code'], $ts ? session_slot($ts[0]) : null, count($ts), $mailReason);
                send_mail($o['email'], $subj, $html, $img);
            }
            preview_out();
        }
        $sent = 0;
        foreach ($orders as $o) {
            $ts = array_values(array_filter(order_tickets((int) $o['id']), fn($t) => !$t['canceled']));
            cancel_order($o, 'admin:' . $me['email']);
            log_event('canceled', $o, null, 'แอดมินยกเลิกทั้ง' . (count($ids) > 1 ? 'วัน' : 'รอบ') . (!empty($in['notify']) ? ' · ส่งอีเมลแจ้งผู้จอง' : '') . $cancelLog);
            if (!empty($in['notify'])) {
                mail_use_outbox((bool) $o['is_test'], $o['code']);
                [$subj, $html, $img] = cancel_email($o['lang'] === 'th' ? 'th' : 'en', $o['code'], $ts ? session_slot($ts[0]) : null, count($ts), $mailReason);
                $sent += send_mail($o['email'], $subj, $html, $img) ? 1 : 0;
                mail_use_outbox(false);
            }
        }
        if (!empty($in['close'])) {
            $pdo->exec("UPDATE sessions SET active = 0 WHERE id IN ($inIds)");
        }
        foreach ($ids as $sid) {
            log_event('note', null, $sid, 'ยกเลิกทั้ง' . (count($ids) > 1 ? 'วัน' : 'รอบ') . ' ' . count($orders) . " การจอง ($people คน)" . (!empty($in['close']) ? ' · ปิดรับจอง' : '') . $cancelLog);
        }
        if ($orders) {
            notify_admin('canceled', ['note' => 'ยกเลิกทั้ง' . (count($ids) > 1 ? 'วัน' : 'รอบ') . ' ' . count($orders) . " การจอง ($people คน) โดย {$me['email']}" . $cancelNotify]);
        }
        json_out(200, ['ok' => true, 'orders' => count($orders), 'people' => $people, 'sent' => $sent]);

    case 'login_code_issue':
        // ผู้ดูแลสูงสุดออกรหัสเข้าสู่ระบบแบบใช้ครั้งเดียวให้ผู้ใช้ (ไม่ต้องส่งอีเมล) — รหัส 6 หลัก + ลิงก์ อายุ 24 ชม.
        $me = require_admin('admin');
        if (!is_super($me)) {
            json_fail(403, 'forbidden', 'เฉพาะผู้ดูแลสูงสุด');
        }
        $st = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND active = 1');
        $st->execute([(int) ($in['id'] ?? 0)]);
        $u = $st->fetch() ?: json_fail(404, 'not_found', 'ไม่พบผู้ใช้ (หรือบัญชีถูกปิด)');
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token = bin2hex(random_bytes(24));
        $exp = time() + 86400;
        $pdo->prepare('DELETE FROM login_codes WHERE email = ? OR expires_at < ?')->execute([$u['email'], time()]);
        $pdo->prepare('INSERT INTO login_codes (email, code_hash, expires_at, link_hash) VALUES (?, ?, ?, ?)')
            ->execute([$u['email'], password_hash($code, PASSWORD_DEFAULT), $exp, hash('sha256', $token)]);
        json_out(200, ['ok' => true, 'email' => $u['email'], 'code' => $code, 'link' => public_url('admin/?login=' . $token),
            'expires_at' => (new DateTimeImmutable('@' . $exp))->setTimezone(tz())->format(DATE_ATOM)]);

    case 'order_resend':
        require_admin('admin');
        $o = find_order((string) ($in['code'] ?? '')) ?: json_fail(404, 'not_found', 'ไม่พบการจองนี้');
        $ts = array_values(array_filter(order_tickets((int) $o['id']), fn($t) => !$t['canceled']));
        if ($o['status'] !== 'p' || !$ts) {
            json_fail(409, 'status', 'การจองนี้ถูกยกเลิกแล้ว');
        }
        $tickets = array_map(fn($t) => ['n' => (int) $t['n'], 'name' => trim($t['given'] . ' ' . $t['family']), 'secret' => $t['secret'], 'email' => (string) $t['email']], $ts);
        $to = trim((string) ($in['to'] ?? '')) ?: $o['email'];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            json_fail(422, 'validation', 'อีเมลไม่ถูกต้อง');
        }
        mail_use_outbox((bool) $o['is_test'], $o['code']);
        [$subj, $html, $img] = booking_email($o['lang'], $o['code'], session_slot($ts[0]), $tickets,
            public_url('manage.php?o=' . rawurlencode($o['code']) . '&s=' . rawurlencode($o['secret'])), count($ts));
        $ok = send_mail($to, $subj, $html, $img);
        mail_use_outbox(false);
        if ($preview) {
            preview_out();
        }
        log_event('resend', $o, null, 'ส่งอีเมลยืนยันอีกครั้งถึง ' . $to);
        $ok ? json_out(200, ['ok' => true, 'to' => $to]) : json_fail(502, 'mail', 'ส่งอีเมลไม่สำเร็จ');

    // ---------- เช็คอิน ----------
    case 'checkin':
        $me = require_admin('staff');
        json_out(200, ['ok' => true] + do_checkin((string) ($in['secret'] ?? ''), (int) ($in['session'] ?? 0), $me['email'], !empty($in['force'])));

    case 'checkin_ticket':
        // เช็คอินด้วยมือ (ค้นชื่อ) หรือยกเลิกเช็คอิน
        $me = require_admin('staff');
        $st = $pdo->prepare('SELECT t.secret, t.session_id, s.starts_at FROM tickets t JOIN sessions s ON s.id = t.session_id WHERE t.id = ?');
        $st->execute([(int) ($in['id'] ?? 0)]);
        $t = $st->fetch() ?: json_fail(404, 'not_found', 'ไม่พบตั๋ว');
        if (session_locked($t['starts_at'])) {
            json_fail(409, 'locked', 'รอบนี้ผ่านไปแล้ว แก้ไขการเช็คอินไม่ได้');
        }
        if (!empty($in['undo'])) {
            $pdo->prepare('UPDATE tickets SET checked_in_at = NULL, checked_in_by = NULL WHERE id = ?')->execute([(int) $in['id']]);
            $pdo->prepare('INSERT INTO checkin_log (ticket_id, at, by, result) VALUES (?, ?, ?, ?)')->execute([(int) $in['id'], now_iso(), $me['email'], 'undo']);
            json_out(200, ['ok' => true, 'result' => 'undone']);
        }
        json_out(200, ['ok' => true] + do_checkin($t['secret'], (int) $t['session_id'], $me['email']));

    case 'scan_sync':
        // หน้าสแกน: ดึงรายชื่อตั๋วของรอบไว้ใช้ออฟไลน์ + ส่งผลเช็คอินที่ค้าง (คิวตอนเน็ตหลุด)
        $me = require_admin('staff');
        $sid = (int) ($in['session'] ?? 0);
        $results = [];
        // คิวทุกรอบ (ไม่ใช่เฉพาะรอบที่เปิดอยู่) — แต่ละรายการใช้รอบและเวลาที่สแกนจริงของตัวเอง
        foreach (array_slice((array) ($in['queue'] ?? []), 0, 500) as $q) {
            $results[] = ['secret' => (string) ($q['secret'] ?? '')] + do_checkin((string) ($q['secret'] ?? ''), (int) ($q['session'] ?? $sid), $me['email'] . ' (offline)', false, isset($q['at']) ? (string) $q['at'] : null);
        }
        // รวมการจองที่ถูกยกเลิก (x) ด้วย — หน้างานค้นชื่อเจอ เห็นเหตุผล และคืนการจองได้
        $st = $pdo->prepare('SELECT t.id, t.n, t.given, t.family, t.secret, t.checked_in_at, t.canceled, o.code, o.status, o.canceled_by FROM tickets t JOIN orders o ON o.id = t.order_id WHERE t.session_id = ?');
        $st->execute([$sid]);
        json_out(200, ['ok' => true, 'results' => $results, 'tickets' => array_map(fn($t) => [
            'id' => (int) $t['id'], 'code' => $t['code'], 'n' => (int) $t['n'], 'name' => trim($t['given'] . ' ' . $t['family']),
            's' => hash('sha256', $t['secret']), 'in' => $t['checked_in_at'], 'x' => (bool) $t['canceled'] || $t['status'] === 'c',
            'r' => $t['status'] === 'c' ? $t['canceled_by'] : ($t['canceled'] ? 'attendee' : null), 'w' => $t['status'] === 'w',
        ], $st->fetchAll())]);

    // ---------- แอดมิน + ตั้งค่า ----------
    case 'admins':
        $me = require_admin('admin');
        $rows = $pdo->query('SELECT id, email, name, role, super, active, created_at, can_cancel FROM admins ORDER BY role, email')->fetchAll();
        $sup = is_super($me);
        foreach ($rows as &$r) {
            $r['super'] = $sup && (int) $r['super'] ? 1 : 0;   // ผู้ดูแลทั่วไปเห็นทุกคนเป็น "ผู้ดูแล" เท่ากัน
        }
        unset($r);
        json_out(200, ['ok' => true, 'admins' => $rows, 'can_manage' => $sup]);

    case 'admin_save':
        $me = require_admin('admin');
        if (!is_super($me)) {
            json_fail(403, 'forbidden', 'เฉพาะผู้ดูแลสูงสุดจัดการผู้ใช้ได้');
        }
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_fail(422, 'validation', 'อีเมลไม่ถูกต้อง');
        }
        $level = (string) ($in['level'] ?? (($in['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff'));   // staff | admin | super
        $role = $level === 'staff' ? 'staff' : 'admin';
        $super = $level === 'super' ? 1 : 0;
        if ($email === $me['email']) {
            $super = 1;   // ผู้ดูแลสูงสุดลดระดับตัวเองไม่ได้
        }
        $active = array_key_exists('active', $in) ? (!empty($in['active']) ? 1 : 0) : 1;
        if ($email === $me['email'] && ($role !== 'admin' || !$active)) {
            json_fail(422, 'self', 'ลดสิทธิ์หรือปิดบัญชีตัวเองไม่ได้');
        }
        // ไม่ส่ง name มา (เช่น กดเปิด/ปิดบัญชี) = คงชื่อเดิม
        $hasName = array_key_exists('name', $in);
        $hasCx = array_key_exists('can_cancel', $in);   // พนักงานที่ยกเลิกการจองได้ (ไม่ส่งมา = คงเดิม)
        $pdo->prepare('INSERT INTO admins (email, name, role, super, active, created_at, can_cancel) VALUES (?, ?, ?, ?, ?, ?, ?)
                       ON CONFLICT(email) DO UPDATE SET name = CASE WHEN ? THEN excluded.name ELSE admins.name END, role = excluded.role, super = excluded.super, active = excluded.active,
                       can_cancel = CASE WHEN ? THEN excluded.can_cancel ELSE admins.can_cancel END')
            ->execute([$email, mb_substr(trim((string) ($in['name'] ?? '')), 0, 80) ?: null, $role, $super, $active, now_iso(), !empty($in['can_cancel']) ? 1 : 0, $hasName ? 1 : 0, $hasCx ? 1 : 0]);
        json_out(200, ['ok' => true]);

    case 'audit':
        // ประวัติการใช้งานทั้งหมด (ผู้ดูแลสูงสุดเท่านั้น) + การเข้าสู่ระบบ
        $me = require_admin('admin');
        if (!is_super($me)) {
            json_fail(403, 'forbidden', 'เฉพาะผู้ดูแลสูงสุด');
        }
        $before = (string) ($in['before'] ?? '') ?: '9999';
        $who = mb_strtolower(trim((string) ($in['who'] ?? '')));
        $st = $pdo->prepare("SELECT * FROM (
                SELECT id, at, actor, action, label, target, link, note, snap, undoable, undone_at, undone_by, undo_of FROM audit_log
                UNION ALL
                SELECT NULL, k.created_at, a.email, 'login', 'เข้าสู่ระบบ', NULL, NULL, k.ua, NULL, 0, NULL, NULL, NULL FROM auth_tokens k JOIN admins a ON a.id = k.admin_id
            ) WHERE at < ? AND (? = '' OR lower(actor) = ?) ORDER BY at DESC, id DESC LIMIT 60");
        $st->execute([$before, $who, $who]);
        $rows = array_map(function ($r) {
            $snap = $r['snap'] ? json_decode($r['snap'], true) : [];
            unset($r['snap']);
            $r['diff'] = $snap ? audit_diff($snap) : [];
            $r['undoable'] = (int) $r['undoable'] === 1 && !$r['undone_at'];
            if ($r['action'] === 'login') {
                $r['note'] = preg_match('/iPhone|iPad|Android|Macintosh|Windows/', (string) $r['note'], $m) ? $m[0] : null;
            }
            return $r;
        }, $st->fetchAll());
        $people = $pdo->query('SELECT email, name FROM admins ORDER BY email')->fetchAll();
        json_out(200, ['ok' => true, 'items' => $rows, 'people' => $people]);

    case 'audit_undo':
        $me = require_admin('admin');
        if (!is_super($me)) {
            json_fail(403, 'forbidden', 'เฉพาะผู้ดูแลสูงสุด');
        }
        $r = audit_undo((int) ($in['id'] ?? 0), !empty($in['force']));
        if (isset($r['error'])) {
            json_fail(409, 'undo', $r['error']);
        }
        json_out(200, ['ok' => !isset($r['conflict'])] + $r);

    case 'me_email_request':
        // เปลี่ยนอีเมลตัวเอง: ส่งรหัส 6 หลักไปที่อีเมลใหม่ก่อน (อีเมล = ตัวตนที่ใช้เข้าสู่ระบบ)
        $me = require_admin();
        $new = mb_strtolower(trim((string) ($in['email'] ?? '')));
        if (!filter_var($new, FILTER_VALIDATE_EMAIL)) {
            json_fail(422, 'validation', 'อีเมลไม่ถูกต้อง');
        }
        if ($new === $me['email']) {
            json_fail(422, 'same', 'เป็นอีเมลเดิมอยู่แล้ว');
        }
        $st = $pdo->prepare('SELECT 1 FROM admins WHERE email = ?');
        $st->execute([$new]);
        if ($st->fetchColumn()) {
            json_fail(409, 'taken', 'อีเมลนี้มีผู้ใช้อื่นใช้อยู่แล้ว');
        }
        $key = 'chg:' . $me['id'] . ':' . $new;
        $st = $pdo->prepare('SELECT COUNT(*) FROM phone_codes WHERE phone LIKE ? AND created_at > ?');
        $st->execute(['chg:' . $me['id'] . ':%', time() - 3600]);
        if ((int) $st->fetchColumn() >= 5) {
            json_fail(429, 'rate_limited', 'ขอรหัสบ่อยเกินไป กรุณารอสักครู่');
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO phone_codes (phone, code_hash, expires_at, ip, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$key, password_hash($code, PASSWORD_DEFAULT), time() + 600, client_ip(), time()]);
        $subject = "รหัสยืนยันอีเมลใหม่: $code";
        $content = '<p style="margin:0 0 6px;font-size:15px;text-align:center">' . mail_esc('กรอกรหัสนี้ในหน้าบัญชีของระบบจอง เพื่อเปลี่ยนอีเมลเข้าสู่ระบบเป็นอีเมลนี้ (ใช้ได้ 10 นาที)') . '</p>'
            . '<div style="text-align:center;font-family:Menlo,Consolas,monospace;font-size:34px;font-weight:700;letter-spacing:.3em;padding:14px 0 4px">' . $code . '</div>';
        $sent = mail_no_bcc(fn() => send_mail($new, $subject, mail_layout('th', mail_esc($subject), $content), ['logo' => venue_logo_bytes()]));
        $sent ? json_out(200, ['ok' => true]) : json_fail(502, 'mail', 'ส่งอีเมลไม่สำเร็จ');

    case 'me_email_verify':
        $me = require_admin();
        $new = mb_strtolower(trim((string) ($in['email'] ?? '')));
        if (!vc_verify('chg:' . $me['id'] . ':' . $new, (string) ($in['code'] ?? ''))) {
            json_fail(401, 'bad_code', 'รหัสไม่ถูกต้องหรือหมดอายุ');
        }
        try {
            $pdo->prepare('UPDATE admins SET email = ? WHERE id = ?')->execute([$new, $me['id']]);
        } catch (PDOException $e) {
            json_fail(409, 'taken', 'อีเมลนี้มีผู้ใช้อื่นใช้อยู่แล้ว');
        }
        // แจ้งอีเมลเดิม (กันถูกเปลี่ยนโดยไม่รู้ตัว)
        $subject = 'อีเมลเข้าสู่ระบบของคุณถูกเปลี่ยนแล้ว';
        $content = '<p style="margin:0;font-size:15px;line-height:1.7;text-align:center">' . mail_esc("บัญชีระบบจองของคุณเปลี่ยนอีเมลเข้าสู่ระบบจาก {$me['email']} เป็น $new แล้ว หากคุณไม่ได้เป็นผู้เปลี่ยน กรุณาแจ้งผู้ดูแลระบบทันที") . '</p>';
        mail_no_bcc(fn() => send_mail($me['email'], $subject, mail_layout('th', mail_esc($subject), $content), ['logo' => venue_logo_bytes()]));
        json_out(200, ['ok' => true, 'email' => $new]);

    case 'admin_email':
        // ผู้ดูแลสูงสุดเปลี่ยนอีเมลของผู้ใช้อื่น (เช่น พนักงานเปลี่ยนอีเมล) — ไม่ต้องยืนยัน
        $me = require_admin('admin');
        if (!is_super($me)) {
            json_fail(403, 'forbidden', 'เฉพาะผู้ดูแลสูงสุดจัดการผู้ใช้ได้');
        }
        $new = mb_strtolower(trim((string) ($in['email'] ?? '')));
        if (!filter_var($new, FILTER_VALIDATE_EMAIL)) {
            json_fail(422, 'validation', 'อีเมลไม่ถูกต้อง');
        }
        try {
            $pdo->prepare('UPDATE admins SET email = ? WHERE id = ?')->execute([$new, (int) ($in['id'] ?? 0)]);
        } catch (PDOException $e) {
            json_fail(409, 'taken', 'อีเมลนี้มีผู้ใช้อื่นใช้อยู่แล้ว');
        }
        json_out(200, ['ok' => true]);

    // ---------- แจ้งเตือนในเว็บแอป (Web Push) ----------
    case 'push_info':
        $me = require_admin();
        require_once __DIR__ . '/../lib/webpush.php';
        $st = $pdo->prepare('SELECT COUNT(*) FROM push_subs WHERE admin_id = ?');
        $st->execute([$me['id']]);
        json_out(200, ['ok' => true, 'supported' => push_supported(), 'key' => push_supported() ? webpush_vapid()['public'] : null,
            'prefs' => push_prefs($me), 'devices' => (int) $st->fetchColumn()]);

    case 'push_subscribe':
        $me = require_admin();
        $ep = (string) ($in['endpoint'] ?? '');
        if (!preg_match('#^https://#', $ep) || empty($in['p256dh']) || empty($in['auth'])) {
            json_fail(422, 'validation', 'ข้อมูลการสมัครไม่ถูกต้อง');
        }
        $pdo->prepare('INSERT INTO push_subs (admin_id, endpoint, p256dh, auth, ua, created_at) VALUES (?, ?, ?, ?, ?, ?)
                       ON CONFLICT(endpoint) DO UPDATE SET admin_id = excluded.admin_id, p256dh = excluded.p256dh, auth = excluded.auth')
            ->execute([$me['id'], $ep, (string) $in['p256dh'], (string) $in['auth'], mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), now_iso()]);
        json_out(200, ['ok' => true]);

    case 'push_unsubscribe':
        $me = require_admin();
        $pdo->prepare('DELETE FROM push_subs WHERE endpoint = ? AND admin_id = ?')->execute([(string) ($in['endpoint'] ?? ''), $me['id']]);
        json_out(200, ['ok' => true]);

    case 'push_prefs':
        $me = require_admin();
        require_once __DIR__ . '/../lib/webpush.php';
        $p = [
            'events' => array_values(array_intersect((array) ($in['events'] ?? []), PUSH_EVENTS)),
            'summary' => array_values(array_unique(array_filter(array_map('intval', (array) ($in['summary'] ?? [])), fn($d) => $d >= 0 && $d <= 3))),
            'hour' => max(5, min(22, (int) ($in['hour'] ?? 18))),
        ];
        $pdo->prepare('UPDATE admins SET push_prefs = ? WHERE id = ?')->execute([json_encode($p), $me['id']]);
        json_out(200, ['ok' => true, 'prefs' => $p]);

    case 'push_test':
        $me = require_admin();
        require_once __DIR__ . '/../lib/webpush.php';
        $n = push_to(fn($r) => (int) $r['admin_id'] === (int) $me['id'], ['title' => 'ทดสอบการแจ้งเตือน', 'body' => 'การแจ้งเตือนของระบบจองทำงานแล้ว', 'url' => public_url('admin/#/account'), 'tag' => 'vb-test']);
        json_out(200, ['ok' => true, 'sent' => $n]);

    case 'cron_status':
        // งานรายชั่วโมงใน Plesk (Scheduled Tasks → Fetch a URL) — ผู้ดูแลสูงสุดเห็นลิงก์พร้อมคีย์ ไว้ตั้งใหม่ถ้าถูกลบ
        is_super(require_admin('admin')) || json_fail(403, 'forbidden', 'เฉพาะผู้ดูแลสูงสุด');
        $key = (string) (cfg()['cron_key'] ?? '');
        $last = setting('cron_ran_at');
        json_out(200, ['ok' => true, 'url' => $key !== '' ? public_url('tools/reminders.php?key=' . rawurlencode($key)) : null, 'last' => $last,
            'healthy' => $last !== null && (time() - strtotime($last)) < 2 * 3600]);

    case 'wallet_status':
        $me = require_admin('admin');
        json_out(200, ['ok' => true] + wallet_cert_info());

    case 'wallet_csr':
    case 'wallet_cert_upload':
        // ต่ออายุใบรับรอง Apple Wallet จากหน้าเว็บ (ผู้ดูแลสูงสุด): สร้าง CSR → ขอใบรับรองที่ Apple → อัป .cer
        $me = require_admin('admin');
        if (!is_super($me)) {
            json_fail(403, 'forbidden', 'เฉพาะผู้ดูแลสูงสุด');
        }
        try {
            if ($action === 'wallet_csr') {
                json_out(200, ['ok' => true, 'csr' => wallet_make_csr()]);
            }
            $raw = base64_decode((string) ($in['data'] ?? ''), true);
            if (!$raw || strlen($raw) > 20000) {
                json_fail(422, 'validation', 'ไฟล์ไม่ถูกต้อง');
            }
            json_out(200, ['ok' => true] + wallet_install_cert($raw) + ['info' => wallet_cert_info()]);
        } catch (Throwable $e) {
            json_fail(422, 'wallet', $e->getMessage());
        }

    case 'integration_test':
        $me = require_admin('admin');
        if (!is_super($me)) {
            json_fail(403, 'forbidden', 'เฉพาะผู้ดูแลสูงสุด');
        }
        json_out(200, ['ok' => true] + ['result' => ext_test((string) ($in['which'] ?? ''))]);

    case 'ghost_backfill':
        // ส่งรายชื่อคนที่เคยติ๊กรับข่าวสาร (การจองเก่า รวมนำเข้าจาก Pretix) เข้า Ghost ครั้งเดียว — ติดป้าย visit-booking-backfill
        $me = require_admin('admin');
        if (!is_super($me)) {
            json_fail(403, 'forbidden', 'เฉพาะผู้ดูแลสูงสุด');
        }
        // การจองที่นำเข้าจาก Pretix: ความยินยอมรับข่าวสารอยู่ใน plugin_data.newsletter_manual (ตอนนำเข้าไม่ได้เก็บ) → ดึงมาใส่ orders.news ก่อน
        $pretixSynced = null;
        if (!empty($in['dry']) && !empty(cfg()['api_token']) && (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE source = 'pretix'")->fetchColumn()) {   // ดึงเฉพาะตอนนับ (ตอนส่งทีละชุดไม่ต้องดึงซ้ำ)
            try {
                $up = $pdo->prepare("UPDATE orders SET news = 1 WHERE code = ? AND source = 'pretix' AND news = 0");
                $pretixSynced = 0;
                foreach (pretix_get_all('orders/') as $po) {
                    if (!empty($po['plugin_data']['newsletter_manual']['newsletter_requested'])) {
                        $up->execute([(string) $po['code']]);
                        $pretixSynced += $up->rowCount();
                    }
                }
            } catch (Throwable $e) {
                error_log('pretix news sync: ' . $e->getMessage());
            }
        }
        $rows = $pdo->query("SELECT lower(o.email) email, trim(o.given || ' ' || o.family) name, o.lang, MIN(o.created_at) at FROM orders o WHERE o.news = 1 AND o.is_test = 0 AND o.email <> '' GROUP BY lower(o.email)
            UNION SELECT lower(t.email), trim(t.given || ' ' || t.family), o.lang, MIN(o.created_at) FROM tickets t JOIN orders o ON o.id = t.order_id WHERE t.news = 1 AND o.is_test = 0 AND t.email IS NOT NULL AND t.email <> '' GROUP BY lower(t.email)")->fetchAll();
        $uniq = [];
        foreach ($rows as $r) {
            $uniq[$r['email']] ??= $r;
        }
        if (!empty($in['dry'])) {
            json_out(200, ['ok' => true, 'count' => count($uniq), 'done_at' => setting('ghost_backfill_at'), 'pretix_synced' => $pretixSynced,
                'list' => array_values(array_map(fn($r) => ['email' => $r['email'], 'name' => $r['name'], 'lang' => $r['lang'], 'at' => $r['at']], $uniq))]);
        }
        // ส่งทีละชุด (emails = อีเมลในชุดนี้) ให้หน้าเว็บแสดงความคืบหน้า + ผลรายคน
        $want = array_map('mb_strtolower', (array) ($in['emails'] ?? array_keys($uniq)));
        $results = [];
        foreach ($want as $em) {
            if (!isset($uniq[$em])) {
                continue;
            }
            $r = $uniq[$em];
            $x = ghost_add_member($r['email'], $r['name'], $r['lang'] === 'th' ? 'th' : 'en', 'Backfill from visit bookings (opted in ' . substr((string) $r['at'], 0, 10) . ')', ['visit-booking-backfill']);
            if ($x === 'off') {
                json_fail(409, 'off', 'ยังไม่ได้ตั้งค่า Ghost');
            }
            $results[] = ['email' => $em, 'result' => $x];
        }
        setting_set('ghost_backfill_at', now_iso());
        json_out(200, ['ok' => true, 'results' => $results]);

    case 'integrations':
        // บริการภายนอก (ผู้ดูแลสูงสุด): ค่าลับไม่ส่งกลับ — บอกแค่ว่าตั้งแล้วหรือยัง; ส่งค่าว่าง = คงเดิม, clear = ลบ
        $me = require_admin('admin');
        if (!is_super($me)) {
            json_fail(403, 'forbidden', 'เฉพาะผู้ดูแลสูงสุด');
        }
        $keys = ['turnstile_site' => false, 'turnstile_secret' => true, 'ghost_url' => false, 'ghost_admin_key' => true];
        foreach ((array) ($in['values'] ?? []) as $k => $v) {
            if (!array_key_exists($k, $keys)) {
                continue;
            }
            $v = trim((string) $v);
            if ($k === 'ghost_admin_key' && $v !== '' && !preg_match('/^[0-9a-f]{24}:[0-9a-f]{64}$/', $v)) {
                json_fail(422, 'validation', 'Ghost Admin API key ต้องเป็นรูปแบบ id:secret');
            }
            if ($k === 'ghost_url' && $v !== '' && !preg_match('#^https://#', $v)) {
                json_fail(422, 'validation', 'Ghost URL ต้องขึ้นต้นด้วย https://');
            }
            if ($v !== '' || !$keys[$k]) {
                setting_set($k, mb_substr($v, 0, 300));
            }
        }
        foreach ((array) ($in['clear'] ?? []) as $k) {
            if (array_key_exists($k, $keys)) {
                setting_set($k, '');
            }
        }
        unset($GLOBALS['__settings']);
        $out = [];
        foreach ($keys as $k => $secret) {
            $out[$k] = $secret ? ext_cfg($k) !== '' : ext_cfg($k);
        }
        json_out(200, ['ok' => true, 'values' => $out]);

    case 'sessions_list':
        // เครื่องที่ล็อกอินอยู่ (ของตัวเอง; ผู้ดูแลสูงสุดดูของคนอื่นได้ด้วย admin_id)
        $me = require_admin();
        $aid = (int) ($in['admin_id'] ?? $me['id']);
        if ($aid !== (int) $me['id'] && !is_super($me)) {
            json_fail(403, 'forbidden', 'เฉพาะผู้ดูแลสูงสุด');
        }
        $cur = hash('sha256', (string) ($_COOKIE[AUTH_COOKIE] ?? ''));
        $st = $pdo->prepare('SELECT token_hash, created_at, last_seen, ua, ip FROM auth_tokens WHERE admin_id = ? AND expires_at > ? ORDER BY COALESCE(last_seen, created_at) DESC');
        $st->execute([$aid, time()]);
        json_out(200, ['ok' => true, 'sessions' => array_map(fn($r) => ['id' => substr($r['token_hash'], 0, 16), 'created_at' => $r['created_at'], 'last_seen' => $r['last_seen'],
            'ua' => (string) $r['ua'], 'ip' => $r['ip'], 'current' => hash_equals($r['token_hash'], $cur)], $st->fetchAll())]);

    case 'sessions_revoke':
        // ออกจากระบบเครื่องที่เลือก (id) หรือทุกเครื่องยกเว้นเครื่องนี้ (others) หรือทุกเครื่องของผู้ใช้อื่น (ผู้ดูแลสูงสุด)
        $me = require_admin();
        $aid = (int) ($in['admin_id'] ?? $me['id']);
        if ($aid !== (int) $me['id'] && !is_super($me)) {
            json_fail(403, 'forbidden', 'เฉพาะผู้ดูแลสูงสุด');
        }
        $cur = hash('sha256', (string) ($_COOKIE[AUTH_COOKIE] ?? ''));
        if (!empty($in['others'])) {
            $st = $pdo->prepare('DELETE FROM auth_tokens WHERE admin_id = ? AND token_hash <> ?');
            $st->execute([$aid, $cur]);
        } else {
            $id = preg_replace('/[^0-9a-f]/', '', (string) ($in['id'] ?? ''));
            if (strlen($id) !== 16) {
                json_fail(422, 'validation', 'ไม่พบเครื่องนี้');
            }
            $st = $pdo->prepare('DELETE FROM auth_tokens WHERE admin_id = ? AND substr(token_hash, 1, 16) = ?');
            $st->execute([$aid, $id]);
        }
        json_out(200, ['ok' => true, 'removed' => $st->rowCount()]);

    case 'me_name':   // ทุกคนแก้ชื่อตัวเองได้
        $me = require_admin();
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
        $pdo->prepare('UPDATE admins SET name = ? WHERE email = ?')->execute([$name ?: null, $me['email']]);
        json_out(200, ['ok' => true, 'name' => $name ?: null]);

    case 'settings':
        require_admin('admin');
        $keys = ['default_capacity', 'max_per_order', 'cancel_minutes', 'close_minutes', 'months_ahead', 'auto_generate', 'empty_close', 'empty_close_days', 'reminder_on', 'reminder_days', 'reminder_hour', 'email_verify', 'email_confirm', 'email_confirm_hours', 'sms_verify', 'bcc_email', 'notify_email', 'notify_events', 'slack_webhook', 'waitlist_on', 'waitlist_hours'];
        if (isset($in['values']) && is_array($in['values'])) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $in['values'])) {
                    $v = trim((string) $in['values'][$k]);
                    if (in_array($k, ['bcc_email', 'notify_email', 'notify_events', 'slack_webhook'], true)) {   // ข้อความ
                        if ($k === 'slack_webhook' && $v !== '' && !preg_match('#^https://hooks\.slack\.com/services/#', $v)) {
                            json_fail(422, 'validation', 'Slack Webhook URL ต้องขึ้นต้นด้วย https://hooks.slack.com/services/');
                        }
                        setting_set($k, mb_substr($v, 0, 500));
                        continue;
                    }
                    if ($v !== '' && !ctype_digit($v)) {
                        json_fail(422, 'validation', "ค่า $k ต้องเป็นตัวเลข");
                    }
                    setting_set($k, $v);
                }
            }
        }
        $out = [];
        foreach ($keys as $k) {
            $st = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
            $st->execute([$k]);
            $x = $st->fetchColumn();
            $out[$k] = $x === false ? null : $x;   // ยังไม่เคยตั้ง = null (ให้หน้าเว็บใช้ค่าเริ่มต้นได้)
        }
        json_out(200, ['ok' => true, 'values' => $out, 'mail' => own_mail_enabled() && (string) (cfg()['smtp_host'] ?? '') !== '', 'wallet' => wallet_enabled(), 'sms' => sms_configured()]);

    case 'texts':
        // ข้อความที่แก้ได้ (ตั้งค่า → ข้อความ): ดูค่าเดิม + ที่แก้ไว้ / บันทึกทีละคำ (value ว่าง = กลับเป็นข้อความเดิม)
        require_admin('admin');
        $flatNoshow = function (string $l): array {
            $T = noshow_texts_default($l);
            $o = [];
            foreach ($T as $k => $v) {
                if ($k === 'p') {
                    foreach ($v as $i => $x) {
                        $o['p' . ($i + 1)] = $x;
                    }
                } elseif (is_string($v) && $k !== 'sign') {
                    $o[$k] = $v;
                }
            }
            return $o;
        };
        $mailDefaults = fn(string $l) => array_filter(MAIL_T[$l], fn($v, $k) => is_string($v) && $k !== 'sign', ARRAY_FILTER_USE_BOTH);
        $defaults = ['mail' => ['th' => $mailDefaults('th'), 'en' => $mailDefaults('en')], 'noshow' => ['th' => $flatNoshow('th'), 'en' => $flatNoshow('en')]];
        if (($in['op'] ?? '') === 'save') {
            $scope = (string) ($in['scope'] ?? '');
            $lang = ($in['lang'] ?? '') === 'en' ? 'en' : 'th';
            $key = (string) ($in['key'] ?? '');
            if (!in_array($scope, TEXT_SCOPES, true) || !preg_match('/^[A-Za-z0-9_]{1,40}$/', $key)) {
                json_fail(422, 'validation', 'ไม่รู้จักข้อความนี้');
            }
            $value = trim((string) ($in['value'] ?? ''));
            if ($value !== '') {
                $def = $defaults[$scope][$lang][$key] ?? null;
                if (isset($defaults[$scope]) && $def === null) {
                    json_fail(422, 'validation', 'ไม่รู้จักข้อความนี้');
                }
                // ข้อความเดิมไม่มี HTML (แสดงแบบตัวอักษร) = ตัดแท็กทิ้งทั้งหมด
                $value = $def !== null && !str_contains($def, '<') ? trim(strip_tags($value)) : text_clean($value);
                if ($def !== null && text_placeholders($def) !== text_placeholders($value)) {
                    json_fail(422, 'validation', 'ต้องมีตัวแทนค่า ' . implode(' ', text_placeholders($def)) . ' เหมือนข้อความเดิม (ระบบจะใส่ค่าจริงแทนตรงนั้น)');
                }
                $value = mb_substr($value, 0, 3000);
            }
            $all = text_overrides();
            if ($value === '') {
                unset($all[$scope][$lang][$key]);
                if (empty($all[$scope][$lang])) {
                    unset($all[$scope][$lang]);
                }
                if (empty($all[$scope])) {
                    unset($all[$scope]);
                }
            } else {
                $all[$scope][$lang][$key] = $value;
            }
            setting_set('texts', json_encode($all, JSON_UNESCAPED_UNICODE));
            $GLOBALS['__texts_dirty'] = true;
            json_out(200, ['ok' => true, 'value' => $value]);
        }
        json_out(200, ['ok' => true, 'defaults' => $defaults, 'overrides' => text_overrides() ?: new stdClass()]);

    case 'pattern':
        // รูปแบบรอบประจำ: ดู / ดูผลก่อนบันทึก (preview) / บันทึก (save) — รอบเดิมไม่ถูกแตะ
        require_admin('admin');
        $months = (int) setting('months_ahead', '6');
        try {
            $p = isset($in['pattern']) ? session_pattern_clean((array) $in['pattern']) : session_pattern();
        } catch (InvalidArgumentException $e) {
            json_fail(422, 'validation', $e->getMessage());
        }
        $want = array_column(pattern_slots($months, $p), 0);
        // รอบในอนาคตที่เปิดอยู่แต่ไม่ตรงรูปแบบนี้ (จะไม่ถูกลบเอง)
        $st = $pdo->prepare('SELECT id, starts_at FROM sessions WHERE active = 1 AND starts_at > ? ORDER BY starts_at');
        $st->execute([now_iso()]);
        $future = $st->fetchAll();
        $counts = session_counts(array_map(fn($r) => (int) $r['id'], $future));
        $off = [];
        foreach ($future as $r) {
            if (!in_array($r['starts_at'], $want, true)) {
                $off[] = ['id' => (int) $r['id'], 'starts_at' => $r['starts_at'], 'booked' => $counts[(int) $r['id']]['booked'] ?? 0];
            }
        }
        if (($in['op'] ?? '') === 'save') {
            setting_set('session_pattern', json_encode($p));
            $made = generate_sessions($months, $p);
            json_out(200, ['ok' => true, 'pattern' => $p, 'created' => $made]);
        }
        json_out(200, ['ok' => true, 'pattern' => $p, 'is_default' => setting('session_pattern') === null || setting('session_pattern') === '',
            'months' => $months, 'would_create' => generate_sessions($months, $p, true), 'next' => array_slice($want, 0, 6), 'off' => $off]);

    case 'venue':
        // ข้อมูลสถานที่ (ชื่อ ที่อยู่ แผนที่ ติดต่อ เว็บไซต์ นโยบายความเป็นส่วนตัว โลโก้) — ใช้ทั้งหน้าเว็บ อีเมล ตั๋ว Wallet
        require_once __DIR__ . '/../lib/venue.php';
        require_admin('admin');
        if (isset($in['values']) && is_array($in['values'])) {
            foreach (VENUE_KEYS as $k) {
                if (!array_key_exists($k, $in['values'])) {
                    continue;
                }
                $v = trim(preg_replace('/[\r\n\t]+/', ' ', (string) $in['values'][$k]));
                if ($v !== '' && in_array($k, ['maps', 'website', 'privacy'], true) && !preg_match('#^https://[^\s"<>]+$#', $v)) {
                    json_fail(422, 'validation', 'ลิงก์ต้องขึ้นต้นด้วย https://');
                }
                if ($v !== '' && in_array($k, ['email', 'group_email'], true) && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    json_fail(422, 'validation', 'อีเมลไม่ถูกต้อง');
                }
                setting_set('venue_' . $k, mb_substr($v, 0, 300));   // ว่าง = กลับไปใช้ค่าเริ่มต้น
            }
        }
        if (array_key_exists('logo', $in)) {
            $logo = (string) $in['logo'];
            if ($logo === '') {
                setting_set('venue_logo', '');
            } else {
                $bin = base64_decode($logo, true);
                $info = $bin !== false ? @getimagesizefromstring($bin) : false;
                if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) || strlen($bin) > 600 * 1024) {
                    json_fail(422, 'validation', 'โลโก้ต้องเป็นไฟล์ PNG หรือ JPG ไม่เกิน 600 KB');
                }
                setting_set('venue_logo', base64_encode($bin));
            }
        }
        $GLOBALS['__venue_dirty'] = true;
        $raw = [];
        foreach (VENUE_KEYS as $k) {
            $raw[$k] = (string) setting('venue_' . $k, '');
        }
        json_out(200, ['ok' => true, 'values' => $raw, 'defaults' => VENUE_DEFAULTS, 'logo' => '../' . venue_logo_path(), 'custom_logo' => (bool) setting('venue_logo')]);

    case 'notify_test':
        require_admin('admin');
        $r = notify_admin('test', ['note' => 'ส่งจากหลังบ้านโดย ' . current_admin()['email'], 'admin_path' => 'admin/#/settings'], true);
        json_out(200, ['ok' => true] + $r);

    case 'test_mode':
        require_admin('admin');
        if (array_key_exists('on', $in)) {
            setting_set('test_mode', !empty($in['on']) ? '1' : '0');
        }
        $n = (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE is_test = 1')->fetchColumn();
        json_out(200, ['ok' => true, 'on' => test_mode(), 'test_orders' => $n,
            'outbox' => (int) $pdo->query('SELECT COUNT(*) FROM outbox')->fetchColumn()]);

    case 'test_purge':
        require_admin('admin');
        $ids = $pdo->query('SELECT id FROM orders WHERE is_test = 1')->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $in_ = implode(',', array_map('intval', $ids));
            $pdo->exec("DELETE FROM checkin_log WHERE ticket_id IN (SELECT id FROM tickets WHERE order_id IN ($in_))");
            $pdo->exec("DELETE FROM tickets WHERE order_id IN ($in_)");
            $pdo->exec("DELETE FROM orders WHERE id IN ($in_)");
            $pdo->exec("DELETE FROM event_log WHERE order_id IN ($in_) OR is_test = 1");   // ประวัติของการจองทดสอบหายไปด้วย
            $pdo->exec("DELETE FROM mail_log WHERE order_code NOT IN (SELECT code FROM orders)");
        }
        if (!empty($in['outbox'])) {
            $pdo->exec('DELETE FROM outbox');
        }
        json_out(200, ['ok' => true, 'deleted' => count($ids)]);

    case 'outbox':
        require_admin('admin');
        json_out(200, ['ok' => true, 'mails' => $pdo->query('SELECT id, created_at, to_addr, subject, order_code, sent_at, sent_to FROM outbox ORDER BY id DESC LIMIT 100')->fetchAll()]);

    case 'outbox_view':
        require_admin('admin');
        $st = $pdo->prepare('SELECT * FROM outbox WHERE id = ?');
        $st->execute([(int) ($in['id'] ?? 0)]);
        $m = $st->fetch() ?: json_fail(404, 'not_found', 'ไม่พบอีเมล');
        json_out(200, ['ok' => true, 'subject' => $m['subject'], 'to' => $m['to_addr'],
            'html' => mail_preview_html($m['html'], array_map('base64_decode', json_decode($m['images'], true) ?: []))]);

    case 'outbox_send':
        require_admin('admin');
        $to = trim((string) ($in['to'] ?? ''));
        if ($to !== '' && !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            json_fail(422, 'validation', 'อีเมลไม่ถูกต้อง');
        }
        outbox_send((int) ($in['id'] ?? 0), $to ?: null) ? json_out(200, ['ok' => true]) : json_fail(502, 'mail', 'ส่งไม่สำเร็จ (ตรวจการตั้งค่า SMTP)');

    case 'mail_preview':
        // ตัวอย่างอีเมลจากข้อมูลสมมติ — ไม่ส่ง
        require_admin('admin');
        $lang = ($in['lang'] ?? 'th') === 'en' ? 'en' : 'th';
        $slot = demo_slot();
        $tk = demo_tickets($lang);
        $manage = public_url('manage.php?o=PREVW&s=preview');
        [$subj, $html, $img] = match ($in['type'] ?? 'booked') {
            'ticket' => booking_email($lang, 'PREVW', $slot, [$tk[1]], $manage, 2, true),
            'cancel' => cancel_email($lang, 'PREVW', $slot, 2),
            'reminder' => booking_email($lang, 'PREVW', $slot, $tk, $manage, 2, false, true),
            'att_cancel' => attendee_cancel_email($lang, 'PREVW', 'Jane Doe', $slot, 1, $manage),
            'noshow' => noshow_email($lang, $slot, 2, 'PREVW'),
            'resend' => resend_email($lang, [['code' => 'PREVW', 'slot' => $slot, 'qty' => 2, 'url' => $manage]]),
            'confirm' => confirm_request_email($lang, 'PREVW', $slot, 2, array_column($tk, 'name'), $manage . '&confirm=1', confirm_deadline(new DateTimeImmutable('now', tz()), $slot['starts_at'])),
            'expired' => expired_email($lang, 'PREVW', $slot, 2),
            'code' => email_code_preview($lang),
            'wl_joined' => waitlist_email('joined', ['given' => $lang === 'th' ? 'สมชาย' : 'Somchai', 'family' => $lang === 'th' ? 'ใจดี' : 'Jaidee', 'qty' => 2, 'lang' => $lang], $slot),
            'wl_invited' => waitlist_email('invited', ['given' => $lang === 'th' ? 'สมชาย' : 'Somchai', 'family' => $lang === 'th' ? 'ใจดี' : 'Jaidee', 'qty' => 2, 'lang' => $lang,
                'token' => 'preview', 'expires_at' => (new DateTimeImmutable('+48 hours', tz()))->format(DATE_ATOM)], $slot),
            default  => booking_email($lang, 'PREVW', $slot, $tk, $manage, 2),
        };
        // ตัวอย่างไม่แนบ PDF (รหัส PREVW) — สร้างตั๋วตัวอย่างให้ดูว่าอีเมลจริงจะแนบไฟล์อะไร
        if (in_array($in['type'] ?? 'booked', ['booked', 'ticket', 'reminder'], true)) {
            require_once __DIR__ . '/../lib/ticketpdf.php';
            $one = ($in['type'] ?? '') === 'ticket';
            $img[venue_slug() . '-ticket-PREVW' . ($one ? '-2' : '') . '.pdf'] = ticket_pdf($lang, 'PREVW', $slot, $one ? [$tk[1]] : $tk);
        }
        json_out(200, ['ok' => true, 'subject' => $subj, 'html' => mail_preview_html($html, array_filter($img, fn($k) => !str_contains((string) $k, '.'), ARRAY_FILTER_USE_KEY)), 'files' => mail_preview_files($img)]);

    case 'order_create':
        // พนักงานเพิ่มการจองเอง (โทรมาจอง / walk-in หน้างาน) — ไม่ต้องยืนยันอีเมล; เกินความจุต้องยืนยัน (force)
        $me = require_admin('staff');
        $sid = (int) ($in['session'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM sessions WHERE id = ?');
        $st->execute([$sid]);
        $sess = $st->fetch() ?: json_fail(404, 'not_found', 'ไม่พบรอบนี้');
        if (session_locked($sess['starts_at'])) {
            json_fail(409, 'locked', 'รอบนี้ผ่านไปแล้ว');
        }
        $clean = fn($v) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v)), 0, 100);
        $cGiven = $clean($in['given'] ?? '');
        $cFamily = $clean($in['family'] ?? '');
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $phoneRaw = trim((string) ($in['phone'] ?? ''));
        $phone = $phoneRaw !== '' ? normalize_phone($phoneRaw) : null;
        $qty = (int) ($in['qty'] ?? 1);
        $lang = ($in['lang'] ?? 'th') === 'en' ? 'en' : 'th';
        if ($cGiven === '') {
            json_fail(422, 'validation', 'กรุณากรอกชื่อผู้ติดต่อ');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_fail(422, 'validation', 'อีเมลไม่ถูกต้อง');
        }
        if ($phoneRaw !== '' && $phone === null) {
            json_fail(422, 'validation', 'เบอร์โทรไม่ถูกต้อง');
        }
        if ($qty < 1 || $qty > 50) {
            json_fail(422, 'validation', 'จำนวนคนต้องอยู่ระหว่าง 1–50');
        }
        $names = array_values((array) ($in['names'] ?? []));
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $cap = session_capacity($sess);
            $booked = session_counts([$sid])[$sid]['booked'] ?? 0;
            if ($cap !== null && $booked + $qty > $cap && empty($in['force'])) {
                $pdo->exec('ROLLBACK');
                json_fail(409, 'over_capacity', "รอบนี้จะเกินความจุ (จองแล้ว $booked จาก $cap คน)", ['booked' => $booked, 'capacity' => $cap]);
            }
            $code = new_order_code();
            $secret = random_secret(24);
            $isTest = test_mode();
            $pdo->prepare("INSERT INTO orders (code, secret, status, email, phone, given, family, lang, source, created_at, is_test, confirmed_at, comment)
                           VALUES (?, ?, 'p', ?, ?, ?, ?, ?, 'admin', ?, ?, ?, ?)")
                ->execute([$code, $secret, $email, $phone, $cGiven, $cFamily, $lang, now_iso(), $isTest ? 1 : 0, now_iso(),
                           'เพิ่มโดย ' . $me['email'] . (!empty($in['walkin']) ? ' (walk-in)' : ' (โทร/ติดต่อ)')]);
            $orderId = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO tickets (order_id, session_id, n, given, family, phone, email, secret, msecret, checked_in_at, checked_in_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $checkin = !empty($in['checkin']) && (new DateTimeImmutable($sess['starts_at']))->setTimezone(tz())->format('Y-m-d') === (new DateTimeImmutable('now', tz()))->format('Y-m-d');
            for ($i = 0; $i < $qty; $i++) {
                $nm = $i === 0 ? [$cGiven, $cFamily] : [$clean($names[$i]['given'] ?? ''), $clean($names[$i]['family'] ?? '')];
                if ($nm[0] === '') {
                    $nm = [$cGiven, trim($cFamily . ' (' . ($i + 1) . ')')];   // ไม่ทราบชื่อ — ใช้ชื่อผู้ติดต่อ + ลำดับ
                }
                $ins->execute([$orderId, $sid, $i + 1, $nm[0], $nm[1], $i === 0 ? $phone : null, $i === 0 && $email !== '' ? $email : null, random_secret(28), random_secret(24),
                               $checkin ? now_iso() : null, $checkin ? $me['email'] . ' (walk-in)' : null]);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
        $o = find_order($code);
        log_event('booked', $o, $sid, $qty . ' คน · พนักงานเพิ่ม (' . $me['email'] . ')' . ($checkin ? ' · เช็คอินแล้ว' : ''), 'admin:' . $me['email']);
        if ($email !== '' && !empty($in['send_email'])) {
            register_shutdown_function(function () use ($o) {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                send_booking_emails($o);
            });
        }
        json_out(200, ['ok' => true, 'code' => $code]);

    case 'stats_detail':
        // สถิติละเอียดของปี (ไม่รวมการจองทดสอบ): ช่องทางที่มา, อัตราไม่มารายเดือน, จองล่วงหน้ากี่วัน, ขนาดกลุ่ม, ภาษา, หน่วยงาน
        require_admin('admin');
        $year = preg_match('/^\d{4}$/', (string) ($in['year'] ?? '')) ? (string) $in['year'] : (new DateTimeImmutable('now', tz()))->format('Y');
        $st = $pdo->prepare("SELECT o.id, o.source, o.ref, o.lang, o.created_at, o.org, MIN(s.starts_at) AS starts_at, COUNT(t.id) AS qty,
                                    SUM(t.checked_in_at IS NOT NULL) AS came
                             FROM orders o JOIN tickets t ON t.order_id = o.id AND t.canceled = 0 JOIN sessions s ON s.id = t.session_id
                             WHERE o.is_test = 0 AND o.status = 'p' AND substr(s.starts_at, 1, 4) = ? GROUP BY o.id");
        $st->execute([$year]);
        $now = now_iso();
        $chan = function (array $o): string {
            if ($o['source'] === 'admin') {
                return 'staff';
            }
            if ($o['source'] === 'pretix') {
                return 'pretix';
            }
            $r = $o['ref'] ? (json_decode($o['ref'], true) ?: []) : [];
            $s = mb_strtolower(trim((string) ($r['src'] ?? '')));
            $h = mb_strtolower((string) ($r['host'] ?? ''));
            foreach (['line' => 'line', 'facebook' => 'facebook', 'fb' => 'facebook', 'instagram' => 'instagram', 'ig' => 'instagram', 'google' => 'google', 'email' => 'email', 'newsletter' => 'email', 'website' => 'website', 'web' => 'website', 'qr' => 'qr'] as $k => $v) {
                if ($s !== '' && str_contains($s, $k)) {
                    return $v;
                }
            }
            if ($s !== '') {
                return 'other';
            }
            if (in_array($r['app'] ?? '', ['line', 'facebook', 'instagram', 'google'], true)) {   // เปิดจากแอป (ตรวจจาก User-Agent / fbclid)
                return $r['app'];
            }
            foreach (['line.me' => 'line', 'facebook.' => 'facebook', 'fb.' => 'facebook', 'instagram.' => 'instagram', 'google.' => 'google', preg_replace('/^www\./', '', (string) parse_url(venue()['website'], PHP_URL_HOST)) => 'website'] as $k => $v) {
                if ($h !== '' && str_contains($h, $k)) {
                    return $v;
                }
            }
            return $h !== '' ? 'other' : 'direct';
        };
        $out = ['channels' => [], 'months' => [], 'lead' => ['0–1' => 0, '2–3' => 0, '4–7' => 0, '8–14' => 0, '15–30' => 0, '31+' => 0],
                'size' => ['1' => 0, '2' => 0, '3–5' => 0, '6–10' => 0, '11+' => 0], 'lang' => ['th' => 0, 'en' => 0], 'orders' => 0, 'people' => 0, 'org' => 0];
        foreach ($st->fetchAll() as $o) {
            $q = (int) $o['qty'];
            $out['orders']++;
            $out['people'] += $q;
            $c = $chan($o);
            $out['channels'][$c] = ($out['channels'][$c] ?? 0) + $q;
            $out['lang'][$o['lang'] === 'th' ? 'th' : 'en'] += $q;
            $out['org'] += $o['org'] ? 1 : 0;
            $out['size'][$q === 1 ? '1' : ($q === 2 ? '2' : ($q <= 5 ? '3–5' : ($q <= 10 ? '6–10' : '11+')))]++;
            $d = (int) floor((strtotime($o['starts_at']) - strtotime($o['created_at'])) / 86400);
            if ($o['source'] !== 'pretix' || $d >= 0) {
                $out['lead'][$d <= 1 ? '0–1' : ($d <= 3 ? '2–3' : ($d <= 7 ? '4–7' : ($d <= 14 ? '8–14' : ($d <= 30 ? '15–30' : '31+'))))]++;
            }
            if ($o['starts_at'] < $now) {   // รอบที่ผ่านไปแล้ว: มา / ไม่มา รายเดือน
                $m = substr($o['starts_at'], 0, 7);
                $out['months'][$m] ??= ['booked' => 0, 'came' => 0];
                $out['months'][$m]['booked'] += $q;
                $out['months'][$m]['came'] += (int) $o['came'];
            }
        }
        arsort($out['channels']);
        ksort($out['months']);
        $cx = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE o.is_test = 0 AND o.status = 'c' AND substr(o.created_at, 1, 4) = ?");
        $cx->execute([$year]);
        $out['canceled_orders'] = (int) $cx->fetchColumn();
        json_out(200, ['ok' => true, 'year' => $year] + $out);

    case 'stats':
        require_admin('staff');
        $row = $pdo->query("SELECT COUNT(*) tickets, SUM(t.checked_in_at IS NOT NULL) checked FROM tickets t JOIN orders o ON o.id = t.order_id WHERE t.canceled = 0 AND o.is_test = 0")->fetch();
        $year = (new DateTimeImmutable('now', tz()))->format('Y');
        $y = $pdo->prepare("SELECT COUNT(*) FROM tickets t JOIN sessions s ON s.id = t.session_id JOIN orders o ON o.id = t.order_id WHERE t.canceled = 0 AND o.is_test = 0 AND substr(s.starts_at, 1, 4) = ?");
        $y->execute([$year]);
        $yt = (int) $y->fetchColumn();
        $q = $pdo->prepare("SELECT SUM(t.canceled = 1), SUM(t.canceled = 0 AND t.checked_in_at IS NOT NULL), SUM(t.canceled = 0 AND t.checked_in_at IS NULL AND s.starts_at < ?)
                            FROM tickets t JOIN sessions s ON s.id = t.session_id JOIN orders o ON o.id = t.order_id WHERE o.is_test = 0 AND substr(s.starts_at, 1, 4) = ?");
        $q->execute([(new DateTimeImmutable('today', tz()))->format(DATE_ATOM), $year]);
        [$yc, $ya, $yn] = array_map('intval', $q->fetch(PDO::FETCH_NUM));
        json_out(200, ['ok' => true, 'tickets' => (int) $row['tickets'], 'checked' => (int) $row['checked'], 'year' => $year, 'year_tickets' => $yt,
            'year_canceled' => $yc, 'year_checked' => $ya, 'year_noshow' => $yn]);

    case 'people':
        // รายชื่อผู้เข้าชมตามประเภท (กดจากตัวเลขสรุป): booked | canceled | checked | noshow ของปีที่เลือก
        require_admin('staff');
        $kind = (string) ($in['kind'] ?? 'booked');
        $year = preg_match('/^\d{4}$/', (string) ($in['year'] ?? '')) ? (string) $in['year'] : (new DateTimeImmutable('now', tz()))->format('Y');
        $cond = match ($kind) {
            'canceled' => 't.canceled = 1',
            'checked'  => 't.canceled = 0 AND t.checked_in_at IS NOT NULL',
            'noshow'   => 't.canceled = 0 AND t.checked_in_at IS NULL AND s.starts_at < :today',
            default    => 't.canceled = 0',
        };
        $st = $pdo->prepare("SELECT t.*, o.code, o.status AS order_status, o.canceled_by, o.canceled_at, o.given AS c_given, o.family AS c_family, o.email AS c_email, o.phone AS c_phone,
                                    s.starts_at, s.ends_at FROM tickets t JOIN orders o ON o.id = t.order_id JOIN sessions s ON s.id = t.session_id
                             WHERE o.is_test = 0 AND substr(s.starts_at, 1, 4) = :y AND $cond ORDER BY s.starts_at DESC, o.code, t.n");
        $params = [':y' => $year];
        if ($kind === 'noshow') {
            $params[':today'] = (new DateTimeImmutable('today', tz()))->format(DATE_ATOM);
        }
        $st->execute($params);
        json_out(200, ['ok' => true, 'kind' => $kind, 'year' => $year, 'people' => array_map(fn($t) => ticket_row($t) + [
            'slot' => session_slot($t), 'contact' => trim($t['c_given'] . ' ' . $t['c_family']), 'contact_email' => $t['c_email'], 'contact_phone' => $t['c_phone'],
            'canceled_by' => $t['order_status'] === 'c' ? $t['canceled_by'] : ($t['canceled'] ? 'attendee' : null),
            'hist' => ticket_history($t['email'], $t['phone'], (int) $t['order_id'], $t['given'], $t['family']),
        ], $st->fetchAll())]);

    case 'person':
        // ประวัติทั้งหมดของคนหนึ่ง (อีเมลหรือเบอร์)
        require_admin('staff');
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $phone = trim((string) ($in['phone'] ?? ''));
        $given = trim((string) ($in['given'] ?? ''));
        $family = trim((string) ($in['family'] ?? ''));
        $wp = history_where($email, $phone, $given, $family) ?: json_fail(422, 'validation', 'ต้องมีอีเมล เบอร์ หรือชื่อ-นามสกุล');
        [$where, $params] = $wp;
        $st = $pdo->prepare("SELECT t.*, o.code, o.status AS order_status, o.canceled_by, o.given AS c_given, o.family AS c_family, o.email AS c_email, o.phone AS c_phone, s.starts_at, s.ends_at
                             FROM tickets t JOIN orders o ON o.id = t.order_id JOIN sessions s ON s.id = t.session_id WHERE o.is_test = 0 AND $where ORDER BY s.starts_at DESC");
        $st->execute($params);
        json_out(200, ['ok' => true, 'summary' => ticket_history($email, $phone, 0, $given, $family), 'people' => array_map(fn($t) => ticket_row($t) + [
            'slot' => session_slot($t), 'contact' => trim($t['c_given'] . ' ' . $t['c_family']), 'contact_email' => $t['c_email'], 'contact_phone' => $t['c_phone'],
            'canceled_by' => $t['order_status'] === 'c' ? $t['canceled_by'] : ($t['canceled'] ? 'attendee' : null),
        ], $st->fetchAll())]);
}

json_fail(400, 'action', 'Unknown action');
