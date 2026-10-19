<?php
declare(strict_types=1);
/**
 * Passkey (WebAuthn) สำหรับหลังบ้าน/หน้าสแกน — Face ID / Touch ID / ลายนิ้วมือ / Windows Hello
 * ไม่ใช้ไลบรารี: เบราว์เซอร์ส่ง public key แบบ SPKI (getPublicKey()) และ authenticatorData มาให้ตรง ๆ
 * จึงไม่ต้องถอด CBOR; รองรับ ES256 (-7) และ RS256 (-257), attestation 'none'
 * RP ID = โดเมนหลัก (เช่น example.org) → passkey ที่สร้างบน booking.* ใช้ต่อบน visit.* ได้หลังสลับโดเมน
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/venue.php';

const WA_CHALLENGE_TTL = 300;

function b64u_enc(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64u_dec(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

function wa_rp_id(): string
{
    $c = (string) (cfg()['webauthn_rp_id'] ?? '');
    if ($c !== '') {
        return $c;
    }
    $host = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'))[0]);
    if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
        return $host;
    }
    return implode('.', array_slice(explode('.', $host), -2));
}

function wa_challenge(?int $adminId): string
{
    $ch = b64u_enc(random_bytes(32));
    db()->prepare('DELETE FROM webauthn_challenges WHERE expires_at < ?')->execute([time()]);
    db()->prepare('INSERT INTO webauthn_challenges (challenge, admin_id, expires_at) VALUES (?, ?, ?)')
        ->execute([$ch, $adminId, time() + WA_CHALLENGE_TTL]);
    return $ch;
}

/** ตรวจ clientDataJSON (type/challenge/origin) → admin_id ที่ผูกกับ challenge (0 = ไม่ผูก) หรือ throw */
function wa_check_client(string $cdj, string $type): int
{
    $cd = json_decode($cdj, true);
    if (!is_array($cd) || ($cd['type'] ?? '') !== $type) {
        throw new RuntimeException('type');
    }
    $st = db()->prepare('SELECT admin_id FROM webauthn_challenges WHERE challenge = ? AND expires_at > ?');
    $st->execute([(string) ($cd['challenge'] ?? ''), time()]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('challenge');
    }
    db()->prepare('DELETE FROM webauthn_challenges WHERE challenge = ?')->execute([$cd['challenge']]);
    $o = parse_url((string) ($cd['origin'] ?? ''));
    $host = strtolower($o['host'] ?? '');
    $rp = wa_rp_id();
    $local = in_array($host, ['localhost', '127.0.0.1'], true);
    if (!($host === $rp || str_ends_with($host, '.' . $rp)) || (($o['scheme'] ?? '') !== 'https' && !$local)) {
        throw new RuntimeException('origin');
    }
    return (int) $row['admin_id'];
}

/** ตรวจ authenticatorData: rpIdHash + ผู้ใช้แตะ (UP) + ยืนยันตัวตน (UV = Face ID/รหัสเครื่อง) → [flags, signCount] */
function wa_check_authdata(string $ad): array
{
    if (strlen($ad) < 37 || !hash_equals(hash('sha256', wa_rp_id(), true), substr($ad, 0, 32))) {
        throw new RuntimeException('rpid');
    }
    $flags = ord($ad[32]);
    if (!($flags & 0x01) || !($flags & 0x04)) {
        throw new RuntimeException('uv');
    }
    return [$flags, unpack('N', substr($ad, 33, 4))[1]];
}

function wa_register_options(array $admin): array
{
    $st = db()->prepare('SELECT cred_id FROM passkeys WHERE admin_id = ?');
    $st->execute([$admin['id']]);
    return [
        'challenge' => wa_challenge((int) $admin['id']),
        'rp' => ['id' => wa_rp_id(), 'name' => venue_name('en') . ' · Booking'],
        'user' => ['id' => b64u_enc('admin-' . $admin['id']), 'name' => $admin['email'], 'displayName' => $admin['name'] ?: $admin['email']],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
        'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
        'excludeCredentials' => array_map(fn($id) => ['type' => 'public-key', 'id' => $id], $st->fetchAll(PDO::FETCH_COLUMN)),
        'attestation' => 'none',
        'timeout' => 60000,
    ];
}

function wa_register_finish(array $admin, array $in): void
{
    $cdj = b64u_dec((string) ($in['clientDataJSON'] ?? ''));
    if (wa_check_client($cdj, 'webauthn.create') !== (int) $admin['id']) {
        throw new RuntimeException('challenge');
    }
    $ad = b64u_dec((string) ($in['authenticatorData'] ?? ''));
    [$flags, $count] = wa_check_authdata($ad);
    $id = b64u_dec((string) ($in['id'] ?? ''));
    // attested credential data: aaguid(16) + len(2) + credentialId
    if (!($flags & 0x40) || strlen($ad) < 55 || substr($ad, 55, unpack('n', substr($ad, 53, 2))[1]) !== $id || $id === '') {
        throw new RuntimeException('cred');
    }
    $alg = (int) ($in['alg'] ?? 0);
    $spki = b64u_dec((string) ($in['publicKey'] ?? ''));
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    if (!in_array($alg, [-7, -257], true) || $spki === '' || !openssl_pkey_get_public($pem)) {
        throw new RuntimeException('key');
    }
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 60) ?: 'Passkey';
    db()->prepare('INSERT INTO passkeys (admin_id, cred_id, public_key, alg, sign_count, name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$admin['id'], b64u_enc($id), $pem, $alg, $count, $name, now_iso()]);
}

function wa_login_options(): array
{
    return ['challenge' => wa_challenge(null), 'rpId' => wa_rp_id(), 'userVerification' => 'required', 'timeout' => 60000, 'allowCredentials' => []];
}

/** ตรวจลายเซ็น → admin row (active) หรือ throw */
function wa_login_finish(array $in): array
{
    $cdj = b64u_dec((string) ($in['clientDataJSON'] ?? ''));
    wa_check_client($cdj, 'webauthn.get');
    $ad = b64u_dec((string) ($in['authenticatorData'] ?? ''));
    [, $count] = wa_check_authdata($ad);
    $st = db()->prepare('SELECT p.*, a.active FROM passkeys p JOIN admins a ON a.id = p.admin_id WHERE p.cred_id = ?');
    $st->execute([b64u_enc(b64u_dec((string) ($in['id'] ?? '')))]);
    $pk = $st->fetch();
    if (!$pk) {
        throw new RuntimeException('unknown');
    }
    if (!(int) $pk['active']) {
        throw new RuntimeException('inactive');
    }
    $sig = b64u_dec((string) ($in['signature'] ?? ''));
    if (openssl_verify($ad . hash('sha256', $cdj, true), $sig, $pk['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('signature');
    }
    // ตัวนับ: passkey ที่ sync (iCloud/Google) ส่ง 0 เสมอ — ตรวจเฉพาะเมื่อมีค่า
    if ($count > 0 && $count <= (int) $pk['sign_count']) {
        throw new RuntimeException('counter');
    }
    db()->prepare('UPDATE passkeys SET sign_count = ?, last_used_at = ? WHERE id = ?')->execute([$count, now_iso(), $pk['id']]);
    $st = db()->prepare('SELECT * FROM admins WHERE id = ? AND active = 1');
    $st->execute([$pk['admin_id']]);
    return $st->fetch() ?: throw new RuntimeException('inactive');
}
