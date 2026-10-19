<?php
declare(strict_types=1);
/**
 * ตัวช่วยติดตั้ง / Setup wizard — ใช้ได้เฉพาะตอนที่ยังไม่มีไฟล์ config (ติดตั้งแล้ว = ปิดถาวร)
 * 1) ตรวจความพร้อมของโฮสต์  2) กรอกชื่อสถานที่ + ผู้ดูแลคนแรก + SMTP (ไม่บังคับ)
 * 3) เขียน config (นอก web root ถ้าเขียนได้) + สร้างฐานข้อมูล + ผู้ดูแล → เข้าสู่ระบบหลังบ้านให้เลย
 */
require_once __DIR__ . '/lib/pretix.php';

if (config_path() !== null) {
    http_response_code(404);
    exit('Already installed. / ติดตั้งแล้ว');
}

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$outside = dirname(__DIR__) . '/visit-booking-data';   // นอก web root (cfg() หาที่นี่ก่อน)
$inside = __DIR__ . '/data';                           // สำรอง: ในเว็บ (ห้ามเข้าด้วย .htaccess)
$canOutside = is_dir($outside) ? is_writable($outside) : is_writable(dirname(__DIR__));

$checks = [
    ['PHP ≥ 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION],
    ['PDO SQLite', extension_loaded('pdo_sqlite'), ''],
    ['OpenSSL', extension_loaded('openssl'), ''],
    ['cURL', extension_loaded('curl'), ''],
    ['mbstring', extension_loaded('mbstring'), ''],
    ['GD (QR codes)', extension_loaded('gd'), ''],
    ['Writable data folder / เขียนโฟลเดอร์ข้อมูลได้', $canOutside || is_writable(__DIR__), $canOutside ? $outside : $inside],
];
$ready = !in_array(false, array_column($checks, 1), true);

session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$err = '';
$in = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    $email = mb_strtolower(trim((string) ($in['admin_email'] ?? '')));
    $nameEn = trim((string) ($in['name_en'] ?? ''));
    $nameTh = trim((string) ($in['name_th'] ?? '')) ?: $nameEn;
    $url = rtrim(trim((string) ($in['public_url'] ?? '')), '/');
    if (!hash_equals($_SESSION['csrf'], (string) ($in['csrf'] ?? ''))) {
        $err = 'Session expired — please try again. / หมดเวลา กรุณาลองใหม่';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Admin email is not valid. / อีเมลผู้ดูแลไม่ถูกต้อง';
    } elseif ($nameEn === '') {
        $err = 'Please enter the venue name. / กรุณากรอกชื่อสถานที่';
    } elseif (!preg_match('#^https?://[^\s/]+#', $url)) {
        $err = 'Site URL must start with https:// / ที่อยู่เว็บต้องขึ้นต้นด้วย https://';
    } else {
        $dir = $canOutside ? $outside : $inside;
        @mkdir($dir, 0770, true);
        @mkdir($dir . '/cache', 0770, true);
        if (!$canOutside) {   // ในเว็บ: กันเข้าถึงไฟล์ข้อมูลโดยตรง (Apache)
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        $smtpHost = trim((string) ($in['smtp_host'] ?? ''));
        $config = [
            'public_url'     => $url,
            'timezone'       => in_array($in['timezone'] ?? '', timezone_identifiers_list(), true) ? $in['timezone'] : 'Asia/Bangkok',
            'db_path'        => $dir . '/visit.sqlite',
            'cache_dir'      => $dir . '/cache',
            'max_per_order'  => 10,
            'months_ahead'   => 6,
            'rate_limit'     => ['max' => 10, 'window' => 600],
            'cron_key'       => bin2hex(random_bytes(16)),
            'smtp_host'      => $smtpHost,
            'smtp_port'      => (int) ($in['smtp_port'] ?? 587) ?: 587,
            'smtp_user'      => trim((string) ($in['smtp_user'] ?? '')),
            'smtp_pass'      => (string) ($in['smtp_pass'] ?? ''),
            'mail_from'      => trim((string) ($in['mail_from'] ?? '')) ?: $email,
            'mail_from_name' => $nameEn,
            'mail_reply_to'  => trim((string) ($in['mail_from'] ?? '')) ?: $email,
            // Apple Wallet / Google Wallet / Turnstile / Ghost: ตั้งภายหลังได้ (ดู README)
            'wallet_pass_type_id' => '', 'wallet_team_id' => '',
        ];
        $php = "<?php\n// Created by install.php " . date('Y-m-d H:i') . " — keep this file private (contains passwords)\nreturn " . var_export($config, true) . ";\n";
        $cfgFile = $canOutside ? $dir . '/config.php' : __DIR__ . '/config.php';
        if (@file_put_contents($cfgFile, $php, LOCK_EX) === false) {
            $err = 'Could not write the config file. / เขียนไฟล์ config ไม่ได้: ' . $cfgFile;
        } else {
            @chmod($cfgFile, 0640);
            require_once __DIR__ . '/lib/auth.php';
            require_once __DIR__ . '/lib/venue.php';
            db();   // สร้างตาราง
            db()->prepare("INSERT INTO admins (email, name, role, super, active, created_at) VALUES (?, ?, 'admin', 1, 1, ?)
                           ON CONFLICT(email) DO UPDATE SET role = 'admin', super = 1, active = 1")
                ->execute([$email, trim((string) ($in['admin_name'] ?? '')) ?: null, now_iso()]);
            setting_set('venue_name_en', $nameEn);
            setting_set('venue_name_th', $nameTh);
            setting_set('venue_email', $email);
            setting_set('test_mode', '1');   // เริ่มด้วยโหมดทดลอง (ปิดเองหลัง 24 ชม. หรือปิดในหลังบ้าน)
            $st = db()->prepare('SELECT * FROM admins WHERE email = ?');
            $st->execute([$email]);
            auth_login($st->fetch());
            header('Location: admin/#/settings');
            exit;
        }
    }
}
$guessUrl = (($_SERVER['HTTPS'] ?? '') !== '' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Setup · Visit booking</title>
<style>
  :root{--ink:#141210;--muted:#6e675e;--line:#e4ddd2;--bg:#f7f4ee;--ok:#2f6b3a;--bad:#a3281d}
  body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 system-ui,-apple-system,"Noto Sans Thai",sans-serif}
  main{max-width:640px;margin:0 auto;padding:28px 16px 60px}
  h1{font-size:26px;margin:0 0 4px}h2{font-size:18px;margin:0 0 10px}
  .panel{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;margin:16px 0}
  .muted{color:var(--muted);font-size:14px}
  .chk{display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px solid var(--line);font-size:15px}
  .chk:last-child{border:0}.ok{color:var(--ok)}.no{color:var(--bad);font-weight:600}
  label{display:block;margin:10px 0}label span{display:block;font-size:14px;color:var(--muted);margin-bottom:3px}
  input,select{width:100%;box-sizing:border-box;font:inherit;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:#fff}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}@media(max-width:520px){.grid{grid-template-columns:1fr}}
  button{font:inherit;font-weight:600;background:var(--ink);color:#fff;border:0;border-radius:10px;padding:12px 18px;width:100%;cursor:pointer}
  .err{background:#f6e3e0;color:var(--bad);padding:10px 12px;border-radius:10px}
  details summary{cursor:pointer;font-weight:600}
</style>
</head>
<body>
<main>
  <h1>Visit booking — setup</h1>
  <p class="muted">ตัวช่วยติดตั้ง · ใช้ครั้งเดียว ติดตั้งเสร็จแล้วหน้านี้จะปิดเอง</p>

  <section class="panel">
    <h2>1 · Server check / ตรวจความพร้อม</h2>
    <?php foreach ($checks as [$label, $ok, $note]): ?>
      <div class="chk"><span><?= $h($label) ?><?= $note !== '' ? ' <span class="muted">' . $h($note) . '</span>' : '' ?></span><span class="<?= $ok ? 'ok' : 'no' ?>"><?= $ok ? '✓' : '✗ missing / ไม่มี' ?></span></div>
    <?php endforeach; ?>
    <?php if (!$canOutside && $ready): ?>
      <p class="muted">Data will be stored inside the web folder (<code>data/</code>, protected by .htaccess on Apache). On nginx, block <code>/data/</code> and <code>/config.php</code> yourself.<br>
      ข้อมูลจะเก็บในโฟลเดอร์เว็บ (data/) — ถ้าใช้ nginx ต้องตั้งให้ห้ามเข้าถึงเอง</p>
    <?php endif; ?>
  </section>

  <?php if ($ready): ?>
  <form method="post" class="panel" autocomplete="off">
    <h2>2 · Your venue / สถานที่</h2>
    <?php if ($err): ?><p class="err"><?= $h($err) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <div class="grid">
      <label><span>Venue name (English) *</span><input name="name_en" required value="<?= $h($in['name_en'] ?? '') ?>"></label>
      <label><span>ชื่อสถานที่ (ไทย)</span><input name="name_th" value="<?= $h($in['name_th'] ?? '') ?>"></label>
    </div>
    <label><span>Site URL / ที่อยู่เว็บ *</span><input name="public_url" required value="<?= $h($in['public_url'] ?? $guessUrl) ?>"></label>
    <label><span>Time zone / เขตเวลา</span><select name="timezone"><?php foreach (timezone_identifiers_list() as $tz): ?><option<?= $tz === ($in['timezone'] ?? 'Asia/Bangkok') ? ' selected' : '' ?>><?= $h($tz) ?></option><?php endforeach; ?></select></label>

    <h2 style="margin-top:22px">3 · First administrator / ผู้ดูแลคนแรก</h2>
    <div class="grid">
      <label><span>Email *</span><input name="admin_email" type="email" required value="<?= $h($in['admin_email'] ?? '') ?>"></label>
      <label><span>Name / ชื่อ</span><input name="admin_name" value="<?= $h($in['admin_name'] ?? '') ?>"></label>
    </div>
    <p class="muted">You will be signed in right after setup. Later sign-ins use a code sent by email, so set up SMTP below (or later in the config file).<br>
    ติดตั้งเสร็จจะเข้าสู่ระบบให้ทันที ครั้งต่อไปเข้าด้วยรหัสทางอีเมล จึงควรตั้งค่าส่งอีเมล (SMTP)</p>

    <details>
      <summary>4 · Email sending (SMTP) / การส่งอีเมล</summary>
      <p class="muted">Needed for booking confirmations and sign-in codes. Any SMTP works (e.g. Amazon SES, Gmail, your host's mail). Leave empty to set up later.</p>
      <div class="grid">
        <label><span>SMTP host</span><input name="smtp_host" placeholder="email-smtp.ap-southeast-1.amazonaws.com" value="<?= $h($in['smtp_host'] ?? '') ?>"></label>
        <label><span>Port</span><input name="smtp_port" inputmode="numeric" value="<?= $h($in['smtp_port'] ?? '587') ?>"></label>
        <label><span>Username</span><input name="smtp_user" value="<?= $h($in['smtp_user'] ?? '') ?>"></label>
        <label><span>Password</span><input name="smtp_pass" type="password" value=""></label>
      </div>
      <label><span>From address / อีเมลผู้ส่ง</span><input name="mail_from" type="email" placeholder="bookings@example.org" value="<?= $h($in['mail_from'] ?? '') ?>"></label>
    </details>
    <p style="margin-top:20px"><button>Install / ติดตั้ง</button></p>
    <p class="muted">The site starts in test mode (bookings are marked as tests and emails are not sent). Turn it off in Settings when ready.<br>
    เริ่มด้วยโหมดทดลอง — ปิดได้ในหลังบ้านเมื่อพร้อมใช้งานจริง</p>
  </form>
  <?php else: ?>
    <p class="err">Please ask your host to enable the missing items above, then reload this page.<br>กรุณาให้ผู้ให้บริการโฮสต์เปิดรายการที่ขาด แล้วโหลดหน้านี้ใหม่</p>
  <?php endif; ?>
</main>
</body>
</html>
