# visit-booking

Free timed-entry booking system for museums, galleries and venues. Self-hosted PHP + SQLite, no framework, runs on cheap shared hosting. Thai/English, QR tickets, check-in scanner, Apple & Google Wallet, PDF tickets, email reminders, waitlist.

> **ภาษาไทย:** ระบบจองรอบเข้าชมฟรีสำหรับพิพิธภัณฑ์ หอศิลป์ และสถานที่ต่าง ๆ ติดตั้งบนโฮสต์ของตัวเองได้ (PHP + SQLite ไม่ต้องมีฐานข้อมูลแยก) รองรับไทย/อังกฤษ มีตั๋ว QR หน้าสแกนเช็คอิน Apple/Google Wallet ตั๋ว PDF อีเมลเตือน และรายชื่อรอ — ดูวิธีติดตั้งด้านล่าง

The screenshots below use made-up demo data ("Riverside Gallery").

---

## For visitors

### 1. Pick a date

<img src="docs/screenshots/booking-1-date.png" width="320" alt="Calendar of visit days">

A calendar shows the days that have sessions. No account is needed.

### 2. Pick a session

<img src="docs/screenshots/booking-2-session.png" width="320" alt="Choosing a session and number of visitors">

Choose a time and the number of visitors. Sessions that are full or closed are shown as such. When a session is full, visitors can join the waitlist.

### 3. Your details

<img src="docs/screenshots/booking-3-details.png" width="320" alt="Booking form with email verification">

The person booking (contact) and each visitor are entered separately — every ticket is issued by name. The email address is verified with a 6-digit code before the booking can be sent.

### 4. Done

<img src="docs/screenshots/booking-4-done.png" width="320" alt="Booking confirmed">

The booking is confirmed straight away and a confirmation email is sent.

### Thai and English

<img src="docs/screenshots/booking-th.png" width="320" alt="Booking page in Thai">

The page follows the device language and can be switched at any time.

### Confirmation email

<img src="docs/screenshots/email-confirmation.png" width="420" alt="Confirmation email with QR codes">

One QR code per visitor, a PDF ticket attached, Apple Wallet / Google Wallet buttons and "add to calendar" links. A reminder email is sent before the visit with "I'll come" / "Cancel" buttons.

### PDF ticket and Apple Wallet

| PDF ticket | Apple Wallet |
|---|---|
| <img src="docs/screenshots/ticket-pdf.png" width="300" alt="PDF ticket"> | <img src="docs/screenshots/wallet-apple.png" width="260" alt="Apple Wallet pass on iPhone"> |

Google Wallet passes work the same way (needs a Google Pay & Wallet Console issuer, see *Optional integrations*).

### My booking

<img src="docs/screenshots/my-booking.png" width="320" alt="My booking page with QR tickets">

A private link in the email opens the booking: show the QR tickets, edit visitor details, cancel one visitor or the whole booking, confirm attendance. Each visitor can also get their own link that shows only their ticket.

---

## For staff

### Sessions

<img src="docs/screenshots/admin-sessions.png" width="640" alt="Admin: sessions calendar">

The next visit day with bookings per session, and a calendar of all sessions. Tap a day to see every session of that day with the visitor list.

### One session

<img src="docs/screenshots/admin-session.png" width="640" alt="Admin: one session with visitor list">

Visitor list with check-in status, export to CSV, email everyone in the session, cancel the session.

### One booking

<img src="docs/screenshots/admin-order.png" width="640" alt="Admin: booking details">

Contact and visitors, edit details, resend the email, internal note, and a history of everything that happened to the booking (emails included).

### Add a booking

<img src="docs/screenshots/admin-add-booking.png" width="640" alt="Admin: add a booking for a phone or walk-in visitor">

For phone or walk-in visitors. Walk-ins can be checked in immediately.

### Check-in scanner

| Ready | Checked in | Visitor list |
|---|---|---|
| <img src="docs/screenshots/scanner-ready.png" width="240" alt="Scanner ready"> | <img src="docs/screenshots/scanner-ok.png" width="240" alt="Scanner: checked in"> | <img src="docs/screenshots/scanner-list.png" width="240" alt="Scanner: visitor list"> |

Scan QR codes with the phone camera or a barcode gun. Works offline and syncs later. Scanning a ticket twice shows "already used". A wrong scan can be undone.

### Statistics

<img src="docs/screenshots/admin-stats.png" width="640" alt="Admin: statistics">

Attendance and no-shows by month, how far ahead people book, group sizes, and where bookings come from (LINE, Facebook, Instagram, QR…).

### Settings and texts

| Settings | Texts |
|---|---|
| <img src="docs/screenshots/admin-settings.png" width="420" alt="Admin: settings"> | <img src="docs/screenshots/admin-texts.png" width="420" alt="Admin: edit texts"> |

Venue details and logo, session pattern (e.g. 2nd & 4th Sunday, 10:00–12:00 and 14:00–16:00), capacity, booking rules — and every text on the booking pages and in the emails, in both languages.

### On the phone

<img src="docs/screenshots/admin-mobile.png" width="320" alt="Admin on a phone">

The admin can be added to the home screen and used as an app. New bookings can arrive as push notifications (set per staff member).

---

## Features

