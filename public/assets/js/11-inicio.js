/* =====================================================================
   INICIO: lo importante de todas las apps y de la tienda, a la vista.
   Datos: api.php?action=overview (solo lectura, junta todo en un pedido).
   ===================================================================== */
let INICIO = { status: 'idle', data: null, at: 0, error: '' };
const inEsc = v => esc(v == null ? '' : v);
const inCur = (c, n) => { const s = { ARS: '$', USD: 'US$', EUR: '€' }[c] || (c ? c + ' ' : '$'); return s + ' ' + fnum(n); };
const inAgo = iso => {
  if (!iso) return 'sin datos';
  const t = Date.parse(String(iso).replace(' ', 'T')); if (!t) return String(iso);
  const m = Math.round((Date.now() - t) / 60000);
  if (m < 1) return 'recién'; if (m < 60) return 'hace ' + m + ' min';
  if (m < 1440) return 'hace ' + Math.round(m / 60) + ' h';
  const d = Math.round(m / 1440); return d === 1 ? 'ayer' : 'hace ' + d + ' días';
};

async function inicioGet(url, ms) {
  const ctl = new AbortController(), t = setTimeout(() => ctl.abort(), ms);
  try { const r = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: ctl.signal }); const d = await r.json().catch(() => null); return { status: r.status, d }; }
  catch (e) { return { status: 0, d: null, aborted: e && e.name === 'AbortError' }; }
  finally { clearTimeout(t); }
}
function inicioMerge() {
  const d = INICIO.data; if (!d) return;
  const all = d.apps.concat(d.tienda ? [d.tienda] : []);
  d.alertas = [];
  all.forEach(a => (a.alertas || []).forEach(x => d.alertas.push(Object.assign({}, x, { app: a.name, app_id: a.id }))));
  const o = { rojo: 0, amarillo: 1, info: 2 }; d.alertas.sort((x, y) => o[x.nivel] - o[y.nivel]);
  d.generado = new Date().toISOString();
}
let inicioTimer = 0, inicioTick = 0;
function inicioRepaint() { clearTimeout(inicioTimer); inicioTimer = setTimeout(() => { inicioMerge(); if (ui.route === 'inicio') inicioPaint(); }, 60); }
async function inicioLoad(force) {
  if (INICIO.running) return; /* una sola lectura a la vez: dos juntas traban el hosting */
  if (!force && INICIO.data && Date.now() - INICIO.at < 120000) return;
  INICIO.status = 'loading'; INICIO.running = true;
  try { await inicioRun(); } catch (e) { if (!INICIO.data) { INICIO.status = 'error'; INICIO.error = 'Algo falló al leer: ' + (e && e.message); if (ui.route === 'inicio') inicioPaint(); } } finally { INICIO.running = false; INICIO.cur = null; clearInterval(inicioTick); }
}
async function inicioRun() {
  const pl = await inicioGet('api.php?action=overview_plan', 15000);
  if (!pl.d || !pl.d.ok) { INICIO.status = 'error'; INICIO.error = pl.status === 401 ? 'La sesión venció. Volvé a entrar.' : 'No pude traer los datos ahora. Probá de nuevo en un momento.'; if (ui.route === 'inicio') inicioPaint(); return; }
  const mk = p => ({ id: p.id, name: p.name, kind: p.kind, conectar_id: p.conectar_id, origen: p.origen, ok: false, loading: true, alertas: [] });
  INICIO.data = { apps: pl.d.plan.filter(p => p.kind !== 'tienda').map(mk), tienda: (pl.d.plan.filter(p => p.kind === 'tienda').map(mk))[0] || null, alertas: [], sin_conectar: pl.d.sin_conectar || [], generado: new Date().toISOString() };
  INICIO.status = 'ok'; INICIO.at = Date.now(); inicioPaint();
  /* de a una: tus sitios comparten los procesos del hosting y pedirlas todas juntas las traba */
  clearInterval(inicioTick); inicioTick = setInterval(() => { const c = INICIO.cur; if (!c) return; document.querySelectorAll('[data-wait]').forEach(el => { el.textContent = inicioWait({ id: el.dataset.wait }); }); }, 1000);
  for (const p of pl.d.plan) {
    const t0 = Date.now(); INICIO.cur = { id: p.id, t0 };
    let r;
    try { r = await inicioGet('api.php?action=overview&id=' + encodeURIComponent(p.id), 30000); } catch (e) { r = { status: 0, d: null }; }
    const secs = Math.round((Date.now() - t0) / 100) / 10;
    const got = r.d && r.d.ok ? (r.d.tienda || (r.d.apps || [])[0]) : null;
    const why = r.aborted ? 'el panel no recibió respuesta en ' + secs + ' s' : (r.status === 0 ? 'sin conexión con el panel' : 'el panel respondió con error (HTTP ' + r.status + ')');
    const item = got || { id: p.id, name: p.name, kind: p.kind, conectar_id: p.conectar_id, origen: p.origen, ok: false, error: why, alertas: [{ nivel: 'rojo', texto: 'No pude leer los datos: ' + why }] };
    const D = INICIO.data;
    if (p.kind === 'tienda') D.tienda = item; else D.apps = D.apps.map(a => a.id === p.id ? item : a);
    inicioRepaint();
  }
  INICIO.cur = null; clearInterval(inicioTick); INICIO.at = Date.now(); inicioRepaint();
}
function inicioPaint() {
  const page = $('#view .page'); if (!page || ui.route !== 'inicio') return;
  try { page.innerHTML = VIEWS.inicio.render(); }
  catch (e) { /* si un dato raro rompe el dibujo, que se vea el motivo en pantalla en vez de quedar congelado */
    page.innerHTML = `<div class="card in-bad"><p class="in-err"><b>No pude dibujar el Inicio:</b> ${inEsc(e && e.message)}</p><button class="btn" data-act="inicioRefresh">Reintentar</button></div>`; }
}
function inicioCard(fn, a) {
  try { return fn(a); } catch (e) { return `<div class="card s4 in-app in-bad"><div class="in-app-h"><b>${inEsc(a && a.name)}</b><span class="in-dot rojo"></span></div><p class="in-err">No pude dibujar esta tarjeta: ${inEsc(e && e.message)}</p></div>`; }
}

