<?php
declare(strict_types=1);
/**
 * Template อีเมลของเรา (ไทย/อังกฤษ) — ข้อความส่วนใหญ่ใช้ชุดเดียวกับหน้าเว็บ (assets/app.js I18N)
 *   booking_email()  ยืนยันการจอง + QR ตั๋วทุกใบ (ส่งผู้ติดต่อ) หรือเฉพาะใบของผู้เข้าชม (ส่งผู้เข้าชมที่มีอีเมลของตัวเอง)
 *   cancel_email()   ยืนยันการยกเลิก
 * ดีไซน์: ตาราง + inline style (รองรับ Gmail/Outlook/Apple Mail), สีตามหน้าเว็บ, โลโก้และ QR แนบเป็น cid:
 */

require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/pkpass.php';
require_once __DIR__ . '/gwallet.php';

require_once __DIR__ . '/venue.php';   // ชื่อ ที่อยู่ แผนที่ ติดต่อ — แก้ได้ในหลังบ้าน

require_once __DIR__ . '/texts-default.php';   // MAIL_T + noshow_texts_default() — ข้อความเริ่มต้น (แก้ได้ในหลังบ้าน)

function mail_t(string $lang, string $key)
{
    if ($key === 'sign') {   // ลงท้ายอีเมล = ชื่อสถานที่ (หลังบ้าน → ข้อมูลสถานที่)
        return venue_name($lang);
    }
    $v = text_override('mail', $lang, $key) ?? (MAIL_T[$lang][$key] ?? MAIL_T['en'][$key]);   // แก้ในหลังบ้าน (ตั้งค่า → ข้อความ) ก่อน
    return is_string($v) ? venue_fill($v, $lang) : $v;
}

function public_url(string $path = ''): string
{
    $base = rtrim((string) (cfg()['public_url'] ?? ''), '/');
    if ($base === '') {
        $base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $base . '/' . ltrim($path, '/');
}

/** ['date'=>'Y-m-d','start'=>'H:i','end'=>'H:i'] → ['วันอาทิตย์ที่ 25 ตุลาคม 2569', '10:00–12:00 น.'] */
function mail_fmt_slot(array $slot, string $lang): array
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $slot['date']);
    $dow = mail_t($lang, 'dows')[(int) $d->format('w')];
    $mon = mail_t($lang, 'months')[(int) $d->format('n') - 1];
    $date = $lang === 'th'
        ? sprintf('%sที่ %d %s %d', $dow, (int) $d->format('j'), $mon, (int) $d->format('Y') + 543)
        : sprintf('%s, %d %s %s', $dow, (int) $d->format('j'), $mon, $d->format('Y'));
    $time = $slot['start'] . ($slot['end'] ? '–' . $slot['end'] : '') . ($lang === 'th' ? ' น.' : '');
    return [$date, $time];
}

/** ISO → 'วันศุกร์ที่ 23 ตุลาคม 2569 เวลา 23:59 น.' (00:00 แสดงเป็น 23:59 ของวันก่อน — ไม่ให้งงว่าวันไหน) */
function mail_fmt_dt(string $iso, string $lang): string
{
    $d = (new DateTimeImmutable($iso))->setTimezone(tz());
    if ($d->format('H:i') === '00:00') {
        $d = $d->modify('-1 minute');
    }
    [$date] = mail_fmt_slot(['date' => $d->format('Y-m-d'), 'start' => '', 'end' => ''], $lang);
    return $lang === 'th' ? $date . ' เวลา ' . $d->format('H:i') . ' น.' : $date . ', ' . $d->format('H:i');
}

