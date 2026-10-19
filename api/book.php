<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/booking.php';
require_once __DIR__ . '/../lib/sms.php';
require_once __DIR__ . '/../lib/notify.php';
require_once __DIR__ . '/../lib/waitlist.php';
require_once __DIR__ . '/../lib/history.php';

$c = cfg();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail(405, 'method', 'Method not allowed');
}

$raw = file_get_contents('php://input');
$in  = json_decode((string) $raw, true);
if (!is_array($in)) {
    json_fail(400, 'bad_json', 'ข้อมูลไม่ถูกต้อง');
}

// --- honeypot: บอทมักกรอกทุกช่อง ---
if (!empty($in['website'])) {
    json_out(201, ['ok' => true, 'code' => 'OK', 'url' => '']);   // หลอกว่าผ่าน
}

// --- ตรวจฟอร์ม ---
$trim = fn($v) => is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : '';

$subevent = (int) ($in['subevent'] ?? 0);
$qty      = (int) ($in['qty'] ?? 0);
$cGiven   = mb_substr($trim($in['given'] ?? ''), 0, 100);
$cFamily  = mb_substr($trim($in['family'] ?? ''), 0, 100);
$cOrg     = mb_substr($trim($in['org'] ?? ''), 0, 190);
$email    = mb_strtolower($trim($in['email'] ?? ''));
$phone    = $trim($in['phone'] ?? '');
$lang     = ($in['lang'] ?? 'th') === 'en' ? 'en' : 'th';
$attendees = is_array($in['attendees'] ?? null) ? $in['attendees'] : [];

$errors = [];
if ($subevent <= 0) {
    $errors['subevent'] = 'กรุณาเลือกรอบเข้าชม';
}
$maxPer = (int) setting('max_per_order', '10');
if ($qty < 1 || $qty > $maxPer) {
    $errors['qty'] = 'จำนวนผู้เข้าชมต้องอยู่ระหว่าง 1 ถึง ' . $maxPer;
}
if ($cGiven === '' || $cFamily === '') {
    $errors['cgiven'] = 'กรุณากรอกชื่อและนามสกุลของผู้ติดต่อ';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'กรุณากรอกอีเมลให้ถูกต้อง';
}
$normPhone = 'normalize_phone';   // lib/sms.php: แปลงเบอร์ไทยท้องถิ่นเป็น +66 — null ถ้าไม่ถูกต้อง
$p = $normPhone($phone);
if ($p === null) {
    $errors['phone'] = 'กรุณากรอกเบอร์โทรในรูปแบบ 08x-xxx-xxxx หรือ +66...';
} else {
    $phone = $p;
}
if (count($attendees) !== $qty) {
    $errors['attendees'] = 'กรุณากรอกชื่อผู้เข้าชมให้ครบทุกคน';
}
$cleanAttendees = [];
$seenEmails = [];
$seenPhones = [];
// Pretix ถามต่อผู้เข้าชม: ชื่อ (บังคับ), เบอร์ (บังคับ), อีเมล (ไม่บังคับ), หน่วยงาน (ไม่บังคับ)
foreach (array_values($attendees) as $i => $a) {
    $a = is_array($a) ? $a : [];
    $given  = mb_substr($trim($a['given'] ?? ''), 0, 100);
    $family = mb_substr($trim($a['family'] ?? ''), 0, 100);
    if ($given === '' || $family === '') {
        $errors['attendees'] = 'กรุณากรอกชื่อและนามสกุลของผู้เข้าชมทุกคน';
        break;
    }
    // เบอร์ผู้เข้าชม: ไม่บังคับ (เด็ก/คนไม่มีมือถือ — ผู้ติดต่อมีเบอร์เสมอ) แต่ถ้ากรอกต้องถูกต้องและไม่ซ้ำกัน (ผู้ใช้กำหนด)
    $aPhoneRaw = $trim($a['phone'] ?? '');
    $aPhone = $aPhoneRaw === '' ? '' : $normPhone($aPhoneRaw);
    if ($aPhone === null) {
        $errors['attendees'] = 'กรุณากรอกเบอร์โทรของผู้เข้าชมคนที่ ' . ($i + 1) . ' ให้ถูกต้อง';
        break;
    }
    if ($aPhone !== '' && isset($seenPhones[$aPhone])) {
        $errors['attendees'] = 'เบอร์โทรของผู้เข้าชมแต่ละคนต้องไม่ซ้ำกัน — ถ้าไม่มีเบอร์ของตัวเอง เว้นว่างไว้ได้';
        break;
    }
    $seenPhones[$aPhone] = true;
    $aEmail = mb_strtolower($trim($a['email'] ?? ''));
    if ($aEmail !== '' && !filter_var($aEmail, FILTER_VALIDATE_EMAIL)) {
        $errors['attendees'] = 'อีเมลของผู้เข้าชมคนที่ ' . ($i + 1) . ' ไม่ถูกต้อง';
        break;
    }
    if ($aEmail !== '' && isset($seenEmails[$aEmail])) {
        $errors['attendees'] = 'อีเมลของผู้เข้าชมแต่ละคนต้องไม่ซ้ำกัน — ถ้าไม่มีอีเมลของตัวเอง เว้นว่างไว้ได้';
        break;
    }
    $seenEmails[$aEmail] = true;
    $cleanAttendees[] = [
        'given' => $given, 'family' => $family, 'phone' => $aPhone,
        'email' => $aEmail, 'org' => mb_substr($trim($a['org'] ?? ''), 0, 190),
        // รับข่าวสาร: ติ๊กได้เมื่อกรอกอีเมลของตัวเอง (ผู้ติดต่อมีช่องของตัวเอง)
        'news' => $aEmail !== '' && !empty($a['news']),
    ];
}
if ($errors) {
    json_fail(422, 'validation', 'กรุณาตรวจสอบข้อมูลที่กรอก', ['fields' => $errors]);
}

