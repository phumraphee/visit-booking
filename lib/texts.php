<?php
declare(strict_types=1);
/**
 * ข้อความที่แก้ในหลังบ้าน (ตั้งค่า → ข้อความ) — เก็บเฉพาะคำที่แก้ใน settings.texts = {scope: {th: {key: text}, en: {…}}}
 * scope: mail (อีเมล — MAIL_T ใน lib/emails.php), noshow (อีเมลแจ้งไม่มา), I18N_BOOKING (หน้าจอง), I18N_MANAGE (หน้าการจองของฉัน)
 * ไม่ได้แก้ = ใช้ข้อความเดิมในโค้ด (หน้าตาเหมือนเดิมทุกตัวอักษร)
 */
require_once __DIR__ . '/db.php';

const TEXT_SCOPES = ['mail', 'noshow', 'I18N_BOOKING', 'I18N_MANAGE'];

function text_overrides(): array
{
    static $t = null;
    if ($t === null || !empty($GLOBALS['__texts_dirty'])) {
        unset($GLOBALS['__texts_dirty']);
        $t = json_decode((string) setting('texts', ''), true) ?: [];
    }
    return $t;
}

/** ข้อความที่แก้ไว้ หรือ null */
function text_override(string $scope, string $lang, string $key): ?string
{
    $v = text_overrides()[$scope][$lang][$key] ?? null;
    return is_string($v) && $v !== '' ? $v : null;
}

/** HTML ที่อนุญาตในข้อความ: <b> <strong> <i> <em> <br> <a href="https://|mailto:|tel:"> — นอกนั้นตัดทิ้ง */
function text_clean(string $s): string
{
    $s = strip_tags(str_replace("\r", '', $s), '<b><strong><i><em><br><a>');
    $s = preg_replace_callback('/<a\b[^>]*>/i', function ($m) {
        return preg_match('/href\s*=\s*"((?:https?:\/\/|mailto:|tel:)[^"<>\s]*)"/i', $m[0], $h) ? '<a href="' . $h[1] . '">' : '<a>';
    }, $s);
    return preg_replace('/<(b|strong|i|em|br)\b[^>]*>/i', '<$1>', $s);
}

/** ตัวแทนค่า (%s %d %1$s …) ต้องเหมือนข้อความเดิม — ไม่งั้นอีเมลพัง */
function text_placeholders(string $s): array
{
    preg_match_all('/%(?:\d+\$)?[sd]/', $s, $m);
    sort($m[0]);
    return $m[0];
}

/** สำหรับหน้าเว็บ: window.TEXT_OVERRIDES (เฉพาะ scope ของหน้า) */
function text_overrides_js(string $scope): string
{
    return '<script>window.TEXT_OVERRIDES = ' . json_encode([$scope => text_overrides()[$scope] ?? new stdClass()], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>';
}
