<?php
declare(strict_types=1);
/**
 * Apple Wallet (.pkpass) ของเราเอง — เซ็นด้วยใบรับรอง Pass Type ID จากบัญชี Apple Developer
 * config: wallet_pass_type_id, wallet_team_id, wallet_cert (PEM), wallet_key (PEM), wallet_key_pass, wallet_wwdr (Apple WWDR G4 PEM)
 * ไฟล์ใบรับรองเก็บใน /visit-booking-data/wallet/ (นอก web root) — ยังไม่ครบ = ปิดปุ่ม Wallet
 * โครงไฟล์: pass.json + รูป + manifest.json (SHA-1) + signature (PKCS#7 detached) → zip
 */

function wallet_enabled(): bool
{
    $c = cfg();
    foreach (['wallet_pass_type_id', 'wallet_team_id', 'wallet_cert', 'wallet_key', 'wallet_wwdr'] as $k) {
        if (empty($c[$k])) {
            return false;
        }
    }
    return is_file($c['wallet_cert']) && is_file($c['wallet_key']) && is_file($c['wallet_wwdr']);
}

/** สถานะใบรับรอง Pass Type ID: วันหมดอายุ/วันที่เหลือ + มี CSR ที่รอใบรับรองไหม */
function wallet_cert_info(): array
{
    $c = cfg();
    $info = ['enabled' => wallet_enabled(), 'pass_type_id' => $c['wallet_pass_type_id'] ?? '', 'team_id' => $c['wallet_team_id'] ?? '',
        'expires_at' => null, 'days_left' => null, 'pending_csr' => false];
    if (!empty($c['wallet_cert']) && is_file($c['wallet_cert'])) {
        $x = openssl_x509_parse((string) file_get_contents($c['wallet_cert']));
        if ($x && !empty($x['validTo_time_t'])) {
            $info['expires_at'] = date(DATE_ATOM, (int) $x['validTo_time_t']);
            $info['days_left'] = (int) floor(((int) $x['validTo_time_t'] - time()) / 86400);
        }
    }
    $info['pending_csr'] = is_file(wallet_pending_key_path());
    return $info;
}

function wallet_pending_key_path(): string
{
    return dirname((string) (cfg()['wallet_key'] ?? (__DIR__ . '/../data/wallet/pass.key'))) . '/pending.key';
}

/** สร้างกุญแจใหม่ + CSR (ใช้ขอใบรับรอง Pass Type ID ใหม่ที่ developer.apple.com) — กุญแจเก็บเป็น pending.key จนกว่าจะอัปใบรับรอง */
function wallet_make_csr(): string
{
    $path = wallet_pending_key_path();
    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0700, true);
    }
    if (is_file($path)) {
        $key = openssl_pkey_get_private((string) file_get_contents($path));
    } else {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        if (file_put_contents($path, $pem) === false) {
            throw new RuntimeException('เขียนไฟล์กุญแจไม่ได้ (สิทธิ์โฟลเดอร์ wallet บนโฮสต์)');
        }
        @chmod($path, 0600);
    }
    $csr = openssl_csr_new(['emailAddress' => venue()['email'], 'commonName' => venue_name('en') . ' Wallet', 'countryName' => 'TH'], $key, ['digest_alg' => 'sha256']);
    openssl_csr_export($csr, $out);
    return $out;
}

/** ติดตั้งใบรับรองใหม่ (.cer จาก Apple, DER หรือ PEM) — ต้องคู่กับ pending.key และเป็น Pass Type ID/Team เดิม; สำรองของเดิมไว้ */
function wallet_install_cert(string $raw): array
{
    $c = cfg();
    $pem = str_contains($raw, '-----BEGIN CERTIFICATE-----') ? $raw
        : "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($raw), 64, "\n") . "-----END CERTIFICATE-----\n";
    $x = openssl_x509_parse($pem);
    if (!$x) {
        throw new RuntimeException('ไฟล์นี้ไม่ใช่ใบรับรอง (.cer)');
    }
    $uid = $x['subject']['UID'] ?? '';
    $team = $x['subject']['OU'] ?? '';
    if ($uid !== ($c['wallet_pass_type_id'] ?? '') || $team !== ($c['wallet_team_id'] ?? '')) {
        throw new RuntimeException("ใบรับรองนี้เป็นของ $uid / $team — ไม่ตรงกับ Pass Type ID/Team ของระบบ");
    }
    $keyPath = wallet_pending_key_path();
    $usePending = is_file($keyPath) && openssl_x509_check_private_key($pem, (string) file_get_contents($keyPath));
    if (!$usePending && !openssl_x509_check_private_key($pem, (string) @file_get_contents((string) $c['wallet_key']))) {
        throw new RuntimeException('ใบรับรองไม่คู่กับกุญแจ — ต้องสร้างจาก CSR ล่าสุดของหน้านี้');
    }
    $stamp = date('Ymd-His');
    @copy((string) $c['wallet_cert'], $c['wallet_cert'] . '.bak-' . $stamp);
    if (file_put_contents((string) $c['wallet_cert'], $pem) === false) {
        throw new RuntimeException('เขียนไฟล์ใบรับรองไม่ได้');
    }
    if ($usePending) {
        @copy((string) $c['wallet_key'], $c['wallet_key'] . '.bak-' . $stamp);
        rename($keyPath, (string) $c['wallet_key']);
        @chmod((string) $c['wallet_key'], 0600);
    }
    return ['expires_at' => date(DATE_ATOM, (int) $x['validTo_time_t'])];
}