function mail_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** โครงอีเมล (หัวโลโก้ + การ์ดเนื้อหา + ท้าย) */
function mail_layout(string $lang, string $preheader, string $content): string
{
    $font = "'Noto Sans Thai','Sukhumvit Set','Thonburi',Tahoma,Helvetica,Arial,sans-serif";
    $foot = mail_esc(mail_t($lang, 'i_c'));
    $vName = mail_esc(venue_name('en'));
    $vNameTh = mail_esc(venue_name('th'));
    $vEmail = mail_esc(venue()['email']);
    $vPhone = mail_esc(venue()['phone']);
    $vTel = mail_esc(venue_tel());
    $vWeb = mail_esc(venue()['website']);
    $vWebL = mail_esc(venue_site_label());
    return <<<HTML
<!doctype html>
<html lang="$lang"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title></title></head>
<body style="margin:0;padding:0;background:#f7f4ee;-webkit-text-size-adjust:100%">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">{$preheader}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f7f4ee">
<tr><td align="center" style="padding:24px 12px">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;font-family:$font;color:#141210">
    <tr><td align="center" style="padding:4px 0 18px">
      <img src="cid:logo" width="92" alt="{$vName}" style="display:block;border:0;width:92px;height:auto">
      <div style="font-size:15px;font-weight:600;letter-spacing:.02em;margin-top:8px">{$vNameTh}</div>
    </td></tr>
    <tr><td style="background:#ffffff;border:1px solid #e4ddd2;border-radius:14px;padding:28px 24px">
      $content
    </td></tr>
    <tr><td align="center" style="padding:20px 8px 8px;font-size:13px;line-height:1.7;color:#6e675e">
      $foot<br>
      <a href="mailto:{$vEmail}" style="color:#6e675e">{$vEmail}</a> · <a href="tel:{$vTel}" style="color:#6e675e">{$vPhone}</a><br>
      <a href="{$vWeb}" style="color:#6e675e">{$vWebL}</a>
    </td></tr>
  </table>
</td></tr>
</table>
</body></html>
HTML;
}

function mail_button(string $href, string $label): string
{
    $h = mail_esc($href);
    return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:22px auto 4px"><tr>'
        . '<td style="background:#141210;border-radius:8px"><a href="' . $h . '" style="display:inline-block;padding:13px 26px;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px">'
        . mail_esc($label) . '</a></td></tr></table>';
}

/** ปุ่มคู่: หลัก (ดำ) + รอง (ขอบ) — เช่น "ยืนยันว่าจะมา" / "ยกเลิกการจอง" */
function mail_buttons2(string $href1, string $label1, string $href2, string $label2): string
{
    // inline-block → จอแคบขึ้นบรรทัดใหม่ได้
    $cell = fn($h, $l, $primary) => '<a href="' . mail_esc($h) . '" style="display:inline-block;margin:4px 5px;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600;font-size:15px;white-space:nowrap;'
        . ($primary ? 'background:#141210;color:#ffffff;border:1px solid #141210' : 'background:#ffffff;color:#a3281d;border:1px solid #d9b3ad') . '">' . mail_esc($l) . '</a>';
    return '<div style="margin:16px 0 4px;text-align:center">' . $cell($href1, $label1, true) . $cell($href2, $label2, false) . '</div>';
}

/** สรุปวัน/เวลา/จำนวน + รหัสการจอง */
function mail_summary(string $lang, string $code, array $slot, ?int $qty): string
{
    [$date, $time] = mail_fmt_slot($slot, $lang);
    $rows = '<tr><td style="padding:3px 0;font-size:16px;font-weight:600">' . mail_esc($date) . '</td></tr>'
        . '<tr><td style="padding:3px 0;font-size:16px">' . mail_esc($time)
        . ($qty ? ' &nbsp;·&nbsp; ' . mail_esc(sprintf(mail_t($lang, 'people'), $qty)) : '') . '</td></tr>';
    return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#efe9df;border-radius:10px">'
        . '<tr><td style="padding:14px 16px"><table role="presentation" cellspacing="0" cellpadding="0" border="0">' . $rows . '</table>'
        . '<div style="margin-top:10px;font-size:13px;color:#6e675e">' . mail_esc(mail_t($lang, 'code'))
        . ' <span style="font-family:Menlo,Consolas,monospace;font-size:15px;font-weight:700;letter-spacing:.08em;color:#141210;background:#ffffff;border-radius:6px;padding:2px 8px">'
        . mail_esc($code) . '</span></div></td></tr></table>';
}

/** ย่อหน้าข้อความ (กลาง) — $html = ข้อความคงที่ของเรา (มี <br>/<b>) */
function mail_p(string $html, string $style = ''): string
{
    return '<p style="margin:0 0 10px;font-size:15px;line-height:1.65;text-align:center;' . $style . '">' . $html . '</p>';
}