function inicioInsights(d) {
  const T = d.tienda && d.tienda.ok ? d.tienda : null;
  const apps = (d.apps || []).filter(a => a.ok);
  const pasa = [], mejorar = [], vender = [];
  if (T) {
    const v = T.ventas || {}, e = T.embudo || {};
    pasa.push(`La tienda tuvo ${fnum(T.visitantes)} visitantes en ${T.dias} días (${fnum(T.visitantes_hoy)} hoy) y ${fnum(v.n)} venta${v.n === 1 ? '' : 's'}${v.por_moneda && v.por_moneda.length ? ' (' + v.por_moneda.map(x => inCur(x.moneda, x.monto)).join(' + ') + ')' : ''}.`);
    if (T.visitantes_prev > 0) { const p = Math.round((T.visitantes - T.visitantes_prev) / T.visitantes_prev * 100); pasa.push(`Las visitas ${p >= 0 ? 'subieron' : 'bajaron'} ${Math.abs(p)}% frente al período anterior.`); }
    if (e.vieron_producto > 0) pasa.push(`Embudo de la tienda: ${fnum(e.vieron_producto)} vieron un producto → ${fnum(e.abrieron_detalle)} abrieron el detalle → ${fnum(e.tocaron_comprar)} tocaron Comprar → ${fnum(e.compraron)} compraron.`);
    if (e.abrieron_detalle >= 10 && e.tocaron_comprar / e.abrieron_detalle < 0.1) mejorar.push('Mucha gente abre el detalle pero pocos tocan Comprar: revisá precio, fotos, explicación de lo que incluye y que el botón se vea al abrir.');
    if (e.vieron_producto >= 20 && e.abrieron_detalle / e.vieron_producto < 0.25) mejorar.push('Pocos pasan de ver un producto a abrir el detalle: probá mejores portadas/títulos en el inicio de la tienda.');
    if (e.tocaron_comprar >= 3 && e.compraron === 0) mejorar.push(`${e.tocaron_comprar} personas tocaron Comprar y nadie pagó: probá una compra real con Mercado Pago y PayPal para descartar fallas en el pago.`);
    const m = T.origen || [];
    if (m.length) pasa.push('De dónde llegan: ' + m.slice(0, 3).map(o => `${o.origen} (${o.sesiones})`).join(', ') + '.');
    const dv = T.dispositivos || {}; const td = (dv.celular || 0) + (dv.compu || 0);
    if (td >= 10 && dv.celular / td > 0.7) mejorar.push(`El ${Math.round(dv.celular / td * 100)}% entra desde el celular: revisá que la tienda y los pagos se vean perfectos en pantalla chica.`);
    const prods = (T.productos || []);
    const score = p => p.det * 3 + p.seen + p.wa * 4 + p.demo * 2 + p.buy * 6;
    const cand = prods.filter(p => p.sold === 0 && (p.buy + p.wa + p.demo) > 0).sort((a, b) => score(b) - score(a)).slice(0, 3);
    prods.filter(p => p.sold > 0).slice(0, 2).forEach(p => vender.push(`${p.nombre}: ya vendió ${p.sold}. Es la prueba más fuerte de que se vende, empujala en tus redes.`));
    cand.forEach(p => vender.push(`${p.nombre}: ${p.det} detalles, ${p.buy} clics en Comprar, ${p.wa} en WhatsApp${p.demo ? ', ' + p.demo + ' demos' : ''}. Hay interés real, todavía sin venta.`));
    prods.filter(p => p.det >= 5 && p.buy === 0 && p.wa === 0).slice(0, 2).forEach(p => mejorar.push(`${p.nombre}: la miran (${p.det} detalles) pero nadie compra ni escribe. Revisá su página.`));
    const tb = T.pestanas || {}; const tk = Object.entries(tb).sort((a, b) => b[1] - a[1]);
    if (tk.length && tk[0][1] > 0) pasa.push(`La sección más tocada de la tienda es "${tk[0][0]}" (${tk[0][1]} clics).`);
    if (!T.tiene_datos) mejorar.push('La tienda aún no registra visitas: verificá que el seguimiento (track.php) esté funcionando.');
  } else pasa.push('No pude leer la tienda (descargar): revisá la clave compartida.');
  apps.forEach(a => {
    const L = a.landing, C = a.clientes;
    pasa.push(`${a.name}: ${fnum(C.pagando)} cuenta${C.pagando === 1 ? '' : 's'} pagando, ${fnum(C.en_prueba)} en prueba, ${fnum(C.total)} registradas. Landing: ${fnum(L.visitas)} visitas, ${fnum(L.clicks_sub)} clics en suscribirme.`);
    if (!L.tiene_datos) mejorar.push(`${a.name}: la landing no está midiendo visitas, no se puede saber si funciona.`);
    else if (L.visitas >= 30 && L.conv_pct !== null && L.conv_pct < 2) mejorar.push(`${a.name}: de ${L.visitas} visitas solo ${L.clicks_sub} tocan Suscribirme (${L.conv_pct}%). Probá un título más claro, el precio más arriba y un botón más visible.`);
    if (L.clicks_sub >= 5 && a.iniciadas_30d === 0) mejorar.push(`${a.name}: hay clics en suscribirme pero ninguna suscripción iniciada: revisá el link de pago.`);
    if (C.en_prueba >= 3 && C.pagando === 0) mejorar.push(`${a.name}: ${C.en_prueba} personas en prueba y nadie paga todavía: escribiles antes de que venza la prueba.`);
    if (C.pagando > 0) vender.push(`${a.name}: ya tiene clientes que pagan (${C.pagando}); es tu suscripción con prueba de que funciona.`);
  });
  return { pasa, mejorar, vender };
}

