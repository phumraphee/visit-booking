<?php
declare(strict_types=1);
/**
 * ล็อกอินหลังบ้าน/หน้าสแกนด้วยรหัส OTP ทางอีเมล 
 * - ขอรหัส: 6 หลัก อายุ 10 นาที ลองผิดได้ 5 ครั้ง (บอกผลเหมือนกันทั้งมี/ไม่มีบัญชี — ไม่เปิดเผยว่าอีเมลไหนเป็นแอดมิน)
 * - ผ่านแล้ว: cookie vb_auth (HttpOnly, Secure, SameSite=Lax) อายุ 30 วัน, เก็บแค่ hash ของ token ใน DB
 * - role: admin = ทุกอย่าง, staff = สแกน + ดูรายชื่อรอบ
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/venue.php';
require_once __DIR__ . '/emails.php';

const AUTH_COOKIE = 'vb_auth';
const AUTH_DAYS = 180;   // จำการล็อกอิน 180 วัน และต่ออายุทุกครั้งที่ใช้ (ไม่ใช้เกิน 180 วันจึงต้องล็อกอินใหม่)

function auth_send_code(string $email, string $lang, string $from = 'admin'): void
{
    $email = mb_strtolower(trim($email));
    $st = db()->prepare('SELECT * FROM admins WHERE email = ? AND active = 1');
    $st->execute([$email]);
    $admin = $st->fetch();
    if (!$admin) {
        usleep(random_int(200000, 500000));
        return;
    }
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $token = bin2hex(random_bytes(24));   // ลิงก์เข้าสู่ระบบ (ใช้ครั้งเดียว, อายุเท่ารหัส)
    db()->prepare('DELETE FROM login_codes WHERE email = ? OR expires_at < ?')->execute([$email, time()]);
    db()->prepare('INSERT INTO login_codes (email, code_hash, expires_at, link_hash) VALUES (?, ?, ?, ?)')
        ->execute([$email, password_hash($code, PASSWORD_DEFAULT), time() + 600, hash('sha256', $token)]);
    $url = public_url(($from === 'scan' ? 'scan/' : 'admin/') . '?login=' . $token);
    $subject = $lang === 'th' ? "รหัสเข้าสู่ระบบ: $code" : "Sign-in code: $code";
    $msg = $lang === 'th' ? 'กดปุ่มเพื่อเข้าสู่ระบบ หรือกรอกรหัสด้านล่าง (ใช้ได้ 10 นาที)' : 'Tap the button to sign in, or enter the code below (valid for 10 minutes)';
    $content = '<p style="margin:0 0 6px;font-size:15px;text-align:center">' . mail_esc($msg) . '</p>'
        . mail_button($url, $lang === 'th' ? 'เข้าสู่ระบบ' : 'Sign in')
        . '<div style="text-align:center;font-family:Menlo,Consolas,monospace;font-size:34px;font-weight:700;letter-spacing:.3em;padding:14px 0 4px">' . $code . '</div>';
    $images = ['logo' => venue_logo_bytes()];
    mail_no_bcc(fn() => send_mail($email, $subject, mail_layout($lang, mail_esc($subject), $content), $images));
}

/** ลิงก์เข้าสู่ระบบจากอีเมล → admin row หรือ null (ใช้ครั้งเดียว) */
function auth_verify_link(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT email FROM login_codes WHERE link_hash = ? AND expires_at > ?');
    $st->execute([hash('sha256', $token), time()]);
    $email = $st->fetchColumn();
    if (!$email) {
        return null;
    }
    db()->prepare('DELETE FROM login_codes WHERE email = ?')->execute([$email]);
    $st = db()->prepare('SELECT * FROM admins WHERE email = ? AND active = 1');
    $st->execute([$email]);
    return $st->fetch() ?: null;
}

/** ตรวจรหัส → คืน admin row หรือ null */
function auth_verify_code(string $email, string $code): ?array
{
    $email = mb_strtolower(trim($email));
    $st = db()->prepare('SELECT rowid, * FROM login_codes WHERE email = ? AND expires_at > ? ORDER BY expires_at DESC LIMIT 1');
    $st->execute([$email, time()]);
    $row = $st->fetch();
    if (!$row || $row['tries'] >= 5) {
        return null;
    }
    if (!password_verify(preg_replace('/\D/', '', $code), $row['code_hash'])) {
        db()->prepare('UPDATE login_codes SET tries = tries + 1 WHERE rowid = ?')->execute([$row['rowid']]);
        return null;
    }
    db()->prepare('DELETE FROM login_codes WHERE email = ?')->execute([$email]);
    $st = db()->prepare('SELECT * FROM admins WHERE email = ? AND active = 1');
    $st->execute([$email]);
    return $st->fetch() ?: null;
}

function auth_login(array $admin, ?string $via = null): void
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO auth_tokens (token_hash, admin_id, expires_at, created_at, ua, via) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $admin['id'], time() + AUTH_DAYS * 86400, now_iso(), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), $via]);
    auth_set_cookie($token);
}

