<?php
declare(strict_types=1);
// เพิ่มแอดมินคนแรก (หลังจากนั้นเพิ่มผ่านหลังบ้าน): php tools/add_admin.php email@example.com [admin|staff] [ชื่อ]
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
require_once __DIR__ . '/../lib/db.php';
$email = mb_strtolower(trim($argv[1] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "usage: php tools/add_admin.php email [admin|staff] [name]\n"); exit(1); }
$role = ($argv[2] ?? 'admin') === 'staff' ? 'staff' : 'admin';
db()->prepare('INSERT INTO admins (email, name, role, active, created_at) VALUES (?, ?, ?, 1, ?) ON CONFLICT(email) DO UPDATE SET role = excluded.role, active = 1')
    ->execute([$email, $argv[3] ?? null, $role, now_iso()]);
echo "ok: $email ($role)\n";
