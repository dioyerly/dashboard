VIEWS.pulso = {
  render() {
    const r = resolveRange(), m = metrics(r), pr = prevRange(r), pm = metrics(pr), sm = saasMetrics(r);
    const has = pm.salesCount > 0 || pm.expenses > 0; const empty = !state.sales.length && !state.expenses.length && !state.projects.length && !state.subs.length;
    let h = pageHead(`Pulso · ${periodLabel()}`, `Hola, ${esc(state.settings.business.owner?.split(' ')[0] || '')}.`, `${dDate(r.from)} – ${dDate(r.to)}. Tocá cualquier cifra para ver de dónde sale.`, `<button class="btn" data-act="palette" data-mode="crear">${ic('plus')} Crear</button><button class="btn btn-lens" data-act="newSale">${ic('zap')} Registrar venta</button>`);
    h += missBanner(m.miss);
    if (empty) {
      const ideas = IDEAS.filter(([n]) => !state.projects.some(p => norm(p.name) === norm(n)) && state.settings.brands.length);
      h += `<div class="card" style="margin-bottom:14px;background:linear-gradient(120deg,var(--panel),var(--lens-soft))"><div class="eyebrow">Primeros pasos</div><h3 style="font-size:22px;margin:6px 0 4px">Tu centro de operaciones está listo y vacío</h3><p class="muted" style="margin:0 0 14px">Nada se inventa: cargá lo real y el panel se llena solo. Podés empezar manual e importar después.</p>
        <div class="btn-row">${state.settings.currencies.length > 1 ? `<button class="btn" data-act="newRate">${ic('swap')} 1. Tipo de cambio</button>` : ''}<button class="btn" data-act="newProject">${ic('apps')} Registrar una app</button><button class="btn" data-act="newProduct">${ic('ventas')} Cargar un producto</button><button class="btn" data-act="newExpense">${ic('gastos')} Registrar un gasto</button><button class="btn" data-act="newResource">${ic('infra')} Inventariar hosting/dominio</button><button class="btn" data-act="importCSV">${ic('upload')} Importar CSV</button></div>
        ${ideas.length ? `<div class="small muted" style="margin-top:14px">Proyectos mencionados en tu descripción (tocá para crear la ficha):</div><div class="btn-row" style="margin-top:6px">${ideas.map(([n, b, d]) => `<button class="btn btn-sm btn-ghost" style="border:1px dashed var(--line-2)" data-act="ideaProject" data-n="${esc(n)}" data-b="${b}" data-d="${esc(d)}">+ ${esc(n)}</button>`).join('')}</div>` : ''}</div>`;
    }
    h += `<div class="bento">
      <div class="card hero s6 r2" data-trace="result">${heroFlow()}<div><div class="lbl">Resultado del negocio ${infoI('Ingresos netos − comisiones − impuestos − gastos operativos atribuibles.')}</div><div class="val" data-count="${m.result}" data-kind="money">${money(m.result)}</div><div class="sub">${deltaHTML(m.result, pm.result, has)} ${has ? 'vs. período anterior' : ''}</div></div>
        <div class="hero-steps">${[['Brutas', m.gross, 'gross'], ['Netos', m.net, 'net'], ['Comisiones', -m.commission, 'commission'], ['Imp./ret.', -m.tax, 'tax'], ['Gastos', -m.expenses, 'expenses']].map(([l, v, t]) => `<div data-trace="${t}"><span>${l}</span><b>${moneyShort(v)}</b></div>`).join('')}</div></div>
      ${tile('s3', 'gross', 'Ventas brutas', m.gross, deltaHTML(m.gross, pm.gross, has) + ` <span>${fnum(m.salesCount)} operaciones</span>`, 'Total vendido antes de descuentos y devoluciones.')}
      ${tile('s3', 'net', 'Ingresos netos', m.net, deltaHTML(m.net, pm.net, has) + (m.discount || m.refunded ? ` <span>−${moneyShort(m.discount + m.refunded)} desc./reemb.</span>` : ''), 'Ventas brutas − descuentos − reembolsos.')}
      ${tile('s3', 'collected', 'Dinero cobrado', m.collected, deltaHTML(m.collected, pm.collected, has), 'Pagos efectivamente recibidos en tus cuentas en el período.')}
      ${tile('s3', 'expenses', 'Gastos operativos', m.expenses, deltaHTML(m.expenses, pm.expenses, has, true) + (m.invest ? ` <span data-trace="invest">+ ${moneyShort(m.invest)} inversión</span>` : ''), 'Gastos del período sin inversiones. Los compartidos se cuentan una sola vez.')}
      ${tile('s3 gold-tile', 'mrr', 'MRR', sm.mrr, `ARR ${moneyShort(sm.arr)}`, 'Ingreso recurrente mensual de suscripciones activas hoy.')}
      ${tile('s3', '', 'Suscripciones activas', sm.active, `${sm.trial ? sm.trial + ' en prueba · ' : ''}${sm.unpaid ? `<span class="neg">${sm.unpaid} con pago fallido</span>` : 'sin pagos fallidos'}`, 'Suscriptores que pagan (no incluye pruebas).', 'num')}
      ${tile('s3', 'commission', 'Comisiones', m.commission, m.net ? pct(m.commission / m.net * 100) + ' de los ingresos netos' : '', 'Cargos de plataformas y procesadores de pago.')}
      ${tile('s3', 'tax', 'Impuestos y retenciones', m.tax, '', 'Registrados en cada venta.')}
      <div class="card s8"><div class="card-h"><h3>Ingresos netos vs. gastos</h3><div class="legend"><span><i style="background:var(--c3)"></i>Ingresos netos (−comis./imp.)</span><span><i style="background:var(--c4)"></i>Gastos</span></div></div><div class="chart" id="flowChart"></div></div>
      <div class="card s4"><div class="card-h"><h3>Alertas</h3><a href="#/calendario" class="small muted">Calendario →</a></div>${alertsFeed(6)}</div>
      <div class="card s4"><div class="card-h"><h3>Ventas por marca</h3></div>${donutHTML(Object.entries(m.byBrand).sort((a, b) => b[1] - a[1]).map(([b, v]) => ({ label: brand(b)?.name || 'Sin marca', value: Math.max(0, v), color: brand(b)?.color || 'var(--c8)' })), v => money(v))}<div class="btn-row" style="margin-top:10px">${Object.keys(m.byBrand).map(b => `<button class="btn btn-sm btn-ghost" data-trace="brand:${b}">${esc(brand(b)?.name || 'Sin marca')} →</button>`).join('')}</div></div>
      <div class="card s4"><div class="card-h"><h3>Recurrente vs. única</h3>${infoI('Ingresos netos de cobros de suscripciones frente a ventas de pago único.')}</div>${donutHTML([{ label: 'Recurrente', value: Math.max(0, m.recurring), color: 'var(--c2)' }, { label: 'Única / externa', value: Math.max(0, m.oneTime), color: 'var(--c1)' }], v => money(v))}<div class="btn-row" style="margin-top:10px"><button class="btn btn-sm btn-ghost" data-trace="recurring">Recurrente →</button><button class="btn btn-sm btn-ghost" data-trace="onetime">Única →</button></div></div>
      <div class="card s4"><div class="card-h"><h3>Gastos por categoría</h3></div>${barsHTML(Object.entries(m.expByGroup).sort((a, b) => b[1] - a[1]).map(([g, v], i) => ({ label: g, value: v, color: ['var(--c4)', 'var(--c5)', 'var(--c2)', 'var(--c7)'][i % 4], trace: 'expgroup:' + g })), { fmt: v => moneyShort(v) })}${m.unassigned ? `<p class="tiny muted" style="margin-bottom:0">${moneyShort(m.unassigned)} son gastos generales sin asignar a una marca o app.</p>` : ''}</div>
      <div class="card s6"><div class="card-h"><h3>Rentabilidad por aplicación</h3><span class="tiny muted">resultado operativo del período</span></div>${divBarsHTML(state.projects.filter(p => lensOk(p.brandId)).map(p => ({ p, pl: projectPnL(p.id, r) })).filter(x => x.pl.rev || x.pl.cost).sort((a, b) => b.pl.op - a.pl.op).slice(0, 8).map(x => ({ label: x.p.name, value: x.pl.op, dot: brandColor(x.p.brandId), trace: 'project:' + x.p.id, sub: `ingresos ${moneyShort(x.pl.rev)}` })), v => moneyShort(v))}</div>
      <div class="card s6"><div class="card-h"><h3>Productos que más venden</h3><span class="tiny muted">ingresos netos</span></div>${barsHTML(Object.entries(m.byProduct).sort((a, b) => b[1].net - a[1].net).slice(0, 7).map(([pid, x]) => ({ label: product(pid)?.name || '—', value: x.net, sub: fnum(x.units) + ' u.', color: brandColor(product(pid)?.brandId), trace: 'product:' + pid })), { fmt: v => moneyShort(v) })}</div>
      <div class="card s4"><div class="card-h"><h3>Comisiones por plataforma</h3></div>${barsHTML(Object.entries(m.commByChannel).filter(x => x[1]).sort((a, b) => b[1] - a[1]).map(([c, v]) => ({ label: channel(c)?.name || 'Sin canal', value: v, color: 'var(--c5)', trace: 'commch:' + c, sub: m.byChannel[c] ? pct(v / m.byChannel[c] * 100) : '' })), { fmt: v => moneyShort(v) })}</div>
      <div class="card s4"><div class="card-h"><h3>Clientes</h3>${infoI('Nuevo: su primera compra cae en el período. Recurrente: ya había comprado antes.')}</div>${donutHTML([{ label: 'Nuevos', value: m.customersNew, color: 'var(--c3)' }, { label: 'Recurrentes', value: m.customersRec, color: 'var(--c6)' }], v => fnum(v)).replace(/>[^<]*<\/text><\/svg>/, `>${m.customersNew + m.customersRec}</text></svg>`)}</div>
      <div class="card s4"><div class="card-h"><h3>Suscripciones ganadas y perdidas</h3></div><div class="chart" id="subsChart" style="min-height:150px"></div><div class="legend"><span><i style="background:var(--c3)"></i>Ganadas</span><span><i style="background:var(--c4)"></i>Perdidas</span></div></div>
      <div class="card s8"><div class="card-h"><h3>Resultado mensual</h3><span class="tiny muted">últimos 12 meses</span></div><div class="chart" id="monthChart"></div></div>
      <div class="card s4"><div class="card-h"><h3>Mapa del ecosistema</h3><a href="#/marcas" class="small muted">Ampliar →</a></div><div class="map" id="miniMap"></div></div>
    </div>`;
    return h;
  },
  after() {
    const r = resolveRange(), m = metrics(r), len = daysBetween(r.from, r.to) + 1, monthly = len > 92;
    const labels = [], inc = [], exp = [];
    if (monthly) { const agg = {}; for (const [d, v] of Object.entries(m.byDay)) { const k = d.slice(0, 7); agg[k] = agg[k] || { n: 0, e: 0 }; agg[k].n += v.net; agg[k].e += v.exp; } let d = parseD(r.from); d.setDate(1); const end = parseD(r.to); while (d <= end) { const k = fmtDate(d).slice(0, 7); labels.push({ short: MONTHS[d.getMonth()], long: MONTHS_L[d.getMonth()] + ' ' + d.getFullYear() }); inc.push(round2(agg[k]?.n || 0)); exp.push(round2(agg[k]?.e || 0)); d.setMonth(d.getMonth() + 1); } }
    else for (let i = 0; i < len; i++) { const d = addDays(r.from, i); labels.push({ short: dShort(d), long: dDate(d) }); inc.push(round2(m.byDay[d]?.net || 0)); exp.push(round2(m.byDay[d]?.exp || 0)); }
    lineChart($('#flowChart'), { labels, series: [{ name: 'Ingresos netos', values: inc, color: cssVar('--c3') }, { name: 'Gastos', values: exp, color: cssVar('--c4') }], fmt: v => money(v), height: 240 });
    const mm = lastMonths(12);
    groupBars($('#monthChart'), { labels: mm.map(x => ({ short: MONTHS[x.m], long: MONTHS_L[x.m] + ' ' + x.y })), series: [{ name: 'Resultado', values: mm.map(x => metrics(x.r).result), color: cssVar('--c1') }], fmt: v => moneyShort(v), height: 220 });
    const m6 = lastMonths(6);
    groupBars($('#subsChart'), { labels: m6.map(x => ({ short: MONTHS[x.m], long: MONTHS_L[x.m] + ' ' + x.y })), series: [{ name: 'Ganadas', values: m6.map(x => saasMetrics(x.r).newPaying), color: cssVar('--c3') }, { name: 'Perdidas', values: m6.map(x => saasMetrics(x.r).cancels), color: cssVar('--c4'), neg: true }], fmt: v => fnum(v), height: 160, signed: true });
    drawMap($('#miniMap'), { mini: true });
  }
};
function lastMonths(n) { const out = [], d = new Date(); d.setDate(1); for (let i = n - 1; i >= 0; i--) { const x = new Date(d.getFullYear(), d.getMonth() - i, 1); const from = fmtDate(x), last = fmtDate(new Date(x.getFullYear(), x.getMonth() + 1, 0)); out.push({ y: x.getFullYear(), m: x.getMonth(), r: { from, to: last > todayStr() ? todayStr() : last } }); } return out; }
function heroFlow() {
  const cols = state.settings.brands.map(b => b.color).concat(['#C5A46D']);
  let p = ''; for (let i = 0; i < 9; i++) { const y = 20 + i * 28; p += `<path d="M-20 ${y} C 120 ${y - 40 + (i % 3) * 30}, 260 ${y + 50 - (i % 4) * 20}, 620 ${y - 10}" stroke="${cols[i % cols.length]}" style="animation-duration:${4 + (i % 5)}s"/>`; }
  return `<svg class="flow" viewBox="0 0 600 280" preserveAspectRatio="none" aria-hidden="true">${p}</svg>`;
}
function alertsFeed(limit) {
  const al = computeAlerts().filter(a => ui.lens === 'all' || !a.brands || a.brands.includes(ui.lens));
  if (!al.length) return '<p class="muted small">Sin alertas. Todo en orden por ahora.</p>';
  return `<div class="feed">${al.slice(0, limit).map(a => `<div class="feed-it" data-act="openRef" data-k="${a.ref.kind}" data-id="${a.ref.id || ''}"><span class="urg ${a.urg}"></span><div style="min-width:0"><b style="font-size:13.5px">${esc(a.title)}</b><div class="small muted">${esc(a.detail)}</div></div></div>`).join('')}</div>${al.length > limit ? `<a href="#/calendario" class="small muted">y ${al.length - limit} más →</a>` : ''}`;
}
ACT.openRef = el => { const k = el.dataset.k, id = el.dataset.id; ({ expense: () => openExpense(id), resource: () => openResource(id), sub: () => openSub(id), project: () => openProject(id), product: () => openProduct(id), channel: () => openTrace('channel:' + id), integration: () => go('integraciones'), review: () => { ui.tabs.integ = 'review'; go('integraciones'); }, event: () => editEvent(id) })[k]?.(); };
ACT.ideaProject = el => projectForm(null, { name: el.dataset.n, brandId: el.dataset.b, desc: el.dataset.d });

