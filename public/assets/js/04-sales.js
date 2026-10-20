VIEWS.ventas = {
  render() {
    const tab = ui.tabs.ventas || 'ventas';
    let h = pageHead('Productos y ventas', 'Productos y ventas', 'Catálogo con versiones, formatos e idiomas; cada venta conserva su canal, moneda, comisiones y entrega.', `<button class="btn" data-act="newProduct">${ic('plus')} Producto</button><button class="btn btn-lens" data-act="newSale">${ic('zap')} Registrar venta</button>`);
    h += `<div class="tabs">${[['ventas', 'Ventas'], ['catalogo', 'Catálogo'], ['canales', 'Canales y países']].map(([id, l]) => `<button data-act="tab" data-k="ventas" data-t="${id}" class="${tab === id ? 'on' : ''}">${l}</button>`).join('')}</div>`;
    if (tab === 'catalogo') return h + catalogTab();
    if (tab === 'canales') return h + channelsTab();
    return h + salesTab();
  }
};
ACT.tab = el => { ui.tabs[el.dataset.k] = el.dataset.t; saveUI(); rerender(); };
function salesTab() {
  const r = resolveRange(), f = ui.filters.sales || (ui.filters.sales = {}), q = norm(f.q);
  const list = state.sales.filter(s => inR(s.date, r) && lensOk(saleBrand(s)) && (!f.channel || s.channelId === f.channel) && (!f.type || s.type === f.type) && (!f.pay || s.payStatus === f.pay) && (!f.delivery || s.delivery === f.delivery) && (!q || norm(saleTitle(s) + ' ' + (cust(s.customerId)?.name || '') + ' ' + (s.coupon || '') + ' ' + (s.externalId || '')).includes(q))).sort((a, b) => b.date.localeCompare(a.date) || (b.createdAt || '').localeCompare(a.createdAt || ''));
  const S = state.settings;
  let h = `<div class="filters"><input class="input sm" style="width:200px" placeholder="Buscar producto, cliente, cupón…" value="${esc(f.q || '')}" data-input="salesQ"><select class="input sm" style="width:auto" data-change="salesFilt" data-f="channel">${opts(S.channels.map(c => ({ id: c.id, label: c.name })), f.channel, 'Todos los canales')}</select><select class="input sm" style="width:auto" data-change="salesFilt" data-f="type">${opts(SALE_TYPES, f.type, 'Todos los tipos')}</select><select class="input sm" style="width:auto" data-change="salesFilt" data-f="pay">${opts(PAY_STATUS, f.pay, 'Cobro: todos')}</select><select class="input sm" style="width:auto" data-change="salesFilt" data-f="delivery">${opts(DELIVERY, f.delivery, 'Entrega: todas')}</select></div>`;
  if (!state.sales.length) return h + emptyBox('ventas', 'Sin ventas registradas', 'Registrá ventas manualmente, importá un CSV de tu tienda o marketplace, o cargá cobros de suscripciones. Nada se inventa.', `<div class="btn-row" style="justify-content:center"><button class="btn btn-primary" data-act="newSale">${ic('plus')} Registrar venta</button><button class="btn" data-act="importCSV">${ic('upload')} Importar CSV</button></div>`);
  const valid = list.filter(s => s.status !== 'anulada'); const miss = new Set();
  const tot = sum(valid, s => conv(saleCalc(s).net, s.currency, s.date, miss) ?? 0), pend = valid.filter(s => s.payStatus === 'pendiente').length, inc = valid.filter(s => s.delivery === 'incidencia' || s.delivery === 'pendiente').length;
  h += `<div class="btn-row" style="margin-bottom:12px"><span class="badge info">${valid.length} ventas</span><span class="badge">${money(tot)} netos</span>${pend ? `<span class="badge warn">${pend} a cobrar</span>` : ''}${inc ? `<span class="badge bad">${inc} con entrega pendiente/incidencia</span>` : ''}${miss.size ? `<span class="badge warn" data-trace="missing" style="cursor:pointer">sin TC: no sumadas</span>` : ''}</div>`;
  if (!list.length) return h + '<div class="card"><p class="muted" style="margin:0">Sin ventas con estos filtros en el período.</p></div>';
  return h + `<div class="table-wrap"><table class="rt cards"><thead><tr><th>Fecha</th><th>Concepto</th><th>Marca</th><th>Canal</th><th>País</th><th class="right">Neto original</th><th class="right">${esc(base())}</th><th>Cobro</th><th>Entrega</th><th></th></tr></thead><tbody>
    ${list.slice(0, 500).map(s => { const c = saleCalc(s), v = conv(c.net, s.currency, s.date); return `<tr class="click ${s.status === 'anulada' ? 'muted' : ''}" data-act="openSale" data-id="${s.id}" style="${s.status === 'anulada' ? 'text-decoration:line-through;opacity:.55' : ''}"><td class="first nowrap">${dDate(s.date)}</td><td data-l="Concepto"><b>${esc(saleTitle(s))}</b>${s.type === 'suscripcion' ? ' <span class="badge gold">recurrente</span>' : ''}${c.refunded ? ' <span class="badge bad">reemb.</span>' : ''}<div class="tiny muted">${esc(cust(s.customerId)?.name || '')}${s.coupon ? ' · cupón ' + esc(s.coupon) : ''}</div></td><td data-l="Marca">${brandChip(saleBrand(s))}</td><td data-l="Canal">${esc(channel(s.channelId)?.name || '—')}</td><td data-l="País">${esc(s.country || '—')}</td><td data-l="Neto original" class="right num">${money(c.net, s.currency)}</td><td data-l="Convertido" class="right num">${v == null ? '<span class="neg">sin TC</span>' : money(v)}</td><td data-l="Cobro">${badge(PAY_STATUS, s.payStatus)}</td><td data-l="Entrega">${s.delivery && s.delivery !== 'no_aplica' ? badge(DELIVERY, s.delivery) : ''}</td><td data-l="Origen">${originTag(s.origin)}</td></tr>`; }).join('')}</tbody></table></div>${list.length > 500 ? '<p class="small muted">Mostrando 500. Usá Reportes para exportar todo.</p>' : ''}`;
}
INP.salesQ = debounce(el => { (ui.filters.sales = ui.filters.sales || {}).q = el.value; saveUI(); rerender(); const i = $('[data-input="salesQ"]'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }, 250);
CHG.salesFilt = el => { (ui.filters.sales = ui.filters.sales || {})[el.dataset.f] = el.value; saveUI(); rerender(); };
function productStats(pid) { let n = 0, units = 0, net = 0; const byV = {}, byC = {}; for (const s of state.sales) { if (s.status === 'anulada' || s.productId !== pid) continue; const v = conv(saleCalc(s).net, s.currency, s.date); n++; units += num(s.qty) || 0; if (v != null) { net += v; byV[s.versionId || '_'] = (byV[s.versionId || '_'] || 0) + v; byC[s.channelId || '_'] = (byC[s.channelId || '_'] || 0) + v; } } return { n, units, net: round2(net), byV, byC }; }
function catalogTab() {
  const f = ui.filters.cat || (ui.filters.cat = {}), q = norm(f.q);
  const list = state.products.filter(p => lensOk(p.brandId) && (!f.status || p.status === f.status) && (!q || norm(p.name + ' ' + p.category + ' ' + (p.versions || []).map(v => v.format + ' ' + v.language).join(' ')).includes(q)));
  let h = `<div class="filters"><input class="input sm" style="width:220px" placeholder="Buscar título, categoría, formato…" value="${esc(f.q || '')}" data-input="catQ"><select class="input sm" style="width:auto" data-change="catFilt" data-f="status">${opts(PROD_STATUS, f.status, 'Todos los estados')}</select></div>`;
  if (!state.products.length) return h + emptyBox('ventas', 'Catálogo vacío', 'Cargá ebooks, agendas, microapps o paquetes. Un mismo título puede tener versiones PDF, EPUB, HTML, idiomas y precios por canal.', `<button class="btn btn-primary" data-act="newProduct">${ic('plus')} Nuevo producto</button>`);
  if (!list.length) return h + '<div class="card"><p class="muted" style="margin:0">Sin productos con estos filtros.</p></div>';
  const groups = {}; list.forEach(p => (groups[p.brandId] = groups[p.brandId] || []).push(p));
  return h + Object.entries(groups).map(([bid, ps]) => `<div style="margin-bottom:22px"><div class="card-h"><h3>${brandChip(bid)}</h3><span class="small muted">${ps.length} producto(s)</span></div><div class="app-grid">${ps.map((p, i) => { const st = productStats(p.id); return `<div class="card app-card" data-act="openProductEl" data-id="${p.id}" style="animation-delay:${i * 30}ms"><span class="bar" style="background:${brandColor(p.brandId)}"></span><div style="display:flex;justify-content:space-between;gap:8px"><div><b style="font-family:var(--head);font-size:16px">${esc(p.name)}</b><div class="small muted">${esc(p.category || 'Sin categoría')}${p.bundle?.length ? ' · Paquete' : ''}</div></div>${badge(PROD_STATUS, p.status)}</div>
    <div class="btn-row" style="margin-top:8px;gap:4px">${(p.versions || []).map(v => `<span class="origin" style="color:var(--ink-2)">${esc(v.format)}${v.language ? ' · ' + esc(v.language) : ''}</span>`).join('')}</div>
    <div class="mini"><div><span>Ventas</span><b>${fnum(st.n)}</b></div><div><span>Ingresos</span><b>${moneyShort(st.net)}</b></div><div><span>Versiones</span><b>${(p.versions || []).length}</b></div></div>${p.pendingUpdates ? `<div class="tiny" style="margin-top:8px;color:var(--warn)">⟳ ${esc(p.pendingUpdates)}</div>` : ''}</div>`; }).join('')}</div></div>`).join('');
}
INP.catQ = debounce(el => { (ui.filters.cat = ui.filters.cat || {}).q = el.value; saveUI(); rerender(); const i = $('[data-input="catQ"]'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }, 250);
CHG.catFilt = el => { (ui.filters.cat = ui.filters.cat || {})[el.dataset.f] = el.value; saveUI(); rerender(); };
function channelsTab() {
  const r = resolveRange(), m = metrics(r), miss = new Set(), byCh = {};
  for (const s of state.sales) { if (s.status === 'anulada' || !inR(s.date, r) || !lensOk(saleBrand(s))) continue; const c = saleCalc(s), k = s.channelId || '_'; const x = byCh[k] || (byCh[k] = { n: 0, gross: 0, net: 0, com: 0, liquid: 0 }); const g = conv(c.gross, s.currency, s.date, miss); if (g == null) continue; x.n++; x.gross += g; x.net += conv(c.net, s.currency, s.date); x.com += conv(c.commission, s.currency, s.date); x.liquid += conv(c.liquid, s.currency, s.date); }
  const rows = Object.entries(byCh).sort((a, b) => b[1].liquid - a[1].liquid);
  if (!rows.length) return '<div class="card"><p class="muted" style="margin:0">Sin ventas en el período.</p></div>';
  return `<div class="bento"><div class="card s8"><div class="card-h"><h3>Comparación de canales</h3>${infoI('Neto liquidado = ingresos netos − comisiones − impuestos. Es lo que realmente te deja cada canal.')}</div><div class="table-wrap"><table class="rt cards"><thead><tr><th>Canal</th><th class="right">Ventas</th><th class="right">Brutas</th><th class="right">Netas</th><th class="right">Comisiones</th><th class="right">% comis.</th><th class="right">Neto liquidado</th></tr></thead><tbody>${rows.map(([k, x]) => `<tr class="click" data-trace="channel:${k}"><td class="first"><b>${esc(channel(k)?.name || 'Sin canal')}</b></td><td data-l="Ventas" class="right num">${x.n}</td><td data-l="Brutas" class="right num">${money(x.gross)}</td><td data-l="Netas" class="right num">${money(x.net)}</td><td data-l="Comisiones" class="right num">${money(x.com)}</td><td data-l="% comis." class="right num">${x.net ? pct(x.com / x.net * 100) : '—'}</td><td data-l="Liquidado" class="right num"><b>${money(x.liquid)}</b></td></tr>`).join('')}</tbody></table></div></div>
    <div class="card s4"><div class="card-h"><h3>Ventas por país</h3></div>${barsHTML(Object.entries(m.byCountry).sort((a, b) => b[1] - a[1]).slice(0, 10).map(([c, v]) => ({ label: c, value: v, color: 'var(--c5)' })), { fmt: v => moneyShort(v) })}</div></div>`;
}

/* ---------- producto ---------- */
ACT.newProduct = () => productForm(null);
ACT.openProductEl = el => openProduct(el.dataset.id);
function productForm(p) {
  const S = state.settings; if (!S.brands.length) { toast('Primero creá una marca en Ajustes', 'bad'); return; }
  let versions = p ? clone(p.versions || []) : [];
  const m = openModal({ title: p ? 'Editar producto' : 'Nuevo producto digital', size: 'lg', body: `<form id="pdF" class="form-grid c3" novalidate>
    ${field('Nombre / título *', `<input class="input" name="name" value="${esc(p?.name || '')}">`)}${field('Marca *', `<select class="input" name="brandId">${brandOpts(p?.brandId || (ui.lens !== 'all' ? ui.lens : ''))}</select>`)}${field('Categoría', `<select class="input" name="category"></select>`)}
    ${field('Estado comercial *', `<select class="input" name="status">${opts(PROD_STATUS, p?.status, 'Elegí…')}</select>`)}${field('Fecha de lanzamiento', `<input class="input" type="date" name="launchDate" value="${p?.launchDate || ''}">`)}${field('App vinculada', `<select class="input" name="projectId">${projOpts(p?.projectId || '')}</select>`, '', 'Si el producto pertenece a una app, su venta suma a esa app.')}
    ${field('Actualizaciones pendientes', `<input class="input" name="pendingUpdates" value="${esc(p?.pendingUpdates || '')}" placeholder="Ej.: corregir capítulo 3">`, 'full')}
    ${field('Archivos o recursos vinculados', `<textarea class="input" name="resources" rows="2" placeholder="Un enlace o ruta por línea">${esc((p?.resources || []).join('\n'))}</textarea>`, 'full')}
    <div class="field full"><label>¿Es un paquete? Productos incluidos</label><div class="pickchips" id="pdBundle">${state.products.filter(x => x.id !== p?.id).map(x => `<label><input type="checkbox" value="${x.id}" ${(p?.bundle || []).includes(x.id) ? 'checked' : ''}><span>${esc(x.name)}</span></label>`).join('') || '<span class="small muted">No hay otros productos todavía.</span>'}</div><div class="hint">La venta de un paquete se cuenta UNA vez, en el paquete; los incluidos no suman de nuevo.</div></div>
    <div class="form-sec">Versiones (formato, idioma, versión, precio y canales)</div><div class="full" id="pdVers"></div>
  </form><div class="err-msg" id="pdErr" style="margin-top:8px"></div>`, footer: `<button class="btn btn-ghost" data-cancel>Cancelar</button><button class="btn btn-primary" data-save>Guardar</button>` });
  const f = $('#pdF', m.el);
  const fillCats = () => { const cs = S.categories.filter(c => c.brandId === f.brandId.value).map(c => c.name); if (p?.category && !cs.includes(p.category)) cs.push(p.category); f.category.innerHTML = opts(cs, p?.category || '', 'Sin categoría'); };
  f.brandId.onchange = fillCats; fillCats();
  const drawV = () => { $('#pdVers', m.el).innerHTML = `${versions.length ? `<div class="table-wrap"><table class="rt"><thead><tr><th>Formato</th><th>Idioma</th><th>Versión</th><th>Precio</th><th>Moneda</th><th>Canales</th><th></th></tr></thead><tbody>${versions.map((v, i) => `<tr><td><select class="input sm" data-vi="${i}" data-vf="format">${opts(S.formats.concat(v.format && !S.formats.includes(v.format) ? [v.format] : []), v.format, '—')}</select></td><td><input class="input sm" style="width:90px" data-vi="${i}" data-vf="language" value="${esc(v.language || '')}" list="langList"></td><td><input class="input sm" style="width:64px" data-vi="${i}" data-vf="version" value="${esc(v.version || '')}"></td><td><input class="input sm" style="width:90px" type="number" min="0" step="any" data-vi="${i}" data-vf="price" value="${esc(v.price ?? '')}"></td><td><select class="input sm" data-vi="${i}" data-vf="currency">${curOpts(v.currency)}</select></td><td><div class="pickchips">${S.channels.map(c => `<label><input type="checkbox" data-vi="${i}" data-vch="${c.id}" ${(v.channels || []).includes(c.id) ? 'checked' : ''}><span style="font-size:11px;padding:3px 8px">${esc(c.name)}</span></label>`).join('')}</div></td><td><button type="button" class="icon-btn" style="width:30px;height:30px" data-rmv="${i}" aria-label="Quitar versión">${ic('x')}</button></td></tr>`).join('')}</tbody></table></div>` : '<p class="small muted">Agregá al menos una versión para poder venderlo.</p>'}<datalist id="langList">${S.languages.map(l => `<option value="${esc(l)}">`).join('')}</datalist><button type="button" class="btn btn-sm" style="margin-top:8px" data-addv>${ic('plus')} Agregar versión</button>`;
    $$('[data-vf]', m.el).forEach(i => i.oninput = i.onchange = () => { versions[i.dataset.vi][i.dataset.vf] = i.value; });
    $$('[data-vch]', m.el).forEach(i => i.onchange = () => { const v = versions[i.dataset.vi]; v.channels = v.channels || []; if (i.checked) v.channels.push(i.dataset.vch); else v.channels = v.channels.filter(x => x !== i.dataset.vch); });
    $$('[data-rmv]', m.el).forEach(b => b.onclick = () => { const v = versions[b.dataset.rmv]; if (v.id && state.sales.some(s => s.versionId === v.id)) { toast('Esa versión tiene ventas: no se puede quitar (podés dejarla sin canales).', 'bad'); return; } versions.splice(Number(b.dataset.rmv), 1); drawV(); });
    $('[data-addv]', m.el).onclick = () => { versions.push({ id: uid('vr'), format: '', language: S.languages[0] || '', version: '1', price: '', currency: base(), channels: [] }); drawV(); };
  };
  drawV();
  m.el.querySelector('[data-cancel]').onclick = () => m.close();
  m.el.querySelector('[data-save]').onclick = async () => {
    const d = formData(f), bad = ['name', 'brandId', 'status'].filter(k => !d[k]), errs = [];
    versions.forEach((v, i) => { if (!v.format) errs.push(`Versión ${i + 1}: elegí el formato.`); if (v.price === '' || num(v.price) == null || num(v.price) < 0) errs.push(`Versión ${i + 1}: precio inválido.`); });
    const keys = versions.map(v => norm(v.format + '|' + v.language + '|' + v.version)); if (new Set(keys).size !== keys.length) errs.push('Hay versiones repetidas (mismo formato, idioma y versión).');
    if (state.products.some(x => x.id !== p?.id && x.brandId === d.brandId && norm(x.name) === norm(d.name))) errs.push('Ya existe un producto con ese nombre en esta marca.');
    markInvalid(f, bad); if (bad.length || errs.length) { $('#pdErr', m.el).textContent = errs[0] || 'Completá los campos marcados.'; return; }
    const bundle = $$('#pdBundle input:checked', m.el).map(i => i.value);
    try { await transact('producto', st => { const rec = { name: d.name, brandId: d.brandId, category: d.category, status: d.status, launchDate: d.launchDate, projectId: d.projectId, pendingUpdates: d.pendingUpdates, resources: d.resources.split('\n').map(x => x.trim()).filter(Boolean), bundle, versions: versions.map(v => ({ ...v, id: v.id || uid('vr'), price: round2(num(v.price)) })), updatedAt: new Date().toISOString() }; if (p) Object.assign(st.products.find(x => x.id === p.id), rec); else st.products.push({ id: uid('pd'), createdAt: new Date().toISOString(), ...rec }); logAct(st, p ? 'Producto editado' : 'Producto creado', d.name); }); toast('Producto guardado'); m.close(); render(); } catch (e) { }
  };
}
function openProduct(id) {
  const p = product(id); if (!p) return; while (modalStack.length) modalStack[0].close();
  const st = productStats(id), inBundles = state.products.filter(x => (x.bundle || []).includes(id));
  const d = openDrawer({ title: esc(p.name), body: `<div class="btn-row" style="margin-bottom:12px">${brandChip(p.brandId)} ${badge(PROD_STATUS, p.status)} ${p.category ? `<span class="badge">${esc(p.category)}</span>` : ''}</div>
    <div class="kv" style="margin-bottom:16px"><div data-trace="product:${id}" style="cursor:pointer"><span>Ingresos acumulados</span><b class="mono">${money(st.net)}</b></div><div><span>Ventas</span><b class="mono">${fnum(st.n)} (${fnum(st.units)} u.)</b></div><div><span>Lanzamiento</span><b>${dDate(p.launchDate) || '—'}</b></div><div><span>App vinculada</span><b>${esc(proj(p.projectId)?.name || '—')}</b></div></div>
    ${p.pendingUpdates ? `<div class="card" style="border-color:var(--warn);background:var(--warn-bg);margin-bottom:14px"><b>Actualización pendiente:</b> ${esc(p.pendingUpdates)}</div>` : ''}
    <div class="eyebrow" style="margin-bottom:6px">Versiones</div><div class="table-wrap" style="margin-bottom:16px"><table class="rt"><thead><tr><th>Versión</th><th class="right">Precio</th><th>Canales</th><th class="right">Ingresos</th></tr></thead><tbody>${(p.versions || []).map(v => `<tr><td><b>${esc(versionLabel(v))}</b></td><td class="right num">${money(v.price, v.currency)}</td><td class="small">${(v.channels || []).map(c => esc(channel(c)?.name || '')).join(', ') || '—'}</td><td class="right num">${money(st.byV[v.id] || 0)}</td></tr>`).join('') || '<tr><td colspan="4" class="muted">Sin versiones.</td></tr>'}</tbody></table></div>
    <div class="eyebrow" style="margin-bottom:6px">Ingresos por canal</div>${barsHTML(Object.entries(st.byC).sort((a, b) => b[1] - a[1]).map(([c, v]) => ({ label: channel(c)?.name || 'Sin canal', value: v, color: brandColor(p.brandId) })), { fmt: v => moneyShort(v) })}
    ${p.bundle?.length ? `<div class="eyebrow" style="margin:16px 0 6px">Incluye</div><div class="btn-row">${p.bundle.map(b => `<span class="badge">${esc(product(b)?.name || '')}</span>`).join('')}</div>` : ''}
    ${inBundles.length ? `<div class="eyebrow" style="margin:16px 0 6px">Forma parte de</div><div class="btn-row">${inBundles.map(b => `<span class="badge gold">${esc(b.name)}</span>`).join('')}</div>` : ''}
    ${p.resources?.length ? `<div class="eyebrow" style="margin:16px 0 6px">Recursos</div><div class="feed">${p.resources.map(x => `<div class="small mono" style="padding:4px 0;word-break:break-all">${/^https?:/.test(x) ? `<a href="${esc(x)}" target="_blank" rel="noopener">${esc(x)}</a>` : esc(x)}</div>`).join('')}</div>` : ''}`,
    footer: `<button class="btn" data-sell>${ic('zap')} Registrar venta</button><button class="btn btn-primary" data-edit>${ic('edit')} Editar</button>` });
  d.el.querySelector('[data-edit]').onclick = () => { d.close(); productForm(p); };
  d.el.querySelector('[data-sell]').onclick = () => { d.close(); saleForm({ productId: id }); };
}

/* ---------- venta ---------- */
ACT.newSale = () => saleForm({});
function saleForm(pre) {
  const S = state.settings;
  if (!S.currencies.length) { toast('Configurá las monedas en Ajustes', 'bad'); return; }
  const m = openModal({ title: 'Registrar venta o ingreso', size: 'lg', body: `<form id="slF" class="form-grid c3" novalidate>
    ${field('Tipo *', `<select class="input" name="type">${opts(SALE_TYPES.filter(t => t.id !== 'suscripcion'), pre.type || 'unica')}</select>`, '', 'Los cobros de suscripción se registran desde Suscripciones.')}
    ${field('Fecha *', `<input class="input" type="date" name="date" value="${todayStr()}" max="${todayStr()}">`)}
    ${field('Canal', `<select class="input" name="channelId">${opts(S.channels.map(c => ({ id: c.id, label: c.name })), '', 'Sin especificar')}</select>`)}
    ${field('Producto', `<select class="input" name="productId">${opts(state.products.filter(p => p.status !== 'retirado').map(p => ({ id: p.id, label: p.name + ' · ' + (brand(p.brandId)?.name || '') })), pre.productId || '', 'Ninguno (ingreso de app o externo)')}</select>`)}
    ${field('Versión', `<select class="input" name="versionId"><option value="">—</option></select>`)}
    ${field('App / proyecto', `<select class="input" name="projectId">${projOpts('', 'Ninguna')}</select>`, '', 'Para ingresos de apps fuera de la tienda.')}
    ${field('Concepto', '<input class="input" name="concept" placeholder="Si no es un producto del catálogo">')}
    ${field('Marca', `<select class="input" name="brandId">${brandOpts('')}</select>`, '', 'Se completa sola con producto o app.')}
    ${field('País', `<input class="input" name="country" list="countryList" placeholder="Ej.: Argentina">`)}
    <div class="form-sec">Importes (en la moneda original de la venta)</div>
    ${field('Moneda *', `<select class="input" name="currency">${curOpts()}</select>`)}${field('Cantidad *', '<input class="input" type="number" min="1" step="1" name="qty" value="1">')}${field('Precio unitario *', '<input class="input" type="number" min="0" step="any" name="unitPrice">')}
    ${field('Descuento', '<input class="input" type="number" min="0" step="any" name="discount" placeholder="0">')}${field('Cupón', '<input class="input" name="coupon">')}${field('Reembolso', '<input class="input" type="number" min="0" step="any" name="refunded" placeholder="0">')}
    ${field('Comisión de la plataforma', '<input class="input" type="number" min="0" step="any" name="commission" placeholder="0">', '', '<span id="comHint"></span>')}${field('Impuestos / retenciones', '<input class="input" type="number" min="0" step="any" name="tax" placeholder="0">')}
    <div class="field"><label>Resumen</label><div class="card" id="slSum" style="padding:10px 12px;box-shadow:none;font-size:12.5px"></div></div>
    <div class="form-sec">Cliente, cobro y entrega</div>
    ${field('Cliente', `<select class="input" name="customerId">${opts(state.customers.map(c => ({ id: c.id, label: c.name + (c.email ? ' · ' + c.email : '') })), '', 'Sin identificar')}${opt('__new', '+ Cliente nuevo…', '')}</select>`)}
    <div class="field hidden" id="ncBox"><label>Cliente nuevo</label><div class="addrow"><input class="input" id="ncName" placeholder="Nombre *"><input class="input" id="ncEmail" type="email" placeholder="Email"></div></div>
    ${field('Estado del cobro *', `<select class="input" name="payStatus">${opts(PAY_STATUS, 'pendiente')}</select>`)}
    <div class="field hidden" id="cobroBox"><label>Cobrado en</label><div class="addrow"><select class="input" name="accountId">${opts(S.accounts.map(a => ({ id: a.id, label: a.name + ' (' + a.currency + ')' })), '', 'Sin cuenta')}</select><input class="input" type="date" name="collectedDate" value="${todayStr()}"></div><div class="hint">Se registra como cobro en Finanzas por el neto liquidado.</div></div>
    ${field('Entrega', `<select class="input" name="delivery">${opts(DELIVERY, 'entregado')}</select>`)}
    ${field('Origen del dato', `<select class="input" name="origin">${opts(ORIGINS.filter(o => o.id !== 'integracion'), 'manual')}</select>`)}${field('ID externo (opcional)', '<input class="input" name="externalId" placeholder="Nº de pedido en la plataforma">', '', 'Evita contar dos veces la misma venta.')}
    ${field('Notas', '<input class="input" name="notes">', 'full')}
  </form><datalist id="countryList">${[...new Set(state.sales.map(s => s.country).filter(Boolean))].map(c => `<option value="${esc(c)}">`).join('')}</datalist><div class="err-msg" id="slErr" style="margin-top:8px"></div>`,
    footer: `<button class="btn btn-ghost" data-cancel>Cancelar</button><button class="btn btn-primary" data-save>Guardar venta</button>` });
  const f = $('#slF', m.el);
  const fillVer = () => { const p = product(f.productId.value); f.versionId.innerHTML = p ? opts((p.versions || []).map(v => ({ id: v.id, label: versionLabel(v) + ' · ' + money(v.price, v.currency) })), (p.versions || [])[0]?.id || '', '—') : '<option value="">—</option>'; if (p) { f.brandId.value = p.brandId; if (p.projectId) f.projectId.value = p.projectId; } applyVer(); };
  const applyVer = () => { const p = product(f.productId.value), v = versionOf(p, f.versionId.value); if (v) { f.unitPrice.value = v.price; f.currency.value = v.currency; } calcCom(); };
  const calcCom = () => { const ch = channel(f.channelId.value); $('#comHint', m.el).textContent = ch?.commission ? `Sugerida: ${ch.commission}% del neto` : ''; if (ch?.commission && !f.commission.dataset.touched) { const c = saleCalc(formData(f)); f.commission.value = round2(c.net * ch.commission / 100) || ''; } drawSum(); };
  const drawSum = () => { const c = saleCalc(formData(f)), cur = f.currency.value; $('#slSum', m.el).innerHTML = `<div style="display:flex;justify-content:space-between"><span>Bruto</span><b class="mono">${money(c.gross, cur)}</b></div><div style="display:flex;justify-content:space-between"><span>Neto</span><b class="mono">${money(c.net, cur)}</b></div><div style="display:flex;justify-content:space-between"><span>Liquidado</span><b class="mono">${money(c.liquid, cur)}</b></div>${cur !== base() ? `<div class="tiny ${rateFor(cur, f.date.value) ? 'muted' : 'neg'}">${rateFor(cur, f.date.value) ? '≈ ' + money(conv(c.net, cur, f.date.value)) : 'Sin tipo de cambio para esta fecha'}</div>` : ''}`; };
  f.productId.onchange = fillVer; f.versionId.onchange = applyVer; f.channelId.onchange = calcCom; f.commission.oninput = () => { f.commission.dataset.touched = 1; drawSum(); };
  f.projectId.onchange = () => { const p = proj(f.projectId.value); if (p) f.brandId.value = p.brandId; };
  f.customerId.onchange = () => $('#ncBox', m.el).classList.toggle('hidden', f.customerId.value !== '__new');
  f.payStatus.onchange = () => $('#cobroBox', m.el).classList.toggle('hidden', f.payStatus.value !== 'cobrado');
  f.addEventListener('input', e => { if (['qty', 'unitPrice', 'discount', 'refunded', 'tax'].includes(e.target.name)) calcCom(); else drawSum(); });
  if (pre.productId) fillVer(); drawSum();
  m.el.querySelector('[data-cancel]').onclick = () => m.close();
  const sb = m.el.querySelector('[data-save]');
  sb.onclick = async () => {
    const d = formData(f), bad = [], c = saleCalc(d);
    if (!d.date || d.date > todayStr()) bad.push('date');
    if (!Number.isInteger(num(d.qty)) || num(d.qty) < 1) bad.push('qty');
    if (num(d.unitPrice) == null || num(d.unitPrice) < 0) bad.push('unitPrice');
    if (!d.productId && !d.projectId && !d.concept) bad.push('concept');
    if (!d.brandId && !d.productId && !d.projectId) bad.push('brandId');
    if (num(d.discount) > c.gross) bad.push('discount');
    if (num(d.refunded) > c.gross - c.discount) bad.push('refunded');
    if (c.liquid < 0) { bad.push('commission'); }
    const nc = d.customerId === '__new' ? { name: $('#ncName', m.el).value.trim(), email: $('#ncEmail', m.el).value.trim() } : null;
    if (nc && !nc.name) { $('#ncName', m.el).classList.add('invalid'); bad.push('customerId'); }
    markInvalid(f, bad); if (bad.length) { $('#slErr', m.el).textContent = c.liquid < 0 ? 'Las comisiones e impuestos superan el neto.' : 'Revisá los campos marcados (un ingreso necesita producto, app o concepto).'; return; }
    if (d.externalId && state.sales.some(s => s.externalId && norm(s.externalId) === norm(d.externalId) && s.status !== 'anulada')) { $('#slErr', m.el).textContent = 'Ya existe una venta con ese ID externo: no se duplica.'; markInvalid(f, ['externalId']); return; }
    sb.disabled = true;
    try {
      const saleId = uid('sl'), amount = round2(c.net).toFixed(2);
      const canSql = await financialEnabled() && c.net > 0 && !nc && !d.discount && !d.commission && !d.tax && !d.refunded && d.accountId && d.productId && d.qty > 0;
      if (canSql) {
        const command = { kind:'sale_register', sale_id:saleId, customer_id:d.customerId || null, currency:d.currency, amount, date:d.date, settle_now:d.payStatus==='cobrado', account_id:d.accountId || undefined, lines:[{product_id:d.productId, version_id:d.versionId || null, description:d.concept || '', quantity:String(d.qty), unit_price:round2(d.unitPrice).toFixed(2), line_total:amount}] };
        await transactFinancial('venta', command, (st) => createSale(st, { ...d, customerId: d.customerId, discount: 0, commission: 0, tax: 0, refunded: 0, payStatus: d.payStatus, _id:saleId }, null));
      } else await transact('venta', st => createSale(st, { ...d, customerId: nc ? '' : d.customerId }, nc));
      toast('Venta registrada'); m.close(); render();
    } catch (e) { sb.disabled = false; }
  };
}
/* crea una venta (usada por formulario, suscripciones e importaciones) */
function createSale(st, d, newCust) {
  let customerId = d.customerId || '';
  if (newCust && newCust.name) { const ex = newCust.email && st.customers.find(c => c.email && norm(c.email) === norm(newCust.email)); if (ex) customerId = ex.id; else { const c = { id: uid('cu'), name: newCust.name, email: newCust.email || '', country: d.country || '', createdAt: d.date, notes: '', origin: d.origin || 'manual' }; st.customers.push(c); customerId = c.id; } }
  const p = st.products.find(x => x.id === d.productId), pj = st.projects.find(x => x.id === (d.projectId || p?.projectId));
  const s = { id: d._id || uid('sl'), createdAt: new Date().toISOString(), type: d.type || 'unica', date: d.date, productId: d.productId || '', versionId: d.versionId || '', projectId: d.projectId || p?.projectId || '', planId: d.planId || '', subId: d.subId || '', brandId: d.brandId || p?.brandId || pj?.brandId || '', channelId: d.channelId || '', country: d.country || '', customerId, concept: d.concept || '',
    currency: d.currency, qty: Math.floor(num(d.qty) || 1), unitPrice: round2(num(d.unitPrice)), discount: round2(num(d.discount) || 0), coupon: d.coupon || '', refunded: round2(num(d.refunded) || 0), commission: round2(num(d.commission) || 0), tax: round2(num(d.tax) || 0),
    payStatus: d.payStatus || 'pendiente', delivery: d.delivery || 'no_aplica', status: 'valida', origin: d.origin || 'manual', source: d.source || '', externalId: d.externalId || '', notes: d.notes || '', history: [{ at: new Date().toISOString(), text: 'Registrada' + (d.origin && d.origin !== 'manual' ? ' (' + d.origin + ')' : '') }] };
  st.sales.push(s);
  if (s.payStatus === 'cobrado') st.treasury.push({ id: uid('tr'), date: d.collectedDate || d.date, type: 'cobro', accountTo: d.accountId || '', amount: saleCalc(s).liquid, currency: s.currency, saleId: s.id, note: '' });
  logAct(st, 'Venta registrada', `${saleTitle(s)} · ${money(saleCalc(s).net, s.currency)}`);
  return s.id;
}
ACT.openSale = el => openSale(el.dataset.id);
function openSale(id) {
  const s = byId(state.sales, id); if (!s) return; while (modalStack.length) modalStack[0].close();
  const c = saleCalc(s), cobros = state.treasury.filter(t => t.saleId === id);
  const d = openDrawer({ title: esc(saleTitle(s)), body: `<div class="btn-row" style="margin-bottom:12px">${badge(SALE_TYPES, s.type)} ${badge(PAY_STATUS, s.payStatus)} ${s.delivery !== 'no_aplica' ? badge(DELIVERY, s.delivery) : ''} ${s.status === 'anulada' ? '<span class="badge bad">Anulada</span>' : ''} ${originTag(s.origin)}</div>
    <div class="kv" style="margin-bottom:16px"><div><span>Fecha</span><b>${dDate(s.date)}</b></div><div><span>Marca</span><b>${brandChip(saleBrand(s))}</b></div><div><span>Canal</span><b>${esc(channel(s.channelId)?.name || '—')}</b></div><div><span>País</span><b>${esc(s.country || '—')}</b></div><div><span>Cliente</span><b>${s.customerId ? `<a href="#" data-act="openCustomerEl" data-id="${s.customerId}">${esc(cust(s.customerId)?.name || '')}</a>` : '—'}</b></div><div><span>App</span><b>${esc(proj(saleProject(s))?.name || '—')}</b></div><div><span>Cupón</span><b>${esc(s.coupon || '—')}</b></div><div><span>ID externo</span><b class="mono small">${esc(s.externalId || '—')}</b></div></div>
    <div class="card" style="margin-bottom:14px">${[['Bruto (' + s.qty + ' × ' + money(s.unitPrice, s.currency) + ')', c.gross], ['− Descuento', -c.discount], ['− Reembolsos', -c.refunded], ['= Neto', c.net], ['− Comisión', -c.commission], ['− Impuestos / retenciones', -c.tax], ['= Liquidado', c.liquid]].map(([l, v], i) => `<div class="bar-row" style="padding:5px 0;${[3, 6].includes(i) ? 'font-weight:700' : ''}"><span class="bl small">${l}</span><span class="bv">${money(v, s.currency)}</span></div>`).join('')}
      ${s.currency !== base() ? `<div class="tiny muted" style="margin-top:6px">TC: ${rateNote(s.currency, s.date)} → neto ${conv(c.net, s.currency, s.date) == null ? 'sin convertir' : money(conv(c.net, s.currency, s.date))}</div>` : ''}</div>
    ${cobros.length ? `<div class="eyebrow" style="margin-bottom:6px">Cobros</div>${cobros.map(t => `<div class="small">${dDate(t.date)} · ${money(t.amount, t.currency)} · ${esc(account(t.accountTo)?.name || 'sin cuenta')}</div>`).join('')}` : ''}
    ${s.notes ? `<p class="small"><b>Notas:</b> ${esc(s.notes)}</p>` : ''}
    <div class="eyebrow" style="margin:14px 0 6px">Historial</div>${(s.history || []).slice().reverse().map(h => `<div class="small muted">${new Date(h.at).toLocaleString(locale())} · ${esc(h.text)}</div>`).join('')}`,
    footer: s.status === 'anulada' ? '' : `<button class="btn btn-danger" data-void style="margin-right:auto">Anular</button><select class="input sm" style="width:auto" data-deliv>${opts(DELIVERY, s.delivery)}</select><button class="btn" data-refund>Reembolso</button>${s.payStatus !== 'cobrado' ? `<button class="btn btn-primary" data-collect>Marcar cobrada</button>` : ''}` });
  const upd = async (fn, msg) => { try { await transact('venta', st => { const S2 = st.sales.find(x => x.id === id); fn(S2, st); }); toast(msg); openSale(id); rerender(); } catch (e) { } };
  d.el.querySelector('[data-deliv]')?.addEventListener('change', e => upd(S2 => { S2.history.push({ at: new Date().toISOString(), text: 'Entrega: ' + statusOf(DELIVERY, e.target.value).label }); S2.delivery = e.target.value; }, 'Entrega actualizada'));
  d.el.querySelector('[data-collect]')?.addEventListener('click', async () => {
    const res = await confirmDialog({ title: 'Registrar cobro', message: `Neto liquidado: <b>${money(c.liquid, s.currency)}</b>`, ok: 'Registrar', extra: `<div class="form-grid" style="margin-top:10px">${field('Fecha', `<input class="input" type="date" name="date" value="${todayStr()}" max="${todayStr()}">`)}${field('Cuenta', `<select class="input" name="acc">${opts(state.settings.accounts.map(a => ({ id: a.id, label: a.name + ' (' + a.currency + ')' })), '', 'Sin cuenta')}</select>`)}${field('Importe recibido (' + s.currency + ')', `<input class="input" type="number" step="any" name="amt" value="${c.liquid}">`, 'full')}</div>` });
    if (!res) return; const amt = num(res.amt); if (amt == null || amt < 0) { toast('Importe inválido', 'bad'); return; }
    try { if (await financialEnabled()) { await transactFinancial('cobro',{kind:'sale_collect',sale_id:id,amount:round2(amt).toFixed(2),date:res.date,account_id:res.acc,movement_id:uid('mv')},(st)=>{const S2=st.sales.find(x=>x.id===id);if(S2){S2.payStatus='cobrado';S2.history.push({at:new Date().toISOString(),text:'Cobrada '+money(amt,s.currency)});st.treasury.push({id:uid('tr'),date:res.date,type:'cobro',accountTo:res.acc,amount:round2(amt),currency:s.currency,saleId:id});}}); toast('Cobro registrado'); openSale(id); rerender(); } else await upd((S2,st)=>{S2.payStatus='cobrado';S2.history.push({at:new Date().toISOString(),text:'Cobrada '+money(amt,s.currency)});st.treasury.push({id:uid('tr'),date:res.date,type:'cobro',accountTo:res.acc,amount:round2(amt),currency:s.currency,saleId:id});logAct(st,'Cobro',saleTitle(s));},'Cobro registrado'); } catch(e) { }
  });
  d.el.querySelector('[data-refund]')?.addEventListener('click', async () => {
    const maxR = round2(c.gross - c.discount);
    const res = await confirmDialog({ title: 'Registrar reembolso', message: `Reembolsado hasta ahora: ${money(c.refunded, s.currency)}. Máximo: ${money(maxR, s.currency)}.`, ok: 'Registrar', extra: `<div class="form-grid" style="margin-top:10px">${field('Monto total reembolsado', `<input class="input" type="number" min="0" step="any" name="amt" value="${maxR}">`)}${field('Motivo', '<input class="input" name="why">')}</div>` });
    if (!res) return; const amt = num(res.amt); if (amt == null || amt < 0 || amt > maxR) { toast('Monto inválido', 'bad'); return; }
    try { if (await financialEnabled()) { await transactFinancial('reembolso',{kind:'sale_refund_commercial',sale_id:id,amount:round2(amt).toFixed(2),expected_version:1},(st)=>{const S2=st.sales.find(x=>x.id===id);if(S2){S2.refunded=round2(amt);S2.history.push({at:new Date().toISOString(),text:`Reembolso total ${money(amt,s.currency)}`});}}); toast('Reembolso registrado'); openSale(id); rerender(); } else await upd((S2,st)=>{S2.refunded=round2(amt);S2.history.push({at:new Date().toISOString(),text:`Reembolso total ${money(amt,s.currency)}${res.why?' · '+res.why:''}`});logAct(st,'Reembolso',saleTitle(s)+' '+money(amt,s.currency));},'Reembolso registrado'); } catch(e) { }
  });
  d.el.querySelector('[data-void]')?.addEventListener('click', async () => {
    const res = await confirmDialog({ title: 'Anular venta', message: 'La venta deja de sumar en todos los indicadores (queda en el historial). Usalo para errores de carga o duplicados; para devoluciones usá “Reembolso”.' + (cobros.length ? '<br><b>Tiene cobros registrados: revisalos en Finanzas.</b>' : ''), ok: 'Anular', tone: 'danger', extra: field('Motivo *', '<input class="input" name="why">') });
    if (!res) return; if (!res.why.trim()) { toast('Indicá el motivo', 'bad'); return; }
    try { if (await financialEnabled()) { await transactFinancial('anular',{kind:'sale_void',sale_id:id,expected_version:1},(st)=>{const S2=st.sales.find(x=>x.id===id);if(S2)S2.status='anulada';}); toast('Venta anulada'); openSale(id); rerender(); } else await upd((S2,st)=>{S2.status='anulada';S2.history.push({at:new Date().toISOString(),text:'Anulada: '+res.why});logAct(st,'Venta anulada',saleTitle(s)+' · '+res.why);},'Venta anulada'); } catch(e) { }
  });
}
/* =====================================================================
   SUSCRIPCIONES
   ===================================================================== */
