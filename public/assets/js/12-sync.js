/* =====================================================================
   SINCRONIZACIÓN: trae de la tienda y de cada app lo que ya existe y lo carga
   en Productos y ventas, Suscripciones, Clientes e Integraciones.
   - No duplica: cada registro lleva un ID externo (descargar:pedido:12, gaku:cuenta:3…).
   - No toca lo que cargaste a mano.
   ===================================================================== */
const SYNC = { running: false, report: null, at: 0, error: '' };
try { const s = JSON.parse(localStorage.getItem('eco-sync') || 'null'); if (s) { SYNC.report = s.report; SYNC.at = s.at || 0; } } catch (e) { }
const SYNC_ALIAS = { bookwat: ['bookwat', 'biblioteca', 'reading'], zuhause: ['zuhause', 'nuestra casa'], gaku: ['gaku', 'mi ritmo'] };
const syncDay = s => String(s || '').slice(0, 10);

async function syncFetch(id, view) {
  const r = await inicioGet('api.php?action=source_data&id=' + encodeURIComponent(id) + '&view=' + view, 30000);
  return r.d && r.d.ok ? r.d.data : null;
}

function syncApply(st, got) {
  const R = { prod_new: 0, prod_link: 0, ventas: 0, ventas_act: 0, clientes: 0, subs: 0, subs_act: 0, apps: 0 };
  const nowIso = new Date().toISOString();
  const S = st.settings;
  const ensureCur = code => { if (code && !S.currencies.some(c => c.code === code)) S.currencies.push({ code, symbol: code }); return code; };
  const chSitio = S.channels.find(c => /sitio web/.test(norm(c.name))) || null;
  const chApp = S.channels.find(c => /aplicacion/.test(norm(c.name))) || null;
  const brandFor = tipo => { const by = id => S.brands.find(b => b.id === id); return ((tipo === 'agendas' ? by('br_yoyir') : by('br_estrategia')) || S.brands[0] || {}).id || ''; };
  const custFor = (email, name, date) => {
    email = String(email || '').trim();
    let c = email && st.customers.find(x => norm(x.email) === norm(email));
    if (!c) { c = { id: uid('cu'), name: name || email || 'Sin nombre', email, country: '', createdAt: date || todayStr(), notes: '', origin: 'integracion' }; st.customers.push(c); R.clientes++; }
    return c.id;
  };
  const integ = (name, kind, projectId) => {
    let I = kind === 'tienda' ? st.integrations.find(i => i.kind === 'tienda') : null;
    if (!I) I = integByName(st, name);
    I.kind = kind; I.method = 'api'; I.status = 'activa'; I.lastSync = nowIso; if (projectId) I.projectId = projectId;
    I.stats = I.stats || {}; I.errors = (I.errors || []).filter(x => !/^Sync:/.test(x));
    return I;
  };

  /* ---- tienda: productos y ventas ---- */
  for (const T of got.tienda) {
    const I = integ(T.src.name, 'tienda'); let nuevos = 0;
    const base = 'https://' + T.src.id + '.estrategia.site/producto/';
    for (const x of ((T.cat && T.cat.productos) || [])) {
      const nm = norm(x.titulo); if (!nm) continue;
      let p = st.products.find(q => q.storeSlug === x.slug) || st.products.find(q => norm(q.name) === nm)
        || st.products.find(q => { const a = norm(q.name); return a.length >= 6 && nm.length >= 6 && (a.includes(nm) || nm.includes(a)); });
      if (p) { if (!p.storeSlug) R.prod_link++; p.storeSlug = x.slug; p.storeUrl = base + x.slug; p.storePk = x.pk; continue; }
      const fmt = { agendas: 'PDF', apps: 'App web', landings: 'HTML interactivo', micro: 'Microapp HTML descargable' }[x.tipo] || 'PDF';
      const usd = !(x.precio_ars > 0) && x.precio_usd > 0; const cur = ensureCur(usd ? 'USD' : 'ARS');
      st.products.push({ id: uid('pd'), createdAt: nowIso, name: x.titulo, brandId: brandFor(x.tipo), category: '', status: x.visible ? 'publicado' : 'borrador', launchDate: '', projectId: '', pendingUpdates: '', resources: [base + x.slug], bundle: [],
        versions: [{ id: uid('vr'), format: fmt, language: S.languages[0] || '', version: '1', price: round2(usd ? x.precio_usd : x.precio_ars), currency: cur, channels: chSitio ? [chSitio.id] : [] }],
        origin: 'integracion', storeSlug: x.slug, storeUrl: base + x.slug, storePk: x.pk, updatedAt: nowIso });
      R.prod_new++; nuevos++;
    }
    for (const o of ((T.ped && T.ped.pedidos) || [])) {
      if (o.estado !== 'paid') continue;
      const ext = T.src.id + ':pedido:' + o.id;
      const keep = { id: o.id, codigo: o.codigo, producto: o.producto, monto: o.monto, moneda: o.moneda, estado: o.estado, metodo: o.metodo, email: o.email, creado: o.creado, pagado: o.pagado, descargas: o.descargas, origen: o.origen, propio: o.propio, recorrido: o.recorrido, recorrido_estimado: o.recorrido_estimado };
      const ex = st.sales.find(x => x.externalId === ext);
      if (ex) { ex.store = keep; R.ventas_act++; continue; }
      const p = st.products.find(q => q.storePk === o.product_id) || st.products.find(q => norm(q.name) === norm(o.producto));
      const date = syncDay(o.pagado || o.creado) || todayStr(), cur = ensureCur(o.moneda || 'ARS');
      const id = createSale(st, { type: 'unica', date, collectedDate: date, productId: p ? p.id : '', versionId: p && p.versions && p.versions[0] ? p.versions[0].id : '', brandId: p ? p.brandId : brandFor(''), channelId: chSitio ? chSitio.id : '',
        customerId: custFor(o.email, '', date), currency: cur, qty: 1, unitPrice: o.monto, payStatus: 'cobrado', delivery: 'entregado', origin: 'integracion', source: T.src.id, externalId: ext, notes: 'Pedido #' + o.id + ' · ' + (o.metodo === 'pp' ? 'PayPal' : 'Mercado Pago') + (o.propio ? ' · hecho desde tu propio navegador' : '') });
      st.sales.find(x => x.id === id).store = keep; R.ventas++; nuevos++;
    }
    I.stats.imported = (I.stats.imported || 0) + nuevos;
  }

  /* ---- apps: cuentas → clientes + suscripciones (+ cobros) ---- */
  const MAP = { pago: 'activa', prueba: 'prueba', manual: 'activa', solo_lectura: 'pausada', bloqueado: 'cancelada' };
  for (const A of got.apps) {
    const al = SYNC_ALIAS[A.src.id] || [norm(A.src.id)];
    let pj = st.projects.find(p => p.syncId === A.src.id) || st.projects.find(p => al.some(a => norm(p.name).includes(a)));
    if (pj) pj.syncId = A.src.id;
    if (!pj) {
      pj = { id: uid('ap'), createdAt: nowIso, userStats: [], name: A.src.name, brandId: brandFor('apps'), status: 'publicada', kind: 'suscripcion', launchDate: '', url: 'https://' + A.src.id + '.estrategia.site', desc: '', domain: A.src.id + '.estrategia.site', repo: '', hosting: '', database: '', tech: '', milestone: '', initialInvestment: null, investCurrency: '', notes: 'Creada por la sincronización', origin: 'integracion', syncId: A.src.id };
      st.projects.push(pj); R.apps++;
    }
    const cus = A.customers || [];
    let pl = st.plans.find(x => x.projectId === pj.id);
    if (!pl && cus.length) {
      const mx = Math.max(0, ...cus.map(c => c.monto_cent || 0)) / 100, cur = ensureCur((cus.find(c => c.moneda) || {}).moneda || 'ARS');
      pl = { id: uid('pl'), projectId: pj.id, name: 'Suscripción', price: round2(mx), currency: cur, period: 'mensual', trialDays: 0 }; st.plans.push(pl);
    }
    const I = integ(A.src.name, 'app', pj.id); let nuevas = 0;
    if (!pl) { I.stats.cuentas = 0; continue; }
    for (const c of cus) {
      if (!c.email && !c.nombre) continue;
      const alta = syncDay(c.alta) || todayStr(), ext = A.src.id + ':cuenta:' + c.id;
      const status = MAP[c.categoria] || 'pausada', next = syncDay(c.vence);
      const cid = custFor(c.email, c.nombre, alta);
      let sub = st.subs.find(x => x.externalId === ext);
      if (!sub) {
        sub = { id: uid('sb'), projectId: pj.id, planId: pl.id, customerId: cid, startDate: alta, status, events: [], origin: 'integracion', externalId: ext, grace: c.categoria === 'manual' };
        sub.events.push({ id: uid('ev'), date: alta, type: c.categoria === 'prueba' ? 'prueba' : 'alta', planId: pl.id });
        if (next) sub.nextRenewal = next; st.subs.push(sub); R.subs++; nuevas++;
      } else if (sub.status !== status || (next && sub.nextRenewal !== next)) { sub.status = status; if (next) sub.nextRenewal = next; sub.grace = c.categoria === 'manual'; R.subs_act++; }
      sub.lastAccess = syncDay(c.ultimo_ingreso);
      if (c.categoria === 'pago' && c.monto_cent > 0 && c.ultimo_pago) {
        const ext2 = A.src.id + ':pago:' + c.id + ':' + syncDay(c.ultimo_pago);
        if (!st.sales.some(x => x.externalId === ext2)) {
          const d = syncDay(c.ultimo_pago), cur = ensureCur(c.moneda || 'ARS');
          const sid = createSale(st, { type: 'suscripcion', date: d, collectedDate: d, projectId: pj.id, planId: pl.id, subId: sub.id, brandId: pj.brandId, channelId: chApp ? chApp.id : '', customerId: cid, currency: cur, qty: 1, unitPrice: c.monto_cent / 100, payStatus: 'cobrado', delivery: 'no_aplica', origin: 'integracion', source: A.src.id, externalId: ext2 });
          sub.events.push({ id: uid('ev'), date: d, type: sub.events.some(e => e.type === 'alta' && e.amount) ? 'renovacion' : 'alta', planId: pl.id, amount: round2(c.monto_cent / 100), currency: cur, saleId: sid }); R.ventas++;
        }
      }
    }
    I.stats.imported = (I.stats.imported || 0) + nuevas; I.stats.cuentas = cus.length;
  }
  logAct(st, 'Sincronización', `${R.ventas} ventas, ${R.subs} suscripciones, ${R.clientes} clientes, ${R.prod_new} productos nuevos, ${R.prod_link} vinculados`);
  return R;
}