function inicioReportText(d) {
  const I = inicioInsights(d), T = d.tienda && d.tienda.ok ? d.tienda : null;
  const L = [];
  L.push('INFORME DEL ECOSISTEMA · EstrategIA'); L.push('Generado: ' + new Date().toLocaleString('es-AR')); L.push('');
  L.push('1. QUÉ ESTÁ PASANDO'); I.pasa.forEach(x => L.push('- ' + x)); L.push('');
  L.push('2. ALERTAS Y PROBLEMAS'); (d.alertas.length ? d.alertas : [{ nivel: 'info', texto: 'Sin alertas.', app: '' }]).forEach(a => L.push(`- [${a.nivel.toUpperCase()}] ${a.app ? a.app + ': ' : ''}${a.texto}`)); L.push('');
  L.push('3. QUÉ MEJORAR EN LAS PÁGINAS'); (I.mejorar.length ? I.mejorar : ['Por ahora no hay datos suficientes para marcar algo puntual.']).forEach(x => L.push('- ' + x)); L.push('');
  L.push('4. QUÉ ES MÁS PROBABLE QUE SE VENDA'); (I.vender.length ? I.vender : ['Todavía no hay suficiente movimiento para decirlo.']).forEach(x => L.push('- ' + x)); L.push('');
  L.push('5. CUENTAS ACTIVAS POR APP');
  (d.apps || []).forEach(a => L.push(a.ok ? `- ${a.name}: ${a.clientes.pagando} pagando · ${a.clientes.en_prueba} en prueba · ${a.clientes.gracia} en gracia · ${a.clientes.solo_lectura} solo lectura · ${a.clientes.total} registradas` : `- ${a.name}: sin datos (${a.error})`));
  L.push('');
  if (T && T.productos && T.productos.length) {
    L.push('6. PRODUCTOS MÁS VISTOS DE LA TIENDA (' + T.dias + ' días)');
    T.productos.slice(0, 10).forEach((p, i) => L.push(`${i + 1}. ${p.nombre} — vistas ${p.seen} · detalle ${p.det} · comprar ${p.buy} · WhatsApp ${p.wa} · demo ${p.demo} · vendidos ${p.sold}`));
  }
  return L.join('\n');
}

