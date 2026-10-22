(() => {
  'use strict';
  // หลังบ้าน: รอบ + สถิติ, รายชื่อต่อรอบ (เช็คอินด้วยมือ/ส่งออก), ค้นหาออเดอร์, ตั้งค่า/แอดมิน
  const $ = id => document.getElementById(id);
  const view = $('view');
  // ตัวอย่างอีเมล: ลิงก์/ปุ่มเปิดแท็บใหม่ (ไม่เปิดในกรอบที่รันสคริปต์ไม่ได้) — ใส่ <base> ใน <head> ไม่ให้หลุดโหมดมาตรฐาน
  const mailDoc = h => /<head[^>]*>/i.test(h) ? h.replace(/<head[^>]*>/i, m => m + '<base target="_blank">') : '<base target="_blank">' + h;
  // ไฟล์แนบของอีเมลตัวอย่าง [{name, b64}] → ปุ่ม 📎 กดดูไฟล์ (แอปหน้าจอโฮม = FileView, เบราว์เซอร์ = แท็บใหม่)
  const attachHtml = files => files && files.length ? `<p class="note mail-files">แนบ: ${files.map((f, k) => `<button type="button" class="ghost sm" data-att="${k}">📎 ${R(f.name)}</button>`).join(' ')}</p>` : '';
  const bindAttach = (root, files) => root.querySelectorAll('[data-att]').forEach(b => b.onclick = () => {
    const f = files[Number(b.dataset.att)], bin = atob(f.b64), u8 = new Uint8Array(bin.length);
    for (let k = 0; k < bin.length; k++) u8[k] = bin.charCodeAt(k);
    const url = URL.createObjectURL(new Blob([u8], { type: /\.pdf$/i.test(f.name) ? 'application/pdf' : 'application/octet-stream' }));
    if (STANDALONE && window.FileView) window.FileView.open(url, { title: f.name, filename: f.name, back: 'กลับ', share: 'แชร์' });
    else window.open(url, '_blank');
  });
  let me = null;

  const PK_ICON = '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2M9 9v1M15 9v1M12 9v4h-1M9 15.5c1.7 1.3 4.3 1.3 6 0"/></svg>';
  const R = v => `<span class="raw">${esc(v)}</span>`;   // ข้อมูลผู้ใช้ — ไม่แปลภาษา
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const EN = window.ADMIN_LANG === 'en';
  const MONTHS = EN ? ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] : ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
  const DOWS = EN ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] : ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
  const yr = y => EN ? y : y + 543;
  const fmtDate = iso => { const [y, m, d] = iso.split('-').map(Number); return `${DOWS[new Date(y, m - 1, d).getDay()]} ${d} ${MONTHS[m - 1]} ${yr(y)}`; };
  const fmtTs = ts => { if (!ts) return ''; const d = new Date(ts); return `${d.getDate()} ${MONTHS[d.getMonth()]} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`; };
  const pct = (a, b) => b ? Math.round(a * 100 / b) + '%' : '–';
  const fmtTime = ts => { if (!ts) return ''; const d = new Date(ts); return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`; };

  async function api(action, data = {}) {
    const r = await fetch('../api/admin.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...data }), credentials: 'same-origin' });
    const j = await r.json().catch(() => ({ ok: false, message: 'เชื่อมต่อไม่สำเร็จ' }));
    if (r.status === 401) { me = null; showLogin(); throw new Error('auth'); }
    if (!j.ok) throw new Error(j.message || 'เกิดข้อผิดพลาด');
    return j;
  }
  // หน้าต่างยืนยัน: ask({title, text, ok, cancel, danger, input:{type,value,placeholder}, check:{label,checked}})
  // → Promise<null | {value, checked}>  (null = ยกเลิก/ปิด)
  function ask(o) {
    return new Promise(resolve => {
      const bg = document.createElement('div'); bg.className = 'ask-bg';
      const box = document.createElement('div'); box.className = 'ask'; box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true');
      const h = document.createElement('h3'); h.textContent = o.title; box.append(h);
      if (o.text) { const p = document.createElement('p'); p.textContent = o.text; box.append(p); }
      let inp = null, chk = null;
      if (o.input) { inp = document.createElement('input'); inp.className = 'ask-in'; inp.type = o.input.type || 'text'; inp.value = o.input.value ?? ''; if (o.input.placeholder) inp.placeholder = o.input.placeholder; if (o.input.min !== undefined) inp.min = o.input.min; box.append(inp); }
      let sel = null;
      if (o.select) { sel = document.createElement('select'); sel.className = 'ask-in'; o.select.options.forEach(([v, l]) => { const op = document.createElement('option'); op.value = v; op.textContent = l; sel.append(op); }); sel.value = o.select.value; box.append(sel); }
      if (o.check) { const l = document.createElement('label'); l.className = 'ask-chk'; chk = document.createElement('input'); chk.type = 'checkbox'; chk.checked = !!o.check.checked; l.append(chk, document.createTextNode(o.check.label)); box.append(l); }
      let inp2 = null;
      if (o.input2) { inp2 = document.createElement('input'); inp2.className = 'ask-in'; inp2.type = 'text'; inp2.value = o.input2.value ?? ''; if (o.input2.placeholder) inp2.placeholder = o.input2.placeholder; box.append(inp2); }
      let chk2 = null;
      if (o.check2) { const l = document.createElement('label'); l.className = 'ask-chk'; chk2 = document.createElement('input'); chk2.type = 'checkbox'; chk2.checked = !!o.check2.checked; l.append(chk2, document.createTextNode(o.check2.label)); box.append(l); }
      if (o.preview) {   // ดูอีเมลก่อนส่ง: o.preview(input value) → api(..., preview: 1)
        const pv = document.createElement('button'); pv.type = 'button'; pv.className = 'linkbtn ask-pv'; pv.textContent = '✉︎ ดูอีเมลก่อนส่ง';
        pv.onclick = async () => { try { const r = await o.preview(inp ? inp.value.trim() : null, { checked: chk ? chk.checked : null, checked2: chk2 ? chk2.checked : null }); showMailPreview(r.preview || []); } catch (x) { toast(x.message, true); } };
        box.append(pv);
      }
      const btns = document.createElement('div'); btns.className = 'ask-btns';
      const no = document.createElement('button'); no.type = 'button'; no.textContent = o.cancel || 'ยกเลิก';
      const yes = document.createElement('button'); yes.type = 'button'; yes.className = 'ok' + (o.danger ? ' danger' : ''); yes.textContent = o.ok || 'ยืนยัน';
      btns.append(no, yes); box.append(btns); bg.append(box); document.body.append(bg);
      const done = v => { bg.remove(); document.removeEventListener('keydown', key); resolve(v); };
      const key = e => { if (e.key === 'Escape') done(null); if (e.key === 'Enter' && inp && document.activeElement === inp) yes.click(); };
      document.addEventListener('keydown', key);
      no.onclick = () => done(null);
      bg.onclick = e => { if (e.target === bg) done(null); };
      yes.onclick = () => {
        if (inp && inp.required !== false && o.input.required && !inp.value.trim()) { inp.focus(); return; }
        done({ value: inp ? inp.value.trim() : null, value2: inp2 ? inp2.value.trim() : null, checked: chk ? chk.checked : null, checked2: chk2 ? chk2.checked : null, select: sel ? sel.value : null });
      };
      (inp || no).focus();
      if (inp && inp.value && (inp.type === 'text' || inp.type === 'email')) inp.select();   // ค่าเดิมเลือกไว้ พิมพ์ทับได้ทันที
    });
  }

  const AUTH = window.AUTH || {};
  const PK = AUTH.passkey !== false && window.Passkey && window.Passkey.supported ? window.Passkey.create(api) : null;
  const toast = (msg, bad) => {
    const t = document.createElement('div'); t.className = 'toast' + (bad ? ' bad' : ''); t.textContent = msg;
    document.body.append(t); setTimeout(() => t.remove(), 2600);
  };

  // ---------- login ----------
  function showLogin() {
    $('nav').hidden = true;
    view.innerHTML = `<section class="panel narrow"><h2>เข้าสู่ระบบ</h2>
      ${AUTH.sso ? `<a class="primary sso-btn" href="oidc.php?start=1">${esc(AUTH.sso[EN ? 'en' : 'th'])}</a><div class="pk-or"><span>หรือ</span></div>` : ''}
      <form id="lf"><label><span>อีเมล</span><input type="email" name="email" required autocomplete="email"></label>
      <div id="codeRow" hidden><label><span>รหัส 6 หลักจากอีเมล <em>(หรือกดปุ่ม "เข้าสู่ระบบ" ในอีเมล)</em></span><input class="otp" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*"></label></div>
      <p class="err" id="lerr" hidden></p>
      <button class="primary" id="lbtn">ส่งรหัสเข้าอีเมล</button>
      <button type="button" class="linkbtn havecode" id="haveCode">มีรหัสจากผู้ดูแลแล้ว</button></form>
      ${PK ? `<div class="pk-or"><span>หรือ</span></div><button type="button" class="ghost pk-btn" id="pkLogin">${PK_ICON}เข้าสู่ระบบด้วย Face ID / ลายนิ้วมือ</button>` : ''}</section>`;
    if (PK) {
      PK.prepLogin();
      $('pkLogin').onclick = async () => {
        const err = $('lerr'); err.hidden = true;
        try { const j = await PK.login(); me = j.me; route(); }
        catch (x) { const t = x.message === 'auth' ? null : window.Passkey.errorText(x); if (t) { err.textContent = t; err.hidden = false; } }
      };
    }
    ssoError($('lerr'));
    let step = 1;
    $('haveCode').onclick = () => { step = 2; $('codeRow').hidden = false; $('lbtn').textContent = 'เข้าสู่ระบบ'; $('haveCode').hidden = true; ($('lf').email.value ? $('lf').code : $('lf').email).focus(); };
    $('lf').addEventListener('submit', async e => {
      e.preventDefault();
      const f = e.target, err = $('lerr'); err.hidden = true;
      try {
        if (step === 1) {
          await api('login_request', { email: f.email.value, lang: EN ? 'en' : 'th' });
          step = 2; $('codeRow').hidden = false; $('lbtn').textContent = 'เข้าสู่ระบบ'; f.code.focus();
          toast('ถ้าอีเมลนี้มีสิทธิ์ ระบบส่งรหัสและลิงก์เข้าสู่ระบบไปแล้ว');
        } else {
          const j = await api('login_verify', { email: f.email.value, code: f.code.value });
          me = j.me; route(); offerPasskey();
        }
      } catch (x) { if (x.message !== 'auth') { err.textContent = x.message; err.hidden = false; } }
    });
  }

  // กลับจาก SSO พร้อม ?sso=denied|fail → แสดงข้อความแล้วลบออกจาก URL
  function ssoError(el) {
    const q = new URLSearchParams(location.search).get('sso');
    if (!q) return;
    history.replaceState(null, '', location.pathname + location.hash);
    el.textContent = q === 'denied' ? 'บัญชีนี้ไม่มีสิทธิ์เข้าหลังบ้าน' : 'เข้าสู่ระบบไม่สำเร็จ กรุณาลองใหม่';
    el.hidden = false;
  }

  // เหตุผลที่ยกเลิก (orders.canceled_by)
  function cancelReason(by) {
    if (!by) return 'ยกเลิก';
    if (by === 'unconfirmed') return 'ระบบยกเลิก: ไม่ได้ยืนยันอีเมลภายในเวลา';
    if (by === 'customer') return 'ผู้จองยกเลิกเอง';
    if (by === 'attendee') return 'ผู้เข้าชมยกเลิกตั๋วของตัวเอง';
    if (by.startsWith('admin:')) return 'แอดมินยกเลิก (' + R(by.slice(6)) + ')';
    return R(by);
  }

  // ---------- รอบ ----------
  // ---------- รอบ: ปฏิทิน (ค่าเริ่มต้น) / รายการ ----------
  const MONTHS_FULL = EN ? ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'] : ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
  const pad2 = n => String(n).padStart(2, '0');
  const todayYmd = () => { const d = new Date(); return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`; };
  // สถานะของรอบ (สีชิป/แถว): past | off | full | few | open
  function sState(s) {
    const now = new Date();
    if (new Date(s.starts_at) < now) return 'past';
    if (!s.active || s.auto_closed) return 'off';
    if (s.capacity_eff !== null && s.booked >= s.capacity_eff) return 'full';
    if (s.capacity_eff !== null && s.capacity_eff - s.booked <= 5) return 'few';
    return 'open';
  }
  // ไม่แสดงความจุ (ผู้ใช้กำหนด) — รอบที่จะถึง = จำนวนคนจอง, ผ่านไปแล้ว = มาจริง/จอง
  const IC_P = '<svg class="ci" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c.8-4.4 4-6.5 8-6.5s7.2 2.1 8 6.5"/></svg>';
  const IC_OK = '<svg class="ci" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12.5l5 5L20 6.5"/></svg>';
  // ชิปในปฏิทิน: ไอคอน + ตัวเลข (สั้นพอทั้งไทย/อังกฤษบนมือถือ) — ผ่านไปแล้ว = มาจริง/จอง
  const sLabel = s => sState(s) === 'past' ? `${IC_OK}${s.checked}/${s.booked}` : `${IC_P}${s.booked}`;

  // สรุปทั้งปี — กดแต่ละช่องเพื่อดูรายชื่อ (ไม่รวมการจองทดสอบ)
  const yearKpis = st => `<h3 class="mhead">ทั้งปี ${yr(Number(st.year))}</h3><div class="kpis kpis-link">
    <a href="#/people/booked/${st.year}"><b>${st.year_tickets}</b><small>จอง (ไม่นับที่ยกเลิก)</small></a>
    <a href="#/people/checked/${st.year}"><b>${st.year_checked}</b><small>มาจริง</small></a>
    <a href="#/people/noshow/${st.year}"><b>${st.year_noshow}</b><small>จองแต่ไม่มา</small></a>
    <a href="#/people/canceled/${st.year}"><b>${st.year_canceled}</b><small>ยกเลิก</small></a></div>
    ${me.role === 'admin' ? `<p class="morelink"><a href="#/stats/${st.year}">สถิติเพิ่มเติม ›</a></p>` : ''}`;

  // ---------- แก้ข้อความ (หน้าจอง / การจองของฉัน / อีเมล) — เก็บเฉพาะคำที่แก้ ----------
  const TEXT_TABS = [['I18N_BOOKING', 'หน้าจอง', '../assets/i18n-booking.js'], ['I18N_MANAGE', 'หน้าการจองของฉัน', '../assets/i18n-manage.js'], ['mail', 'อีเมล'], ['noshow', 'อีเมลแจ้งไม่มา']];
  const loadDict = (g, src) => window[g] ? Promise.resolve(window[g]) : new Promise((ok, no) => { const s = document.createElement('script'); s.src = src + '?v=' + Date.now(); s.onload = () => ok(window[g]); s.onerror = no; document.head.append(s); });
  async function showTexts(scope) {
    const tab = TEXT_TABS.find(t => t[0] === scope) || TEXT_TABS[0];
    const j = await api('texts');
    let defs;
    if (tab[2]) { const d = await loadDict(tab[0], tab[2]); defs = { th: {}, en: {} }; ['th', 'en'].forEach(l => Object.entries(d[l]).forEach(([k, v]) => { if (typeof v === 'string') defs[l][k] = v; })); }
    else defs = j.defaults[tab[0]];
    const ov = (j.overrides && j.overrides[tab[0]]) || {};
    const keys = [...new Set([...Object.keys(defs.th || {}), ...Object.keys(defs.en || {})])].filter(k => !['museum', 'brand'].includes(k));   // ชื่อสถานที่แก้ที่ "ข้อมูลสถานที่"
    view.innerHTML = `<p class="back"><a href="#/settings" data-back>‹ กลับ</a></p><h2>แก้ข้อความ</h2>
      <div class="tabs txtabs">${TEXT_TABS.map(t => `<a href="#/texts/${t[0]}" class="${t[0] === tab[0] ? 'on' : ''}">${t[1]}</a>`).join('')}</div>
      <p class="note">แก้ในช่องแล้วกด "บันทึก" · ช่องที่แก้แล้วมีป้าย "แก้แล้ว" · ${tab[2] ? `<a href="../${tab[0] === 'I18N_BOOKING' ? '' : 'manage.php?o=PREVW&s=preview'}" target="_blank" rel="noopener">เปิดหน้าเว็บดู ›</a>` : '<a href="#/mail">ดูตัวอย่างอีเมลในหน้าอีเมล ›</a>'}
        · ข้อความที่มี %s หรือ %d ต้องคงไว้ (ระบบใส่ค่าจริงแทน) · ตัวหนา &lt;b&gt;…&lt;/b&gt; และลิงก์ใช้ได้เฉพาะข้อความที่เดิมมี</p>
      <input type="search" id="txQ" class="raw" placeholder="ค้นหาข้อความ…" style="width:100%;margin:8px 0 12px">
      <div id="txList">${keys.map(k => `<section class="panel txrow" data-k="${esc(k)}"><div class="txhead"><span class="mono">${esc(k)}</span>${['th', 'en'].some(l => ov[l] && ov[l][k]) ? '<span class="tag few">แก้แล้ว</span>' : ''}</div>
        ${['th', 'en'].map(l => defs[l] && defs[l][k] !== undefined ? `<label class="txl"><span>${l === 'th' ? 'ไทย' : 'English'}</span><textarea class="raw" rows="${Math.min(6, Math.ceil((defs[l][k] || '').length / 60) + 1)}" data-l="${l}">${esc((ov[l] && ov[l][k]) || defs[l][k])}</textarea>
          <small class="note raw txdef" ${(ov[l] && ov[l][k]) ? '' : 'hidden'}>เดิม: ${esc(defs[l][k])}</small></label>` : '').join('')}
        <div class="actions"><button type="button" class="linkbtn" data-reset>กลับเป็นข้อความเดิม</button><button type="button" class="primary sm" data-save>บันทึก</button></div></section>`).join('')}</div>`;
    $('txQ').oninput = () => { const q = $('txQ').value.trim().toLowerCase(); view.querySelectorAll('.txrow').forEach(r => { r.hidden = q && !r.textContent.toLowerCase().includes(q) && ![...r.querySelectorAll('textarea')].some(t => t.value.toLowerCase().includes(q)); }); };
    view.querySelectorAll('.txrow').forEach(r => {
      const k = r.dataset.k;
      const saveAll = async reset => {
        try {
          for (const ta of r.querySelectorAll('textarea')) {
            const l = ta.dataset.l, d = defs[l][k];
            let v = reset ? '' : ta.value.trim();
            if (!d.includes('<')) v = v.replace(/<[^>]*>/g, '');   // ข้อความเดิมไม่มี HTML = แสดงแบบตัวอักษร — ตัดแท็กออก
            const cur = (ov[l] && ov[l][k]) || '';
            const want = v === d.trim() ? '' : v;   // เหมือนข้อความเดิม = ไม่เก็บ
            if (want === cur) continue;
            const x = await api('texts', { op: 'save', scope: tab[0], lang: l, key: k, value: want });
            (ov[l] = ov[l] || {})[k] = x.value;
          }
          toast(reset ? 'กลับเป็นข้อความเดิมแล้ว' : 'บันทึกแล้ว'); showTexts(tab[0]);
        } catch (x) { toast(x.message, true); }
      };
      r.querySelector('[data-save]').onclick = () => saveAll(false);
      r.querySelector('[data-reset]').onclick = async () => { if (await ask({ title: 'กลับเป็นข้อความเดิม?', text: k, ok: 'กลับเป็นข้อความเดิม' })) saveAll(true); };
    });
  }

  // ---------- พนักงานเพิ่มการจอง (โทรมาจอง / walk-in) ----------
  async function showAddBooking(sid) {
    const j = await api('session', { id: sid });
    const s = j.session;
    const today = s.date === todayYmd();
    view.innerHTML = `<p class="back"><a href="#/session/${sid}" data-back>‹ กลับ</a></p>
      <h2>เพิ่มการจอง</h2><p class="note">${fmtDate(s.date)} · ${s.start}${s.end ? '–' + s.end : ''} · จองแล้ว ${s.booked}${s.capacity_eff !== null ? ' จาก ' + s.capacity_eff : ''} คน</p>
      <form class="panel" id="abForm">
        <div class="grid2"><label><span>ชื่อ</span><input name="given" type="text" required maxlength="100"></label><label><span>นามสกุล</span><input name="family" type="text" maxlength="100"></label></div>
        <div class="grid2"><label><span>เบอร์โทร</span><input name="phone" type="tel" inputmode="tel" placeholder="08x-xxx-xxxx"></label><label><span>อีเมล</span><input name="email" type="email" inputmode="email"></label></div>
        <div class="grid2"><label><span>จำนวนคน</span><input name="qty" type="number" min="1" max="50" value="1" required></label>
          <label><span>ภาษาอีเมล</span><select name="lang"><option value="th">ไทย</option><option value="en">English</option></select></label></div>
        <div id="abNames"></div>
        <label class="chk"><input type="checkbox" name="send_email" checked> ส่งอีเมลยืนยันพร้อมตั๋ว (ถ้ากรอกอีเมล)</label>
        ${today ? '<label class="chk"><input type="checkbox" name="checkin" checked> walk-in — เช็คอินทุกคนเลย</label>' : ''}
        <p class="err" id="abErr" hidden></p>
        <div class="actions"><a class="ghost" href="#/session/${sid}">ยกเลิก</a><button class="primary">เพิ่มการจอง</button></div>
      </form>`;
    const f = $('abForm');
    const names = () => { const q = Math.min(50, Math.max(1, Number(f.qty.value) || 1)), box = $('abNames'), old = [...box.querySelectorAll('.grid2')].map(r => [r.children[0].querySelector('input').value, r.children[1].querySelector('input').value]);
      box.innerHTML = q > 1 ? `<p class="note">ชื่อผู้เข้าชมคนอื่น (ไม่บังคับ — ว่างไว้จะใช้ชื่อผู้ติดต่อ + ลำดับ)</p>` + Array.from({ length: q - 1 }, (_, i) => `<div class="grid2"><label><span>คนที่ ${i + 2} ชื่อ</span><input type="text" maxlength="100" value="${esc((old[i] || [])[0] || '')}"></label><label><span>นามสกุล</span><input type="text" maxlength="100" value="${esc((old[i] || [])[1] || '')}"></label></div>`).join('') : ''; };
    f.qty.oninput = names;
    f.onsubmit = async e => {
      e.preventDefault(); $('abErr').hidden = true;
      const body = { session: sid, given: f.given.value, family: f.family.value, phone: f.phone.value, email: f.email.value, qty: Number(f.qty.value), lang: f.lang.value,
        send_email: f.send_email.checked, checkin: !!(f.checkin && f.checkin.checked), walkin: !!(f.checkin && f.checkin.checked),
        names: [null, ...[...$('abNames').querySelectorAll('.grid2')].map(r => ({ given: r.children[0].querySelector('input').value, family: r.children[1].querySelector('input').value }))] };
      const btn = f.querySelector('button.primary'); btn.disabled = true;
      try {
        let r;
        try { r = await api('order_create', body); }
        catch (x) {
          if (!/เกินความจุ/.test(x.message)) throw x;
          if (!await ask({ title: 'เกินความจุ', text: x.message + '\nเพิ่มต่อไหม?', ok: 'เพิ่มต่อ' })) return;
          r = await api('order_create', { ...body, force: 1 });
        }
        toast('เพิ่มการจอง ' + r.code + ' แล้ว'); location.hash = '#/order/' + r.code;
      } catch (x) { $('abErr').textContent = x.message; $('abErr').hidden = false; }
      finally { btn.disabled = false; }
    };
  }

  // ---------- สถิติละเอียด (ผู้ดูแล) ----------
  const CHAN = { line: 'LINE', facebook: 'Facebook', instagram: 'Instagram', google: 'Google', website: 'เว็บไซต์', email: 'อีเมล/จดหมายข่าว', qr: 'QR', direct: 'เข้าตรง / ไม่ทราบ', other: 'อื่น ๆ', staff: 'พนักงานเพิ่มให้', pretix: 'Pretix (ระบบเดิม)' };
  const bars = (rows, unit) => { const max = Math.max(1, ...rows.map(r => r[1])); const tot = rows.reduce((a, r) => a + r[1], 0) || 1;
    return `<div class="sbars">${rows.map(([l, n]) => `<div class="sbar"><span class="sbl">${l}</span><span class="sbt"><i style="width:${Math.round(n / max * 100)}%"></i></span><span class="sbn">${n}${unit ? ' ' + unit : ''} <small>${Math.round(n / tot * 100)}%</small></span></div>`).join('')}</div>`; };
  async function showStats(year) {
    const j = await api('stats_detail', { year });
    const months = Object.entries(j.months);
    const TH_M = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    const y = Number(j.year);
    view.innerHTML = `<p class="back"><a href="#/sessions" data-back>‹ กลับ</a></p>
      <h2>สถิติปี ${yr(y)} <small class="note"><a href="#/stats/${y - 1}">‹ ${yr(y - 1)}</a> · <a href="#/stats/${y + 1}">${yr(y + 1)} ›</a></small></h2>
      <p class="note">ไม่รวมการจองทดสอบและการจองที่ยกเลิก · ${j.orders} การจอง · ${j.people} คน · ยกเลิก ${j.canceled_orders} การจอง</p>
      <section class="panel"><h3>มาจากช่องทางไหน <small class="note">(จำนวนคน)</small></h3>
        ${Object.keys(j.channels).length ? bars(Object.entries(j.channels).map(([k, n]) => [CHAN[k] || R(k), n]), 'คน') : '<p class="note">ยังไม่มีข้อมูล</p>'}
        <p class="note">ช่องทางนับจากลิงก์ที่ติดป้าย (หน้า "แชร์หน้าจอง") หรือเว็บที่ลิงก์มา</p></section>
      <section class="panel"><h3>มาจริง / ไม่มา รายเดือน</h3>
        ${months.length ? `<table class="mtbl"><thead><tr><th>เดือน</th><th>จอง</th><th>มาจริง</th><th>ไม่มา</th></tr></thead><tbody>${months.map(([m, v]) => `<tr><td>${TH_M[Number(m.slice(5)) - 1]} ${yr(Number(m.slice(0, 4)))}</td><td>${v.booked}</td><td>${v.came}</td><td>${v.booked - v.came} <small class="note">(${Math.round((v.booked - v.came) / Math.max(1, v.booked) * 100)}%)</small></td></tr>`).join('')}</tbody></table>` : '<p class="note">ยังไม่มีรอบที่ผ่านไป</p>'}</section>
      <section class="panel"><h3>จองล่วงหน้ากี่วัน <small class="note">(จำนวนการจอง)</small></h3>${bars(Object.entries(j.lead).map(([k, n]) => [k + ' วัน', n]), '')}</section>
      <section class="panel"><h3>จำนวนคนต่อการจอง</h3>${bars(Object.entries(j.size).map(([k, n]) => [k + ' คน', n]), '')}</section>
      <section class="panel"><h3>ภาษาที่ใช้จอง <small class="note">(จำนวนคน)</small></h3>${bars([['ไทย', j.lang.th], ['อังกฤษ', j.lang.en]], 'คน')}
        <p class="note">ระบุหน่วยงาน ${j.org} จาก ${j.orders} การจอง</p></section>`;
  }

  // ---------- รายชื่อตามประเภท (จาก KPI) ----------
  const PEOPLE_T = { booked: 'จอง (ไม่นับที่ยกเลิก)', checked: 'มาจริง', noshow: 'จองแต่ไม่มา', canceled: 'ยกเลิก' };
  async function showPeople(kind, year) {
    const j = await api('people', { kind, year });
    view.innerHTML = `<p class="crumb"><a href="#/sessions">รอบ</a></p>
      <div class="shead"><div><h2>${PEOPLE_T[kind] || kind} · ${yr(Number(j.year))}</h2><p class="note">${j.people.length} คน</p></div>
        <div class="tabs">${Object.keys(PEOPLE_T).map(k => `<a href="#/people/${k}/${j.year}" class="${k === kind ? 'on' : ''}">${PEOPLE_T[k]}</a>`).join('')}</div></div>
      <input type="search" id="flt" class="filter" placeholder="ค้นชื่อ / รหัส / เบอร์ / อีเมล">
      ${peopleGroups(j.people, kind)}
      ${j.people.length ? '' : '<p class="note">ไม่มีรายชื่อ</p>'}`;
    $('flt').oninput = e => {
      const q = e.target.value.trim().toLowerCase();
      view.querySelectorAll('tbody tr').forEach(tr => { tr.hidden = q && !tr.dataset.q.includes(q); });
      view.querySelectorAll('.pg-sess').forEach(g => { g.hidden = !g.querySelector('tbody tr:not([hidden])'); });
      view.querySelectorAll('.pg-month').forEach(g => { g.hidden = !g.querySelector('.pg-sess:not([hidden])'); });
    };
  }

  // จัดกลุ่มรายชื่อ: เดือน → รอบ (ใหม่ → เก่า) ให้ย้อนดูง่าย
  function peopleGroups(people, kind) {
    const months = [];
    for (const t of people) {
      const ym = t.slot.date.slice(0, 7);
      let m = months[months.length - 1];
      if (!m || m.ym !== ym) months.push(m = { ym, sess: [] });
      let g = m.sess[m.sess.length - 1];
      if (!g || g.id !== t.session_id) m.sess.push(g = { id: t.session_id, slot: t.slot, rows: [] });
      g.rows.push(t);
    }
    const row = t => `<tr data-q="${esc((t.given + ' ' + t.family + ' ' + t.code + ' ' + (t.phone || '') + ' ' + (t.email || '') + ' ' + t.contact + ' ' + (t.contact_phone || '') + ' ' + (t.org || '')).toLowerCase())}">
        <td class="c-who"><b>${R(t.given)} ${R(t.family)}</b><br><a class="mono" href="#/order/${t.code}">${t.code}-${t.n}</a>${kind === 'person' ? '' : histBadge(t.hist, t)}</td>
        <td class="c-contact">${subInfo(t, t.contact_phone, t.contact && t.contact !== t.given + ' ' + t.family ? t.contact : '')}</td>
        <td class="c-ci">${kind === 'canceled' ? `<small>${cancelReason(t.canceled_by)}</small>` : t.canceled ? `<span class="tag off">ยกเลิก</span>` : t.checked_in_at ? `<span class="tag open">✓ ${fmtTs(t.checked_in_at)}</span>`
          : new Date(t.slot.starts_at) < new Date() ? '<span class="tag full">ไม่มา</span>' : (t.attend_at ? '<span class="tag open">ยืนยันว่าจะมา</span>' : '<span class="tag few">ยังไม่ถึงวัน</span>')}</td></tr>`;
    return months.map(m => `<section class="pg-month"><h3 class="mhead">${MONTHS_FULL[Number(m.ym.slice(5)) - 1]} ${yr(Number(m.ym.slice(0, 4)))} · ${m.sess.reduce((a, g) => a + g.rows.length, 0)} คน</h3>
      ${m.sess.map(g => `<div class="pg-sess"><p class="pg-head"><a href="#/session/${g.id}">${fmtDate(g.slot.date)} · ${g.slot.start}${g.slot.end ? '–' + g.slot.end : ''}</a> <small>${g.rows.length} คน</small></p>
        <table class="tbl"><tbody>${g.rows.map(row).join('')}</tbody></table></div>`).join('')}</section>`).join('');
  }

  async function showSessions(arg = '') {
    const mode = arg === 'list' || arg === 'past' ? arg : 'cal';
    const isAdmin = me.role === 'admin';
    const [all, st] = await Promise.all([api('sessions', { scope: 'all' }), api('stats')]);
    const rows = all.sessions;
    const now = new Date();
    const next = rows.find(s => new Date(s.starts_at).getTime() + 2 * 3600e3 > now.getTime() && s.active);   // รอบที่กำลังจะถึง/กำลังดำเนินอยู่
    const nextDay = next ? rows.filter(s => s.date === next.date && s.active) : [];   // ทั้งวันของรอบถัดไป (เช้า/บ่าย)
    // เดือนที่แสดงในปฏิทิน (#/sessions/cal/YYYY-MM)
    const m = /^cal\/(\d{4})-(\d{2})$/.exec(arg);
    const vy = m ? Number(m[1]) : now.getFullYear(), vm = m ? Number(m[2]) - 1 : now.getMonth();

    const head = `
      ${nextDay.length ? `<a class="nextcard" href="#/day/${nextDay[0].date}/${nextDay.map(x => x.id).join(',')}">
        <span class="nc-main"><small>${nextDay[0].date === todayYmd() ? 'วันนี้' : 'วันเข้าชมถัดไป'}</small><b>${fmtDate(nextDay[0].date)}</b>
          <small>รวม ${nextDay.reduce((a, x) => a + x.booked, 0)} คนจอง${nextDay.some(x => x.checked) ? ` · เช็คอินแล้ว ${nextDay.reduce((a, x) => a + x.checked, 0)}` : ''}</small></span>
        <span class="nc-sess">${nextDay.map(x => `<span class="nc-s${new Date(x.starts_at) <= now && new Date(x.starts_at).getTime() + 2 * 3600e3 > now.getTime() ? ' live' : ''}"><span class="nc-t">${x.start}${x.end ? '–' + x.end : ''}</span><b>${x.booked} คน</b>${x.checked ? `<small>เช็คอิน ${x.checked}</small>` : ''}<span class="ghost sm" data-go="../scan/#${x.id}">สแกน</span></span>`).join('')}</span>
        <span class="nc-btns"><span class="primary sm">รายชื่อทั้งวัน</span></span></a>` : ''}
      <div class="toolbar">
        <div class="tabs"><button data-mode="cal" class="${mode === 'cal' ? 'on' : ''}">ปฏิทิน</button><button data-mode="list" class="${mode === 'list' ? 'on' : ''}">รอบที่จะถึง</button><button data-mode="past" class="${mode === 'past' ? 'on' : ''}">รอบที่ผ่านมา</button></div>
        ${isAdmin ? '<a class="linkbtn addsess" href="#/new-session">+ เพิ่มรอบพิเศษ</a>' : ''}
      </div>`;

    let body = '';
    if (mode === 'cal') {
      const first = new Date(vy, vm, 1).getDay(), dim = new Date(vy, vm + 1, 0).getDate(), today = todayYmd();
      const byDate = new Map();
      rows.forEach(s => { if (!byDate.has(s.date)) byDate.set(s.date, []); byDate.get(s.date).push(s); });
      const inMonth = rows.filter(s => s.date.startsWith(`${vy}-${pad2(vm + 1)}`));
      const msum = inMonth.reduce((a, s) => ({ b: a.b + s.booked, c: a.c + s.checked, n: a.n + 1 }), { b: 0, c: 0, n: 0 });
      let cells = '';
      for (let i = 0; i < first; i++) cells += '<div class="acal-d pad"></div>';
      for (let d = 1; d <= dim; d++) {
        const key = `${vy}-${pad2(vm + 1)}-${pad2(d)}`;
        const list = byDate.get(key) || [];
        // วันที่มีรอบ: แตะตรงไหนก็ได้ในช่อง → ดูทั้งวัน (ทุกรอบ + รายชื่อ) ในหน้าเดียว
        const chips = list.map(s => `<span class="chip ${sState(s)}" title="${s.start}${s.end ? '–' + s.end : ''}${s.note ? ' · ' + esc(s.note) : ''}"><b>${s.start}</b><span>${sLabel(s)}${s.waiting ? ' +' + s.waiting : ''}</span></span>`).join('');
        cells += list.length
          ? `<a class="acal-d has${key === today ? ' today' : ''}" href="#/day/${key}/${list.map(s => s.id).join(',')}"><span class="dn">${d}</span>${chips}</a>`
          : (isAdmin && key >= today ? `<a class="acal-d empty${key === today ? ' today' : ''}" href="#/new-session/${key}" title="เพิ่มรอบพิเศษวันนี้"><span class="dn">${d}</span><span class="addp">+</span></a>`
            : `<div class="acal-d${key === today ? ' today' : ''}"><span class="dn">${d}</span></div>`);
      }
      const prev = new Date(vy, vm - 1, 1), nxt = new Date(vy, vm + 1, 1);
      body = `
        <div class="acal-head"><a class="nav" href="#/sessions/cal/${prev.getFullYear()}-${pad2(prev.getMonth() + 1)}">‹</a>
          <h2>${MONTHS_FULL[vm]} ${yr(vy)}</h2>
          <a class="nav" href="#/sessions/cal/${nxt.getFullYear()}-${pad2(nxt.getMonth() + 1)}">›</a></div>
        <p class="note acal-sum">${msum.n} รอบ · จอง ${msum.b} คน${msum.c ? ` · มาจริง ${msum.c}` : ''}${(vy !== now.getFullYear() || vm !== now.getMonth()) ? ` · <a href="#/sessions/cal/${now.getFullYear()}-${pad2(now.getMonth() + 1)}">เดือนนี้</a>` : ''}</p>
        <div class="acal"><div class="acal-dow">${(EN ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] : ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส']).map(x => `<span>${x}</span>`).join('')}</div><div class="acal-grid">${cells}</div></div>
        <p class="legend acal-lg"><span><i class="chip open"></i>มีที่</span><span><i class="chip few"></i>ใกล้เต็ม</span><span><i class="chip full"></i>เต็ม</span><span><i class="chip off"></i>ปิด</span><span><i class="chip past"></i>ผ่านไปแล้ว (✓ มาจริง/จอง)</span></p>
        ${yearKpis(st)}`;
    } else {
      const list = mode === 'past' ? rows.filter(s => new Date(s.starts_at) < now).reverse() : rows.filter(s => new Date(s.starts_at) >= now);
      const sum = list.reduce((a, s) => ({ b: a.b + s.booked, c: a.c + s.checked, x: a.x + s.canceled }), { b: 0, c: 0, x: 0 });
      let lastMonth = '';
      body = `
        <div class="kpis">
          <div><b>${sum.b}</b><small>จอง (${mode === 'past' ? 'รอบที่ผ่านมา' : 'รอบที่จะถึง'})</small></div>
          ${mode === 'past' ? `<div><b>${sum.c}</b><small>มาจริง</small></div><div><b>${sum.x}</b><small>ยกเลิกแล้ว</small></div>` : ''}
        </div>
        ${yearKpis(st)}
        <div class="slist">${list.map(s => {
          const ym = s.date.slice(0, 7);
          const hdr = ym !== lastMonth ? `<h3 class="mhead">${MONTHS_FULL[Number(ym.slice(5)) - 1]} ${yr(Number(ym.slice(0, 4)))}</h3>` : '';
          lastMonth = ym;
          const stt = sState(s);
          return `${hdr}<a class="srow ${stt}" href="#/session/${s.id}">
            <span class="sd"><b>${fmtDate(s.date)} · ${s.start}${s.end ? '–' + s.end : ''}</b><small>${s.note ? esc(s.note) + ' · ' : ''}${!s.active ? 'ปิดรับ' : s.auto_closed ? '<span class="tag few">ปิดอัตโนมัติ (ไม่มีคนจอง)</span>' : stt === 'past' ? 'ผ่านไปแล้ว' : 'เปิดรับจอง'}</small></span>
            <span class="sb"><b class="sbn">${s.booked} คน</b><small>${stt === 'past' ? `มาจริง ${s.checked}` : (s.checked ? `เช็คอิน ${s.checked}` : 'จอง')}${s.canceled ? ` · ยกเลิก ${s.canceled}` : ''}${s.waiting ? ` · <span class="tag few">รอ ${s.waiting}</span>` : ''}</small></span>
          </a>`; }).join('') || '<p class="note">ไม่มีรอบ</p>'}</div>`;
    }
    view.innerHTML = head + body;
    view.querySelectorAll('[data-mode]').forEach(b => b.onclick = () => { location.hash = '#/sessions/' + (b.dataset.mode === 'cal' ? '' : b.dataset.mode); });
    view.querySelectorAll('[data-go]').forEach(b => b.onclick = e => {
      e.preventDefault(); e.stopPropagation();
      if (STANDALONE && b.dataset.go.startsWith('../scan/')) { openScan(Number(b.dataset.go.split('#')[1]) || 0); return; }   // แอป: เปิดหน้าสแกนในกรอบ
      try { sessionStorage.setItem('vb-back', location.href); } catch (_) {} location.href = b.dataset.go; });
  }

  // เพิ่มรอบพิเศษ (นอกตารางอาทิตย์ที่ 2 และ 4 เช่น วันเปิดพิเศษ / หมู่คณะ) — แตะวันว่างในปฏิทินจะใส่วันที่ให้
  function showSessionForm(date = '') {
    view.innerHTML = `<p class="crumb"><a href="#/sessions">ปฏิทิน</a></p>
      <section class="panel narrow"><h2>เพิ่มรอบพิเศษ</h2>
      <p class="note">รอบอาทิตย์ที่ 2 และ 4 (10:00–12:00, 14:00–16:00) ระบบสร้างให้อัตโนมัติ — ใช้หน้านี้เฉพาะรอบนอกตาราง เช่น วันเปิดพิเศษ</p>
      <form id="sf" class="sform">
        <label><span>วันที่</span><input type="date" name="date" required value="${esc(date || todayYmd())}" min="${todayYmd()}"></label>
        <div class="grid2"><label><span>เวลาเริ่ม</span><input type="time" name="start" required value="10:00"></label><label><span>เวลาจบ</span><input type="time" name="end" value="12:00"></label></div>
        <label><span>หมายเหตุ <em>(เห็นเฉพาะหลังบ้าน เช่น "หมู่คณะโรงเรียน…")</em></span><input type="text" name="note"></label>
        <details class="adv"><summary>จำกัดจำนวน (ไม่บังคับ)</summary><label><span>จำนวนสูงสุดของรอบนี้ <em>(ว่าง = ตามค่าเริ่มต้น)</em></span><input type="number" name="capacity" min="0" inputmode="numeric"></label></details>
        <div class="actions two"><a class="ghost" href="#/sessions">ยกเลิก</a><button class="primary">สร้างรอบ</button></div></form></section>`;
    $('sf').onsubmit = async e => {
      e.preventDefault(); const f = e.target;
      try { const r = await api('session_create', { date: f.date.value, start: f.start.value, end: f.end.value, capacity: f.capacity.value, note: f.note.value }); toast('สร้างรอบแล้ว'); location.hash = '#/session/' + r.id; } catch (x) { toast(x.message, true); }
    };
  }

  // ---------- waiting list ของรอบ (รอบเต็ม → คนลงชื่อรอ; แอดมินกดให้สิทธิ์จอง) ----------
  const WL_ST = { waiting: ['รอ', 'few'], invited: ['ส่งสิทธิ์แล้ว', 'open'], booked: ['จองแล้ว', 'open'], expired: ['สิทธิ์หมดอายุ', 'full'] };
  function waitlistHtml(list, s) {
    if (!list || !list.length) return '';
    return `<section class="panel wl"><h3>รายชื่อรอ (waiting list) · ${list.filter(w => w.status === 'waiting' || w.status === 'invited').length}</h3>
      <p class="note">รอบนี้เต็มตามความจุแล้ว — กด "ให้สิทธิ์จอง" เพื่อส่งลิงก์ให้จองได้แม้เต็ม (ภายใน 48 ชม.) ถ้ายอมให้มาเพิ่ม</p>
      <table class="tbl"><tbody>${list.map(w => `<tr><td class="c-who"><b>${R(w.given)} ${R(w.family)}</b> · ${w.qty} คน${w.is_test ? ' <span class="tag few">ทดสอบ</span>' : ''}<br><small>ลงชื่อ ${fmtTs(w.created_at)}</small></td>
        <td class="c-contact">${w.phone ? `<a href="tel:${esc(w.phone)}">${R(w.phone)}</a>` : ''}<br><small>${R(w.email)}</small></td>
        <td class="c-ci"><span class="tag ${WL_ST[w.status][1]}">${WL_ST[w.status][0]}${w.status === 'invited' ? ' ถึง ' + fmtTs(w.expires_at) : ''}</span>${w.order_code ? `<br><a class="mono" href="#/order/${w.order_code}">${w.order_code}</a>` : ''}
          ${me.role === 'admin' && !s.started && w.status !== 'booked' ? `<button class="ghost sm" data-wlinv="${w.id}" data-name="${esc(w.given + ' ' + w.family)}" data-qty="${w.qty}">${w.status === 'waiting' ? 'ให้สิทธิ์จอง' : 'ส่งสิทธิ์อีกครั้ง'}</button><button class="linkbtn" data-wldel="${w.id}">ลบ</button>` : ''}</td></tr>`).join('')}</tbody></table></section>`;
  }
  function bindWaitlist(reload) {
    view.querySelectorAll('[data-wlinv]').forEach(b => b.onclick = async () => {
      if (!await ask({ title: `ให้สิทธิ์จองแก่ ${b.dataset.name}?`, text: `${b.dataset.qty} คน · ระบบจะส่งอีเมลลิงก์จองให้ จองได้แม้รอบเต็ม (เกินความจุ)`, ok: 'ส่งสิทธิ์จอง', preview: () => api('waitlist_invite', { id: Number(b.dataset.wlinv), preview: 1 }) })) return;
      try { await api('waitlist_invite', { id: Number(b.dataset.wlinv) }); toast('ส่งสิทธิ์จองแล้ว'); reload(); } catch (x) { toast(x.message, true); }
    });
    view.querySelectorAll('[data-wldel]').forEach(b => b.onclick = async () => {
      if (!await ask({ title: 'ลบออกจากรายชื่อรอ?', text: 'จะไม่แจ้งผู้ลงชื่อ', ok: 'ลบ', danger: true })) return;
      try { await api('waitlist_remove', { id: Number(b.dataset.wldel) }); reload(); } catch (x) { toast(x.message, true); }
    });
  }

  // บรรทัดรองของผู้เข้าชม: ไอคอน + เบอร์ · อีเมล · หน่วยงาน (จาง, บรรทัดเดียว ล้นแล้วขึ้นบรรทัดใหม่)
  const ICS = { tel: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>', mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    org: '<path d="M4 21V7l8-4 8 4v14M9 21v-6h6v6M8 10h.01M12 10h.01M16 10h.01"/>', user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c.8-4.4 4-6.5 8-6.5s7.2 2.1 8 6.5"/>' };
  const ic = k => `<svg class="ci2" viewBox="0 0 24 24" aria-hidden="true">${ICS[k]}</svg>`;
  function subInfo(t, contactPhone = '', contactName = '') {
    const items = [];
    if (t.phone) items.push(`<a href="tel:${esc(t.phone)}">${ic('tel')}${R(t.phone)}</a>`);
    else if (contactPhone) items.push(`<a href="tel:${esc(contactPhone)}">${ic('tel')}${R(contactPhone)}</a><em>ผู้ติดต่อ</em>`);
    if (t.email) items.push(`<span>${ic('mail')}${R(t.email)}</span>`);
    if (t.org) items.push(`<span>${ic('org')}${R(t.org)}</span>`);
    if (contactName) items.push(`<span>${ic('user')}ผู้ติดต่อ: ${R(contactName)}</span>`);
    return items.length ? `<span class="vinfo">${items.join('')}</span>` : '';
  }

  // เมนู ⋯ สำหรับคำสั่งที่ใช้ไม่บ่อย — items: [{label, href?, id?, onclick?: 'print'}]
  const moreMenu = items => { items = items.filter(Boolean); if (!items.length) return '';
    return `<span class="mm"><button type="button" class="mm-btn" aria-label="เพิ่มเติม" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg></button><span class="mm-pop" hidden>${items.map(i =>
      i.href ? `<a href="${i.href}">${i.label}</a>` : `<button type="button"${i.id ? ` id="${i.id}"` : ''}${i.print ? ' data-print' : ''}>${i.label}</button>`).join('')}</span></span>`; };
  document.addEventListener('click', e => {
    const b = e.target.closest('.mm-btn');
    document.querySelectorAll('.mm-pop').forEach(p => { if (!b || p !== b.nextElementSibling) { p.hidden = true; p.previousElementSibling.setAttribute('aria-expanded', 'false'); } });
    if (b) { const p = b.nextElementSibling; p.hidden = !p.hidden; b.setAttribute('aria-expanded', String(!p.hidden));
      if (!p.hidden) { p.style.left = ''; p.style.right = ''; const r = p.getBoundingClientRect(); if (r.left < 8) { p.style.left = '0'; p.style.right = 'auto'; } } }   // เมนูไม่ล้นขอบจอซ้าย
    if (e.target.closest('.mm-pop [data-print]')) window.print();
  });

  // ---------- ประวัติผู้เข้าชม (จับคนเดียวกันด้วยอีเมล / เบอร์ / ชื่อ+นามสกุลตรงกัน) ----------
  const personLink = t => '#/person/' + new URLSearchParams({ e: t.email || '', p: t.phone || '', g: t.given || '', f: t.family || '' }).toString();
  function histBadge(h, t) {
    if (!h || !h.total) return '';
    const b = [];
    if (h.noshow) b.push(`<span class="tag full">ไม่มา ${h.noshow} ครั้ง</span>`);
    if (h.attended) b.push(`<span class="tag open">มาแล้ว ${h.attended}</span>`);
    if (h.canceled) b.push(`<span class="tag off">ยกเลิก ${h.canceled}</span>`);
    if (h.upcoming) b.push(`<span class="tag few">มีจองรอบอื่นอีก ${h.upcoming}</span>`);
    return b.length ? ` <a class="hist" href="${t ? personLink(t) : '#'}" title="ประวัติ">${b.join(' ')}</a>` : '';
  }
  async function showPerson(qs) {
    const q = new URLSearchParams(qs);
    const j = await api('person', { email: q.get('e'), phone: q.get('p'), given: q.get('g'), family: q.get('f') });
    const h = j.summary || {};
    view.innerHTML = `<p class="crumb"><a href="javascript:history.back()">กลับ</a></p>
      <div class="shead"><div><h2>${R(q.get('g') || '')} ${R(q.get('f') || '')}</h2><p class="note">${R([q.get('e'), q.get('p')].filter(Boolean).join(' · '))}</p></div></div>
      <p class="note">จับคนเดียวกันด้วยอีเมล หรือเบอร์ หรือชื่อ+นามสกุลตรงกันทั้งคู่ (คนละคนที่ชื่อซ้ำกันอาจถูกรวม)</p>
      <div class="kpis"><div><b>${h.total || 0}</b><small>จองทั้งหมด</small></div><div><b>${h.attended || 0}</b><small>มาจริง</small></div><div><b>${h.noshow || 0}</b><small>จองแต่ไม่มา</small></div><div><b>${h.canceled || 0}</b><small>ยกเลิก</small></div></div>
      ${peopleGroups(j.people, 'person')}`;
  }

  // ---------- ทั้งวัน: ทุกรอบของวันนั้น + รายชื่อ (แตะวันในปฏิทิน) ----------
  async function showDay(date, ids) {
    const rs = await Promise.all(ids.map(id => api('session', { id })));
    rs.sort((a, b) => a.session.starts_at.localeCompare(b.session.starts_at));
    const row = (t, s) => `<tr class="${t.canceled ? 'x' : ''}" data-q="${esc((t.given + ' ' + t.family + ' ' + t.code + ' ' + (t.phone || '') + ' ' + (t.org || '')).toLowerCase())}">
        <td class="c-who"><b>${R(t.given)} ${R(t.family)}</b><br><a href="#/order/${t.code}" class="mono">${t.code}-${t.n}</a>${t.canceled ? ' <span class="tag full">ยกเลิก</span>' : (t.order_status === 'w' ? ' <span class="tag few">รอยืนยันอีเมล</span>' : (t.attend_at && !t.checked_in_at ? ' <span class="tag open">ยืนยันว่าจะมา</span>' : ''))}${t.is_test ? ' <span class="tag few">ทดสอบ</span>' : ''}${histBadge(t.hist, t)}</td>
        <td class="c-contact">${subInfo(t)}</td>
        <td class="c-ci">${t.canceled ? '' : (t.checked_in_at ? `<span class="tag open">✓ ${fmtTime(t.checked_in_at)}</span>${s.locked ? '' : `<button class="linkbtn" data-undo="${t.id}">ยกเลิก</button>`}` : (s.locked ? '<span class="tag full">ไม่มา</span>' : `<button class="ghost sm" data-in="${t.id}">เช็คอิน</button>`))}</td></tr>`;
    view.innerHTML = `
      <p class="crumb"><a href="#/sessions/cal/${date.slice(0, 7)}">ปฏิทินเดือนนี้</a></p>
      <div class="shead"><div><h2>${fmtDate(date)}</h2>
        <p class="note">${rs.length} รอบ · จอง ${rs.reduce((a, r) => a + r.session.booked, 0)} คน</p></div>
        <div class="tools">${moreMenu([me.role === 'admin' && date >= todayYmd() ? { label: 'ส่งอีเมลถึงทั้งวัน', href: `#/session-mail/${ids.join(',')}` } : null,
          me.role === 'admin' && date <= todayYmd() ? { label: 'ส่งอีเมลแจ้งคนที่ไม่มา', id: 'nsDay' } : null,
          me.role === 'admin' && date > todayYmd() ? { label: 'ยกเลิกทั้งวัน', id: 'cxDay' } : null,
          { label: 'ส่งออก CSV (ทั้งวัน)', href: `../api/admin.php?action=export&sessions=${ids.join(',')}` }, { label: 'พิมพ์รายชื่อทั้งวัน', print: true }])}</div></div>
      <input type="search" id="flt" class="filter" placeholder="ค้นชื่อ / รหัส / เบอร์">
      ${rs.map(({ session: s, tickets, waitlist }) => {
        const active = tickets.filter(t => !t.canceled);
        const attend = active.filter(t => t.attend_at || t.checked_in_at).length;
        return `<section class="dayblk">
          <div class="dayhead"><div><h3>${s.start}${s.end ? '–' + s.end : ''} <span class="tag ${sState(s)}">${s.locked ? 'ผ่านไปแล้ว' : s.active ? 'เปิดรับจอง' : 'ปิดรับจอง'}</span></h3>
            <small>${s.locked ? `จอง ${s.booked} · มาจริง ${s.checked}` : `จอง ${s.booked} · ยืนยันว่าจะมา ${attend}/${active.length} · เช็คอินแล้ว ${s.checked}`}${s.note ? ' · ' + R(s.note) : ''}</small></div>
            <div class="tools">${s.locked ? '' : `<a class="primary sm" href="../scan/#${s.id}">สแกน</a>`}${moreMenu([{ label: 'รายละเอียดรอบ', href: `#/session/${s.id}` },
              me.role === 'admin' && !s.locked ? { label: 'ส่งอีเมลรอบนี้', href: `#/session-mail/${s.id}` } : null, { label: 'ส่งออก CSV รอบนี้', href: `../api/admin.php?action=export&session=${s.id}` }])}</div></div>
          ${tickets.length ? `<table class="tbl"><tbody>${tickets.map(t => row(t, s)).join('')}</tbody></table>` : '<p class="note">ยังไม่มีผู้จอง</p>'}
          ${waitlistHtml(waitlist, s)}
        </section>`;
      }).join('')}`;
    const reload = () => showDay(date, ids);
    bindWaitlist(reload);
    if ($('nsDay')) $('nsDay').onclick = () => noshowMail({ sessions: ids.join(',') });
    if ($('cxDay')) $('cxDay').onclick = () => cancelSessions(ids.join(','), 'ทั้งวัน', reload);
    $('flt').oninput = e => { const q = e.target.value.trim().toLowerCase(); view.querySelectorAll('tbody tr').forEach(tr => { tr.hidden = q && !tr.dataset.q.includes(q); }); };
    view.querySelectorAll('[data-in]').forEach(b => b.onclick = async () => { const sess = rs.find(r => r.tickets.some(t => t.id === Number(b.dataset.in))).session; if (!await confirmCheckin(b, sess)) return; try { const r = await api('checkin_ticket', { id: Number(b.dataset.in) }); toast(r.result === 'ok' ? 'เช็คอินแล้ว' : 'สถานะ: ' + r.result); reload(); } catch (x) { toast(x.message, true); } });
    view.querySelectorAll('[data-undo]').forEach(b => b.onclick = async () => {
      const name = b.closest('tr').querySelector('.c-who b').textContent;
      if (!await ask({ title: 'ยกเลิกการเช็คอิน?', text: `${name} จะกลับเป็น "ยังไม่ได้เช็คอิน"`, ok: 'ยกเลิกเช็คอิน', cancel: 'ไม่ใช่', danger: true })) return;
      try { await api('checkin_ticket', { id: Number(b.dataset.undo), undo: true }); reload(); } catch (x) { toast(x.message, true); } });
  }

  // เช็คอินด้วยมือ: แสดงข้อมูลให้ตรวจก่อนยืนยัน — ก่อนถึงวันของรอบเตือนชัด ๆ
  function confirmCheckin(btn, s) {
    const tr = btn.closest('tr');
    const txt = sel => (tr.querySelector(sel)?.innerText || '').trim();
    const name = txt('.c-who b'), code = (tr.querySelector('.c-who .mono')?.textContent || '').trim();
    const info = [code, txt('.c-contact').replace(/\n+/g, ' · '), txt('.c-org')].filter(Boolean).join('\n');
    const early = s && s.date > todayYmd();
    return ask({
      title: `เช็คอิน ${name}?`,
      text: (early ? `⚠️ รอบนี้ยังไม่ถึงวัน (${fmtDate(s.date)} ${s.start}) — ปกติเช็คอินในวันเข้าชมเท่านั้น\n\n` : '') + info,
      ok: early ? 'เช็คอินล่วงหน้า' : 'เช็คอิน', danger: early,
    });
  }

  async function showSession(id) {
    const j = await api('session', { id });
    const s = j.session, isAdmin = me.role === 'admin';
    const active = j.tickets.filter(t => !t.canceled);
    const attend = active.filter(t => t.attend_at || t.checked_in_at).length, pending = active.filter(t => t.order_status === 'w').length;
    view.innerHTML = `
      <p class="crumb"><a href="#/sessions/cal/${s.date.slice(0, 7)}">ปฏิทินเดือนนี้</a></p>
      <div class="shead"><div><h2>${fmtDate(s.date)} · ${s.start}${s.end ? '–' + s.end : ''}</h2>
        <p class="note">${s.locked ? 'รอบนี้ผ่านไปแล้ว — ดูได้อย่างเดียว' : (s.active ? 'เปิดรับจอง' : 'ปิดรับจอง')}${s.note ? ' · ' + esc(s.note) : ''}</p></div>
        <div class="tools stools">${s.locked ? '' : `<a class="primary sm" href="../scan/#${s.id}">สแกนรอบนี้</a><a class="ghost sm" href="#/add-booking/${s.id}">+ เพิ่มการจอง</a>`}${moreMenu([isAdmin && !s.locked ? { label: 'ตั้งค่ารอบ', id: 'sessSet' } : null, isAdmin && s.started ? { label: 'ส่งอีเมลแจ้งคนที่ไม่มา', id: 'nsSess' } : null, isAdmin && !s.started ? { label: 'ยกเลิกทั้งรอบ', id: 'cxSess' } : null,
          isAdmin && !s.locked ? { label: 'ส่งอีเมลถึงทั้งรอบ', href: `#/session-mail/${s.id}` } : null, { label: 'ส่งออก CSV', href: `../api/admin.php?action=export&session=${s.id}` }, { label: 'พิมพ์รายชื่อ', print: true }])}</div></div>
      <div class="kpis"><div><b>${s.booked}</b><small>จอง</small></div>
        ${s.locked ? `<div><b>${s.checked}</b><small>มาจริง (เช็คอิน)</small></div>` : `<div><b>${attend}<small> / ${active.length}</small></b><small>ยืนยันว่าจะมา${pending ? ` · รอยืนยันอีเมล ${pending}` : ''}</small></div>`}<div><b>${s.canceled}</b><small>ยกเลิกแล้ว</small></div></div>
      <input type="search" id="flt" class="filter" placeholder="ค้นชื่อ / รหัส / เบอร์">
      <table class="tbl"><thead><tr><th>ผู้เข้าชม</th><th>ติดต่อ</th><th>เช็คอิน</th></tr></thead>
      <tbody>${j.tickets.map(t => `<tr class="${t.canceled ? 'x' : ''}" data-q="${esc((t.given + ' ' + t.family + ' ' + t.code + ' ' + (t.phone || '') + ' ' + (t.org || '')).toLowerCase())}">
        <td class="c-who"><b>${R(t.given)} ${R(t.family)}</b><br><a href="#/order/${t.code}" class="mono">${t.code}-${t.n}</a>${t.canceled ? ' <span class="tag full">ยกเลิก</span>' : (t.order_status === 'w' ? ' <span class="tag few">รอยืนยันอีเมล</span>' : (t.attend_at && !t.checked_in_at ? ' <span class="tag open">ยืนยันว่าจะมา</span>' : ''))}${t.is_test ? ' <span class="tag few">ทดสอบ</span>' : ''}${histBadge(t.hist, t)}</td>
        <td class="c-contact">${subInfo(t)}</td>
        <td class="c-ci">${t.canceled ? '' : (t.checked_in_at ? `<span class="tag open">✓ ${fmtTime(t.checked_in_at)}</span>${s.locked ? '' : `<button class="linkbtn" data-undo="${t.id}">ยกเลิก</button>`}` : (s.locked ? '<span class="tag full">ไม่มา</span>' : `<button class="ghost sm" data-in="${t.id}">เช็คอิน</button>`))}</td>
      </tr>`).join('')}</tbody></table>
      ${active.length ? '' : '<p class="note">ยังไม่มีผู้จอง</p>'}
      ${waitlistHtml(j.waitlist, s)}`;
    bindWaitlist(() => showSession(id));
    $('flt').oninput = e => { const q = e.target.value.trim().toLowerCase(); view.querySelectorAll('tbody tr').forEach(tr => { tr.hidden = q && !tr.dataset.q.includes(q); }); };
    view.querySelectorAll('[data-in]').forEach(b => b.onclick = async () => { if (!await confirmCheckin(b, s)) return; try { const r = await api('checkin_ticket', { id: Number(b.dataset.in) }); toast(r.result === 'ok' ? 'เช็คอินแล้ว' : 'สถานะ: ' + r.result); showSession(id); } catch (x) { toast(x.message, true); } });
    view.querySelectorAll('[data-undo]').forEach(b => b.onclick = async () => {
      const name = b.closest('tr').querySelector('.c-who b').textContent;
      if (!await ask({ title: 'ยกเลิกการเช็คอิน?', text: `${name} จะกลับเป็น "ยังไม่ได้เช็คอิน"`, ok: 'ยกเลิกเช็คอิน', cancel: 'ไม่ใช่', danger: true })) return;
      try { await api('checkin_ticket', { id: Number(b.dataset.undo), undo: true }); showSession(id); } catch (x) { toast(x.message, true); } });
    // ตั้งค่ารอบ (ใช้นาน ๆ ครั้ง: ปิดรับจองเฉพาะรอบ / หมายเหตุ / จำกัดจำนวน) — หน้าต่างแยก ไม่กินที่หน้ารายชื่อ
    if ($('nsSess')) $('nsSess').onclick = () => noshowMail({ sessions: String(s.id) });
    if ($('cxSess')) $('cxSess').onclick = () => cancelSessions(String(s.id), 'ทั้งรอบ', () => showSession(s.id));
    if ($('sessSet')) $('sessSet').onclick = () => {
      const bg = document.createElement('div'); bg.className = 'ask-bg';
      bg.innerHTML = `<form class="ask sset"><h3>ตั้งค่ารอบ</h3><p class="note">${fmtDate(s.date)} · ${s.start}${s.end ? '–' + s.end : ''}</p>
        <label class="chk"><input type="checkbox" name="active" ${s.active ? 'checked' : ''}><span>เปิดรับจอง<br><em>(ไม่ติ๊ก = ปิดเฉพาะรอบนี้ เช่น วันหยุดพิเศษ)</em></span></label>
        <label><span>หมายเหตุ <em>(เห็นเฉพาะหลังบ้าน)</em></span><input class="ask-in" type="text" name="note" value="${esc(s.note || '')}"></label>
        <label><span>จำนวนสูงสุดของรอบนี้ <em>(ว่าง = ตามค่าเริ่มต้น)</em></span><input class="ask-in" type="number" min="0" inputmode="numeric" name="capacity" value="${s.capacity ?? ''}"></label>
        <div class="ask-btns"><button type="button" class="no">ยกเลิก</button><button class="ok">บันทึก</button></div></form>`;
      document.body.append(bg);
      const f = bg.querySelector('form'), close = () => bg.remove();
      bg.querySelector('.no').onclick = close; bg.onclick = e => { if (e.target === bg) close(); };
      f.onsubmit = async e => {
        e.preventDefault();
        if ((s.active && !f.active.checked) || f.capacity.value === '0') {
          close();
          if (!await ask({ title: 'ปิดรับจองรอบนี้?', text: (s.booked ? `รอบนี้มีผู้จองแล้ว ${s.booked} คน — การจองเดิมยังอยู่ (ไม่ถูกยกเลิก)\n` : '') + 'คนภายนอกจะจองรอบนี้ไม่ได้', ok: 'ปิดรับจอง', danger: true })) return;
        } else close();
        try { await api('session_save', { id: s.id, capacity: f.capacity.value, note: f.note.value, active: f.active.checked }); toast('บันทึกแล้ว'); showSession(id); } catch (x) { toast(x.message, true); }
      };
    };
  }

  // ---------- ค้นหา + ออเดอร์ ----------
  function showSearch(q = '') {
    view.innerHTML = `<h2>ค้นหาการจอง</h2><input type="search" id="q" class="filter" placeholder="รหัสการจอง / ชื่อ / อีเมล / เบอร์ / หน่วยงาน" value="${esc(q)}" autofocus><div id="res"></div>`;
    let timer;
    const run = async () => {
      const v = $('q').value.trim();
      if (v.length < 2) { $('res').innerHTML = ''; return; }
      const j = await api('orders_search', { q: v });
      $('res').innerHTML = j.orders.map(o => `<a class="srow" href="#/order/${o.code}"><span class="sd"><b>${R(o.contact)} · <span class="mono">${o.code}</span></b><small>${R(o.email)}</small></span>
        <span class="sb"><small>${o.slot ? fmtDate(o.slot.date) + ' ' + o.slot.start : ''} · ${o.qty} คน${o.status === 'c' ? ' · <span class="tag full">ยกเลิก</span>' : o.status === 'w' ? ' · <span class="tag few">รอยืนยันอีเมล</span>' : ''}${o.is_test ? ' · <span class="tag few">ทดสอบ</span>' : ''}</small></span></a>`).join('') || '<p class="note">ไม่พบ</p>';
    };
    $('q').oninput = () => { clearTimeout(timer); timer = setTimeout(run, 250); };
    if (q) run();
  }

  // ---------- ประวัติ (ไทม์ไลน์) ----------
  const EV_LABEL = { noshow_mail: 'แจ้งไม่มาเข้าชม', booked: 'จอง', confirmed: 'ยืนยันอีเมล', canceled: 'ยกเลิก', ticket_canceled: 'ยกเลิกตั๋ว', restored: 'คืนการจอง', edited: 'แก้ไขข้อมูล',
    note: 'โน้ตภายใน', attend: 'ยืนยันว่าจะมา', checkin: 'เช็คอิน', invited: 'ให้สิทธิ์จอง (waiting list)', mass_mail: 'ส่งอีเมลถึงทั้งรอบ', resend: 'ส่งอีเมลอีกครั้ง' };
  const MAIL_ST = { sent: 'ส่งแล้ว', failed: 'ส่งไม่สำเร็จ', outbox: 'กล่องทดสอบ', preview: 'ไม่ได้ส่ง (ไม่มี SMTP)' };
  const actorLabel = a => !a ? '' : a === 'customer' ? 'ผู้จอง' : a === 'attendee' ? 'ผู้เข้าชม' : a === 'system' ? 'ระบบ' : a === 'pretix' ? 'Pretix' : a.startsWith('admin:') ? a.slice(6) : a;
  function timelineHtml(items) {
    if (!items || !items.length) return '<p class="note">ยังไม่มีประวัติ</p>';
    return `<ol class="tline">${items.map(i => {
      if (i.kind === 'mail') return `<li class="tl-mail"><time>${fmtTs(i.at)}</time><div><b>อีเมล</b> <span class="tag ${i.status === 'failed' ? 'full' : i.status === 'sent' ? 'open' : 'few'}">${MAIL_ST[i.status] || i.status}</span> ถึง ${R(i.to)}<br><button class="linkbtn" data-mail="${i.id}">${R(i.subject)}</button></div></li>`;
      if (i.kind === 'checkin') return `<li class="tl-ci"><time>${fmtTs(i.at)}</time><div><b>${i.type === 'undo' ? 'ยกเลิกเช็คอิน' : 'เช็คอิน'}</b> ${R(i.detail)}<small> · ${R(i.actor || '')}</small></div></li>`;
      return `<li class="tl-${i.type}"><time>${fmtTs(i.at)}</time><div><b>${EV_LABEL[i.type] || i.type}</b>${i.actor ? `<small> · ${['customer', 'attendee', 'system', 'pretix'].includes(i.actor) ? actorLabel(i.actor) : R(actorLabel(i.actor))}</small>` : ''}${i.detail ? `<br><span class="tl-d">${R(i.detail)}</span>` : ''}</div></li>`;
    }).join('')}</ol>`;
  }
  // แสดงอีเมลที่จะส่ง (อาจหลายฉบับ เช่น ผู้จอง + ผู้เข้าชมที่มีอีเมล) — ซ้อนบนหน้าต่างยืนยัน
  function showMailPreview(list) {
    if (!list.length) { toast('ไม่มีอีเมลที่จะส่ง', true); return; }
    let i = 0;
    const bg = document.createElement('div'); bg.className = 'ask-bg pv-bg';
    bg.innerHTML = `<div class="ask mailbox"><h3></h3><p class="note pv-to"></p><div class="pv-files"></div><iframe class="mailpv" sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe>
      <div class="ask-btns">${list.length > 1 ? '<button type="button" class="pv-next">ฉบับถัดไป</button>' : ''}<button type="button" class="ok">ปิด</button></div></div>`;
    document.body.append(bg);
    const show = () => { const m = list[i]; bg.querySelector('h3').textContent = m.subject; bg.querySelector('.pv-to').textContent = `ถึง ${m.to}` + (list.length > 1 ? ` · ฉบับที่ ${i + 1}/${list.length}` : ''); const pf = bg.querySelector('.pv-files'); pf.innerHTML = attachHtml(m.files); bindAttach(pf, m.files || []); bg.querySelector('iframe').srcdoc = mailDoc(m.html); };
    show();
    const nx = bg.querySelector('.pv-next'); if (nx) nx.onclick = () => { i = (i + 1) % list.length; show(); };
    const close = () => bg.remove();
    bg.querySelector('.ok').onclick = close; bg.onclick = e => { if (e.target === bg) close(); };
  }
  async function viewMail(id) {
    try {
      const m = await api('mail_view', { id });
      const bg = document.createElement('div'); bg.className = 'ask-bg';
      bg.innerHTML = `<div class="ask mailbox"><h3>${R(m.subject)}</h3><p class="note">ถึง ${R(m.to)}</p><iframe class="mailpv" sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe><div class="ask-btns"><button type="button" class="ok">ปิด</button></div></div>`;
      document.body.append(bg);
      bg.querySelector('iframe').srcdoc = mailDoc(m.html);
      const close = () => bg.remove();
      bg.querySelector('.ok').onclick = close; bg.onclick = e => { if (e.target === bg) close(); };
    } catch (x) { toast(x.message, true); }
  }

  // ---------- แก้ไขข้อมูลการจอง ----------
  function editOrder(o, tickets) {
    const fld = (n, l, v, type = 'text') => `<label><span>${l}</span><input type="${type}" name="${n}" value="${esc(v || '')}"></label>`;
    view.innerHTML = `<p class="crumb"><a href="#/order/${o.code}" data-back-order>กลับ</a></p><h2>แก้ไขการจอง <span class="mono">${o.code}</span></h2>
      <form id="eo"><section class="panel"><h3>ผู้ติดต่อ</h3><div class="grid2">${fld('c_given', 'ชื่อ', o.given)}${fld('c_family', 'นามสกุล', o.family)}</div>
        <div class="grid2">${fld('c_email', 'อีเมล', o.email, 'email')}${fld('c_phone', 'เบอร์โทร', o.phone, 'tel')}</div>${fld('c_org', 'หน่วยงาน', o.org)}</section>
      ${tickets.map(t => `<section class="panel"><h3>ผู้เข้าชมคนที่ ${t.n}${t.canceled ? ' <span class="tag full">ยกเลิก</span>' : ''}</h3><div class="grid2">${fld('t' + t.id + '_given', 'ชื่อ', t.given)}${fld('t' + t.id + '_family', 'นามสกุล', t.family)}</div>
        <div class="grid2">${fld('t' + t.id + '_email', 'อีเมล', t.email, 'email')}${fld('t' + t.id + '_phone', 'เบอร์โทร', t.phone, 'tel')}</div>${fld('t' + t.id + '_org', 'หน่วยงาน', t.org)}</section>`).join('')}
      <p class="note">ระบบบันทึกในประวัติว่าแก้อะไร · ไม่ส่งอีเมลแจ้งผู้จอง (ใช้ "ส่งอีเมลยืนยันอีกครั้ง" ถ้าต้องการ)</p>
      <div class="actions"><a class="ghost" href="#/order/${o.code}" data-back-order>ยกเลิก</a><button class="primary">บันทึก</button></div></form>`;
    view.querySelectorAll('[data-back-order]').forEach(a => a.onclick = e => { e.preventDefault(); showOrder(o.code); });   // hash เดิม = ไม่มี hashchange
    window.scrollTo(0, 0);
    $('eo').onsubmit = async e => {
      e.preventDefault(); const f = e.target, g = n => f[n].value;
      try {
        const r = await api('order_edit', { code: o.code, contact: { given: g('c_given'), family: g('c_family'), email: g('c_email'), phone: g('c_phone'), org: g('c_org') },
          tickets: tickets.map(t => ({ id: t.id, given: g(`t${t.id}_given`), family: g(`t${t.id}_family`), email: g(`t${t.id}_email`), phone: g(`t${t.id}_phone`), org: g(`t${t.id}_org`) })) });
        toast(r.changes ? `บันทึกแล้ว (${r.changes} รายการ)` : 'ไม่มีการเปลี่ยนแปลง'); showOrder(o.code);
      } catch (x) { toast(x.message, true); }
    };
  }

  // ---------- กิจกรรมล่าสุด ----------
  async function showActivity() {
    const j = await api('activity');
    const dayKey = at => { const d = new Date(at); return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`; };
    const dayLabel = k => { const t = todayYmd(), y = new Date(); y.setDate(y.getDate() - 1);
      const yk = `${y.getFullYear()}-${pad2(y.getMonth() + 1)}-${pad2(y.getDate())}`; return k === t ? 'วันนี้' : k === yk ? 'เมื่อวาน' : fmtDate(k); };
    const tone = t => ['booked', 'checkin', 'restored', 'confirmed', 'attend'].includes(t) ? 'g' : ['canceled', 'ticket_canceled'].includes(t) ? 'r' : 'n';
    // แถวหลัก: ชื่อ (หนา) + การกระทำ · แถวรอง: รอบ · รหัส · ใครทำ · รายละเอียด (จาง)
    const item = e => {
      const nm = e.type === 'checkin' ? (e.detail || '').replace(/^#\d+\s*/, '') : e.contact;
      const verb = e.type === 'booked' ? `จอง${e.detail && /คน/.test(e.detail) ? ' ' + e.detail.split(' · ')[0] : ''}` : (EV_LABEL[e.type] || e.type);
      const who = e.actor ? actorLabel(e.actor).replace(/@.*$/, '') : '';   // แอดมิน: ชื่อหน้า @ พอ
      const meta = [e.slot ? `${fmtDate(e.slot.date)} ${e.slot.start}` : '', e.code || '', who].filter(Boolean).join(' · ');
      const extra = e.type === 'booked' || e.type === 'checkin' ? '' : (e.detail || '');
      const href = e.code ? `#/order/${e.code}` : e.session_id ? `#/session/${e.session_id}` : '';
      const tag = href ? 'a' : 'div';
      return `<${tag} class="act"${href ? ` href="${href}"` : ''}><span class="adot ${tone(e.type)}"></span>
        <span class="abody"><span class="aline">${nm ? `<b>${R(nm)}</b> ` : ''}<span class="averb ${tone(e.type)}">${verb}</span>${e.is_test ? ' <span class="tag few">ทดสอบ</span>' : ''}</span>
        ${meta ? `<small class="ameta">${R(meta)}</small>` : ''}${extra ? `<small class="aextra">${R(extra)}</small>` : ''}</span>
        <time>${fmtTime(e.at)}</time></${tag}>`;
    };
    let lastDay = '';
    const render = items => items.map(e => { const k = dayKey(e.at); const h = k !== lastDay ? `<h3 class="aday">${dayLabel(k)}</h3>` : ''; lastDay = k; return h + item(e); }).join('');
    view.innerHTML = `<h2>กิจกรรมล่าสุด</h2>
      <div class="acts" id="acts">${render(j.items) || '<p class="note">ยังไม่มีกิจกรรม</p>'}</div>
      ${j.items.length === 50 ? '<div class="actions"><button class="ghost" id="more">ดูเพิ่ม</button></div>' : ''}`;
    let last = j.items.length ? j.items[j.items.length - 1].at : '';
    const more = $('more'); if (more) more.onclick = async () => { const k = await api('activity', { before: last }); $('acts').insertAdjacentHTML('beforeend', render(k.items)); last = k.items.length ? k.items[k.items.length - 1].at : last; if (k.items.length < 50) more.remove(); };
  }

  // ---------- ประวัติการใช้งาน (ผู้ดูแลสูงสุด) + ย้อนกลับ ----------
  const AU_COL = { status: 'สถานะ', canceled: 'ยกเลิก', checked_in_at: 'เช็คอิน', checked_in_by: 'เช็คอินโดย', given: 'ชื่อ', family: 'นามสกุล', email: 'อีเมล', phone: 'เบอร์', org: 'หน่วยงาน',
    internal_note: 'โน้ตภายใน', is_test: 'ทดสอบ', capacity: 'ความจุ', active: 'เปิด', note: 'หมายเหตุ', role: 'สิทธิ์', super: 'ผู้ดูแลสูงสุด', name: 'ชื่อ', v: 'ค่า',
    canceled_at: 'ยกเลิกเมื่อ', canceled_by: 'ยกเลิกโดย', confirmed_at: 'ยืนยันเมื่อ', comment: 'บันทึก', invited_at: 'ให้สิทธิ์เมื่อ', expires_at: 'หมดอายุ', starts_at: 'เริ่ม', ends_at: 'จบ' };
  const AU_T = { orders: 'การจอง', tickets: 'ผู้เข้าชม', sessions: 'รอบ', settings: 'ตั้งค่า', admins: 'ผู้ใช้', waitlist: 'รายชื่อรอ', passkeys: 'passkey', checkin_log: 'บันทึกเช็คอิน', event_log: 'ประวัติ', mail_log: 'อีเมล', outbox: 'กล่องทดสอบ' };
  const auVal = (c, v) => v === null || v === '' ? (c === 'name' ? '(ยังไม่ได้ตั้ง)' : '(ว่าง)') : c === 'status' ? ({ p: 'ใช้งาน', c: 'ยกเลิก', w: 'รอยืนยัน' }[v] || v)
    : ['canceled', 'is_test', 'active', 'super'].includes(c) ? (String(v) === '1' ? 'ใช่' : 'ไม่') : /^\d{4}-\d\d-\d\dT/.test(String(v)) ? fmtTs(v) : String(v);
  async function showAudit() {
    const who = sessionStorage.getItem('vb-au-who') || '';
    const j = await api('audit', { who });
    const dayKey = at => { const d = new Date(at); return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`; };
    const pname = e => { const p = j.people.find(x => x.email === e); return p && p.name ? p.name : String(e).replace(/@.*$/, ''); };
    const diffHtml = d => d.slice(0, 12).map(r => `<li><span class="au-t">${AU_T[r.t] || r.t}</span> ${R(r.name)}${r.kind === 'add' ? ' <i>สร้างใหม่</i>' : r.kind === 'del' ? ' <i>ลบ</i>' : ''}
      ${r.fields.slice(0, 8).map(([c, b, a]) => `<div class="au-f">${AU_COL[c] || c}: <s>${R(auVal(c, b))}</s> → <b>${R(auVal(c, a))}</b></div>`).join('')}</li>`).join('') + (d.length > 12 ? `<li>…อีก ${d.length - 12} รายการ</li>` : '');
    const item = e => `<div class="au${e.undone_at ? ' undone' : ''}${e.action === 'login' ? ' login' : ''}">
        <div class="au-h"><span class="abody"><span class="aline"><b>${R(pname(e.actor))}</b> ${R(e.label)}${e.target ? ` · ${e.link ? `<a href="${esc(e.link)}">${R(e.target)}</a>` : R(e.target)}` : (e.link ? ` · <a href="${esc(e.link)}">เปิด</a>` : '')}</span>
          ${e.note ? `<small class="ameta">${R(e.note)}</small>` : ''}
          ${e.undone_at ? `<small class="ameta">ย้อนกลับแล้ว ${fmtTs(e.undone_at)} โดย ${R(pname(e.undone_by))}</small>` : ''}</span>
          <time>${fmtTime(e.at)}</time></div>
        ${e.diff.length ? `<details class="au-d"><summary>ดูการเปลี่ยนแปลง (${e.diff.length})</summary><ul>${diffHtml(e.diff)}</ul></details>` : ''}
        ${e.undoable ? `<button class="ghost sm" data-undo="${e.id}" data-label="${esc(e.label)}">ย้อนกลับ</button>` : ''}</div>`;
    let lastDay = '';
    const render = items => items.map(e => { const k = dayKey(e.at); const h = k !== lastDay ? `<h3 class="aday">${k === todayYmd() ? 'วันนี้' : fmtDate(k)}</h3>` : ''; lastDay = k; return h + item(e); }).join('');
    view.innerHTML = `<h2>ประวัติการใช้งาน</h2>
      <p class="note">ทุกการกระทำในหลังบ้านและหน้าสแกน ของทุกคน · ย้อนกลับได้ (อีเมลที่ส่งออกไปแล้วเรียกคืนไม่ได้)</p>
      <div class="filter"><select id="auWho"><option value="">ทุกคน</option>${j.people.map(p => `<option value="${esc(p.email)}"${p.email === who ? ' selected' : ''}>${R(p.name || p.email)}</option>`).join('')}</select></div>
      <div class="acts" id="aus">${render(j.items) || '<p class="note">ยังไม่มีประวัติ</p>'}</div>
      ${j.items.length === 60 ? '<div class="actions"><button class="ghost" id="more">ดูเพิ่ม</button></div>' : ''}`;
    $('auWho').onchange = e => { sessionStorage.setItem('vb-au-who', e.target.value); showAudit(); };
    let last = j.items.length ? j.items[j.items.length - 1].at : '';
    const more = $('more'); if (more) more.onclick = async () => { const k = await api('audit', { who, before: last }); $('aus').insertAdjacentHTML('beforeend', render(k.items)); last = k.items.length ? k.items[k.items.length - 1].at : last; if (k.items.length < 60) more.remove(); };
    $('aus').onclick = async ev => {
      const b = ev.target.closest('[data-undo]'); if (!b) return;
      if (!await ask({ title: 'ย้อนกลับ?', text: `"${b.dataset.label}" — ข้อมูลจะกลับไปเป็นเหมือนก่อนทำรายการนี้\nอีเมลที่ส่งไปแล้วเรียกคืนไม่ได้ · การย้อนกลับถูกบันทึกไว้และย้อนกลับได้อีก`, ok: 'ย้อนกลับ', danger: true })) return;
      try {
        let r = await api('audit_undo', { id: Number(b.dataset.undo) });
        if (r.conflict) {
          if (!await ask({ title: 'มีการแก้ไขหลังจากนั้น', text: `ข้อมูลต่อไปนี้ถูกเปลี่ยนอีกหลังรายการนี้:\n${r.conflict.join('\n')}\n\nย้อนกลับจะทับการเปลี่ยนแปลงเหล่านั้นด้วย`, ok: 'ย้อนกลับทั้งหมด', danger: true })) return;
          r = await api('audit_undo', { id: Number(b.dataset.undo), force: true });
        }
        toast('ย้อนกลับแล้ว'); showAudit();
      } catch (x) { toast(x.message, true); }
    };
  }

  // ---------- ยกเลิก: เหตุผล (แจ้งผู้จอง) แยกจาก หมายเหตุ (ภายใน) ----------
  const REASON_PH = 'เหตุผลที่แจ้งผู้จอง (ไม่บังคับ) เช่น ไฟดับ';
  const NOTE_PH = 'หมายเหตุภายใน (ไม่บังคับ) — ผู้จองไม่เห็น';
  // ยกเลิกทั้งรอบ / ทั้งวัน
  async function cancelSessions(ids, label, after) {
    try {
      const d = await api('sessions_cancel', { sessions: ids, dry: 1 });
      const a = await ask({ title: `ยกเลิก${label}?`, text: d.orders ? `${d.orders} การจอง · ${d.people} คน จะถูกยกเลิกทั้งหมด (คืนได้ทีละการจอง)` : 'ยังไม่มีการจองในช่วงนี้ — ปิดรับจองได้อย่างเดียว',
        input: { value: '', placeholder: REASON_PH }, input2: { value: '', placeholder: NOTE_PH },
        check: d.orders ? { label: 'ส่งอีเมลแจ้งยกเลิกให้ผู้จองทุกคน', checked: true } : null, check2: { label: 'ปิดรับจองด้วย (ไม่ให้จองเพิ่ม)', checked: true },
        ok: `ยกเลิก${label}`, cancel: 'ไม่ยกเลิก', danger: true, preview: d.orders ? reason => api('sessions_cancel', { sessions: ids, preview: 1, reason }) : null });
      if (!a) return;
      const r = await api('sessions_cancel', { sessions: ids, reason: a.value, note: a.value2, notify: !!a.checked, close: !!a.checked2 });
      toast(`ยกเลิกแล้ว ${r.orders} การจอง` + (r.sent ? ` · ส่งอีเมล ${r.sent} ฉบับ` : '')); if (after) after();
    } catch (x) { toast(x.message, true); }
  }

  // ---------- อีเมลแจ้งผู้ที่จองแต่ไม่มา (ข้อความจากผู้ใช้) ----------
  async function noshowMail(params, after) {
    try {
      const d = await api('noshow_mail', { ...params, dry: 1 });
      if (!d.count) { toast('ไม่มีผู้ที่ต้องแจ้ง (ไม่มีคนที่ไม่มา หรือแจ้งไปแล้ว)'); return; }
      if (!await ask({ title: 'ส่งอีเมลแจ้งไม่มาเข้าชม?', text: `ส่งถึงผู้ติดต่อ ${d.count} การจอง ที่จองแต่ไม่มาและยังไม่เคยได้รับอีเมลนี้\nเนื้อหา: ขอให้ยกเลิกล่วงหน้าหากมาไม่ได้ และแจ้งว่าการไม่มาซ้ำอาจมีผลต่อการจองครั้งต่อไป`,
        ok: `ส่ง ${d.count} ฉบับ`, preview: () => api('noshow_mail', { ...params, preview: 1 }) })) return;
      const r = await api('noshow_mail', params); toast(`ส่งแล้ว ${r.sent} ฉบับ`); if (after) after();
    } catch (x) { toast(x.message, true); }
  }

  // ---------- ส่งอีเมลถึงทุกคนในรอบ ----------
  async function showSessionMail(idArg) {
    const ids = String(idArg).split(',').map(Number).filter(Boolean), id = ids.join(',');
    const ss = (await Promise.all(ids.map(x => api('session', { id: x })))).map(j => j.session).sort((a, b) => a.starts_at.localeCompare(b.starts_at));
    const s = ss[0], back = ids.length > 1 ? `#/day/${s.date}/${id}` : `#/session/${id}`;
    view.innerHTML = `<p class="crumb"><a href="${back}">กลับ</a></p><h2>ส่งอีเมลถึงผู้เข้าชม${ids.length > 1 ? 'ทั้งวัน' : ''}</h2><p class="note">${fmtDate(s.date)} · ${ss.map(x => x.start + (x.end ? '–' + x.end : '')).join(', ')}</p>
      <form id="smf" class="panel"><label><span>ส่งถึง</span><select name="who"><option value="contacts">ผู้ติดต่อของทุกการจอง</option><option value="all">ผู้ติดต่อ + ผู้เข้าชมที่มีอีเมลของตัวเอง</option></select></label>
        <label><span>หัวเรื่อง</span><input type="text" name="subject" required maxlength="200"></label>
        <label><span>ข้อความ <em>(ระบบใส่คำทักทาย ชื่อ วันเวลาของรอบ และคำลงท้ายให้)</em></span><textarea name="body" class="notebox" rows="7" required></textarea></label>
        <p class="note" id="smCount"></p>
        <div class="actions"><button type="button" class="ghost" id="smPrev">ดูตัวอย่าง</button><button class="primary">ส่งอีเมล</button></div></form>
      <iframe class="mailpv" id="smPv" hidden sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe>`;
    const f = $('smf'), payload = dry => ({ id, who: f.who.value, subject: f.subject.value, body: f.body.value, dry });
    const count = async () => { try { const r = await api('session_mail', payload(true)); $('smCount').textContent = `ผู้รับ ${r.count} คน` + ($('testbar').hidden ? '' : ' (โหมดทดลอง: เฉพาะการจองทดสอบ — เข้ากล่องอีเมลทดสอบ)'); return r; } catch (x) { toast(x.message, true); } };
    count(); f.who.onchange = count;
    $('smPrev').onclick = async () => { const r = await count(); if (r && r.preview) { $('smPv').hidden = false; $('smPv').srcdoc = mailDoc(r.preview); } else toast('กรอกหัวเรื่องและข้อความก่อน', true); };
    f.onsubmit = async e => {
      e.preventDefault(); const r0 = await count(); if (!r0) return;
      if (!f.subject.value.trim() || !f.body.value.trim()) { toast('กรอกหัวเรื่องและข้อความก่อน', true); return; }
      if (!await ask({ title: `ส่งอีเมลถึง ${r0.count} คน?`, text: `หัวเรื่อง: ${f.subject.value}\nส่งแล้วยกเลิกไม่ได้`, ok: 'ส่งอีเมล' })) return;
      try { const r = await api('session_mail', payload(false)); toast(`ส่งแล้ว ${r.sent}/${r.count}`); location.hash = back; } catch (x) { toast(x.message, true); }
    };
  }

  async function showOrder(code) {
    const j = await api('order', { code });
    const o = j.order, isAdmin = me.role === 'admin';
    const active = j.tickets.filter(t => !t.canceled);
    const ST = o.status === 'c' ? ['full', 'ยกเลิก'] : o.status === 'w' ? ['few', 'รอยืนยันอีเมล'] : ['open', 'ยืนยันแล้ว'];
    const src = o.source === 'pretix' ? 'นำเข้าจาก Pretix' : o.source === 'admin' ? 'แอดมิน' : 'เว็บ';
    const canCancel = (isAdmin || me.can_cancel) && (o.status === 'p' || o.status === 'w') && !o.started;
    view.innerHTML = `
      <p class="crumb"><a href="#/search">ค้นหา</a></p>
      <div class="ohead"><h2><span class="mono">${o.code}</span></h2><span class="tag ${ST[0]}">${ST[1]}</span>${o.is_test ? '<span class="tag few">ทดสอบ</span>' : ''}</div>
      <section class="osum">
        ${o.slot ? `<a class="osum-when" href="#/session/${o.session_id}"><b>${fmtDate(o.slot.date)}</b><span>${o.slot.start}${o.slot.end ? '–' + o.slot.end : ''}</span></a>` : ''}
        <div class="osum-n"><b>${active.length}</b><small>คน</small></div>
        <p class="osum-meta">จองเมื่อ ${fmtTs(o.created_at)} · ${src}</p>
        ${o.status === 'c' ? `<p class="osum-alert r">ยกเลิกเมื่อ ${fmtTs(o.canceled_at)} · ${cancelReason(o.canceled_by)}</p>` : ''}
        ${o.status === 'w' ? `<p class="osum-alert">ยังไม่ได้กดยืนยันในอีเมล — ถ้าไม่ยืนยันภายใน ${fmtTs(o.confirm_by)} ระบบจะยกเลิกอัตโนมัติ</p>` : ''}
        ${o.status === 'p' && o.started ? '<p class="osum-meta">รอบนี้เริ่มหรือผ่านไปแล้ว — ยกเลิกย้อนหลังไม่ได้</p>' : ''}
      </section>
      <div class="obar">
        ${isAdmin ? `<button class="ghost sm" id="editO"><svg class="oi" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/></svg>แก้ไขข้อมูล</button>` : ''}
        ${isAdmin && o.status === 'p' && !o.started ? `<button class="ghost sm" id="resend"><svg class="oi" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>ส่งอีเมลอีกครั้ง</button>` : ''}
        ${o.manage_url ? `<button class="ghost sm" id="copy"><svg class="oi" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a1 1 0 0 1 1-1h10"/></svg>คัดลอกลิงก์ "การจองของฉัน"</button>` : ''}
        ${o.status === 'c' && !o.ended ? `<button class="primary sm" id="restoreO">คืนการจอง</button>` : ''}
        ${isAdmin && o.status === 'w' && !o.started ? `<button class="primary sm" id="confirmO">ยืนยันแทน (ส่งตั๋ว)</button>` : ''}
        ${isAdmin ? moreMenu([o.is_test ? { label: 'ยกเลิกเครื่องหมายทดสอบ (กลับมานับสถิติ)', id: 'unTest' } : { label: 'ทำเป็นการจองทดสอบ (ไม่นับสถิติ)', id: 'mkTest' },
          o.is_test ? { label: 'ลบการจองนี้ถาวร', id: 'delO' } : null,
          o.ended && o.status === 'p' && !j.tickets.some(t => t.checked_in_at) && !(j.timeline || []).some(i => i.type === 'noshow_mail') ? { label: 'ส่งอีเมลแจ้งไม่มาเข้าชม', id: 'nsO' } : null]) : ''}
      </div>
      <section class="panel"><h3 class="h3row">ผู้ติดต่อ${isAdmin ? '<button class="linkbtn" data-edit>แก้ไข</button>' : ''}</h3>
        <p class="oname"><b>${R(o.given)} ${R(o.family)}</b>${o.org ? `<small>${R(o.org)}</small>` : ''}</p>
        <a class="oline" href="mailto:${esc(o.email)}"><svg class="oi" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg><span>${R(o.email)}</span></a>
        ${o.phone ? `<a class="oline" href="tel:${esc(o.phone)}"><svg class="oi" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg><span>${R(o.phone)}</span>${o.phone_verified_at ? '<span class="tag open">ยืนยันเบอร์แล้ว</span>' : ''}</a>` : ''}
        <p class="osmall">ภาษา ${o.lang === 'th' ? 'ไทย' : 'อังกฤษ'} · รับข่าวสาร: ${o.news ? 'ใช่' : 'ไม่'}${o.status === 'p' && o.confirmed_at && o.source === 'web' ? ` · ยืนยันอีเมลแล้ว${o.reminded_at && !o.reminded_at.startsWith('skip') ? ' · ส่งเตือนเมื่อ ' + fmtTs(o.reminded_at) : ''}` : ''}</p>
        ${o.contact_hist && o.contact_hist.total ? `<p class="osmall">เคยจองอีก ${o.contact_hist.total} ครั้ง:${histBadge(o.contact_hist, { email: o.email, phone: o.phone, given: o.given, family: o.family })}</p>` : ''}
        ${o.comment ? `<p class="osmall">${R(o.comment)}</p>` : ''}
      </section>
      <section class="panel"><h3>ผู้เข้าชม (${active.length}${j.tickets.length > active.length ? ` · ยกเลิก ${j.tickets.length - active.length}` : ''})</h3>
        <div class="vlist">${j.tickets.map(t => `<div class="vrow${t.canceled ? ' x' : ''}"><div class="vmain"><b>${R(t.given)} ${R(t.family)}</b><small class="mono">${o.code}-${t.n}</small>${histBadge(t.hist, t)}
          ${t.phone || t.email || t.org ? `<small class="vsub">${[t.phone ? `<a href="tel:${esc(t.phone)}">${R(t.phone)}</a>` : '', t.email ? R(t.email) + (t.news ? ' · รับข่าวสาร' : '') : '', t.org ? R(t.org) : ''].filter(Boolean).join(' · ')}</small>` : ''}</div>
          <div class="vst">${t.checked_in_at ? `<span class="tag open">✓ ${fmtTs(t.checked_in_at)}</span>` : t.canceled ? '<span class="tag off">ยกเลิก</span>' : t.attend_at ? '<span class="tag open">ยืนยันว่าจะมา</span>' : ''}
            ${(isAdmin || me.can_cancel) && !t.canceled ? moreMenu([isAdmin ? { label: 'แก้ไขข้อมูล', id: `te${t.id}` } : null, isAdmin && t.email && o.status === 'p' && !o.started ? { label: 'ส่งตั๋วให้คนนี้', id: `tr${t.id}` } : null,
              !o.started && active.length > 1 ? { label: 'ยกเลิกคนนี้', id: `tc${t.id}` } : null]) : ''}</div></div>`).join('')}</div>
      </section>
      <section class="panel onote"><h3>โน้ตภายใน <small class="note">(เห็นเฉพาะหลังบ้าน)</small></h3>
        <div id="noteView"${o.internal_note ? '' : ' hidden'}><p class="notetext">${R(o.internal_note || '')}</p><button class="linkbtn" id="noteEdit">แก้โน้ต</button></div>
        ${o.internal_note ? '' : '<button class="linkbtn" id="noteAdd">+ เพิ่มโน้ต</button>'}
        <div id="noteForm" hidden><textarea id="noteO" class="notebox" rows="3" placeholder="เช่น โทรคุยแล้ว จะมาสาย 15 นาที">${esc(o.internal_note || '')}</textarea>
          <div class="actions"><button class="ghost sm" id="noteSave">บันทึกโน้ต</button></div></div>
      </section>
      <section class="panel"><h3>ประวัติ</h3>${timelineHtml(j.timeline)}</section>
      ${canCancel ? `<div class="odanger"><button class="danger" id="cancel">ยกเลิกการจองนี้</button></div>` : ''}`;
    { const openNote = () => { $('noteForm').hidden = false; $('noteView').hidden = true; if ($('noteAdd')) $('noteAdd').hidden = true; $('noteO').focus(); };
      if ($('noteEdit')) $('noteEdit').onclick = openNote; if ($('noteAdd')) $('noteAdd').onclick = openNote; }
    $('noteSave').onclick = async () => { try { await api('order_note', { code: o.code, note: $('noteO').value }); toast('บันทึกแล้ว'); showOrder(code); } catch (x) { toast(x.message, true); } };
    view.querySelectorAll('[data-mail]').forEach(b => b.onclick = () => viewMail(Number(b.dataset.mail)));
    view.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => editOrder(o, j.tickets));
    if ($('nsO')) $('nsO').onclick = () => noshowMail({ code: o.code }, () => showOrder(code));
    if ($('mkTest')) $('mkTest').onclick = async () => {
      if (!await ask({ title: `ทำ ${o.code} เป็นการจองทดสอบ?`, text: 'ไม่นับในสถิติ ไม่ขึ้นในกิจกรรม และไม่ส่งแจ้งเตือนแอดมิน — ข้อมูลยังอยู่ กดกลับได้\nใช้กับการจองที่จองเพื่อทดลองระบบ', ok: 'ทำเป็นทดสอบ' })) return;
      try { await api('order_mark_test', { code: o.code, on: true }); toast('ทำเป็นการจองทดสอบแล้ว'); showOrder(code); } catch (x) { toast(x.message, true); } };
    if ($('unTest')) $('unTest').onclick = async () => {
      try { await api('order_mark_test', { code: o.code, on: false }); toast('กลับมานับสถิติแล้ว'); showOrder(code); } catch (x) { toast(x.message, true); } };
    if ($('delO')) $('delO').onclick = async () => {
      const a = await ask({ title: `ลบ ${o.code} ถาวร?`, text: 'ลบการจอง ตั๋ว ประวัติ และอีเมลของการจองนี้ทั้งหมด ย้อนกลับไม่ได้\nพิมพ์รหัสการจองเพื่อยืนยัน', input: { placeholder: o.code, required: true }, ok: 'ลบถาวร', danger: true });
      if (!a) return;
      if (a.value.toUpperCase() !== o.code) { toast('รหัสไม่ตรง — ไม่ได้ลบ', true); return; }
      try { await api('order_delete', { code: o.code }); toast('ลบแล้ว'); location.hash = o.session_id ? '#/session/' + o.session_id : '#/sessions'; } catch (x) { toast(x.message, true); } };
    j.tickets.forEach(t => {
      const e = $(`te${t.id}`), r = $(`tr${t.id}`), c = $(`tc${t.id}`), nm = `${t.given} ${t.family}`;
      if (e) e.onclick = () => editOrder(o, j.tickets);
      if (r) r.onclick = async () => { if (!await ask({ title: `ส่งตั๋วให้ ${nm}?`, text: `ส่งตั๋ว (QR) ไปที่ ${t.email}`, ok: 'ส่งตั๋ว', preview: () => api('ticket_resend', { id: t.id, preview: 1 }) })) return;
        try { const x = await api('ticket_resend', { id: t.id }); toast('ส่งแล้วถึง ' + x.to); showOrder(code); } catch (x) { toast(x.message, true); } };
      if (c) c.onclick = async () => { const a = await ask({ title: `ยกเลิก ${nm}?`, text: 'คนอื่นในการจองนี้ยังใช้ตั๋วเดิมได้ตามปกติ',
          input: { value: '', placeholder: REASON_PH }, input2: { value: '', placeholder: NOTE_PH },
          check: t.email ? { label: 'ส่งอีเมลแจ้งผู้เข้าชมคนนี้', checked: true } : null,
          ok: 'ยกเลิกคนนี้', danger: true, preview: t.email ? reason => api('ticket_cancel', { id: t.id, preview: 1, reason }) : null });
        if (!a) return; try { await api('ticket_cancel', { id: t.id, notify: !!a.checked, reason: a.value, note: a.value2 }); toast('ยกเลิกแล้ว'); showOrder(code); } catch (x) { toast(x.message, true); } };
    });
    if (isAdmin) $('editO').onclick = () => editOrder(o, j.tickets);
    if ($('copy')) $('copy').onclick = async () => { try { await navigator.clipboard.writeText(o.manage_url); toast('คัดลอกแล้ว'); } catch (_) { ask({ title: 'ลิงก์ "การจองของฉัน"', text: 'คัดลอกลิงก์ด้านล่าง', input: { value: o.manage_url }, ok: 'ปิด' }); } };
    if (o.status === 'c' && !o.ended) $('restoreO').onclick = async () => {
      const a = await ask({ title: `คืนการจอง ${o.code}?`, text: `${o.given} ${o.family} · ${j.tickets.length} คน\nตั๋วทุกใบในการจองนี้จะกลับมาใช้ได้ (ต้องมีที่ว่างพอ)`, check: { label: 'ส่งอีเมลตั๋วให้ผู้จองอีกครั้ง', checked: !o.started }, ok: 'คืนการจอง', preview: () => api('order_restore', { code: o.code, preview: 1 }) });
      if (!a) return;
      try { await api('order_restore', { code: o.code, notify: a.checked }); toast('คืนการจองแล้ว'); showOrder(code); } catch (x) { toast(x.message, true); }
    };
    if (isAdmin && o.status === 'w' && !o.started) {
      $('confirmO').onclick = async () => {
        if (!await ask({ title: `ยืนยันการจอง ${o.code} แทนผู้จอง?`, text: `เช่น โทรคุยแล้ว หรืออีเมลเข้าสแปม\nระบบจะส่งตั๋ว (QR) ไปที่ ${o.email}`, ok: 'ยืนยันและส่งตั๋ว', preview: () => api('order_confirm', { code: o.code, preview: 1 }) })) return;
        try { await api('order_confirm', { code: o.code }); toast('ยืนยันแล้ว'); showOrder(code); } catch (x) { toast(x.message, true); }
      };
    }
    if (isAdmin && (o.status === 'p' || o.status === 'w') && !o.started) {
      if ($('resend')) $('resend').onclick = async () => {
        const a = await ask({ title: 'ส่งอีเมลยืนยันอีกครั้ง', text: 'ส่งอีเมลยืนยันการจองพร้อม QR ไปที่:', input: { type: 'email', value: o.email, required: true }, ok: 'ส่งอีเมล', preview: to => api('order_resend', { code: o.code, to: to || o.email, preview: 1 }) });
        if (!a) return;
        try { const r = await api('order_resend', { code: o.code, to: a.value }); toast('ส่งแล้วถึง ' + r.to); } catch (x) { toast(x.message, true); }
      };
      $('cancel').onclick = async () => {
        const a = await ask({ title: `ยกเลิกการจอง ${o.code}?`, text: `${o.given} ${o.family} · ${j.tickets.filter(t => !t.canceled).length} คน\nตั๋วทุกใบในการจองนี้จะใช้ไม่ได้`,
          input: { value: '', placeholder: REASON_PH }, input2: { value: '', placeholder: NOTE_PH },
          check: { label: 'ส่งอีเมลแจ้งยกเลิกให้ผู้จอง', checked: true },
          ok: 'ยกเลิกการจอง', cancel: 'ไม่ยกเลิก', danger: true, preview: reason => api('order_cancel', { code: o.code, preview: 1, reason }) });
        if (!a) return;
        try { await api('order_cancel', { code: o.code, notify: a.checked, reason: a.value, note: a.value2 }); toast('ยกเลิกแล้ว'); showOrder(code); } catch (x) { toast(x.message, true); }
      };
    }
  }

  // ---------- อีเมล: กล่องอีเมลทดสอบ + ตัวอย่าง ----------
  async function showMail() {
    const [ob, tm, st] = await Promise.all([api('outbox'), api('test_mode'), api('settings')]);
    // ตัวอย่างเฉพาะอีเมลที่ระบบส่งจริงตามการตั้งค่าตอนนี้ (แบบลิงก์ยืนยันหลังจองปิดอยู่ = ไม่แสดง)
    const legacy = st.values.email_confirm === '1';
    const PV = [['code', 'รหัสยืนยันอีเมล (ก่อนจอง)'], ...(legacy ? [['confirm', 'ขอให้ยืนยันการจอง']] : []), ['booked', 'ยืนยันการจอง (ตั๋ว)'], ...(legacy ? [['expired', 'ยกเลิก: ไม่ได้ยืนยัน']] : []),
      ['reminder', 'เตือนก่อนวันเข้าชม'], ['ticket', 'ตั๋วถึงผู้เข้าชม'], ['cancel', 'ยกเลิก'], ['att_cancel', 'แจ้งผู้ติดต่อ: ผู้เข้าชมยกเลิก'], ['resend', 'ส่งลิงก์อีกครั้ง'], ['wl_joined', 'ลงชื่อรอแล้ว'], ['wl_invited', 'มีที่ว่าง (สิทธิ์จอง)'], ['noshow', 'แจ้งไม่มาเข้าชม']];
    view.innerHTML = `<h2>อีเมล</h2>
      <section class="panel"><h3>ตัวอย่างอีเมล <small class="note">(ข้อมูลสมมติ ไม่ส่ง)</small></h3>
        <div class="tools wrapbtn">${PV.map(([k, l]) =>
          `<button class="ghost sm" data-pv="${k}" data-lang="th">${l} · ไทย</button><button class="ghost sm" data-pv="${k}" data-lang="en">${l} · EN</button>`).join('')}</div></section>
      <section class="panel"><h3>ตัวอย่างไฟล์ตั๋ว <small class="note">(ข้อมูลสมมติ · QR ใช้เช็คอินไม่ได้)</small></h3>
        <div class="tools wrapbtn">
          <a class="ghost sm" href="../api/admin.php?action=sample_pdf&lang=th" target="_blank" rel="noopener">ตั๋ว PDF · ไทย</a><a class="ghost sm" href="../api/admin.php?action=sample_pdf&lang=en" target="_blank" rel="noopener">ตั๋ว PDF · EN</a>
          <a class="ghost sm" href="../api/admin.php?action=sample_pass&lang=th">Apple Wallet · ไทย</a><a class="ghost sm" href="../api/admin.php?action=sample_pass&lang=en">Apple Wallet · EN</a>
          <a class="ghost sm" href="../api/admin.php?action=sample_gpass&lang=th" target="_blank" rel="noopener">Google Wallet · ไทย</a><a class="ghost sm" href="../api/admin.php?action=sample_gpass&lang=en" target="_blank" rel="noopener">Google Wallet · EN</a></div>
        <p class="note">Apple Wallet: เปิดใน Safari บน iPhone · Google Wallet: เปิดบนมือถือ Android หรือคอมพิวเตอร์ (ล็อกอินบัญชี Google)</p></section>
      <section class="panel"><h3>กล่องอีเมลทดสอบ (${ob.mails.length})</h3>
        <p class="note">อีเมลของการจองทดสอบจะมาอยู่ที่นี่แทนการส่งจริง — กด "ส่งจริง" เมื่อต้องการให้ส่งออกไป (ใช้โควตา SMTP)</p>
        <table class="tbl ob"><tbody>${ob.mails.map(m => `<tr class="click" data-view="${m.id}"><td class="c-who"><b>${R(m.subject)}</b><br><small>${fmtTs(m.created_at)} · ถึง ${R(m.to_addr)}${m.order_code ? ' · ' + m.order_code : ''}</small>${m.sent_at ? `<br><span class="tag open">ส่งจริงแล้ว ${fmtTs(m.sent_at)}</span>` : ''}</td>
          <td class="c-ci"><button class="ghost sm" data-view="${m.id}">ดู</button><button class="ghost sm" data-send="${m.id}" data-to="${esc(m.to_addr)}">ส่งจริง</button></td></tr>`).join('') || '<tr><td class="note">ยังไม่มี</td></tr>'}</tbody></table>
        ${ob.mails.length ? '<p><button class="linkbtn" id="clearOb">ล้างกล่องอีเมลทดสอบ</button></p>' : ''}</section>
      <div id="pv"></div>`;
    const show = (subject, html, files) => {
      $('pv').innerHTML = `<section class="panel"><h3>${R(subject)}</h3>${attachHtml(files)}<iframe class="mailpv" sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe></section>`;
      bindAttach($('pv'), files || []);
      $('pv').querySelector('iframe').srcdoc = mailDoc(html);
      $('pv').scrollIntoView({ behavior: 'smooth' });
    };
    view.querySelectorAll('[data-pv]').forEach(b => b.onclick = async () => { const j = await api('mail_preview', { type: b.dataset.pv, lang: b.dataset.lang }); show(j.subject, j.html, j.files); });
    // แตะทั้งแถว (หรือปุ่ม "ดู") เพื่อเปิดดูอีเมล
    view.querySelectorAll('tr[data-view]').forEach(tr => tr.onclick = async e => {
      if (e.target.closest('[data-send]')) return;
      const j = await api('outbox_view', { id: Number(tr.dataset.view) }); show(j.subject + ' → ' + j.to, j.html);
    });
    view.querySelectorAll('[data-send]').forEach(b => b.onclick = async e => {
      e.stopPropagation();
      const a = await ask({ title: 'ส่งอีเมลนี้จริง?', text: 'อีเมลจะถูกส่งออกจริงผ่าน SMTP (ใช้โควตา) ไปที่:', input: { type: 'email', value: b.dataset.to, required: true }, ok: 'ส่งจริง' });
      if (!a) return;
      try { await api('outbox_send', { id: Number(b.dataset.send), to: a.value }); toast('ส่งแล้ว'); showMail(); } catch (x) { toast(x.message, true); }
    });
    const c = $('clearOb'); if (c) c.onclick = async () => { if (!await ask({ title: 'ล้างกล่องอีเมลทดสอบ?', text: 'อีเมลทดสอบทั้งหมดจะถูกลบ ย้อนกลับไม่ได้', ok: 'ล้างทั้งหมด', danger: true })) return; await api('test_purge', { outbox: true }); showMail(); };
  }

  // ---------- ตั้งค่า ----------
  async function showSettings() {
    const [s, a, tm] = await Promise.all([api('settings'), api('admins'), api('test_mode')]);
    const v = s.values;
    // ตัวช่วยฟอร์ม: แถว (ชื่อ + ช่อง), สวิตช์เปิด/ปิด (ช่องย่อยโผล่เมื่อเปิด), ช่องตัวเลขมีหน่วย, ตัวเลือก
    const row = (label, ctl, help = '') => `<div class="srow2"><span class="sl">${label}${help ? `<small>${help}</small>` : ''}</span><span class="sc">${ctl}</span></div>`;
    const num = (name, val, unit, min = 0) => `<span class="numu"><input type="number" name="${name}" min="${min}" inputmode="numeric" value="${esc(val)}"><span>${unit}</span></span>`;
    const sel = (name, opts, val) => { const o = opts.some(([k]) => k === String(val)) ? opts : [...opts, [String(val), String(val)]];
      return `<select name="${name}">${o.map(([k, l]) => `<option value="${k}"${k === String(val) ? ' selected' : ''}>${l}</option>`).join('')}</select>`; };
    const sw = (name, on, title, desc = '', sub = '') => `<div class="sw-row"><label class="sw"><span class="swt"><b>${title}</b>${desc ? `<small>${desc}</small>` : ''}</span>
      <input type="checkbox" name="${name}" ${on ? 'checked' : ''}><span class="swk" aria-hidden="true"></span></label>${sub ? `<div class="sw-sub"${on ? '' : ' hidden'}>${sub}</div>` : ''}</div>`;
    view.innerHTML = `<h2>ตั้งค่า</h2>
      <section class="panel${tm.on ? ' tm-on' : ''}"><h3>โหมดทดลอง</h3>
        <div class="sw-row"><label class="sw"><span class="swt"><b>เปิดโหมดทดลอง</b><small>การจองใหม่เป็น "ทดสอบ" · ไม่นับที่ว่าง/สถิติ · อีเมลถึงลูกค้าเข้ากล่องทดสอบ (ไม่ส่งจริง) · หน้าจองแสดงแถบโหมดทดลอง</small></span>
          <input type="checkbox" id="tmOn" ${tm.on ? 'checked' : ''}><span class="swk" aria-hidden="true"></span></label></div>
        <div class="srow2"><span class="sl">ข้อมูลทดสอบตอนนี้<small>${tm.test_orders} การจอง</small></span><span class="sc">${tm.test_orders ? '<button type="button" class="ghost sm danger-t" id="purge">ลบข้อมูลทดสอบ</button>' : ''}</span></div>
      </section>
      <form id="stf" class="setf">
        <section class="panel"><h3>การรับจอง</h3>
          ${row('จองได้สูงสุดต่อครั้ง', num('max_per_order', v.max_per_order || 10, 'คน', 1), 'หมู่คณะที่มากกว่านี้ให้ติดต่อโดยตรง')}
          ${sw('cap_on', v.default_capacity !== '' && v.default_capacity !== null, 'จำกัดจำนวนคนต่อรอบ', 'รอบที่เต็มจะขึ้นว่า "เต็ม" ให้คนนอก (ไม่บอกตัวเลข)',
            row('จำนวนต่อรอบ', num('default_capacity', v.default_capacity || 30, 'คน', 1)) +
            sw('waitlist_on', v.waitlist_on !== '0', 'เปิดรายชื่อรอเมื่อรอบเต็ม', 'แอดมินเป็นคนกดให้สิทธิ์จอง',
              row('สิทธิ์จองมีอายุ', num('waitlist_hours', v.waitlist_hours || 48, 'ชั่วโมง', 1))))}
          ${row('ปิดรับจอง', sel('close_minutes', [['0', 'ถึงเวลาเริ่มรอบ'], ['60', '1 ชั่วโมงก่อนรอบ'], ['180', '3 ชั่วโมงก่อนรอบ'], ['1440', '1 วันก่อนรอบ']], v.close_minutes || '0', 'นาทีก่อนรอบ'))}
          ${sw('empty_close', v.empty_close !== '0', 'ปิดรอบที่ยังไม่มีใครจอง', 'รอบที่มีคนจองแล้วยังเปิดรับต่อจนถึงเวลาเริ่มรอบ · คนนอกเห็นแค่ "ปิดรับจองแล้ว"',
            row('ปิดตั้งแต่', sel('empty_close_days', [['1', '00:00 ของ 1 วันก่อน (อาทิตย์ → เสาร์ 00:00)'], ['2', '00:00 ของ 2 วันก่อน (อาทิตย์ → ศุกร์ 00:00)'], ['3', '00:00 ของ 3 วันก่อน']], v.empty_close_days || '1', 'วันก่อน')))}
        </section>
        <section class="panel"><h3>รอบเข้าชม</h3>
          ${sw('auto_generate', v.auto_generate !== '0', 'สร้างรอบอัตโนมัติตามรูปแบบรอบ', 'รอบนอกตารางเพิ่มได้จากปฏิทิน (+ เพิ่มรอบพิเศษ)',
            row('ล่วงหน้า', num('months_ahead', v.months_ahead || 6, 'เดือน', 1)))}
        </section>
        <section class="panel" id="patternPanel"></section>
        <section class="panel"><h3>การยกเลิก</h3>
          ${row('ผู้จองยกเลิกเองได้', sel('cancel_minutes', [['0', 'จนถึงเวลาเริ่มรอบ'], ['60', 'ถึง 1 ชั่วโมงก่อนรอบ'], ['600', 'ถึง 10 ชั่วโมงก่อนรอบ (แบบ Pretix เดิม)'], ['1440', 'ถึง 1 วันก่อนรอบ']], v.cancel_minutes || '0', 'นาทีก่อนรอบ'), 'หลังจากนั้นต้องติดต่อเจ้าหน้าที่')}
        </section>
        <section class="panel"><h3>อีเมลเตือนก่อนวันเข้าชม</h3>
          ${sw('reminder_on', v.reminder_on !== '0', 'ส่งอีเมลเตือน', 'พร้อม QR ปุ่ม "ยืนยันว่าจะมา" และ "ยกเลิกการจอง"',
            row('ส่งเมื่อ', sel('reminder_days', [['1', '1 วันก่อน'], ['2', '2 วันก่อน'], ['3', '3 วันก่อน']], v.reminder_days || '1', 'วันก่อน') + ' ' +
              sel('reminder_hour', Array.from({ length: 15 }, (_, i) => [String(i + 6), `เวลา ${pad2(i + 6)}:00`]), v.reminder_hour || '9', 'โมง')))}
        </section>
        <section class="panel"><h3>ยืนยันตัวตนผู้จอง</h3>
          ${sw('email_verify', v.email_verify !== '0', 'ยืนยันอีเมลด้วยรหัสก่อนจอง', 'แนะนำ · จองสำเร็จ = อีเมลจริงแน่นอน')}
          ${sw('sms_verify', v.sms_verify === '1', 'ยืนยันเบอร์มือถือด้วยรหัส SMS', s.sms ? 'มีค่าใช้จ่ายต่อข้อความ' : 'ยังไม่ได้ตั้งบัญชี SMS — ตอนนี้ใช้ได้เฉพาะโหมดทดลอง')}
          <details class="adv"><summary>ขั้นสูง</summary>
            ${sw('email_confirm', v.email_confirm === '1', 'แบบเดิม: ยืนยันด้วยลิงก์ในอีเมลหลังจอง', 'ไม่ยืนยันตามเวลา = ยกเลิกอัตโนมัติ (ไม่แนะนำ — คนมักคิดว่าจองแล้วจบ)',
              row('ต้องยืนยันภายใน', num('email_confirm_hours', v.email_confirm_hours || 24, 'ชั่วโมง', 1)))}
          </details>
        </section>
        <div class="setsave"><button class="primary">บันทึกการตั้งค่า</button></div>
      </form>
      <p class="note">อีเมลระบบ: ${s.mail ? '✓ ตั้งค่า SMTP แล้ว' : '✗ ยังไม่ตั้ง SMTP (อีเมลจะไม่ถูกส่ง)'} · Apple Wallet: ${s.wallet ? '✓ พร้อมใช้' : '✗ ยังไม่ใส่ใบรับรอง'} · SMS: ${s.sms ? '✓ พร้อมใช้' : '✗ ยังไม่ตั้งบัญชี'}</p>
      <section class="panel"><h3>แจ้งเตือนแอดมิน</h3><form id="nf">
        <div class="srow2 stack"><span class="sl">อีเมลที่รับแจ้งเตือน<small>หลายอีเมลคั่นด้วย , · ว่าง = ไม่ส่ง</small></span><div class="chips nchips">${a.admins.filter(x => x.active).map(x => `<label class="pchip"><input type="checkbox" data-nmail="${esc(x.email)}"><span>${R(x.name || x.email.split('@')[0])}</span></label>`).join('')}</div>
          <input type="text" name="notify_email" value="${esc(v.notify_email || '')}" placeholder="เลือกผู้ใช้ด้านบน หรือพิมพ์อีเมลอื่น"></div>
        <div class="srow2 stack"><span class="sl">แจ้งเมื่อ</span><div class="chips">${[['booked', 'จองใหม่'], ['canceled', 'ยกเลิกการจอง'], ['ticket_canceled', 'ยกเลิกบางคน'], ['edited', 'ผู้จองแก้ไขข้อมูล'], ['restored', 'คืนการจอง'], ['waitlist', 'ลงชื่อรอ (รอบเต็ม)']].map(([k, l]) =>
          `<label class="pchip"><input type="checkbox" name="ev_${k}" ${(v.notify_events || 'booked,canceled,ticket_canceled,edited,restored,waitlist').split(',').includes(k) ? 'checked' : ''}><span>${l}</span></label>`).join('')}</div></div>
        <div class="srow2 stack"><span class="sl">Slack<small>Incoming Webhook URL · ว่าง = ไม่ส่ง</small></span><input type="url" name="slack_webhook" value="${esc(v.slack_webhook || '')}" placeholder="https://hooks.slack.com/services/…" autocomplete="off"></div>
        <div class="srow2 stack"><span class="sl">สำเนาลับ (Bcc) อีเมลถึงผู้จองทุกฉบับ<small>แบบ Pretix · ว่าง = ปิด</small></span><input type="text" name="bcc_email" value="${esc(v.bcc_email || '')}" placeholder="info@example.org"></div>
        <div class="actions"><button type="button" class="ghost" id="nTest">ส่งทดสอบ</button><button class="primary">บันทึก</button></div>
        <p class="note">การจองทดสอบไม่ส่งแจ้งเตือน — ใช้ปุ่ม "ส่งทดสอบ" แทน</p></form></section>
      <section class="panel"><h3>ข้อความ</h3><p class="note">แก้ข้อความบนหน้าจอง หน้าการจองของฉัน และอีเมล (ไทย/อังกฤษ) · ข้อความที่ไม่ได้แก้ใช้ของเดิม</p><div class="actions"><a class="ghost btn" href="#/texts">แก้ข้อความ ›</a></div></section>
      <section class="panel" id="venuePanel"></section>
      ${a.can_manage ? '<section class="panel" id="intPanel"></section><section class="panel" id="walletPanel"></section>' : ''}${a.can_manage ? '<section class="panel" id="cronPanel"></section>' : ''}
      <section class="panel"><h3>ผู้ใช้หลังบ้าน</h3>
        <div class="ulist">${a.admins.map(x => `<div class="urow${x.active ? '' : ' off'}"><span class="uav">${esc((x.name || x.email).slice(0, 1).toUpperCase())}</span>
          <span class="uinfo"><b>${R(x.name || x.email.split('@')[0])}</b><small>${R(x.email)}</small>
            <span class="utags">${roleTag(x)}${x.email === me.email ? '<small class="you">คุณ</small>' : ''}${x.active ? '' : '<small class="you">ปิดบัญชีแล้ว</small>'}</span></span>
          ${a.can_manage ? moreMenu([{ label: 'แก้ไขชื่อ / สิทธิ์', id: `ue${x.id}` }, x.email === me.email ? null : { label: 'เปลี่ยนอีเมล', id: `um${x.id}` }, x.email === me.email ? null : { label: 'เครื่องที่ล็อกอิน / ออกจากระบบ', id: `us${x.id}` }, !x.active ? null : { label: x.email === me.email ? 'ออกรหัสเข้าสู่ระบบให้ตัวเอง (ใช้กับเครื่องอื่น)' : 'ออกรหัสเข้าสู่ระบบ (ไม่ต้องรออีเมล)', id: `uc${x.id}` },
            x.email === me.email ? null : { label: x.active ? 'ปิดบัญชี' : 'เปิดบัญชี', id: `ut${x.id}` }]) : ''}</div>`).join('')}</div>
        ${a.can_manage ? `<details class="adv addu"><summary>+ เพิ่มผู้ใช้</summary>
          <form id="af"><div class="grid2"><label><span>อีเมล</span><input type="email" name="email" required></label><label><span>ชื่อ</span><input type="text" name="name"></label></div>
            <label><span>สิทธิ์</span><select name="role">${LEVELS.map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}</select></label>
            <div class="actions"><button class="primary">เพิ่มผู้ใช้</button></div></form></details>` : '<p class="note">เพิ่มหรือแก้ไขผู้ใช้ได้เฉพาะผู้ดูแลสูงสุด</p>'}
        <p class="note">เข้าสู่ระบบด้วยรหัสทางอีเมล หรือ Face ID (ตั้งที่ เพิ่มเติม → บัญชี)</p></section>`;
    $('tmOn').onchange = async e => {
      const on = e.target.checked;
      const ok = await ask(on
        ? { title: 'เปิดโหมดทดลอง?', text: 'การจองใหม่ทุกรายการ (รวมจากคนภายนอก) จะเป็นข้อมูลทดสอบ ไม่ส่งอีเมลจริง และไม่นับที่ว่าง\nอย่าเปิดทิ้งไว้ตอนใช้งานจริง', ok: 'เปิดโหมดทดลอง' }
        : { title: 'ปิดโหมดทดลอง?', text: 'การจองใหม่จะเป็นของจริง และส่งอีเมลจริง', ok: 'ปิดโหมดทดลอง' });
      if (!ok) { e.target.checked = !on; return; }
      await api('test_mode', { on }); toast(e.target.checked ? 'เปิดโหมดทดลองแล้ว' : 'ปิดโหมดทดลองแล้ว'); refreshTestbar(); };
    const pg = $('purge'); if (pg) pg.onclick = async () => { if (!await ask({ title: 'ลบข้อมูลทดสอบ?', text: `การจองทดสอบ ${tm.test_orders} รายการ (และการเช็คอินของตั๋วเหล่านั้น) จะถูกลบถาวร\nการจองจริงไม่ได้รับผลกระทบ`, ok: 'ลบข้อมูลทดสอบ', danger: true })) return; const r = await api('test_purge'); toast(`ลบ ${r.deleted} รายการ`); showSettings(); };
    $('stf').querySelectorAll('.sw input').forEach(c => c.onchange = () => { const sub = c.closest('.sw-row').querySelector(':scope > .sw-sub'); if (sub) sub.hidden = !c.checked; });
    $('stf').onsubmit = async e => {
      e.preventDefault(); const f = e.target, values = {};
      ['max_per_order', 'close_minutes', 'empty_close_days', 'months_ahead', 'cancel_minutes', 'reminder_days', 'reminder_hour', 'waitlist_hours', 'email_confirm_hours'].forEach(k => values[k] = f[k].value);
      ['auto_generate', 'empty_close', 'reminder_on', 'waitlist_on', 'email_verify', 'email_confirm', 'sms_verify'].forEach(k => values[k] = f[k].checked ? '1' : '0');
      values.default_capacity = f.cap_on.checked ? f.default_capacity.value : '';   // ไม่จำกัด = ว่าง
      try { await api('settings', { values }); toast('บันทึกแล้ว'); } catch (x) { toast(x.message, true); }
    };
    $('nf').onsubmit = async e => {
      e.preventDefault(); const f = e.target;
      const evs = ['booked', 'canceled', 'ticket_canceled', 'edited', 'restored', 'waitlist'].filter(k => f['ev_' + k].checked).join(',');
      try { await api('settings', { values: { notify_email: f.notify_email.value, notify_events: evs, slack_webhook: f.slack_webhook.value, bcc_email: f.bcc_email.value } }); toast('บันทึกแล้ว'); } catch (x) { toast(x.message, true); }
    };
    // เลือกผู้ใช้หลังบ้านเป็นผู้รับแจ้งเตือน (ซิงก์กับช่องอีเมล — พิมพ์อีเมลอื่นเพิ่มได้)
    { const ne = $('nf').notify_email, list = () => ne.value.split(/[\s,;]+/).map(x => x.trim().toLowerCase()).filter(Boolean);
      const sync = () => { const l = list(); view.querySelectorAll('[data-nmail]').forEach(c => { c.checked = l.includes(c.dataset.nmail.toLowerCase()); }); };
      view.querySelectorAll('[data-nmail]').forEach(c => c.onchange = () => { const e = c.dataset.nmail.toLowerCase(), l = list().filter(x => x !== e); if (c.checked) l.push(e); ne.value = l.join(', '); });
      ne.oninput = sync; sync(); }
    if ($('venuePanel')) renderVenue();
    if ($('patternPanel')) renderPattern();
    if ($('intPanel')) renderIntegrations();
    if ($('walletPanel')) renderWalletCert();
    if ($('cronPanel')) renderCron();
    $('nTest').onclick = async () => {
      try { const r = await api('notify_test'); toast(`อีเมล: ${r.email ? '✓ ส่งแล้ว' : '— ไม่ได้ส่ง'} · Slack: ${r.slack ? '✓ ส่งแล้ว' : '— ไม่ได้ส่ง'}`); } catch (x) { toast(x.message, true); }
    };
    if ($('af')) $('af').onsubmit = async e => {
      e.preventDefault(); const f = e.target;
      try { await api('admin_save', { email: f.email.value, name: f.name.value, level: f.role.value }); toast('เพิ่มแล้ว'); showSettings(); } catch (x) { toast(x.message, true); }
    };
    a.admins.forEach(x => {
      const self = x.email === me.email;
      const ue = $(`ue${x.id}`), ut = $(`ut${x.id}`), um = $(`um${x.id}`), uc = $(`uc${x.id}`), us = $(`us${x.id}`);
      if (us) us.onclick = () => showUserSessions(x).catch(e => toast(e.message, true));
      if (uc) uc.onclick = async () => {
        if (!await ask({ title: 'ออกรหัสเข้าสู่ระบบ?', text: `ให้ ${x.name || x.email} — รหัส 6 หลักและลิงก์ ใช้ได้ครั้งเดียว ภายใน 24 ชม. (ไม่ส่งอีเมล ส่งต่อให้เองทาง LINE หรือบอกปากเปล่า)\nรหัสเก่าที่ยังไม่ได้ใช้จะใช้ไม่ได้`, ok: 'ออกรหัส' })) return;
        try {
          const r = await api('login_code_issue', { id: x.id });
          const bg = document.createElement('div'); bg.className = 'ask-bg';
          bg.innerHTML = `<div class="ask lcode"><h3>รหัสเข้าสู่ระบบ</h3><p class="note">${R(r.email)} · ใช้ได้ถึง ${fmtTs(r.expires_at)} · ครั้งเดียว</p>
            <div class="lcode-n mono">${r.code.slice(0, 3)} ${r.code.slice(3)}</div>
            <p class="note">หน้าเข้าสู่ระบบ → กรอกอีเมล → กด "มีรหัสจากผู้ดูแลแล้ว" → กรอกรหัส<br>หรือเปิดลิงก์นี้ในเครื่องที่จะใช้ (เข้าสู่ระบบทันที)</p>
            <div class="ask-btns"><button type="button" class="cp">คัดลอกลิงก์</button><button type="button" class="ok">ปิด</button></div></div>`;
          document.body.append(bg);
          bg.querySelector('.cp').onclick = async () => { try { await navigator.clipboard.writeText(r.link); toast('คัดลอกลิงก์แล้ว'); } catch (_) { prompt('ลิงก์', r.link); } };
          bg.querySelector('.ok').onclick = () => bg.remove();
        } catch (e) { toast(e.message, true); }
      };
      if (um) um.onclick = async () => {
        const r = await ask({ title: 'เปลี่ยนอีเมลผู้ใช้', text: `${x.name || x.email}\nผู้ใช้จะเข้าสู่ระบบด้วยอีเมลใหม่ (passkey เดิมยังใช้ได้)`, input: { type: 'email', value: x.email, required: true }, ok: 'บันทึก' });
        if (!r || r.value === x.email) return;
        try { await api('admin_email', { id: x.id, email: r.value }); toast('เปลี่ยนอีเมลแล้ว'); showSettings(); } catch (e) { toast(e.message, true); }
      };
      if (ue) ue.onclick = async () => {
        const r = await ask({ title: 'แก้ไขผู้ใช้', text: x.email, input: { value: x.name || x.email.split('@')[0], placeholder: 'ชื่อ' },
          select: self ? null : { options: LEVELS, value: levelOf(x) },
          check: self ? null : { label: 'พนักงานคนนี้ยกเลิกการจองได้ (เช่น คนประสานงาน) — ผู้ดูแลยกเลิกได้อยู่แล้ว', checked: !!Number(x.can_cancel) }, ok: 'บันทึก' });
        if (!r) return;
        try {
          await api('admin_save', { email: x.email, name: r.value, level: self ? 'super' : r.select, active: !!x.active, ...(self ? {} : { can_cancel: !!r.checked }) });
          if (self) me.name = r.value || null;
          toast('บันทึกแล้ว'); showSettings();
        } catch (e) { toast(e.message, true); }
      };
      if (ut) ut.onclick = async () => {
        if (x.active && !await ask({ title: 'ปิดบัญชีนี้?', text: `${x.email} จะเข้าหลังบ้านและหน้าสแกนไม่ได้ (เปิดกลับได้ภายหลัง)`, ok: 'ปิดบัญชี', danger: true })) return;
        try { await api('admin_save', { email: x.email, level: levelOf(x), active: !x.active }); showSettings(); } catch (e) { toast(e.message, true); }
      };
    });
  }

  // ---------- passkey ----------
  // หลังเข้าสู่ระบบด้วยอีเมล: ชวนตั้ง Face ID ครั้งเดียวต่อเครื่อง (ไม่เอา = ไม่ถามอีก, ตั้งทีหลังได้ที่เมนู "บัญชี")
  async function offerPasskey() {
    if (!PK || window.Passkey.hasOnDevice() || window.Passkey.asked()) return;
    PK.prepRegister();
    const ok = await ask({ title: 'ใช้ Face ID / ลายนิ้วมือ ครั้งหน้า?', text: 'เข้าสู่ระบบเครื่องนี้ได้ทันที ไม่ต้องรอรหัสทางอีเมล\n(ตั้งหรือลบได้ภายหลังที่เมนู "บัญชี")', ok: 'ตั้งค่าเลย', cancel: 'ไว้ทีหลัง' });
    window.Passkey.setAsked();
    if (!ok) return;
    try { await PK.register(); toast('ตั้งค่า Face ID / ลายนิ้วมือแล้ว'); if (location.hash === '#/account') route(); }
    catch (x) { const t = window.Passkey.errorText(x); if (t) toast(t === 'กรุณาแตะอีกครั้ง' ? 'ตั้งค่าได้ที่เมนู "บัญชี"' : t, true); }
  }

  // ระดับผู้ใช้: ผู้ดูแลสูงสุด (จัดการผู้ใช้ได้) / ผู้ดูแล / พนักงาน — ผู้ดูแลทั่วไปเห็นผู้ดูแลทุกคนเป็น "ผู้ดูแล"
  const LEVELS = [['staff', 'พนักงาน — สแกน + ดูรายชื่อ'], ['admin', 'ผู้ดูแล — ทำได้ทุกอย่าง ยกเว้นจัดการผู้ใช้'], ['super', 'ผู้ดูแลสูงสุด — ทำได้ทุกอย่าง + จัดการผู้ใช้']];
  const levelOf = x => x.role !== 'admin' ? 'staff' : (x.super ? 'super' : 'admin');
  const roleTag = x => { const l = levelOf(x); return `<span class="tag ${l === 'staff' ? 'few' : 'open'}">${l === 'super' ? 'ผู้ดูแลสูงสุด' : l === 'admin' ? 'ผู้ดูแล' : 'พนักงาน'}</span>` + (l === 'staff' && Number(x.can_cancel) ? '<span class="tag few">ยกเลิกการจองได้</span>' : ''); };

  // ---------- บริการภายนอก (ผู้ดูแลสูงสุด): Turnstile กันบอท + Ghost จดหมายข่าว ----------
  async function renderIntegrations() {
    const box = $('intPanel'); const j = await api('integrations'); const v = j.values;
    const secret = (name, set, ph) => `<input type="password" name="${name}" autocomplete="off" placeholder="${set ? 'ตั้งไว้แล้ว — เว้นว่างเพื่อใช้ค่าเดิม' : ph}">`;
    // แถวปุ่มของแต่ละบริการ: ทดสอบการเชื่อมต่อ (ซ้าย) · ลบค่า (ขวา)
    const acts = (test, name, set) => `<div class="int-act"><button type="button" class="ghost sm" data-test="${test}">ทดสอบการเชื่อมต่อ</button>${set ? `<button type="button" class="linkbtn" data-clear="${name}">ลบค่า</button>` : ''}</div>`;
    box.innerHTML = `<h3>บริการภายนอก</h3><form id="intf">
      <div class="srow2 stack"><span class="sl">Cloudflare Turnstile — กันบอทในหน้าจอง<small>dash.cloudflare.com → Turnstile → Add widget (โดเมนของหน้าจอง) · ว่างทั้งคู่ = ปิด</small></span>
        <input type="text" name="turnstile_site" value="${esc(v.turnstile_site || '')}" placeholder="Site key">${secret('turnstile_secret', v.turnstile_secret, 'Secret key')}${acts('turnstile', 'turnstile_secret', v.turnstile_secret)}</div>
      <div class="srow2 stack"><span class="sl">Ghost — ส่งรายชื่อคนที่ติ๊กรับข่าวสาร<small>Ghost Admin → Settings → Integrations → Add custom integration → Admin API key · ว่าง = ปิด</small></span>
        <input type="text" name="ghost_url" value="${esc(v.ghost_url || '')}" placeholder="https://example.org">${secret('ghost_admin_key', v.ghost_admin_key, 'Admin API key (id:secret)')}${acts('ghost', 'ghost_admin_key', v.ghost_admin_key)}
        ${v.ghost_admin_key ? '<button type="button" class="ghost sm gbf" id="ghostBackfill">ส่งรายชื่อรับข่าวสารย้อนหลัง</button>' : ''}</div>
      <div class="actions"><button class="primary">บันทึก</button></div>
      <p class="note">ค่าลับเก็บในระบบ ไม่แสดงกลับมาหลังบันทึก · กดบันทึกก่อนแล้วค่อยทดสอบ</p></form>`;
    if ($('ghostBackfill')) $('ghostBackfill').onclick = async () => {
      try {
        const d = await api('ghost_backfill', { dry: 1 });
        if (!d.count) { toast('ไม่มีรายชื่อที่ติ๊กรับข่าวสาร'); return; }
        // แสดงรายชื่อทั้งหมดก่อนส่ง
        const bg = document.createElement('div'); bg.className = 'ask-bg';
        bg.innerHTML = `<div class="ask gbf-box"><h3>ส่งรายชื่อย้อนหลังเข้า Ghost?</h3>
          <p class="note">${d.count} อีเมล ที่เคยติ๊กรับข่าวสารตอนจอง (รวมที่นำเข้าจาก Pretix) · ติดป้าย visit-booking-backfill · อีเมลที่มีใน Ghost แล้วจะไม่ถูกแก้${d.done_at ? ` · เคยส่งเมื่อ ${fmtTs(d.done_at)}` : ''}</p>
          <ol class="gbf-list">${d.list.map(r => `<li><b>${R(r.name || '–')}</b><small>${R(r.email)} · ${r.lang === 'th' ? 'ไทย' : 'EN'} · จอง ${R(fmtDate(String(r.at).slice(0, 10)))}</small></li>`).join('')}</ol>
          <div class="ask-btns"><button type="button" class="no">ยกเลิก</button><button type="button" class="ok">ส่ง ${d.count} รายชื่อ</button></div></div>`;
        document.body.append(bg);
        bg.querySelector('.no').onclick = () => bg.remove();
        // ส่งทีละ 5 รายชื่อ: แสดงความคืบหน้า และผลของแต่ละคนในรายการ (เพิ่มใหม่ / มีอยู่แล้ว / ไม่สำเร็จ)
        const TAG = { new: ['open', 'เพิ่มใหม่'], exists: ['few', 'มีอยู่แล้ว'], fail: ['full', 'ไม่สำเร็จ'] };
        bg.querySelector('.ok').onclick = async () => {
          const ok = bg.querySelector('.ok'), no = bg.querySelector('.no'); ok.disabled = true; no.disabled = true;
          const lis = [...bg.querySelectorAll('.gbf-list li')], emails = d.list.map(r => r.email), sum = { new: 0, exists: 0, fail: 0 };
          for (let i = 0; i < emails.length; i += 5) {
            ok.textContent = `กำลังส่ง ${Math.min(i + 5, emails.length)} / ${emails.length}…`;
            let res = [];
            try { res = (await api('ghost_backfill', { emails: emails.slice(i, i + 5) })).results; }
            catch (x) { res = emails.slice(i, i + 5).map(e => ({ email: e, result: 'fail' })); }
            res.forEach(r => { sum[r.result] = (sum[r.result] || 0) + 1; const li = lis[emails.indexOf(r.email)]; if (li) { li.querySelector('b').insertAdjacentHTML('beforeend', ` <span class="tag ${TAG[r.result][0]}">${TAG[r.result][1]}</span>`); li.scrollIntoView({ block: 'nearest' }); } });
          }
          bg.querySelector('h3').textContent = 'ส่งเสร็จแล้ว';
          bg.querySelector('.note').textContent = `เพิ่มใหม่ ${sum.new} · มีอยู่แล้ว ${sum.exists}` + (sum.fail ? ` · ไม่สำเร็จ ${sum.fail}` : '');
          ok.remove(); no.disabled = false; no.textContent = 'ปิด';
        };
      } catch (x) { toast(x.message, true); }
    };
    box.querySelectorAll('[data-test]').forEach(b => b.onclick = async () => {
      b.disabled = true;
      try { const r = (await api('integration_test', { which: b.dataset.test })).result; await ask({ title: r.ok ? '✓ ใช้ได้' : '✗ ยังใช้ไม่ได้', text: r.msg, ok: 'ตกลง', cancel: 'ปิด' }); }
      catch (x) { toast(x.message, true); } finally { b.disabled = false; }
    });
    box.querySelectorAll('[data-clear]').forEach(b => b.onclick = async () => {
      if (!await ask({ title: 'ลบค่านี้?', text: 'บริการนี้จะถูกปิดจนกว่าจะใส่ค่าใหม่', ok: 'ลบ', danger: true })) return;
      try { await api('integrations', { clear: [b.dataset.clear] }); toast('ลบแล้ว'); renderIntegrations(); } catch (x) { toast(x.message, true); }
    });
    $('intf').onsubmit = async e => {
      e.preventDefault(); const f = e.target;
      try { await api('integrations', { values: { turnstile_site: f.turnstile_site.value, turnstile_secret: f.turnstile_secret.value, ghost_url: f.ghost_url.value, ghost_admin_key: f.ghost_admin_key.value } }); toast('บันทึกแล้ว'); renderIntegrations(); }
      catch (x) { toast(x.message, true); }
    };
  }

  // ---------- ใบรับรอง Apple Wallet: ดูวันหมดอายุ · สร้าง CSR · อัปใบรับรองใหม่ (ผู้ดูแลสูงสุด) ----------
  // รูปแบบรอบประจำ — วันในสัปดาห์ × สัปดาห์ของเดือน × เวลา (ระบบสร้างรอบล่วงหน้าตามนี้; รอบเดิมไม่ถูกแตะ)
  const DOW_TH = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
  async function renderPattern() {
    const box = $('patternPanel');
    let p = (await api('pattern')).pattern;
    const draw = () => {
      box.innerHTML = `<h3>รูปแบบรอบ</h3><p class="note">ระบบสร้างรอบล่วงหน้าตามรูปแบบนี้ · รอบที่มีอยู่แล้วและการจองไม่ถูกแตะ</p>
        <div class="srow2 stack"><span class="sl">วัน</span><div class="chips">${DOW_TH.map((d, i) => `<label class="pchip"><input type="checkbox" data-pd="${i}" ${p.days.includes(i) ? 'checked' : ''}><span>${d}</span></label>`).join('')}</div></div>
        <div class="srow2 stack"><span class="sl">สัปดาห์ของเดือน<small>ไม่เลือกเลย = ทุกสัปดาห์</small></span><div class="chips">${[1, 2, 3, 4, 5, -1].map(w => `<label class="pchip"><input type="checkbox" data-pw="${w}" ${p.weeks.includes(w) ? 'checked' : ''}><span>${w === -1 ? 'สุดท้ายของเดือน' : 'ที่ ' + w}</span></label>`).join('')}</div></div>
        <div class="srow2 stack"><span class="sl">เวลารอบ</span><div class="ptimes">${p.times.map(([a, b], i) => `<div class="ptime"><input type="time" value="${a}" data-pt="${i}" data-k="0"> – <input type="time" value="${b}" data-pt="${i}" data-k="1">${p.times.length > 1 ? `<button type="button" class="linkbtn" data-prm="${i}">ลบ</button>` : ''}</div>`).join('')}
          <button type="button" class="ghost sm" id="pAdd">+ เพิ่มรอบเวลา</button></div></div>
        <div id="pPrev" class="note"></div>
        <div class="actions"><button type="button" class="primary" id="pSave">บันทึกรูปแบบรอบ</button></div>`;
      box.querySelectorAll('[data-pd]').forEach(c => c.onchange = () => { const d = Number(c.dataset.pd); p.days = c.checked ? [...p.days, d] : p.days.filter(x => x !== d); preview(); });
      box.querySelectorAll('[data-pw]').forEach(c => c.onchange = () => { const w = Number(c.dataset.pw); p.weeks = c.checked ? [...p.weeks, w] : p.weeks.filter(x => x !== w); preview(); });
      box.querySelectorAll('[data-pt]').forEach(i => i.onchange = () => { p.times[Number(i.dataset.pt)][Number(i.dataset.k)] = i.value; preview(); });
      box.querySelectorAll('[data-prm]').forEach(b => b.onclick = () => { p.times.splice(Number(b.dataset.prm), 1); draw(); });
      $('pAdd').onclick = () => { p.times.push(['10:00', '12:00']); draw(); };
      $('pSave').onclick = save;
      preview();
    };
    let last = null;
    const preview = async () => {
      const el = $('pPrev');
      try {
        const r = last = await api('pattern', { pattern: p });
        el.innerHTML = `จะสร้างรอบใหม่ <b>${r.would_create}</b> รอบ (ล่วงหน้า ${r.months} เดือน)`
          + (r.next.length ? `<br>รอบถัดไปตามรูปแบบ: ${r.next.map(x => fmtTs(x)).join(' · ')}` : '<br>ไม่มีรอบตามรูปแบบนี้ในช่วงล่วงหน้า')
          + (r.off.length ? `<br><span class="err">มีรอบที่เปิดอยู่ ${r.off.length} รอบที่ไม่ตรงรูปแบบนี้ (จองแล้ว ${r.off.reduce((a, x) => a + x.booked, 0)} คน) — ระบบไม่ลบให้ ปิดเองได้ในปฏิทินถ้าไม่ต้องการ</span>` : '');
      } catch (x) { last = null; el.innerHTML = `<span class="err">${R(x.message)}</span>`; }
    };
    const save = async () => {
      await preview();
      if (!last) return;
      if (!await ask({ title: 'บันทึกรูปแบบรอบ?', text: `ระบบจะสร้างรอบใหม่ ${last.would_create} รอบตอนนี้` + (last.off.length ? `\nรอบเดิมที่ไม่ตรงรูปแบบ ${last.off.length} รอบยังเปิดอยู่ (ปิดเองได้)` : ''), ok: 'บันทึก' })) return;
      try { const r = await api('pattern', { pattern: p, op: 'save' }); toast(`บันทึกแล้ว · สร้างรอบใหม่ ${r.created} รอบ`); p = r.pattern; draw(); } catch (x) { toast(x.message, true); }
    };
    draw();
  }

  // ข้อมูลสถานที่ — ใช้ทั้งหน้าเว็บ อีเมล ตั๋ว PDF และ Wallet (ว่าง = ค่าเริ่มต้น)
  const VENUE_F = [['name_th', 'ชื่อสถานที่ (ไทย)'], ['name_en', 'ชื่อสถานที่ (อังกฤษ)'], ['address_th', 'ที่อยู่ (ไทย)'], ['address_en', 'ที่อยู่ (อังกฤษ)'],
    ['maps', 'ลิงก์แผนที่ (Google Maps)', 'url'], ['email', 'อีเมลติดต่อ', 'email'], ['group_email', 'อีเมลนัดหมายหมู่คณะ', 'email'], ['phone', 'เบอร์โทรติดต่อ', 'tel'], ['website', 'เว็บไซต์', 'url'], ['privacy', 'ลิงก์นโยบายความเป็นส่วนตัว', 'url']];
  async function renderVenue() {
    const box = $('venuePanel'); const j = await api('venue');
    box.innerHTML = `<h3>ข้อมูลสถานที่</h3><p class="note">ใช้ในหน้าจอง หน้าการจองของฉัน อีเมล ตั๋ว PDF และ Apple/Google Wallet · เว้นว่าง = ใช้ค่าเดิม</p>
      <form id="venueForm">${VENUE_F.map(([k, l, type]) => `<div class="srow2 stack"><span class="sl">${l}</span>
        ${k.startsWith('address') ? `<textarea class="raw" name="${k}" rows="2" placeholder="${esc(j.defaults[k])}">${esc(j.values[k] || '')}</textarea>` : `<input class="raw" type="${type || 'text'}" name="${k}" value="${esc(j.values[k] || '')}" placeholder="${esc(j.defaults[k])}">`}</div>`).join('')}
        <div class="srow2"><span class="sl">โลโก้<small>PNG หรือ JPG ไม่เกิน 600 KB · ใช้บนหน้าเว็บและอีเมล</small></span>
          <span class="vlogo"><img src="${esc(j.logo)}" alt="" height="48"><label class="ghost sm btn">เปลี่ยนโลโก้<input type="file" id="vLogo" accept="image/png,image/jpeg" hidden></label>${j.custom_logo ? '<button type="button" class="linkbtn" id="vLogoReset">ใช้โลโก้เดิม</button>' : ''}</span></div>
        <div class="actions"><button class="primary">บันทึก</button></div></form>`;
    $('venueForm').onsubmit = async e => {
      e.preventDefault();
      const values = Object.fromEntries(VENUE_F.map(([k]) => [k, e.target[k].value]));
      try { await api('venue', { values }); toast('บันทึกแล้ว'); renderVenue(); } catch (x) { toast(x.message, true); }
    };
    $('vLogo').onchange = async e => {
      const f = e.target.files[0]; if (!f) return;
      const buf = new Uint8Array(await f.arrayBuffer()); let bin = ''; buf.forEach(b => { bin += String.fromCharCode(b); });
      try { await api('venue', { logo: btoa(bin) }); toast('เปลี่ยนโลโก้แล้ว'); renderVenue(); } catch (x) { toast(x.message, true); e.target.value = ''; }
    };
    if ($('vLogoReset')) $('vLogoReset').onclick = async () => { if (!await ask({ title: 'ใช้โลโก้เดิม?', ok: 'ใช้โลโก้เดิม' })) return; try { await api('venue', { logo: '' }); renderVenue(); } catch (x) { toast(x.message, true); } };
  }

  // งานรายชั่วโมง (Plesk Scheduled Task) — สถานะล่าสุด + ลิงก์พร้อมคีย์สำหรับตั้งใหม่
  async function renderCron() {
    const box = $('cronPanel'); let c;
    try { c = await api('cron_status'); } catch (_) { box.remove(); return; }
    box.innerHTML = `<h3>งานอัตโนมัติรายชั่วโมง</h3>
      <div class="srow2"><span class="sl">ทำงานล่าสุด<small>อีเมลเตือน · ยกเลิกการจองที่ไม่ยืนยัน · สรุปจำนวนคน · เตือนใบรับรองหมดอายุ</small></span>
        <span class="tag ${c.healthy ? 'open' : 'full'}">${c.last ? fmtTs(c.last) : 'ยังไม่เคยทำงาน'}</span></div>
      ${c.healthy ? '' : '<p class="note">ไม่ได้ทำงานเกิน 2 ชั่วโมง — ตรวจใน Plesk ว่างานยังอยู่ (ระหว่างนี้ระบบยังทำงานเมื่อมีคนเปิดหน้าจอง)</p>'}
      ${c.url ? `<div class="srow2 stack"><span class="sl">ลิงก์สำหรับ Plesk<small>Plesk → Websites &amp; Domains → Scheduled Tasks → Add Task → Fetch a URL → วางลิงก์นี้ → Run: Hourly · ลิงก์มีคีย์ลับ ห้ามเผยแพร่</small></span>
        <div class="int-act"><code class="cron-url" id="cronUrl" hidden>${R(c.url)}</code><button type="button" class="ghost sm" id="cronShow">แสดงลิงก์</button><button type="button" class="ghost sm" id="cronCopy">คัดลอกลิงก์</button></div></div>` : '<p class="note">ยังไม่ได้ตั้ง cron_key ใน config บนโฮสต์</p>'}`;
    if (!c.url) return;
    $('cronShow').onclick = () => { const u = $('cronUrl'); u.hidden = !u.hidden; $('cronShow').textContent = u.hidden ? 'แสดงลิงก์' : 'ซ่อนลิงก์'; };
    $('cronCopy').onclick = async () => { try { await navigator.clipboard.writeText(c.url); toast('คัดลอกลิงก์แล้ว'); } catch (_) { $('cronUrl').hidden = false; } };
  }

  async function renderWalletCert() {
    const box = $('walletPanel'); const w = await api('wallet_status');
    const warn = w.days_left !== null && w.days_left <= 60;
    box.innerHTML = `<h3>ใบรับรอง Apple Wallet</h3>
      <div class="srow2"><span class="sl">สถานะ<small>${R(w.pass_type_id || '–')} · Team ${R(w.team_id || '–')}</small></span>
        <span class="tag ${!w.enabled ? 'off' : warn ? 'full' : 'open'}">${!w.enabled ? 'ยังไม่ได้ตั้งค่า' : w.days_left < 0 ? 'หมดอายุแล้ว' : `หมดอายุ ${fmtDate(w.expires_at.slice(0, 10))} · เหลือ ${w.days_left} วัน`}</span></div>
      <div class="srow2 stack"><span class="sl">ต่ออายุใบรับรอง<small>1) กด "สร้างไฟล์ CSR" → 2) developer.apple.com → Certificates → + → Pass Type ID Certificate → เลือก ${R(w.pass_type_id || 'Pass Type ID เดิม')} → อัปไฟล์ CSR → ดาวน์โหลด pass.cer → 3) กด "อัปใบรับรองใหม่"</small></span>
        <div class="int-act"><button type="button" class="ghost sm" id="wCsr">สร้างไฟล์ CSR${w.pending_csr ? ' (ใช้อันเดิม)' : ''}</button>
          <label class="ghost sm btn wup">อัปใบรับรองใหม่ (.cer)<input type="file" id="wCer" accept=".cer,.pem,.crt" hidden></label></div></div>
      <p class="note">ระบบเตือนผู้ดูแลสูงสุดทางอีเมลและแจ้งเตือนเว็บแอปเมื่อเหลือ 60, 30, 14, 7 และ 1 วัน · ใบรับรองเดิมสำรองไว้บนโฮสต์</p>`;
    $('wCsr').onclick = async () => {
      try {
        const r = await api('wallet_csr');
        const f = new File([r.csr], 'PassTypeID.certSigningRequest', { type: 'application/pkcs10' });
        if (STANDALONE && navigator.canShare && navigator.canShare({ files: [f] })) { await navigator.share({ files: [f] }).catch(() => {}); }
        else { const u = URL.createObjectURL(f), a = document.createElement('a'); a.href = u; a.download = f.name; document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(u), 30000); }
        toast('ดาวน์โหลด CSR แล้ว — นำไปขอใบรับรองที่ developer.apple.com'); renderWalletCert();
      } catch (x) { toast(x.message, true); }
    };
    $('wCer').onchange = async e => {
      const file = e.target.files[0]; if (!file) return;
      const buf = new Uint8Array(await file.arrayBuffer()); let bin = ''; buf.forEach(b => { bin += String.fromCharCode(b); });
      if (!await ask({ title: 'ติดตั้งใบรับรองใหม่?', text: `${file.name}\nตั๋ว Apple Wallet ที่ออกหลังจากนี้จะใช้ใบรับรองใหม่ (ตั๋วที่ลูกค้าเพิ่มไว้แล้วยังใช้ได้)`, ok: 'ติดตั้ง' })) { e.target.value = ''; return; }
      try { const r = await api('wallet_cert_upload', { data: btoa(bin) }); toast('ติดตั้งแล้ว · หมดอายุ ' + fmtDate(r.expires_at.slice(0, 10))); renderWalletCert(); refreshWalletBanner(); }
      catch (x) { toast(x.message, true); e.target.value = ''; }
    };
  }
  // แถบเตือนบนหลังบ้าน (ผู้ดูแลสูงสุด) เมื่อใบรับรองเหลือ ≤ 60 วัน
  async function refreshWalletBanner() {
    if (!me || !me.super) return;
    let w; try { w = await api('wallet_status'); } catch (_) { return; }
    let bar = document.getElementById('walletbar');
    const show = w.enabled && w.days_left !== null && w.days_left <= 60;
    if (!show) { if (bar) bar.remove(); return; }
    if (!bar) { bar = document.createElement('a'); bar.id = 'walletbar'; bar.className = 'testbar walletbar'; bar.href = '#/settings'; $('testbar').after(bar); }
    bar.textContent = w.days_left < 0 ? 'ใบรับรอง Apple Wallet หมดอายุแล้ว — ต่ออายุที่ ตั้งค่า → ใบรับรอง Apple Wallet' : `ใบรับรอง Apple Wallet จะหมดอายุในอีก ${w.days_left} วัน — ต่ออายุที่ ตั้งค่า → ใบรับรอง Apple Wallet`;
  }

  // ---------- แชร์หน้าจอง: ลิงก์ + QR ----------
  function showShare() {
    const url = new URL('../', location.href).href;
    view.innerHTML = `<h2>แชร์หน้าจอง</h2>
      <section class="panel share">
        <img class="share-qr" src="../api/admin.php?action=qr" alt="QR" width="240" height="240">
        <p class="share-url">${R(url)}</p>
        <div class="actions share-btns">
          <button class="primary" id="shCopy">คัดลอกลิงก์</button>
          ${navigator.share ? '<button class="ghost" id="shShare">แชร์…</button>' : ''}
          <a class="ghost btn" href="../api/admin.php?action=qr&dl=1" download="booking-qr.png">บันทึกรูป QR</a>
          <a class="ghost btn" href="${esc(url)}" target="_blank" rel="noopener">เปิดหน้าจอง</a>
        </div>
      </section>
      <section class="panel"><h3>ลิงก์แยกช่องทาง</h3>
        <p class="note">ไม่ใช้ก็ได้ — ระบบตรวจเองเมื่อเปิดจากแอป LINE / Facebook / Instagram, จากเว็บอื่น หรือสแกน QR ด้านบน · ลิงก์แยกช่วยให้แม่นขึ้นในกรณีที่ตรวจเองไม่ได้ เช่น ลิงก์ในอีเมล หรือคนคัดลอกลิงก์ไปเปิดเอง</p>
        <div class="chlinks">${[['line', 'LINE'], ['facebook', 'Facebook'], ['instagram', 'Instagram'], ['website', 'เว็บไซต์'], ['email', 'อีเมล/จดหมายข่าว'], ['qr', 'QR (โปสเตอร์/ใบปลิว) — QR ด้านบนติดป้ายนี้แล้ว']].map(([k, l]) =>
          `<div class="srow2"><span class="sl">${l}<small>${R(url)}?utm_source=${k}</small></span><button type="button" class="ghost sm" data-chl="${k}">คัดลอก</button></div>`).join('')}</div>
      </section>`;
    view.querySelectorAll('[data-chl]').forEach(b => b.onclick = async () => {
      const u = `${url}?utm_source=${b.dataset.chl}`;
      try { await navigator.clipboard.writeText(u); toast('คัดลอกลิงก์แล้ว'); } catch (_) { ask({ title: 'ลิงก์', text: 'คัดลอกลิงก์ด้านล่าง', input: { value: u }, ok: 'ปิด' }); }
    });
    $('shCopy').onclick = async () => {
      try { await navigator.clipboard.writeText(url); toast('คัดลอกลิงก์แล้ว'); }
      catch (_) { const t = document.createElement('textarea'); t.value = url; document.body.append(t); t.select(); document.execCommand('copy'); t.remove(); toast('คัดลอกลิงก์แล้ว'); }
    };
    if ($('shShare')) $('shShare').onclick = () => navigator.share({ url }).catch(() => {});
  }

  // ---------- แจ้งเตือนในเว็บแอป (Web Push) — ขอสิทธิ์เฉพาะตอนเปิดเป็นแอปจากหน้าจอโฮม (ผู้ใช้กำหนด) ----------
  const STANDALONE = navigator.standalone === true || matchMedia('(display-mode: standalone)').matches || new URLSearchParams(location.search).has('app');   // ?app = ทดสอบโหมดแอปในเบราว์เซอร์
  const PUSH_OK = STANDALONE && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  const swReg = PUSH_OK ? navigator.serviceWorker.register('sw.js').catch(() => null) : Promise.resolve(null);
  const b64ToU8 = s => Uint8Array.from(atob((s + '==='.slice((s.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
  async function pushSend(sub) { const j = sub.toJSON(); await api('push_subscribe', { endpoint: j.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth }); }
  async function pushCurrent() { const reg = await swReg; return reg ? reg.pushManager.getSubscription() : null; }
  async function pushEnable() {
    const info = await api('push_info');
    if (!info.supported) throw new Error('เซิร์ฟเวอร์ยังไม่รองรับการแจ้งเตือน');
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') throw new Error('ไม่ได้รับอนุญาต — เปิดได้ที่ การตั้งค่า → การแจ้งเตือน → ระบบจอง');
    const reg = await swReg; if (!reg) throw new Error('เครื่องนี้ไม่รองรับ');
    await navigator.serviceWorker.ready;
    const sub = (await reg.pushManager.getSubscription()) || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToU8(info.key) });
    await pushSend(sub);
  }
  // เปิดแอป: ส่งการสมัครปัจจุบันขึ้นเซิร์ฟเวอร์อีกครั้ง (กันหลุด) + รับข้อความจาก SW เมื่อปลายทางเปลี่ยน
  if (PUSH_OK) {
    navigator.serviceWorker.addEventListener('message', async e => { if (e.data && e.data.type === 'push-resub') { const sub = await pushCurrent(); if (sub && me) pushSend(sub).catch(() => {}); } });
    setTimeout(async () => { if (me && Notification.permission === 'granted') { const sub = await pushCurrent(); if (sub) pushSend(sub).catch(() => {}); } }, 3000);
  }
  // เปิดเป็นแอป → บังคับเปิดการแจ้งเตือนก่อนใช้งาน (ผู้ใช้กำหนด) — iOS ต้องให้ผู้ใช้แตะเองจึงขอสิทธิ์ได้ เลยเป็นหน้าต่างที่มีปุ่มเดียว
  let pushGateShown = false;
  async function pushGate() {
    if (!PUSH_OK || pushGateShown || !me) return;
    if (Notification.permission === 'granted' && await pushCurrent()) return;
    pushGateShown = true;
    const denied = Notification.permission === 'denied';
    const bg = document.createElement('div'); bg.className = 'ask-bg pgate';
    bg.innerHTML = `<div class="ask"><h3>เปิดการแจ้งเตือน</h3>
      <p>${denied ? 'การแจ้งเตือนของแอปนี้ถูกปิดไว้ — เปิดได้ที่ การตั้งค่า → การแจ้งเตือน → ระบบจอง แล้วปิดแอปและเปิดใหม่' : 'ระบบจองต้องเปิดการแจ้งเตือน เพื่อแจ้งเมื่อมีคนจองหรือยกเลิก และสรุปจำนวนผู้เข้าชมล่วงหน้า (เลือกเรื่องที่จะแจ้งได้ที่ เพิ่มเติม → บัญชี)'}</p>
      <p class="err" hidden></p>
      <div class="ask-btns">${denied ? '<button type="button" class="later">ข้ามครั้งนี้</button>' : ''}<button type="button" class="ok">${denied ? 'ตรวจอีกครั้ง' : 'เปิดการแจ้งเตือน'}</button></div></div>`;
    document.body.append(bg);
    const later = bg.querySelector('.later'); if (later) later.onclick = () => bg.remove();
    bg.querySelector('.ok').onclick = async () => {
      if (denied) { location.reload(); return; }
      try { await pushEnable(); bg.remove(); toast('เปิดการแจ้งเตือนแล้ว'); }
      catch (e) { const er = bg.querySelector('.err'); er.textContent = e.message; er.hidden = false; if (Notification.permission === 'denied') { bg.remove(); pushGateShown = false; pushGate(); } }
    };
  }
  const PUSH_EV = [['booked', 'จองใหม่'], ['canceled', 'ยกเลิกการจอง'], ['ticket_canceled', 'ยกเลิกบางคน'], ['edited', 'ผู้จองแก้ไขข้อมูล'], ['restored', 'คืนการจอง'], ['waitlist', 'ลงชื่อรอ (รอบเต็ม)']];
  const PUSH_SUM = [[0, 'วันนั้น'], [1, 'ก่อน 1 วัน'], [2, 'ก่อน 2 วัน'], [3, 'ก่อน 3 วัน']];
  async function renderPush() {
    const box = $('pushPanel'); if (!box) return;
    const info = await api('push_info');
    const sub = PUSH_OK ? await pushCurrent() : null;
    const on = !!sub && Notification.permission === 'granted';
    const p = info.prefs;
    box.innerHTML = `<h3>การแจ้งเตือน</h3>
      ${PUSH_OK ? `<div class="srow2"><span class="sl">เครื่องนี้<small>${on ? 'เปิดการแจ้งเตือนอยู่' : 'ยังไม่ได้เปิด'}</small></span>
          ${on ? '<span class="pbtns"><button type="button" class="ghost sm" id="pTest">ส่งทดสอบ</button><button type="button" class="linkbtn" id="pOff">ปิด</button></span>' : '<button type="button" class="primary sm" id="pOn">เปิดการแจ้งเตือน</button>'}</div>`
        : `<p class="note">เปิดการแจ้งเตือนได้เมื่อใช้เป็นแอป — Safari: ปุ่มแชร์ → เพิ่มไปยังหน้าจอโฮม แล้วเปิดจากไอคอน "ระบบจอง"${info.devices ? ` · ตอนนี้เปิดไว้ ${info.devices} เครื่อง` : ''}</p>`}
      <form id="pf">
        <div class="srow2 stack"><span class="sl">แจ้งเมื่อ</span><div class="chips">${PUSH_EV.map(([k, l]) => `<label class="pchip"><input type="checkbox" name="ev" value="${k}" ${p.events.includes(k) ? 'checked' : ''}><span>${l}</span></label>`).join('')}</div></div>
        <div class="srow2 stack"><span class="sl">สรุปจำนวนผู้เข้าชม<small>ส่งเฉพาะวันที่มีรอบเปิด</small></span><div class="chips">${PUSH_SUM.map(([d, l]) => `<label class="pchip"><input type="checkbox" name="sum" value="${d}" ${p.summary.includes(d) ? 'checked' : ''}><span>${l}</span></label>`).join('')}</div>
          <label class="inline">เวลา <select name="hour">${Array.from({ length: 18 }, (_, i) => i + 5).map(h => `<option value="${h}" ${h === p.hour ? 'selected' : ''}>${String(h).padStart(2, '0')}:00</option>`).join('')}</select></label></div>
        <div class="actions"><button class="primary">บันทึก</button></div>
        <p class="note">ตั้งค่านี้ใช้กับทุกเครื่องที่คุณเปิดการแจ้งเตือนไว้</p></form>`;
    if ($('pOn')) $('pOn').onclick = async () => { try { await pushEnable(); toast('เปิดการแจ้งเตือนแล้ว'); renderPush(); } catch (e) { toast(e.message, true); } };
    if ($('pOff')) $('pOff').onclick = async () => { const s2 = await pushCurrent(); if (s2) { await api('push_unsubscribe', { endpoint: s2.endpoint }).catch(() => {}); await s2.unsubscribe().catch(() => {}); } toast('ปิดการแจ้งเตือนบนเครื่องนี้แล้ว'); renderPush(); };
    if ($('pTest')) $('pTest').onclick = async () => { try { const r = await api('push_test'); toast(r.sent ? `ส่งแล้ว ${r.sent} เครื่อง` : 'ส่งไม่สำเร็จ', !r.sent); } catch (e) { toast(e.message, true); } };
    $('pf').onsubmit = async e => {
      e.preventDefault(); const f = e.target;
      try {
        await api('push_prefs', { events: [...f.querySelectorAll('[name=ev]:checked')].map(x => x.value), summary: [...f.querySelectorAll('[name=sum]:checked')].map(x => Number(x.value)), hour: Number(f.hour.value) });
        toast('บันทึกแล้ว');
      } catch (x) { toast(x.message, true); }
    };
  }

  // ---------- เครื่องที่ล็อกอินอยู่ ----------
  const uaLabel = ua => {
    const dev = /iPhone/.test(ua) ? 'iPhone' : /iPad/.test(ua) ? 'iPad' : /Android/.test(ua) ? 'Android' : /Macintosh/.test(ua) ? 'Mac' : /Windows/.test(ua) ? 'Windows' : /Linux/.test(ua) ? 'Linux' : 'อุปกรณ์';
    const app = /iPhone|iPad/.test(ua) && !/Safari\//.test(ua) ? 'แอปหน้าจอโฮม' : /Edg\//.test(ua) ? 'Edge' : /CriOS|Chrome\//.test(ua) ? 'Chrome' : /FxiOS|Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : '';
    return dev + (app ? ' · ' + app : '');
  };
  const sessionsHtml = list => `<div class="slist">${list.map(x => `<div class="srow-s"><div><b>${R(uaLabel(x.ua))}</b>${x.current ? ' <span class="tag open">เครื่องนี้</span>' : ''}
      <small>ใช้ล่าสุด ${fmtTs(x.last_seen || x.created_at)} · ล็อกอินเมื่อ ${fmtTs(x.created_at)}${x.ip ? ' · IP ' + R(x.ip) : ''}</small></div>
      ${x.current ? '' : `<button type="button" class="linkbtn" data-srv="${x.id}">ออกจากระบบ</button>`}</div>`).join('') || '<p class="note">ไม่มี</p>'}</div>`;
  async function bindSessions(box, adminId, reload) {
    box.querySelectorAll('[data-srv]').forEach(b => b.onclick = async () => {
      if (!await ask({ title: 'ออกจากระบบเครื่องนี้?', text: 'เครื่องนั้นต้องล็อกอินใหม่', ok: 'ออกจากระบบ', danger: true })) return;
      try { await api('sessions_revoke', { id: b.dataset.srv, admin_id: adminId }); toast('ออกจากระบบแล้ว'); reload(); } catch (x) { toast(x.message, true); }
    });
    const all = box.querySelector('[data-srvall]');
    if (all) all.onclick = async () => {
      if (!await ask({ title: adminId === undefined ? 'ออกจากระบบเครื่องอื่นทั้งหมด?' : 'ออกจากระบบทุกเครื่องของผู้ใช้นี้?', text: 'เครื่องเหล่านั้นต้องล็อกอินใหม่', ok: 'ออกจากระบบ', danger: true })) return;
      try { const r = await api('sessions_revoke', { others: true, admin_id: adminId }); toast(`ออกจากระบบ ${r.removed} เครื่อง`); reload(); } catch (x) { toast(x.message, true); }
    };
  }
  async function renderMySessions() {
    const box = $('sessPanel'); if (!box) return;
    const j = await api('sessions_list');
    box.innerHTML = `<h3>เครื่องที่ล็อกอินอยู่ (${j.sessions.length})</h3>${sessionsHtml(j.sessions)}
      ${j.sessions.length > 1 ? '<div class="actions"><button type="button" class="ghost sm" data-srvall>ออกจากระบบเครื่องอื่นทั้งหมด</button></div>' : ''}`;
    bindSessions(box, undefined, renderMySessions);
  }
  async function showUserSessions(x) {
    const j = await api('sessions_list', { admin_id: x.id });
    const bg = document.createElement('div'); bg.className = 'ask-bg';
    bg.innerHTML = `<div class="ask gbf-box"><h3>เครื่องที่ล็อกอิน — ${R(x.name || x.email)}</h3>${sessionsHtml(j.sessions)}
      <div class="ask-btns">${j.sessions.length ? '<button type="button" class="danger-l" data-srvall>ออกจากระบบทุกเครื่อง</button>' : ''}<button type="button" class="ok">ปิด</button></div></div>`;
    document.body.append(bg);
    bg.querySelector('.ok').onclick = () => bg.remove();
    bindSessions(bg, x.id, () => { bg.remove(); showUserSessions(x); });
  }

  async function showAccount() {
    const j = await api('passkeys');
    view.innerHTML = `<h2>บัญชี</h2>
      <section class="panel acct"><span class="uav lg">${esc((me.name || me.email).slice(0, 1).toUpperCase())}</span>
        <div class="acct-main"><div class="acct-name"><b>${R(me.name || me.email.split('@')[0])}</b>
          <button type="button" class="iconbtn" id="meName" aria-label="แก้ไขชื่อ" title="แก้ไขชื่อ"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/></svg></button></div>
          <div class="acct-mail">${R(me.email)} <button type="button" class="linkbtn sm" id="meEmail">เปลี่ยนอีเมล</button></div>${roleTag(me)}</div></section>
      <section class="panel"${AUTH.passkey === false ? ' hidden' : ''}><h3>Face ID / ลายนิ้วมือ (Passkey)</h3>
        <p class="note">เข้าสู่ระบบได้ทันทีโดยไม่ต้องรอรหัสทางอีเมล ใช้ได้ทั้งหลังบ้านและหน้าสแกน · passkey ใน iCloud Keychain / Google Password Manager ใช้ได้ทุกเครื่องที่ล็อกอินบัญชีเดียวกัน</p>
        ${j.passkeys.length ? `<table class="tbl"><tbody>${j.passkeys.map(p => `<tr><td>${R(p.name)}<br><small>ตั้งเมื่อ ${fmtTs(p.created_at)}${p.last_used_at ? ' · ใช้ล่าสุด ' + fmtTs(p.last_used_at) : ''}</small></td>
          <td class="c-ci"><button class="linkbtn" data-pkdel="${p.id}" data-name="${esc(p.name)}">ลบ</button></td></tr>`).join('')}</tbody></table>` : '<p class="note">ยังไม่ได้ตั้ง</p>'}
        ${PK ? `<div class="actions"><button class="primary" id="pkAdd">${PK_ICON}ตั้งค่าบนเครื่องนี้</button></div>` : '<p class="note">เบราว์เซอร์นี้ไม่รองรับ passkey</p>'}
      </section>
      <section class="panel" id="pushPanel"></section>
      <section class="panel" id="sessPanel"></section>`;
    renderPush(); renderMySessions();
    if (PK) {
      PK.prepRegister();
      $('pkAdd').onclick = async () => {
        try { await PK.register(); toast('ตั้งค่าแล้ว'); showAccount(); }
        catch (x) { const t = window.Passkey.errorText(x); if (t) toast(t, true); }
      };
    }
    $('meName').onclick = async () => {
      const r = await ask({ title: 'แก้ไขชื่อ', input: { value: me.name || me.email.split('@')[0], placeholder: 'ชื่อ' }, ok: 'บันทึก' });
      if (!r) return;
      try { const j2 = await api('me_name', { name: r.value }); me.name = j2.name; toast('บันทึกแล้ว'); showAccount(); } catch (e) { toast(e.message, true); }
    };
    $('meEmail').onclick = async () => {
      const a = await ask({ title: 'เปลี่ยนอีเมล', text: 'อีเมลใช้เข้าสู่ระบบ — ระบบจะส่งรหัสยืนยันไปที่อีเมลใหม่ก่อน', input: { type: 'email', value: '', placeholder: 'อีเมลใหม่', required: true }, ok: 'ส่งรหัส' });
      if (!a) return;
      try { await api('me_email_request', { email: a.value }); } catch (e) { toast(e.message, true); return; }
      for (;;) {
        const c = await ask({ title: 'กรอกรหัสยืนยัน', text: `ส่งรหัส 6 หลักไปที่ ${a.value} แล้ว (ใช้ได้ 10 นาที)`, input: { type: 'text', value: '', placeholder: '000000', required: true }, ok: 'ยืนยัน' });
        if (!c) return;
        try { const r = await api('me_email_verify', { email: a.value, code: c.value }); me.email = r.email; toast('เปลี่ยนอีเมลแล้ว'); showAccount(); return; }
        catch (e) { toast(e.message, true); }
      }
    };
    view.querySelectorAll('[data-pkdel]').forEach(b => b.onclick = async () => {
      if (!await ask({ title: 'ลบ passkey นี้?', text: `${b.dataset.name} จะใช้เข้าสู่ระบบไม่ได้อีก (ใช้อีเมลแทนได้เสมอ)\nควรลบออกจากเครื่องด้วยที่ การตั้งค่า → รหัสผ่าน`, ok: 'ลบ', danger: true })) return;
      await api('passkey_delete', { id: Number(b.dataset.pkdel) }); toast('ลบแล้ว'); showAccount();
    });
  }

  // ---------- router ----------
  // ประวัติการเปิดหน้าในแอป → ปุ่มย้อนกลับ (.crumb ลิงก์แรก) กลับไปหน้าที่มาจริง; ไม่มีประวัติ (เปิดจากลิงก์) = ใช้ลิงก์เดิมของหน้า
  const FORM_PAGES = /^#\/(session-mail|new-session)\b/;   // หน้าฟอร์ม: ส่ง/บันทึกแล้วไม่ต้องย้อนกลับมา
  const navStack = [location.hash || '#/sessions'];
  window.addEventListener('hashchange', () => {
    const h = location.hash || '#/sessions';
    if (h === navStack[navStack.length - 1]) return;
    if (h === navStack[navStack.length - 2]) { navStack.pop(); return; }   // ย้อนกลับ
    if (FORM_PAGES.test(navStack[navStack.length - 1])) navStack.pop();
    navStack.push(h);
    if (navStack.length > 50) navStack.shift();
  });
  const fixBack = () => {
    const a = view.querySelector('.crumb a:first-child');
    if (!a || a.dataset.fixed || a.hasAttribute('data-back-order')) return;   // หน้าแก้ไขการจอง (hash เดิม) กลับไปหน้าการจองเสมอ
    const prev = navStack[navStack.length - 2];
    if (prev) { a.setAttribute('href', prev); a.textContent = 'กลับ'; }
    a.dataset.fixed = '1';
  };
  new MutationObserver(fixBack).observe(view, { childList: true });
  async function route() {
    if (!me) { showLogin(); return; }
    try { localStorage.setItem('vb-admin', '1'); } catch (_) {}   // หน้าจองจะแสดงปุ่ม "หลังบ้าน" มุมล่างขวาในเครื่องนี้
    $('nav').hidden = false;
    document.querySelectorAll('[data-role=admin]').forEach(el => { el.hidden = me.role !== 'admin'; });
    document.querySelectorAll('[data-role=super]').forEach(el => { el.hidden = !me.super; });
    refreshTestbar();
    pushGate();
    if (!window._wbChecked) { window._wbChecked = true; refreshWalletBanner(); }
    const [, page, arg] = (location.hash || '#/sessions').split('/');
    $('moreBtn').classList.toggle('on', ['mail', 'settings', 'account', 'share', 'audit'].includes(page));
    document.querySelectorAll('[data-nav]').forEach(a => a.classList.toggle('on', a.dataset.nav === page || ((page === 'session' || page === 'day' || page === 'session-mail' || page === 'people' || page === 'person' || page === 'new-session') && a.dataset.nav === 'sessions') || (page === 'order' && a.dataset.nav === 'search')));
    try {
      if (page === 'day') await showDay(arg, (location.hash.split('/')[3] || '').split(',').map(Number).filter(Boolean));
      else if (page === 'session') await showSession(Number(arg));
      else if (page === 'search') showSearch(decodeURIComponent(arg || ''));
      else if (page === 'order') await showOrder(arg);
      else if (page === 'settings' && me.role === 'admin') await showSettings();
      else if (page === 'mail' && me.role === 'admin') await showMail();
      else if (page === 'account') await showAccount();
      else if (page === 'share') showShare();
      else if (page === 'stats' && me.role === 'admin') await showStats(arg || '');
      else if (page === 'add-booking') await showAddBooking(Number(arg));
      else if (page === 'texts' && me.role === 'admin') await showTexts(arg || 'I18N_BOOKING');
      else if (page === 'audit' && me.super) await showAudit();
      else if (page === 'activity') await showActivity();
      else if (page === 'new-session' && me.role === 'admin') showSessionForm(arg || '');
      else if (page === 'person') await showPerson(location.hash.slice('#/person/'.length));
      else if (page === 'people') await showPeople(arg, (location.hash.split('/')[3] || ''));
      else if (page === 'session-mail' && me.role === 'admin') await showSessionMail(arg);
      else await showSessions((location.hash.split('/').slice(2).join('/')) || '');
    } catch (x) { if (x.message !== 'auth') view.innerHTML = `<p class="err">${R(x.message)}</p>`; }
  }
  async function refreshTestbar() {
    try { const j = await fetch('../api/slots.php').then(r => r.json()); $('testbar').hidden = !j.test_mode; if (j.test_until) { let u = $('testUntil'); if (!u) { u = document.createElement('span'); u.id = 'testUntil'; $('testbar').append(u); } u.textContent = ` · ปิดเองอัตโนมัติ ${fmtTs(j.test_until)}`; } } catch (_) {}
  }
  // iOS: แตะ 2 ครั้งบนข้อความ/พื้นที่ว่าง (ไม่ใช่ปุ่ม/ช่องกรอก) = ซูม แม้ตั้ง touch-action แล้ว → กันไว้ (pinch ยังใช้ได้ ยกเว้นหน้าสแกน)
  { let lastT = 0; document.addEventListener('touchend', e => { const now = Date.now(); if (now - lastT < 350 && !e.target.closest('a,button,input,select,textarea,label,summary,[role=button],[data-id],.slot,.chip')) e.preventDefault(); lastT = now; }, { passive: false }); }
  window.addEventListener('hashchange', route);
  // มือถือ: "เพิ่มเติม" เปิด/ปิดแผงเมนูรอง
  { const btn = $('moreBtn'), box = $('navMore');
    const close = () => { box.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); };
    btn.onclick = e => { e.stopPropagation(); const o = !box.classList.contains('open'); box.classList.toggle('open', o); btn.setAttribute('aria-expanded', String(o)); };
    document.addEventListener('click', e => { if (!e.target.closest('#navMore')) close(); });
    window.addEventListener('hashchange', close); }
  // ไปหน้าสแกน: จำหน้านี้ไว้ ให้ปุ่ม ‹ ในหน้าสแกนกลับมาที่เดิม (เช่น หน้ารายวัน)
  // แอปหน้าจอโฮม: เปิดหน้าสแกนในกรอบที่ค้างไว้ (ไม่โหลดหน้าใหม่ → iOS ขอสิทธิ์กล้องครั้งเดียวต่อการเปิดแอป)
  let scanHost = null;
  function openScan(id) {
    if (!scanHost) {
      scanHost = document.createElement('div'); scanHost.id = 'scanHost';
      scanHost.innerHTML = `<iframe src="../scan/?embed=1${id ? '#' + id : ''}" allow="camera; fullscreen" title="สแกน"></iframe>`;
      document.body.append(scanHost);
    } else {
      scanHost.querySelector('iframe').contentWindow.postMessage({ type: 'scan-open', id }, location.origin);
    }
    scanHost.hidden = false; document.body.classList.add('scan-open');
    document.querySelector('.nav-scan')?.classList.add('on');
    document.querySelectorAll('[data-nav]').forEach(a => a.classList.remove('on'));
  }
  function closeScan() {
    if (!scanHost || scanHost.hidden) return;
    scanHost.hidden = true; document.body.classList.remove('scan-open');
    document.querySelector('.nav-scan')?.classList.remove('on');
    scanHost.querySelector('iframe').contentWindow.postMessage({ type: 'scan-close' }, location.origin);
  }
  window.addEventListener('message', e => { if (e.origin === location.origin && e.data && e.data.type === 'scan-back') { closeScan(); route(); } });
  window.addEventListener('hashchange', closeScan);
  // แตะแท็บอื่นตอนเปิดหน้าสแกน: ถ้า hash เดิม (เช่น เปิดสแกนจากหน้ารอบแล้วกด "รอบ") จะไม่มี hashchange → ปิดเองแล้วแสดงหน้านั้น
  document.addEventListener('click', e => {
    const a = e.target.closest('#nav a[href^="#/"]');
    if (!a || !scanHost || scanHost.hidden) return;
    closeScan();
    if (a.getAttribute('href') === location.hash) { e.preventDefault(); route(); }
  });
  // แอปหน้าจอโฮม: ไฟล์ (PDF/CSV/รูป QR) เปิดแล้วไม่มีปุ่มกลับ → ดึงไฟล์แล้วเปิดเมนูแชร์ของ iPhone แทน (บันทึก/พิมพ์/ส่งต่อ)
  async function shareFile(url) {
    try {
      const r = await fetch(url); if (!r.ok) throw new Error('เปิดไฟล์ไม่สำเร็จ');
      const cd = r.headers.get('content-disposition') || '', name = (cd.match(/filename="?([^";]+)"?/) || [])[1] || 'file';
      const file = new File([await r.blob()], name, { type: r.headers.get('content-type') || 'application/octet-stream' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        try { await navigator.share({ files: [file] }); }
        catch (er) { if (er.name === 'NotAllowedError' && await ask({ title: 'ไฟล์พร้อมแล้ว', text: name, ok: 'บันทึก / แชร์' })) await navigator.share({ files: [file] }).catch(() => {}); }   // iOS: หมดจังหวะแตะระหว่างโหลด → ให้แตะอีกครั้ง
        return;
      }
      const u = URL.createObjectURL(file); const a = document.createElement('a'); a.href = u; a.download = name; document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(u), 30000);
    } catch (x) { toast(x.message, true); }
  }
  document.addEventListener('click', e => {
    const f = e.target.closest('a[href*="api/admin.php?action=export"], a[href*="action=sample_pdf"], a[href*="action=qr&dl=1"]');
    if (f && STANDALONE) { e.preventDefault(); if (window.FileView) window.FileView.open(f.getAttribute('href'), { back: 'กลับ', share: 'แชร์' }); else shareFile(f.getAttribute('href')); }   // ดูในแอปพร้อมปุ่มกลับ/แชร์
  });
  document.addEventListener('click', e => {
    const a = e.target.closest('a[href^="../scan/"]'); if (!a) return;
    if (STANDALONE) {
      e.preventDefault();
      if (e.target.closest('[data-nav], .nav-scan') && scanHost && !scanHost.hidden) return;
      openScan(Number((a.getAttribute('href').split('#')[1] || '')) || 0);
      return;
    }
    try { sessionStorage.setItem('vb-back', location.href); } catch (_) {}
  });
  document.querySelectorAll('[data-al]').forEach(b => { b.classList.toggle('on', b.dataset.al === (window.ADMIN_LANG || 'th')); b.onclick = () => window.setAdminLang(b.dataset.al); });
  $('logout').onclick = async () => {
    const r = await api('logout').catch(() => ({}));
    me = null;
    if (r.sso) location.href = 'oidc.php?logout=1';   // เข้าด้วย SSO → ไปออกจาก SSO ต่อ (ดู admin/oidc.php)
    else showLogin();
  };

  // ลิงก์เข้าสู่ระบบจากอีเมล (?login=...) → เข้าเลย แล้วลบ token ออกจาก URL
  const loginToken = new URLSearchParams(location.search).get('login');
  if (loginToken) {
    history.replaceState(null, '', location.pathname + location.hash);
    api('login_link', { token: loginToken }).then(j => { me = j.me; route(); offerPasskey(); })
      .catch(x => { showLogin(); if (x.message !== 'auth') toast(x.message, true); });
  } else {
    api('me').then(j => { me = j.me; route(); }).catch(() => showLogin());
  }
})();
