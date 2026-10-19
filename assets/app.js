(() => {
  'use strict';

  // ---------- i18n ----------
  const I18N = window.I18N_BOOKING;
  // ข้อความที่แก้ในหลังบ้าน (เฉพาะคีย์ที่ค่าเดิมเป็นข้อความ)
  { const ov = (window.TEXT_OVERRIDES || {})['I18N_BOOKING'] || {}; ['th', 'en'].forEach(l => Object.entries(ov[l] || {}).forEach(([k, v]) => { if (typeof I18N[l][k] === 'string' && typeof v === 'string' && v !== '') I18N[l][k] = v; })); }
  // ชื่อสถานที่จากหลังบ้าน (ข้อมูลสถานที่)
  // ข้อมูลสถานที่ในข้อความ: {venue} = ชื่อ, {group_email} = อีเมลนัดหมายหมู่คณะ (เหมือน venue_fill() ฝั่ง PHP)
  { const M = window.MUSEUM || {}, nm = M.name || {};
    ['th', 'en'].forEach(l => Object.keys(I18N[l]).forEach(k => { const v = I18N[l][k]; if (typeof v !== 'string' || !v.includes('{')) return;
      const n = nm[l] || '';
      I18N[l][k] = v.replace(/(?<![\p{L}])(\p{Ll}+,? )\{venue\}/gu, (_, p) => p + (n.startsWith('The ') ? 'the ' + n.slice(4) : n)).split('{venue}').join(n).split('{group_email}').join(M.group_email || ''); })); }


  // ---------- state ----------
  const S = {
    lang: 'en',           // ค่าเริ่มต้นภาษาอังกฤษ (ผู้ใช้กำหนด); จำตัวเลือกล่าสุดไว้ใน localStorage
    slots: [],            // จาก API
    byDate: new Map(),    // 'YYYY-MM-DD' -> [slot]
    maxPerOrder: 10,
    view: null,           // {y, m} เดือนที่แสดง
    date: null,
    slot: null,
    qty: 1,
    minMonth: null, maxMonth: null,
    pastByDate: new Map(),   // 'YYYY-MM-DD' -> [รอบที่จบไปแล้ว]
  };
  { let saved = null; try { saved = localStorage.getItem('lang'); } catch (_) {}
    // ยังไม่เคยเลือกภาษา: มือถือ/เบราว์เซอร์ตั้งเป็นไทย → ไทย (ไม่งั้นอังกฤษตามเดิม)
    S.lang = saved || ((navigator.languages || [navigator.language || '']).some(l => /^th\b/i.test(l)) ? 'th' : S.lang); }
  { const ql = new URLSearchParams(location.search).get('lang'); if (ql === 'th' || ql === 'en') { S.lang = ql; try { localStorage.setItem('lang', ql); } catch (_) {} } }   // ลิงก์จากอีเมล
  if (S.lang !== 'th' && S.lang !== 'en') S.lang = 'en';

  const $ = id => document.getElementById(id);
  const t = key => I18N[S.lang][key];

  // ---------- helpers ----------
  const pad = n => String(n).padStart(2, '0');
  const ymd = (y, m, d) => `${y}-${pad(m + 1)}-${pad(d)}`;
  const todayYmd = () => { const n = new Date(); return ymd(n.getFullYear(), n.getMonth(), n.getDate()); };
  const fmtDate = (iso) => {
    const [y, m, d] = iso.split('-').map(Number);
    const dow = new Date(y, m - 1, d).getDay();
    return S.lang === 'th'
      ? `${t('dowsLong')[dow]}ที่ ${d} ${t('months')[m - 1]} ${t('year')(y)}`
      : `${t('dowsLong')[dow]}, ${d} ${t('months')[m - 1]} ${y}`;
  };
  const slotState = s => (S.wl && s.id === S.wl.session) ? 'open' : !s.available ? 'full' : (s.few ? 'few' : 'open');
  const waitlistable = s => S.waitlistOn && !s.available && !s.closed && !s.ended && !(S.wl && s.id === S.wl.session);
  const dayState = list => {
    const st = list.map(slotState);
    if (st.includes('open')) return 'open';
    if (st.includes('few')) return 'few';
    return 'full';
  };

  function applyLang() {
    document.documentElement.lang = S.lang;
    const HTML_KEYS = ['f_contact_hint', 'i_1', 'i_2', 'i_3', 'agree'];   // ข้อความคงที่ของเรา (ไม่มี input ผู้ใช้) ที่มี HTML: nowrap / ตัวหนา / ลิงก์
    document.querySelectorAll('[data-i18n]').forEach(el => {
      const v = I18N[S.lang][el.dataset.i18n];
      if (typeof v !== 'string') return;
      if (HTML_KEYS.includes(el.dataset.i18n)) el.innerHTML = v; else el.textContent = v;
    });
    document.querySelectorAll('[data-i18n-ph]').forEach(el => { el.placeholder = I18N[S.lang][el.dataset.i18nPh]; });
    renderDone(); renderPv();
    document.querySelectorAll('[data-i18n-aria]').forEach(el => el.setAttribute('aria-label', I18N[S.lang][el.dataset.i18nAria]));
    document.querySelectorAll('.lang button').forEach(b => b.classList.toggle('on', b.dataset.lang === S.lang));
    document.title = (S.lang === 'th' ? 'จองเข้าชม' : 'Book a visit') + ' · ' + I18N.en.museum;
    renderDow();
    renderCalendar();
    if (S.date) renderSlots();
    if (S.slot) renderSummary();
    renderAttendees(false);
    renderQtyHint();
  }

  // ---------- steps ----------
  function goStep(n) {
    const prev = S.step;
    S.step = n;
    [1, 2, 3, 4].forEach(i => { $('step-' + i).hidden = i !== n; });
    document.querySelectorAll('.steps li').forEach(li => {
      const k = Number(li.dataset.step);
      li.classList.toggle('on', k === n);
      li.classList.toggle('done', k < n);
      const back = k < n && n < 4;
      li.classList.toggle('back', back);
      li.tabIndex = back ? 0 : -1;
      if (back) li.setAttribute('role', 'button'); else li.removeAttribute('role');
    });
    // หน้าอยู่ที่เดิม (ผู้ใช้ต้องการ) — เลื่อนเฉพาะเมื่อหัวของขั้นใหม่หลุดขอบจอด้านบนจนมองไม่เห็น
    const r = $('step-' + n).getBoundingClientRect();
    if (r.top < 0) window.scrollTo({ top: r.top + window.scrollY - 8, behavior: 'smooth' });
    // โปรแกรมอ่านหน้าจอ/คีย์บอร์ด: ย้ายโฟกัสไปหัวข้อขั้นใหม่ (ไม่ค้างที่ปุ่มที่ถูกซ่อน) — ไม่เลื่อนหน้า
    if (S.ready && prev !== n) { const h = $('step-' + n).querySelector('h2, h3'); if (h) { h.tabIndex = -1; h.focus({ preventScroll: true }); } }
    saveDraft();
  }

  // ---------- draft: รีเฟรชแล้วกลับมาขั้นเดิมพร้อมข้อมูลที่กรอก (sessionStorage = เฉพาะแท็บนี้, ปิดแท็บแล้วหาย) ----------
  const DRAFT_KEY = 'visit-draft';
  // คีย์กันจองซ้ำ (เน็ตหลุดแล้วกดใหม่ = ได้การจองเดิม) — ใหม่หลังจองสำเร็จ
  const idemKey = () => { try { let k = sessionStorage.getItem('visit-idem'); if (!k) { k = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join(''); sessionStorage.setItem('visit-idem', k); } return k; } catch (_) { return ''; } };
  // มาจากช่องทางไหน (ครั้งแรกที่เข้าในแท็บนี้): ?utm_source/medium/campaign หรือเว็บที่ลิงก์มา
  const REF = (() => {
    try {
      const saved = sessionStorage.getItem('visit-ref'); if (saved) return JSON.parse(saved);
      const q = new URLSearchParams(location.search), r = { src: q.get('utm_source') || '', med: q.get('utm_medium') || '', camp: q.get('utm_campaign') || '' };
      try { const h = document.referrer ? new URL(document.referrer).hostname : ''; if (h && h !== location.hostname) r.host = h; } catch (_) {}
      // ตรวจเองโดยไม่ต้องใช้ลิงก์แยก: เปิดจากแอป (เบราว์เซอร์ในแอป LINE/Facebook/Instagram) หรือพารามิเตอร์ที่แอปเติมให้เอง
      const ua = navigator.userAgent;
      r.app = /\bLine\//i.test(ua) ? 'line' : /Instagram/i.test(ua) ? 'instagram' : /FBAN|FBAV|FB_IAB|FBIOS/i.test(ua) || q.has('fbclid') ? 'facebook' : q.has('gclid') ? 'google' : '';
      sessionStorage.setItem('visit-ref', JSON.stringify(r)); return r;
    } catch (_) { return {}; }
  })();
  const CONTACT_IDS = ['cGiven', 'cFamily', 'cEmail', 'cPhone', 'cOrg'];
  function collectAttendees() {
    return [...document.querySelectorAll('#attendees .att')].map(r => {
      const o = {};
      ATT_FIELDS.forEach(k => { const i = r.querySelector(`[name=${k}]`); o[k] = i ? i.value : ''; });
      ['same', 'news', 'sameorg'].forEach(k => { const i = r.querySelector(`[name=${k}]`); o[k] = i ? i.checked : false; });
      return o;
    });
  }
  function saveDraft() {
    if (!S.ready) return;   // ยังไม่ได้กู้คืน — อย่าเขียนทับ
    const d = { step: S.step, date: S.date, slot: S.slot ? S.slot.id : null, qty: S.qty, att: collectAttendees(),
      contact: Object.fromEntries(CONTACT_IDS.map(id => [id, $(id).value])), news: $('cNews').checked,
      agree: document.querySelector('[name=agree]').checked };
    try { sessionStorage.setItem(DRAFT_KEY, JSON.stringify(d)); } catch (_) {}
  }
  function clearDraft() { try { sessionStorage.removeItem(DRAFT_KEY); } catch (_) {} }
  function restoreDraft() {
    let d = null;
    try { d = JSON.parse(sessionStorage.getItem(DRAFT_KEY) || 'null'); } catch (_) {}
    S.ready = true;
    if (!d || !d.date || !S.byDate.has(d.date)) return;
    S.date = d.date;
    const [y, m] = d.date.split('-').map(Number);
    S.view = { y, m: m - 1 };
    S.qty = Math.max(1, Number(d.qty) || 1);
    S.slot = S.slots.find(x => x.id === d.slot && slotState(x) !== 'full') || null;
    CONTACT_IDS.forEach(id => { if (d.contact && typeof d.contact[id] === 'string') $(id).value = d.contact[id]; });
    $('cNews').checked = !!d.news;
    document.querySelector('[name=agree]').checked = !!d.agree;
    renderCalendar();
    renderSlots();
    if (d.step >= 3 && S.slot) {
      renderSummary();
      renderAttendees(true, d.att);
      goStep(3);
    } else {
      renderAttendees(true, d.att);
      goStep(2);
    }
  }

  // ---------- calendar ----------
  function renderDow() {
    $('dow').innerHTML = t('dows').map(d => `<span>${d}</span>`).join('');
  }

  function renderCalendar() {
    if (!S.view) return;
    const { y, m } = S.view;
    $('monthLabel').textContent = `${t('months')[m]} ${t('year')(y)}`;
    const first = new Date(y, m, 1).getDay();           // 0 = อาทิตย์
    const dim = new Date(y, m + 1, 0).getDate();
    const today = todayYmd();
    let html = '';
    for (let i = 0; i < first; i++) html += '<button type="button" class="day pad" tabindex="-1"></button>';
    for (let d = 1; d <= dim; d++) {
      const key = ymd(y, m, d);
      const list = S.byDate.get(key);
      let cls = 'day', dis = ' disabled', sub = '';
      // วันที่มีรอบกดดูได้เสมอ (เต็ม/ปิด/จบไปแล้วก็กดดูได้ จองไม่ได้) — ไม่ให้วันหายหรือดูเหมือนมีปัญหา
      if (key < today) { cls += S.pastByDate.has(key) ? ' full' : ' past'; if (S.pastByDate.has(key)) dis = ''; }
      else if (list) { cls += ' ' + dayState(list); dis = ''; }
      else if (S.pastByDate.has(key)) { cls += ' full'; dis = ''; }   // วันนี้ แต่ทุกรอบเริ่มไปแล้ว
      if (key === today) { cls += ' today'; sub = `<small>${t('today')}</small>`; }
      if (key === S.date) cls += ' sel';
      // โปรแกรมอ่านหน้าจอ: อ่านวันที่เต็ม + สถานะ (บนจอบอกด้วยสีอย่างเดียว)
      const st = / few/.test(cls) ? t('lg_few') : / open/.test(cls) ? t('lg_open') : / full/.test(cls) ? t('lg_full') : '';
      const aria = `${fmtDate(key)}${st ? ' · ' + st : ''}${key === today ? ' · ' + t('today') : ''}`;
      html += `<button type="button" class="${cls}"${dis} data-date="${key}" aria-label="${aria.replace(/"/g, '&quot;')}" aria-pressed="${key === S.date}">${d}${sub}</button>`;
    }
    $('days').innerHTML = html;
    const now = new Date();
    $('todayBtn').hidden = y === now.getFullYear() && m === now.getMonth();
    $('prevM').disabled = S.minMonth && (y * 12 + m) <= S.minMonth;
    $('nextM').disabled = S.maxMonth && (y * 12 + m) >= S.maxMonth;
  }

  function shiftMonth(delta) {
    let { y, m } = S.view;
    m += delta;
    if (m < 0) { m = 11; y--; }
    if (m > 11) { m = 0; y++; }
    S.view = { y, m };
    renderCalendar();
  }

  // ---------- slots ----------
  function renderSlots() {
    $('dayLabel').textContent = fmtDate(S.date);
    const list = [...(S.pastByDate.get(S.date) || []), ...(S.byDate.get(S.date) || [])].sort((a, b) => a.start.localeCompare(b.start));
    $('slots').innerHTML = list.map(s => {
      const st = slotState(s);
      // ไม่แสดงจำนวนที่ว่างให้คนจองเห็น (ผู้ใช้กำหนด) — บอกแค่ ว่าง / เหลือน้อย / เต็ม / ปิดรับ / จบไปแล้ว
      const wl = waitlistable(s);
      const tag = s.ended ? t('ended') : s.closed ? t('closed') : st === 'full' ? t(wl ? 'full_wl' : 'full') : st === 'few' ? t('few') : t('unlimited');
      return `<button type="button" class="slot${(S.slot && S.slot.id === s.id) || (S.wlSlot && S.wlSlot.id === s.id) ? ' sel' : ''}" data-id="${s.id}"${st === 'full' && !wl ? ' disabled' : ''}>
        <b>${t('time')(s.start, s.end || '')}</b>
        <span class="tag ${st}">${tag}</span>
      </button>`;
    }).join('');
    // ไม่มีรอบให้เลือก → ซ่อนช่องจำนวนคน
    document.querySelector('#step-2 .qty').hidden = !list.some(s => slotState(s) !== 'full') || !!S.wlSlot;
    $('toForm').hidden = !!S.wlSlot;
    $('wlBox').hidden = !S.wlSlot;
    updateQty();
  }

  function selectSlot(id) {
    const sl = S.slots.find(s => s.id === id) || null;
    // รอบเต็ม → ฟอร์มลงชื่อรอแทนการจอง
    if (sl && waitlistable(sl)) {
      S.wlSlot = sl; S.slot = null;
      $('wlQty').innerHTML = Array.from({ length: S.maxPerOrder }, (_, i) => `<option>${i + 1}</option>`).join('');
      $('wlOk').hidden = true; $('wlForm').hidden = false; $('wlErr').hidden = true;
      renderSlots();
      return;
    }
    if (S.wlSlot) { S.wlSlot = null; renderSlots(); }
    S.slot = sl;
    document.querySelectorAll('.slot').forEach(b => b.classList.toggle('sel', Number(b.dataset.id) === id));
    updateQty();
  }

  function maxQty() {
    let max = S.maxPerOrder;
    if (S.wl && S.slot && S.slot.id === S.wl.session) return S.wl.qty;   // สิทธิ์จาก waiting list: ไม่เกินจำนวนที่ลงชื่อไว้
    if (S.slot && S.slot.max) max = Math.min(max, S.slot.max);
    return Math.max(1, max);
  }

  // "สูงสุด N คน" + ช่องทางหมู่คณะ (ข้อความเดิม) — เด่นขึ้นเมื่อกด + จนถึงจำนวนสูงสุด
  function renderQtyHint() {
    $('qtyHint').innerHTML = `${t('qtyHint')(S.maxPerOrder)}<br><span class="group${S.qty >= maxQty() ? ' hot' : ''}">${t('group')}</span>`;
  }

  function updateQty() {
    const max = maxQty();
    if (S.qty > max) S.qty = max;
    if (S.qty < 1) S.qty = 1;
    $('qty').value = S.qty;
    $('qty').max = max;
    $('qMinus').disabled = S.qty <= 1;
    $('qPlus').disabled = S.qty >= max;
    renderQtyHint();
    $('toForm').disabled = !S.slot;
    saveDraft();
  }

  // ---------- form ----------
  function renderSummary() {
    if (!S.slot) return;
    $('summary').innerHTML =
      `<span><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg><b>${fmtDate(S.date)}</b></span>` +
      `<span><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><b>${t('time')(S.slot.start, S.slot.end || '')}</b></span>` +
      `<span><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><circle cx="17" cy="9" r="2.5"/><path d="M16.5 14.6c2.6.2 4.4 1.9 5 5.4"/></svg><b>${t('people')(S.qty)}</b></span>`;
  }

  // ผู้เข้าชมแต่ละคน: ชื่อ-นามสกุล, เบอร์ (บังคับ), อีเมล (ไม่บังคับ), หน่วยงาน (ไม่บังคับ) — ตามคำถามใน Pretix
  // ทุกคนกรอกรายคน (คนจองอาจไม่ได้มาเอง เช่น เลขาจองให้เจ้านาย) — เฉพาะคนที่ 1 ติ๊ก "ใช้ข้อมูลผู้ติดต่อ" ได้ (ค่าเริ่มต้นไม่ติ๊ก)
  const ATT_FIELDS = ['given', 'family', 'phone', 'email', 'org'];
  function renderAttendees(reset, initial) {
    const box = $('attendees');
    const prev = Array.isArray(initial) ? initial : (reset ? [] : collectAttendees());
    let html = '';
    for (let i = 0; i < S.qty; i++) {
      const p = prev[i] || { given: '', family: '', phone: '', email: '', org: '', same: false, news: false, sameorg: false };
      const field = (k, label, type, req, extra = '') =>
        `<label><span>${label}</span><input name="${k}" type="${type}"${req ? ' required' : ''} autocomplete="off"${extra} value="${esc(p[k] || '')}"></label>`;
      html += `<div class="att">
        <div class="n">${esc(t('visitorN')(i + 1))}</div>
        <div class="att-f">
          ${i === 0 ? `<label class="same"><input type="checkbox" name="same"${p.same ? ' checked' : ''}><span>${esc(t('same_all'))}</span></label><div class="copied" data-copy="same"></div>` : ''}
          <div class="grid2"${i === 0 ? ' data-own-name' : ''}${i === 0 && p.same ? ' hidden' : ''}>
            ${field('given', t('given'), 'text', true, ' maxlength="100"')}
            ${field('family', t('family'), 'text', true, ' maxlength="100"')}
          </div>
          <div class="att-contact"${i === 0 && p.same ? ' hidden' : ''}>
            ${field('email', t('f_email'), 'email', false, ' inputmode="email"')}
            <label class="news"><input type="checkbox" name="news"${p.news ? ' checked' : ''}><span>${esc(t('news'))}</span></label>
            ${field('phone', t('f_phone'), 'tel', false, ' inputmode="tel" placeholder="08x-xxx-xxxx"')}
          </div>
          <label class="same same-org"><input type="checkbox" name="sameorg"${p.sameorg ? ' checked' : ''}><span>${esc(t('same_org'))}</span></label><div class="copied" data-copy="sameorg"></div>
          <div class="att-org"${p.sameorg ? ' hidden' : ''}>
            ${field('org', `${t('f_org')} <em>${t('opt')}</em>`, 'text', false, ' maxlength="190"')}
          </div>
        </div>
      </div>`;
    }
    box.innerHTML = html;
    refreshCopied();
  }

  // แสดงข้อมูลที่ดึงมาจากผู้ติดต่อใต้ช่องที่ติ๊ก (จะได้ไม่ต้องเลื่อนขึ้นไปดู) — อัปเดตทันทีเมื่อแก้ข้อมูลผู้ติดต่อ
  function refreshCopied() {
    const v = id => $(id).value.trim();
    const contact = [`${v('cGiven')} ${v('cFamily')}`.trim(), v('cEmail'), v('cPhone')].filter(Boolean).join(' · ');
    document.querySelectorAll('#attendees .copied').forEach(el => {
      const cb = el.closest('.att').querySelector(`[name=${el.dataset.copy}]`);
      const text = el.dataset.copy === 'same' ? contact : v('cOrg');
      el.textContent = text || (S.lang === 'th' ? '(ยังไม่ได้กรอกข้อมูลผู้ติดต่อ)' : '(contact details not filled in yet)');
      el.hidden = !cb.checked;
    });
  }
  ['cGiven', 'cFamily', 'cEmail', 'cPhone', 'cOrg'].forEach(id => $(id).addEventListener('input', refreshCopied));

  $('attendees').addEventListener('change', e => {
    if (e.target.name === 'sameorg') { e.target.closest('.att').querySelector('.att-org').hidden = e.target.checked; refreshCopied(); return; }
    if (e.target.name !== 'same') return;
    const row = e.target.closest('.att');
    row.querySelector('.att-contact').hidden = e.target.checked;
    const own = row.querySelector('[data-own-name]');
    if (own) own.hidden = e.target.checked;
    refreshCopied();
  });

  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function showErr(msg) {
    const el = $('formErr');
    el.textContent = msg || '';
    el.hidden = !msg;
    if (msg) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    // ไปที่ช่องแรกที่ผิด (ช่องถูกทำเครื่องหมาย .bad หลังเรียกฟังก์ชันนี้บางกรณี → รอรอบถัดไป)
    if (msg) setTimeout(() => { const bad = document.querySelector('#step-3 .bad'); if (bad && typeof bad.focus === 'function') bad.focus({ preventScroll: true }); }, 0);
  }

  // ---------- หน้าเสร็จสิ้น: ยืนยันแล้ว / รอยืนยันทางอีเมล ----------
  function fmtDeadline(iso) {
    if (!iso) return '';
    const d = new Date(iso), p = n => String(n).padStart(2, '0');
    let x = new Date(d.getTime() + (7 * 60 + d.getTimezoneOffset()) * 60000);   // เวลาไทย
    if (x.getHours() === 0 && x.getMinutes() === 0) x = new Date(x.getTime() - 60000);   // 00:00 → 23:59 ของวันก่อน
    return t('at')(fmtDate(`${x.getFullYear()}-${p(x.getMonth() + 1)}-${p(x.getDate())}`), `${p(x.getHours())}:${p(x.getMinutes())}`);
  }
  function renderDone() {
    const pend = !!(S.done && S.done.pending);
    $('doneH').dataset.i18n = pend ? 'done_h_p' : 'done_h';
    $('doneH').textContent = t(pend ? 'done_h_p' : 'done_h');
    $('doneIcon').textContent = pend ? '✉' : '✓';
    $('doneIcon').classList.toggle('pend', pend);
    $('doneMail').hidden = pend;
    $('donePending').hidden = !pend;
    if (pend) $('donePending').textContent = t('done_mail_p')(S.done.email, fmtDeadline(S.done.by));
  }

  // ---------- ยืนยันอีเมล (ev) / เบอร์มือถือไทย (pv) ของผู้ติดต่อด้วยรหัส 6 หลัก ก่อนกดจอง ----------
  // จองสำเร็จ = ยืนยันแล้ว ไม่มีสถานะค้างให้คนเข้าใจผิดว่าจองแล้ว (ผู้ใช้เลือก)
  const normEmail = v => v.trim().toLowerCase();
  const normPh = v => { const d = v.replace(/[^\d+]/g, ''); return d.startsWith('+') ? d : d.replace(/^0/, '+66'); };
  function makeVerifier(o) {   // o: {id, input, channel, norm, needed, key}
    const V = { token: '', value: '', sent: false };
    try { Object.assign(V, JSON.parse(sessionStorage.getItem('visit-' + o.id) || '{}')); } catch (_) {}
    const save = () => { try { sessionStorage.setItem('visit-' + o.id, JSON.stringify({ token: V.token, value: V.value })); } catch (_) {} };
    const el = k => $(o.id + k);
    const cur = () => o.norm($(o.input).value);
    const needed = () => o.needed(cur());
    const verified = () => !!V.token && V.value === cur();
    const msg = (txt, ok) => { const m = el('Msg'); m.textContent = txt || ''; m.hidden = !txt; m.classList.toggle('ok', !!ok); };
    function render() {
      const need = needed();
      $(o.id).hidden = !need;
      if (!need) return;
      const ok = verified();
      el('Send').hidden = ok;
      el('CodeRow').hidden = ok || !V.sent;
      el('Send').textContent = t(V.sent ? 'vc_resend' : o.key + '_send');
      if (ok) msg(t(o.key + '_ok'), true); else if (!V.sent) msg('');
    }
    $(o.input).addEventListener('input', () => { if (V.value && V.value !== cur()) V.sent = false; render(); });
    el('Send').addEventListener('click', async () => {
      const b = el('Send'); b.disabled = true; b.classList.add('busy'); msg('');
      try {
        const r = await fetch('api/verify.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'send', channel: o.channel, value: $(o.input).value, lang: S.lang }) });
        const j = await r.json().catch(() => ({}));
        if (r.ok && j.ok) {
          V.sent = true; V.value = cur(); V.token = '';
          render();
          msg(t('vc_sent')($(o.input).value.trim()) + (j.test_code ? ' · ' + t('vc_test') + j.test_code : ''));
          if (j.test_code) el('Code').value = j.test_code;
          el('Code').focus();
        } else msg({ vc_wait: t('vc_wait'), vc_too_many: t('vc_many') }[j.error] || t(o.key + '_fail'));
      } catch (_) { msg(t('netErr')); }
      finally { b.disabled = false; b.classList.remove('busy'); }
    });
    async function check() {
      const b = el('Check'); b.disabled = true; b.classList.add('busy');
      try {
        const r = await fetch('api/verify.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'verify', channel: o.channel, value: $(o.input).value, code: el('Code').value }) });
        const j = await r.json().catch(() => ({}));
        if (r.ok && j.ok) { V.token = j.token; V.value = cur(); save(); $(o.input).classList.remove('bad'); render(); }
        else msg(t('vc_bad'));
      } catch (_) { msg(t('netErr')); }
      finally { b.disabled = false; b.classList.remove('busy'); }
    }
    el('Check').addEventListener('click', check);
    el('Code').addEventListener('input', e => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6); if (e.target.value.length === 6) check(); });
    el('Code').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); check(); } });
    return { render, needed, verified, token: () => V.token, reset: () => { V.token = ''; V.value = ''; V.sent = false; save(); render(); } };
  }
  const EV = makeVerifier({ id: 'ev', input: 'cEmail', channel: 'email', key: 'ev', norm: normEmail, needed: v => S.emailVerify && /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v) });
  const PV = makeVerifier({ id: 'pv', input: 'cPhone', channel: 'phone', key: 'pv', norm: normPh, needed: v => S.smsVerify && /^\+66[689]\d{8}$/.test(v) });
  function renderPv() { EV.render(); PV.render(); }

  async function submit(e) {
    e.preventDefault();
    const f = $('bookForm');
    showErr('');
    f.querySelectorAll('input').forEach(i => i.classList.remove('bad'));

    // client-side validation
    let bad = false;
    f.querySelectorAll('input[required]').forEach(i => {
      if (i.closest('[hidden]')) return;
      const ok = i.type === 'checkbox' ? i.checked : i.value.trim() !== '' && (i.type !== 'email' || /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(i.value));
      if (!ok) { i.classList.add('bad'); bad = true; }
    });
    if (bad) { showErr(S.lang === 'th' ? 'กรุณากรอกข้อมูลที่มีเครื่องหมายให้ครบ' : 'Please complete the highlighted fields'); return; }

    // อีเมล/เบอร์ของผู้เข้าชมห้ามซ้ำกัน (รวมคนที่ 1 ที่ติ๊กใช้ข้อมูลผู้ติดต่อ) — ว่างได้
    // เหมือน normalize_phone() ฝั่งเซิร์ฟเวอร์ (lib/sms.php)
    const normPhone = v => { let d = v.replace(/[^\d+]/g, '').replace(/^00/, '+'); if (/^66\d{8,9}$/.test(d)) d = '+' + d; d = d.replace(/^\+660/, '+66'); return d.startsWith('+') ? d : d.replace(/^0/, '+66'); };
    const dupCheck = (name, contactId, norm) => {
      const seen = new Map();
      let dup = false;
      f.querySelectorAll('#attendees .att').forEach((r, i) => {
        const same = i === 0 && r.querySelector('[name=same]').checked;
        const inp = r.querySelector(`[name=${name}]`);
        const v = norm((same ? $(contactId).value : inp.value).trim());
        if (!v) return;
        if (seen.has(v)) { dup = true; if (!same) inp.classList.add('bad'); const prev = seen.get(v); if (prev) prev.classList.add('bad'); }
        else seen.set(v, same ? null : inp);
      });
      return dup;
    };
    if (dupCheck('email', 'cEmail', v => v.toLowerCase())) { showErr(t('dup_email')); return; }
    if (dupCheck('phone', 'cPhone', v => v ? normPhone(v) : '')) { showErr(t('dup_phone')); return; }

    if (S.wl && S.wl.demo) { showErr(S.lang === 'th' ? 'นี่คือลิงก์ตัวอย่าง จองจริงไม่ได้' : 'This is a sample link. Booking is disabled.'); return; }
    if (EV.needed() && !EV.verified()) { $('cEmail').classList.add('bad'); showErr(t('ev_need')); $('ev').scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
    if (PV.needed() && !PV.verified()) { $('cPhone').classList.add('bad'); showErr(t('pv_need')); $('pv').scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }

    const payload = {
      subevent: S.slot.id,
      qty: S.qty,
      given: $('cGiven').value.trim(),
      family: $('cFamily').value.trim(),
      org: $('cOrg').value.trim(),
      email: $('cEmail').value.trim(),
      phone: $('cPhone').value.trim(),
      phone_token: PV.verified() ? PV.token() : '',
      email_token: EV.verified() ? EV.token() : '',
      wl_token: S.wl ? S.wl.token : '',
      idem: idemKey(),
      ref: REF,
      news: $('cNews').checked,
      website: f.website.value,
      turnstile: (f.querySelector('[name="cf-turnstile-response"]') || {}).value || '',
      lang: S.lang,
      attendees: [...f.querySelectorAll('.att')].map((r, i) => {
        const v = k => { const el = r.querySelector(`[name=${k}]`); return el ? el.value.trim() : ''; };
        const same = i === 0 && r.querySelector('[name=same]').checked;
        const sameName = same;
        return {
          given: sameName ? $('cGiven').value.trim() : v('given'),
          family: sameName ? $('cFamily').value.trim() : v('family'),
          org: r.querySelector('[name=sameorg]').checked ? $('cOrg').value.trim() : v('org'),
          phone: same ? $('cPhone').value.trim() : v('phone'),
          email: same ? $('cEmail').value.trim() : v('email'),
          news: !same && r.querySelector('[name=news]').checked,
        };
      }),
    };

    const btn = $('submitBtn');
    btn.disabled = true; btn.classList.add('busy');
    try {
      const r = await fetch('api/book.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
      });
      const j = await r.json().catch(() => ({}));
      if (r.ok && j.ok) {
        $('orderCode').textContent = j.code;
        $('orderLink').href = j.url || '#';
        $('orderLink').hidden = !j.url;
        $('calLinks').hidden = !j.ics;
        if (j.ics) { $('icsLink').href = j.ics + '&lang=' + S.lang; $('gcalLink').href = j.gcal; }
        $('doneText').textContent = t('doneText')(t('people')(S.qty), `${fmtDate(S.date)} ${t('time')(S.slot.start, S.slot.end || '')}`);
        S.done = { pending: !!j.pending, email: j.email, by: j.confirm_by };
        if (j.pending) $('calLinks').hidden = true;   // ยังไม่ยืนยัน = ยังไม่มีตั๋ว
        renderDone();
        EV.reset(); PV.reset();
        if (S.wl) { S.wl = null; $('wlInvite').hidden = true; history.replaceState(null, '', location.pathname); }
        goStep(4);
        clearDraft();
        try { sessionStorage.removeItem('visit-idem'); } catch (_) {}
        loadSlots(true);   // รีเฟรชที่ว่างเบื้องหลัง
      } else {
        if (window.turnstile) window.turnstile.reset();   // token ใช้ได้ครั้งเดียว
        showErr(localizeErr(j));
        if (j.fields) Object.keys(j.fields).forEach(k => { const i = f.querySelector(`[name=${k}]`); if (i) i.classList.add('bad'); });
        if (j.error === 'phone_unverified') PV.reset();
        if (j.error === 'email_unverified') EV.reset();
        if (j.error === 'slot_full' || j.error === 'slot_closed' || j.error === 'not_enough') { await loadSlots(true); S.slot = null; goStep(2); renderSlots(); }
      }
    } catch (_) {
      showErr(t('netErr'));
    } finally {
      btn.disabled = false; btn.classList.remove('busy');
    }
  }

  function localizeErr(j) {
    if (S.lang === 'th') return (j.fields && Object.values(j.fields)[0]) || j.message || t('netErr');
    const map = {
      slot_full: 'This session just filled up. Please choose another one.',
      not_enough: 'Not enough places left in this session for this many visitors. Please reduce the number or choose another session.',
      slot_closed: 'This session is no longer open for booking.',
      rate_limited: 'Too many requests. Please wait a moment and try again.',
      validation: 'Please check the highlighted fields.',
      upstream: 'The booking system is temporarily unavailable. Please try again shortly.',
      bot_check: 'Please complete the verification and try again.',
      phone_unverified: 'Please verify your phone number with the SMS code.',
      email_unverified: 'Please verify your email with the code we sent you.',
    };
    return map[j.error] || 'The booking could not be completed. Please try again.';
  }

  // ---------- data ----------
  async function loadSlots(silent) {
    const cal = $('cal');
    if (!silent) cal.setAttribute('aria-busy', 'true');
    try {
      // ครั้งแรกใช้ผลที่ <link rel=preload> ดึงไว้ตั้งแต่เปิดหน้า (เซิร์ฟเวอร์ส่ง no-store อยู่แล้ว)
      const r = await fetch('api/slots.php', S.slotsLoaded ? { cache: 'no-store' } : {});
      S.slotsLoaded = true;
      const j = await r.json();
      if (!j.ok) throw new Error(j.message || 'err');
      S.slots = j.slots;
      S.maxPerOrder = j.max_per_order || 10;
      S.pastByDate = new Map();
      (j.past || []).forEach(p => { if (!S.pastByDate.has(p.date)) S.pastByDate.set(p.date, []); S.pastByDate.get(p.date).push({ ...p, id: 'p' + p.date + p.start, ended: true, available: false }); });
      $('testbar').hidden = !j.test_mode;
      $('testFill').hidden = !j.test_mode;
      S.smsVerify = !!j.sms_verify; S.emailVerify = !!j.email_verify; S.waitlistOn = !!j.waitlist; renderPv();
      S.byDate = new Map();
      j.slots.forEach(s => { if (!S.byDate.has(s.date)) S.byDate.set(s.date, []); S.byDate.get(s.date).push(s); });

      const now = new Date();
      const months = [...S.byDate.keys()].map(k => { const [y, m] = k.split('-').map(Number); return y * 12 + (m - 1); });
      S.minMonth = now.getFullYear() * 12 + now.getMonth();
      S.maxMonth = months.length ? Math.max(...months, S.minMonth) : S.minMonth;
      if (!S.view) {
        // เปิดที่เดือนแรกที่มีรอบว่าง
        const firstOpen = j.slots.filter(s => slotState(s) !== 'full').map(s => s.date).sort()[0];
        const base = firstOpen ? new Date(...firstOpen.split('-').map((v, i) => Number(v) - (i === 1 ? 1 : 0))) : now;
        S.view = { y: base.getFullYear(), m: base.getMonth() };
      }
      $('calNote').hidden = j.slots.length > 0;
      $('calNote').textContent = j.slots.length ? '' : t('noSlots');
      if (S.slot) { const fresh = j.slots.find(s => s.id === S.slot.id); S.slot = fresh || null; }
      renderCalendar();
      if (!S.ready) { restoreDraft(); applyInvite(); }
      else if (S.date && !$('step-2').hidden) renderSlots();
    } catch (_) {
      $('calNote').hidden = false;
      $('calNote').textContent = t('loadErr');
    } finally {
      cal.setAttribute('aria-busy', 'false');
    }
  }

  // ---------- waiting list ----------
  // ลิงก์สิทธิ์จองจากอีเมล (?wl=TOKEN): เลือกรอบ/จำนวน/ข้อมูลผู้ติดต่อให้ แล้วไปขั้นกรอกข้อมูล
  const wlToken = new URLSearchParams(location.search).get('wl');
  async function applyInvite() {
    if (!wlToken) return;
    try {
      const r = await fetch('api/waitlist.php?token=' + encodeURIComponent(wlToken) + '&lang=' + S.lang, { cache: 'no-store' });
      const j = await r.json();
      if (!j.ok) throw new Error();
      S.wl = { token: wlToken, session: j.session, qty: j.qty, demo: !!j.demo };
      const sl = S.slots.find(x => x.id === j.session) || { id: j.session, date: j.slot.date, start: j.slot.start, end: j.slot.end, available: true };
      if (!S.slots.includes(sl)) { S.slots.push(sl); if (!S.byDate.has(sl.date)) S.byDate.set(sl.date, []); S.byDate.get(sl.date).push(sl); }
      S.date = sl.date; S.slot = sl; S.qty = j.qty;
      const [y, m] = sl.date.split('-').map(Number); S.view = { y, m: m - 1 };
      [['cGiven', j.given], ['cFamily', j.family], ['cEmail', j.email], ['cPhone', (j.phone || '').replace(/^\+66/, '0')]].forEach(([id, v]) => { if (!$(id).value) $(id).value = v || ''; });
      renderCalendar(); renderSlots(); renderSummary(); renderAttendees(false); renderPv();
      goStep(3);
      $('wlInvite').textContent = t('wl_invite')(fmtDeadline(j.expires_at)) + (j.demo ? (S.lang === 'th' ? ' (ลิงก์ตัวอย่าง จองจริงไม่ได้)' : ' (sample link, booking disabled)') : '');
      $('wlInvite').hidden = false;
      document.querySelector('#step-3 .summary').before($('wlInvite'));
    } catch (_) {
      $('calNote').hidden = false; $('calNote').textContent = t('wl_bad');
    }
  }
  $('wlForm').addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, err = $('wlErr'); err.hidden = true;
    let bad = false;
    f.querySelectorAll('input[required]').forEach(i => { const ok = i.value.trim() !== '' && (i.type !== 'email' || /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(i.value)); i.classList.toggle('bad', !ok); bad = bad || !ok; });
    if (bad) { err.textContent = S.lang === 'th' ? 'กรุณากรอกข้อมูลให้ครบถ้วน' : 'Please complete all fields'; err.hidden = false; return; }
    const b = $('wlBtn'); b.disabled = true; b.classList.add('busy');
    try {
      const r = await fetch('api/waitlist.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ session: S.wlSlot.id, given: f.given.value, family: f.family.value, email: f.email.value, phone: f.phone.value, qty: Number(f.qty.value), lang: S.lang, website: f.website.value, turnstile: (f.querySelector('[name="cf-turnstile-response"]') || {}).value || '' }) });
      const j = await r.json().catch(() => ({}));
      if (window.turnstile) try { window.turnstile.reset(f.querySelector('.cf-turnstile')); } catch (_) {}   // token ใช้ได้ครั้งเดียว
      if (r.ok && j.ok) { f.hidden = true; $('wlOk').textContent = t('wl_ok'); $('wlOk').hidden = false; }
      else { err.textContent = S.lang === 'th' ? (j.message || t('netErr')) : (j.error === 'rate_limited' ? 'Too many requests. Please wait a moment and try again.' : j.error === 'not_full' ? 'This session can be booked normally, or is closed.' : j.error === 'bot_check' ? 'Please complete the verification and try again.' : 'Please check your details and try again.'); err.hidden = false; if (j.error === 'not_full') { await loadSlots(true); S.wlSlot = null; renderSlots(); } }
    } catch (_) { err.textContent = t('netErr'); err.hidden = false; }
    finally { b.disabled = false; b.classList.remove('busy'); }
  });

  // iOS: แตะ 2 ครั้งบนข้อความ/พื้นที่ว่าง (ไม่ใช่ปุ่ม/ช่องกรอก) = ซูม แม้ตั้ง touch-action แล้ว → กันไว้ (pinch ยังใช้ได้ ยกเว้นหน้าสแกน)
  { let lastT = 0; document.addEventListener('touchend', e => { const now = Date.now(); if (now - lastT < 350 && !e.target.closest('a,button,input,select,textarea,label,summary,[role=button],[data-id],.slot,.chip')) e.preventDefault(); lastT = now; }, { passive: false }); }
  // ---------- events ----------
  document.querySelectorAll('.lang button').forEach(b => b.addEventListener('click', () => {
    S.lang = b.dataset.lang;
    try { localStorage.setItem('lang', S.lang); } catch (_) {}
    applyLang();
  }));
  $('prevM').addEventListener('click', () => shiftMonth(-1));
  $('todayBtn').addEventListener('click', () => { const n = new Date(); S.view = { y: n.getFullYear(), m: n.getMonth() }; renderCalendar(); });
  $('nextM').addEventListener('click', () => shiftMonth(1));
  $('days').addEventListener('click', e => {
    const b = e.target.closest('.day[data-date]');
    if (!b || b.disabled) return;
    S.date = b.dataset.date;
    S.slot = null;
    renderCalendar();
    renderSlots();
    goStep(2);
  });
  $('slots').addEventListener('click', e => {
    const b = e.target.closest('.slot[data-id]');
    if (!b || b.disabled) return;
    selectSlot(Number(b.dataset.id));
  });
  $('qMinus').addEventListener('click', () => { S.qty--; updateQty(); });
  $('qPlus').addEventListener('click', () => { S.qty++; updateQty(); });
  $('qty').addEventListener('change', () => { S.qty = parseInt($('qty').value, 10) || 1; updateQty(); });
  $('toForm').addEventListener('click', () => { renderSummary(); renderAttendees(false); goStep(3); });
  document.querySelectorAll('[data-back]').forEach(b => b.addEventListener('click', () => goStep(Number(b.dataset.back))));
  const stepsBar = document.querySelector('.steps');
  const stepBack = e => {
    const li = e.target.closest('li.back');
    if (!li) return;
    if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    goStep(Number(li.dataset.step));
  };
  stepsBar.addEventListener('click', stepBack);
  stepsBar.addEventListener('keydown', stepBack);
  // โหมดทดลอง: กรอกข้อมูลสมมติให้ครบทุกช่องในคลิกเดียว (อีเมล @example.com — อีเมลของการจองทดสอบเข้ากล่องทดสอบอยู่แล้ว)
  $('testFill').addEventListener('click', () => {
    const G = ['สมชาย', 'สมหญิง', 'Jane', 'Anan', 'Malee', 'Peter', 'Kanya', 'Wichai', 'Lisa', 'Niran'];
    const F = ['ใจดี', 'รักงาม', 'Doe', 'Srisuk', 'Wongsa', 'Smith', 'Chaiyo', 'Boonmee', 'Tan', 'Kaewdee'];
    const rnd = n => String(Math.floor(Math.random() * 10 ** n)).padStart(n, '0');
    const set = (el, v) => { if (el) { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); } };
    set($('cGiven'), 'ทดสอบ'); set($('cFamily'), 'ระบบ' + rnd(2));
    set($('cEmail'), `test+${rnd(4)}@example.com`); set($('cPhone'), '08' + rnd(8)); set($('cOrg'), 'Test Org');
    document.querySelectorAll('#attendees .att').forEach((r, i) => {
      const same = r.querySelector('[name=same]'); if (same && same.checked) same.click();
      set(r.querySelector('[name=given]'), G[i % G.length]); set(r.querySelector('[name=family]'), F[i % F.length]);
      set(r.querySelector('[name=phone]'), i % 3 === 2 ? '' : '08' + rnd(8));
      set(r.querySelector('[name=email]'), i % 2 ? '' : `visitor${i + 1}+${rnd(3)}@example.com`);
    });
    const ag = document.querySelector('[name=agree]'); if (ag) ag.checked = true;
    saveDraft();
  });
  // ส่งลิงก์การจองอีกครั้ง (แทนปุ่ม Resend order link ของ Pretix)
  $('resendForm').addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, msg = $('rsMsg'), btn = f.querySelector('button');
    const em = f.email.value.trim();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(em)) { f.email.classList.add('bad'); return; }
    f.email.classList.remove('bad'); btn.disabled = true;
    try {
      const r = await fetch('api/resend.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: em, lang: S.lang }) });
      const j = await r.json().catch(() => ({}));
      msg.textContent = r.ok && j.ok ? t('rs_ok') : (S.lang === 'th' ? (j.message || t('netErr')) : (j.error === 'rate_limited' ? 'Too many requests. Please wait a moment and try again.' : t('netErr')));
    } catch (_) { msg.textContent = t('netErr'); }
    msg.hidden = false; btn.disabled = false;
  });
  $('bookForm').addEventListener('submit', submit);
  $('bookForm').addEventListener('input', saveDraft);
  $('bookForm').addEventListener('change', saveDraft);
  // เริ่มใหม่: ล้างการจองที่ค้าง (draft) แล้วกลับไปปฏิทิน — ใช้กับปุ่ม "จองเพิ่ม" และโลโก้
  function startOver() {
    S.slot = null; S.date = null; S.qty = 1;
    $('bookForm').reset(); renderAttendees(true); clearDraft();
    renderCalendar(); goStep(1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  $('again').addEventListener('click', startOver);
  document.querySelector('.brand').addEventListener('click', e => { e.preventDefault(); startOver(); });

  // ---------- init ----------
  applyLang();
  loadSlots(false);
})();
