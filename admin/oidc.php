<?php
/**
 * เข้าสู่ระบบ / ออกจากระบบ ผ่าน SSO (OIDC) — ดู lib/oidc.php
 *   ?start=1[&back=scan]   ไปหน้า SSO
 *   ?logout=1[&back=scan]  (หลังลบ session ของแอปแล้ว) ถาม SSO แบบ prompt=none ว่ายังล็อกอินอยู่ไหม
 *                          ยังอยู่ → ไปหน้า "ออกจากระบบ" ของ SSO · หมดอายุแล้ว → กลับหน้าล็อกอินของแอปเลย
 *   ?code=…&state=…        callback จาก SSO
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/pretix.php';
require_installed('../');
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/oidc.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$backUrl = fn(string $b, string $q = '') => ($b === 'scan' ? '../scan/' : '../admin/') . ($q !== '' ? '?sso=' . $q : '');
$go = function (string $url): never {
    header('Location: ' . $url, true, 302);
    exit;
};
$fail = function (string $why, string $back, string $q = 'fail') use ($go, $backUrl): never {
    @file_put_contents(cfg()['cache_dir'] . '/oidc-errors.log', date('c') . ' ' . $why . "\n", FILE_APPEND);
    $go($backUrl($back, $q));
};

if (!oidc_enabled()) {
    http_response_code(404);
    exit('SSO is not configured');
}

$back = ($_GET['back'] ?? '') === 'scan' ? 'scan' : 'admin';

try {
    if (isset($_GET['start'])) {
        oidc_start('login', $back);
    }
    if (isset($_GET['logout'])) {
        auth_logout();
        oidc_start('logout', $back);
    }
} catch (Throwable $e) {
    $fail('start: ' . $e->getMessage(), $back);
}

$st = oidc_take_state((string) ($_GET['state'] ?? ''));
if (!$st) {
    $fail('bad state', $back);
}
$back = $st['back'];
if (isset($_GET['iss']) && rtrim((string) $_GET['iss'], '/') !== oidc_conf()['issuer']) {
    $fail('iss param', $back);
}

if ($st['purpose'] === 'logout') {
    // ได้ code = ยังล็อกอิน SSO อยู่ → ทิ้ง code (ไม่สร้าง session) แล้วไปหน้าออกจากระบบของ SSO
    // ได้ error (login_required ฯลฯ) = SSO หมดอายุแล้ว → กลับหน้าล็อกอินของแอป
    if (isset($_GET['code']) && oidc_conf()['logout_url'] !== '') {
        $go(oidc_conf()['logout_url']);
    }
    $go($backUrl($back));
}

if (isset($_GET['error'])) {
    $fail('error: ' . substr((string) $_GET['error'], 0, 60), $back, $_GET['error'] === 'access_denied' ? 'denied' : 'fail');
}
if (rate_limited('login')) {
    $fail('rate limited', $back);
}

try {
    $claims = oidc_finish((string) ($_GET['code'] ?? ''), $st);
} catch (Throwable $e) {
    $fail('finish: ' . $e->getMessage(), $back);
}
$admin = oidc_find_admin($claims);
if (!$admin) {
    $fail('no admin for ' . substr(hash('sha256', strtolower((string) ($claims['email'] ?? ''))), 0, 12), $back, 'denied');
}
auth_login($admin, 'oidc');
$go($backUrl($back));