function inicioSetupCard(d) {
  const pend = d.sin_conectar || [];
  if (!pend.length) return '';
  return `<div class="card in-setup"><div class="eyebrow">Conectar tus apps</div><h3>${pend.length === 1 ? 'Falta 1 app' : 'Faltan ' + pend.length + ' apps'} por conectar</h3>
    <p class="muted">Es fácil: en el admin de la app tocás <b>«Generar código de conexión»</b> y escribís ese código acá. Sin claves ni archivos.</p>
    <div class="btn-row">${pend.map(a => `<button class="btn btn-lens" data-act="conectarCodigo" data-app="${inEsc(a.id)}" data-name="${inEsc(a.name)}">Conectar ${inEsc(a.name)}</button>`).join('')}</div></div>`;
}
function inicioFoot(a) {
  const cid = a.conectar_id || ''; if (!cid) return '';
  return `<div class="in-foot"><button class="btn btn-sm btn-ghost" data-act="conectarCodigo" data-app="${inEsc(cid)}" data-name="${inEsc(a.name)}">${a.ok ? 'Renovar conexión' : 'Conectar de nuevo'}</button>${a.origen === 'tabla' ? `<button class="btn btn-sm btn-ghost" data-act="inicioQuitar" data-id="${inEsc(a.id)}" data-name="${inEsc(a.name)}">Desconectar</button>` : ''}</div>`;
}

function inicioWait(a) {
  const c = INICIO.cur; if (!c || c.id !== a.id) return 'en espera';
  return 'leyendo… ' + Math.floor((Date.now() - c.t0) / 1000) + ' s';
}
function inicioAppCard(a) {
  if (a.loading) return `<div class="card s4 in-app"><div class="in-app-h"><b>${inEsc(a.name)}</b><span class="tiny muted" data-wait="${inEsc(a.id)}">${inicioWait(a)}</span></div><div class="in-skel"></div></div>`;
  if (!a.ok) return `<div class="card s4 in-app in-bad"><div class="in-app-h"><b>${inEsc(a.name)}</b><span class="in-dot rojo"></span></div><p class="in-err">${inEsc(a.error)}</p>${inicioFoot(a)}</div>`;
  const C = a.clientes, L = a.landing, worst = (a.alertas.find(x => x.nivel === 'rojo') ? 'rojo' : a.alertas.find(x => x.nivel === 'amarillo') ? 'amarillo' : 'ok');
  const rec = a.recientes.slice(0, 4).map(c => `<li><span>${inEsc(c.nombre || c.email)}</span><em>${c.categoria ? inEsc(c.categoria) + ' · ' : ''}${inAgo(c.ultimo_ingreso || c.alta)}</em></li>`).join('');
  return `<div class="card s4 in-app"><div class="in-app-h"><b>${inEsc(a.name)}</b><span class="in-dot ${worst}" title="${worst === 'ok' ? 'Todo bien' : 'Hay alertas'}"></span></div>
    <div class="in-big"><div><b>${fnum(C.pagando)}</b><span>pagando</span></div><div><b>${fnum(C.en_prueba)}</b><span>en prueba</span></div><div><b>${fnum(C.total)}</b><span>cuentas</span></div></div>
    <div class="in-rows"><div><span>Visitas landing (30d)</span><b>${fnum(L.visitas)}</b></div><div><span>Clics en suscribirme</span><b>${fnum(L.clicks_sub)}${L.conv_pct !== null ? ` <i>${L.conv_pct}%</i>` : ''}</b></div><div><span>Suscripciones iniciadas</span><b>${fnum(a.iniciadas_30d)}</b></div></div>
    ${L.tiene_datos ? '' : '<p class="in-note">La landing aún no mide visitas.</p>'}
    ${rec ? `<div class="in-sub">Últimos en entrar</div><ul class="in-list">${rec}</ul>` : ''}
    ${a.alertas.length ? `<div class="in-al">${a.alertas.slice(0, 3).map(x => `<div class="in-pill ${x.nivel}">${inEsc(x.texto)}</div>`).join('')}</div>` : ''}${inicioFoot(a)}</div>`;
}

