(() => {
  'use strict';

  const I18N = window.I18N_MANAGE;
  // ข้อความที่แก้ในหลังบ้าน (เฉพาะคีย์ที่ค่าเดิมเป็นข้อความ)
  { const ov = (window.TEXT_OVERRIDES || {})['I18N_MANAGE'] || {}; ['th', 'en'].forEach(l => Object.entries(ov[l] || {}).forEach(([k, v]) => { if (typeof I18N[l][k] === 'string' && typeof v === 'string' && v !== '') I18N[l][k] = v; })); }
  // ชื่อสถานที่จากหลังบ้าน (ข้อมูลสถานที่)
  // ข้อมูลสถานที่ในข้อความ: {venue} = ชื่อ, {group_email} = อีเมลนัดหมายหมู่คณะ (เหมือน venue_fill() ฝั่ง PHP)
  { const M = window.MUSEUM || {}, nm = M.name || {};
    ['th', 'en'].forEach(l => Object.keys(I18N[l]).forEach(k => { const v = I18N[l][k]; if (typeof v !== 'string' || !v.includes('{')) return;
      const n = nm[l] || '';
      I18N[l][k] = v.replace(/(?<![\p{L}])(\p{Ll}+,? )\{venue\}/gu, (_, p) => p + (n.startsWith('The ') ? 'the ' + n.slice(4) : n)).split('{venue}').join(n).split('{group_email}').join(M.group_email || ''); })); }

  const $ = id => document.getElementById(id);
  // หน้าต่างยืนยัน: ask({title, text, ok, cancel, danger, input:{type,value,placeholder}, check:{label,checked}})
  // → Promise<null | {value, checked}>  (null = ยกเลิก/ปิด)
  // กล่องเหตุผลการยกเลิก (ไม่บังคับ): select + ช่องพิมพ์ → { reason_key, reason_text }
  function reasonBlock() {
    const w = document.createElement('div'); w.className = 'reason';
    w.innerHTML = `<label><span>${t('r_label')}</span><select class="ask-in"><option value="">${t('r_pick')}</option>${Object.entries(t('r_opts')).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select></label>
      <input class="ask-in" type="text" maxlength="300" placeholder="${t('r_more')}">`;
    w.get = () => ({ reason_key: w.querySelector('select').value, reason_text: w.querySelector('input').value.trim() });
    return w;
  }
  let lastReason = {};
  function ask(o) {
    return new Promise(resolve => {
      const bg = document.createElement('div'); bg.className = 'ask-bg';
      const box = document.createElement('div'); box.className = 'ask'; box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true');
      const h = document.createElement('h3'); h.textContent = o.title; box.append(h);
      if (o.text) { const p = document.createElement('p'); p.textContent = o.text; box.append(p); }
      let inp = null, chk = null;
      if (o.input) { inp = document.createElement('input'); inp.className = 'ask-in'; inp.type = o.input.type || 'text'; inp.value = o.input.value ?? ''; if (o.input.placeholder) inp.placeholder = o.input.placeholder; if (o.input.min !== undefined) inp.min = o.input.min; box.append(inp); }
      if (o.check) { const l = document.createElement('label'); l.className = 'ask-chk'; chk = document.createElement('input'); chk.type = 'checkbox'; chk.checked = !!o.check.checked; l.append(chk, document.createTextNode(o.check.label)); box.append(l); }
      let rb = null;
      if (o.reason) { rb = reasonBlock(); box.append(rb); }
      const btns = document.createElement('div'); btns.className = 'ask-btns';
      const no = document.createElement('button'); no.type = 'button'; no.textContent = o.cancel || (lang === 'th' ? 'ไม่ใช่' : 'No');
      const yes = document.createElement('button'); yes.type = 'button'; yes.className = 'ok' + (o.danger ? ' danger' : ''); yes.textContent = o.ok || (lang === 'th' ? 'ยืนยัน' : 'Confirm');
      btns.append(no, yes); box.append(btns); bg.append(box); document.body.append(bg);
      const done = v => { bg.remove(); document.removeEventListener('keydown', key); resolve(v); };
      const key = e => { if (e.key === 'Escape') done(null); if (e.key === 'Enter' && inp && document.activeElement === inp) yes.click(); };
      document.addEventListener('keydown', key);
      no.onclick = () => done(null);
      bg.onclick = e => { if (e.target === bg) done(null); };
      yes.onclick = () => {
        if (inp && inp.required !== false && o.input.required && !inp.value.trim()) { inp.focus(); return; }
        if (rb) lastReason = rb.get();
        done({ value: inp ? inp.value.trim() : null, checked: chk ? chk.checked : null });
      };
      (inp || no).focus();
    });
  }

  const qs = new URLSearchParams(location.search);
  const code = qs.get('o') || '', secret = qs.get('s') || '', tkey = qs.get('t') || '';   // t = ลิงก์ตั๋วส่วนตัวของผู้เข้าชม
  let lang = 'en', data = null, errKey = '', note = '', okKey = '';
  { let saved = null; try { saved = localStorage.getItem('lang'); } catch (_) {}
    lang = saved || ((navigator.languages || [navigator.language || '']).some(l => /^th\b/i.test(l)) ? 'th' : 'en'); }
  // ลิงก์จากอีเมลบอกภาษาที่ใช้ตอนจอง
  if (qs.get('lang') === 'th' || qs.get('lang') === 'en') { lang = qs.get('lang'); try { localStorage.setItem('lang', lang); } catch (_) {} }
  // &confirm=1 / &attend=1 จากปุ่มในอีเมล → กดให้อัตโนมัติครั้งเดียว แล้วล้างออกจาก URL (รีเฟรชไม่กดซ้ำ)
  let autoAction = qs.get('confirm') === '1' ? 'confirm' : qs.get('attend') === '1' ? 'attend' : qs.get('cancel') === '1' ? 'cancel' : '';
  if (autoAction || qs.get('lang')) { ['confirm', 'attend', 'cancel', 'lang'].forEach(k => qs.delete(k)); history.replaceState(null, '', location.pathname + '?' + qs.toString()); }
  if (lang !== 'th' && lang !== 'en') lang = 'en';
  const t = k => I18N[lang][k];

  const fmtDate = iso => {
    const [y, m, d] = iso.split('-').map(Number);
    return t('date')(t('dowsLong')[new Date(y, m - 1, d).getDay()], d, t('months')[m - 1], y);
  };

  // กำหนดเวลา: 00:00 แสดงเป็น 23:59 ของวันก่อน (ไม่ให้งงว่าวันไหน)
  const fmtDeadline = iso => {
    if (!iso) return '';
    let d = new Date(iso);
    const p = n => String(n).padStart(2, '0');
    const bkk = new Date(d.getTime() + (7 * 60 + d.getTimezoneOffset()) * 60000);   // แสดงเวลาไทยเสมอ
    let x = bkk; if (x.getHours() === 0 && x.getMinutes() === 0) x = new Date(x.getTime() - 60000);
    return t('at')(fmtDate(`${x.getFullYear()}-${p(x.getMonth() + 1)}-${p(x.getDate())}`), `${p(x.getHours())}:${p(x.getMinutes())}`);
  };

  function render() {
    document.documentElement.lang = lang;
    document.querySelectorAll('[data-i18n]').forEach(el => { const v = t(el.dataset.i18n); if (typeof v === 'string') el.textContent = v; });
    document.querySelectorAll('.lang button').forEach(b => b.classList.toggle('on', b.dataset.lang === lang));
    document.title = t('title') + ' · ' + I18N.en.museum;
    if (errKey) { $('msg').hidden = false; $('msg').textContent = t(errKey); $('detail').hidden = true; return; }
    if (!data) return;
    $('msg').hidden = true;
    $('detail').hidden = false;
    let demo = $('demoNote');   // การจองตัวอย่างจากอีเมลตัวอย่างของหลังบ้าน
    if (data.demo && !demo) { demo = document.createElement('p'); demo.id = 'demoNote'; demo.className = 'note'; demo.style.cssText = 'margin:0 0 12px;padding:8px 12px;border-radius:8px;background:#f6e3e0;text-align:center'; $('detail').prepend(demo); }
    if (demo) demo.textContent = lang === 'th' ? 'การจองตัวอย่าง (ไม่ใช่การจองจริง)' : 'Sample booking (not a real booking)';
    $('code').textContent = data.code;
    const st = $('status');
    st.textContent = t('st')[data.status] || data.status;
    st.className = 'tag ' + (data.status === 'p' ? 'open' : (data.status === 'n' || data.status === 'w') ? 'few' : 'full');
    $('confirmBox').hidden = data.status !== 'w';
    if (data.status === 'w') $('confirmText').textContent = t('confirm_by')(fmtDeadline(data.confirm_by));
    $('attendBox').hidden = !data.can_attend;
    $('attendText').textContent = data.attend ? '✓ ' + t('attended') : t('attend_q');
    $('attendBtn').hidden = !!data.attend;
    if (data.expired && !$('err').textContent) showErr(t('expired'));
    $('okMsg').hidden = !okKey; $('okMsg').textContent = okKey ? t(okKey) : '';
    $('summary').innerHTML = '';
    if (data.slot) {
      const ICONS = ['<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>', '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>', '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><circle cx="17" cy="9" r="2.5"/><path d="M16.5 14.6c2.6.2 4.4 1.9 5 5.4"/></svg>'];
      [fmtDate(data.slot.date), t('time')(data.slot.start, data.slot.end || ''), t('people')(data.qty)]
        .forEach((txt, i) => { const sp = document.createElement('span'); sp.innerHTML = ICONS[i]; const b = document.createElement('b'); b.textContent = txt; sp.append(b); $('summary').append(sp); });
    }
    const tickets = data.tickets || [];
    // มี QR = แสดงเป็นการ์ดตั๋ว (ชื่ออยู่ในการ์ดแล้ว ไม่ต้องแสดงรายชื่อซ้ำ)
    $('names').replaceChildren(...(tickets.length ? [] : data.names.filter(Boolean).map(n => { const li = document.createElement('li'); li.textContent = n; return li; })));
    $('qrNote').hidden = !tickets.length;
    $('printWrap').hidden = !tickets.length;
    $('calLinks').hidden = !data.ics;
    if (data.ics) { $('icsLink').href = data.ics + '&lang=' + lang; $('gcalLink').href = data.gcal; }
    $('tickets').replaceChildren(...tickets.map(tk => {
      const card = document.createElement('div'); card.className = 'ticket';
      const lab = document.createElement('small'); lab.textContent = t('visitorN')(tk.n);
      const nm = document.createElement('b'); nm.textContent = tk.name;
      const img = document.createElement('img'); img.src = tk.qr; img.alt = 'QR'; img.width = 180; img.height = 180;
      const cd = document.createElement('code'); cd.textContent = data.mode === 'ticket' ? data.code : `${data.code}-${tk.n}`;
      // ส่วนที่แสดงเฉพาะตอนพิมพ์ (1 ใบต่อหน้า): หัว (ชื่อสถานที่) + วันเวลา + ที่อยู่
      const ph = document.createElement('div'); ph.className = 'print-only print-head';
      ph.innerHTML = '<img src="' + (document.querySelector('.brand-logo') || {}).getAttribute?.('src') + '" alt="" width="70"><b></b>'; ph.querySelector('b').textContent = I18N.th.museum;
      const pw = document.createElement('div'); pw.className = 'print-only print-when';
      if (data.slot) pw.textContent = `${fmtDate(data.slot.date)} · ${t('time')(data.slot.start, data.slot.end || '')}`;
      const pa = document.createElement('div'); pa.className = 'print-only print-addr';
      pa.textContent = (window.MUSEUM && window.MUSEUM.address[lang]) || '';
      const pn = document.createElement('div'); pn.className = 'print-only print-note'; pn.textContent = t('show_qr');
      card.append(ph, pw, lab, nm, img, cd, pn, pa);
      if (!tkey && data.can_cancel && tickets.length > 1) { const c = document.createElement('button'); c.type = 'button'; c.className = 'linkbtn cancel-one'; c.textContent = t('cancel_one'); c.onclick = () => cancelOne(tk); card.append(c); }
      // ป้ายทางการ Apple Wallet (ไม่แสดงบน Android — Google Wallet แยกต่างหาก)
      // iPhone/iPad เห็น Apple, Android เห็น Google, คอมพิวเตอร์เห็นทั้งคู่
      const isAndroid = /Android/i.test(navigator.userAgent), isApple = /iPhone|iPad|iPod/i.test(navigator.userAgent) || (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);
      const L = lang === 'th' ? 'th' : 'en', badges = document.createElement('div'); badges.className = 'wallet-badges';
      if (tk.pass && !isAndroid) badges.insertAdjacentHTML('beforeend', `<a class="wallet-badge" href="${tk.pass}&lang=${lang}"><img src="assets/brand/wallet/apple-wallet-${L}.svg" alt="${t('wallet')}" height="42"></a>`);
      if (tk.gpass && !isApple) badges.insertAdjacentHTML('beforeend', `<a class="wallet-badge" href="${tk.gpass}" target="_blank" rel="noopener"><img src="assets/brand/wallet/google-wallet-${L}.svg" alt="${lang === 'th' ? 'เพิ่มลงใน Google Wallet' : 'Add to Google Wallet'}" height="42"></a>`);
      if (badges.children.length) card.append(badges);
      return card;
    }));
    $('cancelBtn').hidden = !data.can_cancel;
    $('editBtn').hidden = !data.can_edit || !$('editBox').hidden;
    $('cancelBtn').textContent = t(tkey ? 'cancel_ticket' : 'cancel');
    const reason = data.status === 'c' ? '' : (!data.can_cancel && data.reason && data.reason !== 'status' ? t('cant')[data.reason] : '');
    $('cantNote').textContent = note ? t(note) : reason || '';
    $('cantNote').hidden = !$('cantNote').textContent;
  }

  function showErr(msg) { $('err').textContent = msg || ''; $('err').hidden = !msg; if (msg) $('err').scrollIntoView({ block: 'center', behavior: 'smooth' }); }

  async function load() {
    if (!tkey && (!code || !secret)) { errKey = 'badLink'; render(); return; }
    try {
      const q = (tkey ? `t=${encodeURIComponent(tkey)}` : `code=${encodeURIComponent(code)}&secret=${encodeURIComponent(secret)}`) + `&lang=${lang}`;
      const r = await fetch(`api/order.php?${q}`, { cache: 'no-store' });
      const j = await r.json().catch(() => ({}));
      if (r.ok && j.ok) { data = j; errKey = ''; }
      else errKey = (j.error === 'not_found' || j.error === 'bad_link') ? 'badLink' : 'netErr';
    } catch (_) { errKey = 'netErr'; }
    $('box').setAttribute('aria-busy', 'false');
    render();
  }

  // หลายคนในการจอง: ให้เลือกว่ายกเลิกใคร (ครบทุกคน = ยกเลิกทั้งการจอง)
  function pickWho(tickets) {
    return new Promise(res => {
      const bg = document.createElement('div'); bg.className = 'ask-bg';
      bg.innerHTML = `<div class="ask" role="dialog" aria-modal="true"><h3>${t('who_q')}</h3><p>${t('who_t')}</p>
        <div class="who">${tickets.map(x => `<label class="ask-chk"><input type="checkbox" value="${x.n}"> ${x.name.replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]))}</label>`).join('')}
        <label class="ask-chk all"><input type="checkbox" class="allbox"> ${t('all')}</label></div>
        <div class="rb-slot"></div><p class="err" hidden></p>
        <div class="ask-btns"><button type="button" class="no">${lang === 'th' ? 'ไม่ยกเลิก' : 'Keep booking'}</button><button type="button" class="ok danger">${t('cancel_sel')}</button></div></div>`;
      document.body.append(bg);
      const rb = reasonBlock(); bg.querySelector('.rb-slot').append(rb);
      const boxes = [...bg.querySelectorAll('.who input:not(.allbox)')], all = bg.querySelector('.allbox');
      all.onchange = () => boxes.forEach(b => { b.checked = all.checked; });
      boxes.forEach(b => b.onchange = () => { all.checked = boxes.every(x => x.checked); });
      const done = v => { bg.remove(); res(v); };
      bg.querySelector('.no').onclick = () => done(null);
      bg.onclick = e => { if (e.target === bg) done(null); };
      bg.querySelector('.ok').onclick = () => {
        const ns = boxes.filter(b => b.checked).map(b => Number(b.value));
        if (!ns.length) { const er = bg.querySelector('.err'); er.textContent = t('pick_one'); er.hidden = false; return; }
        lastReason = rb.get();
        done(ns);
      };
    });
  }
  async function cancelAll(skipAsk) {
    const summary = data.slot ? `${fmtDate(data.slot.date)} · ${t('time')(data.slot.start, data.slot.end || '')}` : '';
    if (!skipAsk && !await ask({ title: t(tkey ? 'cancel_ticket' : 'cancel') + '?', text: (summary ? summary + '\n' : '') + t(tkey ? 'confirm_ticket' : 'confirm'),
      ok: t(tkey ? 'cancel_ticket' : 'cancel'), cancel: lang === 'th' ? 'ไม่ยกเลิก' : 'Keep booking', danger: true, reason: true })) return;
    const btn = $('cancelBtn');
    btn.disabled = true; btn.classList.add('busy'); showErr('');
    try {
      const r = await fetch('api/order.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign(tkey ? { t: tkey, lang } : { code, secret, lang }, lastReason)) });
      const j = await r.json().catch(() => ({}));
      if (r.ok && j.ok) { note = 'canceled'; await load(); }
      else showErr(j.error === 'demo' || lang === 'th' ? (j.message || t('netErr')) : (j.reason && t('cant')[j.reason]) || t('netErr'));
    } catch (_) { showErr(t('netErr')); }
    finally { btn.disabled = false; btn.classList.remove('busy'); }
  }
  $('cancelBtn').addEventListener('click', async () => {
    lastReason = {};
    const tickets = (data && data.tickets) || [];
    if (tkey || tickets.length <= 1) return cancelAll(false);   // คนเดียว / ลิงก์ตั๋วส่วนตัว: ถามยืนยันปกติ
    const ns = await pickWho(tickets);
    if (!ns) return;
    if (ns.length === tickets.length) return cancelAll(true);   // เลือกครบ = ยกเลิกทั้งการจอง
    showErr('');
    try {
      const r = await fetch('api/order.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code, secret, lang, action: 'cancel_tickets', ns, ...lastReason }) });
      const j = await r.json().catch(() => ({}));
      if (r.ok && j.ok) { okKey = 'one_canceled'; await load(); } else showErr(j.error === 'demo' || lang === 'th' ? (j.message || t('netErr')) : t('netErr'));
    } catch (_) { showErr(t('netErr')); }
  });

  async function post(action, btn) {
    if (btn) { btn.disabled = true; btn.classList.add('busy'); }
    showErr('');
    try {
      const r = await fetch('api/order.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(tkey ? { t: tkey, lang, action } : { code, secret, lang, action }) });
      const j = await r.json().catch(() => ({}));
      if (r.ok && j.ok) { okKey = action === 'confirm' ? (j.already ? '' : 'confirmed') : 'attended'; await load(); }
      else showErr(j.error === 'expired' ? t('expired') : j.error === 'demo' || lang === 'th' ? (j.message || t('netErr')) : t('netErr'));
    } catch (_) { showErr(t('netErr')); }
    finally { if (btn) { btn.disabled = false; btn.classList.remove('busy'); } }
  }
  // ดาวน์โหลดตั๋ว PDF (A6 เดียวกับที่แนบในอีเมล — พิมพ์ต่อเองได้)
  $('printBtn').addEventListener('click', async () => {
    const url = 'api/ticket_pdf.php?' + (tkey ? 't=' + encodeURIComponent(tkey) : 'o=' + encodeURIComponent(code) + '&s=' + encodeURIComponent(secret)) + '&lang=' + lang;
    // เปิดเป็นแอปหน้าจอโฮม: ไฟล์ที่เปิดแล้วไม่มีปุ่มกลับ → ใช้เมนูแชร์ของเครื่อง (บันทึก/พิมพ์/ส่งต่อ)
    const app = navigator.standalone === true || matchMedia('(display-mode: standalone)').matches;
    if (app && window.FileView) {   // ดูตั๋วในแอปพร้อมปุ่มกลับ/แชร์
      window.FileView.open(url, { title: t('print'), back: lang === 'th' ? 'กลับ' : 'Back', share: lang === 'th' ? 'แชร์ / บันทึก' : 'Share / save', error: t('netErr') });
      return;
    }
    location.href = url;
  });
  async function cancelOne(tk) {
    lastReason = {};
    if (!await ask({ title: t('cancel_one_q')(tk.name), text: t('cancel_one_t'), ok: t('cancel_one'), cancel: t('keep'), danger: true, reason: true })) return;
    showErr('');
    try {
      const r = await fetch('api/order.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code, secret, lang, action: 'cancel_ticket', n: tk.n, ...lastReason }) });
      const j = await r.json().catch(() => ({}));
      if (r.ok && j.ok) { okKey = 'one_canceled'; await load(); } else showErr(j.error === 'demo' || lang === 'th' ? (j.message || t('netErr')) : t('netErr'));
    } catch (_) { showErr(t('netErr')); }
  }
  // ฟอร์มแก้ไขข้อมูล (ผู้จอง: ผู้ติดต่อ + ผู้เข้าชมทุกคน, ลิงก์ตั๋วส่วนตัว: เฉพาะตัวเอง)
  const escH = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const phoneLocal = v => (v || '').replace(/^\+66/, '0');
  function openEdit() {
    const e = data.edit; if (!e) return;
    const f = (name, label, val, type = 'text', opt = false, ro = false) => `<label><span>${label}${opt ? ` <em>${t('opt')}</em>` : ''}</span><input name="${name}" type="${type}" value="${escH(val)}"${ro ? ' readonly' : ''}${type === 'tel' ? ' inputmode="tel"' : ''}></label>`;
    let html = `<p class="hint">${escH(t('edit_hint'))}</p>`;
    if (e.contact) html += `<fieldset class="box"><legend>${t('contact')}</legend><div class="grid2">${f('c_given', t('given'), e.contact.given)}${f('c_family', t('family'), e.contact.family)}</div>
      ${f('c_email', t('email'), e.contact.email, 'email', false, true)}<p class="hint">${escH(t('email_fixed'))}</p>${f('c_phone', t('phone'), phoneLocal(e.contact.phone), 'tel')}${f('c_org', t('org'), e.contact.org, 'text', true)}</fieldset>`;
    html += e.tickets.map(x => `<fieldset class="box"><legend>${t('visitorN')(x.n)}</legend><div class="grid2">${f(`t${x.id}_given`, t('given'), x.given)}${f(`t${x.id}_family`, t('family'), x.family)}</div>
      ${f(`t${x.id}_phone`, t('phone'), phoneLocal(x.phone), 'tel', true)}${f(`t${x.id}_email`, t('email'), x.email, 'email', true)}${f(`t${x.id}_org`, t('org'), x.org, 'text', true)}</fieldset>`).join('');
    html += `<p class="err" id="editErr" hidden></p><div class="actions"><button type="button" class="ghost" id="editCancel">${t('keep') === 'Keep' ? 'Cancel' : 'ยกเลิก'}</button><button class="primary" id="editSave">${t('save')}</button></div>`;
    const box = $('editBox'); box.innerHTML = html; box.hidden = false; $('editBtn').hidden = true;
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    $('editCancel').onclick = () => { box.hidden = true; render(); };
    box.onsubmit = async ev => {
      ev.preventDefault();
      const g = n => (box.elements[n] || {}).value || '';
      const tickets = e.tickets.map(x => ({ id: x.id, given: g(`t${x.id}_given`), family: g(`t${x.id}_family`), phone: g(`t${x.id}_phone`), email: g(`t${x.id}_email`), org: g(`t${x.id}_org`) }));
      const body = tkey ? { t: tkey, lang, action: 'edit', ticket: tickets[0] }
        : { code, secret, lang, action: 'edit', tickets, contact: { given: g('c_given'), family: g('c_family'), phone: g('c_phone'), org: g('c_org') } };
      const btn = $('editSave'); btn.disabled = true; btn.classList.add('busy');
      try {
        const r = await fetch('api/order.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        const j = await r.json().catch(() => ({}));
        if (r.ok && j.ok) { box.hidden = true; okKey = 'edited'; await load(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
        else { const el = $('editErr'); el.textContent = lang === 'th' ? (j.message || t('netErr')) : (j.error === 'validation' ? 'Please check the details (names are required; phone numbers and emails must be valid and different for each visitor).' : t('netErr')); el.hidden = false; }
      } catch (_) { const el = $('editErr'); el.textContent = t('netErr'); el.hidden = false; }
      finally { btn.disabled = false; btn.classList.remove('busy'); }
    };
  }
  $('editBtn').addEventListener('click', openEdit);
  $('confirmBtn').addEventListener('click', () => post('confirm', $('confirmBtn')));
  $('attendBtn').addEventListener('click', () => post('attend', $('attendBtn')));

  document.querySelectorAll('.lang button').forEach(b => b.addEventListener('click', () => {
    lang = b.dataset.lang;
    try { localStorage.setItem('lang', lang); } catch (_) {}
    render();
  }));

  // iOS: แตะ 2 ครั้งบนข้อความ/พื้นที่ว่าง (ไม่ใช่ปุ่ม/ช่องกรอก) = ซูม แม้ตั้ง touch-action แล้ว → กันไว้ (pinch ยังใช้ได้ ยกเว้นหน้าสแกน)
  { let lastT = 0; document.addEventListener('touchend', e => { const now = Date.now(); if (now - lastT < 350 && !e.target.closest('a,button,input,select,textarea,label,summary,[role=button],[data-id],.slot,.chip')) e.preventDefault(); lastT = now; }, { passive: false }); }
  render();
  load().then(() => {
    if (autoAction === 'confirm' && data && data.status === 'w') post('confirm');
    else if (autoAction === 'confirm' && data && data.expired) showErr(t('expired'));
    else if (autoAction === 'attend' && data && data.can_attend && !data.attend) post('attend');
    else if (autoAction === 'cancel' && data && data.can_cancel) $('cancelBtn').click();   // ปุ่มยกเลิกในอีเมล → เปิดหน้าต่างยืนยัน (ไม่ยกเลิกเอง)
    autoAction = '';
  });
})();
