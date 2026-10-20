const ICONS = {
  pulso: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
  marcas: '<circle cx="12" cy="12" r="3"/><circle cx="5" cy="6" r="2"/><circle cx="19" cy="6" r="2"/><circle cx="12" cy="20" r="2"/><path d="M6.5 7.5 10 10.5M17.5 7.5 14 10.5M12 15v3"/>',
  apps: '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><path d="M17.5 14v7M14 17.5h7"/>',
  ventas: '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
  suscripciones: '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M12 8v4l3 2"/>',
  gastos: '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/>',
  finanzas: '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
  infra: '<rect x="2" y="3" width="20" height="7" rx="2"/><rect x="2" y="14" width="20" height="7" rx="2"/><path d="M6 6.5h.01M6 17.5h.01M10 6.5h6M10 17.5h6"/>',
  calendario: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
  clientes: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  integraciones: '<path d="M9 2v6M15 2v6"/><path d="M6 8h12v4a6 6 0 0 1-12 0Z"/><path d="M12 18v4"/>',
  reportes: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/>',
  ajustes: '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
  lock: '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
  zap: '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
  arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  trace: '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3M11 8v6M8 11h6"/>',
  map: '<circle cx="12" cy="12" r="2"/><circle cx="4" cy="5" r="2"/><circle cx="20" cy="5" r="2"/><circle cx="4" cy="19" r="2"/><circle cx="20" cy="19" r="2"/><path d="m5.5 6.5 5 4M18.5 6.5l-5 4M5.5 17.5l5-4M18.5 17.5l-5-4"/>',
  link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
  clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  swap: '<path d="M7 16V4M3 8l4-4 4 4M17 8v12M21 16l-4 4-4-4"/>',
  code: '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',

  resumen: '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
  ventas: '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
  productos: '<path d="M20.38 3.46 16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23Z"/>',
  clientes: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  compras: '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
  finanzas: '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
  reportes: '<path d="M3 3v18h18"/><path d="M7 16v-5"/><path d="M12 16V8"/><path d="M17 16v-9"/>',
  configuracion: '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M1 14h6"/><path d="M9 8h6"/><path d="M17 16h6"/>',
  search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  x: '<path d="M18 6 6 18M6 6l12 12"/>',
  moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2m-7.07-14.07 1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
  upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
  edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
  archive: '<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
  trash: '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
  box: '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
  alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
  check: '<path d="M20 6 9 17l-5-5"/>',
  more: '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
  logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
  image: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
  sliders: '<path d="M21 4h-7M10 4H3M21 12h-9M8 12H3M21 20h-5M12 20H3M14 2v4M8 10v4M16 18v4"/>',
  grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
  list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
  tag: '<path d="M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.42l8.7 8.7a2.43 2.43 0 0 0 3.42 0l6.58-6.58a2.43 2.43 0 0 0 0-3.42Z"/><circle cx="7.5" cy="7.5" r=".5"/>',
  receipt: '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
  history: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>'
};
const ic = (n, cls = '') => `<svg class="${cls}" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[n] || ''}</svg>`;
const infoI = text => `<button type="button" class="info-i" data-tip="${esc(text)}" aria-label="${esc(text)}">i</button>`;

/* ---------- toasts ---------- */
function toast(msg, tone = 'ok') {
  const box = $('#toasts'); if (!box) return;
  const t = document.createElement('div');
  t.className = 'toast ' + tone; t.setAttribute('role', 'status');
  t.innerHTML = `<span class="tdot"></span><span>${esc(msg)}</span>`;
  box.appendChild(t);
  setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 320); }, tone === 'bad' ? 5200 : 3000);
}

/* ---------- tooltips ---------- */
let tipEl = null;
document.addEventListener('mouseover', e => {
  const el = e.target.closest('[data-tip]'); if (!el) return;
  tipEl?.remove(); tipEl = document.createElement('div'); tipEl.className = 'tip'; tipEl.textContent = el.dataset.tip; document.body.appendChild(tipEl);
  const r = el.getBoundingClientRect(), w = tipEl.offsetWidth, h = tipEl.offsetHeight;
  let x = Math.min(window.innerWidth - w - 8, Math.max(8, r.left + r.width / 2 - w / 2));
  let y = r.top - h - 8; if (y < 8) y = r.bottom + 8;
  tipEl.style.left = x + 'px'; tipEl.style.top = y + 'px';
});
document.addEventListener('mouseout', e => { if (e.target.closest('[data-tip]')) { tipEl?.remove(); tipEl = null; } });
document.addEventListener('click', e => { const el = e.target.closest('.info-i'); if (el) { e.preventDefault(); e.stopPropagation(); toast(el.dataset.tip, 'ok'); } }, true);

/* ---------- ripple en botones ---------- */
document.addEventListener('pointerdown', e => {
  const b = e.target.closest('.btn-primary,.btn-lens'); if (!b) return;
  const r = b.getBoundingClientRect(), s = Math.max(r.width, r.height), sp = document.createElement('span');
  sp.className = 'ripple'; sp.style.width = sp.style.height = s + 'px'; sp.style.left = (e.clientX - r.left - s / 2) + 'px'; sp.style.top = (e.clientY - r.top - s / 2) + 'px';
  b.appendChild(sp); setTimeout(() => sp.remove(), 600);
});

/* ---------- modales y paneles ---------- */
const modalStack = [];
function openModal({ title, body = '', footer = '', size = 'md', drawer = false, onMount, onClose }) {
  const ov = document.createElement('div');
  ov.className = 'overlay' + (drawer ? ' drawer' : '');
  ov.innerHTML = `<div class="modal ${size}" role="dialog" aria-modal="true" aria-label="${esc(title)}">
    <div class="modal-h"><h2>${title}</h2><button type="button" class="icon-btn" data-close aria-label="Cerrar">${ic('x')}</button></div>
    <div class="modal-b">${body}</div>${footer ? `<div class="modal-f">${footer}</div>` : ''}</div>`;
  document.body.appendChild(ov);
  const m = { el: ov, box: ov.querySelector('.modal'), body: ov.querySelector('.modal-b'), foot: ov.querySelector('.modal-f'), closed: false,
    close() { if (m.closed) return; m.closed = true; ov.classList.add('closing'); const i = modalStack.indexOf(m); if (i >= 0) modalStack.splice(i, 1); setTimeout(() => ov.remove(), 200); onClose && onClose(); },
    setBody(h) { m.body.innerHTML = h; }, setFoot(h) { if (m.foot) m.foot.innerHTML = h; } };
  ov.addEventListener('mousedown', e => { if (e.target === ov) m.close(); });
  ov.querySelector('[data-close]').addEventListener('click', () => m.close());
  modalStack.push(m);
  onMount && onMount(m);
  setTimeout(() => { const f = ov.querySelector('[autofocus]') || ov.querySelector('.modal-b input:not([type=hidden]):not([type=checkbox]):not([type=file]),.modal-b select,.modal-b textarea'); if (f && window.innerWidth > 860) f.focus(); }, 60);
  return m;
}
const openDrawer = o => openModal({ ...o, drawer: true });
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && modalStack.length) { modalStack[modalStack.length - 1].close(); }
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); $('#gsearch')?.focus(); }
});
function confirmDialog({ title = '¿Confirmás?', message = '', ok = 'Confirmar', tone = 'primary', extra = '' }) {
  return new Promise(res => {
    let done = false;
    const m = openModal({ title, size: 'sm', body: `<p style="margin:0 0 6px">${message}</p>${extra}`,
      footer: `<button class="btn btn-ghost" data-no>Volver</button><button class="btn ${tone === 'danger' ? 'btn-danger solid' : 'btn-primary'}" data-yes>${esc(ok)}</button>`,
      onClose: () => { if (!done) res(false); } });
    m.el.querySelector('[data-no]').onclick = () => m.close();
    m.el.querySelector('[data-yes]').onclick = () => { done = true; const extraVals = {}; $$('[name]', m.body).forEach(i => extraVals[i.name] = i.type === 'checkbox' ? i.checked : i.value); m.close(); res(extra ? extraVals : true); };
  });
}
function formData(form) {
  const o = {};
  for (const el of form.elements) {
    if (!el.name) continue;
    if (el.type === 'checkbox') { if (el.dataset.multi) { (o[el.name] = o[el.name] || []); if (el.checked) o[el.name].push(el.value); } else o[el.name] = el.checked; }
    else if (el.type === 'radio') { if (el.checked) o[el.name] = el.value; }
    else if (el.type !== 'file') o[el.name] = el.value.trim();
  }
  return o;
}
function markInvalid(form, names) {
  $$('.invalid', form).forEach(x => x.classList.remove('invalid'));
  names.forEach(n => { const el = form.elements[n]; if (el && el.classList) el.classList.add('invalid'); });
  if (names.length) { const el = form.elements[names[0]]; el?.focus?.(); }
}
const opt = (v, l, sel) => `<option value="${esc(v)}" ${String(sel) === String(v) ? 'selected' : ''}>${esc(l)}</option>`;
const opts = (arr, sel, empty) => (empty != null ? opt('', empty, sel) : '') + arr.map(a => typeof a === 'string' ? opt(a, a, sel) : opt(a.id, a.label || a.name, sel)).join('');
const field = (label, inner, cls = '', hint = '') => `<div class="field ${cls}"><label>${label}</label>${inner}${hint ? `<div class="hint">${hint}</div>` : ''}</div>`;
const emptyBox = (icon, title, text, btn = '') => `<div class="empty"><div class="ill">${ic(icon)}</div><h3>${title}</h3><p>${text}</p>${btn}</div>`;

