<?php
// หน้าจองเข้าชม — ตัวหน้าเป็น static HTML, ข้อมูลทั้งหมดมาจาก /api/*.php
require_once __DIR__ . '/lib/pretix.php';
require_installed();
require __DIR__ . '/lib/db.php';   // setting() — คีย์ Turnstile ที่ตั้งในหลังบ้าน
require_once __DIR__ . '/lib/emails.php';   // public_url() — og:url / og:image + ข้อมูลสถานที่ (venue)
$V = venue();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$v = '1.13.12';
$turnstile = ext_cfg('turnstile_site');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Book a visit · <?= $h($V['name_en']) ?></title>
<meta name="description" content="เปิดให้เข้าชมตามรอบเวลาในปฏิทินด้านล่าง กรุณาจองล่วงหน้า">
<?php $site = public_url('/'); ?>
<link rel="canonical" href="<?= htmlspecialchars($site) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= $h($V['name_en']) ?>">
<meta property="og:title" content="Book a visit · <?= $h($V['name_en']) ?>">
<meta property="og:description" content="Visits are by advance booking only. Choose a date and a session below.">
<meta property="og:url" content="<?= htmlspecialchars($site) ?>">
<meta property="og:image" content="<?= htmlspecialchars(public_url('assets/brand/logo-1024.png')) ?>">
<meta property="og:image:width" content="1024"><meta property="og:image:height" content="1024">
<meta property="og:locale" content="en_US"><meta property="og:locale:alternate" content="th_TH">
<meta name="twitter:card" content="summary">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" href="api/slots.php" as="fetch" crossorigin>
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

<div class="testbar" id="testbar" hidden data-i18n="testbar">TEST MODE — bookings made now are tests only</div>
<main id="app" class="wrap">

  <section class="intro">
    <h1 data-i18n="title">Book a visit</h1>
    <p class="lead" data-i18n="lead">Visits are by advance booking only. Choose a date and a session below.</p>
  </section>

  <ol class="steps" aria-label="Steps" data-i18n-aria="a_steps">
    <li class="on" data-step="1"><span>1</span><b data-i18n="s1">Pick a date</b></li>
    <li data-step="2"><span>2</span><b data-i18n="s2">Pick a session</b></li>
    <li data-step="3"><span>3</span><b data-i18n="s3">Your details</b></li>
    <li data-step="4"><span>4</span><b data-i18n="s4">Done</b></li>
  </ol>

  <!-- STEP 1: ปฏิทิน -->
  <section class="panel" id="step-1">
    <div class="cal-head">
      <button type="button" class="nav" id="prevM" aria-label="Previous month" data-i18n-aria="a_prev">‹</button>
      <div class="mlabel"><h2 id="monthLabel">—</h2><button type="button" class="linkbtn" id="todayBtn" hidden data-i18n="back_today">Today</button></div>
      <button type="button" class="nav" id="nextM" aria-label="Next month" data-i18n-aria="a_next">›</button>
    </div>
    <div class="cal" id="cal" aria-busy="true">
      <div class="dow" id="dow"></div>
      <div class="days" id="days"></div>
    </div>
    <p class="legend">
      <span><i class="dot open"></i><span data-i18n="lg_open">Available</span></span>
      <span><i class="dot few"></i><span data-i18n="lg_few">Few left</span></span>
      <span><i class="dot full"></i><span data-i18n="lg_full">Full / closed</span></span>
    </p>
    <p class="note" id="calNote" hidden></p>
  </section>

  <!-- STEP 2: รอบ -->
  <section class="panel" id="step-2" hidden>
    <h2 id="dayLabel">—</h2>
    <div class="slots" id="slots"></div>
    <p class="note tznote" data-i18n="tz_note">All times are Thailand time (GMT+7)</p>
    <div class="wl-box" id="wlBox" hidden>
      <p class="wl-p" data-i18n="wl_p">This session is fully booked. Join the waiting list and we will email you if a place becomes available.</p>
      <form id="wlForm" novalidate>
        <div class="grid2">
          <label><span data-i18n="given">Given name</span><input name="given" type="text" required autocomplete="given-name" maxlength="100"></label>
          <label><span data-i18n="family">Family name</span><input name="family" type="text" required autocomplete="family-name" maxlength="100"></label>
        </div>
        <label><span data-i18n="f_email">Email</span><input name="email" type="email" required autocomplete="email" inputmode="email"></label>
        <label><span data-i18n="f_phone">Phone number</span><input name="phone" type="tel" required autocomplete="tel" inputmode="tel" placeholder="08x-xxx-xxxx"></label>
        <label><span data-i18n="qty">Number of visitors</span><select name="qty" id="wlQty"></select></label>
        <label class="hp" aria-hidden="true"><span>Website</span><input name="website" type="text" tabindex="-1" autocomplete="off"></label>