async function syncAll() {
  if (SYNC.running) return;
  while (typeof INICIO !== 'undefined' && INICIO.running) await new Promise(r => setTimeout(r, 700)); /* una cosa a la vez: el hosting comparte procesos */
  SYNC.running = true; SYNC.error = ''; syncRepaint();
  const got = { tienda: [], apps: [] }, fails = [];
  try {
    const pl = await inicioGet('api.php?action=overview_plan', 15000);
    if (!pl.d || !pl.d.ok) throw new Error(pl.status === 401 ? 'La sesión venció' : 'no pude leer la lista de apps');
    for (const p of pl.d.plan) {
      if (p.kind === 'tienda') {
        const cat = await syncFetch(p.id, 'catalogo'), ped = await syncFetch(p.id, 'pedidos');
        if (!cat && !ped) fails.push(p.name); else got.tienda.push({ src: p, cat, ped });
      } else {
        const c = await syncFetch(p.id, 'customers');
        if (c && Array.isArray(c.customers)) got.apps.push({ src: p, customers: c.customers }); else fails.push(p.name);
      }
    }
    const R = await transact('sync', st => syncApply(st, got));
    R.fallaron = fails; SYNC.report = R; SYNC.at = Date.now();
    try { localStorage.setItem('eco-sync', JSON.stringify({ at: SYNC.at, report: R })); } catch (e) { }
    try { if (typeof updateTicker === 'function') updateTicker(); } catch (e) { }
    if (typeof rerender === 'function' && ui.route !== 'inicio') rerender();
  } catch (e) { SYNC.error = (e && e.message) || 'falló la sincronización'; }
  finally { SYNC.running = false; syncRepaint(); }
}
function syncRepaint() { if (typeof inicioPaint === 'function' && ui.route === 'inicio') inicioPaint(); }
function syncAuto() { if (!SYNC.running && Date.now() - SYNC.at > 10 * 60000) syncAll(); }
ACT.syncAhora = () => syncAll();

