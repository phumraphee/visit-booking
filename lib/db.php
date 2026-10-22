<?php
declare(strict_types=1);
/**
 * ฐานข้อมูลของเรา (SQLite) — แทน Pretix ทั้งหมด: รอบ, ออเดอร์, ตั๋ว, เช็คอิน, แอดมิน
 * ไฟล์: cfg db_path (host: /visit-booking-data/visit.sqlite นอก web root)
 * เวลาใน DB: ISO 8601 พร้อม offset (Asia/Bangkok) เช่น 2026-10-25T10:00:00+07:00
 */

require_once __DIR__ . '/pretix.php';   // cfg(), json_out(), rate_limited(), client_ip(), turnstile_ok(), ghost_subscribe()

const DB_VERSION = 17;

function db_file(): string
{
    $c = cfg();
    return $c['db_path'] ?? ($c['cache_dir'] . '/../visit.sqlite');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $path = db_file();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    db_migrate($pdo);
    return $pdo;
}

function db_migrate(PDO $pdo): void
{
    $v = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($v >= DB_VERSION) {
        return;
    }
    if ($v >= 1) {
        if ($v < 2) {
            db_migrate_v2($pdo);
        }
        if ($v < 3) {
            db_migrate_v3($pdo);
        }
        if ($v < 4) {
            db_migrate_v4($pdo);
        }
        if ($v < 5) {
            db_migrate_v5($pdo);
        }
        if ($v < 6) {
            db_migrate_v6($pdo);
        }
        if ($v < 7) {
            db_migrate_v7($pdo);
        }
        if ($v < 8) {
            db_migrate_v8($pdo);
        }
        if ($v < 9) {
            db_migrate_v9($pdo);
        }
        if ($v < 10) {
            db_migrate_v10($pdo);
        }
        if ($v < 11) {
            db_migrate_v11($pdo);
        }
        if ($v < 12) {
            db_migrate_v12($pdo);
        }
        if ($v < 13) {
            db_migrate_v13($pdo);
        }
        if ($v < 14) {
            db_migrate_v14($pdo);
        }
        if ($v < 15) {
            db_migrate_v15($pdo);
        }
        if ($v < 16) {
            db_migrate_v16($pdo);
        }
        if ($v < 17) {
            db_migrate_v17($pdo);
        }
        $pdo->exec('PRAGMA user_version = ' . DB_VERSION);
        return;
    }
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS sessions (
    id          INTEGER PRIMARY KEY,
    starts_at   TEXT NOT NULL,
    ends_at     TEXT,
    capacity    INTEGER,                 -- NULL = ใช้ค่าเริ่มต้น (settings.default_capacity), 0 = ปิดรับ
    active      INTEGER NOT NULL DEFAULT 1,
    note        TEXT,
    pretix_id   INTEGER UNIQUE,
    created_at  TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS sessions_start ON sessions(starts_at);

CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY,
    code        TEXT NOT NULL UNIQUE,
    secret      TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'p',   -- p = ยืนยัน, c = ยกเลิก
    email       TEXT NOT NULL,
    phone       TEXT,
    given       TEXT,
    family      TEXT,
    org         TEXT,
    lang        TEXT NOT NULL DEFAULT 'en',
    news        INTEGER NOT NULL DEFAULT 0,
    comment     TEXT,
    source      TEXT NOT NULL DEFAULT 'web', -- web | admin | pretix
    created_at  TEXT NOT NULL,
    canceled_at TEXT,
    canceled_by TEXT
);
CREATE INDEX IF NOT EXISTS orders_email ON orders(email);

CREATE TABLE IF NOT EXISTS tickets (
    id            INTEGER PRIMARY KEY,
    order_id      INTEGER NOT NULL REFERENCES orders(id),
    session_id    INTEGER NOT NULL REFERENCES sessions(id),
    n             INTEGER NOT NULL,          -- ลำดับในออเดอร์ (1, 2, …)
    given         TEXT NOT NULL,
    family        TEXT NOT NULL,
    phone         TEXT,
    email         TEXT,
    org           TEXT,
    news          INTEGER NOT NULL DEFAULT 0,
    secret        TEXT NOT NULL UNIQUE,      -- ข้อความใน QR
    canceled      INTEGER NOT NULL DEFAULT 0,
    checked_in_at TEXT,
    checked_in_by TEXT
);
CREATE INDEX IF NOT EXISTS tickets_session ON tickets(session_id, canceled);
CREATE INDEX IF NOT EXISTS tickets_order ON tickets(order_id);

CREATE TABLE IF NOT EXISTS checkin_log (
    id        INTEGER PRIMARY KEY,
    ticket_id INTEGER,
    at        TEXT NOT NULL,
    by        TEXT,
    result    TEXT NOT NULL,   -- ok | already | wrong_session | canceled | unknown | undo
    raw       TEXT
);

CREATE TABLE IF NOT EXISTS admins (
    id         INTEGER PRIMARY KEY,
    email      TEXT NOT NULL UNIQUE,
    name       TEXT,
    role       TEXT NOT NULL DEFAULT 'staff',   -- admin = ทุกอย่าง, staff = สแกน + ดูรายชื่อ
    active     INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS login_codes (
    email      TEXT NOT NULL,
    code_hash  TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    tries      INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS auth_tokens (
    token_hash TEXT PRIMARY KEY,
    admin_id   INTEGER NOT NULL REFERENCES admins(id),
    expires_at INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    ua         TEXT
);

CREATE TABLE IF NOT EXISTS settings (
    k TEXT PRIMARY KEY,
    v TEXT
);
SQL);
    $defaults = [
        'default_capacity' => '30',
        'max_per_order'    => (string) (cfg()['max_per_order'] ?? 10),
        'cancel_minutes'   => '600',   // ยกเลิกเองได้ถึงกี่นาทีก่อนรอบเริ่ม (ค่าเดิมใน Pretix: 600 = 10 ชม.)
        'close_minutes'    => '0',     // ปิดรับจองกี่นาทีก่อนรอบเริ่ม
        'months_ahead'     => (string) (cfg()['months_ahead'] ?? 6),
    ];
    $st = $pdo->prepare('INSERT OR IGNORE INTO settings(k, v) VALUES (?, ?)');
    foreach ($defaults as $k => $val) {
        $st->execute([$k, $val]);
    }
    db_migrate_v2($pdo);
    db_migrate_v3($pdo);
    db_migrate_v4($pdo);
    db_migrate_v5($pdo);
    db_migrate_v6($pdo);
    db_migrate_v7($pdo);
    db_migrate_v8($pdo);
    db_migrate_v9($pdo);
    db_migrate_v10($pdo);
    db_migrate_v11($pdo);
    db_migrate_v12($pdo);
    db_migrate_v13($pdo);
    db_migrate_v14($pdo);
    db_migrate_v15($pdo);
    db_migrate_v16($pdo);
    db_migrate_v17($pdo);
    $pdo->exec('PRAGMA user_version = ' . DB_VERSION);
}

/** v17: เข้าสู่ระบบผ่าน OIDC (Pocket ID ฯลฯ) — auth_tokens.via บอกว่าเข้าด้วยวิธีไหน (ใช้ตอนออกจากระบบ), oidc_states เก็บ state/nonce/PKCE ระหว่างไปหน้า SSO */
function db_migrate_v17(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(auth_tokens)')->fetchAll(), 'name');
    if (!in_array('via', $cols, true)) {
        $pdo->exec('ALTER TABLE auth_tokens ADD COLUMN via TEXT');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS oidc_states (
        state      TEXT PRIMARY KEY,
        nonce      TEXT NOT NULL,
        verifier   TEXT NOT NULL,
        purpose    TEXT NOT NULL,
        back       TEXT NOT NULL,
        expires_at INTEGER NOT NULL
    )');
}

/**
 * v16: orders.idem (กดจองซ้ำตอนเน็ตหลุด = ได้การจองเดิม), orders.ref (มาจากช่องทางไหน: utm/referrer JSON),
 *      orders.ticket_mails (จำนวนอีเมลตั๋วที่ส่งเพราะแก้ข้อมูล — จำกัดกันใช้ส่งสแปม), admins.can_cancel (พนักงานที่ยกเลิกการจองได้)
 */
function db_migrate_v16(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
    foreach (['idem' => 'TEXT', 'ref' => 'TEXT', 'ticket_mails' => 'INTEGER NOT NULL DEFAULT 0'] as $c => $t) {
        if (!in_array($c, $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN $c $t");
        }
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS orders_idem ON orders(idem) WHERE idem IS NOT NULL');
    $cols = array_column($pdo->query('PRAGMA table_info(admins)')->fetchAll(), 'name');
    if (!in_array('can_cancel', $cols, true)) {
        $pdo->exec('ALTER TABLE admins ADD COLUMN can_cancel INTEGER NOT NULL DEFAULT 0');
    }
}

/** v15: เครื่องที่ล็อกอิน (auth_tokens) — ใช้ล่าสุดเมื่อไหร่ + IP สำหรับหน้า "เครื่องที่ล็อกอินอยู่" */
function db_migrate_v15(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(auth_tokens)')->fetchAll(), 'name');
    if (!in_array('last_seen', $cols, true)) {
        $pdo->exec('ALTER TABLE auth_tokens ADD COLUMN last_seen TEXT');
    }
    if (!in_array('ip', $cols, true)) {
        $pdo->exec('ALTER TABLE auth_tokens ADD COLUMN ip TEXT');
    }
}

/** v14: Web Push แจ้งเตือนในเว็บแอป (lib/webpush.php) — เครื่องที่สมัคร + ตั้งค่ารายคน + กันส่งสรุปซ้ำ */
function db_migrate_v14(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS push_subs (
  id INTEGER PRIMARY KEY,
  admin_id INTEGER NOT NULL,
  endpoint TEXT NOT NULL UNIQUE,
  p256dh TEXT NOT NULL,
  auth TEXT NOT NULL,
  ua TEXT,
  created_at TEXT NOT NULL,
  last_ok TEXT
);
CREATE TABLE IF NOT EXISTS push_log (k TEXT PRIMARY KEY, at TEXT NOT NULL);
SQL);
    $cols = array_column($pdo->query('PRAGMA table_info(admins)')->fetchAll(), 'name');
    if (!in_array('push_prefs', $cols, true)) {
        $pdo->exec('ALTER TABLE admins ADD COLUMN push_prefs TEXT');
    }
}

/** v13: ประวัติการใช้งานหลังบ้าน + ย้อนกลับ (lib/audit.php) */
function db_migrate_v13(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY,
  at TEXT NOT NULL,
  actor TEXT NOT NULL,
  action TEXT NOT NULL,
  label TEXT NOT NULL,
  target TEXT,
  link TEXT,
  note TEXT,
  snap TEXT,                   -- JSON [{t, before{pk:row}, after{pk:row}}]
  undoable INTEGER NOT NULL DEFAULT 0,
  undone_at TEXT,
  undone_by TEXT,
  undo_of INTEGER
);
CREATE INDEX IF NOT EXISTS audit_at ON audit_log(at);
SQL);
}

/** v12: ผู้ดูแลสูงสุด (admins.super) — จัดการผู้ใช้หลังบ้านได้คนเดียว; ผู้ดูแลคนแรกที่มีอยู่ = ผู้ดูแลสูงสุด */
function db_migrate_v12(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(admins)')->fetchAll(), 'name');
    if (!in_array('super', $cols, true)) {
        $pdo->exec('ALTER TABLE admins ADD COLUMN super INTEGER NOT NULL DEFAULT 0');
    }
    $pdo->exec("UPDATE admins SET super = 1 WHERE id = (SELECT MIN(id) FROM admins WHERE role = 'admin' AND active = 1)
                AND NOT EXISTS (SELECT 1 FROM admins WHERE super = 1)");
}

/** v11: ประวัติ (event_log), ประวัติอีเมลที่ส่ง (mail_log), โน้ตภายในของการจอง (orders.internal_note) */
function db_migrate_v11(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS event_log (
  id INTEGER PRIMARY KEY,
  at TEXT NOT NULL,
  type TEXT NOT NULL,          -- booked | confirmed | canceled | ticket_canceled | restored | edited | note | attend | waitlist | invited | mass_mail | resend
  order_id INTEGER,
  session_id INTEGER,
  actor TEXT,                  -- customer | attendee | system | admin:<email>
  detail TEXT,
  is_test INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS event_log_order ON event_log(order_id);
CREATE INDEX IF NOT EXISTS event_log_at ON event_log(at);
CREATE TABLE IF NOT EXISTS mail_log (
  id INTEGER PRIMARY KEY,
  at TEXT NOT NULL,
  to_addr TEXT NOT NULL,
  subject TEXT NOT NULL,
  order_code TEXT,
  status TEXT NOT NULL,        -- sent | failed | outbox | preview
  html TEXT
);
CREATE INDEX IF NOT EXISTS mail_log_order ON mail_log(order_code);
SQL);
    $cols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
    if (!in_array('internal_note', $cols, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN internal_note TEXT');
    }
}

/**
 * v10: waiting list (รอบเต็ม → ลงชื่อรอ, แอดมินกดให้สิทธิ์จอง = ลิงก์จองได้แม้เต็ม อายุ waitlist_hours)
 *      ยกเลิกเองได้จนถึงเวลาเริ่มรอบ (cancel_minutes = 0 — ผู้ใช้เลือก 2026-10-18 แทน 600 ของ Pretix)
 */
function db_migrate_v10(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS waitlist (
  id INTEGER PRIMARY KEY,
  session_id INTEGER NOT NULL REFERENCES sessions(id),
  given TEXT NOT NULL,
  family TEXT NOT NULL,
  email TEXT NOT NULL,
  phone TEXT,
  qty INTEGER NOT NULL,
  lang TEXT NOT NULL DEFAULT 'en',
  status TEXT NOT NULL DEFAULT 'waiting',   -- waiting | invited | booked | removed | expired
  token TEXT UNIQUE,
  invited_at TEXT,
  expires_at TEXT,
  order_code TEXT,
  is_test INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS waitlist_session ON waitlist(session_id, status);
SQL);
    $pdo->exec("INSERT OR REPLACE INTO settings(k, v) VALUES ('cancel_minutes', '0')");
    $pdo->exec("INSERT OR IGNORE INTO settings(k, v) VALUES ('waitlist_on', '1'), ('waitlist_hours', '48')");
}

/** v9: ยืนยันอีเมลด้วยรหัสในฟอร์มแทนลิงก์หลังจอง (ผู้ใช้เลือก — คนคิดว่าจองแล้วจบ ไม่กดลิงก์ การจองหาย); SMS ปิดเป็นค่าเริ่มต้น */
function db_migrate_v9(PDO $pdo): void
{
    $pdo->exec("INSERT OR REPLACE INTO settings(k, v) VALUES ('email_verify', '1'), ('email_confirm', '0'), ('sms_verify', '0')");
}

/**
 * v8: ยืนยันการจอง — orders.status 'w' = รอยืนยันอีเมล (กันที่ไว้จนถึง confirm_by), SMS OTP เบอร์ผู้ติดต่อ, ปุ่ม "ยืนยันว่าจะมา" (tickets.attend_at)
 * การจองเดิมทั้งหมดถือว่ายืนยันแล้ว
 */
function db_migrate_v8(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
    foreach (['confirm_by', 'confirmed_at', 'phone_verified_at'] as $c) {
        if (!in_array($c, $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN $c TEXT");
        }
    }
    $pdo->exec("UPDATE orders SET confirmed_at = created_at WHERE confirmed_at IS NULL AND status <> 'w'");
    $cols = array_column($pdo->query('PRAGMA table_info(tickets)')->fetchAll(), 'name');
    if (!in_array('attend_at', $cols, true)) {
        $pdo->exec('ALTER TABLE tickets ADD COLUMN attend_at TEXT');
    }
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS phone_codes (
  id INTEGER PRIMARY KEY,
  phone TEXT NOT NULL,
  code_hash TEXT NOT NULL,
  expires_at INTEGER NOT NULL,
  tries INTEGER NOT NULL DEFAULT 0,
  token TEXT UNIQUE,
  verified_at INTEGER,
  ip TEXT,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS phone_codes_phone ON phone_codes(phone);
SQL);
    foreach (['email_confirm' => '1', 'email_confirm_hours' => '24', 'sms_verify' => '1'] as $k => $v) {
        $pdo->prepare('INSERT OR IGNORE INTO settings(k, v) VALUES (?, ?)')->execute([$k, $v]);
    }
}

/** v7: passkey (Face ID / ลายนิ้วมือ) สำหรับหลังบ้าน */
function db_migrate_v7(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS passkeys (
  id INTEGER PRIMARY KEY,
  admin_id INTEGER NOT NULL REFERENCES admins(id),
  cred_id TEXT NOT NULL UNIQUE,
  public_key TEXT NOT NULL,
  alg INTEGER NOT NULL,
  sign_count INTEGER NOT NULL DEFAULT 0,
  name TEXT,
  created_at TEXT NOT NULL,
  last_used_at TEXT
);
CREATE TABLE IF NOT EXISTS webauthn_challenges (
  challenge TEXT PRIMARY KEY,
  admin_id INTEGER,
  expires_at INTEGER NOT NULL
);
SQL);
}

/** v6: เตือน 1 วันก่อนวันเข้าชม (ตามค่าเดิมใน Pretix) — เปลี่ยนเฉพาะถ้ายังเป็นค่าเริ่มต้นเดิม 2 */
function db_migrate_v6(PDO $pdo): void
{
    $pdo->exec("UPDATE settings SET v = '1' WHERE k = 'reminder_days' AND v = '2'");
}

/** v5: อีเมลเตือนก่อนวันเข้าชม (orders.reminded_at) */
function db_migrate_v5(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
    if (!in_array('reminded_at', $cols, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN reminded_at TEXT');
    }
    foreach (['reminder_on' => '1', 'reminder_days' => '1', 'reminder_hour' => '9'] as $k => $v) {
        $pdo->prepare('INSERT OR IGNORE INTO settings(k, v) VALUES (?, ?)')->execute([$k, $v]);
    }
}

/** v4: ลิงก์เข้าสู่ระบบในอีเมล OTP (login_codes.link_hash, ใช้ครั้งเดียว) */
function db_migrate_v4(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(login_codes)')->fetchAll(), 'name');
    if (!in_array('link_hash', $cols, true)) {
        $pdo->exec('ALTER TABLE login_codes ADD COLUMN link_hash TEXT');
    }
}

/** v3: ลิงก์ตั๋วส่วนตัวของผู้เข้าชม (tickets.msecret — แยกจาก secret ใน QR ที่โชว์หน้างาน) */
function db_migrate_v3(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(tickets)')->fetchAll(), 'name');
    if (!in_array('msecret', $cols, true)) {
        $pdo->exec('ALTER TABLE tickets ADD COLUMN msecret TEXT');
    }
    $up = $pdo->prepare('UPDATE tickets SET msecret = ? WHERE id = ?');
    foreach ($pdo->query('SELECT id FROM tickets WHERE msecret IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $up->execute([random_secret(24), $id]);
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS tickets_msecret ON tickets(msecret)');
}

/** v2: โหมดทดลอง (orders.is_test) + กล่องอีเมลทดสอบ (outbox) */
function db_migrate_v2(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
    if (!in_array('is_test', $cols, true)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN is_test INTEGER NOT NULL DEFAULT 0');
    }
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS outbox (
    id         INTEGER PRIMARY KEY,
    created_at TEXT NOT NULL,
    to_addr    TEXT NOT NULL,
    subject    TEXT NOT NULL,
    html       TEXT NOT NULL,      -- ใช้ cid: ตามจริง (รูปอยู่ใน images)
    images     TEXT NOT NULL,      -- JSON {cid: base64}
    order_code TEXT,
    sent_at    TEXT,
    sent_to    TEXT
);
SQL);
    $pdo->exec("INSERT OR IGNORE INTO settings(k, v) VALUES ('test_mode', '0')");
}

/** โหมดทดลอง — ปิดเองเมื่อเปิดค้างเกิน 24 ชม. (กันลืมปิด: การจองจริงจะไม่นับที่นั่ง และลูกค้าไม่ได้อีเมลตั๋ว) */
const TEST_MODE_HOURS = 24;
function test_mode(): bool
{
    $since = (string) setting('test_mode_since', '');
    if (setting('test_mode', '0') !== '1') {
        if ($since !== '') {
            setting_set('test_mode_since', '');
        }
        return false;
    }
    if ($since === '') {
        setting_set('test_mode_since', now_iso());
        return true;
    }
    if (time() - (int) strtotime($since) > TEST_MODE_HOURS * 3600) {
        setting_set('test_mode', '0');
        setting_set('test_mode_since', '');
        log_event('test_mode_off', null, null, 'ปิดโหมดทดลองอัตโนมัติ (เปิดค้างเกิน ' . TEST_MODE_HOURS . ' ชม.)', 'system');
        return false;
    }
    return true;
}

/** เวลาที่โหมดทดลองจะปิดเอง (ISO) หรือ null */
function test_mode_until(): ?string
{
    return test_mode() && setting('test_mode_since') ? date(DATE_ATOM, (int) strtotime((string) setting('test_mode_since')) + TEST_MODE_HOURS * 3600) : null;
}

function setting(string $k, ?string $default = null): ?string
{
    if (!isset($GLOBALS['__settings'])) {
        $GLOBALS['__settings'] = [];
        foreach (db()->query('SELECT k, v FROM settings') as $r) {
            $GLOBALS['__settings'][$r['k']] = $r['v'];
        }
    }
    return $GLOBALS['__settings'][$k] ?? $default;
}

function setting_set(string $k, string $v): void
{
    db()->prepare('INSERT INTO settings(k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
    unset($GLOBALS['__settings']);
}

/** ตั้งค่าแบบ atomic: เปลี่ยนเป็น $v ได้เฉพาะเมื่อยังไม่ใช่ $v → true = ได้สิทธิ์ทำงาน (กันสองคำขอทำงานเดียวกันพร้อมกัน) */
function setting_claim(string $k, string $v): bool
{
    $st = db()->prepare('INSERT INTO settings(k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v WHERE settings.v IS NOT excluded.v');
    $st->execute([$k, $v]);
    unset($GLOBALS['__settings']);
    return $st->rowCount() === 1;
}

/** บันทึกประวัติ (ไทม์ไลน์ในหน้าการจอง + หน้า "กิจกรรม") — ล้มเหลวไม่กระทบงานหลัก */
function log_event(string $type, ?array $order, ?int $sessionId = null, string $detail = '', ?string $actor = null): void
{
    try {
        if ($order && $sessionId === null) {
            $st = db()->prepare('SELECT session_id FROM tickets WHERE order_id = ? LIMIT 1');
            $st->execute([$order['id']]);
            $sessionId = (int) $st->fetchColumn() ?: null;
        }
        if ($actor === null && function_exists('current_admin') && ($me = current_admin())) {
            $actor = 'admin:' . $me['email'];
        }
        db()->prepare('INSERT INTO event_log (at, type, order_id, session_id, actor, detail, is_test) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([now_iso(), $type, $order['id'] ?? null, $sessionId, $actor, $detail !== '' ? mb_substr($detail, 0, 1000) : null, !empty($order['is_test']) ? 1 : 0]);
    } catch (Throwable $e) {
        error_log('log_event: ' . $e->getMessage());
    }
}

function now_iso(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(cfg()['timezone'] ?? 'Asia/Bangkok')))->format(DATE_ATOM);
}

function tz(): DateTimeZone
{
    return new DateTimeZone(cfg()['timezone'] ?? 'Asia/Bangkok');
}

/** รหัสการจอง 5 ตัว (ไม่มีตัวที่สับสน 0/O/1/I) */
function new_order_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $exists = db()->prepare('SELECT 1 FROM orders WHERE code = ?');
        $exists->execute([$code]);
    } while ($exists->fetchColumn());
    return $code;
}

function random_secret(int $len = 24): string
{
    $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < $len; $i++) {
        $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $s;
}

function session_capacity(array $s): ?int
{
    if ($s['capacity'] !== null && $s['capacity'] !== '') {
        return (int) $s['capacity'];
    }
    $d = setting('default_capacity');
    return ($d === null || $d === '') ? null : (int) $d;
}

/** จำนวนตั๋วที่ใช้ไปของแต่ละรอบ [session_id => count] */
function session_counts(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_map('intval', $ids));
    $out = [];
    foreach (db()->query("SELECT t.session_id, COUNT(*) n, SUM(t.checked_in_at IS NOT NULL) ci FROM tickets t JOIN orders o ON o.id = t.order_id
                          WHERE t.canceled = 0 AND o.is_test = 0 AND t.session_id IN ($in) GROUP BY t.session_id") as $r) {
        $out[(int) $r['session_id']] = ['booked' => (int) $r['n'], 'checked' => (int) $r['ci']];
    }
    return $out;
}

/** รูปแบบรอบประจำ (หลังบ้าน → ตั้งค่า → รูปแบบรอบ) — settings.session_pattern JSON; ยังไม่ตั้ง = อาทิตย์ที่ 2 และ 4, 10:00–12:00 + 14:00–16:00 */
const SESSION_PATTERN_DEFAULT = ['days' => [0], 'weeks' => [2, 4], 'times' => [['10:00', '12:00'], ['14:00', '16:00']]];
function session_pattern(): array
{
    $p = json_decode((string) setting('session_pattern', ''), true);
    return is_array($p) && !empty($p['days']) && !empty($p['times']) ? $p + ['weeks' => []] : SESSION_PATTERN_DEFAULT;
}

/** ตรวจ/จัดรูปแบบรอบ — days 0–6 (0 = อาทิตย์), weeks 1–5 หรือ -1 (สุดท้ายของเดือน), ว่าง = ทุกสัปดาห์, times [[HH:MM, HH:MM], …] */
function session_pattern_clean(array $p): array
{
    $days = array_values(array_unique(array_filter(array_map('intval', (array) ($p['days'] ?? [])), fn($d) => $d >= 0 && $d <= 6)));
    $weeks = array_values(array_unique(array_filter(array_map('intval', (array) ($p['weeks'] ?? [])), fn($w) => ($w >= 1 && $w <= 5) || $w === -1)));
    $times = [];
    foreach ((array) ($p['times'] ?? []) as $t) {
        [$a, $b] = array_map(fn($x) => trim((string) $x), array_pad((array) $t, 2, ''));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $a) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $b) || $b <= $a) {
            throw new InvalidArgumentException('เวลารอบไม่ถูกต้อง: ' . $a . '–' . $b);
        }
        $times[] = [$a, $b];
    }
    usort($times, fn($x, $y) => strcmp($x[0], $y[0]));
    if (!$days || !$times) {
        throw new InvalidArgumentException('ต้องเลือกอย่างน้อย 1 วัน และ 1 รอบเวลา');
    }
    sort($days);
    sort($weeks);
    return ['days' => $days, 'weeks' => $weeks, 'times' => $times];
}

