<?php
// หน้าสแกนเช็คอิน (PWA) — ใช้ได้ทั้ง iOS/Android ผ่านเบราว์เซอร์, ทำงานออฟไลน์ได้หลังโหลดรายชื่อรอบ
$v = '1.2.20';
require_once __DIR__ . '/../lib/pretix.php';
require_installed('../');
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/oidc.php';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#141210">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>สแกนเช็คอิน</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="../assets/brand/mark-32.png" sizes="32x32">
<link rel="apple-touch-icon" href="../assets/brand/app-180.png">
<link rel="stylesheet" href="../assets/scan.css?v=<?= $v ?>">
</head>
<body>
<header class="bar">
  <div class="bar-top">
    <a class="back" href="../admin/" id="backBtn" aria-label="กลับหลังบ้าน" title="กลับหลังบ้าน">‹</a>
    <span class="bar-title">สแกนเช็คอิน</span>
    <span class="net" id="net" title="สถานะเครือข่าย"></span>
    <button type="button" class="alang-s" id="alang">EN</button>
  </div>
  <div class="bar-sess" id="barSess">
    <button id="sessBtn" class="sess" aria-label="เลือกรอบ"><span class="sd" id="sessDate">เลือกรอบ</span><b class="st" id="sessTime"></b><span class="caret">▾</span></button>
    <span class="count"><b id="count">–</b><small>เช็คอินแล้ว</small></span>
  </div>
</header>

<section id="login" class="sheet" hidden>
  <h2>เข้าสู่ระบบ</h2>
  <button type="button" id="ssoLogin" class="btn" hidden></button>
  <p class="or" id="ssoOr" hidden>หรือ</p>
  <form id="lf">
    <input type="email" name="email" placeholder="อีเมล" required autocomplete="email">
    <input name="code" type="text" class="otp" placeholder="รหัส 6 หลัก" inputmode="numeric" autocomplete="one-time-code" maxlength="6" hidden>
    <button class="btn">ส่งรหัสเข้าอีเมล</button>
    <button type="button" class="linklike" id="haveCode">มีรหัสจากผู้ดูแลแล้ว</button>
    <p class="msg" id="lmsg"></p>
  </form>
  <button type="button" id="pkLogin" class="btn ghost pk" hidden>เข้าสู่ระบบด้วย Face ID / ลายนิ้วมือ</button>
</section>

<section id="sessions" class="sheet" hidden>
  <h2>เลือกรอบ</h2>
  <div id="slist"></div>
</section>

<main id="scanner" hidden>
  <div class="cam">
    <video id="video" playsinline muted></video>
    <div class="frame"></div>
    <button id="camBtn" class="btn ghost">เปิดกล้อง</button>
    <div class="gun-panel" id="gunPanel" hidden>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7V5a1 1 0 0 1 1-1h2M17 4h2a1 1 0 0 1 1 1v2M20 17v2a1 1 0 0 1-1 1h-2M7 20H5a1 1 0 0 1-1-1v-2M7 8v8M10 8v8M13 8v8M16 8v8"/></svg>
      <b>โหมดปืนสแกน</b><span>ยิงบาร์โค้ด/QR ได้เลย — กล้องปิดอยู่</span>
    </div>
  </div>
  <div class="tools">
    <button id="listBtn" class="tbtn"><svg class="ti" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></svg><span>รายชื่อ</span></button>
    <button id="torchBtn" class="tbtn" hidden><svg class="ti" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 2h6l-1 7h-4L9 2zM10 9v11a2 2 0 0 0 4 0V9"/></svg><span>ไฟฉาย</span></button>
    <button id="gunBtn" class="tbtn"><svg class="ti" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7V5a1 1 0 0 1 1-1h2M17 4h2a1 1 0 0 1 1 1v2M20 17v2a1 1 0 0 1-1 1h-2M7 20H5a1 1 0 0 1-1-1v-2M7 8v8M10 8v8M13 8v8M16 8v8"/></svg><span id="gunLbl">ปิดกล้อง</span></button>
  </div>
  <p class="hint">สแกน QR จากอีเมลหรือ Wallet · ใช้ปืนสแกนบาร์โค้ดได้ · ทำงานต่อได้แม้เน็ตหลุด</p>
</main>

<section id="list" class="sheet full" hidden>
  <div class="lhead"><input type="search" id="q" placeholder="ค้นชื่อ / รหัส"><button id="closeList" class="btn ghost" data-en="Close">ปิด</button></div>
  <div id="rows"></div>
</section>

<div id="result" class="result" hidden>
  <div class="ricon" id="ricon"></div>
  <div class="rtitle" id="rtitle"></div>
  <div class="rname" id="rname"></div>
  <div class="rsub" id="rsub"></div>
  <button id="force" class="btn" hidden>เช็คอินอยู่ดี</button>
  <button id="undo" class="btn ghost" hidden>สแกนผิด — ยกเลิกเช็คอินนี้</button>
  <button id="restore" class="btn" hidden>คืนการจองและเช็คอิน</button>
</div>

<div id="cfm" class="cfm" hidden role="dialog" aria-modal="true">
  <div class="cfm-box">
    <div class="cfm-warn" id="cfmWarn" hidden></div>
    <div class="cfm-title" id="cfmTitle">เช็คอิน?</div>
    <div class="cfm-name" id="cfmName"></div>
    <div class="cfm-sub" id="cfmSub"></div>
    <div class="cfm-btns"><button type="button" class="btn ghost" id="cfmNo">ยกเลิก</button><button type="button" class="btn" id="cfmYes">เช็คอิน</button></div>
  </div>
</div>
<canvas id="canvas" hidden></canvas>
<script>window.AUTH = <?= json_encode(['sso' => oidc_enabled() ? ['th' => oidc_label('th'), 'en' => oidc_label('en')] : null, 'passkey' => passkey_enabled()], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="../assets/admin-i18n.js?v=1.0.66"></script>
<script src="../assets/vendor/jsQR.js?v=1.4.0" defer></script>
<script src="../assets/passkey.js?v=<?= $v ?>" defer></script>
<script src="../assets/ptr.js?v=<?= $v ?>" defer></script>
<script src="../assets/scan.js?v=<?= $v ?>" defer></script>
</body>
</html>