<?php if ($turnstile !== ''): ?>
        <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstile) ?>" data-language="auto"></div>
<?php endif; ?>
        <p class="err" id="wlErr" role="alert" hidden></p>
        <button class="primary" id="wlBtn" data-i18n="wl_btn">Join the waiting list</button>
      </form>
      <p class="okmsg" id="wlOk" hidden></p>
    </div>
    <p class="okmsg" id="wlInvite" hidden></p>
    <div class="qty">
      <label for="qty" data-i18n="qty">Number of visitors</label>
      <div class="qty-ctl">
        <button type="button" id="qMinus" aria-label="Fewer" data-i18n-aria="a_minus">−</button>
        <input id="qty" type="number" min="1" value="1" inputmode="numeric">
        <button type="button" id="qPlus" aria-label="More" data-i18n-aria="a_plus">+</button>
      </div>
      <small id="qtyHint"></small>
    </div>
    <div class="actions">
      <button type="button" class="ghost" data-back="1" data-i18n="back">Back</button>
      <button type="button" class="primary" id="toForm" disabled data-i18n="next">Next</button>
    </div>
  </section>

  <!-- STEP 3: ฟอร์ม -->
  <section class="panel" id="step-3" hidden>
    <div class="summary" id="summary"></div>
    <button type="button" class="ghost testfill" id="testFill" hidden data-i18n="testfill">⚡ Fill test data</button>
    <form id="bookForm" novalidate>
      <fieldset class="box">
        <legend data-i18n="f_contact">Contact</legend>
        <p class="hint" data-i18n="f_contact_hint">The person making this booking. Confirmation and tickets are sent to this email.</p>
        <div class="grid2">
          <label><span data-i18n="given">Given name</span><input id="cGiven" name="cgiven" type="text" required autocomplete="given-name" maxlength="100"></label>
          <label><span data-i18n="family">Family name</span><input id="cFamily" name="cfamily" type="text" required autocomplete="family-name" maxlength="100"></label>
        </div>
        <label><span data-i18n="f_email">Email</span><input id="cEmail" name="email" type="email" required autocomplete="email" inputmode="email"></label>
        <div class="pv" id="ev" hidden>
          <p class="hint" data-i18n="ev_hint"></p>
          <button type="button" class="ghost sm" id="evSend" data-i18n="ev_send"></button>
          <div class="pv-row" id="evCodeRow" hidden>
            <input id="evCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*" data-i18n-ph="vc_code" placeholder="6-digit code">
            <button type="button" class="primary sm" id="evCheck" data-i18n="vc_check">Verify</button>
          </div>
          <p class="pv-msg" id="evMsg" role="status" hidden></p>
        </div>
        <label class="news"><input id="cNews" type="checkbox" name="news"><span data-i18n="news"><?= $h(venue_fill("Yes, I want to receive {venue}'s newsletter", 'en')) ?></span></label>
        <label><span data-i18n="f_phone">Phone number</span><input id="cPhone" name="phone" type="tel" required autocomplete="tel" inputmode="tel" placeholder="08x-xxx-xxxx"></label>
        <div class="pv" id="pv" hidden>
          <p class="hint" data-i18n="pv_hint"></p>
          <button type="button" class="ghost sm" id="pvSend" data-i18n="pv_send"></button>
          <div class="pv-row" id="pvCodeRow" hidden>
            <input id="pvCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*" data-i18n-ph="vc_code" placeholder="6-digit code">
            <button type="button" class="primary sm" id="pvCheck" data-i18n="vc_check">Verify</button>
          </div>
          <p class="pv-msg" id="pvMsg" role="status" hidden></p>
        </div>
        <label><span><span data-i18n="f_org">Organization / Affiliation</span> <em data-i18n="opt">(optional)</em></span><input id="cOrg" name="corg" type="text" maxlength="190" autocomplete="organization"></label>
        <!-- honeypot -->
        <label class="hp" aria-hidden="true"><span>Website</span><input name="website" type="text" tabindex="-1" autocomplete="off"></label>
      </fieldset>

      <fieldset class="box">
        <legend data-i18n="f_att">Visitors</legend>
        <p class="hint" data-i18n="f_att_hint">Enter details for every visitor. Each ticket is issued by name.</p>
        <div id="attendees"></div>
      </fieldset>

      <label class="agree">
        <input type="checkbox" name="agree" required>
        <span data-i18n="agree">If you are <b>unable to attend</b>, <b>please cancel your booking</b> in advance or contact us so we can arrange accordingly. Please note that <b>a no-show without prior notice may affect your ability to make future bookings</b>.</span>
      </label>