/** วัน-เวลาเริ่ม/จบของรอบตามรูปแบบ ล่วงหน้า $months เดือน (อนาคตเท่านั้น) → [[starts_at, ends_at], …] */
function pattern_slots(int $months, ?array $p = null): array
{
    $p ??= session_pattern();
    $first = new DateTimeImmutable('first day of this month', tz());
    $now = new DateTimeImmutable('now', tz());
    $limit = $now->modify("+$months months");
    $out = [];
    for ($m = 0; $m <= $months; $m++) {
        $month = $first->modify("+$m month");
        $byDow = [];   // วันในสัปดาห์ → [วันที่ 1, 2, …] ของเดือนนี้
        for ($d = $month; $d->format('m') === $month->format('m'); $d = $d->modify('+1 day')) {
            $byDow[(int) $d->format('w')][] = $d;
        }
        $days = [];
        foreach ($p['days'] as $dow) {
            $list = $byDow[$dow] ?? [];
            if (!$p['weeks']) {
                array_push($days, ...$list);
                continue;
            }
            foreach ($p['weeks'] as $w) {
                $x = $w === -1 ? end($list) : ($list[$w - 1] ?? null);
                if ($x) {
                    $days[$x->format('Y-m-d')] = $x;
                }
            }
        }
        foreach ($days as $dObj) {
            $day = $dObj->format('Y-m-d');
            foreach ($p['times'] as [$a, $b]) {
                $sa = new DateTimeImmutable("$day $a", tz());
                if ($sa <= $now || $sa > $limit) {
                    continue;
                }
                $out[$sa->format(DATE_ATOM)] = [$sa->format(DATE_ATOM), (new DateTimeImmutable("$day $b", tz()))->format(DATE_ATOM)];
            }
        }
    }
    ksort($out);
    return array_values($out);
}

