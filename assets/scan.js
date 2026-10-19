(() => {
  'use strict';
  // สแกนเช็คอิน: กล้อง (BarcodeDetector ถ้ามี ไม่งั้น jsQR) / ปืนสแกน (คีย์บอร์ด) / ค้นชื่อแล้วกดเช็คอิน
  // ออฟไลน์: รายชื่อตั๋วของรอบเก็บใน localStorage (เก็บแค่ sha256 ของรหัส QR) + คิวเช็คอินส่งเมื่อเน็ตกลับ
  const $ = id => document.getElementById(id);
  const API = '../api/admin.php';
  const EN = window.ADMIN_LANG === 'en';
  const MONTHS = EN ? ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] : ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
  const DOWS = EN ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] : ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
  const fmtDate = iso => { const [y, m, d] = iso.split('-').map(Number); return `${DOWS[new Date(y, m - 1, d).getDay()]} ${d} ${MONTHS[m - 1]}`; };
  const hhmm = ts => { const d = new Date(ts); return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`; };
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const store = {
    get: (k, d) => { try { return JSON.parse(localStorage.getItem(k)) ?? d; } catch (_) { return d; } },
    set: (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (_) {} },
  };

  let session = store.get('scan-session', null);      // {id, date, start, end}
  let tickets = [];                                    // [{id, code, n, name, s (sha256), in, x}]
  let queue = store.get('scan-queue', []);             // [{secret, session, at}]
  let stream = null, detector = null, scanning = false, lastText = '', lastAt = 0, pendingForce = null;

  async function api(action, data = {}) {
    const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action, ...data }) });
    const j = await r.json().catch(() => ({ ok: false }));
    if (r.status === 401) { if ($('login').hidden) showLogin(); throw new Error('auth'); }   // อย่ารีเซ็ตฟอร์มที่กำลังกรอก
    if (!j.ok) throw new Error(j.message || 'error');
    return j;
  }
  const sha256 = async t => [...new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(t)))].map(b => b.toString(16).padStart(2, '0')).join('');
  const show = id => {
    ['login', 'sessions', 'scanner', 'list'].forEach(x => { $(x).hidden = x !== id && !(id === 'list' && x === 'scanner'); });
    // ยังไม่ล็อกอิน: ซ่อนปุ่มเลือกรอบ/ตัวนับ (ไม่มีความหมายตอนนี้)
    $('barSess').hidden = id === 'login';
  };

  function setNet() {
    const on = navigator.onLine;
    $('net').className = 'net ' + (on ? 'on' : 'off');
    $('net').textContent = on ? (queue.length ? `รอส่ง ${queue.length}` : 'ออนไลน์') : `ออฟไลน์${queue.length ? ' · รอส่ง ' + queue.length : ''}`;
  }
  function setCount() {
    const valid = tickets.filter(t => !t.x);
    $('count').textContent = session ? `${valid.filter(t => t.in).length} / ${valid.length}` : '–';
    // แถวรอบ: วันที่เต็ม + เวลาตัวใหญ่ (กดเพื่อเปลี่ยนรอบ)
    if (session) {
      const [y, m, d] = session.date.split('-').map(Number);
      $('sessDate').textContent = `${DOWS[new Date(y, m - 1, d).getDay()]} ${d} ${MONTHS[m - 1]} ${EN ? y : y + 543}`;
      $('sessTime').textContent = session.start + (session.end ? '–' + session.end : '');
    } else { $('sessDate').textContent = 'เลือกรอบ'; $('sessTime').textContent = ''; }
  }

  // ---------- เสียง / สั่น ----------
  let ac = null;
  function beep(ok) {
    try {
      ac = ac || new (window.AudioContext || window.webkitAudioContext)();
      const o = ac.createOscillator(), g = ac.createGain();
      o.frequency.value = ok ? 880 : 220; o.type = ok ? 'sine' : 'square';
      g.gain.setValueAtTime(0.25, ac.currentTime); g.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + (ok ? 0.18 : 0.45));
      o.connect(g).connect(ac.destination); o.start(); o.stop(ac.currentTime + (ok ? 0.2 : 0.5));
    } catch (_) {}
    if (navigator.vibrate) navigator.vibrate(ok ? 60 : [120, 80, 120]);
  }

  const REASON = r => !r ? '' : r === 'unconfirmed' ? 'ไม่ได้ยืนยันอีเมลภายในเวลา' : r === 'customer' ? 'ผู้จองยกเลิกเอง' : r === 'attendee' ? 'ผู้เข้าชมยกเลิกตั๋วของตัวเอง' : r.startsWith('admin:') ? 'แอดมินยกเลิก' : r;

  // ---------- ผลการสแกน ----------
  let hideTimer;
  function result(kind, t, sub = '') {
    const map = {
      ok: ['✓', 'เช็คอินแล้ว', 'ok'], already: ['!', 'ใช้ไปแล้ว', 'warn'], wrong_session: ['✕', 'ไม่ใช่รอบนี้', 'bad'],
      canceled: ['✕', 'ตั๋วถูกยกเลิก', 'bad'], locked: ['✕', 'รอบนี้ผ่านไปแล้ว', 'bad'], unknown: ['?', 'ไม่พบตั๋ว', 'bad'], queued: ['✓', 'เช็คอินแล้ว (ออฟไลน์)', 'ok'], unconfirmed: ['!', 'ยังไม่ได้ยืนยันการจองทางอีเมล', 'warn'],
    };
    const [icon, title, cls] = map[kind] || map.unknown;
    $('result').className = 'result ' + cls;
    $('ricon').textContent = icon; $('rtitle').textContent = title;
    $('rname').textContent = t ? (t.name || `${t.given || ''} ${t.family || ''}`.trim()) : '';
    $('rsub').textContent = sub;
    $('force').hidden = kind !== 'wrong_session' && kind !== 'unconfirmed';
    undoId = kind === 'ok' && t && t.id ? t.id : null;
    $('restore').hidden = !restoreSecret;
    $('undo').hidden = !undoId; $('undo').textContent = 'สแกนผิด — ยกเลิกเช็คอินนี้';
    $('result').hidden = false;
    beep(cls === 'ok');
    clearTimeout(hideTimer);
    hideTimer = setTimeout(() => { $('result').hidden = true; pendingForce = null; }, (kind === 'wrong_session' || kind === 'unconfirmed' || restoreSecret) ? 8000 : kind === 'ok' ? 4000 : 1800);
  }
  // ข้อความแจ้ง (ใช้กล่องผลสแกน ไม่มีเสียง)
  function flash(cls, title, name, sub) {
    $('result').className = 'result ' + cls;
    $('ricon').textContent = cls === 'ok' ? '✓' : '!'; $('rtitle').textContent = title; $('rname').textContent = name; $('rsub').textContent = sub;
    $('force').hidden = true; $('undo').hidden = true; $('restore').hidden = true; $('result').hidden = false;
    clearTimeout(hideTimer); hideTimer = setTimeout(() => { $('result').hidden = true; }, 2500);
  }
  $('result').onclick = e => { if (!['force', 'undo', 'restore'].includes(e.target.id)) { $('result').hidden = true; pendingForce = null; restoreSecret = null; } };
  // ตั๋วของการจองที่ถูกยกเลิก (เช่น ไม่ได้ยืนยัน) แต่คนมาถึงหน้างาน → คืนการจองแล้วเช็คอินในปุ่มเดียว
  let restoreSecret = null, restoreCode = null;
  $('restore').onclick = async () => {
    if (!restoreSecret) return;
    const sec = restoreSecret; restoreSecret = null; clearTimeout(hideTimer);
    try {
      await api('order_restore', { code: restoreCode });
      handleServer(await api('checkin', { secret: sec, session: session.id }), sec);
      sync();
    } catch (err) { if (err.message !== 'auth') flash('bad', 'คืนการจองไม่สำเร็จ', err.message, ''); }
  };
  // สแกนผิดคน: ยกเลิกเช็คอินที่เพิ่งทำ (ต้องออนไลน์) — สแกนซ้ำภายหลังได้ตามปกติ
  let undoId = null;
  async function undoCheckin(id) {
    await api('checkin_ticket', { id, undo: true });
    const loc = tickets.find(x => x.id === id); if (loc) { loc.in = null; saveTickets(); }
    lastText = ''; setCount();
    if (!$('list').hidden) renderList();   // รายชื่อที่เปิดอยู่ต้องเปลี่ยนตามทันที
    sync();
  }
  $('undo').onclick = async () => {
    if (!undoId) return;
    clearTimeout(hideTimer);
    try { await undoCheckin(undoId); undoId = null; flash('warn', 'ยกเลิกเช็คอินแล้ว', $('rname').textContent, ''); }
    catch (err) { if (err.message !== 'auth') flash('bad', 'ต้องออนไลน์เพื่อยกเลิก', '', ''); }
  };
  $('force').onclick = async () => {
    if (!pendingForce) return;
    try { const r = await api('checkin', { secret: pendingForce, session: session.id, force: true }); handleServer(r, pendingForce); } catch (_) {}
  };

  function handleServer(r, secret) {
    const t = r.ticket;
    if (t) {
      const loc = tickets.find(x => x.id === t.id);
      if (loc && (r.result === 'ok' || r.result === 'already')) { loc.in = t.checked_in_at || loc.in || new Date().toISOString(); saveTickets(); }
    }
    const sub = r.result === 'already' && t ? `เมื่อ ${hhmm(t.checked_in_at)}${t.checked_in_by ? ' · ' + t.checked_in_by : ''}`
      : r.result === 'wrong_session' && t ? `ตั๋วรอบ ${fmtDate(t.slot.date)} ${t.slot.start}`
      : t ? `${t.code}-${t.n}` : '';
    if (r.result === 'wrong_session' || r.result === 'unconfirmed') pendingForce = secret;
    restoreSecret = r.result === 'canceled' && r.restorable && secret ? secret : null;
    restoreCode = t ? t.code : null;
    result(r.result, t, r.result === 'canceled' ? REASON(r.reason) : sub);
    setCount();
    if (!$('list').hidden) renderList();
  }

  async function onCode(text) {
    text = (text || '').trim();
    if (!text || !session) return;
    const now = Date.now();
    if (text === lastText && now - lastAt < 3000) return;   // กันสแกนซ้ำรัว ๆ
    lastText = text; lastAt = now;
    restoreSecret = null;
    const h = await sha256(text);
    const loc = tickets.find(t => t.s === h);
    if (navigator.onLine) {
      try { handleServer(await api('checkin', { secret: text, session: session.id }), text); return; }
      catch (e) { if (e.message === 'auth') return; /* เน็ตมีปัญหา → ใช้ข้อมูลในเครื่อง */ }
    }
    // ออฟไลน์
    if (!loc) { result('unknown', null, 'ไม่พบในรายชื่อรอบนี้ (ออฟไลน์)'); return; }
    if (loc.x) { result('canceled', loc); return; }
    if (loc.in) { result('already', loc, `เมื่อ ${hhmm(loc.in)}`); return; }
    loc.in = new Date().toISOString();
    queue.push({ secret: text, session: session.id, at: loc.in });
    store.set('scan-queue', queue); saveTickets(); setNet(); setCount();
    result('queued', loc, `${loc.code}-${loc.n}`);
  }

  // ---------- ซิงก์รายชื่อ + คิว ----------
  const SYNC_FAIL = { canceled: 'ถูกยกเลิกแล้ว', unknown: 'ไม่พบในระบบ', locked: 'รอบปิดแล้ว', wrong_session: 'ผิดรอบ', unconfirmed: 'ยังไม่ยืนยันอีเมล' };
  function saveTickets() { if (session) store.set('scan-tickets-' + session.id, tickets); }
  async function sync() {
    if (!session || !navigator.onLine || !$('login').hidden) { setNet(); return; }
    const sent = queue.slice(0, 500);   // ส่งคิวทุกรอบ (สแกนรอบเช้าออฟไลน์แล้วเปลี่ยนไปรอบบ่าย ก็ยังส่งขึ้น)
    try {
      const j = await api('scan_sync', { session: session.id, queue: sent });
      queue = queue.filter(q => !sent.includes(q));
      store.set('scan-queue', queue);
      tickets = j.tickets; saveTickets();
      // แจ้งรายการที่ส่งขึ้นแล้วไม่สำเร็จ (ยกเลิกแล้ว / ไม่พบ / ผิดรอบ ฯลฯ) — ไม่ทิ้งเงียบ ๆ
      const bad = (j.results || []).filter(r => !['ok', 'already'].includes(r.result));
      if (bad.length) alert(`เช็คอินออฟไลน์ ${bad.length} รายการไม่สำเร็จ:\n` + bad.map(r => (r.ticket ? `${r.ticket.code}-${r.ticket.n} ${r.ticket.given || ''} ${r.ticket.family || ''}` : 'ไม่พบตั๋ว') + ' · ' + (SYNC_FAIL[r.result] || r.result)).join('\n'));
    } catch (_) {}
    setNet(); setCount();
    if (!$('list').hidden) renderList();
  }
  setInterval(sync, 20000);
  window.ptrRefresh = () => sync();
  window.addEventListener('online', () => { setNet(); sync(); });
  window.addEventListener('offline', setNet);

  // ---------- กล้อง ----------
  async function startCam() {
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
    } catch (e) { $('camBtn').textContent = 'เปิดกล้องไม่ได้ — แตะเพื่อลองใหม่'; return; }
    const v = $('video'); v.srcObject = stream; await v.play();
    $('camBtn').hidden = true;
    const track = stream.getVideoTracks()[0];
    const caps = track.getCapabilities ? track.getCapabilities() : {};
    $('torchBtn').hidden = !caps.torch;
    if ('BarcodeDetector' in window) {
      try { if ((await BarcodeDetector.getSupportedFormats()).includes('qr_code')) detector = new BarcodeDetector({ formats: ['qr_code'] }); } catch (_) {}
    }
    scanning = true; loop();
  }
  const canvas = $('canvas'), ctx = canvas.getContext('2d', { willReadFrequently: true });
  async function loop() {
    if (!scanning) return;
    const v = $('video');
    if (v.readyState >= 2 && $('result').hidden) {
      try {
        if (detector) {
          const codes = await detector.detect(v);
          if (codes.length) onCode(codes[0].rawValue);
        } else if (window.jsQR) {
          const w = 640, hgt = Math.round(v.videoHeight * 640 / v.videoWidth) || 480;
          canvas.width = w; canvas.height = hgt;
          ctx.drawImage(v, 0, 0, w, hgt);
          const img = ctx.getImageData(0, 0, w, hgt);
          const code = jsQR(img.data, w, hgt, { inversionAttempts: 'dontInvert' });
          if (code && code.data) onCode(code.data);
        }
      } catch (_) {}
    }
    setTimeout(loop, detector ? 120 : 160);
  }
  $('camBtn').onclick = startCam;
  // โหมดปืนสแกน: ปิดกล้อง (ประหยัดแบต/ไม่ต้องเล็ง) — รับรหัสจากปืนสแกนแบบคีย์บอร์ดอย่างเดียว; จำค่าไว้ในเครื่อง
  function stopCam() {
    scanning = false;
    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    $('video').srcObject = null; $('torchBtn').hidden = true;
  }
  function setGun(on) {
    store.set('scan-gun', on ? 1 : 0);
    $('gunPanel').hidden = !on; $('video').hidden = on; document.querySelector('.cam').classList.toggle('gun', on);
    $('gunLbl').textContent = on ? 'เปิดกล้อง' : 'ปิดกล้อง';
    $('gunBtn').classList.toggle('on', on); $('gunBtn').title = on ? 'เปิดกล้อง' : 'ปิดกล้อง (ใช้ปืนสแกน)';
    if (on) { stopCam(); $('camBtn').hidden = true; }
    else if (!stream) { $('camBtn').hidden = false; startCam(); }
  }
  const gunOn = () => !!store.get('scan-gun', 0);
  $('gunBtn').onclick = () => setGun(!gunOn());
  let torch = false;
  $('torchBtn').onclick = async () => { try { torch = !torch; await stream.getVideoTracks()[0].applyConstraints({ advanced: [{ torch }] }); } catch (_) {} };
  document.addEventListener('visibilitychange', () => {
    if (document.hidden && stream) { scanning = false; stream.getTracks().forEach(t => t.stop()); stream = null; if (!gunOn()) { $('camBtn').hidden = false; $('camBtn').textContent = 'เปิดกล้อง'; } }
  });

  // ---------- ปืนสแกน (พิมพ์เร็ว + Enter) ----------
  let buf = '', bufAt = 0;
  document.addEventListener('keydown', e => {
    if (e.target.tagName === 'INPUT') return;
    const now = Date.now();
    if (now - bufAt > 80) buf = '';
    bufAt = now;
    if (e.key === 'Enter') { if (buf.length >= 6) onCode(buf); buf = ''; }
    else if (e.key.length === 1) buf += e.key;
  });

  // ---------- รายชื่อ / ค้นหา / เช็คอินด้วยมือ ----------
  function renderList() {
    const q = $('q').value.trim().toLowerCase();
    const rows = tickets.filter(t => !q || (t.name + ' ' + t.code).toLowerCase().includes(q))
      .sort((a, b) => (!!a.in - !!b.in) || a.name.localeCompare(b.name, 'th'));
    $('rows').innerHTML = rows.map(t => `<div class="lrow${t.x ? ' x' : ''}"><div><b>${esc(t.name)}</b><small>${t.code}-${t.n}${t.x && t.r ? ' · ' + REASON(t.r) : ''}${t.w ? ' · รอยืนยันอีเมล' : ''}</small></div>
      ${t.x ? (t.r && t.r !== 'attendee' ? `<button class="pill bad" data-restore="${esc(t.code)}">ยกเลิก · คืนการจอง</button>` : '<span class="pill bad">ยกเลิก</span>') : t.in ? `<button class="pill ok" data-undo="${t.id}">✓ ${hhmm(t.in)}</button>` : `<button class="btn sm" data-id="${t.id}">เช็คอิน</button>`}</div>`).join('') || '<p class="hint">ไม่พบ</p>';
  }
  $('listBtn').onclick = () => { $('list').hidden = false; renderList(); $('q').focus(); };
  $('closeList').onclick = () => { $('list').hidden = true; };
  $('q').oninput = renderList;
  // หน้าต่างยืนยัน (เช็คอินด้วยมือ) → Promise<boolean>
  function confirmSheet(o) {
    return new Promise(res => {
      $('cfmWarn').hidden = !o.warn; $('cfmWarn').textContent = o.warn || '';
      $('cfmTitle').textContent = o.title; $('cfmName').textContent = o.name; $('cfmSub').textContent = o.sub || '';
      $('cfmYes').textContent = o.ok; $('cfmYes').classList.toggle('danger', !!o.warn); $('cfmNo').textContent = o.no || 'ยกเลิก';
      $('cfm').hidden = false;
      const done = v => { $('cfm').hidden = true; $('cfmYes').onclick = $('cfmNo').onclick = null; res(v); };
      $('cfmYes').onclick = () => done(true); $('cfmNo').onclick = () => done(false);
    });
  }
  const todayStr = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
  $('rows').onclick = async e => {
    const rs = e.target.closest('[data-restore]');
    if (rs) {   // แตะ 2 ครั้ง: คืนการจองทั้งการจอง (ทุกคนในการจองนั้น)
      if (!rs.classList.contains('arm')) { rs.classList.add('arm'); rs.textContent = 'แตะอีกครั้งเพื่อคืนการจอง'; setTimeout(() => renderList(), 3000); return; }
      try { await api('order_restore', { code: rs.dataset.restore }); await sync(); flash('ok', 'คืนการจองแล้ว', rs.dataset.restore, 'กดเช็คอินได้เลย'); }
      catch (err) { if (err.message !== 'auth') flash('bad', 'คืนการจองไม่สำเร็จ', err.message, ''); }
      return;
    }
    const u = e.target.closest('[data-undo]');
    if (u) {   // แตะ 2 ครั้งเพื่อยกเลิกเช็คอิน (กันแตะพลาด)
      if (!u.classList.contains('arm')) { u.classList.add('arm'); u.textContent = 'แตะอีกครั้งเพื่อยกเลิก'; setTimeout(() => renderList(), 3000); return; }
      try { await undoCheckin(Number(u.dataset.undo)); renderList(); } catch (err) { if (err.message !== 'auth') flash('bad', 'ต้องออนไลน์เพื่อยกเลิก', '', ''); }
      return;
    }
    const b = e.target.closest('[data-id]'); if (!b) return;
    const t = tickets.find(x => x.id === Number(b.dataset.id));
    const early = session && session.date > todayStr();
    if (!await confirmSheet({ title: early ? 'เช็คอินล่วงหน้า?' : 'เช็คอิน?', name: t.name, sub: `${t.code}-${t.n}`, ok: early ? 'เช็คอินล่วงหน้า' : 'เช็คอิน',
      warn: early ? `รอบนี้ยังไม่ถึงวัน (${fmtDate(session.date)} ${session.start}) — ปกติเช็คอินในวันเข้าชมเท่านั้น` : '' })) return;
    try { const r = await api('checkin_ticket', { id: t.id }); handleServer(r, ''); renderList(); }
    catch (err) { if (err.message !== 'auth') alert('ต้องออนไลน์เพื่อเช็คอินด้วยมือ'); }
  };

  // ---------- เลือกรอบ ----------
  async function showSessions() {
    show('sessions');
    try {
      const j = await api('sessions', { scope: 'upcoming' });
      const today = new Date(); const ymd = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
      $('slist').innerHTML = j.sessions.slice(0, 12).map(s => `<button class="srow${s.date === ymd ? ' today' : ''}" data-s='${esc(JSON.stringify({ id: s.id, date: s.date, start: s.start, end: s.end }))}'>
        <b>${fmtDate(s.date)} · ${s.start}${s.end ? '–' + s.end : ''}</b><small>${s.booked} คน${s.date === ymd ? ' · วันนี้' : ''}</small></button>`).join('') || '<p class="hint">ไม่มีรอบที่จะถึง</p>';
    } catch (e) { if (e.message !== 'auth') $('slist').innerHTML = '<p class="hint">โหลดไม่ได้ (ออฟไลน์?)</p>'; }
  }
  $('slist').onclick = e => {
    const b = e.target.closest('[data-s]'); if (!b) return;
    pick(JSON.parse(b.dataset.s));
  };
  function pick(s) {
    session = s; store.set('scan-session', s);
    tickets = store.get('scan-tickets-' + s.id, []);
    show('scanner'); setCount(); sync();
    setGun(gunOn());   // จำโหมดไว้: ปืนสแกน = ไม่เปิดกล้อง
  }
  // แตะปุ่มรอบซ้ำ = ปิดรายการรอบ กลับหน้าสแกน (ถ้าเลือกรอบไว้แล้ว)
  $('sessBtn').onclick = () => { if (!$('sessions').hidden && session) { pick(session); return; } showSessions(); };

  // ---------- passkey (Face ID / ลายนิ้วมือ) ----------
  const PK = window.Passkey && window.Passkey.supported ? window.Passkey.create(api) : null;
  if (PK) {
    $('pkLogin').onclick = async () => {
      $('lmsg').textContent = '';
      try { await PK.login(); start(); }
      catch (x) { const t = x.message === 'auth' ? null : window.Passkey.errorText(x); if (t) $('lmsg').textContent = t; }
    };
  }
  // หลังล็อกอินด้วยอีเมล: ถามครั้งเดียวต่อเครื่องว่าจะใช้ Face ID ไหม (ตั้งทีหลังได้ที่หลังบ้าน → บัญชี)
  async function offerPasskey() {
    if (!PK || window.Passkey.hasOnDevice() || window.Passkey.asked()) return;
    PK.prepRegister();
    const yes = await confirmSheet({ title: 'ครั้งหน้าเข้าสู่ระบบด้วย', name: 'Face ID / ลายนิ้วมือ', sub: 'ไม่ต้องรอรหัสทางอีเมล', ok: 'ตั้งค่าเลย', no: 'ไว้ทีหลัง' });
    window.Passkey.setAsked();
    if (!yes) return;
    try { await PK.register(); flash('ok', 'ตั้งค่า Face ID / ลายนิ้วมือแล้ว', '', 'ครั้งหน้าเข้าสู่ระบบได้ทันที'); }
    catch (x) { const t = window.Passkey.errorText(x); if (t) flash('bad', t === 'กรุณาแตะอีกครั้ง' ? 'ตั้งค่าได้ที่หลังบ้าน → บัญชี' : t, '', ''); }
  }

  // ---------- login ----------
  function showLogin() {
    show('login');
    if (PK) { $('pkLogin').hidden = false; PK.prepLogin(); }
    let step = 1;
    $('haveCode').onclick = () => { const f = $('lf'); step = 2; f.code.hidden = false; f.querySelector('button').textContent = 'เข้าสู่ระบบ'; $('haveCode').hidden = true; (f.email.value ? f.code : f.email).focus(); };
    $('lf').onsubmit = async e => {
      e.preventDefault(); const f = e.target; $('lmsg').textContent = '';
      try {
        if (step === 1) { await api('login_request', { email: f.email.value, from: 'scan', lang: EN ? 'en' : 'th' }); step = 2; f.code.hidden = false; f.querySelector('button').textContent = 'เข้าสู่ระบบ'; f.code.focus(); $('lmsg').textContent = 'ถ้าอีเมลนี้มีสิทธิ์ ระบบส่งรหัสไปแล้ว'; }
        else { await api('login_verify', { email: f.email.value, code: f.code.value }); justLoggedIn = true; start(); }
      } catch (x) { if (x.message !== 'auth') $('lmsg').textContent = x.message; }
    };
  }

  let justLoggedIn = false;
  async function start() {
    setNet();
    const loginToken = new URLSearchParams(location.search).get('login');
    if (loginToken) {
      history.replaceState(null, '', location.pathname + location.hash);
      try { await api('login_link', { token: loginToken }); justLoggedIn = true; } catch (x) { showLogin(); $('lmsg').textContent = x.message === 'auth' ? '' : x.message; return; }
    }
    try {
      const j = await api('me');
      if (!j.me) { showLogin(); return; }
    } catch (_) {
      if (!session) return;   // ออฟไลน์ตั้งแต่เปิด: ใช้รอบที่เลือกไว้ล่าสุด
    }
    if (justLoggedIn) { justLoggedIn = false; setTimeout(offerPasskey, 600); }
    const fromHash = Number((location.hash || '').slice(1));
    if (fromHash && (!session || session.id !== fromHash)) {
      try {
        const j = await api('sessions', { scope: 'all' });
        const s = j.sessions.find(x => x.id === fromHash);
        if (s) { pick({ id: s.id, date: s.date, start: s.start, end: s.end }); return; }
      } catch (_) {}
    }
    try { localStorage.setItem('vb-admin', '1'); } catch (_) {}
    if (session) pick(session); else showSessions();
  }

  // เปิดอยู่ในหลังบ้าน (แอปหน้าจอโฮม): หน้าสแกนค้างไว้ในกรอบ ไม่โหลดใหม่ → iOS ขอสิทธิ์กล้องครั้งเดียวต่อการเปิดแอป
  const EMBED = new URLSearchParams(location.search).has('embed') && window.parent !== window;
  if (EMBED) {
    document.body.classList.add('embed');
    window.addEventListener('message', async e => {
      if (e.origin !== location.origin || !e.data) return;
      if (e.data.type === 'scan-close') { stopCam(); if (!gunOn()) { $('camBtn').hidden = false; $('camBtn').textContent = 'เปิดกล้อง'; } }
      if (e.data.type === 'scan-open') {
        const id = Number(e.data.id) || 0;
        if (id && (!session || session.id !== id)) {
          try { const j = await api('sessions', { scope: 'all' }); const x = j.sessions.find(y => y.id === id); if (x) { pick({ id: x.id, date: x.date, start: x.start, end: x.end }); return; } } catch (_) {}
        }
        if (session && !$('scanner').hidden && !gunOn() && !stream) startCam();
        sync();
      }
    });
  }
  $('backBtn').onclick = e => {   // กลับหน้าหลังบ้านที่กดเข้ามา (จำไว้ตอนกดลิงก์สแกน)
    if (EMBED) { e.preventDefault(); window.parent.postMessage({ type: 'scan-back' }, location.origin); return; }
    let back = null; try { back = sessionStorage.getItem('vb-back'); } catch (_) {}
    if (back && back.startsWith(location.origin)) { e.preventDefault(); location.href = back; }
  };
  // หน้าสแกนเป็นเครื่องมือหน้างาน: ปิดการซูม (iOS ไม่สน maximum-scale สำหรับ pinch / แตะ 2 ครั้ง)
  ['gesturestart', 'gesturechange'].forEach(ev => document.addEventListener(ev, e => e.preventDefault(), { passive: false }));
  // iOS: แตะ 2 ครั้งบนข้อความ/พื้นที่ว่าง (ไม่ใช่ปุ่ม/ช่องกรอก) = ซูม แม้ตั้ง touch-action แล้ว → กันไว้ (pinch ยังใช้ได้ ยกเว้นหน้าสแกน)
  { let lastT = 0; document.addEventListener('touchend', e => { const now = Date.now(); if (now - lastT < 350 && !e.target.closest('a,button,input,select,textarea,label,summary,[role=button],[data-id],.slot,.chip')) e.preventDefault(); lastT = now; }, { passive: false }); }
  $('alang').textContent = EN ? 'ไทย' : 'EN';
  $('alang').onclick = () => window.setAdminLang(EN ? 'th' : 'en');
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
  start();
})();
