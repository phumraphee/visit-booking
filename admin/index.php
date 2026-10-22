<?php
// หลังบ้านการจองเข้าชม — SPA (assets/admin.js) คุยกับ api/admin.php
$v = '1.8.75';
require_once __DIR__ . '/../lib/pretix.php';
require_installed('../');
require_once __DIR__ . '/../lib/venue.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/oidc.php';
$vn = htmlspecialchars(venue_name('th'), ENT_QUOTES);
header('Cache-Control: no-cache');   // แอปหน้าจอโฮมไม่ค้างหน้าเก่า
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#f7f4ee">
<title>ระบบการจองเข้าชม · <?= $vn ?></title>
<link rel="icon" href="../assets/brand/mark-32.png" sizes="32x32">
<link rel="apple-touch-icon" href="../assets/brand/app-180.png">
<link rel="manifest" href="manifest.webmanifest">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="ระบบจอง">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600&family=Noto+Serif+Thai:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/style.css?v=<?= $v ?>">
<link rel="stylesheet" href="../assets/admin.css?v=<?= $v ?>">
</head>
<body class="admin">
<header class="site">
  <a class="brand" href="./">
    <img class="brand-logo" src="../<?= htmlspecialchars(venue_logo_path(), ENT_QUOTES) ?>" width="101" height="88" alt="">
    <span class="brand-txt"><span class="brand-mark"><?= $vn ?></span><small class="brand-sub">ระบบการจองเข้าชม</small></span>
  </a>
  <span class="alang hlang"><button type="button" data-al="th">ไทย</button><button type="button" data-al="en">EN</button></span>
  <nav class="anav" id="nav" hidden>
    <a href="#/sessions" data-nav="sessions"><svg class="ni" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg><span>รอบ</span></a>
    <a href="#/search" data-nav="search"><svg class="ni" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg><span>ค้นหา</span></a>
    <a href="../scan/" class="nav-scan"><svg class="ni" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2M7 12h10"/></svg><span>สแกน</span></a>
    <a href="#/activity" data-nav="activity"><svg class="ni" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg><span>กิจกรรม</span></a>
    <button type="button" class="nav-morebtn" id="moreBtn" aria-expanded="false"><svg class="ni" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg><span>เพิ่มเติม</span></button>
    <div class="nav-more" id="navMore">
      <a href="#/mail" data-nav="mail" data-role="admin"><svg class="ni" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg><span>อีเมล</span></a>
      <a href="#/settings" data-nav="settings" data-role="admin"><svg class="ni" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg><span>ตั้งค่า</span></a>
      <a href="#/audit" data-nav="audit" data-role="super"><svg class="ni" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5M12 7v5l3 3"/></svg><span>ประวัติการใช้งาน</span></a>
      <a href="#/account" data-nav="account"><svg class="ni" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c.8-4.4 4-6.5 8-6.5s7.2 2.1 8 6.5"/></svg><span>บัญชี</span></a>
      <a href="#/share" data-nav="share"><svg class="ni" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7M12 3v12M7 8l5-5 5 5"/></svg><span>แชร์หน้าจอง</span></a>
      <button type="button" class="nav-logout nav-ico" onclick="location.reload()" title="รีเฟรช" aria-label="รีเฟรช"><svg class="ni" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.7M20 4v4h-4"/></svg><span>รีเฟรช</span></button>
      <button type="button" id="logout" class="nav-logout nav-ico" title="ออก" aria-label="ออก"><svg class="ni" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 17l5-5-5-5M15 12H4"/></svg><span>ออก</span></button>
    </div>
  </nav>
</header>
<div class="testbar" id="testbar" hidden>โหมดทดลองเปิดอยู่ — การจองใหม่เป็นข้อมูลทดสอบ และอีเมลจะไม่ถูกส่งจริง (ดูได้ที่เมนู "อีเมล")</div>
<main class="wrap" id="view"><p class="note">กำลังโหลด…</p></main>
<script>window.VENUE_NAME = <?= json_encode(['th' => venue_name('th'), 'en' => venue_name('en')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script>window.AUTH = <?= json_encode(['sso' => oidc_enabled() ? ['th' => oidc_label('th'), 'en' => oidc_label('en')] : null, 'passkey' => passkey_enabled()], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="../assets/admin-i18n.js?v=1.0.66"></script>
<script src="../assets/passkey.js?v=<?= $v ?>" defer></script>
<script src="../assets/ptr.js?v=<?= $v ?>" defer></script>
<script src="../assets/fileview.js?v=<?= $v ?>" defer></script>
<script src="../assets/admin.js?v=<?= $v ?>" defer></script>
</body>
</html>
