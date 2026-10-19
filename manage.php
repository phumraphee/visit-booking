<?php
// ดู / ยกเลิกการจอง — ลิงก์: manage.php?o=CODE&s=SECRET (ได้จากหน้าจองสำเร็จ)
require_once __DIR__ . '/lib/pretix.php';
require_installed();
require_once __DIR__ . '/lib/emails.php';
$V = venue();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$v = '1.12.21';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="referrer" content="no-referrer">
<title>My booking · <?= $h($V['name_en']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600&family=Noto+Serif+Thai:wght@400;600&display=swap" rel="stylesheet">
<link rel="icon" href="assets/brand/mark-32.png" sizes="32x32">
<link rel="apple-touch-icon" href="assets/brand/mark-180.png">
<link rel="stylesheet" href="assets/style.css?v=<?= $v ?>">
</head>
<body>
<header class="site">
  <a class="brand" href="./">
    <img class="brand-logo" src="<?= $h(venue_logo_path()) ?>" width="101" height="88" alt="<?= $h($V['name_en']) ?>">
    <span class="brand-mark" data-i18n="museum"><?= $h($V['name_en']) ?></span>
  </a>
  <div class="lang" role="group" aria-label="Language">
    <button type="button" data-lang="th">ไทย</button>
    <button type="button" data-lang="en" class="on">EN</button>
  </div>
</header>

<main class="wrap">
  <section class="intro"><h1 data-i18n="title">My booking</h1></section>
  <section class="panel" id="box" aria-busy="true">
    <p class="note" id="msg" data-i18n="loading">Loading…</p>
    <div id="detail" hidden>
      <p class="code"><span data-i18n="code">Booking code</span> <b id="code">—</b> <span class="tag" id="status"></span></p>
      <div class="summary" id="summary"></div>
      <div class="confirm-box" id="confirmBox" hidden>
        <p id="confirmText"></p>
        <button type="button" class="primary" id="confirmBtn" data-i18n="confirm_btn">Confirm booking</button>
      </div>
      <div class="attend-box" id="attendBox" hidden>
        <p id="attendText"></p>
        <button type="button" class="primary" id="attendBtn" data-i18n="attend_btn">I will attend</button>
      </div>
      <p class="okmsg" id="okMsg" role="status" hidden></p>
      <ul id="names" class="names"></ul>
      <p class="hint" id="qrNote" hidden data-i18n="show_qr">Please show the QR when you arrive.</p>
      <div id="tickets" class="tickets"></div>
      <p class="print-link" id="printWrap" hidden><button type="button" class="ghost sm" id="printBtn"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg><span data-i18n="print">Download tickets (PDF)</span></button></p>
      <p class="cal-links" id="calLinks" hidden><a id="icsLink" class="calbtn" href="#"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg><span data-i18n="cal_ics">Add to calendar</span></a><a id="gcalLink" class="calbtn" href="#" target="_blank" rel="noopener"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg><span>Google Calendar</span></a></p>
      <p class="err" id="err" role="alert" hidden></p>
      <p class="hint" id="cantNote" hidden></p>
      <form class="edit-box" id="editBox" hidden novalidate></form>
      <div class="actions stack">
        <button type="button" class="ghost" id="editBtn" hidden data-i18n="edit">Edit details</button>
        <a class="ghost" href="./" data-i18n="home">Back to booking</a>
        <button type="button" class="danger" id="cancelBtn" hidden data-i18n="cancel">Cancel this booking</button>
      </div>
    </div>
  </section>
</main>

<footer class="site">
  <span>© <?= $h($V['name_en']) ?> · <a href="<?= $h($V['privacy']) ?>" target="_blank" rel="noopener" data-i18n="privacy">Privacy Policy</a></span>
  <a class="staff-link" href="admin/" data-i18n="staff">Staff</a>
</footer>
<a class="admin-fab" id="adminFab" href="admin/" hidden>หลังบ้าน</a>
<script>try { if (localStorage.getItem('vb-admin') === '1') document.getElementById('adminFab').hidden = false; } catch (_) {}</script>
<script>window.MUSEUM = <?= json_encode(['address' => ['th' => venue_address('th'), 'en' => venue_address('en')], 'name' => ['th' => venue_name('th'), 'en' => venue_name('en')], 'maps' => $V['maps'], 'group_email' => $V['group_email']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<?= text_overrides_js('I18N_MANAGE') ?>
<script src="assets/i18n-manage.js?v=<?= $v ?>" defer></script>
<script src="assets/manage.js?v=<?= $v ?>" defer></script>
<script src="assets/ptr.js?v=<?= $v ?>" defer></script>
<script src="assets/fileview.js?v=<?= $v ?>" defer></script>
</body>
</html>