/** คำทักทาย + ประโยคเปิด (ข้อความเดิมจาก Pretix) */
function mail_intro(string $lang, string $key, ?string $name = null, string ...$args): string
{
    $greet = $name ? sprintf(mail_t($lang, 'greet_name'), mail_esc($name)) : mail_t($lang, 'greet');
    $ev = mail_t($lang, 'event');
    return mail_p($greet, 'margin-bottom:6px') . mail_p(sprintf(mail_t($lang, $key), mail_esc($ev), ...array_map('mail_esc', $args)), 'margin-bottom:18px');
}

/** ปิดท้าย: หากมีข้อสงสัย… + ขอแสดงความนับถือ (ข้อความเดิมจาก Pretix) */
function mail_closing(string $lang, bool $resend = false): string
{
    return '<p style="margin:22px 0 0;font-size:14.5px;line-height:1.7">' . mail_esc(mail_t($lang, $resend ? 'questions_resend' : 'questions')) . '</p>'
        . '<p style="margin:12px 0 0;font-size:14.5px;line-height:1.6">' . mail_esc(mail_t($lang, 'regards')) . '<br><b>' . mail_esc(mail_t($lang, 'sign')) . '</b></p>';
}

function mail_notes(string $lang): string
{
    $li = '';
    foreach (['i_1', 'i_2', 'i_3'] as $k) {
        $li .= '<li style="margin:0 0 6px">' . mail_t($lang, $k) . '</li>';
    }
    return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:24px;border-left:4px solid #a3281d;background:#f7f4ee;border-radius:8px">'
        . '<tr><td style="padding:14px 16px;font-size:13.5px;line-height:1.65;color:#6e675e">'
        . '<div style="font-weight:700;color:#141210;font-size:14px;margin-bottom:6px">' . mail_esc(mail_t($lang, 'i_h')) . '</div>'
        . '<ul style="margin:0;padding-left:18px">' . $li . '</ul></td></tr></table>';
}

/**
 * อีเมลยืนยันการจอง
 * $tickets = [['n'=>1,'name'=>'…','secret'=>'…'], …]  (ทุกใบ = อีเมลผู้ติดต่อ, ใบเดียว = อีเมลถึงผู้เข้าชม)
 * คืน [subject, html, images]
 */
