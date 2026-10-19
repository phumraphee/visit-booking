<?php
declare(strict_types=1);
/**
 * ประวัติผู้เข้าชม — ระบุตัวด้วยอีเมล หรือเบอร์ หรือชื่อ+นามสกุลตรงกันทั้งคู่ (อย่างใดอย่างหนึ่ง = คนเดียวกัน), ไม่รวมการจองทดสอบ
 * ใช้แสดง "เคยมา / ไม่มา / ยกเลิก" ในหลังบ้าน (ข้อความหน้าจอง: no-show อาจมีผลต่อการจองครั้งต่อไป)
 * ชื่อซ้ำกันได้ (คนละคนชื่อเหมือนกัน) — ผู้ใช้ยอมรับ ให้เทียบเฉพาะเมื่อชื่อและนามสกุลตรงกันทั้งคู่
 */
require_once __DIR__ . '/db.php';

/** เงื่อนไข SQL + พารามิเตอร์สำหรับจับคนเดียวกันในตาราง tickets (t) */
function history_where(?string $email, ?string $phone, ?string $given = null, ?string $family = null, string $a = 't'): ?array
{
    $given = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $given)));
    $family = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $family)));
    $email = mb_strtolower(trim((string) $email));
    $phone = trim((string) $phone);
    $w = [];
    $p = [];
    if ($email !== '') {
        $w[] = "lower($a.email) = ?";
        $p[] = $email;
    }
    if ($phone !== '') {
        $w[] = "$a.phone = ?";
        $p[] = $phone;
    }
    if ($given !== '' && $family !== '') {
        $w[] = "(lower(trim($a.given)) = ? AND lower(trim($a.family)) = ?)";
        array_push($p, $given, $family);
    }
    return $w ? ['(' . implode(' OR ', $w) . ')', $p] : null;
}

/** สรุป: total, attended, noshow, canceled, upcoming (ไม่นับการจอง $excludeOrderId ทั้งการจอง — คนในการจองเดียวกันมักใช้เบอร์/อีเมลร่วมกัน) — null = ระบุตัวไม่ได้ */
function ticket_history(?string $email, ?string $phone, int $excludeId = 0, ?string $given = null, ?string $family = null): ?array
{
    static $cache = [];
    $k = mb_strtolower((string) $email) . '|' . $phone . '|' . $excludeId . '|' . mb_strtolower("$given|$family");
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    $wp = history_where($email, $phone, $given, $family);
    if (!$wp) {
        return $cache[$k] = null;
    }
    [$where, $params] = $wp;
    $st = db()->prepare("SELECT COUNT(*) total,
            SUM(t.canceled = 0 AND t.checked_in_at IS NOT NULL) attended,
            SUM(t.canceled = 0 AND t.checked_in_at IS NULL AND s.starts_at < ?) noshow,
            SUM(t.canceled = 1) canceled,
            SUM(t.canceled = 0 AND s.starts_at >= ?) upcoming
        FROM tickets t JOIN orders o ON o.id = t.order_id JOIN sessions s ON s.id = t.session_id
        WHERE o.is_test = 0 AND t.order_id <> ? AND $where");
    $today = (new DateTimeImmutable('today', tz()))->format(DATE_ATOM);
    $st->execute(array_merge([$today, $today, $excludeId], $params));
    $r = array_map('intval', $st->fetch(PDO::FETCH_ASSOC));
    return $cache[$k] = $r;
}

/** ผู้ติดต่อ: การจองที่เคยทำ (นับเป็นออเดอร์) — ไม่มา = รอบผ่านแล้ว ไม่ยกเลิก และไม่มีใครในการจองเช็คอินเลย */
function contact_history(?string $email, ?string $phone, int $excludeOrderId = 0, ?string $given = null, ?string $family = null): ?array
{
    $wp = history_where($email, $phone, $given, $family, 'o');
    if (!$wp) {
        return null;
    }
    [$where, $params] = $wp;
    $st = db()->prepare("SELECT o.id, o.status, MIN(s.starts_at) first_start, SUM(t.checked_in_at IS NOT NULL) ci
        FROM orders o JOIN tickets t ON t.order_id = o.id JOIN sessions s ON s.id = t.session_id
        WHERE o.is_test = 0 AND o.id <> ? AND $where GROUP BY o.id");
    $st->execute(array_merge([$excludeOrderId], $params));
    $now = new DateTimeImmutable('now', tz());
    $r = ['total' => 0, 'attended' => 0, 'noshow' => 0, 'canceled' => 0, 'upcoming' => 0];
    foreach ($st->fetchAll() as $o) {
        $r['total']++;
        if ($o['status'] === 'c') {
            $r['canceled']++;
        } elseif ((int) $o['ci'] > 0) {
            $r['attended']++;
        } elseif (new DateTimeImmutable($o['first_start']) < $now) {
            $r['noshow']++;
        } else {
            $r['upcoming']++;
        }
    }
    return $r;
}

/** ข้อความสั้นสำหรับแจ้งเตือนแอดมิน (เฉพาะเมื่อมีประวัติที่ควรรู้) */
function history_note(?array $h): string
{
    if (!$h || !$h['total']) {
        return '';
    }
    $parts = [];
    if ($h['noshow']) {
        $parts[] = '⚠️ เคยจองแต่ไม่มา ' . $h['noshow'] . ' ครั้ง';
    }
    if ($h['attended']) {
        $parts[] = 'เคยมาแล้ว ' . $h['attended'] . ' ครั้ง';
    }
    if ($h['canceled']) {
        $parts[] = 'เคยยกเลิก ' . $h['canceled'] . ' ครั้ง';
    }
    return $parts ? 'ประวัติผู้จอง: ' . implode(' · ', $parts) : '';
}
