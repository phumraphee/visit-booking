<?php
declare(strict_types=1);
/**
 * ส่งอีเมลเอง (แทนอีเมลของ Pretix — ออเดอร์จาก API ตั้ง send_email:false)
 * SMTP: Amazon SES (email-smtp.<region>.amazonaws.com:587 STARTTLS) — ค่าใน config: smtp_*
 * ยังไม่ตั้ง smtp_host → เขียนลง cache/mail-last.html + mail.log แทน (ใช้ตอนพัฒนา)
 * smtp_send() (SMTP + STARTTLS ไม่ใช้ไลบรารี (ใช้งานจริงกับ SES แล้ว)
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/venue.php';

/**
 * ใช้อีเมลของเราแทน Pretix ไหม — ต้องตั้ง smtp_host แล้วเท่านั้น (กันคนจองไม่ได้อีเมลเลย)
 * mail_preview=true (เฉพาะในเครื่อง) = ใช้ template ของเราแต่เขียนลงไฟล์แทนการส่ง
 */
function own_mail_enabled(): bool
{
    $c = cfg();
    return (string) ($c['smtp_host'] ?? '') !== '' || !empty($c['mail_preview']);
}

/** QR เป็น PNG (ข้อความ = secret ของตั๋วใน Pretix → แอป check-in ของ Pretix สแกนได้) */
function qr_png(string $data, int $scale = 8): string
{
    $opt = new chillerlan\QRCode\QROptions([
        'outputType'    => chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG,
        'outputBase64'  => false,
        'scale'         => $scale,
        'quietzoneSize' => 2,
        'eccLevel'      => chillerlan\QRCode\Common\EccLevel::M,
    ]);
    return (new chillerlan\QRCode\QRCode($opt))->render($data);
}

/**
 * เปิด/ปิดการเก็บอีเมลลง outbox แทนการส่ง (ออเดอร์ทดสอบ)
 * โหมดทดลอง: อีเมลถึงลูกค้าทุกฉบับเข้า outbox เสมอ แม้เป็นการจองจริงที่นำเข้าจาก Pretix
 * (กันลูกค้าจริงได้อีเมล "ยกเลิก/ยืนยัน" จากระบบที่ยังทดสอบอยู่ ขณะที่การจองใน Pretix ยังใช้งานจริง)
 */
function mail_use_outbox(bool $on, ?string $orderCode = null): void
{
    $GLOBALS['mail_outbox'] = $on || (function_exists('test_mode') && test_mode());
    $GLOBALS['mail_outbox_code'] = $orderCode;
}

function outbox_add(string $to, string $subject, string $html, array $images, ?string $code): bool
{
    db()->prepare('INSERT INTO outbox (created_at, to_addr, subject, html, images, order_code) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([now_iso(), $to, $subject, $html, json_encode(array_map('base64_encode', $images)), $code]);
    return true;
}

/** ส่งอีเมลจาก outbox จริง (กดจากหลังบ้าน) */
function outbox_send(int $id, ?string $to = null): bool
{
    $st = db()->prepare('SELECT * FROM outbox WHERE id = ?');
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) {
        return false;
    }
    $to = $to ?: $m['to_addr'];
    $prev = $GLOBALS['mail_outbox'] ?? false;
    $prevCode = $GLOBALS['mail_outbox_code'] ?? null;
    $GLOBALS['mail_outbox'] = false;
    $GLOBALS['mail_outbox_code'] = $m['order_code'];
    $ok = send_mail($to, $m['subject'], $m['html'], array_map('base64_decode', json_decode($m['images'], true) ?: []));
    $GLOBALS['mail_outbox'] = $prev;
    $GLOBALS['mail_outbox_code'] = $prevCode;
    if ($ok) {
        db()->prepare('UPDATE outbox SET sent_at = ?, sent_to = ? WHERE id = ?')->execute([now_iso(), $to, $id]);
    }
    return $ok;
}

/** HTML สำหรับพรีวิว (cid: → data URI) */
function mail_preview_html(string $html, array $images): string
{
    foreach ($images as $cid => $png) {
        $html = str_replace("cid:$cid", 'data:image/png;base64,' . base64_encode($png), $html);
    }
    return $html;
}

/** ไฟล์แนบ (คีย์ $images ที่มีจุด) สำหรับหน้าตัวอย่าง: [{name, b64}] — กดดูไฟล์ได้ในหลังบ้าน */
function mail_preview_files(array $images): array
{
    $out = [];
    foreach ($images as $k => $data) {
        if (str_contains((string) $k, '.')) {
            $out[] = ['name' => (string) $k, 'b64' => base64_encode((string) $data)];
        }
    }
    return $out;
}