/* ---------- descarga de archivos ---------- */
function downloadFile(name, content, mime) {
  const blob = content instanceof Blob ? content : new Blob([content], { type: mime });
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name;
  document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
}
function toCSV(cols, rows) {
  const sep = state.settings.numFormat === 'en' ? ',' : ';';
  const cell = v => { if (v == null) return ''; let s = typeof v === 'number' ? (state.settings.numFormat === 'en' ? String(v) : String(v).replace('.', ',')) : String(v); return /[";,\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
  return '﻿' + [cols.map(c => cell(c.label)).join(sep), ...rows.map(r => cols.map(c => cell(c.raw ? c.raw(r) : r[c.key])).join(sep))].join('\r\n');
}
function toXLS(cols, rows, title) {
  const x = s => String(s ?? '').replace(/[<>&"]/g, c => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c]));
  const cell = v => typeof v === 'number' ? `<Cell><Data ss:Type="Number">${v}</Data></Cell>` : `<Cell><Data ss:Type="String">${x(v)}</Data></Cell>`;
  return `<?xml version="1.0"?><?mso-application progid="Excel.Sheet"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Styles><Style ss:ID="h"><Font ss:Bold="1"/></Style></Styles><Worksheet ss:Name="${x(title).slice(0, 30)}"><Table><Row>${cols.map(c => `<Cell ss:StyleID="h"><Data ss:Type="String">${x(c.label)}</Data></Cell>`).join('')}</Row>${rows.map(r => `<Row>${cols.map(c => cell(c.raw ? c.raw(r) : r[c.key])).join('')}</Row>`).join('')}</Table></Worksheet></Workbook>`;
}

/* ---------- imágenes (se comprimen antes de guardar) ---------- */
function compressImage(file, max = 900, q = .78) {
  return new Promise((res, rej) => {
    if (!file.type.startsWith('image/')) return rej(new Error('El archivo no es una imagen'));
    const fr = new FileReader();
    fr.onload = () => { const img = new Image(); img.onload = () => {
      const k = Math.min(1, max / Math.max(img.width, img.height)); const c = document.createElement('canvas');
      c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); res(c.toDataURL('image/jpeg', q));
    }; img.onerror = () => rej(new Error('No se pudo leer la imagen')); img.src = fr.result; };
    fr.onerror = () => rej(fr.error); fr.readAsDataURL(file);
  });
}

/* =====================================================================
   GRÁFICOS (SVG propio, sin librerías)
   ===================================================================== */
const cssVar = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
function niceMax(v) { if (v <= 0) return 1; const p = Math.pow(10, Math.floor(Math.log10(v))); const n = v / p; return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * p; }

/* Línea/área con varias series + tooltip */
function lineChart(el, { labels, series, fmt = v => v, height = 250 }) {
  if (!el) return;
  const W = Math.max(280, el.clientWidth), H = height, pl = 54, pr = 14, pt = 14, pb = 30;
  const iw = W - pl - pr, ih = H - pt - pb, n = labels.length;
  const all = series.flatMap(s => s.values); const mn = Math.min(0, ...all); const mxv = Math.max(0, ...all); const mx = mxv > 0 ? niceMax(mxv) : (mn < 0 ? 0 : 1);
  const lo = mn < 0 ? -niceMax(-mn) : 0;
  const x = i => pl + (n <= 1 ? iw / 2 : i * iw / (n - 1)); const y = v => pt + ih - (v - lo) / (mx - lo) * ih;
  const ticks = 4; let grid = '', yl = '';
  const allZero = all.every(v => !v);
  for (let i = 0; i <= ticks; i++) { const v = lo + (mx - lo) * i / ticks; grid += `<line x1="${pl}" x2="${W - pr}" y1="${y(v)}" y2="${y(v)}"/>`; if (allZero && v !== 0) continue; yl += `<text x="${pl - 8}" y="${y(v) + 4}" text-anchor="end">${esc(moneyShort(v))}</text>`; }
  const step = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(iw / 70))));
  let xl = ''; labels.forEach((l, i) => { if (i % step === 0 || i === n - 1) xl += `<text x="${x(i)}" y="${H - 8}" text-anchor="middle">${esc(l.short)}</text>`; });
  const gid = 'g' + Math.random().toString(36).slice(2, 7);
  let paths = '';
  series.forEach((s, si) => {
    const pts = s.values.map((v, i) => [x(i), y(v)]);
    const d = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ',' + p[1].toFixed(1)).join('');
    if (si === 0 && n > 1) paths += `<path class="areaFade" d="${d}L${x(n - 1)},${y(Math.max(lo, 0))}L${x(0)},${y(Math.max(lo, 0))}Z" fill="url(#${gid})"/>`;
    paths += `<path class="ln" d="${d}" fill="none" stroke="${s.color}" stroke-width="${si === 0 ? 2.6 : 2}" stroke-linejoin="round" stroke-linecap="round" ${s.dash ? 'stroke-dasharray="5 5"' : ''}/>`;
    if (n <= 40) paths += pts.map(p => `<circle cx="${p[0]}" cy="${p[1]}" r="${n < 16 ? 3.2 : 2}" fill="var(--panel)" stroke="${s.color}" stroke-width="2"/>`).join('');
  });
  el.innerHTML = `<svg width="100%" viewBox="0 0 ${W} ${H}" role="img" aria-label="Gráfico de evolución"><defs><linearGradient id="${gid}" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="${series[0]?.color}" stop-opacity=".28"/><stop offset="1" stop-color="${series[0]?.color}" stop-opacity="0"/></linearGradient></defs>
    <g class="grid">${grid}</g><g class="ax">${yl}${xl}</g>${paths}<line class="guide" x1="0" x2="0" y1="${pt}" y2="${pt + ih}" stroke="var(--camel)" stroke-width="1" opacity="0"/><g class="hl"></g><rect x="${pl}" y="${pt}" width="${iw}" height="${ih}" fill="transparent" class="hit"/></svg><div class="ctip"></div>`;
  $$('path.ln', el).forEach(p => { if (p.getAttribute('stroke-dasharray')) return; const L = p.getTotalLength(); p.style.strokeDasharray = L; p.style.strokeDashoffset = L; p.classList.add('draw'); });
  const svg = $('svg', el), tip = $('.ctip', el), guide = $('.guide', el), hl = $('.hl', el);
  const move = ev => {
    const r = svg.getBoundingClientRect(), sx = (ev.clientX - r.left) * W / r.width;
    const i = Math.max(0, Math.min(n - 1, Math.round(n <= 1 ? 0 : (sx - pl) / iw * (n - 1))));
    guide.setAttribute('x1', x(i)); guide.setAttribute('x2', x(i)); guide.setAttribute('opacity', .6);
    hl.innerHTML = series.map(s => `<circle cx="${x(i)}" cy="${y(s.values[i])}" r="5.5" fill="${s.color}" stroke="var(--panel)" stroke-width="2.5"/>`).join('');
    tip.innerHTML = `<div style="opacity:.75;margin-bottom:3px">${esc(labels[i].long)}</div>` + series.map(s => `<div><i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:${s.color};margin-right:6px"></i>${esc(s.name)}: <b>${esc(fmt(s.values[i]))}</b></div>`).join('');
    tip.style.opacity = 1;
    const px = x(i) * r.width / W, tw = tip.offsetWidth;
    tip.style.transform = `translate(${Math.max(0, Math.min(r.width - tw, px - tw / 2))}px, ${Math.max(0, y(Math.max(...series.map(s => s.values[i]))) * r.height / H - tip.offsetHeight - 14)}px)`;
    tip.style.left = '0'; tip.style.top = '0';
  };
  $('.hit', el).addEventListener('pointermove', move);
  $('.hit', el).addEventListener('pointerleave', () => { tip.style.opacity = 0; guide.setAttribute('opacity', 0); hl.innerHTML = ''; });
}

