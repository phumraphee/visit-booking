<?php
declare(strict_types=1);
/**
 * Google Wallet (ตั๋วแบบ Event ticket) — ไม่ใช้ไลบรารี
 * - class 1 อันต่อรอบ (วัน/เวลา/สถานที่) สร้างผ่าน REST API ครั้งแรกที่มีคนกด (เก็บไว้ใน settings gw_class_<sid>)
 * - object (ตั๋วรายคน) สร้าง/อัปเดตผ่าน REST ตอนกดลิงก์ แล้ว JWT ของลิงก์ "Save to Google Wallet" ส่งแค่ id
 * config: gwallet_issuer_id, gwallet_sa (ไฟล์ service account JSON — อยู่นอก web root, อัปด้วย ./deploy.sh --push-wallet)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/emails.php';

function gwallet_enabled(): bool
{
    $c = cfg();
    return !empty($c['gwallet_issuer_id']) && !empty($c['gwallet_sa']) && is_file((string) $c['gwallet_sa']);
}

function gw_sa(): array
{
    static $sa = null;
    return $sa ??= json_decode((string) file_get_contents((string) cfg()['gwallet_sa']), true) ?: [];
}

function gw_b64(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function gw_jwt(array $payload): string
{
    $sa = gw_sa();
    $head = gw_b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $sa['private_key_id'] ?? '']));
    $body = gw_b64(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (!openssl_sign("$head.$body", $sig, (string) $sa['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('gwallet: sign failed');
    }
    return "$head.$body." . gw_b64($sig);
}

/** OAuth access token ของ service account (scope wallet_object.issuer) */
function gw_token(): string
{
    $sa = gw_sa();
    $now = time();
    $assert = gw_jwt(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',
        'aud' => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]);
    $ch = curl_init($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assert])]);
    $j = json_decode((string) curl_exec($ch), true) ?: [];
    if (empty($j['access_token'])) {
        throw new RuntimeException('gwallet token: ' . substr(json_encode($j), 0, 200));
    }
    return $j['access_token'];
}

function gw_api(string $method, string $path, ?array $body, string $token): array
{
    $ch = curl_init('https://walletobjects.googleapis.com/walletobjects/v1/' . ltrim($path, '/'));
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
    $raw = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?: [], $raw];
}

function gw_loc(string $en, string $th): array
{
    return ['defaultValue' => ['language' => 'en-US', 'value' => $en], 'translatedValues' => [['language' => 'th', 'value' => $th]]];
}

/** class ของรอบ (สร้างครั้งเดียว · อัปเดตเมื่อเปลี่ยนแบบ) → class id */
function gw_ensure_class(array $session): string
{
    $issuer = (string) cfg()['gwallet_issuer_id'];
    $id = $issuer . '.visit_s' . (int) $session['id'];
    // v3: การ์ดแสดง ชื่อผู้เข้าชม + วันที่ | รอบ (14:00–16:00) · ชื่อสถานที่ไม่ซ้ำ (ตัด venue ออก ที่อยู่ไปอยู่ในรายละเอียด)
    $ver = $id . '|v7';   // v7: รายละเอียดด้านล่าง = ที่อยู่ + แผนที่ + ดู/ยกเลิก + ข้อควรทราบ + ติดต่อ + เว็บไซต์ (detailsTemplateOverride ต้องระบุลิงก์เอง ไม่งั้นหาย)
    if (setting('gw_class_' . (int) $session['id']) === $ver) {
        return $id;
    }
    $link = fn($k) => ['firstValue' => ['fields' => [['fieldPath' => "object.linksModuleData.uris['$k']"]]]];
    $field = fn($k) => ['firstValue' => ['fields' => [['fieldPath' => "object.textModulesData['$k']"]]]];
    $class = [
        'id' => $id,
        'issuerName' => venue_name('en'),
        'localizedIssuerName' => gw_loc(venue_name('en'), venue_name('th')),   // ตามภาษาของเครื่อง
        'reviewStatus' => 'UNDER_REVIEW',
        'eventName' => gw_loc('Visit', 'เข้าชม'),
        'dateTime' => array_filter(['start' => $session['starts_at'], 'end' => $session['ends_at'] ?: null]),
        'logo' => ['sourceUri' => ['uri' => public_url('assets/brand/app-180.png')], 'contentDescription' => gw_loc(venue_name('en'), venue_name('th'))],
        'hexBackgroundColor' => '#f7f4ee',
        'homepageUri' => ['uri' => venue()['website'], 'description' => venue_site_label()],
        'classTemplateInfo' => ['cardTemplateOverride' => ['cardRowTemplateInfos' => [
            ['oneItem' => ['item' => $field('visitor')]],
            ['twoItems' => ['startItem' => $field('date'), 'endItem' => $field('session')]],
        ]], 'detailsTemplateOverride' => ['detailsItemInfos' => [
            ['item' => $field('address')],
            ['item' => $link('map')],
            ['item' => $link('manage')],
            ['item' => $field('notes')],
            ['item' => $field('contact')],
            ['item' => $link('web')],
        ]]],
    ];
    $token = gw_token();
    [$st] = gw_api('GET', 'eventTicketClass/' . rawurlencode($id), null, $token);
    if ($st === 200) {
        [$st2, , $raw] = gw_api('PUT', 'eventTicketClass/' . rawurlencode($id), $class, $token);   // แทนทั้ง class (ต้องเป็น UNDER_REVIEW)
    } elseif ($st === 404) {
        [$st2, , $raw] = gw_api('POST', 'eventTicketClass', $class, $token);
    } else {
        throw new RuntimeException("gwallet class get ($st)");
    }
    if ($st2 >= 300) {
        throw new RuntimeException("gwallet class ($st2): " . substr($raw, 0, 300));
    }
    setting_set('gw_class_' . (int) $session['id'], $ver);
    return $id;
}