// --- กดจองซ้ำ (เน็ตหลุดหลังระบบบันทึกแล้ว / กดสองครั้ง): คีย์เดียวกันจากฟอร์มเดิม = คืนการจองเดิม ไม่สร้างใหม่ ---
$idem = preg_match('/^[A-Za-z0-9_-]{16,64}$/', (string) ($in['idem'] ?? '')) ? (string) $in['idem'] : null;
$bookedResponse = function (array $o) use ($lang): never {
    $ts = array_values(array_filter(order_tickets((int) $o['id']), fn($t) => !$t['canceled']));
    $sl = session_slot($ts[0] ?? ['starts_at' => now_iso(), 'ends_at' => null]);
    $m = 'manage.php?o=' . rawurlencode($o['code']) . '&s=' . rawurlencode($o['secret']);
    json_out(201, ['ok' => true, 'code' => $o['code'], 'url' => $m, 'email' => $o['email'], 'qty' => count($ts), 'pending' => $o['status'] === 'w', 'confirm_by' => $o['confirm_by'],
        'slot' => ['date' => $sl['date'], 'start' => $sl['start'], 'end' => $sl['end']], 'again' => true,
        'ics' => 'api/ics.php?o=' . rawurlencode($o['code']) . '&s=' . rawurlencode($o['secret']),
        'gcal' => google_calendar_url($o['code'], $sl['starts_at'], $sl['ends_at'], count($ts), public_url($m), $lang)]);
};
if ($idem !== null) {
    $st = db()->prepare('SELECT * FROM orders WHERE idem = ?');
    $st->execute([$idem]);
    if ($prev = $st->fetch()) {
        $bookedResponse($prev);
    }
}
// มาจากช่องทางไหน (utm_* / เว็บที่ลิงก์มา) — หน้าสถิติหลังบ้าน
$ref = array_filter(array_map(fn($v) => is_string($v) ? mb_substr(trim($v), 0, 120) : '', array_intersect_key((array) ($in['ref'] ?? []), array_flip(['src', 'med', 'camp', 'host', 'app']))));
$refJson = $ref ? json_encode($ref, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

// --- ยืนยันอีเมลผู้ติดต่อด้วยรหัสในฟอร์ม (ค่าเริ่มต้น) — จองสำเร็จ = ยืนยันแล้ว ไม่มีสถานะค้าง ---
$emailVerified = false;
if (email_verify_on()) {
    if (!vc_token_valid('mail:' . $email, (string) ($in['email_token'] ?? ''))) {
        json_fail(422, 'email_unverified', 'กรุณายืนยันอีเมลด้วยรหัสที่ส่งไปทางอีเมล', ['fields' => ['email' => 'กรุณายืนยันอีเมลด้วยรหัสที่ส่งไปทางอีเมล']]);
    }
    $emailVerified = true;
}

// --- ยืนยันเบอร์มือถือไทยของผู้ติดต่อด้วย SMS (ถ้าเปิด) ---
$phoneVerified = false;
if (sms_verify_on() && thai_mobile($phone)) {
    if (!vc_token_valid($phone, (string) ($in['phone_token'] ?? ''))) {
        json_fail(422, 'phone_unverified', 'กรุณายืนยันเบอร์โทรด้วยรหัสทาง SMS', ['fields' => ['phone' => 'กรุณายืนยันเบอร์โทรด้วยรหัสทาง SMS']]);
    }
    $phoneVerified = true;
}

// --- Turnstile (ถ้าเปิด) ---
if (!turnstile_ok(is_string($in['turnstile'] ?? null) ? $in['turnstile'] : '')) {
    json_fail(403, 'bot_check', 'กรุณายืนยันว่าไม่ใช่บอท แล้วลองใหม่');
}

// --- rate limit นับเฉพาะคำขอที่ผ่านการตรวจฟอร์มแล้ว ---
if (rate_limited('book')) {
    json_fail(429, 'rate_limited', 'คุณส่งคำขอบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่');
}

// ความยินยอมรับข่าวสาร (หลักฐาน PDPA เก็บใน orders.news / tickets.news + comment)
$optIns = [];
if (!empty($in['news'])) {
    $optIns[$email] = trim("$cGiven $cFamily");
}
foreach ($cleanAttendees as $a) {
    if ($a['news'] && !isset($optIns[$a['email']])) {
        $optIns[$a['email']] = trim($a['given'] . ' ' . $a['family']);
    }
}
$comment = $optIns ? 'Newsletter opt-in (' . now_iso() . ', ' . $lang . '): ' . implode(', ', array_keys($optIns)) : null;

// --- สร้างออเดอร์ในฐานข้อมูลของเรา (BEGIN IMMEDIATE = ล็อกกันจองเกินที่ว่างพร้อมกัน) ---
$pdo = db();
$pdo->exec('BEGIN IMMEDIATE');
try {
    $slot = null;
    foreach (bookable_slots() as $sl) {
        if ($sl['id'] === $subevent) {
            $slot = $sl;
            break;
        }
    }
    // สิทธิ์จองจาก waiting list (แอดมินให้): จองได้แม้รอบเต็ม/ปิด ไม่เกินจำนวนที่ลงชื่อไว้
    if (($in['wl_token'] ?? '') === 'preview') {
        json_fail(409, 'demo', 'นี่คือลิงก์ตัวอย่าง จองจริงไม่ได้');
    }
    $wl = waitlist_by_token((string) ($in['wl_token'] ?? ''));
    if ($wl && ((int) $wl['session_id'] !== $subevent || $qty > (int) $wl['qty'])) {
        $wl = null;
    }
    if (!$slot && $wl) {
        $st = $pdo->prepare('SELECT * FROM sessions WHERE id = ?');
        $st->execute([$subevent]);
        $slot = ($r = $st->fetch()) ? session_slot($r) + ['id' => (int) $r['id'], 'closed' => false, 'available' => true, 'left' => null] : null;
    }
    if (!$wl && (!$slot || $slot['closed'])) {
        $pdo->exec('ROLLBACK');
        json_fail(409, 'slot_closed', 'รอบนี้ปิดรับจองแล้ว กรุณาเลือกรอบอื่น');
    }
    if (!$wl && (!$slot['available'] || ($slot['left'] !== null && $slot['left'] < $qty))) {
        $pdo->exec('ROLLBACK');
        // ไม่บอกจำนวนที่ว่าง (ผู้ใช้กำหนด)
        json_fail(409, $slot['left'] ? 'not_enough' : 'slot_full', $slot['left'] ? 'ที่ว่างในรอบนี้ไม่พอสำหรับจำนวนผู้เข้าชมนี้ กรุณาลดจำนวนหรือเลือกรอบอื่น' : 'รอบนี้เต็มแล้ว กรุณาเลือกรอบอื่น');
    }
    $code = new_order_code();
    $secret = random_secret(24);
    $isTest = test_mode();
    // (ทางเลือกเดิม ปิดเป็นค่าเริ่มต้น) ยืนยันด้วยลิงก์ในอีเมลหลังจอง: สถานะ 'w' กันที่ไว้จนถึง confirm_by
    $pending = !$emailVerified && email_confirm_on();
    $confirmBy = $pending ? confirm_deadline(new DateTimeImmutable('now', tz()), $slot['starts_at']) : null;
    if ($idem !== null) {   // คำขอซ้ำที่มาพร้อมกัน — รอบนี้ล็อกอยู่ ตรวจอีกครั้งในธุรกรรม
        $st = $pdo->prepare('SELECT * FROM orders WHERE idem = ?');
        $st->execute([$idem]);
        if ($prev = $st->fetch()) {
            $pdo->exec('ROLLBACK');
            $bookedResponse($prev);
        }
    }
    $pdo->prepare('INSERT INTO orders (code, secret, status, email, phone, given, family, org, lang, news, comment, source, created_at, is_test, confirm_by, confirmed_at, phone_verified_at, idem, ref)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$code, $secret, $pending ? 'w' : 'p', $email, $phone, $cGiven, $cFamily, $cOrg ?: null, $lang, !empty($in['news']) ? 1 : 0, $comment, 'web', now_iso(), $isTest ? 1 : 0,
                   $confirmBy, $pending ? null : now_iso(), $phoneVerified ? now_iso() : null, $idem, $refJson]);
    $orderId = (int) $pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO tickets (order_id, session_id, n, given, family, phone, email, org, news, secret, msecret) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $tickets = [];
    foreach ($cleanAttendees as $idx => $a) {
        $tsecret = random_secret(28);
        $msecret = random_secret(24);
        $ins->execute([$orderId, $subevent, $idx + 1, $a['given'], $a['family'], $a['phone'] ?: null, $a['email'] ?: null, $a['org'] ?: null, $a['news'] ? 1 : 0, $tsecret, $msecret]);
        $tickets[] = ['n' => $idx + 1, 'name' => trim($a['given'] . ' ' . $a['family']), 'secret' => $tsecret, 'email' => $a['email'], 'msecret' => $msecret];
    }
    if ($wl) {
        $pdo->prepare("UPDATE waitlist SET status = 'booked', order_code = ? WHERE id = ?")->execute([$code, $wl['id']]);
    }
    $pdo->exec('COMMIT');
    log_event('booked', ['id' => $orderId, 'is_test' => $isTest], $subevent, $qty . ' คน' . ($wl ? ' · ใช้สิทธิ์จาก waiting list' : '') . ($pending ? ' · รอยืนยันอีเมล' : ''), 'customer');
    try {   // บันทึกการจองแล้ว — ใช้รหัสยืนยันไม่สำเร็จก็ต้องตอบว่าจองสำเร็จ
        if ($emailVerified) {
            vc_token_consume('mail:' . $email, (string) $in['email_token']);
        }
        if ($phoneVerified) {
            vc_token_consume($phone, (string) $in['phone_token']);
        }
    } catch (Throwable $e) {
        error_log('book token consume: ' . $e->getMessage());
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->exec('ROLLBACK');
    }
    error_log('book: ' . $e->getMessage());
    json_fail(500, 'server', 'ไม่สามารถทำการจองได้ กรุณาลองใหม่อีกครั้ง');
}

