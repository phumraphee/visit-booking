<?php
/**
 * Manual configuration (optional) — the setup wizard (install.php) creates this file for you.
 * If you configure by hand: copy to ../visit-booking-data/config.php (outside the web root, preferred)
 * or to config.php in this folder. Keep it private: it contains passwords.
 *
 * ตั้งค่าเอง (ไม่บังคับ) — ปกติใช้ตัวช่วยติดตั้ง install.php ซึ่งสร้างไฟล์นี้ให้
 */
return [
    'public_url'     => 'https://booking.example.org',   // address of this site, no trailing slash
    'timezone'       => 'Asia/Bangkok',
    'db_path'        => __DIR__ . '/visit.sqlite',       // SQLite database (folder must be writable)
    'cache_dir'      => __DIR__ . '/cache',              // writable
    'max_per_order'  => 10,
    'months_ahead'   => 6,                               // how far ahead sessions are created/shown
    'rate_limit'     => ['max' => 10, 'window' => 600],  // per IP, per 10 minutes (some buckets have higher limits)
    'cron_key'       => '',   // random string → call https://…/tools/reminders.php?key=… hourly (optional; the site also runs it on visits)

    // ---- Email (SMTP) — required for confirmations and admin sign-in codes ----
    'smtp_host'      => '',   // e.g. email-smtp.ap-southeast-1.amazonaws.com
    'smtp_port'      => 587,
    'smtp_user'      => '',
    'smtp_pass'      => '',
    'mail_from'      => 'bookings@example.org',
    'mail_from_name' => 'Example Venue',
    'mail_reply_to'  => 'info@example.org',
    'mail_preview'   => false,   // true (local only): write emails to cache/mail-last.html instead of sending

    // ---- Apple Wallet (optional) — Pass Type ID certificate from an Apple Developer account ----
    // The certificate can later be renewed from Admin → Settings.
    'wallet_pass_type_id' => '',   // e.g. pass.org.example.visit
    'wallet_team_id'      => '',
    'wallet_cert'         => __DIR__ . '/wallet/pass.pem',
    'wallet_key'          => __DIR__ . '/wallet/pass.key',
    'wallet_key_pass'     => '',
    'wallet_wwdr'         => __DIR__ . '/wallet/AppleWWDRCAG4.pem',

    // ---- Google Wallet (optional) — issuer ID + service-account JSON from the Google Pay & Wallet Console ----
    'gwallet_issuer_id' => '',
    'gwallet_sa'        => __DIR__ . '/wallet/google-sa.json',

    // ---- Passkeys (optional) — defaults to the site's domain ----
    'webauthn_rp_id' => '',

    // Cloudflare Turnstile and Ghost newsletter can be set in Admin → Settings → External services.
];