function inicioTienda(T) {
  if (!T) return '';
  if (T.loading) return `<div class="card s12 in-app"><div class="in-app-h"><b>${inEsc(T.name)}</b><span class="tiny muted" data-wait="${inEsc(T.id)}">${inicioWait(T)}</span></div><div class="in-skel"></div></div>`;
  if (!T.ok) return `<div class="card s12 in-app in-bad"><div class="in-app-h"><b>${inEsc(T.name)}</b><span class="in-dot rojo"></span></div><p class="in-err">${inEsc(T.error)}</p>${inicioFoot(T)}</div>`;
  const e = T.embudo || {}, mx = Math.max(1, e.vieron_producto || 0, e.abrieron_detalle || 0, e.tocaron_comprar || 0, e.compraron || 0);
  const fr = (l, v) => `<div class="in-f"><span>${l}</span><i style="--w:${Math.max(2, Math.round(v / mx * 100))}%"></i><b>${fnum(v)}</b></div>`;
  const prods = (T.productos || []).slice(0, 8);
  const pmx = Math.max(1, ...prods.map(p => p.det * 3 + p.seen));
  return `<div class="card s8 in-tienda"><div class="card-h"><h3>Productos más vistos · tienda</h3><span class="tiny muted">últimos ${T.dias} días</span></div>
    ${prods.length ? `<div class="in-tbl"><div class="in-th"><span>Producto</span><span>Vistas</span><span>Detalle</span><span>Comprar</span><span>WhatsApp</span><span>Vendidos</span></div>${prods.map(p => `<div class="in-tr"><span class="in-pn"><i style="--w:${Math.round((p.det * 3 + p.seen) / pmx * 100)}%"></i><u>${inEsc(p.nombre)}</u></span><span>${p.seen}</span><span>${p.det}</span><span>${p.buy}</span><span>${p.wa}</span><span><b>${p.sold}</b></span></div>`).join('')}</div>` : '<p class="muted">Todavía no hay vistas de productos en este período.</p>'}</div>
    <div class="card s4"><div class="card-h"><h3>Embudo de la tienda</h3></div>${fr('Vieron un producto', e.vieron_producto || 0)}${fr('Abrieron el detalle', e.abrieron_detalle || 0)}${fr('Tocaron Comprar', e.tocaron_comprar || 0)}${fr('Compraron', e.compraron || 0)}
    <div class="in-sub" style="margin-top:14px">Pedidos</div><div class="in-rows"><div><span>Pagados</span><b>${fnum(T.pedidos.pagados)}</b></div><div><span>Sin pagar</span><b>${fnum(T.pedidos.pendientes)}</b></div><div><span>Fallidos / devueltos</span><b>${fnum(T.pedidos.fallidos)}</b></div></div></div>
    <div class="card s6"><div class="card-h"><h3>De dónde llegan</h3></div>${(T.origen || []).length ? (T.origen || []).map(o => `<div class="in-f"><span>${inEsc(o.origen)}</span><i style="--w:${Math.max(3, Math.round(o.sesiones / Math.max(1, T.origen[0].sesiones) * 100))}%"></i><b>${fnum(o.sesiones)}</b></div>`).join('') : '<p class="muted">Sin datos todavía.</p>'}</div>
    <div class="card s6"><div class="card-h"><h3>Últimos pedidos</h3></div>${(T.ultimos_pedidos || []).length ? `<ul class="in-list">${T.ultimos_pedidos.slice(0, 6).map(o => `<li><span>${inEsc(o.producto)}</span><em><b class="in-st ${o.estado === 'paid' ? 'ok' : o.estado === 'pending' ? 'wait' : 'bad'}">${o.estado === 'paid' ? 'Pagado' : o.estado === 'pending' ? 'Sin pagar' : 'Falló'}</b> ${inCur(o.moneda, o.monto)} · ${inAgo(o.fecha)}</em></li>`).join('')}</ul>` : '<p class="muted">Todavía no hay pedidos.</p>'}</div>`;
}