function booking_email(string $lang, string $code, array $slot, array $tickets, string $manageUrl, int $qty, bool $forAttendee = false, bool $reminder = false): array
{
    [$date, $time] = mail_fmt_slot($slot, $lang);
    $images = ['logo' => venue_logo_bytes()];
    $cards = '';
    foreach ($tickets as $t) {
        $cid = 'qr' . (int) $t['n'];
        $images[$cid] = qr_png((string) $t['secret'], 8);
        $cards .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:14px;border:1px solid #e4ddd2;border-radius:12px">'
            . '<tr><td align="center" style="padding:16px 12px 14px">'
            . '<div style="font-size:12px;color:#6e675e;letter-spacing:.04em">' . mail_esc(sprintf(mail_t($lang, 'visitor'), (int) $t['n'])) . '</div>'
            . '<div style="font-size:18px;font-weight:600;margin:2px 0 10px">' . mail_esc((string) $t['name']) . '</div>'
            . '<img src="cid:' . $cid . '" width="200" height="200" alt="QR" style="display:block;border:0;width:200px;height:200px;margin:0 auto">'
            . '<div style="font-family:Menlo,Consolas,monospace;font-size:12px;color:#6e675e;margin-top:6px">' . mail_esc($code) . '-' . (int) $t['n'] . '</div>'
            . ((wallet_enabled() || gwallet_enabled()) && str_contains($manageUrl, 'manage.php?o=')
                // ป้ายทางการ Apple Wallet / Google Wallet (PNG จาก SVG ทางการ — อีเมลแสดง SVG ไม่ได้) วางคู่กัน
                ? '<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:10px auto 0"><tr>'
                    . (wallet_enabled() ? '<td style="padding:0 4px"><a href="' . mail_esc(str_replace('manage.php?', 'api/pass.php?', $manageUrl) . '&n=' . (int) $t['n'] . '&lang=' . $lang) . '" style="display:inline-block">'
                        . '<img src="cid:awallet" width="111" height="35" alt="' . mail_esc(mail_t($lang, 'wallet')) . '" style="display:block;border:0;width:111px;height:35px"></a></td>' : '')
                    . (gwallet_enabled() ? '<td style="padding:0 4px"><a href="' . mail_esc(str_replace('manage.php?', 'api/gpass.php?', $manageUrl) . '&n=' . (int) $t['n'] . '&lang=' . $lang) . '" style="display:inline-block">'
                        . '<img src="cid:gwallet" width="127" height="35" alt="' . mail_esc($lang === 'th' ? 'เพิ่มลงใน Google Wallet' : 'Add to Google Wallet') . '" style="display:block;border:0;width:127px;height:35px"></a></td>' : '')
                    . '</tr></table>'
                : '')
            . '</td></tr></table>';
    }
    $h = mail_t($lang, $reminder ? 'remind_h' : ($forAttendee ? 'ticket_h' : 'done_h'));
    $content = '<div style="text-align:center">'
        . ($reminder ? '' : '<div style="display:inline-block;width:48px;height:48px;line-height:48px;border-radius:50%;background:#2f6b3a;color:#ffffff;font-size:26px;font-weight:700">✓</div>')
        . '<h1 style="font-size:24px;line-height:1.3;margin:12px 0 14px;font-weight:700">' . mail_esc($h) . '</h1></div>'
        . mail_intro($lang, $reminder ? 'p_remind' : ($forAttendee ? 'p_ticket' : 'p_booked'), $forAttendee && $tickets ? (string) $tickets[0]['name'] : null)
        . mail_summary($lang, $code, $slot, $forAttendee ? null : $qty)
        . '<p style="margin:12px 0 0;font-size:13px;line-height:1.6;color:#6e675e;text-align:center">📍 ' . mail_esc(venue_address($lang))
        . ' · <a href="' . venue()['maps'] . '" style="color:#141210">' . ($lang === 'th' ? 'แผนที่' : 'Map') . '</a></p>'
        . ($reminder ? '<p style="margin:16px 0 0;padding:12px 14px;border-radius:10px;background:#f6e3e0;color:#141210;font-size:14.5px;line-height:1.6;text-align:center">' . mail_t($lang, $lang === 'th' ? 'i_2' : 'i_1') . '</p>' : '')
        . ($reminder ? '<p style="margin:18px 0 0;text-align:center;font-size:15px">' . mail_esc(mail_t($lang, 'attend_p')) . '</p>'
            . mail_buttons2($manageUrl . '&attend=1&lang=' . $lang, mail_t($lang, 'attend_btn'), $manageUrl . '&cancel=1&lang=' . $lang, mail_t($lang, 'cancel_btn')) : '')
        . '<p style="margin:20px 0 0;text-align:center;font-size:15px;font-weight:600">' . mail_esc(mail_t($lang, 'show_qr')) . '</p>'
        . $cards
        . '<p style="margin:22px 0 0;text-align:center;font-size:14px;color:#6e675e">' . mail_esc(mail_t($lang, 'view_at')) . '</p>'
        . str_replace('margin:22px auto 4px', 'margin:8px auto 4px', mail_button($manageUrl, mail_t($lang, $forAttendee ? 'manage_ticket' : 'manage')))
        // ปุ่มปฏิทินขอบบาง 2 ปุ่ม (ไม่มีป้ายทางการ)
        . (!empty($slot['starts_at']) && str_contains($manageUrl, 'manage.php?o=') ? (function () use ($manageUrl, $lang, $code, $slot, $qty) {
            // inline-block (ไม่ใช่ช่องตาราง) → จอแคบปุ่มขึ้นบรรทัดใหม่ได้ ไม่ดันอีเมลให้กว้างเกินจอ
            $cell = fn($h, $l) => '<a href="' . mail_esc($h) . '" style="display:inline-block;margin:4px;padding:9px 14px;border:1px solid #d8d1c5;border-radius:8px;background:#ffffff;color:#141210;text-decoration:none;font-size:13.5px;font-weight:600;white-space:nowrap">&#128197;&nbsp;' . mail_esc($l) . '</a>';
            return '<div style="margin:12px 0 0;text-align:center">'
                . $cell(str_replace('manage.php?', 'api/ics.php?', $manageUrl) . '&lang=' . $lang, mail_t($lang, 'cal_ics'))
                . $cell(google_calendar_url($code, $slot['starts_at'], $slot['ends_at'] ?? null, $qty, $manageUrl, $lang), mail_t($lang, 'cal_g'))
                . '</div>';
        })() : '')
        . mail_notes($lang)
        . mail_closing($lang);
    $subject = sprintf(mail_t($lang, $reminder ? 'remind_subject' : ($forAttendee ? 'ticket_subject' : 'booked_subject')), "$date $time", $code);
    // แนบตั๋ว PDF (A6 คนละหน้า) — สร้างไม่สำเร็จก็ยังส่งอีเมลได้ (มี QR ในเนื้ออีเมลอยู่แล้ว)
    if ($tickets && !str_starts_with($code, 'PREVW')) {
        try {
            require_once __DIR__ . '/ticketpdf.php';
            $images[venue_slug() . '-ticket-' . $code . (count($tickets) === 1 && $forAttendee ? '-' . (int) $tickets[0]['n'] : '') . '.pdf'] = ticket_pdf($lang, $code, $slot, $tickets);
        } catch (Throwable $e) {
            error_log('ticket pdf: ' . $e->getMessage());
        }
    }
    if (wallet_enabled() && str_contains($manageUrl, 'manage.php?o=')) {
        $images['awallet'] = (string) @file_get_contents(__DIR__ . '/../assets/brand/wallet/apple-wallet-' . ($lang === 'th' ? 'th' : 'en') . '@2x.png');
    }
    if (gwallet_enabled() && str_contains($manageUrl, 'manage.php?o=')) {
        $images['gwallet'] = (string) @file_get_contents(__DIR__ . '/../assets/brand/wallet/google-wallet-' . ($lang === 'th' ? 'th' : 'en') . '@2x.png');
    }
    // ไม่แนบ .ics (คนส่วนใหญ่ไม่รู้จักไฟล์ — ผู้ใช้เลือก) ใช้ลิงก์ "เพิ่มลงในปฏิทิน · Google Calendar" ในอีเมลแทน
    return [$subject, mail_layout($lang, mail_esc("$date $time · $code"), $content), $images];
}