/* ---------- mapa del ecosistema ---------- */
function drawMap(el, { mini = false } = {}) {
  if (!el) return;
  const W = Math.max(280, el.clientWidth), H = mini ? Math.min(300, W * .9) : Math.max(420, Math.min(620, W * .62)), cx = W / 2, cy = H / 2;
  const S = state.settings, R1 = Math.min(W, H) * .2, R2 = Math.min(W, H) * .36, R3 = Math.min(W, H) * .47;
  const allTime = { from: '0000-01-01', to: '9999-12-31' };
  const nodes = [], edges = [];
  nodes.push({ id: 'core', x: cx, y: cy, r: mini ? 14 : 20, fill: 'var(--struct)', label: mini ? '' : (S.business.name || 'Ecosistema') });
  const brands = S.brands; const bPos = {};
  brands.forEach((b, i) => { const a = i / Math.max(1, brands.length) * Math.PI * 2 - Math.PI / 2; const x = cx + Math.cos(a) * R1, y = cy + Math.sin(a) * R1; bPos[b.id] = { a, x, y }; nodes.push({ id: 'b:' + b.id, x, y, r: mini ? 10 : 15, fill: b.color, label: b.name, act: `data-act="setLens" data-id="${b.id}"` }); edges.push([cx, cy, x, y, true]); });
  const pPos = {};
  brands.forEach(b => {
    const ps = state.projects.filter(p => p.brandId === b.id), prods = state.products.filter(p => p.brandId === b.id && !p.projectId);
    const items = [...ps.map(p => ({ t: 'p', p })), ...(prods.length ? [{ t: 'catalog', n: prods.length }] : [])];
    const spread = Math.PI * 2 / Math.max(1, brands.length) * .8;
    items.forEach((it, j) => {
      const a = bPos[b.id].a + (items.length > 1 ? (j / (items.length - 1) - .5) * spread : 0), x = cx + Math.cos(a) * R2, y = cy + Math.sin(a) * R2;
      if (it.t === 'p') { const pl = projectPnL(it.p.id, allTime); const rr = Math.max(mini ? 4 : 6, Math.min(mini ? 10 : 18, 5 + Math.sqrt(Math.max(0, pl.rev)) / 30)); pPos[it.p.id] = { x, y, a }; nodes.push({ id: 'p:' + it.p.id, x, y, r: rr, fill: 'var(--panel)', stroke: pl.op >= 0 ? 'var(--pos)' : 'var(--coral)', label: mini ? '' : it.p.name, act: `data-act="openProjectEl" data-id="${it.p.id}"`, title: `${it.p.name} · ingresos ${money(pl.rev)} · resultado ${money(pl.op)}` }); }
      else nodes.push({ id: 'c:' + b.id, x, y, r: mini ? 6 : 10, fill: b.alt || b.color, label: mini ? '' : `${it.n} producto${it.n > 1 ? 's' : ''}`, act: `data-act="setLensGo" data-id="${b.id}" data-r="ventas"` });
      edges.push([bPos[b.id].x, bPos[b.id].y, x, y, false]);
    });
  });
  const res = state.resources.filter(r => r.status !== 'cancelado');
  res.forEach((r, i) => {
    const linked = (r.projectIds || []).map(id => pPos[id]).filter(Boolean);
    const a = linked.length ? Math.atan2(sum(linked, p => Math.sin(p.a)), sum(linked, p => Math.cos(p.a))) + (i % 3 - 1) * .12 : i / Math.max(1, res.length) * Math.PI * 2;
    const x = cx + Math.cos(a) * R3, y = cy + Math.sin(a) * R3;
    nodes.push({ id: 'r:' + r.id, x, y, r: mini ? 3.5 : 6, fill: r.status === 'sin_uso' ? 'var(--warn)' : 'var(--c5)', label: mini ? '' : r.name, small: true, act: `data-act="openResourceEl" data-id="${r.id}"`, title: `${statusOf(RES_TYPES, r.type).label}: ${r.name}` });
    linked.forEach(p => edges.push([x, y, p.x, p.y, false, true]));
  });
  const svg = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Mapa del ecosistema">
    <circle class="ring" cx="${cx}" cy="${cy}" r="${R1}"/><circle class="ring" cx="${cx}" cy="${cy}" r="${R2}"/>${res.length ? `<circle class="ring" cx="${cx}" cy="${cy}" r="${R3}"/>` : ''}
    ${edges.map(e => `<path class="edge ${e[4] ? 'flowing' : ''}" d="M${e[0]},${e[1]} L${e[2]},${e[3]}" ${e[5] ? 'stroke-dasharray="2 4" opacity=".6"' : ''}/>`).join('')}
    ${nodes.map((n, i) => `<g class="node" ${n.act || ''} style="animation:pageIn .5s ${i * .03}s both"><circle cx="${n.x}" cy="${n.y}" r="${n.r}" fill="${n.fill}" stroke="${n.stroke || 'var(--panel)'}" stroke-width="${n.stroke ? 3 : 2}"><title>${esc(n.title || n.label)}</title></circle>${n.label ? `<text x="${n.x}" y="${n.y + n.r + 13}" text-anchor="middle" style="font-size:${n.small ? 9.5 : 11}px;${n.small ? 'fill:var(--muted)' : 'font-weight:600'}">${esc(n.label.length > 22 ? n.label.slice(0, 21) + '…' : n.label)}</text>` : ''}</g>`).join('')}
  </svg>`;
  el.innerHTML = svg + (!mini ? `<div class="legend" style="justify-content:center;margin-top:8px"><span><i style="background:var(--struct)"></i>Ecosistema</span><span><i style="border:2px solid var(--pos);background:none"></i>App con resultado ≥ 0</span><span><i style="border:2px solid var(--coral);background:none"></i>App con resultado negativo</span><span><i style="background:var(--c5)"></i>Infraestructura</span><span><i style="background:var(--warn)"></i>Recurso sin uso</span></div>` : '');
}
ACT.openProjectEl = el => openProject(el.dataset.id);
ACT.openResourceEl = el => openResource(el.dataset.id);
ACT.setLensGo = el => { ui.lens = el.dataset.id; saveUI(); applyLens(); go(el.dataset.r); };

/* ---------- Marcas ---------- */
VIEWS.marcas = {
  render() {
    const r = resolveRange(), S = state.settings;
    let h = pageHead('Mis marcas', 'Marcas', 'Rendimiento individual de cada marca. Los gastos se atribuyen según su asignación (directa o vía aplicaciones).', `<button class="btn" data-act="go" data-r="ajustes">${ic('ajustes')} Editar marcas</button>`);
    if (!S.brands.length) return h + emptyBox('marcas', 'Sin marcas', 'Creá tus marcas en Ajustes para separar sus resultados.', `<button class="btn btn-primary" data-act="go" data-r="ajustes">Ir a Ajustes</button>`);
    const saved = ui.lens; const rows = S.brands.map(b => { ui.lens = b.id; const m = metrics(r); const sm = saasMetrics(r); ui.lens = saved; return { b, m, sm }; });
    h += `<div class="bento">${rows.map(({ b, m, sm }) => {
      const prods = state.products.filter(p => p.brandId === b.id), apps = state.projects.filter(p => p.brandId === b.id);
      const top = Object.entries(m.byProduct).sort((x, y) => y[1].net - x[1].net).slice(0, 4);
      return `<div class="card s4" style="padding:0"><div style="height:86px;background:linear-gradient(120deg,${esc(b.dark)},${esc(b.color)} 60%,${esc(b.alt || b.color)});position:relative"><span style="position:absolute;left:18px;bottom:12px;color:#fff;font-family:var(--head);font-size:22px;font-weight:700;text-shadow:0 2px 12px rgba(0,0,0,.3)">${esc(b.name)}</span></div>
        <div style="padding:16px 18px"><p class="small muted" style="margin:0 0 12px">${esc(b.desc || '')}</p>
        <div class="kv" style="margin-bottom:14px"><div data-trace="brand:${b.id}" style="cursor:pointer"><span>Ingresos netos</span><b class="mono">${money(m.net)}</b></div><div><span>Gastos atribuidos</span><b class="mono">${money(m.expenses)}</b></div><div><span>Resultado</span><b class="mono ${m.result < 0 ? 'neg' : 'posv'}">${money(m.result)}</b></div><div><span>MRR</span><b class="mono">${money(sm.mrr)}</b></div><div><span>Productos</span><b>${prods.length}</b></div><div><span>Apps</span><b>${apps.length}</b></div></div>
        <div class="eyebrow" style="margin-bottom:6px">Más vendidos</div>${top.length ? barsHTML(top.map(([pid, x]) => ({ label: product(pid)?.name || '—', value: x.net, color: b.color, trace: 'product:' + pid })), { fmt: v => moneyShort(v) }) : '<p class="small muted">Sin ventas en el período.</p>'}
        <div class="btn-row" style="margin-top:14px"><button class="btn btn-sm" data-act="setLens" data-id="${b.id}">Ver todo con esta lente</button></div></div></div>`; }).join('')}
      <div class="card s12"><div class="card-h"><h3>Mapa del ecosistema</h3><span class="small muted">Marcas → aplicaciones y catálogos → infraestructura. Tamaño según ingresos históricos.</span></div><div class="map" id="bigMap"></div></div>
      <div class="card s12"><div class="card-h"><h3>Comparación de marcas</h3></div><div class="table-wrap"><table class="rt cards"><thead><tr><th>Marca</th><th class="right">Ventas brutas</th><th class="right">Ingresos netos</th><th class="right">Comisiones</th><th class="right">Gastos</th><th class="right">Resultado</th><th class="right">% del total</th></tr></thead><tbody>
        ${(() => { const tot = sum(rows, x => x.m.net) || 1; return rows.map(({ b, m }) => `<tr class="click" data-trace="brand:${b.id}"><td class="first">${brandChip(b.id)}</td><td data-l="Brutas" class="right num">${money(m.gross)}</td><td data-l="Netos" class="right num">${money(m.net)}</td><td data-l="Comisiones" class="right num">${money(m.commission)}</td><td data-l="Gastos" class="right num">${money(m.expenses)}</td><td data-l="Resultado" class="right num ${m.result < 0 ? 'neg' : 'posv'}">${money(m.result)}</td><td data-l="% total" class="right num">${pct(m.net / tot * 100)}</td></tr>`).join(''); })()}</tbody></table></div></div>
    </div>`;
    return h;
  },
  after() { drawMap($('#bigMap')); }
};
/* =====================================================================
   APLICACIONES, PRODUCTOS Y VENTAS
   ===================================================================== */
const brandOpts = sel => opts(state.settings.brands.map(b => ({ id: b.id, label: b.name })), sel, 'Elegí una marca…');
const curOpts = sel => opts(state.settings.currencies.map(c => ({ id: c.code, label: c.code })), sel || base());
const projOpts = (sel, empty = 'Ninguna') => opts(state.projects.map(p => ({ id: p.id, label: p.name + (brand(p.brandId) ? ' · ' + brand(p.brandId).name : '') })), sel, empty);
function saleTitle(s) { const p = product(s.productId); if (p) return p.name + (versionOf(p, s.versionId) ? ' · ' + versionLabel(versionOf(p, s.versionId)) : ''); if (s.planId) return (proj(s.projectId)?.name || 'Suscripción') + ' · ' + (plan(s.planId)?.name || ''); if (s.projectId) return proj(s.projectId)?.name + (s.concept ? ' · ' + s.concept : ''); return s.concept || 'Ingreso'; }

/* ---------------- APLICACIONES ---------------- */
VIEWS.apps = {
  render() {
    const r = resolveRange(), f = ui.filters.apps || (ui.filters.apps = {});
    let list = state.projects.filter(p => lensOk(p.brandId) && (!f.status || p.status === f.status) && (!f.kind || p.kind === f.kind));
    let h = pageHead('Mis aplicaciones', 'Aplicaciones y proyectos', 'Cada app con su ficha técnica, monetización, usuarios, costos asignados y recuperación de la inversión — esté o no dentro de la tienda principal.', `<button class="btn btn-lens" data-act="newProject">${ic('plus')} Nueva app / proyecto</button>`);
    if (!state.projects.length) {
      const ideas = IDEAS.filter(([n]) => state.settings.brands.length);
      return h + emptyBox('apps', 'Sin aplicaciones registradas', 'Registrá tus apps publicadas, en desarrollo o internas. Vas a ver cuánto genera cada una y cuánto cuesta mantenerla.', `<div class="btn-row" style="justify-content:center"><button class="btn btn-primary" data-act="newProject">${ic('plus')} Registrar app</button>${ideas.map(([n, b, d]) => `<button class="btn btn-ghost" style="border:1px dashed var(--line-2)" data-act="ideaProject" data-n="${esc(n)}" data-b="${b}" data-d="${esc(d)}">+ ${esc(n)}</button>`).join('')}</div>`);
    }
    h += `<div class="filters"><select class="input sm" style="width:auto" data-change="appFilt" data-f="status">${opts(APP_STATUS, f.status, 'Todos los estados')}</select><select class="input sm" style="width:auto" data-change="appFilt" data-f="kind">${opts(APP_KINDS, f.kind, 'Todos los tipos')}</select><span class="small muted" style="margin-left:auto">${list.length} de ${state.projects.length}</span></div>`;
    if (!list.length) return h + '<div class="card"><p class="muted" style="margin:0">Ninguna app coincide con la lente o los filtros.</p></div>';
    h += `<div class="app-grid">${list.map((p, i) => { const pl = projectPnL(p.id, r), acc = projectPnL(p.id), pay = projectPaying(p.id), u = latestUsers(p);
      return `<div class="card app-card" data-act="openProjectEl" data-id="${p.id}" style="animation-delay:${i * 40}ms"><span class="bar" style="background:${brandColor(p.brandId)}"></span>
        <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start"><div><b style="font-family:var(--head);font-size:17px">${esc(p.name)}</b><div class="small muted">${esc(statusOf(APP_KINDS, p.kind).label)}</div></div>${badge(APP_STATUS, p.status)}</div>
        <div class="mini"><div><span>Ingresos</span><b>${moneyShort(pl.rev)}</b></div><div><span>Resultado</span><b class="${pl.op < 0 ? 'neg' : ''}">${moneyShort(pl.op)}</b></div><div><span>${p.kind === 'suscripcion' ? 'Suscript.' : 'Clientes'}</span><b>${p.kind === 'suscripcion' ? pay.subs : pay.buyers}</b></div></div>
        ${acc.invest > 0 ? `<div style="margin-top:12px"><div class="small" style="display:flex;justify-content:space-between"><span class="muted">Recuperación de inversión</span><b class="mono">${pct(Math.max(0, acc.recovery))}</b></div><div class="progress" style="margin-top:5px"><i style="width:${Math.min(100, Math.max(0, acc.recovery))}%"></i></div></div>` : ''}
        ${u ? `<div class="tiny muted" style="margin-top:10px">${fnum(u.registered)} registrados · ${fnum(u.active)} activos (${dShort(u.date)})</div>` : ''}</div>`; }).join('')}</div>`;
    return h;
  }
};
CHG.appFilt = el => { (ui.filters.apps = ui.filters.apps || {})[el.dataset.f] = el.value; saveUI(); rerender(); };
ACT.newProject = () => projectForm(null);
function projectForm(p, pre = {}) {
  if (!state.settings.brands.length) { toast('Primero creá una marca en Ajustes', 'bad'); return; }
  const v = { ...pre, ...(p || {}) };
  const m = openModal({ title: p ? 'Editar aplicación' : 'Nueva aplicación o proyecto', size: 'lg', body: `<form id="prF" class="form-grid c3" novalidate>
    ${field('Nombre *', `<input class="input" name="name" value="${esc(v.name || '')}">`)}${field('Marca *', `<select class="input" name="brandId">${brandOpts(v.brandId)}</select>`)}${field('Estado *', `<select class="input" name="status">${opts(APP_STATUS, v.status, 'Elegí…')}</select>`)}
    ${field('Tipo de monetización *', `<select class="input" name="kind">${opts(APP_KINDS, v.kind, 'Elegí…')}</select>`, '', 'Define cómo se calcula su rentabilidad.')}${field('Fecha de lanzamiento', `<input class="input" type="date" name="launchDate" value="${v.launchDate || ''}">`)}${field('URL', `<input class="input" name="url" value="${esc(v.url || '')}" placeholder="https://">`)}
    ${field('Descripción y propósito', `<textarea class="input" name="desc" rows="2">${esc(v.desc || '')}</textarea>`, 'full')}
    <div class="form-sec">Ficha técnica</div>
    ${field('Dominio', `<input class="input" name="domain" value="${esc(v.domain || '')}">`)}${field('Repositorio', `<input class="input" name="repo" value="${esc(v.repo || '')}" placeholder="github.com/…">`)}${field('Hosting', `<input class="input" name="hosting" value="${esc(v.hosting || '')}">`)}
    ${field('Base de datos', `<input class="input" name="database" value="${esc(v.database || '')}">`)}${field('Tecnología', `<input class="input" name="tech" value="${esc(v.tech || '')}" placeholder="Ej.: Flask, HTML…">`)}${field('Próximo hito técnico/comercial', `<input class="input" name="milestone" value="${esc(v.milestone || '')}">`)}
    <div class="form-sec">Inversión inicial no registrada como gasto (opcional)</div>
    ${field('Importe', `<input class="input" type="number" min="0" step="any" name="initialInvestment" value="${v.initialInvestment ?? ''}">`, '', 'Si ya cargás los gastos de desarrollo como “inversión”, dejalo vacío para no duplicar.')}${field('Moneda', `<select class="input" name="investCurrency">${curOpts(v.investCurrency)}</select>`)}
    ${field('Notas', `<input class="input" name="notes" value="${esc(v.notes || '')}">`)}
  </form><div class="err-msg" id="prErr" style="margin-top:8px"></div>`, footer: `<button class="btn btn-ghost" data-cancel>Cancelar</button><button class="btn btn-primary" data-save>Guardar</button>` });
  m.el.querySelector('[data-cancel]').onclick = () => m.close();
  m.el.querySelector('[data-save]').onclick = async () => {
    const f = $('#prF', m.el), d = formData(f), bad = ['name', 'brandId', 'status', 'kind'].filter(k => !d[k]);
    if (state.projects.some(x => x.id !== p?.id && norm(x.name) === norm(d.name))) { bad.push('name'); $('#prErr', m.el).textContent = 'Ya existe una app con ese nombre.'; }
    const inv = d.initialInvestment === '' ? null : num(d.initialInvestment); if (inv != null && inv < 0) bad.push('initialInvestment');
    markInvalid(f, bad); if (bad.length) { if (!$('#prErr', m.el).textContent) $('#prErr', m.el).textContent = 'Completá los campos marcados.'; return; }
    try { const id = await transact('app', st => { const rec = { ...d, initialInvestment: inv }; if (p) { Object.assign(st.projects.find(x => x.id === p.id), rec); logAct(st, 'App editada', d.name); return p.id; } const n = { id: uid('ap'), createdAt: new Date().toISOString(), userStats: [], ...rec }; st.projects.push(n); logAct(st, 'App registrada', d.name); return n.id; }); toast('App guardada'); m.close(); render(); if (!p) setTimeout(() => openProject(id), 250); } catch (e) { }
  };
}
function openProject(id) {
  const p = proj(id); if (!p) return;
  while (modalStack.length) modalStack[0].close();
  const r = resolveRange(), pl = projectPnL(id, r), acc = projectPnL(id), pay = projectPaying(id), u = latestUsers(p);
  const plans = state.plans.filter(x => x.projectId === id), subs = state.subs.filter(s => s.projectId === id);
  const ev = subs.flatMap(s => s.events).filter(e => inR(e.date, r));
  const res = state.resources.filter(x => (x.projectIds || []).includes(id));
  const hr = state.settings.hourly; const hoursCost = hr.rate && acc.hours ? conv(acc.hours * hr.rate, hr.currency, todayStr()) : null;
  const upcoming = calendarEvents(todayStr(), addDays(todayStr(), 90)).filter(e => (e.ref.kind === 'resource' && res.some(x => x.id === e.ref.id)) || (e.ref.kind === 'expense' && expProjectShare(byId(state.expenses, e.ref.id), id) > 0) || (e.ref.kind === 'project' && e.ref.id === id));
  const d = openDrawer({ title: esc(p.name), body: `
    <div class="btn-row" style="margin-bottom:12px">${brandChip(p.brandId)} ${badge(APP_STATUS, p.status)} <span class="badge">${esc(statusOf(APP_KINDS, p.kind).label)}</span></div>
    ${p.desc ? `<p style="margin-top:0">${esc(p.desc)}</p>` : ''}
    <div class="bento" style="grid-template-columns:repeat(2,minmax(0,1fr));margin-bottom:14px">
      <div class="card tile" data-trace="project:${id}" style="grid-column:auto"><div class="lbl">Ingresos netos · período</div><div class="val" style="font-size:21px">${money(pl.rev)}</div><div class="sub">${pl.sales} operaciones</div></div>
      <div class="card tile" style="grid-column:auto"><div class="lbl">Resultado operativo · período</div><div class="val ${pl.op < 0 ? 'neg' : 'posv'}" style="font-size:21px">${money(pl.op)}</div><div class="sub">costos ${moneyShort(pl.cost + pl.com + pl.tax)}</div></div>
    </div>
    <div class="card" style="margin-bottom:14px"><div class="eyebrow" style="margin-bottom:8px">Estado de resultados del período</div>
      ${[['Ingresos netos', pl.rev], ['− Comisiones', -pl.com], ['− Impuestos/retenciones', -pl.tax], ['− Costos asignados (hosting, DB, herramientas, publicidad…)', -pl.cost], ['= Resultado operativo', pl.op]].map(([l, v], i) => `<div class="bar-row" style="padding:6px 0;border-bottom:${i < 4 ? '1px dashed var(--line)' : '0'}"><span class="bl ${i === 4 ? '' : 'small'}">${i === 4 ? `<b>${l}</b>` : l}</span><span class="bv ${v < 0 ? 'neg' : ''}">${money(v)}</span></div>`).join('')}
      ${pl.ads ? `<div class="tiny muted" style="margin-top:6px">Incluye ${money(pl.ads)} de comercialización/publicidad.</div>` : ''}</div>
    <div class="card" style="margin-bottom:14px"><div class="eyebrow" style="margin-bottom:8px">Inversión y recuperación (histórico)</div>
      <div class="kv"><div><span>Inversión acumulada</span><b class="mono">${money(acc.invest)}</b></div><div><span>Resultado operativo acumulado</span><b class="mono ${acc.op < 0 ? 'neg' : ''}">${money(acc.op)}</b></div><div><span>Horas registradas</span><b class="mono">${fnum(acc.hours, 1)} h</b></div><div><span>Valor estimado de horas</span><b class="mono">${hoursCost != null ? money(hoursCost) + ' <span class="origin estimado">estimado</span>' : '<span class="muted small">Definí tu valor hora en Ajustes</span>'}</b></div></div>
      ${acc.invest > 0 ? `<div style="margin-top:12px"><div class="small" style="display:flex;justify-content:space-between"><span>${acc.recovery >= 100 ? '✓ Inversión recuperada' : acc.op > 0 ? 'Genera ganancias pero aún no recuperó la inversión' : 'Aún no genera resultado positivo'}</span><b class="mono">${pct(Math.max(0, acc.recovery))}</b></div><div class="progress" style="margin-top:6px"><i style="width:${Math.min(100, Math.max(0, acc.recovery))}%"></i></div>${acc.op > 0 && acc.recovery < 100 && pl.op > 0 ? `<div class="tiny muted" style="margin-top:6px">Al ritmo del período elegido, faltarían ~${fnum(Math.ceil((acc.invest - acc.op) / (pl.op / Math.max(1, (daysBetween(r.from, r.to) + 1) / 30.4))))} meses <span class="origin estimado">estimado</span></div>` : ''}</div>` : '<p class="small muted" style="margin:10px 0 0">Sin inversión registrada. Marcá los gastos de desarrollo como “inversión” y asignalos a esta app.</p>'}</div>
    <div class="card" style="margin-bottom:14px"><div class="card-h" style="margin-bottom:8px"><div class="eyebrow">Usuarios y clientes</div><button class="btn btn-sm" data-users>Actualizar usuarios</button></div>
      <div class="kv"><div><span>Registrados</span><b class="mono">${u ? fnum(u.registered) : '—'}</b></div><div><span>Activos</span><b class="mono">${u ? fnum(u.active) : '—'}</b></div><div><span>Clientes que pagan</span><b class="mono">${p.kind === 'suscripcion' ? pay.subs + ' suscripciones activas' : pay.buyers + ' compradores'}</b></div><div><span>Dato de usuarios</span><b class="small">${u ? `${dDate(u.date)} ${originTag(u.origin) || '<span class="origin">manual</span>'}` : 'sin cargar'}</b></div></div></div>
    ${p.kind === 'suscripcion' || plans.length ? `<div class="card" style="margin-bottom:14px"><div class="card-h" style="margin-bottom:8px"><div class="eyebrow">Planes y suscripciones</div><button class="btn btn-sm" data-plan>+ Plan</button></div>
      ${plans.length ? `<div class="btn-row" style="margin-bottom:10px">${plans.map(x => `<button class="badge info" style="border:0;cursor:pointer" data-editplan="${x.id}">${esc(x.name)} · ${money(x.price, x.currency)}/${x.period === 'anual' ? 'año' : 'mes'}${x.trialDays ? ` · ${x.trialDays}d prueba` : ''}</button>`).join('')}</div>` : '<p class="small muted">Definí al menos un plan para registrar suscripciones.</p>'}
      <div class="kv" style="grid-template-columns:repeat(3,1fr)"><div><span>Activas</span><b class="mono">${subs.filter(s => s.status === 'activa').length}</b></div><div><span>Nuevas</span><b class="mono">${ev.filter(e => e.type === 'alta' || e.type === 'conversion').length}</b></div><div><span>Renovaciones</span><b class="mono">${ev.filter(e => e.type === 'renovacion').length}</b></div><div><span>Cancelaciones</span><b class="mono">${ev.filter(e => e.type === 'cancelacion').length}</b></div><div><span>Pagos fallidos</span><b class="mono">${ev.filter(e => e.type === 'pago_fallido').length}</b></div><div><span>En prueba</span><b class="mono">${subs.filter(s => s.status === 'prueba').length}</b></div></div></div>` : ''}
    <div class="card" style="margin-bottom:14px"><div class="eyebrow" style="margin-bottom:8px">Ficha técnica</div><div class="kv">
      <div><span>URL</span><b>${p.url ? `<a href="${esc(p.url)}" target="_blank" rel="noopener">${esc(p.url)}</a>` : '—'}</b></div><div><span>Dominio</span><b>${esc(p.domain || '—')}</b></div><div><span>Repositorio</span><b>${esc(p.repo || '—')}</b></div><div><span>Hosting</span><b>${esc(p.hosting || '—')}</b></div><div><span>Base de datos</span><b>${esc(p.database || '—')}</b></div><div><span>Tecnología</span><b>${esc(p.tech || '—')}</b></div><div><span>Lanzamiento</span><b>${dDate(p.launchDate) || '—'}</b></div><div><span>Próximo hito</span><b>${esc(p.milestone || '—')}</b></div></div>
      ${res.length ? `<div class="eyebrow" style="margin:14px 0 6px">Recursos vinculados</div><div class="btn-row">${res.map(x => `<button class="badge" style="border:0;cursor:pointer" data-act="openResourceEl" data-id="${x.id}">${esc(statusOf(RES_TYPES, x.type).label)}: ${esc(x.name)}</button>`).join('')}</div>` : ''}</div>
    ${upcoming.length ? `<div class="card"><div class="eyebrow" style="margin-bottom:6px">Próximos vencimientos (90 días)</div><div class="feed">${upcoming.map(e => `<div class="feed-it" data-act="openRef" data-k="${e.ref.kind}" data-id="${e.ref.id}"><span class="urg ${e.tone === 'bad' ? 'alta' : 'media'}"></span><div><b class="small">${esc(e.title)}</b><div class="tiny muted">${dDate(e.date)} ${e.amount ? '· ' + esc(e.amount) : ''}</div></div></div>`).join('')}</div></div>` : ''}`,
    footer: `<button class="btn btn-ghost" data-act="newTime" data-p="${id}">${ic('clock')} Registrar horas</button><button class="btn btn-primary" data-edit>${ic('edit')} Editar</button>` });
  d.el.querySelector('[data-edit]').onclick = () => { d.close(); projectForm(p); };
  d.el.querySelector('[data-plan]')?.addEventListener('click', () => planForm(id));
  $$('[data-editplan]', d.el).forEach(b => b.onclick = () => planForm(id, plan(b.dataset.editplan)));
  d.el.querySelector('[data-users]').onclick = () => usersForm(p);
}
function planForm(pid, pl) {
  const m = openModal({ title: pl ? 'Editar plan' : 'Nuevo plan de ' + esc(proj(pid)?.name), size: 'sm', body: `<form id="plF" class="form-grid">${field('Nombre *', `<input class="input" name="name" value="${esc(pl?.name || '')}" placeholder="Ej.: Pro mensual">`, 'full')}${field('Precio *', `<input class="input" type="number" min="0" step="any" name="price" value="${pl?.price ?? ''}">`)}${field('Moneda *', `<select class="input" name="currency">${curOpts(pl?.currency)}</select>`)}${field('Periodicidad *', `<select class="input" name="period">${opt('mensual', 'Mensual', pl?.period)}${opt('anual', 'Anual', pl?.period)}</select>`)}${field('Días de prueba', `<input class="input" type="number" min="0" step="1" name="trialDays" value="${pl?.trialDays ?? ''}">`)}</form>`, footer: `<button class="btn btn-primary" data-save>Guardar plan</button>` });
  m.el.querySelector('[data-save]').onclick = async () => { const f = $('#plF', m.el), d = formData(f), bad = []; if (!d.name) bad.push('name'); const pr = num(d.price); if (pr == null || pr < 0) bad.push('price'); markInvalid(f, bad); if (bad.length) return;
    try { await transact('plan', st => { const rec = { name: d.name, price: round2(pr), currency: d.currency, period: d.period, trialDays: num(d.trialDays) || 0 }; if (pl) Object.assign(st.plans.find(x => x.id === pl.id), rec); else st.plans.push({ id: uid('pl'), projectId: pid, ...rec }); logAct(st, 'Plan', d.name); }); m.close(); openProject(pid); } catch (e) { } };
}
function usersForm(p) {
  const m = openModal({ title: 'Usuarios de ' + esc(p.name), size: 'sm', body: `<form id="usF" class="form-grid">${field('Fecha *', `<input class="input" type="date" name="date" value="${todayStr()}" max="${todayStr()}">`)}${field('Origen', `<select class="input" name="origin">${opts(ORIGINS, 'manual')}</select>`)}${field('Usuarios registrados', '<input class="input" type="number" min="0" step="1" name="registered">')}${field('Usuarios activos', '<input class="input" type="number" min="0" step="1" name="active">')}</form><p class="small muted">Se guarda como una foto con fecha: así se ve la evolución. Los clientes que pagan se calculan solos.</p>`, footer: `<button class="btn btn-primary" data-save>Guardar</button>` });
  m.el.querySelector('[data-save]').onclick = async () => { const d = formData($('#usF', m.el)); const rg = num(d.registered), ac = num(d.active); if (rg == null && ac == null) { toast('Cargá al menos un valor', 'bad'); return; } if ((ac ?? 0) > (rg ?? Infinity)) { toast('Los activos no pueden superar a los registrados', 'bad'); return; }
    try { await transact('usuarios', st => { const P = st.projects.find(x => x.id === p.id); P.userStats = P.userStats || []; P.userStats.push({ date: d.date, registered: rg ?? 0, active: ac ?? 0, origin: d.origin }); logAct(st, 'Usuarios actualizados', `${p.name}: ${rg}/${ac}`); }); m.close(); openProject(p.id); } catch (e) { } };
}
ACT.newTime = el => {
  if (!state.projects.length) { toast('Primero registrá una app o proyecto', 'bad'); return; }
  const m = openModal({ title: 'Registrar horas', size: 'sm', body: `<form id="tmF" class="form-grid">${field('Proyecto *', `<select class="input" name="projectId">${projOpts(el?.dataset?.p || '', 'Elegí…')}</select>`, 'full')}${field('Fecha *', `<input class="input" type="date" name="date" value="${todayStr()}" max="${todayStr()}">`)}${field('Horas *', '<input class="input" type="number" min="0.25" step="0.25" name="hours">')}${field('Tipo', `<select class="input" name="kind">${opt('desarrollo', 'Desarrollo', '')}${opt('mantenimiento', 'Mantenimiento', '')}${opt('contenido', 'Contenido / diseño', '')}${opt('soporte', 'Soporte', '')}</select>`)}${field('Nota', '<input class="input" name="note">')}</form>`, footer: `<button class="btn btn-primary" data-save>Guardar</button>` });
  m.el.querySelector('[data-save]').onclick = async () => { const f = $('#tmF', m.el), d = formData(f), h = num(d.hours), bad = []; if (!d.projectId) bad.push('projectId'); if (!h || h <= 0 || h > 24) bad.push('hours'); markInvalid(f, bad); if (bad.length) return;
    try { await transact('horas', st => { st.timeLogs.push({ id: uid('tm'), ...d, hours: h }); logAct(st, 'Horas', `${proj(d.projectId)?.name}: ${h} h`); }); toast('Horas registradas'); m.close(); } catch (e) { } };
};

/* ---------------- PRODUCTOS Y VENTAS ---------------- */