VIEWS.inicio = {
  render() {
    const d = INICIO.data, T = d && d.tienda && d.tienda.ok ? d.tienda : null;
    const head = pageHead('Inicio', 'Todo tu ecosistema, de un vistazo', d ? `Actualizado ${inAgo(d.generado)}. Datos reales de tu tienda y de cada app.` : 'Trayendo los datos de tus apps…',
      `<button class="btn" data-act="inicioRefresh">${ic('swap')} Actualizar</button><button class="btn btn-lens" data-act="inicioInforme" ${d ? '' : 'disabled'}>${ic('reportes')} Informe</button>`);
    if (!d) {
      if (INICIO.status === 'error') return head + `<div class="card"><p class="in-err">${inEsc(INICIO.error)}</p><button class="btn" data-act="inicioRefresh">Reintentar</button></div>`;
      return head + `<div class="bento">${'<div class="card s3 in-skel"></div>'.repeat(4)}</div>`;
    }
    const apps = d.apps || [];
    const pag = apps.reduce((s, a) => s + (a.ok ? a.clientes.pagando : 0), 0), prueba = apps.reduce((s, a) => s + (a.ok ? a.clientes.en_prueba : 0), 0);
    const rojas = d.alertas.filter(a => a.nivel === 'rojo').length, amar = d.alertas.filter(a => a.nivel === 'amarillo').length;
    const cargando = apps.some(a => a.loading) || (d.tienda && d.tienda.loading);
    const estado = cargando ? `<div class="in-status"><b>Leyendo tus apps…</b><span>Se van completando una por una, puede tardar unos segundos.</span></div>` : rojas ? `<div class="in-status rojo"><b>${rojas} problema${rojas === 1 ? '' : 's'} para resolver</b><span>${inEsc(d.alertas.find(a => a.nivel === 'rojo').app)}: ${inEsc(d.alertas.find(a => a.nivel === 'rojo').texto)}</span></div>`
      : amar ? `<div class="in-status amarillo"><b>${amar} aviso${amar === 1 ? '' : 's'} para revisar</b><span>Nada urgente, pero conviene mirarlo.</span></div>`
        : `<div class="in-status ok"><b>Todo en orden</b><span>No hay alertas en tus apps ni en la tienda.</span></div>`;
    const kpi = (cls, lbl, val, sub) => `<div class="card ${cls} in-kpi"><div class="lbl">${lbl}</div><div class="val">${val}</div><div class="sub">${sub || ''}</div></div>`;
    const v = T ? T.ventas : null, vd = T && T.visitantes_prev > 0 ? Math.round((T.visitantes - T.visitantes_prev) / T.visitantes_prev * 100) : null;
    let h = head + inicioSetupCard(d) + estado + '<div class="bento in-top">'
      + kpi('s3', 'Ventas · tienda', T ? fnum(v.n) : '—', T ? (v.por_moneda.length ? v.por_moneda.map(x => inCur(x.moneda, x.monto)).join(' + ') : 'sin ventas todavía') + ` · ${T.dias} días` : 'tienda sin conexión')
      + kpi('s3', 'Visitas a la tienda', T ? fnum(T.visitantes) : '—', T ? `${fnum(T.visitantes_hoy)} hoy` + (vd !== null ? ` · <span class="${vd >= 0 ? 'pos' : 'neg'}">${vd >= 0 ? '▲' : '▼'} ${Math.abs(vd)}%</span>` : '') : '')
      + kpi('s3', 'Suscripciones activas', fnum(pag), `${fnum(prueba)} en prueba · ${apps.length} apps`)
      + kpi('s3', 'Conversión tienda', T && T.conv_pct !== null ? T.conv_pct + '%' : '—', T ? `${fnum(T.sesiones)} sesiones` : '')
      + '</div>';
    if (d.alertas.length) h += `<div class="card in-alerts"><div class="card-h"><h3>Alertas</h3><span class="tiny muted">${d.alertas.length}</span></div>${d.alertas.slice(0, 10).map(a => `<div class="in-alert ${a.nivel}"><i></i><span><b>${inEsc(a.app)}</b> ${inEsc(a.texto)}</span></div>`).join('')}</div>`;
    h += '<div class="in-sec">Tus apps</div><div class="bento">' + apps.map(a => inicioCard(inicioAppCard, a)).join('') + '</div>';
    h += '<div class="in-sec">Tienda · descargar</div><div class="bento">' + inicioCard(inicioTienda, d.tienda) + '</div>';
    return h;
  },
  after() { inicioLoad(false); }
};
ACT.inicioRefresh = () => inicioLoad(true);
ACT.inicioInforme = () => {
  if (!INICIO.data) return;
  if (INICIO.data.apps.concat(INICIO.data.tienda ? [INICIO.data.tienda] : []).some(a => a.loading)) return toast('Esperá a que terminen de cargar las apps', 'warn');
  const txt = inicioReportText(INICIO.data);
  const m = openModal({ title: 'Informe del ecosistema', size: 'lg', body: `<pre class="in-report" id="inReport">${inEsc(txt)}</pre>`,
    footer: `<button class="btn" data-in-copy>Copiar</button><button class="btn btn-lens" data-in-print>Imprimir / PDF</button>` });
  m.el.querySelector('[data-in-copy]').onclick = async () => { try { await navigator.clipboard.writeText(txt); toast('Informe copiado'); } catch (e) { toast('No pude copiar', 'warn'); } };
  m.el.querySelector('[data-in-print]').onclick = () => { const w = window.open('', '_blank'); if (!w) return toast('Permití ventanas emergentes para imprimir', 'warn'); w.document.write('<pre style="font:14px/1.6 system-ui;white-space:pre-wrap;padding:24px">' + inEsc(txt) + '</pre>'); w.document.close(); w.print(); };
};