/** อีเมลยืนยันการยกเลิก */
function cancel_email(string $lang, string $code, ?array $slot, int $qty, string $reason = ''): array
{
    $images = ['logo' => venue_logo_bytes()];
    $content = '<h1 style="font-size:22px;line-height:1.35;margin:0 0 14px;font-weight:700;text-align:center">' . mail_esc(mail_t($lang, 'cancel_h')) . '</h1>'
        . mail_intro($lang, 'p_cancel', null, $code)
        // เหตุผลจากสถานที่ (แอดมินเลือกให้แสดง เช่น ไฟดับ)
        . ($reason !== '' ? '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:6px 0 14px;border-left:4px solid #a3281d;background:#f7f4ee;border-radius:8px"><tr><td style="padding:12px 16px;font-size:14.5px;line-height:1.65;text-align:left">'
            . '<b>' . ($lang === 'th' ? 'เหตุผล' : 'Reason') . ':</b> ' . nl2br(mail_esc($reason)) . '</td></tr></table>' : '')
        . ($slot ? mail_summary($lang, $code, $slot, $qty) : '')
        . mail_button(public_url('/'), mail_t($lang, 'again'))
        . mail_closing($lang);
    return [sprintf(mail_t($lang, 'cancel_subject'), $code), mail_layout($lang, mail_esc($code), $content), $images];
}

// ---------- ปฏิทิน ----------

function ics_escape(string $s): string
{
    return str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $s);
}

