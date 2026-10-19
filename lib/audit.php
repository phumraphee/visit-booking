<?php
declare(strict_types=1);
/**
 * ประวัติการใช้งานหลังบ้าน (audit_log) + ย้อนกลับ — ผู้ดูแลสูงสุดเห็นทุกการกระทำของทุกคน (ผู้ใช้กำหนด 2026-10-18)
 * ทำงานแบบ snapshot: ก่อนทำ action เก็บแถวที่เกี่ยวข้อง (before) → หลังตอบสำเร็จเก็บอีกครั้ง (after)
 * ย้อนกลับ = เขียนแถว before กลับ + ลบแถวที่ action สร้างขึ้นใหม่ (การย้อนกลับก็ถูกบันทึกเป็นรายการใหม่ → ย้อนการย้อนได้)
 * อีเมล/แจ้งเตือนที่ส่งออกไปแล้วเรียกคืนไม่ได้
 */
require_once __DIR__ . '/db.php';

const AUDIT_PK = ['settings' => 'k'];   // ตารางอื่นใช้ id
const AUDIT_HIDE = ['secret', 'msecret', 'token', 'public_key', 'cred_id', 'html', 'images', 'sign_count'];   // ไม่ส่งไปหน้าเว็บ

/**
 * spec = [table, where, params, new?] — new=true: เก็บแถวที่ถูกสร้างใหม่ (id มากกว่าเดิม) ด้วย
 * ลำดับใน spec: ตารางแม่ก่อนลูก (orders ก่อน tickets) — ตอนย้อนกลับจะ insert ตามลำดับ และลบย้อนลำดับ
 */
function audit_snap(array $specs, ?array $maxIds = null, ?array $prev = null): array
{
    $pdo = db();
    $out = [];
    foreach ($specs as $i => $sp) {
        [$t, $where, $params] = $sp;
        $pk = AUDIT_PK[$t] ?? 'id';
        $sql = "SELECT * FROM $t WHERE ($where)";
        $p = $params;
        if (!empty($sp[3]) && $maxIds !== null) {
            $sql .= " OR $pk > ?";
            $p[] = $maxIds[$i];
        }
        // หลังทำ: ตามแถวเดิมด้วย pk เสมอ (แถวอาจไม่ตรง where แล้ว เช่น order_code ถูกล้าง)
        $keep = $prev ? array_keys($prev[$i]['rows']) : [];
        if ($keep) {
            $sql .= " OR $pk IN (" . implode(',', array_fill(0, count($keep), '?')) . ')';
            array_push($p, ...array_map('strval', $keep));
        }
        $st = $pdo->prepare($sql);
        $st->execute($p);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[(string) $r[$pk]] = $r;
        }
        $out[] = ['t' => $t, 'rows' => $rows];
    }
    return $out;
}

/** เริ่มบันทึก: snapshot ก่อน + บันทึกหลังตอบสำเร็จ (shutdown — json_out() เรียก exit) */
function audit_begin(string $action, array $meta, array $specs): void
{
    $maxIds = [];
    foreach ($specs as $sp) {
        $pk = AUDIT_PK[$sp[0]] ?? 'id';
        $maxIds[] = !empty($sp[3]) ? (int) db()->query("SELECT COALESCE(MAX($pk), 0) FROM {$sp[0]}")->fetchColumn() : 0;
    }
    $before = audit_snap($specs);
    register_shutdown_function(function () use ($action, $meta, $specs, $before, $maxIds) {
        $code = http_response_code();
        if (!is_int($code) || $code < 200 || $code >= 300) {
            return;
        }
        try {
            $after = audit_snap($specs, $maxIds, $before);
            $snap = [];
            foreach ($before as $i => $b) {
                $snap[] = ['t' => $b['t'], 'before' => $b['rows'], 'after' => $after[$i]['rows']];
            }
            $changed = audit_changed($snap);
            if ($specs && !$changed && empty($meta['always'])) {
                return;   // ไม่มีอะไรเปลี่ยน (เช่น กดบันทึกโดยไม่แก้) ไม่ต้องบันทึก
            }
            audit_write($action, $meta, $changed ? $snap : [], !empty($specs) && $changed && empty($meta['no_undo']));
        } catch (Throwable $e) {
            error_log('audit: ' . $e->getMessage());
        }
    });
}

