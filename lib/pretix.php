<?php
declare(strict_types=1);

/**
 * ตัวช่วยคุยกับ Pretix REST API + cache + rate limit
 */

/** ไฟล์ config: VISIT_CONFIG (env) → ../visit-booking-data/config.php (นอก web root) → config.php ที่รากโปรเจกต์ — ยังไม่มี = null (ยังไม่ได้ติดตั้ง) */
function config_path(): ?string
{
    foreach ([getenv('VISIT_CONFIG') ?: null, __DIR__ . '/../../visit-booking-data/config.php', __DIR__ . '/../config.php'] as $p) {
        if ($p && is_file($p)) {
            return $p;
        }
    }
    return null;
}

/** หน้าเว็บ: ยังไม่ได้ติดตั้ง → ไปตัวช่วยติดตั้ง */
function require_installed(string $toRoot = ''): void
{
    if (config_path() === null) {
        header('Location: ' . $toRoot . 'install.php', true, 302);
        exit;
    }
}

function cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $path = config_path();
        if ($path === null) {
            json_fail(500, 'config_missing', 'ยังไม่ได้ติดตั้ง — เปิด install.php');
        }
        $cfg = require $path;
        date_default_timezone_set($cfg['timezone'] ?? 'Asia/Bangkok');
    }
    return $cfg;
}

function json_out(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_fail(int $status, string $code, string $message, array $extra = []): never
{
    json_out($status, ['ok' => false, 'error' => $code, 'message' => $message] + $extra);
}

/**
 * เรียก Pretix API — คืน [httpStatus, decodedBody|null, rawBody]
 */
function pretix_request(string $method, string $path, ?array $body = null, array $query = []): array
{
    $c = cfg();
    $url = rtrim($c['pretix_base'], '/') . '/api/v1/organizers/' . rawurlencode($c['organizer'])
         . '/events/' . rawurlencode($c['event']) . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    $headers = [
        'Authorization: Token ' . $c['api_token'],
        'Accept: application/json',
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        error_log("pretix curl error: $err");
        return [0, null, $err];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $decoded = json_decode($raw, true);
    return [$status, is_array($decoded) ? $decoded : null, $raw];
}

/** ดึงทุกหน้า (Pretix แบ่งหน้า results/next) */
function pretix_get_all(string $path, array $query = []): array
{
    $results = [];
    $query['page_size'] = $query['page_size'] ?? 100;
    $page = 1;
    do {
        $query['page'] = $page;
        [$status, $data] = pretix_request('GET', $path, null, $query);
        if ($status !== 200 || !isset($data['results'])) {
            throw new RuntimeException("pretix GET $path failed ($status)");
        }
        $results = array_merge($results, $data['results']);
        $page++;
    } while (!empty($data['next']) && $page < 50);
    return $results;
}

/**
 * รวมรายการรอบ (subevents) ที่ยังจองได้ + จำนวนที่ว่าง
 * คืน array ของ ['id','date','start','end','available','left','presale_end']
 */
function fetch_slots(): array
{
    $c  = cfg();
    $tz = new DateTimeZone($c['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $horizon = $now->modify('+' . (int) $c['months_ahead'] . ' months');

    $subevents = pretix_get_all('subevents/', [
        'active'    => 'true',
        'is_future' => 'true',
        'ordering'  => 'date_from',
    ]);

    // กรองรอบที่ยังอยู่ในช่วงเปิดจอง (API ไม่ตรวจ presale เองตอนสร้างออเดอร์)
    $slots = [];
    foreach ($subevents as $se) {
        $from = new DateTimeImmutable($se['date_from']);
        $from = $from->setTimezone($tz);
        if ($from < $now || $from > $horizon) {
            continue;
        }
        if (!empty($se['presale_end'])) {
            $pe = (new DateTimeImmutable($se['presale_end']))->setTimezone($tz);
            if ($pe < $now) {
                continue;
            }
        }
        if (!empty($se['presale_start'])) {
            $ps = (new DateTimeImmutable($se['presale_start']))->setTimezone($tz);
            if ($ps > $now) {
                continue;
            }
        }
        $to = !empty($se['date_to']) ? (new DateTimeImmutable($se['date_to']))->setTimezone($tz) : null;
        $slots[$se['id']] = [
            'id'        => $se['id'],
            'date'      => $from->format('Y-m-d'),
            'start'     => $from->format('H:i'),
            'end'       => $to ? $to->format('H:i') : null,
            'available' => true,
            'left'      => null,   // null = ไม่จำกัด
            'presale_end' => $se['presale_end'] ?? null,
        ];
    }
    if (!$slots) {
        return [];
    }

    // ดึงโควตาพร้อม availability เฉพาะรอบที่สนใจ (แบ่งเป็นชุดละ 40 กัน URL ยาวเกิน)
    $ids = array_keys($slots);
    foreach (array_chunk($ids, 40) as $chunk) {
        $quotas = pretix_get_all('quotas/', [
            'subevent__in'      => implode(',', $chunk),
            'with_availability' => 'true',
        ]);
        foreach ($quotas as $q) {
            $sid = $q['subevent'] ?? null;
            if ($sid === null || !isset($slots[$sid])) {
                continue;
            }
            if (!in_array((int) $c['item_id'], array_map('intval', $q['items'] ?? []), true)) {
                continue;
            }
            // ถ้าสินค้าอยู่ในหลายโควตา ให้ใช้ค่าที่น้อยที่สุด
            if (isset($q['available']) && $q['available'] === false) {
                $slots[$sid]['available'] = false;
                $slots[$sid]['left'] = 0;
            }
            if (array_key_exists('available_number', $q) && $q['available_number'] !== null) {
                $n = (int) $q['available_number'];
                if ($slots[$sid]['left'] === null || $n < $slots[$sid]['left']) {
                    $slots[$sid]['left'] = $n;
                }
                if ($n <= 0) {
                    $slots[$sid]['available'] = false;
                }
            }
        }
    }

    return array_values($slots);
}

/** cache ไฟล์ง่าย ๆ */
function cached_slots(bool $force = false): array
{
    $c = cfg();
    $dir = $c['cache_dir'];
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/slots.json';
    if (!$force && is_file($file) && (time() - filemtime($file)) < (int) $c['cache_ttl']) {
        $data = json_decode((string) file_get_contents($file), true);
        if (is_array($data)) {
            return $data;
        }
    }
    $slots = fetch_slots();
    @file_put_contents($file, json_encode($slots, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $slots;
}

function invalidate_slots_cache(): void
{
    @unlink(cfg()['cache_dir'] . '/slots.json');
}

const CF_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
    '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
    '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
    '2a06:98c0::/29', '2c0f:f248::/32',
];

function ip_in_cidr(string $ip, string $cidr): bool
{
    [$net, $bits] = explode('/', $cidr);
    $a = @inet_pton($ip);
    $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) {
        return false;
    }
    $bits = (int) $bits;
    $bytes = intdiv($bits, 8);
    if (strncmp($a, $b, $bytes) !== 0) {
        return false;
    }
    $rem = $bits % 8;
    if ($rem === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
}

function client_ip(): string
{
    // เชื่อ CF-Connecting-IP เฉพาะเมื่อคำขอมาจาก Cloudflare จริง — origin เข้าตรงได้ ถ้าเชื่อทุกครั้งบอทปลอม header หลบ rate limit ได้
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
        foreach (CF_RANGES as $r) {
            if (ip_in_cidr($remote, $r)) {
                return $cf;
            }
        }
    }
    return $remote;
}

/** ค่าเชื่อมต่อบริการภายนอก: ตั้งในหลังบ้าน (settings) ก่อน ไม่มีจึงใช้ config — Turnstile / Ghost */
function ext_cfg(string $k): string
{
    $v = function_exists('setting') ? (string) setting($k, '') : '';
    return $v !== '' ? $v : (string) (cfg()[$k] ?? '');
}

/** Cloudflare Turnstile — ปิดอยู่ถ้ายังไม่ได้ใส่ turnstile_secret (หลังบ้าน หรือ config) */
function turnstile_ok(string $token): bool
{
    $secret = ext_cfg('turnstile_secret');
    if ($secret === '') {
        return true;
    }
    if ($token === '') {
        return false;
    }
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => client_ip()]),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw = curl_exec($ch);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j)) {
        error_log('turnstile: no response');
        return true;   // Cloudflare ล่ม → ไม่บล็อกผู้จองจริง (ยังมี honeypot + rate limit)
    }
    return !empty($j['success']);
}

