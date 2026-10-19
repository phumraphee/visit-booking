<?php
declare(strict_types=1);
/**
 * ยืนยันข้อมูลผู้ติดต่อด้วยรหัส 6 หลักในฟอร์มจอง (ก่อนกดจอง)
 *   อีเมล: ส่งรหัสทางอีเมล (SES) — setting email_verify (ค่าเริ่มต้นเปิด)
 *   SMS: ผู้ให้บริการ ThaiBulkSMS API v2 — เปิดเมื่อใส่ sms_key + sms_secret (+ sms_sender) ใน config และ setting sms_verify
 * ยืนยันเฉพาะเบอร์มือถือไทย (+66 6/8/9) — เบอร์ต่างประเทศจองได้โดยไม่ยืนยัน (หลังบ้านเห็นว่ายังไม่ยืนยัน)
 * โหมดทดลอง: ไม่ส่ง SMS จริง — คืนรหัสให้หน้าเว็บแสดง (ไม่เปลืองเครดิต)
 * โหมดทดลอง (ทั้งอีเมลและ SMS): ไม่ส่งจริง — คืนรหัสให้หน้าเว็บแสดง
 * กันส่งถี่: ต่อปลายทาง 30 วิ/ครั้ง และ 3 ครั้ง/ชม., ต่อ IP 10 ครั้ง/ชม., SMS ทั้งระบบ sms_daily_cap/วัน (ค่าเริ่มต้น 200)
 * ตาราง phone_codes ใช้ร่วมกัน: key = '+66…' (SMS) หรือ 'mail:<อีเมล>'
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/venue.php';
require_once __DIR__ . '/emails.php';

function sms_configured(): bool
{
    $c = cfg();
    return ($c['sms_key'] ?? '') !== '' && ($c['sms_secret'] ?? '') !== '';
}

/** ต้องยืนยันเบอร์ตอนจองไหม (โหมดทดลองเปิดให้ลองได้แม้ยังไม่มีบัญชี SMS) */
function sms_verify_on(): bool
{
    return setting('sms_verify', '0') === '1' && (sms_configured() || test_mode());
}

function email_verify_on(): bool
{
    return setting('email_verify', '1') === '1';
}

function thai_mobile(string $e164): bool
{
    return (bool) preg_match('/^\+66[689]\d{8}$/', $e164);
}

/** เบอร์ → +66… / +… หรือ null */
function normalize_phone(string $v): ?string
{
    $d = preg_replace('/[^\d+]/', '', $v);
    $d = preg_replace('/^00/', '+', $d);   // 0066… = +66…
    if (preg_match('/^66\d{8,9}$/', $d)) {   // 66812345678 (ลืม +)
        $d = '+' . $d;
    }
    $d = preg_replace('/^\+660/', '+66', $d);   // +66 081… (ใส่ 0 ซ้ำ)
    if ($d === '' || strlen(preg_replace('/\D/', '', $d)) < 8 || strpos($d, '+', 1) !== false) {
        return null;
    }
    if ($d[0] === '+') {
        return $d;
    }
    return preg_match('/^0\d{8,9}$/', $d) ? '+66' . substr($d, 1) : null;
}

function sms_send(string $e164, string $message): bool
{
    $c = cfg();
    $ch = curl_init('https://api-v2.thaibulksms.com/sms');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => $c['sms_key'] . ':' . $c['sms_secret'],
        CURLOPT_POSTFIELDS => http_build_query(array_filter([
            'msisdn' => '0' . substr($e164, 3),
            'message' => $message,
            'sender' => (string) ($c['sms_sender'] ?? ''),
        ])),
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code < 200 || $code >= 300) {
        error_log("sms: HTTP $code " . substr((string) $body, 0, 300));
        return false;
    }
    return true;
}

/**
 * ขอรหัส → ['ok'=>true, 'test_code'=>?] หรือ ['ok'=>false, 'error'=>wait|too_many|unavailable|send_failed]
 * $key = '+66…' หรือ 'mail:<อีเมล>'
 */