/** ไฟล์ .ics ของการจอง (1 event ต่อรอบ) */
function booking_ics(string $code, array $slot, string $startsAt, ?string $endsAt, int $qty, string $manageUrl, string $lang): string
{
    $utc = new DateTimeZone('UTC');
    $st = (new DateTimeImmutable($startsAt))->setTimezone($utc);
    $en = $endsAt ? (new DateTimeImmutable($endsAt))->setTimezone($utc) : $st->modify('+2 hours');
    $title = ($lang === 'th' ? 'เข้าชม ' : 'Visit: ') . venue_name($lang);
    $desc = sprintf(mail_t($lang, 'people'), $qty) . ' · ' . mail_t($lang, 'code') . " $code\n" . mail_t($lang, 'show_qr') . "\n$manageUrl\n" . venue()['maps'];
    $loc = venue_address($lang);
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//' . venue_name('en') . '//Visit booking//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:' . $code . '@' . preg_replace('/^[^.]+\.(?=[^.]+\.[^.]+$)/', '', (string) (parse_url(public_url('/'), PHP_URL_HOST) ?: 'localhost')),   // visit.example.com → example.com (คงรูปแบบเดิม — UID เปลี่ยน = ปฏิทินขึ้นนัดซ้ำ)
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . $st->format('Ymd\THis\Z'),
        'DTEND:' . $en->format('Ymd\THis\Z'),
        'SUMMARY:' . ics_escape($title),
        'DESCRIPTION:' . ics_escape($desc),
        'LOCATION:' . ics_escape($loc),
        'URL:' . $manageUrl,
        'BEGIN:VALARM', 'TRIGGER:-P1D', 'ACTION:DISPLAY', 'DESCRIPTION:' . ics_escape($title), 'END:VALARM',
        'END:VEVENT', 'END:VCALENDAR',
    ];
    // ตัดบรรทัดยาว 75 octets ตาม RFC 5545
    $out = '';
    foreach ($lines as $l) {
        while (strlen($l) > 75) {
            $cut = 75;
            while ($cut > 0 && (ord($l[$cut]) & 0xC0) === 0x80) {
                $cut--;   // อย่าตัดกลางตัวอักษร UTF-8
            }
            $out .= substr($l, 0, $cut) . "\r\n ";
            $l = substr($l, $cut);
        }
        $out .= $l . "\r\n";
    }
    return $out;
}

/** ลิงก์เพิ่มใน Google Calendar */
function google_calendar_url(string $code, string $startsAt, ?string $endsAt, int $qty, string $manageUrl, string $lang): string
{
    $utc = new DateTimeZone('UTC');
    $st = (new DateTimeImmutable($startsAt))->setTimezone($utc);
    $en = $endsAt ? (new DateTimeImmutable($endsAt))->setTimezone($utc) : $st->modify('+2 hours');
    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action'   => 'TEMPLATE',
        'text'     => ($lang === 'th' ? 'เข้าชม ' : 'Visit: ') . venue_name($lang),
        'dates'    => $st->format('Ymd\THis\Z') . '/' . $en->format('Ymd\THis\Z'),
        'details'  => sprintf(mail_t($lang, 'people'), $qty) . ' · ' . mail_t($lang, 'code') . " $code\n$manageUrl",
        'location' => venue_address($lang),
    ]);
}

/** อีเมลรวมลิงก์การจองที่ยังไม่ถึงวัน (ขอส่งลิงก์อีกครั้ง) — $items = [['code','slot','qty','url'], ...] */
function resend_email(string $lang, array $items): array
{
    $images = ['logo' => venue_logo_bytes()];
    $rows = '';
    foreach ($items as $it) {
        $label = !empty($it['ticket']) ? mail_t($lang, 'manage_ticket') : mail_t($lang, 'manage');
        $who = !empty($it['name']) ? '<p style="margin:0 0 6px;font-size:14px;color:#6e675e">' . mail_esc($it['name']) . '</p>' : '';
        $rows .= '<div style="margin-top:14px">' . $who . mail_summary($lang, $it['code'], $it['slot'], $it['qty']) . mail_button($it['url'], $label) . '</div>';
    }
    $content = '<h1 style="font-size:22px;line-height:1.35;margin:0 0 14px;font-weight:700;text-align:center">' . mail_esc(mail_t($lang, 'resend_h')) . '</h1>'
        . mail_intro($lang, 'p_resend') . $rows . mail_notes($lang) . mail_closing($lang, true);
    return [mail_t($lang, 'resend_subject'), mail_layout($lang, mail_esc(mail_t($lang, 'resend_subject')), $content), $images];
}