/** คืน true ถ้าเกินลิมิต */
function rate_limited(string $bucket): bool
{
    $c = cfg();
    $dir = $c['cache_dir'] . '/rl';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/' . hash('sha256', $bucket . client_ip()) . '.json';
    $now = time();
    $win = (int) $c['rate_limit']['window'];
    // เพดานต่อประเภท (ต่อ IP ต่อ 10 นาที): เปิดดูตั๋ว/ไฟล์ต้องสูง — คนที่หน้างานใช้ wifi ของสถานที่ (IP เดียวกัน) เปิด QR พร้อมกัน
    $max = ['order_view' => 300, 'pass' => 120, 'gpass' => 120, 'book' => 30][$bucket] ?? (int) $c['rate_limit']['max'];

    $hits = [];
    if (is_file($file)) {
        $hits = json_decode((string) file_get_contents($file), true) ?: [];
    }
    $hits = array_values(array_filter($hits, fn($t) => ($now - (int) $t) < $win));
    if (count($hits) >= $max) {
        return true;
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return false;
}

/**
 * เพิ่มผู้ที่ติ๊กรับข่าวสารเป็น member ของ Ghost ผ่าน Admin API
 * ปิดอยู่ถ้ายังไม่ได้ใส่ ghost_url / ghost_admin_key ใน config (key = "id:secret" จาก Ghost → Integrations)
 * คืน true ถ้าเพิ่มได้หรือมีอยู่แล้ว
 */
/** JWT สำหรับ Ghost Admin API (key = "id:secret") */
function ghost_jwt(string $key): string
{
    [$id, $secret] = explode(':', $key, 2);
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $now = time();
    $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $id]));
    $body = $b64(json_encode(['iat' => $now, 'exp' => $now + 300, 'aud' => '/admin/']));
    return "$head.$body." . $b64(hash_hmac('sha256', "$head.$body", (string) hex2bin($secret), true));
}