function mime_header(string $s): string
{
    return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/** HTML → ข้อความล้วน (สำหรับ part text/plain) */
function html_to_text(string $html): string
{
    $t = preg_replace(['#<head.*?</head>#is', '#<div style="display:none.*?</div>#is'], '', $html);
    $t = preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $t);
    $t = preg_replace('#<(br|/p|/tr|/h\d|/div|/table|/li)[^>]*>#i', "\n", $t);
    $t = trim(html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8'));
    $t = preg_replace('/[ \t]+/', ' ', $t);
    return preg_replace("/\n\s*\n+/", "\n\n", $t);
}

/**
 * ส่งอีเมล HTML พร้อมรูปแนบในเนื้อหา (cid:) — $images = ['cid' => pngBytes]
 * คืน true ถ้าส่งได้ (หรือเขียนลงไฟล์ตอนยังไม่ตั้ง SMTP)
 */
/** ประวัติอีเมลต่อการจอง (เฉพาะอีเมลที่ผูกกับรหัสการจอง — ตั้งด้วย mail_use_outbox(..., $code)) */
function mail_log_add(string $to, string $subject, string $html, string $status): void
{
    $code = $GLOBALS['mail_outbox_code'] ?? null;
    if (!$code || !function_exists('db')) {
        return;
    }
    try {
        db()->prepare('INSERT INTO mail_log (at, to_addr, subject, order_code, status, html) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([now_iso(), $to, $subject, $code, $status, $html]);
    } catch (Throwable $e) {
        error_log('mail_log: ' . $e->getMessage());
    }
}

/**
 * สำเนาลับ (Bcc) อีเมลถึงผู้จองทุกฉบับ → settings.bcc_email (ว่าง = ปิด) — แบบเดียวกับ Pretix 
 * ไม่ใส่ในอีเมลระบบ (รหัสเข้าสู่ระบบ/รหัสยืนยัน/แจ้งเตือนแอดมิน): ครอบด้วย mail_no_bcc()
 */
function mail_bcc_for(string $to): array
{
    if (!empty($GLOBALS['mail_no_bcc']) || !function_exists('setting')) {
        return [];
    }
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string) setting('bcc_email', '')) as $b) {
        $b = mb_strtolower(trim($b));
        if ($b !== '' && filter_var($b, FILTER_VALIDATE_EMAIL) && $b !== mb_strtolower($to)) {
            $out[] = $b;
        }
    }
    return $out;
}

function mail_no_bcc(callable $fn)
{
    $prev = $GLOBALS['mail_no_bcc'] ?? false;
    $GLOBALS['mail_no_bcc'] = true;
    try {
        return $fn();
    } finally {
        $GLOBALS['mail_no_bcc'] = $prev;
    }
}