function auth_set_cookie(string $token): void
{
    setcookie(AUTH_COOKIE, $token, [
        'expires'  => time() + AUTH_DAYS * 86400,
        'path'     => '/',
        'secure'   => ($_SERVER['HTTPS'] ?? '') !== '' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** ออกจากระบบ — คืนค่าวิธีที่ใช้เข้า (เช่น 'oidc') เพื่อให้หน้าเว็บไปออกจาก SSO ต่อ */
function auth_logout(): ?string
{
    $t = $_COOKIE[AUTH_COOKIE] ?? '';
    $via = null;
    if ($t !== '') {
        $st = db()->prepare('SELECT via FROM auth_tokens WHERE token_hash = ?');
        $st->execute([hash('sha256', $t)]);
        $via = $st->fetchColumn() ?: null;
        db()->prepare('DELETE FROM auth_tokens WHERE token_hash = ?')->execute([hash('sha256', $t)]);
    }
    setcookie(AUTH_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    return $via;
}

/** เปิด passkey (Face ID / ลายนิ้วมือ) หรือไม่ — ปิดได้ด้วย 'passkey_enabled' => false (ข้อมูลในตาราง passkeys ไม่ถูกลบ) */
function passkey_enabled(): bool
{
    return (bool) (cfg()['passkey_enabled'] ?? true);
}

function current_admin(): ?array
{
    static $me = false;
    if ($me !== false) {
        return $me;
    }
    $t = $_COOKIE[AUTH_COOKIE] ?? '';
    $me = null;
    if ($t !== '') {
        $st = db()->prepare('SELECT a.*, k.expires_at AS token_exp, k.last_seen AS token_seen FROM auth_tokens k JOIN admins a ON a.id = k.admin_id WHERE k.token_hash = ? AND k.expires_at > ? AND a.active = 1');
        $st->execute([hash('sha256', $t), time()]);
        $me = $st->fetch() ?: null;
        // ต่ออายุแบบเลื่อน: ใช้งานแล้วนับ 180 วันใหม่ (อัปเดตวันละครั้งพอ)
        // ใช้ล่าสุด (อัปเดตไม่เกินทุก 10 นาที)
        if ($me && (empty($me['token_seen']) || strtotime((string) $me['token_seen']) < time() - 600)) {
            db()->prepare('UPDATE auth_tokens SET last_seen = ?, ip = ? WHERE token_hash = ?')->execute([now_iso(), client_ip(), hash('sha256', $t)]);
        }
        if ($me && (int) $me['token_exp'] < time() + (AUTH_DAYS - 1) * 86400) {
            db()->prepare('UPDATE auth_tokens SET expires_at = ? WHERE token_hash = ?')->execute([time() + AUTH_DAYS * 86400, hash('sha256', $t)]);
            if (!headers_sent()) {
                auth_set_cookie($t);
            }
        }
    }
    return $me;
}

/** ต้องล็อกอิน (และต้องเป็น admin ถ้า $role = 'admin') — ไม่ผ่าน = 401/403 JSON */
function require_admin(string $role = 'staff'): array
{
    $me = current_admin();
    if (!$me) {
        json_fail(401, 'auth', 'กรุณาเข้าสู่ระบบ');
    }
    if ($role === 'admin' && $me['role'] !== 'admin') {
        json_fail(403, 'forbidden', 'ต้องเป็นผู้ดูแลระบบ');
    }
    return $me;
}

/** ยกเลิกการจองได้: ผู้ดูแลทุกคน + พนักงานที่ผู้ดูแลสูงสุดให้สิทธิ์ (admins.can_cancel) เช่น คนประสานงานยืนยันการมา */
function can_cancel(?array $me): bool
{
    return $me && ($me['role'] === 'admin' || !empty($me['can_cancel']));
}

function require_cancel(): array
{
    $me = require_admin('staff');
    if (!can_cancel($me)) {
        json_fail(403, 'forbidden', 'ไม่มีสิทธิ์ยกเลิกการจอง');
    }
    return $me;
}

/** ผู้ดูแลสูงสุด (จัดการผู้ใช้ได้) — ถ้ายังไม่มีใครเป็นเลย ผู้ดูแลทุกคนนับเป็นผู้ดูแลสูงสุด (กันล็อกตัวเองออก) */
function is_super(?array $me): bool
{
    if (!$me || $me['role'] !== 'admin') {
        return false;
    }
    if (!empty($me['super'])) {
        return true;
    }
    return !(int) db()->query("SELECT COUNT(*) FROM admins WHERE super = 1 AND role = 'admin' AND active = 1")->fetchColumn();
}

/** กัน CSRF สำหรับ POST: ต้องเป็น JSON และ Origin ตรงกับโฮสต์ */
function require_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== explode(':', $host)[0]) {
        json_fail(403, 'origin', 'Forbidden');
    }
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
        json_fail(415, 'content_type', 'JSON only');
    }
}
