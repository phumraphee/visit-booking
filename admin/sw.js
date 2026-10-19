// Service worker ของหลังบ้าน — ใช้สำหรับแจ้งเตือน (Web Push) เท่านั้น ไม่แคชหน้า
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (_) { d = { body: e.data && e.data.text() }; }
  e.waitUntil(self.registration.showNotification(d.title || 'ระบบจอง', {
    body: d.body || '', tag: d.tag || 'vb', renotify: true,
    icon: '../assets/brand/app-180.png', badge: '../assets/brand/mark-32.png',
    data: { url: d.url || './' },
  }));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = new URL((e.notification.data && e.notification.data.url) || './', self.location.href).href;
  e.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of all) {
      if (c.url.startsWith(self.registration.scope)) { await c.focus(); if ('navigate' in c) await c.navigate(url); return; }
    }
    await self.clients.openWindow(url);
  })());
});

// ปลายทางเปลี่ยน: สมัครใหม่ด้วยกุญแจเดิม แล้วให้หน้าแอปส่งขึ้นเซิร์ฟเวอร์ (SW ไม่มี cookie ล็อกอินที่แน่นอน)
self.addEventListener('pushsubscriptionchange', e => {
  e.waitUntil((async () => {
    try { const key = e.oldSubscription && e.oldSubscription.options && e.oldSubscription.options.applicationServerKey; if (key) await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }); } catch (_) {}
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of all) c.postMessage({ type: 'push-resub' });
  })());
});
