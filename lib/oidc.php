<?php
/**
 * เข้าสู่ระบบหลังบ้าน/หน้าสแกนผ่าน OpenID Connect (เช่น Pocket ID) — ไม่ใช้ไลบรารี
 * authorization code + PKCE (S256), ตรวจ id_token RS256 ด้วย JWKS, จับคู่ด้วยอีเมล ↔ admins.email ที่ active
 * (ไม่สร้างผู้ใช้อัตโนมัติ — สิทธิ์ยังจัดการในหลังบ้านเหมือนเดิม)
 *
 * config:
 *   'oidc_issuer'        => 'https://sso.example.org'   ว่าง = ปิด
 *   'oidc_client_id'     => '...'
 *   'oidc_client_secret' => '...'                        ว่าง = public client (PKCE อย่างเดียว)
 *   'oidc_secret_file'   => '/path/oidc.json'           ทางเลือก: {"client_id":..,"client_secret":..} (ไม่ต้องเขียนความลับใน config)
 *   'oidc_label'         => ['th' => '...', 'en' => '...']  ข้อความบนปุ่ม
 *   'oidc_logout_url'    => '...'                        หน้า "ออกจากระบบ" ของ SSO (ค่าเริ่มต้น <issuer>/logout)
 */
declare(strict_types=1);

function oidc_conf(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $cfg = cfg();
    $c = [
        'issuer' => rtrim((string) ($cfg['oidc_issuer'] ?? ''), '/'),
        'client_id' => (string) ($cfg['oidc_client_id'] ?? ''),
        'client_secret' => (string) ($cfg['oidc_client_secret'] ?? ''),
    ];
    $f = (string) ($cfg['oidc_secret_file'] ?? '');
    if ($f !== '' && is_readable($f)) {
        $j = json_decode((string) file_get_contents($f), true);
        if (is_array($j)) {
            $c['client_id'] = (string) ($j['client_id'] ?? $c['client_id']);
            $c['client_secret'] = (string) ($j['client_secret'] ?? $c['client_secret']);
        }
    }
    $c['logout_url'] = (string) ($cfg['oidc_logout_url'] ?? ($c['issuer'] !== '' ? $c['issuer'] . '/logout' : ''));
    return $c;
}

function oidc_enabled(): bool
{
    $c = oidc_conf();
    return $c['issuer'] !== '' && $c['client_id'] !== '';
}

function oidc_label(string $lang): string
{
    $l = cfg()['oidc_label'] ?? null;
    if (is_array($l) && !empty($l[$lang])) {
        return (string) $l[$lang];
    }
    return $lang === 'en' ? 'Sign in with SSO' : 'เข้าสู่ระบบด้วย SSO';
}

/** URL ของ admin/oidc.php (callback) — ต้องตรงกับที่ลงทะเบียนไว้ใน SSO */
function oidc_redirect_uri(): string
{
    $base = rtrim((string) (cfg()['public_url'] ?? ''), '/');
    if ($base === '') {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $base = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $base . '/admin/oidc.php';
}

function oidc_http(string $url, ?array $post = null, array $headers = []): array
{
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers)];
    if ($post !== null) {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        throw new RuntimeException('http: ' . $err);
    }
    $j = json_decode((string) $body, true);
    if (!is_array($j)) {
        throw new RuntimeException("http $code: not json");
    }
    return [$code, $j];
}

/** อ่าน JSON จาก URL แล้วเก็บใน cache/ ($ttl วินาที) — $fresh = ข้ามแคช */
function oidc_cached(string $url, int $ttl, bool $fresh = false): array
{
    $f = cfg()['cache_dir'] . '/oidc-' . substr(hash('sha256', $url), 0, 16) . '.json';
    if (!$fresh && is_file($f) && filemtime($f) > time() - $ttl) {
        $j = json_decode((string) file_get_contents($f), true);
        if (is_array($j)) {
            return $j;
        }
    }
    [$code, $j] = oidc_http($url);
    if ($code !== 200) {
        throw new RuntimeException("fetch $code");
    }
    @file_put_contents($f, json_encode($j), LOCK_EX);
    return $j;
}

function oidc_discovery(): array
{
    $d = oidc_cached(oidc_conf()['issuer'] . '/.well-known/openid-configuration', 86400);
    if (rtrim((string) ($d['issuer'] ?? ''), '/') !== oidc_conf()['issuer']) {
        throw new RuntimeException('issuer mismatch');
    }
    return $d;
}

