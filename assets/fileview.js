// ดูไฟล์ในแอป (PDF / CSV / รูป) พร้อมปุ่มกลับและปุ่มแชร์ — ใช้ในแอปหน้าจอโฮมที่ iOS เปิดไฟล์ทับแล้วไม่มีปุ่มกลับ
// FileView.open(url, { title, back, share }) — PDF วาดด้วย PDF.js (cdnjs) ทุกหน้า
(() => {
  'use strict';
  const PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
  let pdfjsReady = null;
  const loadPdfjs = () => pdfjsReady || (pdfjsReady = new Promise((ok, no) => {
    const s = document.createElement('script'); s.src = PDFJS + 'pdf.min.js';
    s.onload = () => { window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js'; ok(window.pdfjsLib); };
    s.onerror = no; document.head.append(s);
  }));
  const css = `.fv{position:fixed;inset:0;z-index:300;background:#2a2724;display:flex;flex-direction:column}
    .fv-bar{display:flex;align-items:center;gap:10px;padding:calc(env(safe-area-inset-top) + 10px) 12px 10px;background:#141210;color:#fff}
    .fv-bar b{flex:1;min-width:0;font:600 15px/1.3 system-ui,sans-serif;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:center}
    .fv-bar button{flex:none;min-height:40px;padding:0 14px;border-radius:999px;border:1px solid rgba(255,255,255,.3);background:transparent;color:#fff;font:500 15px system-ui,sans-serif;cursor:pointer}
    .fv-bar .fv-share{background:#fff;color:#141210;border-color:#fff}
    .fv-body{flex:1;overflow:auto;-webkit-overflow-scrolling:touch;padding:14px 12px calc(env(safe-area-inset-bottom) + 14px);display:flex;flex-direction:column;align-items:center;gap:12px}
    .fv-body canvas,.fv-body img{max-width:100%;height:auto;background:#fff;box-shadow:0 4px 18px rgba(0,0,0,.35);border-radius:4px}
    .fv-body table{background:#fff;border-collapse:collapse;font:13px/1.4 system-ui,sans-serif;align-self:stretch}
    .fv-body td,.fv-body th{border:1px solid #e3ddd3;padding:5px 7px;white-space:nowrap;text-align:left}.fv-body th{background:#efe9df}
    .fv-wrap{align-self:stretch;overflow:auto}
    .fv-msg{color:#ddd;font:15px system-ui,sans-serif;margin-top:30vh}`;
  const parseCsv = text => {
    const rows = []; let row = [], cell = '', q = false;
    text = text.replace(/^﻿/, '');
    for (let i = 0; i < text.length; i++) {
      const c = text[i];
      if (q) { if (c === '"') { if (text[i + 1] === '"') { cell += '"'; i++; } else q = false; } else cell += c; }
      else if (c === '"') q = true;
      else if (c === ',') { row.push(cell); cell = ''; }
      else if (c === '\n') { row.push(cell.replace(/\r$/, '')); rows.push(row); row = []; cell = ''; }
      else cell += c;
    }
    if (cell || row.length) { row.push(cell); rows.push(row); }
    return rows;
  };
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  async function open(url, o = {}) {
    if (!document.getElementById('fv-css')) { const st = document.createElement('style'); st.id = 'fv-css'; st.textContent = css; document.head.append(st); }
    const box = document.createElement('div'); box.className = 'fv';
    box.innerHTML = `<div class="fv-bar"><button type="button" class="fv-back">‹ ${esc(o.back || 'กลับ')}</button><b>${esc(o.title || '')}</b><button type="button" class="fv-share" disabled>${esc(o.share || 'แชร์')}</button></div><div class="fv-body"><p class="fv-msg">…</p></div>`;
    document.body.append(box);
    const body = box.querySelector('.fv-body'), shareBtn = box.querySelector('.fv-share');
    const close = () => { box.remove(); window.removeEventListener('popstate', close); };
    box.querySelector('.fv-back').onclick = close;
    let file = null;
    shareBtn.onclick = async () => {
      if (!file) return;
      if (navigator.canShare && navigator.canShare({ files: [file] })) { navigator.share({ files: [file] }).catch(() => {}); return; }
      const u = URL.createObjectURL(file), a = document.createElement('a'); a.href = u; a.download = file.name; document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(u), 30000);
    };
    try {
      const r = await fetch(url); if (!r.ok) throw new Error(String(r.status));
      const type = (r.headers.get('content-type') || '').split(';')[0];
      const cd = r.headers.get('content-disposition') || '';
      const name = o.filename || (cd.match(/filename="?([^";]+)"?/) || [])[1] || 'file';
      const blob = await r.blob();
      file = new File([blob], name, { type: type || blob.type });
      if (!o.title) box.querySelector('.fv-bar b').textContent = name;
      shareBtn.disabled = false;
      body.innerHTML = '';
      if (type === 'application/pdf') {
        const lib = await loadPdfjs();
        const pdf = await lib.getDocument({ data: await blob.arrayBuffer() }).promise;
        const w = Math.min(body.clientWidth - 24, 820), dpr = Math.min(window.devicePixelRatio || 1, 3);
        for (let p = 1; p <= pdf.numPages; p++) {
          const page = await pdf.getPage(p), v1 = page.getViewport({ scale: 1 }), vp = page.getViewport({ scale: (w / v1.width) * dpr });
          const c = document.createElement('canvas'); c.width = vp.width; c.height = vp.height; c.style.width = (vp.width / dpr) + 'px';
          body.append(c);
          await page.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
        }
      } else if (type.startsWith('image/')) {
        const img = document.createElement('img'); img.src = URL.createObjectURL(blob); body.append(img);
      } else if (type.includes('csv')) {
        const rows = parseCsv(await blob.text());
        body.innerHTML = `<div class="fv-wrap"><table>${rows.map((rw, i) => `<tr>${rw.map(c => i ? `<td>${esc(c)}</td>` : `<th>${esc(c)}</th>`).join('')}</tr>`).join('')}</table></div>`;
      } else {
        body.innerHTML = `<p class="fv-msg">${esc(name)}</p>`;
      }
    } catch (x) {
      body.innerHTML = `<p class="fv-msg">${esc(o.error || 'เปิดไฟล์ไม่สำเร็จ')}</p>`;
    }
  }
  window.FileView = { open };
})();