$manage = 'manage.php?o=' . rawurlencode($code) . '&s=' . rawurlencode($secret);

// หลังตอบผู้ใช้แล้ว: อีเมลขอให้ยืนยัน (หรือส่งตั๋วเลยถ้าปิดการยืนยันอีเมล) — ช้า/ล่มไม่กระทบการจอง
$wlUsed = (bool) $wl;
register_shutdown_function(function () use ($orderId, $pending, $wlUsed) {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    $st = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    $o = $st->fetch();
    $pending ? send_confirm_request($o) : send_booking_emails($o);
    notify_order('booked', $o, trim(($pending ? 'รอยืนยันอีเมล ' : '') . ($wlUsed ? 'จองด้วยสิทธิ์จาก waiting list (เกินความจุ) ' : '')
        . history_note(contact_history($o['email'], $o['phone'], (int) $o['id'], $o['given'], $o['family']))));
});

json_out(201, [
    'ok'    => true,
    'code'  => $code,
    'url'   => $manage,
    'email' => $email,
    'qty'   => $qty,
    'pending'    => $pending,
    'confirm_by' => $confirmBy,
    'slot'  => ['date' => $slot['date'], 'start' => $slot['start'], 'end' => $slot['end']],
    'ics'   => 'api/ics.php?o=' . rawurlencode($code) . '&s=' . rawurlencode($secret),
    'gcal'  => google_calendar_url($code, $slot['starts_at'], $slot['ends_at'], $qty, public_url($manage), $lang),
]);