function oidc_b64u(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function oidc_unb64u(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

/** เริ่มไปหน้า SSO — $purpose 'login' หรือ 'logout' (prompt=none เพื่อดูว่ายังล็อกอิน SSO อยู่ไหม), $back = หน้าที่จะกลับ */
function oidc_start(string $purpose, string $back): never
{
    $d = oidc_discovery();
    $state = oidc_b64u(random_bytes(24));
    $nonce = oidc_b64u(random_bytes(24));
    $verifier = oidc_b64u(random_bytes(48));
    db()->prepare('DELETE FROM oidc_states WHERE expires_at < ?')->execute([time()]);
    db()->prepare('INSERT INTO oidc_states (state, nonce, verifier, purpose, back, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$state, $nonce, $verifier, $purpose, $back, time() + 600]);
    $q = [
        'response_type' => 'code',
        'client_id' => oidc_conf()['client_id'],
        'redirect_uri' => oidc_redirect_uri(),
        'scope' => 'openid email profile',
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => oidc_b64u(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
    ];
    if ($purpose === 'logout') {
        $q['prompt'] = 'none';
    }
    header('Location: ' . $d['authorization_endpoint'] . '?' . http_build_query($q), true, 302);
    exit;
}

/** ดึง state ที่บันทึกไว้ (ใช้ครั้งเดียว) */
function oidc_take_state(string $state): ?array
{
    $st = db()->prepare('SELECT * FROM oidc_states WHERE state = ? AND expires_at >= ?');
    $st->execute([$state, time()]);
    $row = $st->fetch() ?: null;
    db()->prepare('DELETE FROM oidc_states WHERE state = ?')->execute([$state]);
    return $row;
}

/** แลก code เป็น token แล้วตรวจ id_token — คืน claims */
function oidc_finish(string $code, array $st): array
{
    $c = oidc_conf();
    $d = oidc_discovery();
    $post = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => oidc_redirect_uri(), 'code_verifier' => $st['verifier'], 'client_id' => $c['client_id']];
    $headers = [];
    if ($c['client_secret'] !== '') {
        $headers[] = 'Authorization: Basic ' . base64_encode(rawurlencode($c['client_id']) . ':' . rawurlencode($c['client_secret']));
    }
    [$http, $tok] = oidc_http($d['token_endpoint'], $post, $headers);
    if ($http !== 200 || empty($tok['id_token'])) {
        throw new RuntimeException('token ' . $http . ' ' . ($tok['error'] ?? ''));
    }
    $claims = oidc_verify_jwt((string) $tok['id_token'], (string) $d['jwks_uri']);
    $aud = (array) ($claims['aud'] ?? []);
    if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $c['issuer'] || !in_array($c['client_id'], $aud, true)) {
        throw new RuntimeException('iss/aud');
    }
    if ((int) ($claims['exp'] ?? 0) < time() - 60 || (int) ($claims['iat'] ?? 0) > time() + 300) {
        throw new RuntimeException('expired');
    }
    if (!hash_equals($st['nonce'], (string) ($claims['nonce'] ?? ''))) {
        throw new RuntimeException('nonce');
    }
    if (empty($claims['email']) && !empty($tok['access_token']) && !empty($d['userinfo_endpoint'])) {
        [$h2, $ui] = oidc_http($d['userinfo_endpoint'], null, ['Authorization: Bearer ' . $tok['access_token']]);
        if ($h2 === 200 && ($ui['sub'] ?? null) === ($claims['sub'] ?? '')) {
            $claims += $ui;
        }
    }
    return $claims;
}

/** ตรวจลายเซ็น JWT (RS256) ด้วย JWKS — ไม่เจอ kid ให้ดึง JWKS ใหม่หนึ่งครั้ง (กรณีหมุนกุญแจ) */
function oidc_verify_jwt(string $jwt, string $jwksUri): array
{
    $p = explode('.', $jwt);
    if (count($p) !== 3) {
        throw new RuntimeException('jwt');
    }
    $h = json_decode(oidc_unb64u($p[0]), true);
    if (($h['alg'] ?? '') !== 'RS256') {
        throw new RuntimeException('alg ' . ($h['alg'] ?? ''));
    }
    foreach ([false, true] as $fresh) {
        foreach ((oidc_cached($jwksUri, 86400, $fresh)['keys'] ?? []) as $k) {
            if (($k['kty'] ?? '') === 'RSA' && (!isset($h['kid']) || ($k['kid'] ?? null) === $h['kid'])) {
                $ok = openssl_verify($p[0] . '.' . $p[1], oidc_unb64u($p[2]), oidc_rsa_pem($k['n'], $k['e']), OPENSSL_ALGO_SHA256);
                if ($ok === 1) {
                    return json_decode(oidc_unb64u($p[1]), true) ?: throw new RuntimeException('claims');
                }
                throw new RuntimeException('signature');
            }
        }
    }
    throw new RuntimeException('kid');
}

/** JWK (n, e) → PEM (SubjectPublicKeyInfo) */
function oidc_rsa_pem(string $n, string $e): string
{
    $int = function (string $b): string {
        if (ord($b[0]) > 0x7f) {
            $b = "\x00" . $b;   // INTEGER ต้องเป็นบวก
        }
        return "\x02" . oidc_der_len(strlen($b)) . $b;
    };
    $seq = fn(string $b) => "\x30" . oidc_der_len(strlen($b)) . $b;
    $rsa = $seq($int(oidc_unb64u($n)) . $int(oidc_unb64u($e)));
    $spki = $seq($seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00") . "\x03" . oidc_der_len(strlen($rsa) + 1) . "\x00" . $rsa);
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function oidc_der_len(int $n): string
{
    if ($n < 0x80) {
        return chr($n);
    }
    $b = ltrim(pack('N', $n), "\x00");
    return chr(0x80 | strlen($b)) . $b;
}

/** หาผู้ใช้หลังบ้านจาก claims — อีเมลต้องตรงกับ admins.email ที่ active (ไม่สร้างใหม่) */
function oidc_find_admin(array $claims): ?array
{
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    if ($email === '') {
        return null;
    }
    $st = db()->prepare('SELECT * FROM admins WHERE lower(email) = ? AND active = 1');
    $st->execute([$email]);
    return $st->fetch() ?: null;
}