/** ย่อรูป PNG (คงสัดส่วน, พื้นโปร่งใส) ให้พอดีกรอบ w×h */
function wallet_png(string $src, int $w, int $h): string
{
    $im = imagecreatefromstring((string) file_get_contents($src));
    $sw = imagesx($im);
    $sh = imagesy($im);
    $r = min($w / $sw, $h / $sh);
    $nw = max(1, (int) round($sw * $r));
    $nh = max(1, (int) round($sh * $r));
    $out = imagecreatetruecolor($nw, $nh);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $sw, $sh);
    ob_start();
    imagepng($out);
    return (string) ob_get_clean();
}

/**
 * โลโก้แบบกว้าง (ตราสัญลักษณ์ + ชื่อ 2 บรรทัด) สำหรับตั๋วภาษาอังกฤษ — ชื่อเต็มยาวเกินช่อง logoText (ถูกตัด "…")
 * ขนาด Apple: กว้างได้ถึง 160pt สูง 50pt → $scale = 1/2/3
 */
function wallet_logo_wide(int $scale, array $lines): string
{
    $w = 160 * $scale;
    $h = 50 * $scale;
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagealphablending($out, true);
    $mark = imagecreatefromstring((string) file_get_contents(__DIR__ . '/../assets/brand/mark-180.png'));
    $ms = 46 * $scale;
    imagecopyresampled($out, $mark, 0, (int) (($h - $ms) / 2), 0, 0, $ms, $ms, imagesx($mark), imagesy($mark));
    require_once __DIR__ . '/ticketpdf.php';
    $font = __DIR__ . '/fonts/' . pdf_font()[2];   // ฟอนต์ตัวหนาเดียวกับตั๋ว PDF
    $ink = imagecolorallocate($out, 20, 18, 16);
    $x = $ms + 7 * $scale;
    // ขนาดตัวอักษรให้พอดีความกว้างที่เหลือ (GD ใช้หน่วย pt ที่ 96dpi)
    $size = 14 * $scale;
    foreach ($lines as $ln) {
        $bb = imagettfbbox($size, 0, $font, $ln);
        $size = min($size, $size * ($w - $x - 2 * $scale) / max(1, $bb[2] - $bb[0]));
    }
    imagettftext($out, $size, 0, $x, (int) (22 * $scale), $ink, $font, $lines[0]);
    imagettftext($out, $size, 0, $x, (int) (38 * $scale), $ink, $font, $lines[1] ?? '');
    ob_start();
    imagepng($out);
    return (string) ob_get_clean();
}

/** zip แบบไม่บีบอัด (ไม่ต้องพึ่ง ZipArchive) */
function zip_store(array $files): string
{
    $data = '';
    $central = '';
    $offset = 0;
    foreach ($files as $name => $content) {
        $crc = crc32($content);
        $len = strlen($content);
        $nlen = strlen($name);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0x21, $crc, $len, $len, $nlen, 0) . $name;
        $data .= $local . $content;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0x21, $crc, $len, $len, $nlen, 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($local) + $len;
    }
    return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), $offset, 0);
}

/**
 * สร้าง .pkpass ของตั๋ว 1 ใบ
 * $t: ['code','n','name','secret','slot'=>['date','start','end'],'starts_at','ends_at','lang']
 */