/**
 * สร้างรอบตามรูปแบบประจำ (session_pattern()) ล่วงหน้า $months เดือน
 * ข้ามรอบที่มีเวลาเริ่มซ้ำอยู่แล้ว (รวมรอบที่แอดมินปิดไว้ — จะไม่ถูกสร้างใหม่) คืนจำนวนที่สร้าง ($dry = นับอย่างเดียว ไม่สร้าง)
 */
function generate_sessions(int $months, ?array $pattern = null, bool $dry = false): int
{
    $pdo = db();
    $exists = $pdo->prepare('SELECT 1 FROM sessions WHERE starts_at = ?');
    $ins = $pdo->prepare('INSERT INTO sessions (starts_at, ends_at, capacity, active, created_at) VALUES (?, ?, NULL, 1, ?)');
    $made = 0;
    foreach (pattern_slots($months, $pattern) as [$sa, $ea]) {
        $exists->execute([$sa]);
        if ($exists->fetchColumn()) {
            continue;
        }
        if (!$dry) {
            $ins->execute([$sa, $ea, now_iso()]);
        }
        $made++;
    }
    return $made;
}

/** สร้างรอบล่วงหน้าอัตโนมัติวันละครั้ง (settings.auto_generate = 1) */
function auto_generate_sessions(): void
{
    if (setting('auto_generate', '1') !== '1') {
        return;
    }
    $today = (new DateTimeImmutable('now', tz()))->format('Y-m-d');
    if (setting('auto_generated_on') === $today) {
        return;
    }
    setting_set('auto_generated_on', $today);
    generate_sessions((int) setting('months_ahead', '6'));
}