/** ทดสอบการเชื่อมต่อบริการภายนอก → ['ok'=>bool, 'msg'=>ข้อความไทย] (ไม่เปลี่ยนข้อมูลอะไร) */
function ext_test(string $which): array
{
    if ($which === 'turnstile') {
        $site = ext_cfg('turnstile_site');
        $secret = ext_cfg('turnstile_secret');
        if ($site === '' || $secret === '') {
            return ['ok' => false, 'msg' => 'ยังใส่ Site key / Secret key ไม่ครบ'];
        }
        // ส่ง token ปลอม: secret ถูก → Cloudflare ตอบ invalid-input-response, secret ผิด → invalid-input-secret
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => 'connection-test'])]);
        $j = json_decode((string) curl_exec($ch), true) ?: [];
        $codes = $j['error-codes'] ?? [];
        if (in_array('invalid-input-secret', $codes, true)) {
            return ['ok' => false, 'msg' => 'Secret key ไม่ถูกต้อง'];
        }
        if (!empty($j['success']) || in_array('invalid-input-response', $codes, true) || in_array('timeout-or-duplicate', $codes, true)) {
            return ['ok' => true, 'msg' => 'Secret key ถูกต้อง · ตรวจ Site key ได้ที่หน้าจอง (ต้องเห็นกล่อง Cloudflare ก่อนปุ่มจอง)'];
        }
        return ['ok' => false, 'msg' => 'ติดต่อ Cloudflare ไม่สำเร็จ' . ($codes ? ' (' . implode(', ', $codes) . ')' : '')];
    }
    if ($which === 'ghost') {
        $base = rtrim(ext_cfg('ghost_url'), '/');
        $key = ext_cfg('ghost_admin_key');
        if ($base === '' || !str_contains($key, ':')) {
            return ['ok' => false, 'msg' => 'ยังใส่ URL / Admin API key ไม่ครบ'];
        }
        $ch = curl_init($base . '/ghost/api/admin/site/');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Ghost ' . ghost_jwt($key), 'Accept-Version: v5.0']]);
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code >= 300 && $code < 400) {   // ถูกส่งต่อไปที่อื่น → การส่งรายชื่อ (POST) จะไม่ตาม ต้องใส่ URL ปลายทาง
            $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $to = $loc !== '' ? preg_replace('#/ghost/.*$#', '', $loc) : '';
            return ['ok' => false, 'msg' => 'URL นี้ถูกส่งต่อไปที่อื่น' . ($to ? ' — ให้ใส่ URL เป็น ' . $to : '')];
        }
        $j = json_decode($raw, true) ?: [];
        if ($code === 200) {
            return ['ok' => true, 'msg' => 'เชื่อมต่อ Ghost ได้' . (!empty($j['site']['title']) ? ' · ' . $j['site']['title'] : '')];
        }
        return ['ok' => false, 'msg' => match (true) {
            $code === 0 => 'เปิด URL ไม่ได้ — ตรวจ URL',
            $code === 401 || $code === 403 => 'Admin API key ไม่ถูกต้อง',
            $code === 404 => 'URL ไม่ใช่เว็บ Ghost (ไม่พบ Admin API)',
            default => "Ghost ตอบรหัส $code",
        }];
    }
    return ['ok' => false, 'msg' => 'ไม่รู้จักบริการนี้'];
}

function ghost_subscribe(string $email, string $name, string $lang, string $source): bool
{
    return ghost_add_member($email, $name, $lang, "Opted in via $source on " . date('Y-m-d H:i')) !== 'fail';
}

/** เพิ่ม member ใน Ghost → 'new' | 'exists' (มีอยู่แล้ว ไม่แก้ของเดิม) | 'fail' | 'off' (ยังไม่ได้ตั้งค่า) */
function ghost_add_member(string $email, string $name, string $lang, string $note, array $extraLabels = []): string
{
    $c = cfg();
    $base = rtrim(ext_cfg('ghost_url'), '/');
    $key  = ext_cfg('ghost_admin_key');
    if ($base === '' || !str_contains($key, ':')) {
        return 'off';
    }
    $labels = array_map(fn($l) => ['name' => $l], array_merge($c['ghost_labels'] ?? ['visit-booking'], ['lang-' . $lang], $extraLabels));
    $payload = ['members' => [['email' => $email, 'name' => $name, 'labels' => $labels, 'note' => $note]]];
    $ch = curl_init($base . '/ghost/api/admin/members/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Authorization: Ghost ' . ghost_jwt($key), 'Content-Type: application/json', 'Accept-Version: v5.0'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = (string) curl_exec($ch);
    $st  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($st === 201) {
        return 'new';
    }
    if ($st === 422 && stripos($raw, 'already') !== false) {
        return 'exists';
    }
    error_log("ghost subscribe failed ($st) for $email: " . substr($raw, 0, 300));
    return 'fail';
}
