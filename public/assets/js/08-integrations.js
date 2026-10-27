VIEWS.integraciones = {
  render() {
    if (SOURCES === null) loadSources();
    const tab = ui.tabs.integ || 'conexiones';
    let h = pageHead('Integraciones', 'Integraciones', 'Empezá manual, importá archivos y conectá tus apps de a poco. Cada fuente indica su última sincronización, errores y posibles duplicados.', `<button class="btn" data-act="importJSON">${ic('code')} Eventos JSON</button>${(SOURCES || []).length ? '<button class="btn" data-act="syncAll">Sincronizar todo</button>' : ''}<button class="btn btn-lens" data-act="importCSV">${ic('upload')} Importar CSV</button>`);
    h += `<div class="tabs">${[['conexiones', 'Fuentes de datos'], ['review', `Revisión de duplicados${state.review.length ? ' (' + state.review.length + ')' : ''}`], ['spec', 'Especificación para apps']].map(([id, l]) => `<button data-act="tab" data-k="integ" data-t="${id}" class="${tab === id ? 'on' : ''}">${l}</button>`).join('')}</div>`;
    if (tab === 'review') return h + reviewTab();
    if (tab === 'spec') return h + specTab();
    h += `<div class="app-grid">${state.integrations.map((i, k) => `<div class="card app-card" data-act="editInteg" data-id="${i.id}" style="animation-delay:${k * 40}ms"><span class="bar" style="background:var(--${{ activa: 'pos', error: 'coral', pausada: 'warn' }[i.status] || 'line-2'})"></span>
      <div style="display:flex;justify-content:space-between;gap:8px"><div><b style="font-family:var(--head)">${esc(i.name)}</b><div class="small muted">${esc(statusOf(INTEG_KINDS, i.kind).label)} · ${esc(statusOf(INTEG_METHODS, i.method).label)}</div></div>${badge(INTEG_STATUS, i.status)}</div>
      <div class="mini"><div><span>Última sinc.</span><b>${i.lastSync ? dShort(i.lastSync.slice(0, 10)) : '—'}</b></div><div><span>Importados</span><b>${fnum(i.stats?.imported || 0)}</b></div><div><span>Pendientes</span><b>${fnum(state.review.filter(r => r.source === i.name).length)}</b></div></div>
      ${srcButtons(i)}${(i.errors || []).length ? `<div class="tiny neg" style="margin-top:8px">${esc(i.errors.slice(-1)[0])}</div>` : ''}${i.projectId ? `<div class="tiny muted" style="margin-top:6px">App: ${esc(proj(i.projectId)?.name || '')}</div>` : ''}${i.notes ? `<div class="tiny muted" style="margin-top:6px">${esc(i.notes)}</div>` : ''}</div>`).join('')}
      <button class="card app-card" data-act="editInteg" style="border-style:dashed;display:grid;place-items:center;min-height:150px;color:var(--muted);font-weight:600">${ic('plus')} Nueva fuente de datos</button></div>
      ${appsPanel()}<div class="card" style="margin-top:16px"><div class="eyebrow" style="margin-bottom:6px">Cómo se evita contar dos veces</div><p class="small" style="margin:0">1) Si un registro trae el mismo <b>ID externo</b> que uno existente, se omite. 2) Si coincide fecha, importe, moneda y producto/concepto con otro registro de <b>otra fuente</b>, va a “Revisión de duplicados” en vez de importarse. 3) Los gastos compartidos se registran una vez y se reparten. 4) Los paquetes suman solo como paquete.</p></div>`;
    return h;
  }
};
ACT.editInteg = el => {
  const i = el.dataset.id ? byId(state.integrations, el.dataset.id) : null;
  const m = openModal({ title: i ? esc(i.name) : 'Nueva fuente de datos', body: `<form id="inF" class="form-grid">${field('Nombre *', `<input class="input" name="name" value="${esc(i?.name || '')}" placeholder="Ej.: Etsy, Mercado Pago, app Remember">`, 'full')}${field('Tipo', `<select class="input" name="kind">${opts(INTEG_KINDS, i?.kind || 'app')}</select>`)}${field('Método', `<select class="input" name="method">${opts(INTEG_METHODS, i?.method || 'csv')}</select>`)}${field('Estado', `<select class="input" name="status">${opts(INTEG_STATUS, i?.status || 'sin_conectar')}</select>`)}${field('App asociada', `<select class="input" name="projectId">${projOpts(i?.projectId || '')}</select>`)}${field('Notas', `<textarea class="input" name="notes" rows="2">${esc(i?.notes || '')}</textarea>`, 'full')}</form>${(i?.errors || []).length ? `<div class="eyebrow" style="margin:12px 0 6px">Errores recientes</div>${i.errors.slice(-10).reverse().map(e => `<div class="tiny neg">${esc(e)}</div>`).join('')}` : ''}<p class="tiny muted">Las conexiones por API o webhook quedan documentadas para una etapa siguiente: este panel no se conecta a servicios externos por sí solo.</p>`, footer: `${i ? `<button class="btn btn-danger" data-del style="margin-right:auto">${ic('trash')}</button><button class="btn" data-clr>Limpiar errores</button>` : ''}<button class="btn btn-primary" data-save>Guardar</button>` });
  m.el.querySelector('[data-del]')?.addEventListener('click', async () => { if (!(await confirmDialog({ title: 'Eliminar fuente', message: 'Los registros ya importados se conservan.', ok: 'Eliminar', tone: 'danger' }))) return; try { await transact('int', st => { st.integrations = st.integrations.filter(x => x.id !== i.id); }); m.close(); rerender(); } catch (e) { } });
  m.el.querySelector('[data-clr]')?.addEventListener('click', async () => { try { await transact('int', st => { const I = st.integrations.find(x => x.id === i.id); I.errors = []; if (I.status === 'error') I.status = 'activa'; }); m.close(); rerender(); } catch (e) { } });
  m.el.querySelector('[data-save]').onclick = async () => { const f = $('#inF', m.el), d = formData(f); if (!d.name) { markInvalid(f, ['name']); return; } try { await transact('int', st => { if (i) Object.assign(st.integrations.find(x => x.id === i.id), d); else st.integrations.push({ id: uid('in'), errors: [], stats: {}, lastSync: '', ...d }); }); m.close(); rerender(); } catch (e) { } };
};
function integByName(st, name) { let I = st.integrations.find(x => norm(x.name) === norm(name)); if (!I) { I = { id: uid('in'), name, kind: 'app', method: 'csv', status: 'activa', errors: [], stats: {}, lastSync: '' }; st.integrations.push(I); } I.stats = I.stats || {}; I.errors = I.errors || []; return I; }

/* ---------- utilidades de importación ---------- */
function parseCSV(text) {
  text = text.replace(/^﻿/, ''); const first = text.split(/\r?\n/)[0] || ''; const delim = (first.match(/;/g) || []).length > (first.match(/,/g) || []).length ? ';' : (first.includes('\t') ? '\t' : ',');
  const rows = []; let row = [], cell = '', q = false;
  for (let i = 0; i < text.length; i++) { const ch = text[i]; if (q) { if (ch === '"') { if (text[i + 1] === '"') { cell += '"'; i++; } else q = false; } else cell += ch; } else if (ch === '"') q = true; else if (ch === delim) { row.push(cell); cell = ''; } else if (ch === '\n' || ch === '\r') { if (ch === '\r' && text[i + 1] === '\n') i++; row.push(cell); rows.push(row); row = []; cell = ''; } else cell += ch; }
  if (cell || row.length) { row.push(cell); rows.push(row); }
  const clean = rows.filter(r => r.some(c => c.trim() !== ''));
  return { header: (clean[0] || []).map(h => h.trim()), rows: clean.slice(1) };
}
function smartNum(v) { if (v == null) return null; let s = String(v).replace(/[^\d,.\-]/g, ''); if (!s) return null; const lc = s.lastIndexOf(','), ld = s.lastIndexOf('.'); if (lc > -1 && ld > -1) { s = lc > ld ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, ''); } else if (lc > -1) { s = /,\d{1,2}$/.test(s) ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, ''); } else if ((s.match(/\./g) || []).length > 1) s = s.replace(/\./g, ''); const n = Number(s); return Number.isFinite(n) ? n : null; }
function smartDate(v, fmt) {
  const s = String(v || '').trim(); if (!s) return null; let m;
  if ((m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/))) return `${m[1]}-${pad(m[2])}-${pad(m[3])}`;
  if ((m = s.match(/^(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{2,4})/))) { let [a, b, y] = [Number(m[1]), Number(m[2]), m[3]]; if (y.length === 2) y = '20' + y; let d = a, mo = b; if (fmt === 'mdy' || (fmt === 'auto' && b > 12)) { d = b; mo = a; } if (mo > 12 || d > 31) return null; return `${y}-${pad(mo)}-${pad(d)}`; }
  const t = Date.parse(s); return Number.isNaN(t) ? null : fmtDate(new Date(t));
}
const findByName = (arr, name) => { const n = norm(name); return n ? arr.find(x => norm(x.name) === n) : null; };
function saleFingerprint(s) { return [s.date, s.currency, round2(saleCalc(s).net), norm(product(s.productId)?.name || s.concept || '')].join('|'); }
function expFingerprint(e) { return [e.date, e.currency, round2(e.amount), norm(e.concept)].join('|'); }
const SALE_FIELDS = [['date', 'Fecha *', ['fecha', 'date', 'created', 'sale date', 'order date']], ['unitPrice', 'Importe / precio *', ['importe', 'precio', 'price', 'amount', 'total', 'monto', 'item price']], ['qty', 'Cantidad', ['cantidad', 'qty', 'quantity', 'unidades']], ['currency', 'Moneda', ['moneda', 'currency']], ['product', 'Producto (nombre)', ['producto', 'product', 'item', 'title', 'titulo', 'item name']], ['externalId', 'ID externo', ['id', 'order id', 'pedido', 'order', 'transaction', 'external']], ['channel', 'Canal', ['canal', 'channel', 'plataforma', 'platform']], ['country', 'País', ['pais', 'country', 'ship country']], ['customerName', 'Cliente (nombre)', ['cliente', 'customer', 'buyer', 'nombre']], ['customerEmail', 'Cliente (email)', ['email', 'correo', 'buyer email']], ['discount', 'Descuento', ['descuento', 'discount']], ['coupon', 'Cupón', ['cupon', 'coupon', 'coupon code']], ['commission', 'Comisión', ['comision', 'fee', 'fees', 'commission']], ['tax', 'Impuestos / retenciones', ['impuesto', 'tax', 'retencion', 'vat', 'iva']], ['refunded', 'Reembolso', ['reembolso', 'refund', 'refunded']]];
const EXP_FIELDS = [['date', 'Fecha *', ['fecha', 'date']], ['concept', 'Concepto *', ['concepto', 'descripcion', 'description', 'detalle', 'concept']], ['amount', 'Importe *', ['importe', 'monto', 'amount', 'total', 'debito', 'debit']], ['currency', 'Moneda', ['moneda', 'currency']], ['category', 'Categoría', ['categoria', 'category']], ['supplier', 'Proveedor', ['proveedor', 'supplier', 'vendor', 'comercio']], ['dueDate', 'Vencimiento', ['vencimiento', 'due']], ['externalId', 'ID externo', ['id', 'referencia', 'comprobante', 'reference']]];