/** รอบที่เปิดจองได้ (อนาคต, active, ไม่เกิน months_ahead, ยังไม่ถึงเวลาปิดรับ) พร้อมที่ว่าง */
function bookable_slots(): array
{
    auto_generate_sessions();
    $now = new DateTimeImmutable('now', tz());
    $horizon = $now->modify('+' . (int) setting('months_ahead', '6') . ' months');
    $close = (int) setting('close_minutes', '0');
    $rows = db()->query("SELECT * FROM sessions WHERE active = 1 ORDER BY starts_at")->fetchAll();
    $rows = array_values(array_filter($rows, function ($s) use ($now, $horizon, $close) {
        $st = new DateTimeImmutable($s['starts_at']);
        return $st->modify("-$close minutes") > $now && $st <= $horizon;
    }));
    $counts = session_counts(array_column($rows, 'id'));

    // ปิดรอบที่ไม่มีคนจอง (ผู้ใช้กำหนด — นับรายรอบ): ถึงเวลา cutoff (ค่าเริ่มต้น เสาร์ 00:00 = หลังวันศุกร์ สำหรับวันอาทิตย์)
    // รอบที่ยังไม่มีคนจอง → แสดงเป็น "ปิดรับจองแล้ว" (ไม่บอกเหตุผล — ไม่ให้รอบหายไปเฉย ๆ); มีคนจองแล้ว → เปิดรับต่อจนถึงเวลาเริ่มรอบ
    $closedIds = [];
    if (setting('empty_close', '1') === '1') {
        $daysBefore = (int) setting('empty_close_days', '1');
        foreach ($rows as $s) {
            $day = (new DateTimeImmutable($s['starts_at']))->setTimezone(tz())->format('Y-m-d');
            $cutoff = (new DateTimeImmutable($day . ' 00:00', tz()))->modify("-$daysBefore days");
            if (($counts[(int) $s['id']]['booked'] ?? 0) === 0 && $now >= $cutoff) {
                $closedIds[(int) $s['id']] = true;
            }
        }
    }
    return array_map(function ($s) use ($counts, $closedIds) {
        $st = (new DateTimeImmutable($s['starts_at']))->setTimezone(tz());
        $en = $s['ends_at'] ? (new DateTimeImmutable($s['ends_at']))->setTimezone(tz()) : null;
        $cap = session_capacity($s);
        $booked = $counts[(int) $s['id']]['booked'] ?? 0;
        $left = $cap === null ? null : max(0, $cap - $booked);
        return [
            'id'        => (int) $s['id'],
            'date'      => $st->format('Y-m-d'),
            'start'     => $st->format('H:i'),
            'end'       => $en ? $en->format('H:i') : null,
            'available' => !isset($closedIds[(int) $s['id']]) && ($left === null || $left > 0),
            'closed'    => isset($closedIds[(int) $s['id']]),
            'left'      => $left,
            'starts_at' => $s['starts_at'],
            'ends_at'   => $s['ends_at'],
        ];
    }, $rows);
}