<?php if ($turnstile !== ''): ?>
      <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstile) ?>" data-language="auto"></div>
<?php endif ?>
      <p class="err" id="formErr" role="alert" hidden></p>

      <div class="actions">
        <button type="button" class="ghost" data-back="2" data-i18n="back">Back</button>
        <button type="submit" class="primary" id="submitBtn" data-i18n="confirm">Confirm booking</button>
      </div>
    </form>
  </section>

  <!-- STEP 4: สำเร็จ -->
  <section class="panel done" id="step-4" hidden>
    <div class="check" id="doneIcon">✓</div>
    <h2 id="doneH" data-i18n="done_h">Booking confirmed</h2>
    <p class="code"><span data-i18n="done_code">Booking code</span> <b id="orderCode">—</b></p>
    <p id="doneText"></p>
    <p class="pending-note" id="donePending" hidden></p>
    <p class="hint" id="doneMail" data-i18n="done_mail">Your ticket (QR code) has been emailed to you. Please show the QR when you arrive. If you cannot find the email, check your spam folder.</p>
    <div class="actions center">
      <a class="primary" id="orderLink" href="#" data-i18n="done_link">View / cancel booking</a>
      <button type="button" class="ghost" id="again" data-i18n="again">Book another</button>
    </div>
    <p class="cal-links" id="calLinks" hidden><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg><a id="icsLink" href="#" data-i18n="cal_ics">Add to calendar</a> · <a id="gcalLink" href="#" target="_blank" rel="noopener">Google Calendar</a></p>
  </section>

  <section class="info">
    <h3 data-i18n="i_h">Please note</h3>
    <ul>
      <li data-i18n="i_1">If you are <b>unable to attend</b>, <b>please cancel your booking</b> in advance or contact us so we can arrange accordingly.</li>
      <li data-i18n="i_2">Please note that <b>a no-show without prior notice may affect your ability to make future bookings</b>.</li>
      <li data-i18n="i_3">For <b>schools, organizations or group visits</b>, appointments can be arranged by contacting us at: <a href="mailto:<?= $h($V['group_email']) ?>"><b><?= $h($V['group_email']) ?></b></a></li>
    </ul>
    <p class="contact"><span data-i18n="i_c">If you encounter any problems, please feel free to contact us:</span><br><a href="mailto:<?= $h($V['email']) ?>"><?= $h($V['email']) ?></a> | <a href="tel:<?= $h(venue_tel()) ?>"><?= $h($V['phone']) ?></a><br><a href="<?= $h($V['website']) ?>" target="_blank" rel="noopener"><?= $h($V['website']) ?></a></p>
  </section>

  <section class="panel resend" id="resendBox">
    <h3 data-i18n="rs_h">If you have already booked</h3>
    <p class="hint" data-i18n="rs_p">To view or cancel your booking, use the link in your confirmation email. If you cannot find it, enter the email address you booked with and we will send the link again.</p>
    <form id="resendForm" class="rs-row" novalidate>
      <input type="email" name="email" required autocomplete="email" inputmode="email" placeholder="email@example.com">
      <button class="ghost" data-i18n="rs_btn">Send my booking link</button>
    </form>
    <p class="note" id="rsMsg" hidden></p>
  </section>
</main>

<footer class="site">
  <span>© <?= $h($V['name_en']) ?> · <a href="<?= $h($V['privacy']) ?>" target="_blank" rel="noopener" data-i18n="privacy">Privacy Policy</a></span>
  <a class="staff-link" href="admin/" data-i18n="staff">Staff</a>
</footer>
<a class="admin-fab" id="adminFab" href="admin/" hidden>หลังบ้าน</a>
<script>try { if (localStorage.getItem('vb-admin') === '1') document.getElementById('adminFab').hidden = false; } catch (_) {}</script>

<?php if ($turnstile !== ''): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif ?>
<script>window.MUSEUM = <?= json_encode(['name' => ['th' => venue_name('th'), 'en' => venue_name('en')], 'group_email' => $V['group_email']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<?= text_overrides_js('I18N_BOOKING') ?>
<script src="assets/i18n-booking.js?v=<?= $v ?>" defer></script>
<script src="assets/app.js?v=<?= $v ?>" defer></script>
<script src="assets/ptr.js?v=<?= $v ?>" defer></script>
</body>
</html>