/** แจ้งผู้ติดต่อเมื่อผู้เข้าชมยกเลิกตั๋วของตัวเอง */
function attendee_cancel_email(string $lang, string $code, string $name, array $slot, int $left, string $manageUrl): array
{
    $images = ['logo' => venue_logo_bytes()];
    $content = '<h1 style="font-size:22px;line-height:1.35;margin:0 0 18px;font-weight:700;text-align:center">' . mail_esc(sprintf(mail_t($lang, 'att_cancel_h'), $name)) . '</h1>'
        . mail_summary($lang, $code, $slot, $left ?: null)
        . '<p style="margin:16px 0 0;text-align:center;font-size:15px">' . mail_esc($left ? sprintf(mail_t($lang, 'att_cancel_left'), $left) : mail_t($lang, 'att_cancel_none')) . '</p>'
        . ($left ? mail_button($manageUrl, mail_t($lang, 'manage')) : mail_button(public_url('/'), mail_t($lang, 'again')));
    return [sprintf(mail_t($lang, 'att_cancel_subject'), $code), mail_layout($lang, mail_esc($code), $content), $images];
}

/** ขอให้ยืนยันการจอง (ยังไม่มี QR — ส่งตั๋วหลังยืนยัน) */
function confirm_request_email(string $lang, string $code, array $slot, int $qty, array $names, string $confirmUrl, string $confirmBy): array
{
    [$date, $time] = mail_fmt_slot($slot, $lang);
    $images = ['logo' => venue_logo_bytes()];
    $list = '';
    foreach ($names as $i => $n) {
        $list .= '<li style="margin:0 0 2px">' . mail_esc($n) . '</li>';
    }
    $content = '<h1 style="font-size:24px;line-height:1.3;margin:0 0 18px;font-weight:700;text-align:center">' . mail_esc(mail_t($lang, 'confirm_h')) . '</h1>'
        . mail_summary($lang, $code, $slot, $qty)
        . '<ol style="margin:12px 0 0;padding-left:22px;font-size:14.5px;line-height:1.6">' . $list . '</ol>'
        . '<p style="margin:18px 0 0;text-align:center;font-size:15px;line-height:1.6">' . mail_esc(mail_t($lang, 'confirm_p')) . '</p>'
        . mail_button($confirmUrl, mail_t($lang, 'confirm_btn'))
        . '<p style="margin:14px 0 0;padding:12px 14px;border-radius:10px;background:#f6e3e0;color:#141210;font-size:14px;line-height:1.6;text-align:center">'
        . sprintf(mail_t($lang, 'confirm_by'), mail_esc(mail_fmt_dt($confirmBy, $lang))) . '</p>'
        . mail_notes($lang);
    return [sprintf(mail_t($lang, 'confirm_subject'), "$date $time", $code), mail_layout($lang, mail_esc("$date $time · $code"), $content), $images];
}

/** การจองถูกยกเลิกเพราะไม่ได้ยืนยันภายในเวลา */
function expired_email(string $lang, string $code, array $slot, int $qty): array
{
    $images = ['logo' => venue_logo_bytes()];
    $content = '<h1 style="font-size:22px;line-height:1.35;margin:0 0 18px;font-weight:700;text-align:center">' . mail_esc(mail_t($lang, 'expired_h')) . '</h1>'
        . mail_summary($lang, $code, $slot, $qty)
        . mail_button(public_url('/'), mail_t($lang, 'again'));
    return [sprintf(mail_t($lang, 'expired_subject'), $code), mail_layout($lang, mail_esc($code), $content), $images];
}

/**
 * อีเมลแจ้งผู้ที่จองแต่ไม่มาเข้าชม — ข้อความจากผู้ใช้ (2026-10-18) ไม่แก้ถ้อยคำ
 * แยกภาษาตามผู้จอง (ผู้ใช้กำหนด) · รูปแบบเดียวกับอีเมลอื่น (จัดกลาง, ท้ายอีเมลใช้ footer ของ layout) + ปุ่มจองครั้งถัดไป / ติดต่อ
 */
/** ข้อความอีเมลแจ้งไม่มาที่แก้ในหลังบ้าน (scope noshow; p1/p2 = ย่อหน้า 1/2) — ตัวหนา (bold) ยังใช้วลีเดิม */
function noshow_texts_apply(array $T, string $lang): array
{
    foreach (['h', 'greet', 'warn', 'next', 'btn', 'contact', 'thanks', 'regards'] as $k) {
        if (($o = text_override('noshow', $lang, $k)) !== null) {
            $T[$k] = $o;
        }
    }
    foreach ([0, 1] as $i) {
        if (($o = text_override('noshow', $lang, 'p' . ($i + 1))) !== null) {
            $T['p'][$i] = $o;
        }
    }
    array_walk_recursive($T, function (&$v) use ($lang) {
        $v = is_string($v) ? venue_fill($v, $lang) : $v;
    });
    return $T;
}