function audit_write(string $action, array $meta, array $snap, bool $undoable, ?int $undoOf = null): int
{
    $me = current_admin();
    db()->prepare('INSERT INTO audit_log (at, actor, action, label, target, link, note, snap, undoable, undo_of) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([now_iso(), $me['email'] ?? ($meta['actor'] ?? '?'), $action, $meta['label'] ?? $action, $meta['target'] ?? null, $meta['link'] ?? null,
            $meta['note'] ?? null, $snap ? json_encode($snap, JSON_UNESCAPED_UNICODE) : null, $undoable ? 1 : 0, $undoOf]);
    return (int) db()->lastInsertId();
}

/** มีแถวไหนเปลี่ยนจริงไหม */
function audit_changed(array $snap): bool
{
    foreach ($snap as $s) {
        if ($s['before'] != $s['after']) {
            return true;
        }
    }
    return false;
}

/** สรุปการเปลี่ยนแปลงต่อแถว (สำหรับแสดงผล) — ไม่รวมช่องลับ */
function audit_diff(array $snap): array
{
    $out = [];
    foreach ($snap as $s) {
        $keys = array_unique(array_merge(array_keys($s['before']), array_keys($s['after'])));
        foreach ($keys as $k) {
            $b = $s['before'][$k] ?? null;
            $a = $s['after'][$k] ?? null;
            if ($b == $a) {
                continue;
            }
            $f = [];
            if ($b && $a) {
                foreach ($a as $col => $v) {
                    if (!in_array($col, AUDIT_HIDE, true) && (string) ($b[$col] ?? '') !== (string) $v) {
                        $f[] = [$col, $b[$col] ?? null, $v];
                    }
                }
            }
            $row = $a ?: $b;
            $out[] = ['t' => $s['t'], 'id' => (string) $k, 'kind' => !$b ? 'add' : (!$a ? 'del' : 'edit'), 'fields' => $f, 'name' => audit_rowname($s['t'], $row)];
        }
    }
    return $out;
}

function audit_rowname(string $t, array $r): string
{
    return match ($t) {
        'orders' => (string) $r['code'] . ' · ' . trim($r['given'] . ' ' . $r['family']),
        'tickets' => '#' . $r['n'] . ' ' . trim($r['given'] . ' ' . $r['family']),
        'sessions' => substr((string) $r['starts_at'], 0, 16),
        'settings' => (string) $r['k'],
        'admins' => (string) $r['email'],
        'waitlist' => trim($r['given'] . ' ' . $r['family']),
        'passkeys' => (string) ($r['name'] ?? 'passkey'),
        default => $t . ' #' . ($r['id'] ?? ''),
    };
}

/**
 * ย้อนกลับรายการ $id — คืน ['ok'] หรือ ['conflict' => [...]] (ถ้ามีการเปลี่ยนแปลงหลังจากนั้น ต้องส่ง force)
 */