/* Barras horizontales (HTML, animadas) */
function barsHTML(items, { fmt = v => v, color = 'var(--c2)', max } = {}) {
  if (!items.length) return '<p class="muted small">Sin datos en este período.</p>';
  const m = max || Math.max(...items.map(i => i.value), 1);
  return `<div class="bars">${items.map((it, i) => `<div class="bar-row"${it.trace ? ` data-trace="${esc(it.trace)}"` : ''}><span class="bl" title="${esc(it.label)}">${esc(it.label)}${it.sub ? ` <span class="muted small">${esc(it.sub)}</span>` : ''}</span><span class="bv">${esc(fmt(it.value))}</span><div class="bar-track"><div class="bar-fill" data-w="${Math.max(2, it.value / m * 100)}" style="background:${it.color || color};transition-delay:${i * 60}ms"></div></div></div>`).join('')}</div>`;
}
function animateBars(root = document) { requestAnimationFrame(() => requestAnimationFrame(() => $$('.bar-fill[data-w]', root).forEach(b => b.style.width = b.dataset.w + '%'))); }

/* Dona */
function donutHTML(entries, fmt) {
  const total = entries.reduce((a, e) => a + e.value, 0);
  if (!total) return '<p class="muted small">Sin datos en este período.</p>';
  const R = 52, C = 2 * Math.PI * R; let acc = 0;
  const segs = entries.map((e, i) => { const len = e.value / total * C; const s = `<circle class="seg" cx="70" cy="70" r="${R}" fill="none" stroke="${e.color}" stroke-width="20" stroke-dasharray="${len} ${C - len}" stroke-dashoffset="${C}" data-off="${-acc}" transform="rotate(-90 70 70)"><title>${esc(e.label)}: ${esc(fmt(e.value))}</title></circle>`; acc += len; return s; }).join('');
  return `<div class="donut-wrap"><svg width="140" height="140" viewBox="0 0 140 140" role="img" aria-label="Distribución">${segs}<text x="70" y="66" text-anchor="middle" font-size="11" fill="var(--muted)" font-family="var(--sans)">Total</text><text x="70" y="84" text-anchor="middle" font-size="15" fill="var(--ink)" font-family="var(--serif)">${esc(moneyShort(total))}</text></svg>
  <div class="legend" style="flex-direction:column;gap:7px">${entries.map(e => `<span><i style="background:${e.color}"></i>${esc(e.label)} · <b style="color:var(--ink)">${pct(e.value / total * 100)}</b></span>`).join('')}</div></div>`;
}
function animateDonuts(root = document) { requestAnimationFrame(() => requestAnimationFrame(() => $$('circle.seg', root).forEach(c => c.style.strokeDashoffset = c.dataset.off))); }

/* Cascada de resultados */
function waterfall(el, steps) {
  if (!el) return;
  const W = Math.max(300, el.clientWidth), H = 240, pl = 8, pr = 8, pt = 22, pb = 44;
  const iw = W - pl - pr, ih = H - pt - pb; let run = 0; const bars = [];
  steps.forEach(s => { if (s.kind === 'total') { bars.push({ ...s, a: 0, b: s.value }); run = s.value; } else { const nr = run + s.value; bars.push({ ...s, a: run, b: nr }); run = nr; } });
  const vals = bars.flatMap(b => [b.a, b.b]); const mx = Math.max(0, ...vals), mn = Math.min(0, ...vals); const span = (mx - mn) || 1;
  const y = v => pt + (mx - v) / span * ih; const bw = iw / bars.length;
  let g = `<line x1="${pl}" x2="${W - pr}" y1="${y(0)}" y2="${y(0)}" stroke="var(--line-2)"/>`;
  bars.forEach((b, i) => {
    const top = y(Math.max(b.a, b.b)), h = Math.max(2, Math.abs(y(b.a) - y(b.b))), xx = pl + i * bw + bw * .16, w = bw * .68;
    const col = b.kind === 'total' ? (b.value >= 0 ? 'var(--c1)' : 'var(--bad)') : (b.value >= 0 ? 'var(--c4)' : 'var(--c5)');
    g += `<rect x="${xx}" y="${top}" width="${w}" height="${h}" rx="6" fill="${col}" style="transform-origin:${xx + w / 2}px ${y(0)}px;animation:wfIn .7s ${i * .08}s cubic-bezier(.2,.8,.2,1) both"><title>${esc(b.label)}: ${esc(money(b.value))}</title></rect>`;
    g += `<text x="${xx + w / 2}" y="${top - 6}" text-anchor="middle" font-size="11" font-weight="700" fill="var(--ink)" font-family="var(--sans)">${esc(moneyShort(b.value))}</text>`;
    g += `<foreignObject x="${pl + i * bw}" y="${H - pb + 6}" width="${bw}" height="${pb}"><div xmlns="http://www.w3.org/1999/xhtml" style="font:11px/1.2 var(--sans);color:var(--muted);text-align:center;padding:0 2px">${esc(b.label)}</div></foreignObject>`;
  });
  el.innerHTML = `<style>@keyframes wfIn{from{transform:scaleY(0);opacity:0}}</style><svg width="100%" viewBox="0 0 ${W} ${H}" role="img" aria-label="Cascada de resultados">${g}</svg>`;
}

/* Contadores animados */
function countUp(root = document) {
  $$('[data-count]', root).forEach(el => {
    const to = Number(el.dataset.count), kind = el.dataset.kind; const t0 = performance.now(), dur = 900;
    const f = v => kind === 'money' ? money(v) : fnum(v);
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) { el.textContent = f(to); return; }
    const tick = t => { const k = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - k, 3); el.textContent = f(kind === 'money' ? to * e : Math.round(to * e)); if (k < 1) requestAnimationFrame(tick); else el.textContent = f(to); };
    requestAnimationFrame(tick);
  });
}

