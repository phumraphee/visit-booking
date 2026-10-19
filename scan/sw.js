// Service worker หน้าสแกน: เก็บหน้าแอปไว้ใช้ออฟไลน์ (API ไม่ cache — ข้อมูลเก็บใน localStorage)
const CACHE = 'scan-v30';
const SHELL = ['./', 'manifest.webmanifest', '../assets/scan.css?v=1.2.18', '../assets/scan.js?v=1.2.18', '../assets/passkey.js?v=1.2.18', '../assets/ptr.js?v=1.2.18', '../assets/vendor/jsQR.js?v=1.4.0', '../assets/brand/app-180.png', '../assets/brand/mark-32.png'];
self.addEventListener('install', e => { e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.pathname.includes('/api/')) return;
  // หน้าแอป: เน็ตก่อน (ได้เวอร์ชันใหม่) แล้วค่อย cache
  e.respondWith(fetch(e.request).then(r => { const copy = r.clone(); caches.open(CACHE).then(c => c.put(e.request, copy)); return r; }).catch(() => caches.match(e.request)));
});