/** ลิงก์ "Save to Google Wallet" ของตั๋ว 1 ใบ */
function gw_save_url(array $ticket, array $order, array $session): string
{
    $classId = gw_ensure_class($session);
    $issuer = (string) cfg()['gwallet_issuer_id'];
    $code = $order['code'] . '-' . (int) $ticket['n'];
    $object = [
        'id' => $issuer . '.t' . (int) $ticket['id'] . '_' . substr(hash('sha256', (string) $ticket['secret']), 0, 10),
        'classId' => $classId,
        'state' => 'ACTIVE',
        'ticketNumber' => $code,   // ไม่ใส่ ticketHolderName/reservationInfo — ซ้ำกับ "ผู้เข้าชม" บนการ์ดและรหัสตั๋ว
        'barcode' => ['type' => 'QR_CODE', 'value' => (string) $ticket['secret'], 'alternateText' => $code],
        'hexBackgroundColor' => '#f7f4ee',
        // วันที่/รอบ เป็นภาษาที่ผู้จองใช้
        'textModulesData' => (function () use ($session, $order, $ticket) {
            $th = $order['lang'] === 'th';
            [$d, $t] = mail_fmt_slot(session_slot($session), $th ? 'th' : 'en');
            return [
                ['id' => 'visitor', 'header' => $th ? 'ผู้เข้าชม' : 'Visitor', 'body' => trim($ticket['given'] . ' ' . $ticket['family'])],
                ['id' => 'date', 'header' => $th ? 'วันที่' : 'Date', 'body' => $d],
                ['id' => 'session', 'header' => $th ? 'รอบ' : 'Session', 'body' => $t],
                ['id' => 'address', 'header' => $th ? 'ที่อยู่' : 'Address', 'body' => venue_address($th ? 'th' : 'en')],
                // ข้อความเดียวกับด้านหลังตั๋ว Apple Wallet (lib/pkpass.php)
                ['id' => 'notes', 'header' => $th ? 'ข้อควรทราบ' : 'Please note', 'body' => implode("\n\n", array_map(fn($k) => strip_tags(mail_t($th ? 'th' : 'en', $k)), ['i_1', 'i_2', 'i_3']))],
                ['id' => 'contact', 'header' => $th ? 'ติดต่อ' : 'Contact', 'body' => venue()['email'] . "\n" . venue()['phone']],
            ];
        })(),
        'linksModuleData' => ['uris' => [
            ['uri' => public_url('manage.php?o=' . rawurlencode($order['code']) . '&s=' . rawurlencode($order['secret'])), 'description' => $order['lang'] === 'th' ? 'ดู / ยกเลิกการจอง' : 'View / cancel booking', 'id' => 'manage'],
            ['uri' => venue()['maps'], 'description' => $order['lang'] === 'th' ? 'แผนที่' : 'Map', 'id' => 'map'],
            ['uri' => venue()['website'], 'description' => venue_site_label(), 'id' => 'web'],
        ]],
    ];
    // สร้าง/อัปเดต object ผ่าน API ก่อน แล้วส่งแค่ id ใน JWT (ลิงก์สั้น — ข้อความไทยยาวทำให้ JWT เกิน ~1800 ตัวที่ Google แนะนำ)
    $token = gw_token();
    [$st, , $raw] = gw_api('POST', 'eventTicketObject', $object, $token);
    if ($st === 409) {
        [$st, , $raw] = gw_api('PUT', 'eventTicketObject/' . rawurlencode($object['id']), $object, $token);
    }
    if ($st >= 300) {
        throw new RuntimeException("gwallet object ($st): " . substr($raw, 0, 300));
    }
    $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', public_url('/'));
    $jwt = gw_jwt(['iss' => gw_sa()['client_email'], 'aud' => 'google', 'typ' => 'savetowallet', 'iat' => time(),
        'origins' => [$origin], 'payload' => ['eventTicketObjects' => [['id' => $object['id'], 'classId' => $classId]]]]);
    return 'https://pay.google.com/gp/v/save/' . $jwt;
}