const INICIO_ERR = { codigo_incorrecto: 'El código no es correcto o ya venció. Generá uno nuevo en la app.', demasiados_intentos: 'Demasiados intentos. Esperá unos minutos.', app_sin_conexion_por_codigo: 'Esa app todavía no tiene la conexión por código (falta actualizarla).', app_no_responde: 'La app no respondió. Probá de nuevo en un momento.', db_no_disponible: 'El panel no pudo guardar la conexión.', session_expired: 'La sesión venció. Volvé a entrar.' };
ACT.conectarCodigo = el => {
  const app = el.dataset.app, name = el.dataset.name || app;
  const m = openModal({ title: 'Conectar ' + esc(name), size: 'sm', body: `<ol class="in-steps"><li>Entrá al admin de <b>${esc(name)}</b>.</li><li>Tocá <b>«Generar código de conexión»</b> (en Sistema / Ajustes).</li><li>Escribí acá los 8 números:</li></ol>
    <input id="inCode" class="in-code" inputmode="numeric" autocomplete="off" maxlength="9" placeholder="0000 0000"><p class="in-err" id="inCodeErr" role="alert"></p>`,
    footer: `<button class="btn btn-lens" id="inCodeGo">Conectar</button>` });
  const inp = m.el.querySelector('#inCode'), err = m.el.querySelector('#inCodeErr'), go = m.el.querySelector('#inCodeGo');
  inp.focus();
  inp.oninput = () => { const v = inp.value.replace(/\D+/g, '').slice(0, 8); inp.value = v.length > 4 ? v.slice(0, 4) + ' ' + v.slice(4) : v; };
  inp.onkeydown = e => { if (e.key === 'Enter') go.click(); };
  go.onclick = async () => {
    const code = inp.value.replace(/\D+/g, ''); err.textContent = '';
    if (code.length !== 8) { err.textContent = 'Son 8 números.'; return; }
    go.disabled = true; go.textContent = 'Conectando…';
    const r = await apiPost('conectar_app', { app, codigo: code });
    if (r.error) { err.textContent = INICIO_ERR[r.error] || 'No se pudo conectar (' + r.error + ').'; go.disabled = false; go.textContent = 'Conectar'; return; }
    m.close(); toast(name + ' conectada'); inicioLoad(true);
  };
};
ACT.inicioQuitar = async el => {
  if (!(await confirmDialog({ title: 'Desconectar ' + (el.dataset.name || ''), message: 'El panel deja de leer esta app. Se puede volver a conectar cuando quieras.', ok: 'Desconectar', tone: 'danger' }))) return;
  const r = await apiPost('fuentes_quitar', { id: el.dataset.id });
  if (r.error) return toast('No se pudo desconectar', 'bad');
  toast('Desconectada'); inicioLoad(true);
};
