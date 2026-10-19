// ดึงลงเพื่อรีเฟรช — เฉพาะตอนเปิดเป็นแอปจากหน้าจอโฮม (iOS standalone ไม่มีแถบเบราว์เซอร์/ไม่มี pull-to-refresh)
(() => {
  'use strict';
  const standalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
  if (!standalone) return;
  const TH = (document.documentElement.lang || 'th') !== 'en';
  const el = document.createElement('div');
  el.className = 'ptr';
  el.innerHTML = '<span class="ptr-i">↓</span><span class="ptr-t"></span>';
  document.body.append(el);
  const css = document.createElement('style');
  css.textContent = `.ptr{position:fixed;left:50%;top:calc(env(safe-area-inset-top) + 6px);transform:translate(-50%,-80px);z-index:200;display:flex;align-items:center;gap:8px;
    padding:8px 14px;border-radius:999px;background:#141210;color:#fff;font:600 13px/1 system-ui,sans-serif;box-shadow:0 6px 18px rgba(0,0,0,.25);transition:transform .2s,opacity .2s;opacity:0;pointer-events:none}
    .ptr.on{opacity:1}.ptr .ptr-i{display:inline-block;transition:transform .15s}.ptr.ready .ptr-i{transform:rotate(180deg)}
    .ptr.spin .ptr-i{animation:ptrs .8s linear infinite}@keyframes ptrs{to{transform:rotate(360deg)}}`;
  document.head.append(css);
  const LIMIT = 80;
  let y0 = null, dy = 0;
  const label = () => { el.querySelector('.ptr-t').textContent = dy > LIMIT ? (TH ? 'ปล่อยเพื่อรีเฟรช' : 'Release to refresh') : (TH ? 'ดึงลงเพื่อรีเฟรช' : 'Pull to refresh'); };
  document.addEventListener('touchstart', e => {
    // เริ่มเฉพาะเมื่ออยู่บนสุดของหน้า และไม่ได้แตะในหน้าต่าง/รายการที่เลื่อนได้เอง
    if (window.scrollY > 0 || e.touches.length !== 1 || e.target.closest('.ask-bg,.mm-pop,.nav-more,.sheet.full,video,input,textarea,select')) { y0 = null; return; }
    y0 = e.touches[0].clientY; dy = 0;
  }, { passive: true });
  document.addEventListener('touchmove', e => {
    if (y0 === null) return;
    dy = e.touches[0].clientY - y0;
    if (dy <= 8) { el.classList.remove('on'); return; }
    el.classList.add('on'); el.classList.toggle('ready', dy > LIMIT); label();
    el.style.transform = `translate(-50%, ${Math.min(dy, LIMIT + 30) - 40}px)`;
  }, { passive: true });
  document.addEventListener('touchend', () => {
    if (y0 === null) return;
    y0 = null;
    if (dy > LIMIT) {
      el.classList.add('spin'); el.querySelector('.ptr-t').textContent = TH ? 'กำลังรีเฟรช…' : 'Refreshing…';
      // หน้าที่กำหนด window.ptrRefresh (หน้าสแกน) = ดึงข้อมูลใหม่โดยไม่โหลดหน้า → ไม่ต้องขอสิทธิ์กล้องซ้ำ
      if (typeof window.ptrRefresh === 'function') {
        Promise.resolve(window.ptrRefresh()).catch(() => {}).finally(() => { el.classList.remove('on', 'ready', 'spin'); el.style.transform = ''; });
      } else location.reload();
    } else { el.classList.remove('on', 'ready'); el.style.transform = ''; }
  });
})();