function noshow_email(string $lang = 'th', ?array $slot = null, int $qty = 0, string $code = ''): array
{
    $th = $lang === 'th';
    $url = rtrim(public_url(), '/');
    $T = noshow_texts_apply(noshow_texts_default($lang), $lang);
    $p = fn(string $h, string $st = '') => mail_p($h, $st);   // จัดกลางแบบอีเมลอื่น
    $c = '<h1 style="font-size:21px;line-height:1.4;margin:0 0 16px;font-weight:700;text-align:center">' . mail_esc($T['h']) . '</h1>';
    if ($slot) {   // รอบที่ไม่ได้มา
        [$date, $time] = mail_fmt_slot($slot, $lang);
        $c .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#efe9df;border-radius:10px;margin:0 0 18px"><tr><td style="padding:12px 16px;font-size:15px;color:#6e675e">'
            . '<b style="color:#141210">' . mail_esc($date) . '</b><br>' . mail_esc($time) . ($qty ? ' &nbsp;·&nbsp; ' . mail_esc(sprintf(mail_t($lang, 'people'), $qty)) : '') . ($code ? ' &nbsp;·&nbsp; ' . mail_esc($code) : '')
            . '</td></tr></table>';
    }
    // ตัวหนาเฉพาะประเด็นสำคัญ (ประโยคยาว — ผู้ใช้ขอ) ไม่แก้ถ้อยคำ
    $hl = fn(string $x) => strtr(mail_esc($x), array_combine(array_map('mail_esc', $T['bold']), array_map(fn($k) => '<b>' . mail_esc($k) . '</b>', $T['bold'])));
    $c .= $p(mail_esc($T['greet']));
    foreach ($T['p'] as $x) {
        $c .= $p($hl($x));
    }
    // คำเตือน: กล่องแบบ "ข้อควรทราบ" ของอีเมลอื่น
    $c .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:8px 0 18px;border-left:4px solid #a3281d;background:#f7f4ee;border-radius:8px">'
        . '<tr><td style="padding:12px 16px;font-size:14px;line-height:1.65;color:#6e675e;text-align:left">' . $hl($T['warn']) . '</td></tr></table>';
    $c .= $p(mail_esc($T['next']) . ' <a href="' . mail_esc($url) . '" style="color:#141210">' . mail_esc(preg_replace('#^https?://#', '', $url)) . '</a>', 'margin-bottom:4px');
    $cell = fn($h, $l, $primary) => '<td style="padding:4px 5px"><a href="' . mail_esc($h) . '" style="display:inline-block;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600;font-size:15px;'
        . ($primary ? 'background:#141210;color:#ffffff;border:1px solid #141210' : 'background:#ffffff;color:#141210;border:1px solid #d8d1c5') . '">' . mail_esc($l) . '</a></td>';
    // ปุ่มเรียงแนวตั้ง (จอมือถือไม่ตัดคำในปุ่ม)
    $c .= '<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:14px auto 4px"><tr>' . $cell($url, $T['btn'], true) . '</tr></table>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:2px auto 8px"><tr>'
        . $cell('mailto:' . (cfg()['mail_reply_to'] ?? cfg()['mail_from'] ?? venue()['email']), $T['contact'], false) . '</tr></table>';
    $c .= '<p style="margin:22px 0 0;font-size:14.5px;line-height:1.7">' . mail_esc($T['thanks']) . '</p>'
        . '<p style="margin:12px 0 0;font-size:14.5px;line-height:1.6">' . mail_esc($T['regards']) . '<br><b>' . mail_esc(venue_name($lang)) . '</b></p>';
    $subject = $T['h'];   // หัวเรื่องแยกภาษา (ผู้ใช้กำหนด)
    return [$subject, mail_layout($lang, mail_esc($T['h']), $c), ['logo' => venue_logo_bytes()]];
}