function audit_undo(int $id, bool $force): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM audit_log WHERE id = ?');
    $st->execute([$id]);
    $e = $st->fetch();
    if (!$e || !$e['undoable'] || !$e['snap']) {
        return ['error' => 'ย้อนกลับรายการนี้ไม่ได้'];
    }
    if ($e['undone_at']) {
        return ['error' => 'ย้อนกลับไปแล้ว'];
    }
    $snap = json_decode($e['snap'], true);
    // สถานะปัจจุบันของแถวที่เกี่ยวข้อง
    $cur = [];
    $conflict = [];
    foreach ($snap as $i => $s) {
        $pk = AUDIT_PK[$s['t']] ?? 'id';
        $keys = array_unique(array_merge(array_keys($s['before']), array_keys($s['after'])));
        $cur[$i] = [];
        foreach ($keys as $k) {
            $q = $pdo->prepare("SELECT * FROM {$s['t']} WHERE $pk = ?");
            $q->execute([$k]);
            if ($r = $q->fetch()) {
                $cur[$i][(string) $k] = $r;
            }
            if (($cur[$i][(string) $k] ?? null) != ($s['after'][$k] ?? null)) {
                $conflict[] = audit_rowname($s['t'], $cur[$i][(string) $k] ?? $s['after'][$k] ?? $s['before'][$k]);
            }
        }
    }
    if ($conflict && !$force) {
        return ['conflict' => array_values(array_unique($conflict))];
    }
    $pdo->beginTransaction();
    try {
        // ลบแถวที่ไม่มีใน before (ลูกก่อนแม่)
        for ($i = count($snap) - 1; $i >= 0; $i--) {
            $s = $snap[$i];
            $pk = AUDIT_PK[$s['t']] ?? 'id';
            foreach ($cur[$i] as $k => $_) {
                if (!isset($s['before'][$k])) {
                    $pdo->prepare("DELETE FROM {$s['t']} WHERE $pk = ?")->execute([$k]);
                }
            }
        }
        // เขียน before กลับ (แม่ก่อนลูก)
        foreach ($snap as $i => $s) {
            $pk = AUDIT_PK[$s['t']] ?? 'id';
            foreach ($s['before'] as $k => $row) {
                $cols = array_keys($row);
                if (isset($cur[$i][$k])) {
                    $set = implode(', ', array_map(fn($c) => "\"$c\" = ?", $cols));
                    $pdo->prepare("UPDATE {$s['t']} SET $set WHERE $pk = ?")->execute([...array_values($row), $k]);
                } else {
                    $pdo->prepare("INSERT INTO {$s['t']} (\"" . implode('", "', $cols) . '") VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                        ->execute(array_values($row));
                }
            }
        }
        // รายการย้อนกลับ (ย้อนการย้อนได้)
        $rsnap = [];
        foreach ($snap as $i => $s) {
            $rsnap[] = ['t' => $s['t'], 'before' => $cur[$i], 'after' => $s['before']];
        }
        $me = current_admin();
        $pdo->prepare('UPDATE audit_log SET undone_at = ?, undone_by = ? WHERE id = ?')->execute([now_iso(), $me['email'] ?? '?', $id]);
        audit_write('undo', ['label' => 'ย้อนกลับ: ' . $e['label'], 'target' => $e['target'], 'link' => $e['link']], $rsnap, true, $id);
        $pdo->commit();
    } catch (Throwable $x) {
        $pdo->rollBack();
        return ['error' => 'ย้อนกลับไม่สำเร็จ: ' . $x->getMessage()];
    }
    // ไทม์ไลน์ของการจอง
    foreach ($snap as $s) {
        if ($s['t'] === 'orders') {
            foreach ($s['before'] + $s['after'] as $o) {
                if (function_exists('log_event')) {
                    log_event('note', ['id' => (int) $o['id'], 'is_test' => (int) $o['is_test']], null, 'ผู้ดูแลสูงสุดย้อนกลับ: ' . $e['label']);
                }
            }
        }
    }
    return ['ok' => true];
}

