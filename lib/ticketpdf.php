<?php
declare(strict_types=1);
/**
 * ตั๋ว PDF ขนาด A6 (105×148 มม.) ผู้เข้าชมคนละหน้า — แนบในอีเมล + ดาวน์โหลดจากหน้าการจองของฉัน
 * mPDF (lib/vendor/mpdf-pack, ตัดฟอนต์ที่มากับ mPDF ออก) + ฟอนต์ใน lib/fonts ดู pdf_font() (Noto Thai รุ่นใหม่ mPDF อ่านไม่ได้)
 */
require_once __DIR__ . '/emails.php';
require_once __DIR__ . '/venue.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/vendor/mpdf-pack/autoload.php';

/** ข้อความสำหรับ PDF: จุดหลังอักษรไทยท้ายข้อความ mPDF แสดงกล่องเกิน → แยกจุดเป็นช่วงภาษาอังกฤษ */
function pdf_txt(string $s): string
{
    return (string) preg_replace('/(?<=[\x{0E00}-\x{0E7F}])\./u', '<span lang="en">.</span>', mail_esc($s));
}

/** $tickets = [['n'=>1,'name'=>..,'secret'=>..], ...] → PDF (string) */
function ticket_pdf(string $lang, string $code, array $slot, array $tickets): string
{
    $th = $lang === 'th';
    $tmp = rtrim((string) (cfg()['cache_dir'] ?? sys_get_temp_dir()), '/') . '/mpdf';
    if (!is_dir($tmp)) {
        @mkdir($tmp, 0775, true);
    }
    $pf = pdf_font();
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => [105, 148], 'orientation' => 'P',
        'margin_left' => 9, 'margin_right' => 9, 'margin_top' => 7, 'margin_bottom' => 5,
        'tempDir' => $tmp,
        'fontDir' => [__DIR__ . '/fonts'],
        'fontdata' => [$pf[0] => ['R' => $pf[1], 'B' => $pf[2], 'useOTL' => 0xFF]],
        'default_font' => $pf[0],
        'useDictionaryLBR' => false,   // ตัดคำไทยด้วยพจนานุกรมของ mPDF ใส่ตัวคั่นที่ฟอนต์ไม่มี (ขึ้นเป็นกล่อง) — ข้อความบนตั๋วสั้น ไม่ต้องตัด
    ]);
    $mpdf->SetTitle(($th ? 'ตั๋วเข้าชม ' : 'Tickets ') . $code);
    $mpdf->SetAuthor(venue_name('en'));
    [$date, $time] = mail_fmt_slot($slot, $lang);
    $lb = venue_logo_bytes();
    $logo = 'data:' . (str_starts_with($lb, "\xFF\xD8") ? 'image/jpeg' : 'image/png') . ';base64,' . base64_encode($lb);
    $css = '<style>
        body{color:#141210;font-size:10pt}
        .c{text-align:center}
        .brand{font-weight:bold;font-size:12.5pt;margin:2mm 0 0}
        .rule{border-top:0.3mm solid #e3ddd3;margin:3mm 0}
        .lbl{font-size:8pt;color:#6e675e;letter-spacing:0.3pt}
        .name{font-size:15pt;font-weight:bold;margin:0.5mm 0 2.5mm}
        .when{background:#efe9df;border-radius:2mm;padding:2mm 3mm;font-size:10.5pt}
        .code{font-family:monospace;font-size:10pt;color:#6e675e;margin-top:1mm}
        .foot{font-size:6.8pt;color:#6e675e;line-height:1.4}
    </style>';
    $first = true;
    foreach ($tickets as $t) {
        if (!$first) {
            $mpdf->AddPage();
        }
        $first = false;
        $qr = 'data:image/png;base64,' . base64_encode(qr_png((string) $t['secret'], 10));
        $html = $css . '<div class="c">'
            . '<img src="' . $logo . '" style="width:17mm">'
            . '<div class="brand">' . pdf_txt(venue_name($th ? 'th' : 'en')) . '</div>'
            . '<div class="rule"></div>'
            . '<div class="lbl">' . pdf_txt(sprintf(mail_t($lang, 'visitor'), (int) $t['n'])) . '</div>'
            . '<div class="name">' . pdf_txt((string) $t['name']) . '</div>'
            . '<div class="when"><b>' . pdf_txt($date) . '</b><br>' . pdf_txt($time) . '</div>'
            . '<img src="' . $qr . '" style="width:42mm;height:42mm;margin-top:2mm">'
            . '<div class="code">' . pdf_txt($code . '-' . (int) $t['n']) . '</div>'
            . '<div class="rule"></div>'
            . '<div class="foot">' . pdf_txt(mail_t($lang, 'show_qr')) . '<br>' . pdf_txt(venue_address($th ? 'th' : 'en')) . '</div>'
            . '</div>';
        $mpdf->WriteHTML($html);
    }
    return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}

/**
 * ฟอนต์ตั๋ว PDF (ไทย+อังกฤษในฟอนต์เดียว): ใช้ฟอนต์ที่วางเพิ่มใน lib/fonts ก่อน (ชื่อไฟล์ <ชื่อ>-Regular.ttf + <ชื่อ>-Bold.ttf)
 * ถ้าไม่มี = Sarabun (SIL OFL, lib/fonts/Sarabun-OFL.txt) → [ชื่อในระบบ, ไฟล์ปกติ, ไฟล์ตัวหนา]
 */
function pdf_font(): array
{
    foreach (glob(__DIR__ . '/fonts/*-Regular.ttf') ?: [] as $r) {
        $base = basename($r, '-Regular.ttf');
        if ($base !== 'Sarabun' && empty($GLOBALS['__pdf_font_sarabun']) && is_file(__DIR__ . "/fonts/$base-Bold.ttf")) {
            return [strtolower(preg_replace('/[^A-Za-z0-9]/', '', $base)), "$base-Regular.ttf", "$base-Bold.ttf"];
        }
    }
    return ['sarabun', 'Sarabun-Regular.ttf', 'Sarabun-Bold.ttf'];
}