/** รอบ active ตั้งแต่ต้นเดือนนี้ที่เริ่มไปแล้ว — แสดงในปฏิทินเป็น "จบไปแล้ว" (กดดูได้ จองไม่ได้) */
function past_session_slots(): array
{
    $now = new DateTimeImmutable('now', tz());
    $from = $now->modify('first day of this month')->setTime(0, 0);
    $st = db()->prepare('SELECT * FROM sessions WHERE active = 1 AND starts_at >= ? AND starts_at <= ? ORDER BY starts_at');
    $st->execute([$from->format(DATE_ATOM), $now->format(DATE_ATOM)]);
    return array_map(function ($s) {
        $sl = session_slot($s);
        return ['date' => $sl['date'], 'start' => $sl['start'], 'end' => $sl['end']];
    }, $st->fetchAll());
}

/** ข้อมูลรอบสำหรับแสดงผล/อีเมล ['date','start','end'] */
function session_slot(array $s): array
{
    $st = (new DateTimeImmutable($s['starts_at']))->setTimezone(tz());
    $en = $s['ends_at'] ? (new DateTimeImmutable($s['ends_at']))->setTimezone(tz()) : null;
    return ['date' => $st->format('Y-m-d'), 'start' => $st->format('H:i'), 'end' => $en ? $en->format('H:i') : null,
            'starts_at' => $s['starts_at'], 'ends_at' => $s['ends_at']];
}

