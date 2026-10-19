<?php
declare(strict_types=1);
/**
 * Waiting list — รอบเต็ม (ตามความจุที่ไม่เปิดเผย) → คนนอกลงชื่อรอได้, แจ้งแอดมิน
 * แอดมินกด "ให้สิทธิ์จอง" → อีเมลลิงก์ index.php?wl=TOKEN (อายุ waitlist_hours ชม.) จองได้แม้รอบเต็ม ไม่เกินจำนวนที่ลงชื่อไว้
 * ไม่ให้สิทธิ์อัตโนมัติ (ผู้ใช้กำหนด: แอดมินตัดสินใจว่าจะยอมให้มาเพิ่มไหม)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/venue.php';
require_once __DIR__ . '/emails.php';

function waitlist_on(): bool
{
    return setting('waitlist_on', '1') === '1';
}

/** รายการที่ใช้สิทธิ์ได้ (invited และยังไม่หมดอายุ) จาก token */
function waitlist_by_token(string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9]{20,64}$/', $token)) {
        return null;
    }
    $st = db()->prepare("SELECT w.*, s.starts_at, s.ends_at FROM waitlist w JOIN sessions s ON s.id = w.session_id WHERE w.token = ? AND w.status = 'invited'");
    $st->execute([$token]);
    $w = $st->fetch();
    if (!$w || new DateTimeImmutable((string) $w['expires_at']) <= new DateTimeImmutable('now', tz())
        || new DateTimeImmutable($w['starts_at']) <= new DateTimeImmutable('now', tz())) {
        return null;
    }
    return $w;
}

function waitlist_expire(): void
{
    db()->prepare("UPDATE waitlist SET status = 'expired' WHERE status = 'invited' AND expires_at < ?")
        ->execute([(new DateTimeImmutable('now', tz()))->format(DATE_ATOM)]);
}

/** อีเมลถึงผู้ลงชื่อรอ: $kind = joined (ลงชื่อแล้ว) | invited (ได้สิทธิ์จอง) */
function waitlist_email(string $kind, array $w, array $slot): array
{
    $lang = $w['lang'] === 'th' ? 'th' : 'en';
    $th = $lang === 'th';
    $images = ['logo' => venue_logo_bytes()];
    $name = trim($w['given'] . ' ' . $w['family']);
    [$date, $time] = mail_fmt_slot($slot, $lang);
    if ($kind === 'invited') {
        $h = $th ? 'มีที่ว่างสำหรับคุณแล้ว' : 'A place is available for you';
        $p = $th ? 'คุณได้รับสิทธิ์จองรอบที่ลงชื่อรอไว้ กรุณากดปุ่มด้านล่างเพื่อจองให้เสร็จภายใน ' . mail_fmt_dt((string) $w['expires_at'], $lang) . ' หลังจากนั้นสิทธิ์จะหมดอายุ'
            : 'You can now book the session you joined the waiting list for. Please tap the button below to complete your booking by ' . mail_fmt_dt((string) $w['expires_at'], $lang) . '. After that, this offer expires.';
        $btn = mail_button(public_url('?wl=' . rawurlencode((string) $w['token']) . '&lang=' . $lang), $th ? 'จองเลย' : 'Book now');
        $subject = ($th ? 'มีที่ว่างแล้ว: ' : 'A place is available: ') . "$date $time";
    } else {
        $h = $th ? 'ลงชื่อรอเรียบร้อยแล้ว' : 'You are on the waiting list';
        $p = $th ? 'รอบนี้เต็มแล้ว เราได้บันทึกชื่อของคุณในรายชื่อรอ หากมีที่ว่าง เราจะส่งอีเมลแจ้งพร้อมลิงก์สำหรับจอง (การลงชื่อรอยังไม่ใช่การจอง)'
            : 'This session is fully booked. We have added you to the waiting list. If a place becomes available, we will email you a link to book. (Joining the waiting list is not a booking.)';
        $btn = '';
        $subject = ($th ? 'ลงชื่อรอแล้ว: ' : 'Waiting list: ') . "$date $time";
    }
    $content = '<h1 style="font-size:22px;line-height:1.35;margin:0 0 14px;font-weight:700;text-align:center">' . mail_esc($h) . '</h1>'
        . mail_p(sprintf(mail_t($lang, 'greet_name'), mail_esc($name)), 'margin-bottom:6px')
        . mail_p(mail_esc($p), 'margin-bottom:16px')
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#efe9df;border-radius:10px"><tr><td style="padding:14px 16px;font-size:16px">'
        . '<b>' . mail_esc($date) . '</b><br>' . mail_esc($time) . ' &nbsp;·&nbsp; ' . mail_esc(sprintf(mail_t($lang, 'people'), (int) $w['qty'])) . '</td></tr></table>'
        . $btn . mail_closing($lang);
    return [$subject, mail_layout($lang, mail_esc($subject), $content), $images];
}
