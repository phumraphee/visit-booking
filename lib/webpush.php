<?php
declare(strict_types=1);
/**
 * Web Push แจ้งเตือนแอดมิน/พนักงาน (เว็บแอปที่เพิ่มลงหน้าจอโฮม) — โค้ดเข้ารหัสยกมาจาก ~/health/push.php
 * (RFC 8292 VAPID · RFC 8291 aes128gcm · RFC 8188) ใช้แค่ openssl + curl
 * กุญแจ VAPID เก็บใน settings (สร้างครั้งแรกที่ใช้)
 * ตั้งค่ารายคน (admins.push_prefs JSON): events = เหตุการณ์ที่จะแจ้ง, summary = [0,1,2] วันก่อนวันเข้าชม, hour = เวลาส่งสรุป
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/emails.php';

function push_supported(): bool {
    return function_exists('openssl_pkey_derive')
        && function_exists('hash_hkdf')
        && function_exists('curl_init')
        && in_array('aes-128-gcm', array_map('strtolower', openssl_get_cipher_methods()), true);
}

if (!function_exists('b64u')) {
function b64u(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
}
if (!function_exists('b64u_dec')) {
function b64u_dec(string $s): string {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode($s) ?: '';
}
}

/* ---------- JWT แบบ ES256 ---------- */

/* openssl คืนลายเซ็นเป็น DER แต่ JWS ต้องการ R‖S ดิบ 64 ไบต์
   จุดนี้คือที่พลาดกันบ่อยที่สุด ถ้าไม่แปลงจะโดนปฏิเสธเงียบ ๆ */
function der_to_raw(string $der): string {
    $off = 0;
    if (($der[$off++] ?? '') !== "\x30") throw new RuntimeException('ลายเซ็นผิดรูปแบบ');
    $len = ord($der[$off++]);
    if ($len & 0x80) $off += ($len & 0x7f);          // ความยาวแบบหลายไบต์
    $read = function () use ($der, &$off): string {
        if (($der[$off++] ?? '') !== "\x02") throw new RuntimeException('ลายเซ็นผิดรูปแบบ');
        $n = ord($der[$off++]);
        $v = substr($der, $off, $n);
        $off += $n;
        $v = ltrim($v, "\0");                         // ตัด 0x00 ที่ใส่กันติดลบ
        return str_pad($v, 32, "\0", STR_PAD_LEFT);
    };
    return $read() . $read();
}

function vapid_jwt(string $aud, string $subject, string $privPem, int $ttl = 43200): string {
    $head = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $body = b64u(json_encode([
        'aud' => $aud,
        'exp' => time() + $ttl,
        'sub' => $subject,
    ], JSON_UNESCAPED_SLASHES));
    $signing = "$head.$body";
    $key = openssl_pkey_get_private($privPem);
    if (!$key) throw new RuntimeException('อ่านกุญแจ VAPID ไม่ได้');
    if (!openssl_sign($signing, $der, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('เซ็น JWT ไม่สำเร็จ');
    }
    return "$signing." . b64u(der_to_raw($der));
}

/* ---------- เข้ารหัสเนื้อหา (aes128gcm) ---------- */

/* กุญแจสาธารณะของเครื่องปลายทางมาเป็นจุดดิบ 65 ไบต์ ต้องห่อเป็น DER ก่อน openssl จึงรับ */
function p256_pem_from_raw(string $raw): string {
    if (strlen($raw) !== 65 || $raw[0] !== "\x04") throw new RuntimeException('กุญแจปลายทางผิดรูปแบบ');
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function push_encrypt(string $payload, string $uaPublicRaw, string $authSecret): string {
    $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$eph) throw new RuntimeException('สร้างกุญแจชั่วคราวไม่สำเร็จ');
    $d = openssl_pkey_get_details($eph);
    $asPublic = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)
                       . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);

    $shared = openssl_pkey_derive(p256_pem_from_raw($uaPublicRaw), $eph);
    if ($shared === false) throw new RuntimeException('แลกกุญแจกับเครื่องปลายทางไม่สำเร็จ');

    // RFC 8291 §3.4
    $ikm   = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublicRaw . $asPublic, $authSecret);
    $salt  = random_bytes(16);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    // ก้อนเดียวจบ จึงปิดท้ายด้วยตัวคั่น 0x02
    $plain = $payload . "\x02";
    $ct = openssl_encrypt($plain, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ct === false) throw new RuntimeException('เข้ารหัสเนื้อหาไม่สำเร็จ');

    // หัวก้อนตาม RFC 8188: salt(16) | rs(4) | idlen(1) | keyid | เนื้อหา
    return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $ct . $tag;
}