function build_pkpass(array $t): string
{
    $c = cfg();
    $lang = $t['lang'] === 'th' ? 'th' : 'en';
    [$date, $time] = mail_fmt_slot($t['slot'], $lang);
    $L = $lang === 'th'
        ? ['visit' => 'เข้าชม', 'date' => 'วันที่', 'time' => 'รอบ', 'visitor' => 'ผู้เข้าชม', 'code' => 'รหัสการจอง', 'notes' => 'ข้อควรทราบ', 'contact' => 'ติดต่อ', 'manage' => 'ดู / ยกเลิกการจอง']
        : ['visit' => 'Visit', 'date' => 'Date', 'time' => 'Session', 'visitor' => 'Visitor', 'code' => 'Booking code', 'notes' => 'Please note', 'contact' => 'Contact', 'manage' => 'View / cancel booking'];
    $notes = implode("\n\n", array_map(fn($k) => strip_tags(mail_t($lang, $k)), ['i_1', 'i_2', 'i_3']));
    $pass = [
        'formatVersion'      => 1,
        'passTypeIdentifier' => $c['wallet_pass_type_id'],
        'teamIdentifier'     => $c['wallet_team_id'],
        'serialNumber'       => $t['code'] . '-' . $t['n'],
        'organizationName'   => venue_name('en'),
        'description'        => venue_name('en') . ' — ' . $date,
        // อังกฤษ: ชื่อเต็มอยู่ในรูปโลโก้ (logoText ยาวเกินจะถูกตัด)
        'logoText'           => $lang === 'th' ? venue_name('th') : '',
        'foregroundColor'    => 'rgb(20,18,16)',
        'backgroundColor'    => 'rgb(247,244,238)',
        'labelColor'         => 'rgb(110,103,94)',
        'relevantDate'       => $t['starts_at'],
        'expirationDate'     => (new DateTimeImmutable($t['ends_at'] ?: $t['starts_at']))->modify('+1 day')->format(DATE_ATOM),
        'barcodes'           => [[
            'format'          => 'PKBarcodeFormatQR',
            'message'         => $t['secret'],
            'messageEncoding' => 'iso-8859-1',
            'altText'         => $t['code'] . '-' . $t['n'],
        ]],
        'eventTicket' => [
            'primaryFields'   => [['key' => 'visitor', 'label' => $L['visitor'], 'value' => $t['name']]],
            'secondaryFields' => [
                ['key' => 'date', 'label' => $L['date'], 'value' => $date],
            ],
            'auxiliaryFields' => [
                ['key' => 'time', 'label' => $L['time'], 'value' => $time],
                ['key' => 'code', 'label' => $L['code'], 'value' => $t['code'] . '-' . $t['n']],
            ],
            'backFields' => [
                ['key' => 'address', 'label' => $lang === 'th' ? 'ที่อยู่' : 'Address', 'value' => venue_address($lang) . "\n" . venue()['maps']],
                ['key' => 'notes', 'label' => $L['notes'], 'value' => $notes],
                ['key' => 'manage', 'label' => $L['manage'], 'value' => $t['manage_url'] ?? public_url('/')],
                ['key' => 'contact', 'label' => $L['contact'], 'value' => venue()['email'] . "\n" . venue()['phone'] . "\n" . venue_site_label()],
            ],
        ],
    ];
    $brand = __DIR__ . '/../assets/brand/';
    $files = [
        'pass.json'    => json_encode($pass, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'icon.png'     => wallet_png($brand . 'mark-180.png', 29, 29),
        'icon@2x.png'  => wallet_png($brand . 'mark-180.png', 58, 58),
        'icon@3x.png'  => wallet_png($brand . 'mark-180.png', 87, 87),
    ];
    foreach ([1 => 'logo.png', 2 => 'logo@2x.png', 3 => 'logo@3x.png'] as $sc => $fn) {
        $files[$fn] = $lang === 'th' ? wallet_png($brand . 'mark-180.png', 50 * $sc, 50 * $sc) : wallet_logo_wide($sc, wallet_name_lines(venue_name('en')));
    }
    $manifest = json_encode(array_map('sha1', $files), JSON_UNESCAPED_SLASHES);

    // ลายเซ็น PKCS#7 แบบ detached (DER) ของ manifest.json
    $tmp = sys_get_temp_dir() . '/pk' . bin2hex(random_bytes(6));
    file_put_contents("$tmp.m", $manifest);
    $ok = openssl_pkcs7_sign(
        "$tmp.m", "$tmp.s",
        'file://' . $c['wallet_cert'],
        ['file://' . $c['wallet_key'], (string) ($c['wallet_key_pass'] ?? '')],
        [], PKCS7_BINARY | PKCS7_DETACHED, $c['wallet_wwdr']
    );
    $smime = $ok ? (string) file_get_contents("$tmp.s") : '';
    @unlink("$tmp.m");
    @unlink("$tmp.s");
    if (!$ok) {
        throw new RuntimeException('pkpass sign failed: ' . openssl_error_string());
    }
    // S/MIME → เอาเฉพาะส่วน base64 ของ signature
    if (!preg_match('/filename="?smime\.p7s"?\s+(.+?)\s+------/s', $smime, $m)) {
        throw new RuntimeException('pkpass: cannot extract signature');
    }
    $files['manifest.json'] = $manifest;
    $files['signature'] = base64_decode(preg_replace('/\s+/', '', $m[1]));
    return zip_store($files);
}

/** ชื่อสถานที่เป็น 2 บรรทัด (ตัดตรงคำที่ทำให้สองบรรทัดยาวใกล้กันที่สุด) สำหรับโลโก้ตั๋ว Apple Wallet ภาษาอังกฤษ */
function wallet_name_lines(string $name): array
{
    $w = preg_split('/\s+/', trim($name)) ?: [$name];
    if (count($w) < 2) {
        return [$name];
    }
    $best = [$name];
    $diff = PHP_INT_MAX;
    for ($i = 1; $i < count($w); $i++) {
        $a = implode(' ', array_slice($w, 0, $i));
        $b = implode(' ', array_slice($w, $i));
        if (abs(strlen($a) - strlen($b)) < $diff) {
            $diff = abs(strlen($a) - strlen($b));
            $best = [$a, $b];
        }
    }
    return $best;
}