/** การจองตัวอย่าง (ลิงก์ในอีเมลตัวอย่างของหลังบ้าน: manage.php?o=PREVW&s=preview) — ไม่มีใน DB, กดดูได้แต่ทำอะไรจริงไม่ได้ */
function is_demo_link(string $code, string $secret): bool
{
    return strtoupper($code) === 'PREVW' && $secret === 'preview';
}

function demo_slot(): array
{
    $st = new DateTimeImmutable('next sunday 10:00', tz());
    return session_slot(['starts_at' => $st->format(DATE_ATOM), 'ends_at' => $st->modify('+2 hours')->format(DATE_ATOM)]);
}

function demo_tickets(string $lang): array
{
    return [['n' => 1, 'name' => $lang === 'th' ? 'สมชาย ใจดี' : 'Somchai Jaidee', 'given' => $lang === 'th' ? 'สมชาย' : 'Somchai', 'family' => $lang === 'th' ? 'ใจดี' : 'Jaidee', 'secret' => 'previewticket0001'],
            ['n' => 2, 'name' => 'Jane Doe', 'given' => 'Jane', 'family' => 'Doe', 'secret' => 'previewticket0002']];
}

function find_order(string $code): ?array
{
    $st = db()->prepare('SELECT * FROM orders WHERE code = ?');
    $st->execute([strtoupper($code)]);
    return $st->fetch() ?: null;
}