/* ---------- ส่งจริง ---------- */

/** คืน ['ok'=>bool, 'status'=>int, 'gone'=>bool, 'error'=>string] */
function push_send(array $sub, array $payload, array $vapid, string $subject): array {
    if (!push_supported()) return ['ok' => false, 'status' => 0, 'gone' => false, 'error' => 'php_unsupported'];
    $endpoint = (string)$sub['endpoint'];
    $parts = parse_url($endpoint);
    if (!$parts || empty($parts['host'])) return ['ok' => false, 'status' => 0, 'gone' => true, 'error' => 'bad_endpoint'];
    /* aud ต้องเป็น origin ของบริการผลักดัน ไม่ใช่ URL เต็ม (พอร์ตใส่เฉพาะที่ไม่ใช่ค่ามาตรฐาน) */
    $port = (int)($parts['port'] ?? 0);
    $std = ($parts['scheme'] === 'https' && $port === 443) || ($parts['scheme'] === 'http' && $port === 80);
    $aud = $parts['scheme'] . '://' . $parts['host'] . ($port && !$std ? ':' . $port : '');

    try {
        $jwt  = vapid_jwt($aud, $subject, $vapid['private']);
        $body = push_encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE),
                             b64u_dec((string)$sub['p256dh']), b64u_dec((string)$sub['auth']));
    } catch (Throwable $e) {
        return ['ok' => false, 'status' => 0, 'gone' => false, 'error' => $e->getMessage()];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => [
            'Authorization: vapid t=' . $jwt . ', k=' . $vapid['public'],
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: 1800',
            'Urgency: high',
            'Content-Length: ' . strlen($body),
        ],
    ]);
    $res = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);

    return [
        'ok'     => $status >= 200 && $status < 300,
        'status' => $status,
        // 404/410 = เครื่องนั้นถอนการติดตั้งหรือล้างข้อมูลไปแล้ว ลบทิ้งได้เลย
        'gone'   => in_array($status, [404, 410], true),
        'error'  => $err ?: (($status >= 400) ? substr((string)$res, 0, 200) : ''),
    ];
}


/* ---------- ระบบของเรา ---------- */

function webpush_vapid(): array
{
    $priv = setting('vapid_private');
    $pub = setting('vapid_public');
    if ($priv && $pub) {
        return ['private' => $priv, 'public' => $pub];
    }
    $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($res, $pem);
    $d = openssl_pkey_get_details($res);
    $pubRaw = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    setting_set('vapid_private', $pem);
    setting_set('vapid_public', b64u($pubRaw));
    return ['private' => $pem, 'public' => b64u($pubRaw)];
}

const PUSH_EVENTS = ['booked', 'canceled', 'ticket_canceled', 'edited', 'restored', 'waitlist'];

function push_prefs(array $admin): array
{
    $p = json_decode((string) ($admin['push_prefs'] ?? ''), true) ?: [];
    return [
        'events' => array_values(array_intersect($p['events'] ?? PUSH_EVENTS, PUSH_EVENTS)),
        'summary' => array_values(array_filter(array_map('intval', $p['summary'] ?? [1]), fn($d) => $d >= 0 && $d <= 3)),
        'hour' => max(5, min(22, (int) ($p['hour'] ?? 18))),
    ];
}