/* Barras divergentes (positivo a la derecha, negativo a la izquierda) */
function divBarsHTML(items, fmt) {
  if (!items.length) return '<p class="muted small">Sin datos en este período.</p>';
  const m = Math.max(...items.map(i => Math.abs(i.value)), 1);
  return `<div class="bars">${items.map((it, i) => { const w = Math.abs(it.value) / m * 50; return `<div class="bar-row"${it.trace ? ` data-trace="${esc(it.trace)}"` : ''}><span class="bl">${it.dot ? `<i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:${it.dot};margin-right:6px"></i>` : ''}${esc(it.label)}${it.sub ? ` <span class="muted small">${esc(it.sub)}</span>` : ''}</span><span class="bv ${it.value < 0 ? 'neg' : 'posv'}">${esc(fmt(it.value))}</span><div class="div-track"><div class="div-fill bar-fill" data-w="${w}" style="${it.value < 0 ? 'right:50%' : 'left:50%'};background:${it.value < 0 ? 'var(--coral)' : 'var(--pos)'};transition-delay:${i * 60}ms"></div></div></div>`; }).join('')}</div>`;
}
/* Barras agrupadas verticales (p. ej. ingresos vs gastos por mes, ganadas vs perdidas) */
function groupBars(el, { labels, series, fmt = v => v, height = 220, signed = false }) {
  if (!el) return;
  const W = Math.max(280, el.clientWidth), H = height, pl = 50, pr = 8, pt = 12, pb = 26, iw = W - pl - pr, ih = H - pt - pb, n = labels.length, k = series.length;
  const all = series.flatMap(s => s.values.map(v => signed && s.neg ? -v : v));
  const mxv = Math.max(0, ...all), mnv = Math.min(0, ...all); let mx = mxv > 0 ? niceMax(mxv) : (mnv < 0 ? 0 : 1), mn = mnv < 0 ? -niceMax(-mnv) : 0; const intT = all.every(v => Number.isInteger(v)) && (mx - mn) <= 4; if (intT) { mx = Math.max(mx, Math.ceil(mxv)); mn = Math.min(mn, Math.floor(mnv)); if (mx === mn) mx = 1; } const T = intT ? Math.max(1, mx - mn) : 4;
  const y = v => pt + (mx - v) / (mx - mn) * ih, gw = iw / Math.max(1, n), bw = Math.max(3, Math.min(26, gw * .7 / k));
  let g = '';
  for (let i = 0; i <= T; i++) { const v = mn + (mx - mn) * i / T; g += `<line x1="${pl}" x2="${W - pr}" y1="${y(v)}" y2="${y(v)}"/>`; }
  let ax = ''; for (let i = 0; i <= T; i++) { const v = mn + (mx - mn) * i / T; ax += `<text x="${pl - 6}" y="${y(v) + 4}" text-anchor="end">${esc(fmt(v))}</text>`; }
  const step = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(iw / 56))));
  labels.forEach((l, i) => { if (i % step === 0) ax += `<text x="${pl + gw * i + gw / 2}" y="${H - 8}" text-anchor="middle">${esc(l.short)}</text>`; });
  let bars = '';
  labels.forEach((l, i) => series.forEach((s, j) => { let v = s.values[i]; if (signed && s.neg) v = -v; const x = pl + gw * i + (gw - bw * k) / 2 + j * bw, top = y(Math.max(0, v)), h = Math.max(v ? 2 : 0, Math.abs(y(v) - y(0)));
    bars += `<rect x="${x}" y="${top}" width="${bw - 1.5}" height="${h}" rx="3" fill="${s.color}" style="transform-origin:${x}px ${y(0)}px;animation:wfIn .7s ${i * .03}s cubic-bezier(.2,.8,.2,1) both"><title>${esc(l.long)} · ${esc(s.name)}: ${esc(fmt(s.values[i]))}</title></rect>`; }));
  el.innerHTML = `<style>@keyframes wfIn{from{transform:scaleY(0)}}</style><svg width="100%" viewBox="0 0 ${W} ${H}" role="img" aria-label="Gráfico de barras"><g class="grid">${g}</g><line x1="${pl}" x2="${W - pr}" y1="${y(0)}" y2="${y(0)}" stroke="var(--line-2)"/><g class="ax">${ax}</g>${bars}</svg>`;
}
/* =====================================================================
   SHELL: barra superior con lentes de marca, ticker, dock, ⌘K
   ===================================================================== */
const ROUTES = [
  { id: 'pulso', label: 'Pulso', desc: 'Visión ejecutiva' },
  { id: 'marcas', label: 'Marcas' },
  { id: 'apps', label: 'Aplicaciones' },
  { id: 'ventas', label: 'Productos y ventas' },
  { id: 'suscripciones', label: 'Suscripciones' },
  { id: 'gastos', label: 'Gastos y proveedores' },
  { id: 'finanzas', label: 'Finanzas' },
  { id: 'infra', label: 'Infraestructura' },
  { id: 'calendario', label: 'Calendario y alertas' },
  { id: 'clientes', label: 'Clientes' },
  { id: 'integraciones', label: 'Integraciones' },
  { id: 'reportes', label: 'Reportes' },
  { id: 'ajustes', label: 'Ajustes' }
];
const DOCK_GROUPS = [['pulso', 'marcas', 'apps'], ['ventas', 'suscripciones', 'gastos', 'finanzas'], ['infra', 'calendario', 'clientes'], ['integraciones', 'reportes', 'ajustes']];
const VIEWS = {}, ACT = {}, CHG = {}, INP = {};
let unlocked = false;

function isDark() { return ui.theme ? ui.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches; }
function applyTheme() { if (ui.theme) document.documentElement.dataset.theme = ui.theme; else delete document.documentElement.dataset.theme; applyLens(); }
function applyLens() {
  const r = document.documentElement.style, b = ui.lens !== 'all' ? brand(ui.lens) : null;
  if (!b) { ['--lens-a', '--lens-b', '--lens-ink', '--lens-soft'].forEach(k => r.removeProperty(k)); return; }
  const dark = isDark();
  r.setProperty('--lens-a', dark ? b.color : b.dark);
  r.setProperty('--lens-b', b.alt || b.color);
  r.setProperty('--lens-ink', dark ? b.color : b.dark);
  r.setProperty('--lens-soft', `color-mix(in srgb, ${b.color} ${dark ? 18 : 13}%, var(--panel))`);
}

function render() {
  const root = $('#root');
  if (!state.setupDone) return renderOnboarding(root);
  if (state.settings.pin && !unlocked) return renderLock(root);
  if (!$('#view')) renderShell(root);
  if (!VIEWS[ui.route]) ui.route = 'pulso';
  applyLens(); updateShell();
  const v = VIEWS[ui.route];
  $('#view').innerHTML = `<div class="page">${v.render()}</div>`;
  v.after && v.after();
  postRender($('#view'));
}
function rerender() { const v = VIEWS[ui.route], page = $('#view .page'); if (!page) return render(); const y = window.scrollY; page.innerHTML = v.render(); page.style.animation = 'none'; v.after && v.after(); postRender(page); updateTicker(); window.scrollTo(0, y); }
function postRender(root) { countUp(root); animateBars(root); animateDonuts(root); }