/* ---------- recorrido de una compra (se usa en Inicio y en la ficha de la venta) ---------- */
function trailLabel(e) {
  const n = e.n ? '«' + esc(e.n) + '»' : '';
  switch (e.t) {
    case 'pv': return e.page === 'detalle' ? 'Abrió el producto ' + n : e.page === 'gracias' ? 'Llegó a la página de gracias' : e.page === 'legales' ? 'Leyó los legales' : 'Entró a la tienda';
    case 'tab': return 'Miró la sección «' + esc(e.n) + '»';
    case 'seen': return 'Vio ' + n + ' en la lista';
    case 'click': return 'Tocó ' + n;
    case 'demo': return 'Abrió la demo ' + n;
    case 'wa': return 'Tocó WhatsApp';
    case 'photos': return 'Miró las fotos ' + n;
    case 'buy': return '<b>Tocó Comprar</b> ' + n;
    case 'aviso': return 'Pidió aviso ' + n;
    default: return esc(e.t) + ' ' + n;
  }
}
function orderTrailHTML(o) {
  const hm = s => String(s || '').slice(11, 19), T = s => Date.parse(String(s || '').replace(' ', 'T'));
  const ev = (o.recorrido || []).map(e => `<li><time>${hm(e.ts)}</time><span>${trailLabel(e)}</span></li>`);
  const estado = o.estado === 'paid' ? '<b class="in-st ok">Pagado</b>' : o.estado === 'pending' ? '<b class="in-st wait">No terminó de pagar</b>' : '<b class="in-st bad">Falló / devuelto</b>';
  const steps = ev.concat([`<li><time>${hm(o.creado)}</time><span>Creó el pedido y pasó a ${o.metodo === 'pp' ? 'PayPal' : 'Mercado Pago'}</span></li>`], o.pagado ? [`<li class="ok"><time>${hm(o.pagado)}</time><span><b>Pagó</b> ${inCur(o.moneda, o.monto)}</span></li>`] : [`<li class="bad"><time>—</time><span>No se registró el pago${o.falla ? ': ' + esc(o.falla) : ' (quizás abandonó en la pantalla de pago)'}</span></li>`]);
  const nota = o.propio ? '<p class="in-note">Este pedido salió de <b>tu propio navegador</b> (el panel no cuenta tus visitas): casi seguro fue una prueba tuya.</p>'
    : !(o.recorrido || []).length ? '<p class="in-note">No hay recorrido guardado: el pedido es anterior a que la tienda empezara a registrarlo y no se pudo estimar.</p>'
      : o.recorrido_estimado ? '<p class="in-note">Recorrido <b>estimado</b> por horario (el pedido es anterior al registro exacto): puede no ser de esa persona.</p>' : '';
  return `<div class="in-ord-h">${estado}<span>${inCur(o.moneda, o.monto)} · ${esc(o.producto)}</span></div>
    <div class="in-kv"><div><span>Cliente</span><b>${esc(o.email)}</b></div><div><span>Medio</span><b>${o.metodo === 'pp' ? 'PayPal (dólares)' : 'Mercado Pago'}</b></div><div><span>Llegó desde</span><b>${esc(o.origen || '—')}</b></div><div><span>Pedido</span><b>#${o.id} · ${esc(o.creado)}</b></div>${o.pagado ? `<div><span>Pagado</span><b>${esc(o.pagado)}</b></div><div><span>Descargas</span><b>${o.descargas}</b></div>` : ''}</div>
    ${nota}<div class="in-sub" style="margin-top:12px">Recorrido paso a paso</div><ol class="in-trail">${steps.join('')}</ol>`;
}
ACT.syncVerPedido = el => {
  const o = (INICIO.data && INICIO.data.tienda && INICIO.data.tienda.pedidos_lista || []).find(x => String(x.id) === String(el.dataset.id));
  if (!o) return toast('Ese pedido todavía no se cargó', 'warn');
  openModal({ title: 'Pedido #' + o.id, size: 'md', body: orderTrailHTML(o) });
};
function saleTrailHTML(s) {
  if (!s.store) return '';
  return `<div class="eyebrow" style="margin:14px 0 6px">Recorrido de la compra · tienda</div>${orderTrailHTML(s.store)}`;
}
function productStoreHTML(p) {
  if (!p.storeSlug) return '';
  const T = INICIO && INICIO.data && INICIO.data.tienda && INICIO.data.tienda.ok ? INICIO.data.tienda : null;
  const x = T ? (T.productos || []).find(q => norm(q.nombre) === norm(p.name)) : null;
  return `<div class="eyebrow" style="margin:16px 0 6px">En la tienda</div><div class="kv"><div><span>Página</span><b><a href="${esc(p.storeUrl)}" target="_blank" rel="noopener">Ver en la tienda</a></b></div>${x ? `<div><span>Vistas (30 d)</span><b>${x.seen}</b></div><div><span>Abrieron detalle</span><b>${x.det}</b></div><div><span>Tocaron Comprar</span><b>${x.buy}</b></div><div><span>WhatsApp</span><b>${x.wa}</b></div>` : '<div><span>Visitas</span><b class="muted">abrí Inicio para verlas</b></div>'}</div>`;
}