function order_tickets(int $orderId): array
{
    $st = db()->prepare('SELECT t.*, s.starts_at, s.ends_at FROM tickets t JOIN sessions s ON s.id = t.session_id WHERE t.order_id = ? ORDER BY t.n');
    $st->execute([$orderId]);
    return $st->fetchAll();
}

/** ยกเลิกเองได้ไหม — คืน [bool, reason] (reason: status | started | deadline) */
function order_cancel_check(array $order, array $tickets): array
{
    if ($order['status'] !== 'p' && $order['status'] !== 'w') {   // w = รอยืนยันอีเมล ยกเลิกเองได้
        return [false, 'status'];
    }
    $active = array_filter($tickets, fn($t) => !$t['canceled']);
    if (!$active) {
        return [false, 'status'];
    }
    $first = min(array_map(fn($t) => $t['starts_at'], $active));
    $start = new DateTimeImmutable($first);
    $now = new DateTimeImmutable('now', tz());
    if ($start <= $now) {
        return [false, 'started'];
    }
    $mins = (int) setting('cancel_minutes', '600');
    if ($start->modify("-$mins minutes") <= $now) {
        return [false, 'deadline'];
    }
    return [true, ''];
}

/** รอบที่ผ่านวันไปแล้ว (ก่อนวันนี้ตามเวลาไทย) = ล็อก: ห้ามเช็คอิน/ยกเลิกเช็คอิน/ยกเลิกการจอง — ภายในวันของรอบยังแก้ได้ */
function session_locked(string $startsAt): bool
{
    $day = (new DateTimeImmutable($startsAt))->setTimezone(tz())->format('Y-m-d');
    return $day < (new DateTimeImmutable('now', tz()))->format('Y-m-d');
}