function renderShell(root) {
  document.body.className = '';
  root.innerHTML = `${DataStore.env === 'pruebas' ? '<div class="env-strip" title="Ambiente de pruebas"></div>' : ''}
  <header class="topbar" id="topbar">
    <button class="logo" data-act="go" data-r="pulso" aria-label="Ir al Pulso">
      <span class="logo-mark"><svg viewBox="0 0 38 38" aria-hidden="true"><circle cx="19" cy="19" r="17" fill="none" stroke="var(--line-2)" stroke-dasharray="2 4"/><g class="orbit" id="logoOrbit"></g><circle cx="19" cy="19" r="5.5" fill="var(--struct)"/></svg></span>
      <span class="logo-txt"><b id="bizName"></b><span>Centro de operaciones</span></span>
    </button>
    <div class="lens" id="lens" role="tablist" aria-label="Lente de marca"></div>
    <div class="top-actions">
      <button class="period-btn" data-act="pickPeriod" id="periodBtn" aria-label="Cambiar período"></button>
      <button class="cmd-btn" data-act="palette" aria-label="Buscar o crear">${ic('search')}<span class="lbl-txt">Buscar o crear…</span><kbd style="margin-left:auto">⌘K</kbd></button>
      <button class="icon-btn" data-act="toggleTheme" id="themeBtn" aria-label="Cambiar tema"></button>
      ${state.settings.pin ? `<button class="icon-btn" data-act="lockNow" aria-label="Bloquear">${ic('lock')}</button>` : ''}
    </div>
  </header>
  <div class="ticker" id="ticker" aria-label="Indicadores en vivo"></div>
  <main class="view" id="view"></main>
  <div class="dock-wrap"><nav class="dock" id="dock" aria-label="Módulos"></nav></div>`;
  window.addEventListener('scroll', () => $('#topbar')?.classList.toggle('scrolled', window.scrollY > 4), { passive: true });
}
function updateShell() {
  if (!$('#view') || !$('#bizName')) return;
  const S = state.settings;
  $('#bizName').textContent = S.business.name || 'Mi ecosistema';
  $('#logoOrbit').innerHTML = S.brands.slice(0, 6).map((b, i, a) => { const ang = i / a.length * Math.PI * 2 - Math.PI / 2; return `<circle cx="${19 + Math.cos(ang) * 13}" cy="${19 + Math.sin(ang) * 13}" r="3.4" fill="${esc(b.color)}"/>`; }).join('');
  const lenses = [{ id: 'all', name: 'Ecosistema', dots: S.brands.map(b => b.color) }, ...S.brands.map(b => ({ id: b.id, name: b.name, dots: [b.color, b.alt] }))];
  $('#lens').innerHTML = `<span class="lens-pill" id="lensPill"></span>` + lenses.map(l => `<button role="tab" aria-selected="${ui.lens === l.id}" class="${ui.lens === l.id ? 'on' : ''}" data-act="setLens" data-id="${l.id}"><span class="dots">${l.dots.filter(Boolean).slice(0, 3).map(c => `<i style="background:${esc(c)}"></i>`).join('')}</span>${esc(l.name)}</button>`).join('');
  requestAnimationFrame(() => { const on = $('#lens button.on'), pill = $('#lensPill'); if (on && pill) { pill.style.left = on.offsetLeft + 'px'; pill.style.width = on.offsetWidth + 'px'; } });
  const r = resolveRange();
  $('#periodBtn').innerHTML = `${ic('calendario')}<span>${esc(periodLabel())}</span><span class="mono">${dShort(r.from)}–${dShort(r.to)}</span>`;
  $('#themeBtn').innerHTML = ic(isDark() ? 'sun' : 'moon');
  $('#dock').innerHTML = DOCK_GROUPS.map((g, gi) => (gi ? '<span class="sep"></span>' : '') + g.map(id => { const rt = ROUTES.find(x => x.id === id); return `<a href="#/${id}" class="${ui.route === id ? 'active' : ''}" aria-label="${rt.label}">${ic(id)}<span class="dock-tip">${rt.label}</span></a>`; }).join('')).join('') + `<span class="sep"></span><button class="create" data-act="palette" data-mode="crear" aria-label="Crear">${ic('plus')}<span class="dock-tip">Crear… (⌘K)</span></button>`;
  updateTicker();
  document.title = (S.business.name ? S.business.name + ' · ' : '') + (ROUTES.find(x => x.id === ui.route)?.label || '');
}
function updateTicker() {
  const el = $('#ticker'); if (!el) return;
  const r = resolveRange(), m = metrics(r), sm = saasMetrics(r), al = computeAlerts(), pv = metrics(prevRange(r));
  const d = (a, b) => b ? `<span class="${a >= b ? 'up' : 'down'}">${a >= b ? '▲' : '▼'}${pct(Math.abs((a - b) / Math.abs(b) * 100))}</span>` : '';
  const next = calendarEvents(todayStr(), addDays(todayStr(), 60)).find(e => e.type === 'pago');
  const items = [
    ['result', `RESULTADO ${esc(periodLabel().toUpperCase())}`, `<b>${money(m.result)}</b> ${d(m.result, pv.result)}`],
    ['net', 'INGRESOS NETOS', `<b>${money(m.net)}</b> ${d(m.net, pv.net)}`],
    ['mrr', 'MRR', `<b>${money(sm.mrr)}</b>`],
    ['subs', 'SUSCRIPCIONES ACTIVAS', `<b>${fnum(sm.active)}</b>`],
    ['collected', 'COBRADO', `<b>${money(m.collected)}</b>`],
    ['expenses', 'GASTOS', `<b>${money(m.expenses)}</b>`],
    ['alerts', 'ALERTAS', `<b>${al.length}</b>${al.filter(a => a.urg === 'alta').length ? ` <span class="down">${al.filter(a => a.urg === 'alta').length} urgentes</span>` : ''}`],
    ...(next ? [['cal', 'PRÓXIMO VENCIMIENTO', `<b>${esc(next.title.replace(/^(Vence|Renovación): /, ''))}</b> ${dShort(next.date)}`]] : [])
  ];
  const html = items.map(([k, l, v]) => `<span data-act="tickerGo" data-k="${k}"><i></i>${l} ${v}</span>`).join('');
  el.innerHTML = `<div class="ticker-track">${html}${html}</div>`;
}
ACT.tickerGo = el => { const k = el.dataset.k; if (k === 'alerts' || k === 'cal') go('calendario'); else if (k === 'subs') go('suscripciones'); else openTrace(k); };
function go(r) { if (location.hash !== '#/' + r) location.hash = '#/' + r; else render(); }
ACT.go = el => go(el.dataset.r);
ACT.setLens = el => { ui.lens = el.dataset.id; saveUI(); applyLens(); updateShell(); rerender(); };
ACT.toggleTheme = () => { ui.theme = isDark() ? 'light' : 'dark'; saveUI(); applyTheme(); render(); };
ACT.lockNow = () => { unlocked = false; while (modalStack.length) modalStack[0].close(); $('#root').innerHTML = ''; render(); };
window.addEventListener('hashchange', () => { const r = location.hash.replace('#/', '').split('?')[0]; if (r && r !== ui.route && VIEWS[r]) { ui.route = r; while (modalStack.length) modalStack[0].close(); render(); window.scrollTo(0, 0); } });
document.addEventListener('click', e => { const el = e.target.closest('[data-act]'); if (!el || el.closest('[data-close]')) return; const fn = ACT[el.dataset.act]; if (fn) { e.preventDefault(); fn(el, e); } });
document.addEventListener('click', e => { const el = e.target.closest('[data-trace]'); if (!el || e.target.closest('[data-act]') || e.target.closest('.info-i')) return; openTrace(el.dataset.trace); });
document.addEventListener('change', e => { const el = e.target.closest('[data-change]'); if (el) CHG[el.dataset.change]?.(el, e); });
document.addEventListener('input', e => { const el = e.target.closest('[data-input]'); if (el) INP[el.dataset.input]?.(el, e); });
window.addEventListener('resize', debounce(() => { if (VIEWS[ui.route]?.after && $('#view .page')) VIEWS[ui.route].after(); updateShell(); }, 180));

/* ---------- período global ---------- */
ACT.pickPeriod = () => {
  const p = ui.period, r = resolveRange();
  const m = openModal({ title: 'Período de análisis', size: 'sm', body: `<div class="seg" style="display:flex;flex-wrap:wrap">${PRESETS.map(([id, l]) => `<button data-p="${id}" class="${p.preset === id ? 'on' : ''}">${l}</button>`).join('')}</div>
    <div class="form-grid" style="margin-top:14px">${field('Desde', `<input class="input" type="date" id="pFrom" value="${r.from}">`)}${field('Hasta', `<input class="input" type="date" id="pTo" value="${r.to}">`)}</div>
    <p class="small muted">Se aplica a todo el panel. Las comparaciones usan el período anterior de igual duración.</p>`, footer: `<button class="btn btn-primary" data-ok>Aplicar</button>` });
  $$('[data-p]', m.el).forEach(b => b.onclick = () => { ui.period = { preset: b.dataset.p }; if (b.dataset.p !== 'custom') { saveUI(); m.close(); render(); } else { $$('[data-p]', m.el).forEach(x => x.classList.toggle('on', x === b)); } });
  m.el.querySelector('[data-ok]').onclick = () => { const f = $('#pFrom', m.el).value, t = $('#pTo', m.el).value; if (!f || !t || f > t) { toast('Revisá las fechas', 'bad'); return; } ui.period = { preset: 'custom', from: f, to: t }; saveUI(); m.close(); render(); };
};