/** ตั้งค่าการบันทึกตาม action ของ api/admin.php — null = ไม่บันทึก (อ่านอย่างเดียว) */
function audit_plan(string $action, array $in): ?array
{
    $pdo = db();
    $oid = function () use ($in, $pdo): array {
        $st = $pdo->prepare('SELECT id, code FROM orders WHERE code = ?');
        $st->execute([strtoupper(trim((string) ($in['code'] ?? '')))]);
        $o = $st->fetch();
        return $o ? [(int) $o['id'], $o['code']] : [0, ''];
    };
    $tOrder = function (int $tid) use ($pdo): array {
        $st = $pdo->prepare('SELECT o.id, o.code FROM tickets t JOIN orders o ON o.id = t.order_id WHERE t.id = ?');
        $st->execute([$tid]);
        $o = $st->fetch();
        return $o ? [(int) $o['id'], $o['code']] : [0, ''];
    };
    $orderSpecs = fn(int $id) => [['orders', 'id = ?', [$id]], ['tickets', 'order_id = ?', [$id]]];
    $ord = fn(string $label, array $o, array $extra = []) => [['label' => $label, 'target' => $o[1], 'link' => $o[1] ? '#/order/' . $o[1] : null] + $extra, $orderSpecs($o[0])];
    switch ($action) {
        case 'order_edit': return $ord('แก้ข้อมูลการจอง', $oid());
        case 'order_note': return $ord('แก้โน้ตภายใน', $oid());
        case 'order_mark_test': return $ord(!empty($in['on']) ? 'ทำเป็นการจองทดสอบ' : 'ยกเลิกเครื่องหมายทดสอบ', $oid());
        case 'order_confirm': return $ord('ยืนยันแทนผู้จอง (ส่งตั๋ว)', $oid(), ['note' => 'ส่งอีเมลตั๋วแล้ว']);
        case 'order_create': return [['label' => 'เพิ่มการจอง (พนักงาน)', 'target' => trim((string) ($in['given'] ?? '') . ' ' . (string) ($in['family'] ?? '')), 'link' => null], [['orders', '0', [], true], ['tickets', '0', [], true]]];
        case 'order_cancel': return $ord('ยกเลิกการจอง', $oid(), ['note' => !empty($in['notify']) ? 'ส่งอีเมลแจ้งผู้จองแล้ว' : null]);
        case 'order_restore': return $ord('คืนการจอง', $oid(), ['note' => !empty($in['notify']) ? 'ส่งอีเมลตั๋วอีกครั้งแล้ว' : null]);
        case 'order_resend': $o = $oid(); return [['label' => 'ส่งอีเมลยืนยันอีกครั้ง', 'target' => $o[1], 'link' => '#/order/' . $o[1], 'note' => (string) ($in['to'] ?? ''), 'always' => true], []];
        case 'order_delete':
            [$id, $code] = $oid();
            return [['label' => 'ลบการจองทดสอบ', 'target' => $code, 'link' => null], [
                ['orders', 'id = ?', [$id]], ['tickets', 'order_id = ?', [$id]], ['checkin_log', 'ticket_id IN (SELECT id FROM tickets WHERE order_id = ?)', [$id]],
                ['event_log', 'order_id = ?', [$id]], ['mail_log', 'order_code = ?', [$code]], ['outbox', 'order_code = ?', [$code]], ['waitlist', 'order_code = ?', [$code]]]];
        case 'ticket_cancel': return $ord('ยกเลิกผู้เข้าชม 1 คน', $tOrder((int) ($in['id'] ?? 0)), ['note' => !empty($in['notify']) ? 'ส่งอีเมลแจ้งแล้ว' : null]);
        case 'ticket_resend': $o = $tOrder((int) ($in['id'] ?? 0)); return [['label' => 'ส่งตั๋วให้ผู้เข้าชม', 'target' => $o[1], 'link' => '#/order/' . $o[1], 'always' => true], []];
        case 'checkin':
            $st = $pdo->prepare('SELECT id FROM tickets WHERE secret = ?');
            $st->execute([(string) ($in['secret'] ?? '')]);
            $o = $tOrder((int) $st->fetchColumn());
            return [['label' => 'เช็คอิน (สแกน)', 'target' => $o[1], 'link' => $o[1] ? '#/order/' . $o[1] : null], [['tickets', 'secret = ?', [(string) ($in['secret'] ?? '')]]]];
        case 'checkin_ticket':
            $o = $tOrder((int) ($in['id'] ?? 0));
            return [['label' => !empty($in['undo']) ? 'ยกเลิกเช็คอิน' : 'เช็คอิน (กดเอง)', 'target' => $o[1], 'link' => $o[1] ? '#/order/' . $o[1] : null], [['tickets', 'id = ?', [(int) ($in['id'] ?? 0)]]]];
        case 'scan_sync':
            $sec = array_values(array_filter(array_map(fn($q) => (string) ($q['secret'] ?? ''), (array) ($in['queue'] ?? []))));
            if (!$sec) {
                return null;
            }
            return [['label' => 'เช็คอินตอนออฟไลน์ (' . count($sec) . ')', 'target' => null, 'link' => '#/session/' . (int) ($in['session'] ?? 0)],
                [['tickets', 'secret IN (' . implode(',', array_fill(0, count($sec), '?')) . ')', $sec]]];
        case 'session_save': $id = (int) ($in['id'] ?? 0); return [['label' => 'ตั้งค่ารอบ', 'target' => null, 'link' => '#/session/' . $id], [['sessions', 'id = ?', [$id]]]];
        case 'session_create': return [['label' => 'เพิ่มรอบพิเศษ', 'target' => trim(($in['date'] ?? '') . ' ' . ($in['start'] ?? '')), 'link' => null], [['sessions', '0', [], true]]];
        case 'sessions_generate': return [['label' => 'สร้างรอบล่วงหน้า', 'target' => null, 'link' => null], [['sessions', '0', [], true]]];
        case 'session_mail':
            if (!empty($in['dry'])) {
                return null;
            }
            return [['label' => 'ส่งอีเมลถึงทั้งรอบ', 'target' => mb_substr((string) ($in['subject'] ?? ''), 0, 80), 'link' => null, 'always' => true], []];
        case 'waitlist_invite': case 'waitlist_remove':
            $id = (int) ($in['id'] ?? 0);
            return [['label' => $action === 'waitlist_invite' ? 'ให้สิทธิ์จอง (รายชื่อรอ)' : 'ลบจากรายชื่อรอ', 'target' => null, 'link' => null, 'note' => $action === 'waitlist_invite' ? 'ส่งอีเมลสิทธิ์จองแล้ว' : null], [['waitlist', 'id = ?', [$id]]]];
        case 'admin_save':
            $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
            return [['label' => 'จัดการผู้ใช้', 'target' => $email, 'link' => '#/settings'], [['admins', 'email = ?', [$email]]]];
        case 'me_email_verify': $me = current_admin(); return [['label' => 'เปลี่ยนอีเมลตัวเอง', 'target' => null, 'link' => null], [['admins', 'id = ?', [(int) ($me['id'] ?? 0)]]]];
        case 'admin_email': return [['label' => 'เปลี่ยนอีเมลผู้ใช้', 'target' => null, 'link' => '#/settings'], [['admins', 'id = ?', [(int) ($in['id'] ?? 0)]]]];
        case 'sessions_revoke':
            return [['label' => !empty($in['others']) ? 'ออกจากระบบทุกเครื่อง (ยกเว้นเครื่องที่ใช้)' : 'ออกจากระบบเครื่องที่เลือก', 'target' => null, 'link' => '#/account', 'always' => true], []];
        case 'me_name': $me = current_admin(); return [['label' => 'แก้ชื่อตัวเอง', 'target' => null, 'link' => null], [['admins', 'id = ?', [(int) ($me['id'] ?? 0)]]]];
        case 'texts': return ($in['op'] ?? '') === 'save' ? [['label' => 'แก้ข้อความ', 'target' => (string) ($in['key'] ?? ''), 'link' => '#/texts'], [['settings', "k = 'texts'", []]]] : null;
        case 'pattern': return ($in['op'] ?? '') === 'save' ? [['label' => 'ตั้งรูปแบบรอบ', 'target' => null, 'link' => '#/settings'], [['settings', "k = 'session_pattern'", []], ['sessions', '0', [], true]]] : null;
        case 'venue': return (!empty($in['values']) || array_key_exists('logo', $in)) ? [['label' => 'แก้ข้อมูลสถานที่', 'target' => null, 'link' => '#/settings'], [['settings', "k LIKE 'venue_%'", []]]] : null;
        case 'settings':
            if (!isset($in['values']) || !is_array($in['values'])) {
                return null;
            }
            return [['label' => 'แก้การตั้งค่า', 'target' => null, 'link' => '#/settings'], [['settings', '1', []]]];
        case 'test_mode':
            if (!array_key_exists('on', $in)) {
                return null;
            }
            return [['label' => !empty($in['on']) ? 'เปิดโหมดทดลอง' : 'ปิดโหมดทดลอง', 'target' => null, 'link' => '#/settings'], [['settings', "k = 'test_mode'", []]]];
        case 'test_purge':
            $t = 'order_id IN (SELECT id FROM orders WHERE is_test = 1)';
            return [['label' => 'ลบข้อมูลทดสอบทั้งหมด', 'target' => null, 'link' => null], [
                ['orders', 'is_test = 1', []], ['tickets', $t, []], ['checkin_log', "ticket_id IN (SELECT id FROM tickets WHERE $t)", []],
                ['event_log', "$t OR is_test = 1", []], ['mail_log', 'order_code IN (SELECT code FROM orders WHERE is_test = 1)', []]]
                + (!empty($in['outbox']) ? [5 => ['outbox', '1', []]] : [])];
        case 'outbox_send': return [['label' => 'ส่งอีเมลจากกล่องทดสอบออกจริง', 'target' => (string) ($in['to'] ?? ''), 'link' => '#/mail', 'always' => true], []];
        case 'noshow_mail':
            if (!empty($in['dry'])) {
                return null;
            }
            return [['label' => 'ส่งอีเมลแจ้งไม่มาเข้าชม', 'target' => (string) ($in['code'] ?? ''), 'link' => !empty($in['code']) ? '#/order/' . $in['code'] : null, 'always' => true], []];
        case 'sessions_cancel':
            if (!empty($in['dry'])) {
                return null;
            }
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($in['sessions'] ?? ''))))) ?: [0];
            $inIds = implode(',', $ids);
            return [['label' => count($ids) > 1 ? 'ยกเลิกทั้งวัน' : 'ยกเลิกทั้งรอบ', 'target' => null, 'link' => '#/session/' . $ids[0],
                'note' => trim(((string) ($in['reason'] ?? '')) . (!empty($in['notify']) ? ' · ส่งอีเมลแจ้งผู้จองแล้ว' : ''), ' ·')],
                [['sessions', "id IN ($inIds)", []], ['orders', "id IN (SELECT order_id FROM tickets WHERE session_id IN ($inIds))", []], ['tickets', "session_id IN ($inIds)", []]]];
        case 'login_code_issue':
            return [['label' => 'ออกรหัสเข้าสู่ระบบให้ผู้ใช้', 'target' => null, 'link' => '#/settings', 'always' => true], []];
        case 'ghost_backfill':
            return !empty($in['dry']) ? null : [['label' => 'ส่งรายชื่อรับข่าวสารย้อนหลังเข้า Ghost', 'target' => null, 'link' => '#/settings', 'always' => true], []];
        case 'wallet_csr':
            return [['label' => 'สร้าง CSR ใบรับรอง Apple Wallet', 'target' => null, 'link' => '#/settings', 'always' => true], []];
        case 'wallet_cert_upload':
            return [['label' => 'ติดตั้งใบรับรอง Apple Wallet ใหม่', 'target' => null, 'link' => '#/settings', 'always' => true, 'note' => 'ของเดิมสำรองไว้ (.bak) บนโฮสต์'], []];
        case 'integrations':
            if (empty($in['values']) && empty($in['clear'])) {
                return null;
            }
            return [['label' => 'แก้บริการภายนอก (Turnstile/Ghost)', 'target' => null, 'link' => '#/settings', 'always' => true, 'note' => 'ค่าลับไม่บันทึกในประวัติ'], []];
        case 'notify_test': return [['label' => 'ส่งทดสอบการแจ้งเตือน', 'target' => null, 'link' => null, 'always' => true], []];
        case 'passkey_register': return [['label' => 'ตั้ง Face ID / passkey', 'target' => (string) ($in['name'] ?? ''), 'link' => null, 'no_undo' => true], [['passkeys', '0', [], true]]];
        case 'passkey_delete': return [['label' => 'ลบ passkey', 'target' => null, 'link' => null], [['passkeys', 'id = ?', [(int) ($in['id'] ?? 0)]]]];
    }
    return null;
}