/** ตั๋ว + ออเดอร์ + รอบ จาก msecret (ลิงก์ตั๋วส่วนตัว) */
function find_ticket_by_msecret(string $ms): ?array
{
    $st = db()->prepare('SELECT t.*, o.code, o.status AS order_status, o.lang AS order_lang, o.is_test, o.secret AS order_secret, o.email AS order_email, o.id AS oid, s.starts_at, s.ends_at
                         FROM tickets t JOIN orders o ON o.id = t.order_id JOIN sessions s ON s.id = t.session_id WHERE t.msecret = ?');
    $st->execute([$ms]);
    return $st->fetch() ?: null;
}

function ticket_manage_url(array $t): string
{
    return public_url('manage.php?t=' . rawurlencode((string) $t['msecret']));
}

/** ยกเลิกตั๋วใบเดียว — ถ้าไม่เหลือตั๋วที่ใช้ได้ในออเดอร์ ยกเลิกทั้งออเดอร์ */
function cancel_ticket(array $t, string $by): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE tickets SET canceled = 1 WHERE id = ?')->execute([$t['id']]);
    $left = $pdo->prepare('SELECT COUNT(*) FROM tickets WHERE order_id = ? AND canceled = 0');
    $left->execute([$t['order_id']]);
    if ((int) $left->fetchColumn() === 0) {
        $pdo->prepare("UPDATE orders SET status = 'c', canceled_at = ?, canceled_by = ? WHERE id = ?")->execute([now_iso(), $by, $t['order_id']]);
    }
    $pdo->commit();
}

function cancel_order(array $order, string $by): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE orders SET status = 'c', canceled_at = ?, canceled_by = ? WHERE id = ?")->execute([now_iso(), $by, $order['id']]);
    $pdo->prepare('UPDATE tickets SET canceled = 1 WHERE order_id = ?')->execute([$order['id']]);
    $pdo->commit();
}