function send_mail(string $to, string $subject, string $html, array $images = []): bool
{
    // โหมดดูตัวอย่างก่อนส่ง (หลังบ้าน): เก็บไว้แสดง ไม่ส่ง ไม่ลง outbox/mail_log
    if (isset($GLOBALS['mail_capture']) && is_array($GLOBALS['mail_capture'])) {
        $GLOBALS['mail_capture'][] = ['to' => $to, 'subject' => $subject, 'html' => mail_preview_html($html, $images),
            'files' => mail_preview_files($images)];
        return true;
    }
    $c = cfg();
    $from     = $c['mail_from'] ?? venue()['email'];
    $fromName = $c['mail_from_name'] ?? venue_name('en');
    $replyTo  = $c['mail_reply_to'] ?? $from;

    $alt = 'a' . bin2hex(random_bytes(8));
    $rel = 'r' . bin2hex(random_bytes(8));
    $altPart = "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode(html_to_text($html)))
        . "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html))
        . "--$alt--\r\n";
    $allParts = $images;
    // คีย์ที่มีจุด (เช่น booking.ics, tickets.pdf) = ไฟล์แนบ, ที่เหลือ = รูปในเนื้ออีเมล (cid)
    $files = array_filter($images, fn($k) => str_contains((string) $k, '.'), ARRAY_FILTER_USE_KEY);
    $images = array_diff_key($images, $files);
    if ($images) {
        $body = "--$rel\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n" . $altPart;
        foreach ($images as $cid => $png) {
            $body .= "--$rel\r\nContent-Type: image/png; name=\"$cid.png\"\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-ID: <$cid>\r\nContent-Disposition: inline; filename=\"$cid.png\"\r\n\r\n"
                . chunk_split(base64_encode($png));
        }
        $body .= "--$rel--\r\n";
        $ctype = "multipart/related; boundary=\"$rel\"";
    } else {
        $body = $altPart;
        $ctype = "multipart/alternative; boundary=\"$alt\"";
    }
    if ($files) {
        $mix = 'm' . bin2hex(random_bytes(8));
        $types = ['ics' => 'text/calendar; charset=UTF-8; method=PUBLISH', 'pdf' => 'application/pdf'];
        $body = "--$mix\r\nContent-Type: $ctype\r\n\r\n" . $body;
        foreach ($files as $name => $data) {
            $type = $types[strtolower(pathinfo((string) $name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
            $body .= "--$mix\r\nContent-Type: $type; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n" . chunk_split(base64_encode((string) $data));
        }
        $body .= "--$mix--\r\n";
        $ctype = "multipart/mixed; boundary=\"$mix\"";
    }
    $headers = [
        'From'         => mime_header($fromName) . " <$from>",
        'To'           => $to,
        'Reply-To'     => $replyTo,
        'Subject'      => mime_header($subject),
        'Date'         => date('r'),
        'Message-ID'   => '<' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>',
        'MIME-Version' => '1.0',
        'Content-Type' => $ctype,
    ];

    if (PHP_SAPI === 'cli' && ($dump = getenv('VB_MIME_DUMP'))) {   // ทดสอบในเครื่อง: เขียนอีเมลดิบลงไฟล์
        $hs = '';
        foreach ($headers as $k => $v) {
            $hs .= "$k: $v\r\n";
        }
        file_put_contents($dump, $hs . "\r\n" . $body);
    }
    // โหมดทดลอง / ออเดอร์ทดสอบ: เก็บในกล่องอีเมลทดสอบ (outbox) — ส่งจริงเมื่อกด "ส่งจริง" ในหลังบ้านเท่านั้น
    if (!empty($GLOBALS['mail_outbox'])) {
        mail_log_add($to, $subject, $html, 'outbox');
        return outbox_add($to, $subject, $html, $allParts, $GLOBALS['mail_outbox_code'] ?? null);
    }
    $host = (string) ($c['smtp_host'] ?? '');
    if ($host === '') {
        // ยังไม่ตั้ง SMTP: เก็บไว้ดู (cid: แปลงเป็น data URI ให้เปิดดูในเบราว์เซอร์ได้)
        $preview = $html;
        foreach ($images as $cid => $png) {
            $preview = str_replace("cid:$cid", 'data:image/png;base64,' . base64_encode($png), $preview);
        }
        @file_put_contents($c['cache_dir'] . '/mail-last.html', $preview);
        @file_put_contents($c['cache_dir'] . '/mail.log', '=== ' . date('c') . " to=$to subject=$subject\n", FILE_APPEND);
        mail_log_add($to, $subject, $html, 'preview');
        return true;
    }
    try {
        smtp_send($host, (int) ($c['smtp_port'] ?? 587), (string) ($c['smtp_user'] ?? ''), (string) ($c['smtp_pass'] ?? ''), $from, $to, $headers, $body, mail_bcc_for($to));
        mail_log_add($to, $subject, $html, 'sent');
        return true;
    } catch (Throwable $e) {
        error_log('[mail] ' . $e->getMessage());
        @file_put_contents($c['cache_dir'] . '/mail-errors.log', date('c') . " to=$to subj=$subject err=" . $e->getMessage() . "\n", FILE_APPEND);
        mail_log_add($to, $subject, $html, 'failed');
        return false;
    }
}

function smtp_send(string $host, int $port, string $user, string $pass, string $from, string $to, array $headers, string $body, array $bcc = []): void
{
    $remote = ($port === 465 ? 'ssl://' : 'tcp://') . "$host:$port";
    $fp = stream_socket_client($remote, $errno, $errstr, 15);
    if (!$fp) {
        throw new RuntimeException("connect $remote: $errstr");
    }
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $out;
    };
    $cmd = function (string $c, array $ok) use ($fp, $read): string {
        if ($c !== '') {
            fwrite($fp, $c . "\r\n");
        }
        $r = $read();
        if (!in_array((int) substr($r, 0, 3), $ok, true)) {
            throw new RuntimeException('SMTP ' . (str_starts_with($c, 'AUTH') ? 'AUTH' : $c) . ' → ' . trim($r));
        }
        return $r;
    };
    $cmd('', [220]);
    $ehlo = 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $cmd($ehlo, [250]);
    if ($port !== 465) {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            throw new RuntimeException('STARTTLS failed');
        }
        $cmd($ehlo, [250]);
    }
    if ($user !== '') {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($user), [334]);
        $cmd(base64_encode($pass), [235]);
    }
    $cmd("MAIL FROM:<$from>", [250]);
    $cmd("RCPT TO:<$to>", [250, 251]);
    foreach ($bcc as $b) {   // สำเนาลับ: ส่งถึงแต่ไม่อยู่ในหัวอีเมล
        $cmd("RCPT TO:<$b>", [250, 251]);
    }
    $cmd('DATA', [354]);
    $msg = '';
    foreach ($headers as $k => $v) {
        $msg .= "$k: $v\r\n";
    }
    $msg .= "\r\n" . preg_replace('/^\./m', '..', $body);
    $cmd($msg . "\r\n.", [250]);
    $cmd('QUIT', [221]);
    fclose($fp);
}