/** ส่งถึงทุกเครื่องของผู้ใช้ที่ผ่าน $filter(admin, prefs) — คืนจำนวนที่ส่งสำเร็จ */
function push_to(callable $filter, array $payload): int
{
    if (!push_supported()) {
        return 0;
    }
    $pdo = db();
    $rows = $pdo->query('SELECT s.*, a.push_prefs, a.role, a.email AS admin_email FROM push_subs s JOIN admins a ON a.id = s.admin_id WHERE a.active = 1')->fetchAll();
    $vapid = webpush_vapid();
    $subject = 'mailto:' . (cfg()['mail_reply_to'] ?? venue()['email']);
    $ok = 0;
    foreach ($rows as $r) {
        if (!$filter($r, push_prefs($r))) {
            continue;
        }
        $res = push_send($r, $payload, $vapid, $subject);
        if ($res['ok']) {
            $ok++;
            $pdo->prepare('UPDATE push_subs SET last_ok = ? WHERE id = ?')->execute([now_iso(), $r['id']]);
        } elseif ($res['gone']) {
            $pdo->prepare('DELETE FROM push_subs WHERE id = ?')->execute([$r['id']]);
        } else {
            error_log('push failed: ' . $res['status'] . ' ' . $res['error']);
        }
    }
    return $ok;
}

/** แจ้งเหตุการณ์ (เรียกจาก notify_admin) */
function push_event(string $event, string $title, array $lines, string $url): int
{
    return push_to(fn($a, $p) => in_array($event, $p['events'], true),
        ['title' => $title, 'body' => implode("\n", array_slice($lines, 0, 3)), 'url' => $url, 'tag' => 'vb-' . $event . '-' . substr(md5($title), 0, 6)]);
}

/** สรุปจำนวนผู้เข้าชมล่วงหน้า — เรียกทุกชั่วโมง (lib/reminders.php) */
function push_summary_tick(?DateTimeImmutable $now = null): int
{
    $now = $now ?? new DateTimeImmutable('now', tz());
    $pdo = db();
    $sent = 0;
    $rows = $pdo->query('SELECT DISTINCT a.* FROM admins a JOIN push_subs s ON s.admin_id = a.id WHERE a.active = 1')->fetchAll();
    $test = test_mode() ? 1 : 0;
    foreach ($rows as $a) {
        $p = push_prefs($a);
        if ((int) $now->format('G') !== $p['hour']) {
            continue;
        }
        foreach ($p['summary'] as $d) {
            $date = $now->modify("+$d day")->format('Y-m-d');
            $key = "sum:{$a['id']}:$date:$d";
            $st = $pdo->prepare('SELECT 1 FROM push_log WHERE k = ?');
            $st->execute([$key]);
            if ($st->fetchColumn()) {
                continue;
            }
            $st = $pdo->prepare("SELECT s.id, s.starts_at, s.ends_at,
                    (SELECT COUNT(*) FROM tickets t JOIN orders o ON o.id = t.order_id WHERE t.session_id = s.id AND t.canceled = 0 AND o.status IN ('p','w') AND o.is_test = ?) n
                FROM sessions s WHERE substr(s.starts_at, 1, 10) = ? AND s.active = 1 ORDER BY s.starts_at");
            $st->execute([$test, $date]);
            $ss = $st->fetchAll();
            if (!$ss) {
                continue;   // ไม่มีรอบวันนั้น ไม่ต้องแจ้ง
            }
            $pdo->prepare('INSERT OR IGNORE INTO push_log (k, at) VALUES (?, ?)')->execute([$key, now_iso()]);
            $total = array_sum(array_column($ss, 'n'));
            $when = $d === 0 ? 'วันนี้' : ($d === 1 ? 'พรุ่งนี้' : "อีก $d วัน");
            $th = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
            $dt = new DateTimeImmutable($date, tz());
            $title = "$when · วัน{$th[(int) $dt->format('w')]}ที่ " . (int) $dt->format('j') . ' — ผู้เข้าชม ' . $total . ' คน';
            $body = implode(' · ', array_map(fn($s) => substr($s['starts_at'], 11, 5) . ' น. ' . $s['n'] . ' คน', $ss));
            $sent += push_to(fn($x) => (int) $x['admin_id'] === (int) $a['id'],
                ['title' => $title, 'body' => $body, 'url' => public_url('admin/#/day/' . $date . '/' . implode(',', array_column($ss, 'id'))), 'tag' => "vb-sum-$date"]);
        }
    }
    return $sent;
}