/* ---------- paleta de comandos ⌘K ---------- */
const CREATE_CMDS = [
  ['newSale', 'Registrar venta o ingreso', 'ventas'], ['newExpense', 'Registrar gasto', 'gastos'], ['newSub', 'Nueva suscripción', 'suscripciones'], ['subPayment', 'Cobro / renovación de suscripción', 'suscripciones'],
  ['newProject', 'Nueva aplicación o proyecto', 'apps'], ['newProduct', 'Nuevo producto digital', 'ventas'], ['newResource', 'Nuevo recurso de infraestructura', 'infra'], ['newEvent', 'Nuevo evento en el calendario', 'calendario'],
  ['newCustomer', 'Nuevo cliente', 'clientes'], ['newSupplier', 'Nuevo proveedor', 'gastos'], ['newRate', 'Cargar tipo de cambio', 'finanzas'], ['newTreasury', 'Transferencia, retiro o aporte', 'finanzas'],
  ['newTime', 'Registrar horas en un proyecto', 'clock'], ['importCSV', 'Importar CSV de ventas o gastos', 'integraciones'], ['importJSON', 'Importar eventos JSON de una app', 'code']
];
ACT.palette = el => openPalette(el?.dataset?.mode);
document.addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); if (state.setupDone && (!state.settings.pin || unlocked)) openPalette(); }
  if (e.key === 'Escape' && modalStack.length) modalStack[modalStack.length - 1].close();
});
function openPalette(mode) {
  if ($('.palette')) return;
  const ov = document.createElement('div'); ov.className = 'overlay palette-ov';
  ov.innerHTML = `<div class="palette" role="dialog" aria-label="Paleta de comandos"><div class="pin">${ic('search')}<input placeholder="${mode === 'crear' ? 'Qué querés crear…' : 'Buscá una acción, sección, app, producto o cliente…'}" aria-label="Buscar"></div><div class="res"></div><div class="foot"><span><span class="kbd">↑↓</span> navegar</span><span><span class="kbd">↵</span> abrir</span><span><span class="kbd">esc</span> cerrar</span></div></div>`;
  document.body.appendChild(ov);
  const m = { el: ov, close() { ov.classList.add('closing'); setTimeout(() => ov.remove(), 180); const i = modalStack.indexOf(m); if (i >= 0) modalStack.splice(i, 1); } };
  modalStack.push(m);
  ov.addEventListener('mousedown', e => { if (e.target === ov) m.close(); });
  const inp = $('input', ov), res = $('.res', ov); let items = [], sel = 0;
  const build = () => {
    const q = norm(inp.value); const out = [];
    const add = (grp, icon, label, run, sub = '') => { if (!q || norm(label + ' ' + sub).includes(q)) out.push({ grp, icon, label, run, sub }); };
    CREATE_CMDS.forEach(([a, l, icn]) => add('Crear', icn, l, () => ACT[a]()));
    if (mode !== 'crear') {
      ROUTES.forEach(r => add('Ir a', r.id, r.label, () => go(r.id)));
      [{ id: 'all', name: 'Ecosistema completo' }, ...state.settings.brands].forEach(b => add('Lente', 'marcas', 'Ver ' + b.name, () => { ui.lens = b.id; saveUI(); applyLens(); render(); }));
      if (q.length >= 2) {
        state.projects.forEach(p => add('Aplicaciones', 'apps', p.name, () => openProject(p.id), brand(p.brandId)?.name));
        state.products.forEach(p => add('Productos', 'ventas', p.name, () => openProduct(p.id), brand(p.brandId)?.name));
        state.customers.forEach(c => add('Clientes', 'clientes', c.name, () => openCustomer(c.id), c.email));
        state.resources.forEach(r => add('Infraestructura', 'infra', r.name, () => openResource(r.id), r.provider));
      }
    }
    items = out.slice(0, 40); sel = 0; draw();
  };
  const draw = () => {
    let h = '', g = '';
    items.forEach((it, i) => { if (it.grp !== g) { g = it.grp; h += `<div class="grp">${esc(g)}</div>`; } h += `<button class="it ${i === sel ? 'sel' : ''}" data-i="${i}">${ic(it.icon)}<span>${esc(it.label)}${it.sub ? ` <span class="muted small">· ${esc(it.sub)}</span>` : ''}</span>${i === sel ? '<span class="k kbd">↵</span>' : ''}</button>`; });
    res.innerHTML = h || '<p class="muted small" style="padding:12px">Sin resultados.</p>';
    res.querySelector('.sel')?.scrollIntoView({ block: 'nearest' });
  };
  const run = i => { const it = items[i]; if (!it) return; m.close(); setTimeout(() => it.run(), 60); };
  inp.addEventListener('input', build);
  inp.addEventListener('keydown', e => { if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(items.length - 1, sel + 1); draw(); } if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(0, sel - 1); draw(); } if (e.key === 'Enter') { e.preventDefault(); run(sel); } });
  res.addEventListener('click', e => { const b = e.target.closest('[data-i]'); if (b) run(Number(b.dataset.i)); });
  build(); setTimeout(() => inp.focus(), 30);
}

/* ---------- bienvenida ---------- */
function netSVG() {
  let s = '<svg class="net" viewBox="0 0 400 400" preserveAspectRatio="xMidYMid slice" aria-hidden="true">';
  const pts = []; for (let i = 0; i < 26; i++) pts.push([(i * 97) % 400, (i * 151 + 40) % 400]);
  pts.forEach((p, i) => pts.slice(i + 1).forEach(q => { const d = Math.hypot(p[0] - q[0], p[1] - q[1]); if (d < 120) s += `<line x1="${p[0]}" y1="${p[1]}" x2="${q[0]}" y2="${q[1]}" stroke="#00D9FF" stroke-opacity="${(.35 - d / 400).toFixed(2)}" stroke-width=".8"/>`; }));
  const cols = ['#00D9FF', '#FF00FF', '#D9A5C4', '#C9756B', '#9EAD9D'];
  pts.forEach((p, i) => s += `<circle cx="${p[0]}" cy="${p[1]}" r="${2 + (i % 3)}" fill="${cols[i % 5]}"><animate attributeName="opacity" values=".3;1;.3" dur="${3 + (i % 4)}s" repeatCount="indefinite"/></circle>`);
  return s + '</svg>';
}
function renderOnboarding(root) {
  document.body.className = 'bare';
  root.innerHTML = `<div class="welcome"><div class="art">${netSVG()}<h1>Todo tu ecosistema,<br><em>un solo pulso.</em></h1><p>Marcas, aplicaciones, suscripciones, infraestructura y finanzas en un centro de operaciones. Empezás manual; conectás después.</p></div>
  <form class="frm" id="obForm" novalidate><div class="eyebrow">Configuración inicial</div><h2 style="font-size:24px;margin:6px 0 16px">Arranquemos</h2>
    <div class="form-grid">
      ${field('Nombre del ecosistema *', '<input class="input" name="name" placeholder="Ej.: Mi Ecosistema Digital" autofocus>', 'full')}
      ${field('Tu nombre *', '<input class="input" name="owner">')}
      ${field('Sitio principal', '<input class="input" name="site" placeholder="https://…">')}
      ${field('Moneda de referencia *', `<select class="input" name="base">${opt('', 'Elegí…', '')}${opt('ARS', 'ARS · peso argentino', '')}${opt('USD', 'USD · dólar estadounidense', '')}${opt('otra', 'Otra…', '')}</select>`, '', 'Todo se consolida en esta moneda con tipos de cambio explícitos.')}
      ${field('Formato de números', `<select class="input" name="numFormat">${opt('es', '1.234,56', 'es')}${opt('en', '1,234.56', '')}</select>`)}
      <div class="field hidden" id="otherCur"><label>Código y símbolo</label><div class="addrow"><input class="input" name="oc" placeholder="EUR" maxlength="5"><input class="input" name="os" placeholder="€" maxlength="4"></div></div>
      <label class="check full" style="margin-top:4px"><input type="checkbox" name="seed" checked> Cargar mis 3 marcas (con sus colores), canales, categorías editoriales, formatos y categorías de gastos de la descripción del negocio</label>
    </div><div class="err-msg" id="obErr" style="margin-top:10px"></div>
    <button class="btn btn-primary" style="width:100%;margin-top:16px;padding:12px">Entrar al centro de operaciones ${ic('arrow')}</button>
    <p class="tiny muted" style="margin-top:12px">No se crean ventas, clientes ni importes de ejemplo.</p></form></div>`;
  const f = $('#obForm'); f.base.onchange = () => $('#otherCur').classList.toggle('hidden', f.base.value !== 'otra');
  f.addEventListener('submit', async e => {
    e.preventDefault(); const d = formData(f), bad = [];
    if (!d.name) bad.push('name'); if (!d.owner) bad.push('owner'); if (!d.base) bad.push('base'); if (d.base === 'otra' && !d.oc) bad.push('oc');
    markInvalid(f, bad); if (bad.length) { $('#obErr').textContent = 'Completá los campos marcados.'; return; }
    try {
      await transact('setup', st => {
        const S = st.settings; S.business = { name: d.name, owner: d.owner, site: d.site }; S.numFormat = d.numFormat;
        if (d.seed) seedFromDescription(st);
        const code = d.base === 'otra' ? d.oc.toUpperCase() : d.base; S.baseCurrency = code;
        if (!S.currencies.some(c => c.code === code)) S.currencies.push({ code, symbol: d.base === 'otra' ? (d.os || code) : (code === 'USD' ? 'US$' : '$') });
        st.setupDone = true; logAct(st, 'Configuración inicial', d.name);
      });
      unlocked = true; ui.route = 'pulso'; location.hash = '#/pulso'; $('#root').innerHTML = ''; applyLens(); render(); toast('Listo. Usá ⌘K o el botón + del dock para cargar lo primero.');
    } catch (err) { }
  });
}
function renderLock(root) {
  document.body.className = 'bare';
  root.innerHTML = `<div class="welcome" style="max-width:620px"><div class="art">${netSVG()}<h1>${esc(state.settings.business.name)}</h1><p>Panel bloqueado.</p></div><form class="frm" id="lockF"><div class="eyebrow">Acceso</div><h2 style="margin:6px 0 14px">Ingresá tu PIN</h2>${field('PIN', '<input class="input" name="pin" type="password" inputmode="numeric" autocomplete="off" autofocus>')}<div class="err-msg" id="lockErr" style="margin-top:8px"></div><button class="btn btn-primary" style="width:100%;margin-top:12px">Desbloquear</button><p class="tiny muted" style="margin-top:12px">El PIN evita miradas casuales; no reemplaza la autenticación de un servidor.</p></form></div>`;
  $('#lockF').addEventListener('submit', e => { e.preventDefault(); if (e.target.pin.value === state.settings.pin) { unlocked = true; $('#root').innerHTML = ''; render(); } else { $('#lockErr').textContent = 'PIN incorrecto'; e.target.pin.value = ''; } });
}