**Visitors**
- Calendar of sessions → pick a time → fill in each visitor → done (no account needed)
- Email verification with a 6-digit code before booking (stops typos and fake bookings)
- Confirmation email with a QR code per visitor, a PDF ticket, Apple Wallet / Google Wallet passes, "add to calendar" links
- "My booking" page: view, edit visitors, cancel one visitor or the whole booking, confirm attendance
- Reminder email before the visit with "I'll come" / "Cancel" buttons
- Waitlist when a session is full
- Thai and English (follows the device language), works well on phones, screen-reader friendly calendar

**Staff**
- Admin web app (installable on the home screen) with sign-in by email code or passkey (Face ID / fingerprint)
- Roles: staff (scan + view), administrator, super administrator; per-staff permission to cancel bookings
- Check-in scanner (phone camera or barcode gun), works offline and syncs later
- Add phone / walk-in bookings, cancel a whole session or day with an email to every booker, no-show emails
- Statistics: attendance and no-shows by month, how far ahead people book, group sizes, where bookings come from (LINE, Facebook, Instagram, QR…)
- Everything is editable in the admin: venue details and logo, session pattern (e.g. 2nd & 4th Sunday, 10:00–12:00 and 14:00–16:00), capacity, booking rules, every text on the booking pages and in the emails
- Audit log with undo, daily database backups, web push notifications for new bookings
- Test mode (bookings are marked as tests and emails go to an internal outbox)

## Install (shared hosting)

Requirements: PHP 8.1+ with PDO SQLite, OpenSSL, cURL, mbstring and GD (most hosts have these). Apache is recommended (the included `.htaccess` files protect private folders).

1. Download this repository and upload the files to your website folder (e.g. with FTP or the hosting file manager).
2. Open your website in a browser — the **setup wizard** starts automatically.
3. Enter your venue name, the first administrator's email and (optionally) your SMTP email settings, then press **Install**.
4. You are signed in to the admin. Set your session pattern, texts and logo in **Settings**, then turn off **test mode**.

The wizard stores the configuration and database in a folder **next to** your web folder (`../visit-booking-data/`) when the host allows it, so they cannot be downloaded. After installation the wizard disables itself.

**Email (SMTP)** is needed for booking confirmations and for admin sign-in codes. Any SMTP service works (Amazon SES, your host's mail server, Gmail with an app password…). You can add it later in `visit-booking-data/config.php` — see `config.example.php`.

**Hourly task (recommended):** in your hosting panel add a scheduled task that fetches
`https://your-site/tools/reminders.php?key=YOUR_CRON_KEY` every hour (the key is in the config file and shown in **Settings → Hourly automatic job**). Without it, reminders still run whenever someone opens the booking page.

## Try it locally (Docker)

```bash
docker compose up
```

Then open http://localhost:8080 and follow the setup wizard. Data is kept in a Docker volume.

## Optional integrations

| Feature | What you need | Where to set it |
|---|---|---|
| Apple Wallet passes | Apple Developer account, Pass Type ID certificate | `config.php` (`wallet_*`); certificate renewal from Settings |
| Google Wallet passes | Google Pay & Wallet Console issuer + service account JSON | `config.php` (`gwallet_*`) |
| Bot protection | Cloudflare Turnstile site + secret key | Settings → External services |
| Newsletter sign-ups | Ghost Admin API key | Settings → External services |
| Single sign-on for staff | OpenID Connect provider (e.g. Pocket ID), callback `/admin/oidc.php` | `config.php` (`oidc_*`) |

## Single sign-on for staff (optional)

Staff can sign in to the admin and the scanner with your organisation's OpenID Connect provider (e.g. Pocket ID, Keycloak, Authentik) instead of email codes.

1. At your provider, create a confidential client (not public) with PKCE enabled.
   Callback / redirect URL: `https://your-site/admin/oidc.php` — no logout callback is needed.
2. In `visit-booking-data/config.php` set `oidc_issuer` (e.g. `https://sso.example.org`), `oidc_client_id` and `oidc_client_secret` — or keep the ID and secret out of the config in a JSON file (`{"client_id": "…", "client_secret": "…"}`) and point `oidc_secret_file` to it. Optionally set the button text with `oidc_label`.
3. Add each staff member in **Settings → Users** with the same email address they have at the provider. Users are matched by email; nobody is created automatically. Make sure users cannot change their own email at the provider.
4. Optional: `'passkey_enabled' => false` hides passkey sign-in. Email sign-in codes keep working as a fallback.

Signing out ends the session here and, if the provider session is still active, sends the user to the provider's sign-out page (`<issuer>/logout`, change with `oidc_logout_url`).

## Fonts

PDF tickets use **Sarabun** (SIL Open Font License, included in `lib/fonts`). To use another Thai/Latin font, put `YourFont-Regular.ttf` and `YourFont-Bold.ttf` in `lib/fonts` — it is picked up automatically.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE). Bundled libraries: [mPDF](https://github.com/mpdf/mpdf) (GPL-2.0), [chillerlan/php-qrcode](https://github.com/chillerlan/php-qrcode) (MIT), Sarabun font (OFL-1.1).

Security issues: please report privately via GitHub's **Report a vulnerability** (Security tab) instead of opening a public issue.