function vc_request(string $key, string $lang): array
{
    $pdo = db();
    $now = time();
    $isMail = str_starts_with($key, 'mail:');
    $pdo->prepare('DELETE FROM phone_codes WHERE created_at < ?')->execute([$now - 86400 * 2]);
    $st = $pdo->prepare('SELECT COUNT(*), MAX(created_at) FROM phone_codes WHERE phone = ? AND created_at > ?');
    $st->execute([$key, $now - 3600]);
    [$recent, $last] = $st->fetch(PDO::FETCH_NUM);
    if ($last && $now - (int) $last < 30) {
        return ['ok' => false, 'error' => 'wait', 'wait' => 30 - ($now - (int) $last)];
    }
    if ((int) $recent >= 3) {
        return ['ok' => false, 'error' => 'too_many'];
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM phone_codes WHERE ip = ? AND created_at > ?');
    $st->execute([client_ip(), $now - 3600]);
    if ((int) $st->fetchColumn() >= 10) {
        return ['ok' => false, 'error' => 'too_many'];
    }
    $test = test_mode();
    if (!$test && !$isMail) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM phone_codes WHERE created_at > ? AND phone NOT LIKE 'mail:%'");
        $st->execute([$now - 86400]);
        if ((int) $st->fetchColumn() >= (int) setting('sms_daily_cap', '200')) {
            error_log('sms: daily cap reached');
            return ['ok' => false, 'error' => 'unavailable'];
        }
    }
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO phone_codes (phone, code_hash, expires_at, ip, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$key, password_hash($code, PASSWORD_DEFAULT), $now + 600, client_ip(), $now]);
    if ($test) {
        return ['ok' => true, 'test_code' => $code];
    }
    $ok = $isMail ? email_code_send(substr($key, 5), $code, $lang)
        : sms_send($key, $lang === 'th' ? 'รหัสยืนยันการจอง' . venue_name('th') . ": $code (ใช้ได้ 10 นาที)" : venue_name('en') . " booking code: $code (valid 10 min)");
    if (!$ok) {
        $pdo->prepare('DELETE FROM phone_codes WHERE phone = ? AND created_at = ?')->execute([$key, $now]);
        return ['ok' => false, 'error' => 'send_failed'];
    }
    return ['ok' => true];
}

function email_code_send(string $email, string $code, string $lang): bool
{
    [$subject, $html, $images] = email_code_mail($code, $lang);
    return mail_no_bcc(fn() => send_mail($email, $subject, $html, $images));
}

/** ตัวอย่างในหลังบ้าน (เมนู อีเมล) */
function email_code_preview(string $lang): array
{
    return email_code_mail('482913', $lang);
}

function email_code_mail(string $code, string $lang): array
{
    // ข้อความเดิมจาก Pretix Double Opt-In (doistep_mail_*) + คำแปลไทย
    $ev = mail_t($lang, 'event');
    $subject = $lang === 'th' ? "$code - รหัสยืนยันอีเมลของคุณ" : "$code - Your email address confirmation code";
    $p1 = $lang === 'th'
        ? "ขณะนี้มีผู้กำลังจอง{$ev} โดยใช้อีเมลของคุณ เพื่อยืนยันอีเมลนี้และดำเนินการจองต่อ กรุณากรอกรหัสต่อไปนี้:"
        : "someone is currently trying to place an order for $ev using your E-mail-address. To confirm this E-mail address, and proceed with the ordering process, please enter the following code:";
    $content = mail_p(mail_esc(mail_t($lang, 'greet')), 'text-align:left;margin-bottom:6px')
        . mail_p(mail_esc($p1), 'text-align:left')
        . '<div style="text-align:center;font-family:Menlo,Consolas,monospace;font-size:34px;font-weight:700;letter-spacing:.3em;padding:14px 0 4px">' . $code . '</div>'
        . mail_closing($lang);
    $images = ['logo' => venue_logo_bytes()];
    return [$subject, mail_layout($lang, mail_esc($subject), $content), $images];
}

/** ตรวจรหัส → token (แนบตอนกดจอง, อายุ 1 ชม.) หรือ null */
function vc_verify(string $key, string $code): ?string
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM phone_codes WHERE phone = ? AND expires_at > ? AND verified_at IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$key, time()]);
    $row = $st->fetch();
    if (!$row || $row['tries'] >= 5) {
        return null;
    }
    if (!password_verify(preg_replace('/\D/', '', $code), $row['code_hash'])) {
        $pdo->prepare('UPDATE phone_codes SET tries = tries + 1 WHERE id = ?')->execute([$row['id']]);
        return null;
    }
    $token = bin2hex(random_bytes(16));
    $pdo->prepare('UPDATE phone_codes SET verified_at = ?, token = ? WHERE id = ?')->execute([time(), $token, $row['id']]);
    return $token;
}

function vc_token_valid(string $key, string $token): bool
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return false;
    }
    $st = db()->prepare('SELECT 1 FROM phone_codes WHERE phone = ? AND token = ? AND verified_at > ?');
    $st->execute([$key, $token, time() - 3600]);
    return (bool) $st->fetchColumn();
}

/** ใช้ token ตอนจองสำเร็จ (ครั้งเดียว) */
function vc_token_consume(string $key, string $token): void
{
    db()->prepare('UPDATE phone_codes SET token = NULL WHERE phone = ? AND token = ?')->execute([$key, $token]);
}