ACT.importCSV = () => {
  const S = state.settings;
  const m = openModal({ title: 'Importar CSV', size: 'xl', body: `<div class="form-grid c3" id="icTop">${field('Qué importás *', `<select class="input" id="icKind">${opt('ventas', 'Ventas / ingresos', '')}${opt('gastos', 'Gastos', '')}</select>`)}${field('Fuente *', `<input class="input" id="icSrc" list="icSrcL" placeholder="Ej.: Etsy, Amazon KDP, banco"><datalist id="icSrcL">${state.integrations.map(i => `<option value="${esc(i.name)}">`).join('')}</datalist>`)}${field('Archivo CSV *', '<input class="input" type="file" id="icFile" accept=".csv,text/csv,.txt">', '', 'Desde Excel: Guardar como → CSV.')}</div><div id="icMap" style="margin-top:16px"></div>`, footer: `<span class="small muted" id="icMsg" style="margin-right:auto"></span><button class="btn btn-ghost" data-cancel>Cancelar</button><button class="btn btn-primary" id="icGo" disabled>Importar</button>` });
  m.el.querySelector('[data-cancel]').onclick = () => m.close();
  let parsed = null, map = {}, fixed = {}, plan = null;
  const fields = () => $('#icKind', m.el).value === 'ventas' ? SALE_FIELDS : EXP_FIELDS;
  const guess = () => { map = {}; for (const [k, , syn] of fields()) { const i = parsed.header.findIndex(h => syn.some(s => norm(h) === s || norm(h).includes(s))); if (i >= 0 && !Object.values(map).includes(i)) map[k] = i; } };
  const drawMap = () => {
    if (!parsed) return; const isV = $('#icKind', m.el).value === 'ventas';
    const colSel = k => `<select class="input sm" data-map="${k}">${opt('', '— no importar —', '')}${parsed.header.map((h, i) => opt(i, h || 'Columna ' + (i + 1), map[k])).join('')}</select>`;
    $('#icMap', m.el).innerHTML = `<div class="eyebrow" style="margin-bottom:8px">Columnas (${parsed.rows.length} filas detectadas)</div><div class="form-grid c3">${fields().map(([k, l]) => field(l, colSel(k))).join('')}</div>
      <div class="eyebrow" style="margin:16px 0 8px">Valores fijos (si el archivo no los trae)</div><div class="form-grid c3">
      ${field('Moneda por defecto *', `<select class="input sm" data-fix="currency">${curOpts(fixed.currency)}</select>`)}${field('Formato de fecha', `<select class="input sm" data-fix="dateFmt">${opt('auto', 'Automático', fixed.dateFmt)}${opt('dmy', 'dd/mm/aaaa', fixed.dateFmt)}${opt('mdy', 'mm/dd/aaaa', fixed.dateFmt)}</select>`)}
      ${isV ? field('Canal por defecto', `<select class="input sm" data-fix="channelId">${opts(S.channels.map(c => ({ id: c.id, label: c.name })), fixed.channelId || '', 'Sin canal')}</select>`) + field('Marca por defecto', `<select class="input sm" data-fix="brandId">${brandOpts(fixed.brandId || '')}</select>`, '', 'Si el producto no se reconoce.') + field('App vinculada', `<select class="input sm" data-fix="projectId">${projOpts(fixed.projectId || '')}</select>`) + field('¿Ya cobradas?', `<select class="input sm" data-fix="payStatus">${opts(PAY_STATUS, fixed.payStatus || 'cobrado')}</select>`)
        : field('Categoría por defecto *', `<select class="input sm" data-fix="category">${catOptions(fixed.category || '')}</select>`) + field('Estado', `<select class="input sm" data-fix="status">${opt('pagado', 'Pagado', fixed.status || 'pagado')}${opt('pendiente', 'Pendiente', fixed.status)}</select>`) + field('Importes negativos', `<select class="input sm" data-fix="neg">${opt('abs', 'Tomar valor absoluto (débitos)', fixed.neg || 'abs')}${opt('skip', 'Omitir filas negativas', fixed.neg)}</select>`)}
      </div><div id="icPrev" style="margin-top:16px"></div>`;
    $$('[data-map]', m.el).forEach(s => s.onchange = () => { map[s.dataset.map] = s.value === '' ? undefined : Number(s.value); preview(); });
    $$('[data-fix]', m.el).forEach(s => { fixed[s.dataset.fix] = s.value; s.onchange = () => { fixed[s.dataset.fix] = s.value; preview(); }; });
    preview();
  };
  const preview = () => {
    const isV = $('#icKind', m.el).value === 'ventas', src = $('#icSrc', m.el).value.trim(); const get = (r, k) => map[k] != null ? (r[map[k]] ?? '').trim() : '';
    const out = { ok: [], bad: [], dup: [], review: [] }; const seenExt = new Set(state.sales.map(s => norm(s.externalId)).filter(Boolean).concat(state.expenses.map(e => norm(e.externalId)).filter(Boolean)));
    const fpsSale = new Map(state.sales.filter(s => s.status !== 'anulada').map(s => [saleFingerprint(s), s])), fpsExp = new Map(state.expenses.map(e => [expFingerprint(e), e]));
    parsed.rows.forEach((r, idx) => {
      const n = idx + 2; const date = smartDate(get(r, 'date'), fixed.dateFmt || 'auto'); const ext = get(r, 'externalId');
      if (isV) {
        let price = smartNum(get(r, 'unitPrice')); const qty = Math.max(1, Math.floor(smartNum(get(r, 'qty')) || 1));
        const p = findByName(state.products, get(r, 'product')); const ch = findByName(S.channels, get(r, 'channel'));
        const cur = (get(r, 'currency') || fixed.currency || '').toUpperCase();
        const rec = { type: 'unica', date, productId: p?.id || '', versionId: p?.versions?.[0]?.id || '', concept: p ? '' : get(r, 'product'), projectId: p?.projectId || fixed.projectId || '', brandId: p?.brandId || (fixed.projectId ? proj(fixed.projectId)?.brandId : '') || fixed.brandId || '', channelId: ch?.id || fixed.channelId || '', country: get(r, 'country'), currency: cur, qty, unitPrice: price != null ? Math.abs(price) : null, discount: Math.abs(smartNum(get(r, 'discount')) || 0), coupon: get(r, 'coupon'), commission: Math.abs(smartNum(get(r, 'commission')) || 0), tax: Math.abs(smartNum(get(r, 'tax')) || 0), refunded: Math.abs(smartNum(get(r, 'refunded')) || 0), payStatus: fixed.payStatus || 'cobrado', delivery: 'entregado', origin: 'importado', source: src, externalId: ext, _cust: { name: get(r, 'customerName'), email: get(r, 'customerEmail') } };
        const errs = []; if (!date) errs.push('fecha inválida'); if (rec.unitPrice == null) errs.push('importe inválido'); if (!S.currencies.some(c => c.code === cur)) errs.push('moneda desconocida ' + cur); if (!rec.productId && !rec.concept && !rec.projectId) errs.push('sin producto ni concepto'); if (!rec.brandId) errs.push('sin marca (elegí una por defecto)'); const c = saleCalc(rec); if (c.liquid < 0) errs.push('comisión/impuestos superan el neto');
        if (errs.length) return out.bad.push({ n, errs });
        if (ext && seenExt.has(norm(ext))) return out.dup.push({ n, why: 'ID externo ya registrado' }); if (ext) seenExt.add(norm(ext));
        const fp = saleFingerprint(rec), match = fpsSale.get(fp); if (match && match.source !== src) return out.review.push({ n, rec, matchId: match.id }); fpsSale.set(fp, { ...rec, source: src });
        out.ok.push({ n, rec });
      } else {
        let amt = smartNum(get(r, 'amount')); if (amt != null && amt < 0) { if (fixed.neg === 'skip') return out.dup.push({ n, why: 'importe negativo omitido' }); amt = Math.abs(amt); }
        const cur = (get(r, 'currency') || fixed.currency || '').toUpperCase(), sup = findByName(state.suppliers, get(r, 'supplier'));
        let cat = get(r, 'category'); const g = S.expenseGroups.find(x => x.items.some(i => norm(i) === norm(cat))); cat = g ? g.name + ' › ' + g.items.find(i => norm(i) === norm(cat)) : (fixed.category || '');
        const rec = { concept: get(r, 'concept'), supplierId: sup?.id || '', category: cat, amount: amt, currency: cur, date, dueDate: smartDate(get(r, 'dueDate'), fixed.dateFmt || 'auto') || '', frequency: 'unico', status: fixed.status || 'pagado', payDate: fixed.status === 'pendiente' ? '' : date, nature: 'operativo', costType: 'directo', alloc: { mode: 'general', targets: [] }, origin: 'importado', source: src, externalId: ext, notes: sup ? '' : get(r, 'supplier') };
        const errs = []; if (!date) errs.push('fecha inválida'); if (!rec.concept) errs.push('sin concepto'); if (!(amt > 0)) errs.push('importe inválido'); if (!S.currencies.some(c => c.code === cur)) errs.push('moneda desconocida ' + cur); if (!cat) errs.push('sin categoría');
        if (errs.length) return out.bad.push({ n, errs });
        if (ext && seenExt.has(norm(ext))) return out.dup.push({ n, why: 'ID externo ya registrado' }); if (ext) seenExt.add(norm(ext));
        const fp = expFingerprint(rec), match = fpsExp.get(fp); if (match && match.source !== src) return out.review.push({ n, rec, matchId: match.id }); fpsExp.set(fp, { ...rec });
        out.ok.push({ n, rec });
      }
    });
    plan = out;
    $('#icPrev', m.el).innerHTML = `<div class="btn-row" style="margin-bottom:10px"><span class="badge ok">${out.ok.length} listas para importar</span><span class="badge warn">${out.review.length} a revisión (posible duplicado)</span><span class="badge">${out.dup.length} omitidas</span><span class="badge bad">${out.bad.length} con errores</span></div>
      ${out.ok.length ? `<div class="table-wrap"><table class="rt"><thead><tr><th>Fila</th><th>Fecha</th><th>${isV ? 'Producto / concepto' : 'Concepto'}</th><th class="right">Importe</th><th>${isV ? 'Canal' : 'Categoría'}</th></tr></thead><tbody>${out.ok.slice(0, 8).map(x => `<tr><td class="mono small">${x.n}</td><td>${dDate(x.rec.date)}</td><td>${esc(isV ? (product(x.rec.productId)?.name || x.rec.concept) : x.rec.concept)}</td><td class="right num">${money(isV ? saleCalc(x.rec).net : x.rec.amount, x.rec.currency)}</td><td class="small">${esc(isV ? (channel(x.rec.channelId)?.name || '—') : x.rec.category)}</td></tr>`).join('')}</tbody></table></div>` : ''}
      ${out.bad.length ? `<div class="small neg" style="margin-top:10px">${out.bad.slice(0, 6).map(b => `Fila ${b.n}: ${esc(b.errs.join(', '))}`).join('<br>')}${out.bad.length > 6 ? '<br>…' : ''}</div>` : ''}`;
    $('#icGo', m.el).disabled = !(out.ok.length || out.review.length) || !src; $('#icMsg', m.el).textContent = src ? '' : 'Indicá la fuente.';
  };
  $('#icKind', m.el).onchange = () => { if (parsed) { guess(); drawMap(); } };
  $('#icSrc', m.el).oninput = () => parsed && preview();
  $('#icFile', m.el).onchange = async e => { const f = e.target.files[0]; if (!f) return; try { parsed = parseCSV(await f.text()); if (!parsed.header.length || !parsed.rows.length) throw new Error('vacío'); fixed.currency = fixed.currency || base(); guess(); drawMap(); } catch (er) { toast('No se pudo leer el CSV', 'bad'); } };
  $('#icGo', m.el).onclick = async () => {
    const isV = $('#icKind', m.el).value === 'ventas', src = $('#icSrc', m.el).value.trim(); if (!plan) return; $('#icGo', m.el).disabled = true;
    try { await transact('import', st => {
      const I = integByName(st, src); I.method = 'csv'; I.status = 'activa'; I.lastSync = new Date().toISOString();
      for (const x of plan.ok) { if (isV) { const { _cust, ...rec } = x.rec; createSale(st, rec, _cust.name || _cust.email ? { name: _cust.name || _cust.email, email: _cust.email } : null); } else st.expenses.push({ id: uid('ex'), createdAt: new Date().toISOString(), ...x.rec }); }
      for (const x of plan.review) st.review.push({ id: uid('rv'), kind: isV ? 'sale' : 'expense', data: x.rec, matchId: x.matchId, source: src, at: new Date().toISOString(), reason: 'Coincide fecha, importe, moneda y concepto con un registro de otra fuente' });
      I.stats.imported = (I.stats.imported || 0) + plan.ok.length; if (plan.bad.length) I.errors.push(`${dDate(todayStr())}: ${plan.bad.length} fila(s) con errores en la última importación`);
      logAct(st, 'Importación CSV', `${src}: ${plan.ok.length} importadas, ${plan.review.length} a revisión, ${plan.dup.length} omitidas, ${plan.bad.length} con error`); });
      toast(`Importadas ${plan.ok.length}. ${plan.review.length ? plan.review.length + ' a revisión.' : ''}`); m.close(); render(); } catch (e) { $('#icGo', m.el).disabled = false; }
  };
};
function reviewTab() {
  if (!state.review.length) return '<div class="card"><p class="muted" style="margin:0">Nada para revisar. Los posibles duplicados de importaciones aparecen acá.</p></div>';
  return `<div class="feed">${state.review.map(r => { const match = r.kind === 'sale' ? byId(state.sales, r.matchId) : byId(state.expenses, r.matchId); const d = r.data; return `<div class="card" style="margin-bottom:10px"><div class="card-h" style="margin-bottom:8px"><div><b>${r.kind === 'sale' ? 'Venta' : 'Gasto'} de ${esc(r.source)}</b><div class="tiny muted">${esc(r.reason)}</div></div><div class="btn-row"><button class="btn btn-sm" data-act="reviewDo" data-id="${r.id}" data-a="discard">Descartar</button><button class="btn btn-sm btn-primary" data-act="reviewDo" data-id="${r.id}" data-a="import">Importar igual</button></div></div>
    <div class="form-grid"><div class="card" style="box-shadow:none;background:var(--panel-2)"><div class="eyebrow">Nuevo</div><b>${esc(r.kind === 'sale' ? (product(d.productId)?.name || d.concept) : d.concept)}</b><div class="small">${dDate(d.date)} · ${money(r.kind === 'sale' ? saleCalc(d).net : d.amount, d.currency)} · ID ${esc(d.externalId || '—')}</div></div><div class="card" style="box-shadow:none;background:var(--panel-2)"><div class="eyebrow">Existente ${match ? `(${esc(match.source || match.origin || 'manual')})` : '(ya no existe)'}</div>${match ? `<b>${esc(r.kind === 'sale' ? saleTitle(match) : match.concept)}</b><div class="small">${dDate(match.date)} · ${money(r.kind === 'sale' ? saleCalc(match).net : match.amount, match.currency)} · ID ${esc(match.externalId || '—')}</div>` : ''}</div></div></div>`; }).join('')}</div>`;
}
ACT.reviewDo = async el => { const r = byId(state.review, el.dataset.id); if (!r) return; try { await transact('review', st => { st.review = st.review.filter(x => x.id !== r.id); if (el.dataset.a === 'import') { if (r.kind === 'sale') { const { _cust, ...rec } = r.data; createSale(st, rec, _cust && (_cust.name || _cust.email) ? { name: _cust.name || _cust.email, email: _cust.email } : null); } else st.expenses.push({ id: uid('ex'), createdAt: new Date().toISOString(), ...r.data }); } logAct(st, 'Revisión de duplicado', (el.dataset.a === 'import' ? 'Importado' : 'Descartado') + ' · ' + r.source); }); toast(el.dataset.a === 'import' ? 'Importado' : 'Descartado'); rerender(); } catch (e) { } };
const JSON_EXAMPLE = `[
  { "source": "remember", "external_id": "evt_001", "type": "subscription_started",
    "app": "Remember", "plan": "Pro mensual", "date": "2026-10-01",
    "amount": 4000, "currency": "ARS", "commission": 280,
    "customer_email": "ana@ejemplo.com", "customer_name": "Ana", "country": "Argentina" },
  { "source": "remember", "external_id": "evt_002", "type": "renewal",
    "app": "Remember", "date": "2026-11-01", "amount": 4000, "currency": "ARS",
    "customer_email": "ana@ejemplo.com" },
  { "source": "remember", "external_id": "evt_003", "type": "users",
    "app": "Remember", "date": "2026-11-01", "registered": 120, "active": 45 }
]`;
function specTab() {
  return `<div class="bento"><div class="card s7"><div class="card-h"><h3>Formato de eventos para tus apps</h3><button class="btn btn-sm" data-act="copySpec">Copiar ejemplo</button></div><p class="small muted" style="margin-top:0">Cada app (Flask, HTML, etc.) puede exportar o enviar sus eventos en este formato. Hoy los pegás en “Eventos JSON”; más adelante el mismo formato sirve para un webhook. Los <code>external_id</code> repetidos se ignoran.</p><pre class="mono" style="background:var(--panel-2);border:1px solid var(--line);border-radius:12px;padding:14px;overflow:auto;font-size:12px;line-height:1.5">${esc(JSON_EXAMPLE)}</pre></div>
    <div class="card s5"><div class="eyebrow" style="margin-bottom:8px">Tipos de evento</div><div class="feed small">${[['sale', 'Venta de pago único (amount, currency, product o app)'], ['subscription_started', 'Alta con primer pago (plan obligatorio)'], ['trial_started', 'Inicio de prueba'], ['renewal', 'Renovación cobrada'], ['payment_failed', 'Pago fallido'], ['cancellation', 'Cancelación'], ['plan_changed', 'Cambio de plan (plan)'], ['users', 'Foto de usuarios (registered, active)']].map(([k, l]) => `<div style="padding:6px 0;border-bottom:1px solid var(--line)"><code class="mono">${k}</code> — ${l}</div>`).join('')}</div><p class="tiny muted">Campos comunes: source, external_id, type, app (nombre exacto de la app registrada), date (AAAA-MM-DD), customer_email. Opcionales: commission, tax, country, channel.</p></div></div>`;
}
ACT.copySpec = () => { try { navigator.clipboard.writeText(JSON_EXAMPLE); toast('Ejemplo copiado'); } catch (e) { toast('No se pudo copiar', 'bad'); } };
ACT.importJSON = () => {
  const m = openModal({ title: 'Importar eventos JSON', size: 'lg', body: `<p class="small muted" style="margin-top:0">Pegá un arreglo de eventos (ver "Especificación para apps") o subí un archivo .json.</p><textarea class="input mono" id="ijT" rows="12" style="font-size:12px" placeholder='[{"source":"mi-app","external_id":"...","type":"sale", ...}]'></textarea><div class="btn-row" style="margin-top:8px"><input type="file" accept=".json,application/json" id="ijF" class="input" style="max-width:320px"></div><div id="ijRes" style="margin-top:12px"></div>`, footer: `<button class="btn btn-ghost" data-cancel>Cerrar</button><button class="btn btn-primary" data-go>Procesar</button>` });
  m.el.querySelector('[data-cancel]').onclick = () => m.close();
  $('#ijF', m.el).onchange = async e => { const f = e.target.files[0]; if (f) $('#ijT', m.el).value = await f.text(); };
  m.el.querySelector('[data-go]').onclick = async () => {
    let evs; try { evs = JSON.parse($('#ijT', m.el).value); if (!Array.isArray(evs)) evs = evs.events || evs.data || [evs]; } catch (e) { toast('JSON inválido', 'bad'); return; }
    let res; try { res = await transact('json', st => processEvents(st, evs)); } catch (e) { return; }
    $('#ijRes', m.el).innerHTML = `<div class="btn-row"><span class="badge ok">${res.ok} procesados</span><span class="badge">${res.skip} ya existían</span><span class="badge bad">${res.err.length} con error</span></div>${res.err.length ? `<div class="small neg" style="margin-top:8px">${res.err.slice(0, 10).map(esc).join('<br>')}</div>` : ''}`;
    render();
  };
};
let SOURCES = null, sourcesLoading = false;
async function loadSources() {
  if (sourcesLoading) return; sourcesLoading = true;
  try { const r = await fetch('api.php?action=sources_list', { credentials: 'same-origin', cache: 'no-store' }); const d = await r.json(); SOURCES = r.ok && d.ok && Array.isArray(d.sources) ? d.sources : []; }
  catch (e) { SOURCES = []; }
  sourcesLoading = false;
  if ((SOURCES || []).length) render();
}
const srcFor = i => (SOURCES || []).find(s => s.id === norm(i.name) || norm(s.name) === norm(i.name));
function srcButtons(i) {
  const s = srcFor(i); if (!s) return '';
  return `<div class="btn-row" style="margin-top:10px"><button class="btn btn-sm" data-act="syncOne" data-id="${esc(s.id)}">Sincronizar</button><button class="btn btn-sm" data-act="viewApp" data-id="${esc(s.id)}">Ver app</button></div>`;
}
async function fetchSource(id, view, days) {
  try {
    const r = await fetch('api.php?action=source_data&id=' + encodeURIComponent(id) + '&view=' + view + (days ? '&d=' + encodeURIComponent(days) : ''), { credentials: 'same-origin', cache: 'no-store' });
    const d = await r.json();
    if (!r.ok || !d.ok) return { error: d.code || 'source_unavailable' };
    return { data: d.data };
  } catch (e) { return { error: 'connection_error' }; }
}
async function syncSources(list) {
  let ok = 0, skip = 0, bad = 0;
  for (const s of list) {
    const r = await fetchSource(s.id, 'events');
    if (r.error) { bad++; toast(`${s.name}: ${r.error}`, 'bad'); continue; }
    let res; try { res = await transact('source', st => processEvents(st, r.data.events)); } catch (e) { bad++; continue; }
    ok += res.ok; skip += res.skip; bad += res.err.length;
  }
  toast(`Sincronización: ${ok} procesados, ${skip} repetidos, ${bad} con error.`, bad ? 'bad' : undefined);
  render();
}
const FUENTES_ERR = { id_existente: 'Ya hay una app con ese nombre.', invalid_request: 'Revisá el nombre, la URL (https y dominio estrategia.site) y el token (mínimo 32 caracteres).', solo_tabla: 'Esa app viene de la configuración del servidor y no se puede quitar desde acá.', source_unavailable: 'No pude conectar con la app. Revisá que el export.php esté subido y que el token sea el mismo.', db_no_disponible: 'La base de datos del Centro no está disponible para guardar apps.' };
const fuenteErr = code => FUENTES_ERR[code] || String(code);
async function apiPost(action, payload) {
  let token = '';
  try { const sr = await fetch('api.php?action=session', { credentials: 'same-origin', cache: 'no-store' }); token = (await sr.json()).csrf || ''; } catch (e) { return { error: 'connection_error' }; }
  try {
    const r = await fetch('api.php?action=' + action, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token }, body: JSON.stringify(payload) });
    const d = await r.json();
    if (!r.ok || !d.ok) return { error: d.code || 'source_unavailable' };
    return { data: d };
  } catch (e) { return { error: 'connection_error' }; }
}
function appsPanel() {
  const list = SOURCES || [];
  const rows = list.map(s => `<tr><td>${esc(s.name)}</td><td class="small muted">${esc(s.host || '—')}</td><td><span class="badge">${s.origen === 'tabla' ? 'Centro' : 'Config'}</span></td><td>${s.origen === 'tabla' ? `<button class="btn btn-sm" data-act="quitarApp" data-id="${esc(s.id)}">Quitar</button>` : ''}</td></tr>`).join('');
  return `<div class="card" style="margin-top:16px"><div class="card-h" style="margin-bottom:8px"><div class="eyebrow">Apps conectadas</div><button class="btn btn-sm btn-primary" data-act="conectarApp">+ Conectar app</button></div>${rows ? `<div class="table-wrap"><table class="rt"><thead><tr><th>Nombre</th><th>Host</th><th>Origen</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>` : '<p class="small muted" style="margin:0">Todavía no hay apps conectadas.</p>'}</div>`;
}
ACT.quitarApp = async el => {
  if (!(await confirmDialog({ title: 'Quitar app', message: 'Se desconecta esta app del Centro. Los datos ya importados se conservan.', ok: 'Quitar', tone: 'danger' }))) return;
  const res = await apiPost('fuentes_quitar', { id: el.dataset.id });
  if (res.error) { toast(fuenteErr(res.error), 'bad'); return; }
  toast('App quitada');
  await loadSources(); render();
};
ACT.conectarApp = () => {
  const m = openModal({ title: 'Conectar app', size: 'lg', body: `<form id="caF" class="form-grid">${field('Nombre *', '<input class="input" name="nombre" maxlength="80" placeholder="Ej.: Gaku">', 'full')}${field('URL del export.php *', '<input class="input" name="url" maxlength="300" placeholder="https://zuhause.estrategia.site/export.php">', 'full')}${field('Token *', '<input class="input" name="token" type="password" autocomplete="off" maxlength="200">', 'full')}</form><div id="caR" style="margin-top:12px"></div>`, footer: `<button class="btn btn-ghost" data-cancel>Cancelar</button><button class="btn btn-primary" data-go>Conectar</button>` });
  m.el.querySelector('[data-cancel]').onclick = () => m.close();
  const go = m.el.querySelector('[data-go]'), out = $('#caR', m.el), f = $('#caF', m.el);
  go.onclick = async () => {
    const nombre = f.elements.nombre.value.trim(), url = f.elements.url.value.trim(), token = f.elements.token.value;
    if (!nombre || !url || !token) { out.innerHTML = '<div class="small neg">Completá nombre, URL y token.</div>'; return; }
    go.disabled = true; out.innerHTML = '<p class="small muted">Conectando...</p>';
    const res = await apiPost('fuentes_agregar', { nombre, url, token });
    f.elements.token.value = '';
    if (res.error) { go.disabled = false; out.innerHTML = `<div class="small neg">${esc(fuenteErr(res.error))}</div>`; return; }
    const id = res.data.id;
    await loadSources();
    const t = await fetchSource(id, 'summary');
    if (!t.error) { toast('App conectada. Ya recibimos sus datos.'); m.close(); render(); return; }
    out.innerHTML = `<div class="small neg">${esc(fuenteErr(t.error))}</div><button class="btn btn-sm" id="caQ" style="margin-top:8px">Quitar</button>`;
    $('#caQ', m.el).onclick = async () => {
      const q = await apiPost('fuentes_quitar', { id });
      if (q.error) { toast(fuenteErr(q.error), 'bad'); return; }
      toast('Conexión deshecha'); await loadSources(); m.close(); render();
    };
  };
};
ACT.syncAll = () => syncSources(SOURCES || []);
ACT.syncOne = el => syncSources((SOURCES || []).filter(s => s.id === el.dataset.id));
const SUMMARY_LABELS = { cuentas_total: 'Clientes totales', con_acceso: 'Con acceso ahora', pagando: 'Pagando', en_prueba: 'En prueba (MP)', gracia: 'Cortesía / gracia', solo_lectura: 'Solo lectura', bloqueados: 'Suspendidos', suscripciones_iniciadas_30d: 'Suscripciones (30 días)', landing_visitas_hoy: 'Visitas hoy', landing_visitas_30d: 'Visitas (30 días)', landing_llegaron_precio_30d: 'Llegaron al precio (30 días)', landing_clicks_suscribirme_30d: 'Clics en Suscribirme (30 días)', landing_clicks_whatsapp_30d: 'Clics en WhatsApp (30 días)', landing_clicks_app_30d: 'Clics a la app (30 días)', landing_conversion_pct: 'Conversión (%)', landing_ultimo_evento: 'Último evento de la landing', landing_estado: 'Estado de la landing', generado: 'Generado' };
const ACCESS_LABELS = { activo: 'Con acceso', solo_lectura: 'Solo lectura', bloqueado: 'Suspendido' };
const p2 = n => String(n).padStart(2, '0');
function fmtDMY(v) { const s = String(v ?? ''); const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? `${m[3]}/${m[2]}/${m[1]}` : (s || '—'); }
function fmtDMYHM(v) { const t = new Date(String(v).replace(' ', 'T')); return Number.isNaN(t.getTime()) ? String(v) : `${p2(t.getDate())}/${p2(t.getMonth() + 1)}/${t.getFullYear()} ${p2(t.getHours())}:${p2(t.getMinutes())}`; }
function summaryValue(k, v) {
  if (v === null || v === undefined) return '—';
  if (k === 'landing_ultimo_evento' || k === 'generado') return fmtDMYHM(v);
  return v && typeof v === 'object' ? JSON.stringify(v) : String(v);
}
async function sourceAction(id, accion, campos) {
  let token = '';
  try { const sr = await fetch('api.php?action=session', { credentials: 'same-origin', cache: 'no-store' }); token = (await sr.json()).csrf || ''; } catch (e) { return { error: 'connection_error' }; }
  const payload = { ...campos, id, accion };
  try {
    const r = await fetch('api.php?action=source_action', { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token }, body: JSON.stringify(payload) });
    const d = await r.json();
    if (!r.ok || !d.ok) return { error: d.detalle || d.code || 'source_unavailable' };
    return { result: d.result };
  } catch (e) { return { error: 'connection_error' }; }
}
const CAT_TXT = { pago: 'Pagando', prueba: 'En prueba (MP)', manual: 'Gracia', solo_lectura: 'Solo lectura', bloqueado: 'Suspendido' };
const ERR_MSG = { email_ya_tiene_cuenta: 'Ese email ya tiene una cuenta. Buscala y usá Dar gracia.', tiene_suscripcion_mp: 'Esa cuenta tiene suscripción activa en Mercado Pago.', sin_suscripcion_mp: 'Esa cuenta no tiene suscripción en Mercado Pago.', email_invalido: 'El email no es válido.', nombre_invalido: 'El nombre no es válido.', clave_invalida: 'La clave no es válida.', cuenta_no_encontrada: 'No se encontró la cuenta.' };
const errText = code => ERR_MSG[code] || String(code);
function estadoTxt(c) { let t = CAT_TXT[c.categoria] || String(c.estado ?? c.categoria ?? '—'); if (c.override === 'activo') t += ' · Cortesía'; else if (c.override === 'bloqueado') t += ' · Bloqueado'; return t; }
function venceTxt(c) {
  const base = c.categoria === 'pago' ? (c.proximo_cobro || c.vence) : (c.vence || c.proximo_cobro);
  let t = base ? esc(fmtDMYHM(base)) : '—';
  const n = c.dias_restantes === null || c.dias_restantes === undefined ? NaN : Number(c.dias_restantes);
  if (Number.isFinite(n) && n >= 0) t += `<br><span class="small muted">faltan ${n} ${n === 1 ? 'día' : 'días'}</span>`;
  return t;
}
const nz = v => Number(v) || 0;
const pesosCent = c => '$ ' + Math.round(nz(c) / 100).toLocaleString('es-AR');
const pct1 = (a, b) => nz(b) > 0 ? (nz(a) / nz(b) * 100).toFixed(1).replace('.', ',') + '%' : '—';
const fdm = v => { const m = String(v ?? '').match(/(\d{4})-(\d{2})-(\d{2})/); return m ? `${m[3]}/${m[2]}` : String(v ?? '—'); };
const fdmhm = v => { const t = new Date(String(v).replace(' ', 'T')); return Number.isNaN(t.getTime()) ? String(v ?? '—') : `${p2(t.getDate())}/${p2(t.getMonth() + 1)} ${p2(t.getHours())}:${p2(t.getMinutes())}`; };
const rowsOf = a => Array.isArray(a) ? a : (a && typeof a === 'object' ? Object.entries(a).map(([k, v]) => (v && typeof v === 'object' ? { nombre: k, ...v } : { nombre: k, clicks: v })) : []);
const pick = (x, ...ks) => { for (const k of ks) if (x[k] !== undefined && x[k] !== null) return x[k]; return ''; };
const bigCard = (label, num, sub) => `<div class="card"><div class="eyebrow">${esc(label)}</div><div style="font-family:var(--head);font-size:28px;font-weight:700;line-height:1.2">${esc(String(num))}</div><div class="tiny muted">${esc(sub || '')}</div></div>`;
const TRACK_TAG = esc('<script src="track.js" defer></script>');
const alertBox = html => `<div class="card" style="border-left:3px solid var(--warn);margin-bottom:8px"><div class="small">${html}</div></div>`;
function resumenHtml(R, per) {
  if (R.result && typeof R.result === 'object') R = R.result;
  const L = R.landing || {}, T = L.totales || {}, H = L.hoy || {};
  const V = nz(T.visitas), P = nz(T.precio), C = nz(T.clicks_suscribirme), SI = nz(R.subs_iniciadas), SC = nz(R.subs_con_cuenta), SP = nz(R.subs_con_pago);
  const venc = rowsOf(R.por_vencer);
  const al = [];
  if (nz(R.sin_cuenta_pagaron) > 0) al.push(`${nz(R.sin_cuenta_pagaron)} persona(s) se suscribieron pero no crearon su acceso. Reenviales el link de alta desde Pagos.`);
  if (nz(R.eventos_mp_con_error_24h) > 0) al.push(`${nz(R.eventos_mp_con_error_24h)} notificación(es) de Mercado Pago con error o firma inválida en las últimas 24 h.`);
  if (nz(R.cobros_rechazados_14d) > 0) al.push(`${nz(R.cobros_rechazados_14d)} cobro(s) rechazados en reintento en los últimos 14 días.`);
  if (nz(R.por_vencer_3d) > 0) al.push(`${nz(R.por_vencer_3d)} cuenta(s) de prueba o gracia vencen en 3 días o menos.`);
  let alHtml = al.map(t => alertBox(esc(t))).join('');
  if (!L.tiene_datos) alHtml += alertBox(`Todavía no llegó ninguna visita de la landing. Falta agregar <code>${TRACK_TAG}</code> antes de &lt;/body&gt; en index.html.`);
  else if (L.ultimo_evento && Date.now() - new Date(String(L.ultimo_evento).replace(' ', 'T')).getTime() > 3 * 86400000) alHtml += alertBox(esc(`La landing no registra visitas desde hace más de 3 días (última: ${fmtDMYHM(L.ultimo_evento)}).`));
  if (!alHtml) alHtml = '<div class="card small">Todo en orden: sin alertas.</div>';
  const cards = [
    bigCard('Visitantes hoy', nz(H.visitas), `últimos ${per} días: ${V}`),
    bigCard(`Clics en Suscribirme (${per} días)`, C, pct1(C, V) + ' de las visitas'),
    bigCard(`Suscripciones iniciadas (${per} días)`, SI, `${nz(R.subs_autorizadas)} autorizadas en Mercado Pago`),
    bigCard('Clientes con acceso', nz(R.con_acceso), `${nz(R.pagando)} pagando · ${nz(R.en_prueba)} en prueba · ${nz(R.gracia)} gracia`),
    bigCard('Ingreso mensual (pagando)', pesosCent(R.mrr_cent), `${nz(R.solo_lectura)} solo lectura · ${nz(R.bloqueados)} suspendidos`)
  ].join('');
  const steps = [['Visitaron la landing', V, 'visitantes únicos por día'], ['Vieron el precio', P, 'llegaron a la sección de precio'], ['Tocaron Suscribirme', C, 'clic en cualquier botón'], ['Iniciaron suscripción en Mercado Pago', SI, 'checkout de Mercado Pago'], ['Crearon su cuenta', SC, 'usuario y clave en la app'], ['Ya les cobraron', SP, 'primer pago aprobado']];
  const funnel = steps.map(([l, n, sub], i) => `<div style="margin-bottom:10px"><div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><span class="small"><b>${esc(l)}</b> <span class="tiny muted">${esc(sub)}</span></span><span class="small"><b>${n}</b>${i ? ` <span class="muted">${pct1(n, steps[i - 1][1])}</span>` : ''}</span></div><div style="height:10px;border-radius:6px;background:var(--line);overflow:hidden;margin-top:4px"><div style="height:100%;width:${Math.max(2, V > 0 ? Math.min(100, n / V * 100) : 0)}%;background:var(--pos)"></div></div></div>`).join('');
  const vencHtml = venc.length ? `<div class="table-wrap"><table class="rt"><tbody>${venc.map(x => { const cat = String(pick(x, 'categoria', 'tipo')); return `<tr><td>${esc(String(pick(x, 'nombre')))}</td><td class="small muted">${esc(String(pick(x, 'email')))}</td><td><span class="badge">${cat === 'prueba' ? 'prueba' : 'gracia'}</span></td><td class="small">${esc(fdmhm(pick(x, 'vence', 'fecha')))}</td></tr>`; }).join('')}</tbody></table></div>` : '<p class="small muted">Ninguna en los próximos 3 días.</p>';
  const tips = [];
  if (V >= 20 && C === 0) tips.push('Hay visitas pero nadie toca Suscribirme. Probá desde tu celular que los botones abran el link de Mercado Pago.');
  if (C >= 5 && SI === 0) tips.push('Tocan Suscribirme pero no se inicia ninguna suscripción. Abrí el link del plan en una ventana privada y fijate si Mercado Pago lo muestra bien. También revisá que el webhook llegue.');
  if (SI > 0 && SC < SI) tips.push(`Hay ${SI - SC} suscripción(es) sin cuenta creada. Esa gente pagó o se suscribió y no completó el alta.`);
  if (V > 0 && P * 100 < V * 25) tips.push('Menos del 25% llega a ver el precio: la portada puede no estar invitando a seguir bajando.');
  if (V < 20 && C < 5 && SI < 1) tips.push('Todavía hay pocos datos para sacar conclusiones. Mirá de nuevo con más visitas.');
  return `<div style="margin-bottom:12px">${alHtml}</div><div class="app-grid" style="margin-bottom:16px">${cards}</div><div class="card" style="margin-bottom:16px"><div class="eyebrow" style="margin-bottom:8px">Embudo (${per} días)</div>${funnel}<p class="tiny muted" style="margin:6px 0 0">Las visitas cuentan visitantes únicos por día. Los pasos de Mercado Pago salen de las suscripciones reales.</p></div><div class="card" style="margin-bottom:16px"><div class="eyebrow" style="margin-bottom:8px">Cuentas que vencen pronto</div>${vencHtml}</div>${tips.length ? `<div class="card"><div class="eyebrow" style="margin-bottom:8px">¿Qué me dicen estos números?</div><ul class="small" style="margin:0;padding-left:18px">${tips.map(t => `<li>${esc(t)}</li>`).join('')}</ul></div>` : ''}`;
}
function landingTable(title, head, rows) {
  return `<div class="card" style="margin-bottom:16px"><div class="eyebrow" style="margin-bottom:8px">${esc(title)}</div>${rows.length ? `<div class="table-wrap"><table class="rt"><thead><tr>${head.map(h => `<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.map(r => `<tr>${r.map(c => `<td class="small">${esc(String(c))}</td>`).join('')}</tr>`).join('')}</tbody></table></div>` : '<p class="small muted">Todavía ninguno.</p>'}</div>`;
}
function landingHtml(R, per) {
  if (R.result && typeof R.result === 'object') R = R.result;
  const L = R.landing || {}, T = L.totales || {}, H = L.hoy || {};
  if (!L.tiene_datos) return `<div class="card"><div class="small"><b>Todavía no llegó ninguna visita de la landing.</b> Falta agregar <code>${TRACK_TAG}</code> antes de &lt;/body&gt; en index.html.</div><ol class="small" style="margin:8px 0 0;padding-left:18px"><li>Subí track.php y track.js a la raíz.</li><li>Agregá la línea del script antes de &lt;/body&gt;.</li><li>Entrá desde otro dispositivo y volvé a mirar.</li></ol></div>`;
  const V = nz(T.visitas);
  const cards = [
    bigCard('Visitantes (únicos por día)', V, `hoy: ${nz(H.visitas)}`),
    bigCard('Llegaron al precio', nz(T.precio), pct1(T.precio, V) + ' de las visitas'),
    bigCard('Clics en Suscribirme', nz(T.clicks_suscribirme), `hoy: ${nz(H.clicks)} · ${pct1(T.clicks_suscribirme, V)}`),
    bigCard('Clics en WhatsApp', nz(T.clicks_whatsapp), pct1(T.clicks_whatsapp, V) + ' de las visitas'),
    bigCard('Scroll hasta 90%', nz(T.scroll90), `50%: ${pct1(T.scroll50, V)}`),
    bigCard('Ya tengo cuenta', nz(T.clicks_app), 'clics hacia la app')
  ].join('');
  const dias = rowsOf(L.por_dia);
  const dv = x => nz(pick(x, 'visitas')), dc = x => nz(pick(x, 'clicks', 'clicks_suscribirme')), dw = x => nz(pick(x, 'clicks_whatsapp', 'whatsapp')), dd = x => pick(x, 'dia', 'fecha', 'date');
  const maxV = Math.max(1, ...dias.map(dv));
  const chart = dias.length ? `<div style="overflow-x:auto"><div style="display:flex;align-items:flex-end;gap:3px;height:120px;min-width:${dias.length * 9}px">${dias.map(x => `<div title="${esc(`${fdm(dd(x))}: ${dv(x)} visitas, ${dc(x)} clics`)}" style="flex:1;min-width:6px;height:100%;position:relative;display:flex;align-items:flex-end"><div style="width:100%;height:${Math.max(1, dv(x) / maxV * 100)}%;background:var(--line-2);border-radius:3px 3px 0 0;position:relative"><div style="position:absolute;left:0;right:0;bottom:0;height:${dv(x) > 0 ? Math.min(100, dc(x) / dv(x) * 100) : 0}%;background:var(--pos);border-radius:3px 3px 0 0"></div></div></div>`).join('')}</div></div><div class="tiny muted" style="margin-top:6px">Claro: visitantes. Oscuro: clics en Suscribirme.</div>` : '<p class="small muted">Todavía ninguno.</p>';
  const dev = { movil: 'Celular', tablet: 'Tablet', pc: 'Computadora' }, btnT = { menu: 'Menú superior', portada: 'Portada', precio: 'Sección de precio' };
  const conv = x => pct1(dc(x), dv(x));
  const origen = rowsOf(L.origen).map(x => { const n = String(pick(x, 'origen', 'nombre', 'clave')); return [n, dv(x), dc(x), conv(x)]; });
  const disp = rowsOf(L.dispositivo).map(x => { const n = String(pick(x, 'dispositivo', 'nombre', 'clave')); return [dev[n] || n, dv(x), dc(x), conv(x)]; });
  const bot = rowsOf(L.boton).map(x => { const n = String(pick(x, 'boton', 'nombre', 'clave')); return [btnT[n] || n, nz(pick(x, 'clicks', 'total'))]; });
  const det = dias.slice().reverse().map(x => [fdm(dd(x)), dv(x), dc(x), conv(x), dw(x)]);
  return `<div class="app-grid" style="margin-bottom:16px">${cards}</div><div class="card" style="margin-bottom:16px"><div class="eyebrow" style="margin-bottom:8px">Visitantes y clics por día (${per} días)</div>${chart}</div>${landingTable('De dónde llegan', ['Origen', 'Visitas', 'Clics', 'Conversión'], origen)}${landingTable('Dispositivo', ['Dispositivo', 'Visitas', 'Clics', 'Conversión'], disp)}${landingTable('Qué botón tocan', ['Botón', 'Clics'], bot)}${landingTable('Detalle por día', ['Día', 'Visitantes', 'Clics Suscribirme', 'Conversión', 'WhatsApp'], det)}<p class="tiny muted">Último evento recibido: ${esc(L.ultimo_evento ? fmtDMYHM(L.ultimo_evento) : '—')}</p><p class="tiny muted">En el link de tu bio de Instagram usá ?utm_source=instagram_bio y en cada Reel ?utm_source=reel.</p>`;
}
const pill = (txt, kind) => `<span class="badge${kind ? ' ' + kind : ''}">${esc(String(txt))}</span>`;
const fdt = v => v ? esc(fmtDMYHM(v)) : '—';
const dash = v => v === null || v === undefined || v === '' ? '—' : esc(String(v));
const listOf = a => Array.isArray(a) ? a : [];
function htmlTable(head, rows, empty) {
  return `<div class="table-wrap"><table class="rt"><thead><tr>${head.map(h => `<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.length ? rows.map(r => `<tr>${r.map(c => `<td class="small">${c}</td>`).join('')}</tr>`).join('') : `<tr><td colspan="${head.length}" class="small muted">${esc(empty)}</td></tr>`}</tbody></table></div>`;
}
function montoTxt(cent, cur) {
  const n = Math.round(nz(cent) / 100).toLocaleString('es-AR');
  const c = String(cur || '').toUpperCase();
  return esc(c === 'ARS' || !c ? '$ ' + n : c + ' ' + n);
}
function pagosHtml(d) {
  const sc = listOf(d.sin_cuenta), cu = listOf(d.cuotas);
  const a = sc.map((x, i) => [esc(String(x.email_pago ?? '—')), x.estado === 'authorized' ? pill('Autorizada', 'ok') : pill('Sin autorizar', 'warn'), fdt(x.creada), x.link_alta ? `<div class="btn-row"><button class="btn btn-sm" data-cp="${i}">Copiar link</button><button class="btn btn-sm" data-vl="${i}">Ver link</button></div>` : '—']);
  const b = cu.map(x => [fdt(x.fecha), dash(x.email), (x.estado === 'processed' ? pill('Cobrado', 'ok') : x.estado === 'recycling' ? pill('Rechazado, reintentando', 'bad') : pill(x.estado ?? '—')) + (nz(x.reintento) > 0 ? `<div class="tiny muted">(reintento ${nz(x.reintento)})</div>` : ''), montoTxt(x.monto_cent, x.moneda), dash(x.detalle)]);
  return `<div id="vaL"></div><div class="card" style="margin-bottom:16px"><div class="eyebrow" style="margin-bottom:8px">Se suscribieron pero no crearon su acceso</div>${a.length ? htmlTable(['Email del pago', 'Estado', 'Fecha', 'Link de alta'], a, '') : '<p class="small muted">Nadie está esperando su alta.</p>'}</div><div class="card"><div class="eyebrow" style="margin-bottom:8px">Cuotas cobradas</div>${cu.length ? htmlTable(['Fecha', 'Cliente', 'Estado', 'Monto', 'Detalle'], b, '') : '<p class="small muted">Sin cobros todavía. Si hay gente en prueba, el primer cobro aparece cuando termine.</p>'}</div>`;
}
function sistemaHtml(d) {
  const g = d.diagnostico || {};
  const yn = (v, yes, no, noKind) => v ? pill(yes, 'ok') : pill(no, noKind || 'bad');
  const diag = [
    ['Credencial de Mercado Pago', yn(g.mp_token_cargado, 'cargada', 'falta')],
    ['Clave secreta del webhook', yn(g.webhook_secret_cargado, 'cargada', 'falta')],
    ['Link de suscripción', yn(g.link_suscripcion_ok, 'configurado', 'sin completar')],
    ['Sitio con HTTPS', yn(g.https_ok, 'sí', 'no')],
    ['Envío de mails', g.mail_real ? pill('real', 'ok') : pill('modo prueba (no envía)', 'warn')],
    ['Última notificación de Mercado Pago', d.ultima_notificacion_mp ? esc(fmtDMYHM(d.ultima_notificacion_mp)) : pill('ninguna todavía', 'warn')],
    ['Notificaciones con error (24 h)', nz(d.notificaciones_error_24h) > 0 ? pill(nz(d.notificaciones_error_24h), 'bad') : pill('0', 'ok')],
    ['Contador de la landing', d.landing_ultimo_evento ? pill('recibiendo (último ' + fmtDMYHM(d.landing_ultimo_evento) + ')', 'ok') : pill('sin datos: falta track.js en la landing', 'warn')]
  ];
  const nt = listOf(d.notificaciones).map(x => [fdt(x.recibido), dash(x.topic), dash(x.data_id), x.firma_ok === true ? pill('ok', 'ok') : x.firma_ok === false ? pill('no', 'bad') : '—', x.resultado === 'ok' ? pill('ok', 'ok') : (x.resultado === 'error' || x.resultado === 'firma_invalida') ? pill(x.resultado, 'bad') : dash(x.resultado), `<span class="tiny">${dash(x.detalle)}</span>`]);
  const au = listOf(d.auditoria).map(x => [fdt(x.ts), dash(x.actor), dash(x.accion), `<span class="tiny">${dash(x.detalle)}</span>`]);
  return `<div class="card" style="margin-bottom:16px"><div class="eyebrow" style="margin-bottom:8px">Diagnóstico</div>${htmlTable(['Control', 'Estado'], diag, '')}</div><div class="card" style="margin-bottom:16px"><div class="eyebrow" style="margin-bottom:8px">Notificaciones de Mercado Pago (últimas 40)</div>${htmlTable(['Recibido', 'Tema', 'ID', 'Firma', 'Resultado', 'Detalle'], nt, 'Sin registros todavía.')}</div><div class="card"><div class="eyebrow" style="margin-bottom:8px">Auditoría (últimas 60)</div>${htmlTable(['Fecha', 'Quién', 'Acción', 'Detalle'], au, 'Sin registros todavía.')}</div>`;
}
ACT.viewApp = el => {
  const s = (SOURCES || []).find(x => x.id === el.dataset.id); if (!s) return;
  const tabs = [['summary', 'Resumen'], ['customers', 'Clientes'], ['landing', 'Landing'], ['pagos', 'Pagos'], ['sistema', 'Sistema']];
  const m = openModal({ title: esc(s.name), size: 'lg', body: `<div class="tabs" id="vaT">${tabs.map(([k, l]) => `<button data-vt="${k}">${l}</button>`).join('')}</div><div id="vaB" style="margin-top:12px"></div>`, footer: `<button class="btn btn-ghost" data-cancel>Cerrar</button>` });
  m.el.querySelector('[data-cancel]').onclick = () => m.close();
  const body = $('#vaB', m.el); let seq = 0;
  const err = code => `<div class="small neg">Error: ${esc(code)}</div>`;
  const cell = v => v && typeof v === 'object' ? JSON.stringify(v) : String(v ?? '');
  let custList = [], fText = '', fCat = '';
  const showResult = html => { const l = $('#vaL', m.el); if (l) l.innerHTML = html ? `<div style="margin-bottom:12px">${html}</div>` : ''; };
  const reload = async () => {
    const r = await fetchSource(s.id, 'customers');
    if (r.error) { toast(errText(r.error), 'bad'); return; }
    if (Array.isArray(r.data?.customers)) { custList = r.data.customers; drawTable(); }
  };
  const drawTable = () => {
    const tbl = $('#vaTbl', m.el); if (!tbl) return;
    const q = norm(fText);
    const list = custList.filter(c => (!fCat || c.categoria === fCat) && (!q || norm(`${c.nombre ?? ''} ${c.email ?? ''}`).includes(q)));
    if (!list.length) { tbl.innerHTML = `<p class="small muted">${custList.length ? 'Ningún cliente coincide con el filtro.' : 'No hay clientes registrados.'}</p>`; return; }
    const btn = (c, a, v, l) => `<button class="btn btn-sm" data-ac="${a}" data-v="${v}" data-id="${esc(s.id)}" data-cuenta="${Number(c.id)}">${l}</button>`;
    const acts = c => {
      if (!(Number.isInteger(Number(c.id)) && Number(c.id) > 0)) return '<span class="small muted">Sin ID de cuenta</span>';
      return `<div class="btn-row">${btn(c, 'dar_gracia', '', 'Dar / extender gracia')}${c.categoria === 'manual' ? btn(c, 'quitar_gracia', '', 'Quitar gracia') : ''}${btn(c, 'set_acceso', 'bloqueado', 'Suspender')}${btn(c, 'set_acceso', 'activo', 'Activar')}${btn(c, 'set_acceso', 'auto', 'Volver a automático')}${btn(c, 'link_clave', '', 'Link de clave')}${c.categoria === 'pago' || c.categoria === 'prueba' ? btn(c, 'resync', '', 'Sincronizar con MP') : ''}</div>`;
    };
    tbl.innerHTML = `<div class="table-wrap"><table class="rt"><thead><tr><th>Cliente</th><th>Estado</th><th>Vence / próximo cobro</th><th>Último pago</th><th>Alta e ingreso</th><th>Acciones</th></tr></thead><tbody>${list.map(c => `<tr><td><b>${esc(cell(c.nombre))}</b><div class="small muted">${esc(cell(c.email))}</div>${c.motivo ? `<div class="tiny muted">${esc(cell(c.motivo))}</div>` : ''}</td><td><span class="badge">${esc(estadoTxt(c))}</span></td><td class="small">${venceTxt(c)}</td><td class="small">${c.ultimo_pago ? esc(fmtDMYHM(c.ultimo_pago)) : '—'}${Number(c.monto_cent) > 0 ? `<div class="muted">${esc((Number(c.monto_cent) / 100).toLocaleString('es-AR'))} ${esc(cell(c.moneda))}</div>` : ''}</td><td class="small">Alta ${esc(fmtDMY(c.alta))}<br>Ingreso ${c.ultimo_ingreso ? esc(fmtDMY(c.ultimo_ingreso)) : '—'}</td><td>${acts(c)}</td></tr>`).join('')}</tbody></table></div>`;
    $$('[data-ac]', tbl).forEach(b => b.onclick = () => doAction(b));
  };
  const doAction = async b => {
    const accion = b.dataset.ac, valor = b.dataset.v, campos = { cuenta: Number(b.dataset.cuenta) };
    const conf = { bloqueado: ['Suspender cliente', '¿Suspender el acceso de este cliente?', 'Suspender', 'danger'], activo: ['Activar cliente', '¿Dar acceso a este cliente?', 'Activar'], auto: ['Volver a automático', '¿Volver el acceso de este cliente al modo automático?', 'Confirmar'] };
    if (accion === 'set_acceso') { const [title, message, ok, tone] = conf[valor]; if (!(await confirmDialog({ title, message, ok, tone }))) return; campos.valor = valor; }
    if (accion === 'quitar_gracia' && !(await confirmDialog({ title: 'Quitar gracia', message: '¿Quitar la gracia de este cliente?', ok: 'Quitar', tone: 'danger' }))) return;
    if (accion === 'dar_gracia') {
      const r = await confirmDialog({ title: 'Dar / extender gracia', message: '¿Cuántos días de gracia?', ok: 'Dar gracia', extra: field('Días', '<input class="input" name="dias" type="number" min="1" max="3650" value="7">') });
      if (!r) return;
      const dias = Number(r.dias); if (!Number.isInteger(dias) || dias < 1 || dias > 3650) { toast('Días inválidos', 'bad'); return; }
      campos.dias = dias;
    }
    showResult('');
    b.disabled = true;
    const res = await sourceAction(b.dataset.id, accion, campos);
    b.disabled = false;
    if (res.error) { toast(errText(res.error), 'bad'); return; }
    toast('Listo');
    if (accion === 'link_clave') {
      const link = res.result?.link ?? res.result?.url ?? res.result?.enlace ?? JSON.stringify(res.result);
      showResult(`<div class="card"><div class="small muted">Vence en 1 hora. Se muestra una sola vez.</div><input class="input mono" readonly value="${esc(String(link))}" style="margin-top:6px"></div>`);
    } else if (accion === 'dar_gracia' && res.result?.vence) {
      showResult(`<div class="card small">Gracia hasta ${esc(fmtDMYHM(res.result.vence))}</div>`);
    }
    if (accion !== 'link_clave') await reload();
  };
  const createGrace = async () => {
    const r = await confirmDialog({ title: 'Crear cuenta de gracia', message: 'Se crea una cuenta con acceso de cortesía.', ok: 'Crear', extra: field('Nombre *', '<input class="input" name="nombre" maxlength="120">') + field('Email *', '<input class="input" name="email" type="email" maxlength="190">') + field('Días', '<input class="input" name="dias" type="number" min="1" max="3650" value="7">') + field('Motivo', '<input class="input" name="motivo" maxlength="80">') + field('Clave (opcional)', '<input class="input" name="clave" type="password" maxlength="200" autocomplete="off">', '', 'Si la dejás vacía, la app genera una.') });
    if (!r) return;
    const nombre = String(r.nombre || '').trim(), email = String(r.email || '').trim(), dias = Number(r.dias || 7);
    if (!nombre || !email) { toast('Completá nombre y email', 'bad'); return; }
    if (!Number.isInteger(dias) || dias < 1 || dias > 3650) { toast('Días inválidos', 'bad'); return; }
    const campos = { nombre, email, dias };
    if (String(r.motivo || '').trim()) campos.motivo = String(r.motivo).trim();
    if (r.clave) campos.clave = String(r.clave);
    showResult('');
    const res = await sourceAction(s.id, 'crear_gracia', campos);
    if (res.error) { toast(errText(res.error), 'bad'); return; }
    toast('Listo');
    const gen = res.result?.clave_generada;
    showResult(`<div class="card"><div class="small">Cuenta creada.</div>${gen ? `<div class="small muted" style="margin-top:6px">Clave generada. Se muestra una sola vez:</div><input class="input mono" readonly value="${esc(String(gen))}" style="margin-top:6px">` : ''}</div>`);
    await reload();
  };
  const linkOf = b => { const x = listOf(lastPagos.sin_cuenta)[Number(b.dataset.cp ?? b.dataset.vl)]; return String(x?.link_alta || ''); };
  let lastPagos = {};
  const drawPagos = d => {
    lastPagos = d && typeof d === 'object' ? d : {};
    body.innerHTML = pagosHtml(lastPagos);
    $$('[data-cp]', body).forEach(b => b.onclick = async () => { const link = linkOf(b); if (!link) return; try { await navigator.clipboard.writeText(link); toast('Link copiado'); } catch (e) { toast('No se pudo copiar', 'bad'); } });
    $$('[data-vl]', body).forEach(b => b.onclick = () => { const link = linkOf(b); if (link) showResult(`<div class="card"><div class="small muted">Mandáselo a la persona por WhatsApp o mail.</div><input class="input mono" readonly value="${esc(link)}" style="margin-top:6px"></div>`); });
  };
  let period = 30;
  const drawSummary = (d, k) => {
    const sel = `<div class="btn-row" style="margin-bottom:10px"><span class="small muted">Período:</span><select class="input" id="vaP" style="max-width:140px">${[7, 30, 90].map(n => `<option value="${n}"${n === period ? ' selected' : ''}>${n} días</option>`).join('')}</select></div>`;
    body.innerHTML = sel + (d && typeof d === 'object' && !Array.isArray(d) ? (k === 'landing' ? landingHtml(d, period) : resumenHtml(d, period)) : `<pre class="mono small">${esc(JSON.stringify(d, null, 2))}</pre>`);
    $('#vaP', m.el).onchange = e => { period = Number(e.target.value); show(k); };
  };
  const show = async k => {
    const my = ++seq;
    const isSum = k === 'summary' || k === 'landing';
    $$('[data-vt]', m.el).forEach(b => b.classList.toggle('on', b.dataset.vt === k));
    body.innerHTML = '<p class="small muted">Cargando...</p>';
    const r = await fetchSource(s.id, isSum ? 'summary' : k, isSum ? period : '');
    if (my !== seq) return;
    if (r.error) { body.innerHTML = err(r.error) + '<button class="btn btn-sm" id="vaR" style="margin-top:8px">Reintentar</button>'; $('#vaR', m.el).onclick = () => show(k); return; }
    const d = r.data;
    if (isSum) {
      drawSummary(d, k);
    } else if (k === 'pagos') {
      drawPagos(d);
    } else if (k === 'sistema') {
      body.innerHTML = d && typeof d === 'object' && !Array.isArray(d) ? sistemaHtml(d) : `<pre class="mono small">${esc(JSON.stringify(d, null, 2))}</pre>`;
    } else if (Array.isArray(d.customers)) {
      custList = d.customers;
      body.innerHTML = `<div class="btn-row" style="margin-bottom:10px"><input class="input" id="vaQ" placeholder="Buscar por nombre o email" style="max-width:260px" value="${esc(fText)}"><select class="input" id="vaC" style="max-width:200px">${[['', 'Todos'], ['pago', 'Pagando'], ['prueba', 'En prueba (MP)'], ['manual', 'Gracia'], ['solo_lectura', 'Solo lectura'], ['bloqueado', 'Bloqueados']].map(([v, l]) => `<option value="${v}"${v === fCat ? ' selected' : ''}>${l}</option>`).join('')}</select><button class="btn btn-primary" id="vaN">+ Crear cuenta de gracia</button></div><div id="vaL"></div><div id="vaTbl"></div>`;
      $('#vaQ', m.el).oninput = e => { fText = e.target.value; drawTable(); };
      $('#vaC', m.el).onchange = e => { fCat = e.target.value; drawTable(); };
      $('#vaN', m.el).onclick = createGrace;
      drawTable();
    } else body.innerHTML = '<p class="small muted">Esta app todavía no envía clientes.</p>';
  };
  $$('[data-vt]', m.el).forEach(b => b.onclick = () => show(b.dataset.vt));
  show('summary');
};
function processEvents(st, evs) {
  const out = { ok: 0, skip: 0, err: [] }; const seen = new Set(st.sales.map(s => s.externalId).filter(Boolean)); st.integrations.forEach(i => (i.seen || []).forEach(x => seen.add(x)));
  for (const [k, ev] of evs.entries()) {
    const tag = `#${k + 1} (${ev.external_id || 'sin id'})`;
    try {
      if (!ev || !ev.type || !ev.source) throw new Error('falta type o source');
      if (!ev.external_id) throw new Error('falta external_id');
      if (seen.has(ev.external_id)) { out.skip++; continue; }
      const I = integByName(st, ev.source); I.method = 'json'; I.status = 'activa'; I.lastSync = new Date().toISOString(); I.seen = I.seen || [];
      const date = smartDate(ev.date, 'auto'); if (!date) throw new Error('fecha inválida');
      const app = ev.app ? st.projects.find(p => norm(p.name) === norm(ev.app)) : null; if (ev.app && !app) throw new Error(`la app "${ev.app}" no está registrada`); if (app && !I.projectId) I.projectId = app.id;
      const cur = String(ev.currency || '').toUpperCase(); const needMoney = ['sale', 'subscription_started', 'renewal'].includes(ev.type);
      if (needMoney && (!st.settings.currencies.some(c => c.code === cur) || !(Number(ev.amount) >= 0))) throw new Error('moneda o importe inválido');
      const email = String(ev.customer_email || '').trim(); let cid = '';
      if (email || ev.customer_name) { let c = email && st.customers.find(x => norm(x.email) === norm(email)); if (!c) { c = { id: uid('cu'), name: ev.customer_name || email, email, country: ev.country || '', createdAt: date, notes: '', origin: 'integracion' }; st.customers.push(c); } cid = c.id; }
      const findSub = () => st.subs.find(s => s.projectId === app?.id && s.customerId === cid && s.status !== 'cancelada') || st.subs.find(s => s.projectId === app?.id && s.customerId === cid);
      const ch = ev.channel ? findByName(st.settings.channels, ev.channel) : st.settings.channels.find(c => /aplicacion/i.test(norm(c.name)));
      const base1 = { date, projectId: app?.id || '', customerId: cid, currency: cur, qty: 1, unitPrice: Number(ev.amount) || 0, commission: Number(ev.commission) || 0, tax: Number(ev.tax) || 0, country: ev.country || '', channelId: ch?.id || '', payStatus: 'cobrado', delivery: 'no_aplica', origin: 'integracion', source: ev.source, externalId: ev.external_id };
      switch (ev.type) {
        case 'sale': { const p = ev.product ? st.products.find(x => norm(x.name) === norm(ev.product)) : null; if (!p && !app) throw new Error('sale necesita product o app'); createSale(st, { ...base1, type: 'unica', productId: p?.id || '', versionId: p?.versions?.[0]?.id || '', brandId: p?.brandId || app?.brandId }); break; }
        case 'subscription_started': case 'trial_started': {
          if (!app) throw new Error('falta app'); if (!cid) throw new Error('falta customer_email');
          const pl = st.plans.find(x => x.projectId === app.id && norm(x.name) === norm(ev.plan)); if (!pl) throw new Error(`plan "${ev.plan}" no existe en ${app.name}`);
          const sub = { id: uid('sb'), projectId: app.id, planId: pl.id, customerId: cid, startDate: date, status: ev.type === 'trial_started' ? 'prueba' : 'activa', events: [], origin: 'integracion', externalId: ev.external_id };
          if (ev.type === 'trial_started') { sub.events.push({ id: uid('ev'), date, type: 'prueba', planId: pl.id }); sub.nextRenewal = addDays(date, pl.trialDays || 0); }
          else { const sid = createSale(st, { ...base1, type: 'suscripcion', planId: pl.id, subId: sub.id, brandId: app.brandId }); sub.events.push({ id: uid('ev'), date, type: 'alta', planId: pl.id, amount: Number(ev.amount), currency: cur, saleId: sid }); sub.nextRenewal = addMonths(date, pl.period === 'anual' ? 12 : 1); }
          st.subs.push(sub); break; }
        case 'renewal': { const s = findSub(); if (!s) throw new Error('no hay suscripción para ese cliente y app'); const pl = st.plans.find(x => x.id === s.planId); const wasTrial = s.status === 'prueba'; const sid = createSale(st, { ...base1, type: 'suscripcion', planId: s.planId, subId: s.id, brandId: app.brandId }); s.events.push({ id: uid('ev'), date, type: wasTrial ? 'conversion' : 'renovacion', planId: s.planId, amount: Number(ev.amount), currency: cur, saleId: sid }); s.status = 'activa'; s.nextRenewal = addMonths(date, pl?.period === 'anual' ? 12 : 1); break; }
        case 'payment_failed': { const s = findSub(); if (!s) throw new Error('no hay suscripción'); s.events.push({ id: uid('ev'), date, type: 'pago_fallido', planId: s.planId }); s.status = 'impaga'; break; }
        case 'cancellation': { const s = findSub(); if (!s) throw new Error('no hay suscripción'); s.events.push({ id: uid('ev'), date, type: 'cancelacion', planId: s.planId }); s.status = 'cancelada'; s.cancelDate = date; break; }
        case 'plan_changed': { const s = findSub(); if (!s) throw new Error('no hay suscripción'); const pl = st.plans.find(x => x.projectId === app.id && norm(x.name) === norm(ev.plan)); if (!pl) throw new Error('plan inexistente'); s.events.push({ id: uid('ev'), date, type: 'cambio_plan', planId: pl.id }); s.planId = pl.id; break; }
        case 'users': { if (!app) throw new Error('falta app'); app.userStats = app.userStats || []; app.userStats.push({ date, registered: Number(ev.registered) || 0, active: Number(ev.active) || 0, origin: 'integracion' }); break; }
        default: throw new Error('type desconocido: ' + ev.type);
      }
      I.seen.push(ev.external_id); if (I.seen.length > 20000) I.seen.splice(0, I.seen.length - 20000); seen.add(ev.external_id); I.stats.imported = (I.stats.imported || 0) + 1; out.ok++;
    } catch (e) { out.err.push(`${tag}: ${e.message}`); const I = ev?.source ? integByName(st, ev.source) : null; if (I) { I.errors.push(`${dDate(todayStr())} ${tag}: ${e.message}`); if (I.errors.length > 50) I.errors.splice(0, I.errors.length - 50); I.status = 'error'; } }
  }
  logAct(st, 'Eventos JSON', `${out.ok} procesados, ${out.skip} repetidos, ${out.err.length} con error`);
  return out;
}

/* =====================================================================
   REPORTES
   ===================================================================== */