/* =====================================================================
   TRAZABILIDAD: de dónde sale cada cifra
   ===================================================================== */
function rateNote(cur, date) { if (cur === base()) return '—'; const r = rateFor(cur, date); return r ? `${fmtN(r.rate, 4)} (${dShort(r.date)}${r.source ? ' · ' + esc(r.source) : ''})` : '<span class="neg">sin TC</span>'; }
function salesRows(filter, field, r) {
  return state.sales.filter(s => s.status !== 'anulada' && inR(s.date, r) && lensOk(saleBrand(s)) && filter(s)).sort((a, b) => b.date.localeCompare(a.date)).map(s => { const c = saleCalc(s); const v = c[field]; return { s, orig: v, conv: conv(v, s.currency, s.date) }; }).filter(x => x.orig);
}
function salesTraceTable(rows) {
  if (!rows.length) return '<p class="muted">No hay registros que sumen a esta cifra en el período.</p>';
  const tot = sum(rows, x => x.conv ?? 0);
  return `<div class="table-wrap"><table class="rt cards"><thead><tr><th>Fecha</th><th>Concepto</th><th>Canal</th><th class="right">Original</th><th class="right">TC</th><th class="right">${esc(base())}</th><th>Origen</th></tr></thead><tbody>${rows.slice(0, 300).map(x => `<tr class="click" data-act="openSale" data-id="${x.s.id}"><td class="first nowrap">${dDate(x.s.date)}</td><td data-l="Concepto">${esc(saleTitle(x.s))}</td><td data-l="Canal">${esc(channel(x.s.channelId)?.name || '—')}</td><td data-l="Original" class="right num">${money(x.orig, x.s.currency)}</td><td data-l="TC" class="right num">${rateNote(x.s.currency, x.s.date)}</td><td data-l="Convertido" class="right num">${x.conv == null ? '<span class="neg">excluido</span>' : money(x.conv)}</td><td data-l="Origen">${originTag(x.s.origin) || '<span class="origin">manual</span>'}</td></tr>`).join('')}</tbody><tfoot><tr><td>Total</td><td></td><td></td><td></td><td></td><td class="right">${money(tot)}</td><td></td></tr></tfoot></table></div>${rows.length > 300 ? '<p class="small muted">Se muestran 300; el reporte de ventas tiene todo.</p>' : ''}`;
}
function expRows(filter, r) {
  return state.expenses.filter(e => inR(e.date, r) && filter(e)).map(e => { const sh = expLensShare(e); const c = conv(e.amount, e.currency, e.date); return { e, sh, conv: c == null ? null : c * sh }; }).filter(x => x.sh > 0).sort((a, b) => b.e.date.localeCompare(a.e.date));
}
function expTraceTable(rows) {
  if (!rows.length) return '<p class="muted">No hay gastos que sumen a esta cifra en el período.</p>';
  const tot = sum(rows, x => x.conv ?? 0);
  return `<div class="table-wrap"><table class="rt cards"><thead><tr><th>Fecha</th><th>Concepto</th><th>Categoría</th><th class="right">Original</th><th class="right">Parte</th><th class="right">${esc(base())}</th><th>Origen</th></tr></thead><tbody>${rows.slice(0, 300).map(x => `<tr class="click" data-act="openExpense" data-id="${x.e.id}"><td class="first nowrap">${dDate(x.e.date)}</td><td data-l="Concepto">${esc(x.e.concept)}${x.e.nature === 'inversion' ? ' <span class="badge gold">Inversión</span>' : ''}</td><td data-l="Categoría" class="small">${esc(x.e.category)}</td><td data-l="Original" class="right num">${money(x.e.amount, x.e.currency)}</td><td data-l="Parte asignada" class="right num">${pct(x.sh * 100)}</td><td data-l="Convertido" class="right num">${x.conv == null ? '<span class="neg">sin TC</span>' : money(x.conv)}</td><td data-l="Origen">${originTag(x.e.origin) || '<span class="origin">manual</span>'}</td></tr>`).join('')}</tbody><tfoot><tr><td>Total</td><td></td><td></td><td></td><td></td><td class="right">${money(tot)}</td><td></td></tr></tfoot></table></div>`;
}
function openTrace(key) {
  const r = resolveRange(), m = metrics(r), lensTxt = ui.lens === 'all' ? 'todo el ecosistema' : brand(ui.lens)?.name;
  const [k, id] = String(key).split(':');
  const F = {
    gross: ['Ventas brutas', 'Σ cantidad × precio de lista de cada venta no anulada, convertida a ' + base() + ' con el tipo de cambio vigente a su fecha. Antes de descuentos y reembolsos.', () => salesTraceTable(salesRows(() => true, 'gross', r))],
    net: ['Ingresos netos', 'Ventas brutas − descuentos − reembolsos. Las comisiones e impuestos se restan después, en el resultado.', () => salesTraceTable(salesRows(() => true, 'net', r))],
    discount: ['Descuentos y cupones', 'Suma de descuentos aplicados en cada venta.', () => salesTraceTable(salesRows(() => true, 'discount', r))],
    refunded: ['Reembolsos', 'Importes devueltos registrados en cada venta.', () => salesTraceTable(salesRows(() => true, 'refunded', r))],
    commission: ['Comisiones', 'Cargos de plataformas, marketplaces y procesadores registrados en cada venta.', () => salesTraceTable(salesRows(() => true, 'commission', r))],
    tax: ['Impuestos y retenciones', 'Impuestos o retenciones registrados en cada venta.', () => salesTraceTable(salesRows(() => true, 'tax', r))],
    collected: ['Dinero cobrado', 'Cobros efectivamente recibidos en tus cuentas (fecha del cobro, no de la venta). No incluye transferencias entre cuentas.', () => { const rows = state.treasury.filter(t => t.type === 'cobro' && inR(t.date, r)).filter(t => { const s = byId(state.sales, t.saleId); return lensOk(s ? saleBrand(s) : ''); }); return rows.length ? `<div class="table-wrap"><table class="rt cards"><thead><tr><th>Fecha</th><th>Venta</th><th>Cuenta</th><th class="right">Importe</th><th class="right">${esc(base())}</th></tr></thead><tbody>${rows.map(t => { const s = byId(state.sales, t.saleId); const c = conv(t.amount, t.currency, t.date); return `<tr><td class="first">${dDate(t.date)}</td><td data-l="Venta">${esc(s ? saleTitle(s) : '—')}</td><td data-l="Cuenta">${esc(account(t.accountTo)?.name || '—')}</td><td data-l="Importe" class="right num">${money(t.amount, t.currency)}</td><td data-l="Convertido" class="right num">${c == null ? '<span class="neg">sin TC</span>' : money(c)}</td></tr>`; }).join('')}</tbody></table></div>` : '<p class="muted">No hay cobros registrados en el período. Marcá ventas como cobradas desde su detalle.</p>'; }],
    expenses: ['Gastos operativos', `Gastos con fecha en el período (sin los marcados como inversión). ${ui.lens === 'all' ? 'Cada gasto se cuenta una sola vez aunque esté repartido entre proyectos.' : 'Se toma solo la parte asignada a esta marca (directa o vía sus aplicaciones).'}`, () => expTraceTable(expRows(e => e.nature !== 'inversion', r))],
    invest: ['Inversión del período', 'Gastos marcados como inversión (desarrollo inicial, etc.). No restan al resultado operativo; se usan para medir recuperación.', () => expTraceTable(expRows(e => e.nature === 'inversion', r))],
    result: ['Resultado del negocio', 'Ingresos netos − comisiones − impuestos y retenciones − gastos operativos atribuibles.', () => `<div class="card" style="margin-bottom:14px">${[['Ingresos netos', m.net, 'net'], ['− Comisiones', -m.commission, 'commission'], ['− Impuestos y retenciones', -m.tax, 'tax'], ['− Gastos operativos', -m.expenses, 'expenses']].map(([l, v, t]) => `<div class="bar-row" data-trace="${t}" style="padding:8px 0;border-bottom:1px dashed var(--line);cursor:pointer"><span class="bl">${l}</span><span class="bv">${money(v)}</span></div>`).join('')}<div class="bar-row" style="padding-top:10px"><span class="bl"><b>Resultado</b></span><span class="bv ${m.result < 0 ? 'neg' : 'posv'}" style="font-size:16px">${money(m.result)}</span></div></div><p class="small muted">Tocá cada línea para ver sus registros.</p>`],
    mrr: ['Ingreso recurrente mensual (MRR)', 'Σ precio mensual del plan de cada suscripción ACTIVA hoy (planes anuales ÷ 12), convertido con el último tipo de cambio. No incluye pruebas, pagos fallidos ni canceladas.', () => { const subs = state.subs.filter(s => s.status === 'activa' && lensOk(proj(s.projectId)?.brandId)); return subs.length ? `<div class="table-wrap"><table class="rt cards"><thead><tr><th>Suscriptor</th><th>App</th><th>Plan</th><th class="right">Mensual</th><th class="right">${esc(base())}</th></tr></thead><tbody>${subs.map(s => { const p = plan(s.planId); const v = conv(planMonthly(p), p?.currency, todayStr()); return `<tr class="click" data-act="openSub" data-id="${s.id}"><td class="first">${esc(cust(s.customerId)?.name || '—')}</td><td data-l="App">${esc(proj(s.projectId)?.name || '')}</td><td data-l="Plan">${esc(p?.name || '')} · ${esc(p?.period || '')}</td><td data-l="Mensual" class="right num">${money(planMonthly(p), p?.currency)}</td><td data-l="Convertido" class="right num">${v == null ? '<span class="neg">sin TC</span>' : money(v)}</td></tr>`; }).join('')}</tbody></table></div>` : '<p class="muted">No hay suscripciones activas.</p>'; }],
    brand: ['Ingresos netos de ' + (brand(id)?.name || 'sin marca'), 'Ventas cuya marca es esta (por producto o aplicación).', () => salesTraceTable(salesRows(s => (saleBrand(s) || '_') === id, 'net', r))],
    channel: ['Ingresos netos por ' + (channel(id)?.name || 'canal sin especificar'), 'Ventas registradas con este canal.', () => salesTraceTable(salesRows(s => (s.channelId || '_') === id, 'net', r))],
    commch: ['Comisiones de ' + (channel(id)?.name || 'canal sin especificar'), 'Comisiones registradas en ventas de este canal.', () => salesTraceTable(salesRows(s => (s.channelId || '_') === id, 'commission', r))],
    product: ['Ingresos netos: ' + (product(id)?.name || ''), 'Ventas de este producto (todas sus versiones y canales).', () => salesTraceTable(salesRows(s => s.productId === id, 'net', r))],
    project: ['Ingresos netos: ' + (proj(id)?.name || ''), 'Ventas y cobros de suscripción de esta aplicación.', () => salesTraceTable(salesRows(s => saleProject(s) === id, 'net', r))],
    expgroup: ['Gastos: ' + id, 'Gastos operativos de esta categoría.', () => expTraceTable(expRows(e => e.nature !== 'inversion' && expGroup(e) === id, r))],
    recurring: ['Ingresos recurrentes', 'Ingresos netos de cobros de suscripciones.', () => salesTraceTable(salesRows(s => s.type === 'suscripcion', 'net', r))],
    onetime: ['Ventas únicas', 'Ingresos netos de ventas de pago único e ingresos externos.', () => salesTraceTable(salesRows(s => s.type !== 'suscripcion', 'net', r))],
    missing: ['Registros sin tipo de cambio', 'Estos importes NO se suman al consolidado hasta que cargues un tipo de cambio para su moneda y fecha (Finanzas → Tipos de cambio).', () => { const rows = [...state.sales.filter(s => s.status !== 'anulada' && s.currency !== base() && !rateFor(s.currency, s.date)).map(s => [s.date, 'Venta', saleTitle(s), money(saleCalc(s).net, s.currency)]), ...state.expenses.filter(e => e.currency !== base() && !rateFor(e.currency, e.date)).map(e => [e.date, 'Gasto', e.concept, money(e.amount, e.currency)])]; return rows.length ? `<div class="table-wrap"><table class="rt"><thead><tr><th>Fecha</th><th>Tipo</th><th>Concepto</th><th class="right">Importe</th></tr></thead><tbody>${rows.map(x => `<tr><td>${dDate(x[0])}</td><td>${x[1]}</td><td>${esc(x[2])}</td><td class="right num">${x[3]}</td></tr>`).join('')}</tbody></table></div><div class="btn-row" style="margin-top:12px"><button class="btn btn-primary" data-act="newRate">${ic('swap')} Cargar tipo de cambio</button></div>` : '<p class="muted">Todo convertido.</p>'; }]
  };
  const def = F[k]; if (!def) return;
  openDrawer({ title: esc(def[0]), body: `<div class="eyebrow" style="margin-bottom:6px">${esc(periodLabel())} · ${dDate(r.from)} – ${dDate(r.to)} · ${esc(lensTxt)}</div>
    <div class="card" style="background:var(--lens-soft);border-color:transparent;box-shadow:none;margin-bottom:16px"><div class="eyebrow" style="margin-bottom:4px">Cómo se calcula</div>${esc(def[1])}</div>${def[2]()}` });
  postRender(document.body);
}
/* =====================================================================
   PULSO (visión ejecutiva) Y MARCAS
   ===================================================================== */
function pageHead(eyebrow, title, desc, actions = '') {
  const lensName = ui.lens === 'all' ? 'Ecosistema' : brand(ui.lens)?.name;
  return `<div class="page-head"><div><div class="eyebrow">${esc(eyebrow)} <span class="lens-tag">◉ ${esc(lensName)}</span></div><h1>${title}</h1>${desc ? `<p>${desc}</p>` : ''}</div><div class="btn-row">${actions}</div></div>`;
}
function deltaHTML(cur, prev, has, inverse = false) {
  if (!has) return '<span class="tiny muted">sin período anterior para comparar</span>';
  if (!prev) return cur ? '<span class="delta flat">nuevo</span>' : '<span class="delta flat">= 0</span>';
  const d = (cur - prev) / Math.abs(prev) * 100, up = d >= 0, good = inverse ? !up : up;
  if (Math.abs(d) < .05) return '<span class="delta flat">= 0%</span>';
  return `<span class="delta ${good ? 'up' : 'down'}">${up ? '▲' : '▼'} ${pct(Math.abs(d))}</span>`;
}
const tile = (cls, key, label, value, sub = '', tip = '', kind = 'money') => `<div class="card tile ${cls}" ${key ? `data-trace="${key}"` : ''}>${key ? `<span class="trace-hint">${ic('trace')}</span>` : ''}<div class="lbl">${label} ${tip ? infoI(tip) : ''}</div><div class="val" ${typeof value === 'number' ? `data-count="${value}" data-kind="${kind}"` : ''}>${typeof value === 'number' ? (kind === 'money' ? money(value) : fnum(value)) : value}</div><div class="sub">${sub}</div></div>`;
const missBanner = miss => miss.size ? `<div class="card" style="margin-bottom:14px;border-color:var(--warn);background:var(--warn-bg);display:flex;gap:12px;align-items:center;flex-wrap:wrap" data-trace="missing"><span style="color:var(--warn)">${ic('alert')}</span><div style="flex:1;min-width:220px"><b>Hay importes en otras monedas sin tipo de cambio.</b><div class="small">No se suman al consolidado en ${esc(base())} para no mezclar monedas. Tocá para ver cuáles.</div></div><button class="btn btn-sm" data-act="newRate">${ic('swap')} Cargar tipo de cambio</button></div>` : '';
const IDEAS = [['Remember', 'br_estrategia', 'Aplicación de memoria personal y recordatorios'], ['Conciliación de facturas ARCA', 'br_estrategia', 'Conciliación entre sistemas de gestión y ARCA'], ['Analizador de Excel y CSV', 'br_estrategia', 'Herramienta de análisis de archivos']];

