function currentEnv() { try { return localStorage.getItem('eco-env') || 'produccion'; } catch (e) { return 'produccion'; } }
const DataStore = {
  env: currentEnv(),
  get label() { return 'este navegador · ' + (this.env === 'pruebas' ? 'ambiente de PRUEBAS' : 'producción'); },
  DB: 'centro-operaciones', STORE: 'kv',
  get KEY() { return 'estado-' + this.env; },
  _db: null,
  _remote: null, _remoteVersion: 0, _csrf: '',
  async _remoteEnabled() {
    if (this._remote !== null) return this._remote;
    try {
      const r = await fetch('api.php?action=session', { credentials: 'same-origin', cache: 'no-store' });
      const data = await r.json();
      this._csrf = data.csrf || '';
      this._remote = r.ok && data.authenticated && data.business_storage === 'sql';
    } catch (_) { this._remote = false; }
    return this._remote;
  },
  _open() { return new Promise((res, rej) => { if (!('indexedDB' in window)) return rej(new Error('Sin IndexedDB')); const r = indexedDB.open(this.DB, 1); r.onupgradeneeded = () => r.result.createObjectStore(this.STORE); r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error); }); },
  async load() {
    if (await this._remoteEnabled()) {
      const r = await fetch('api.php?action=business_state&environment=' + encodeURIComponent(this.env), { credentials: 'same-origin', cache: 'no-store' });
      const data = await r.json();
      if (!r.ok || !data.ok) throw new Error(data.code || 'No se pudo leer MariaDB');
      if (data.found) { this._remoteVersion = data.version; return data.state; }
    }
    try { const db = this._db || (this._db = await this._open()); const v = await new Promise((res, rej) => { const q = db.transaction(this.STORE, 'readonly').objectStore(this.STORE).get(this.KEY); q.onsuccess = () => res(q.result || null); q.onerror = () => rej(q.error); }); if (v) return v; } catch (e) { console.warn(e); }
    try { const s = localStorage.getItem('co-' + this.KEY); return s ? JSON.parse(s) : null; } catch (e) { return null; }
  },
  async save(data) {
    if (await this._remoteEnabled()) {
      if (!this._csrf) { const s = await fetch('api.php?action=session', { credentials: 'same-origin', cache: 'no-store' }); const d = await s.json(); this._csrf = d.csrf || ''; }
      const body = { environment: this.env, operation_id: 'state_' + uid('op'), expected_version: this._remoteVersion, state: data };
      const r = await fetch('api.php?action=business_state', { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this._csrf }, body: JSON.stringify(body) });
      const result = await r.json();
      if (!r.ok || !result.ok) { const e = new Error(result.code || 'No se pudo guardar en MariaDB'); e.userMsg = result.code === 'version_conflict' ? 'Los datos cambiaron en otra sesión. Recargá antes de guardar.' : 'No se pudo guardar en MariaDB.'; throw e; }
      this._remoteVersion = result.version;
      return true;
    }
    try { const db = this._db || (this._db = await this._open()); await new Promise((res, rej) => { const tx = db.transaction(this.STORE, 'readwrite'); tx.objectStore(this.STORE).put(data, this.KEY); tx.oncomplete = res; tx.onerror = () => rej(tx.error); tx.onabort = () => rej(tx.error); }); return true; }
    catch (e) { localStorage.setItem('co-' + this.KEY, JSON.stringify(data)); return true; }
  }
};

/* =====================================================================
   ESTADO
   ===================================================================== */
function emptyState() {
  return {
    version: 1, setupDone: false, createdAt: new Date().toISOString(),
    settings: {
      business: { name: '', owner: '', site: '' }, baseCurrency: '', numFormat: 'es',
      currencies: [], brands: [], channels: [], categories: [], formats: [], languages: [], expenseGroups: [], payMethods: [], accounts: [],
      alerts: { upcomingDays: 14, noSalesDays: 30, dropPct: 30 }, hourly: { rate: null, currency: '' }, pin: ''
    },
    rates: [], projects: [], plans: [], products: [], customers: [], sales: [], subs: [], expenses: [], resources: [], suppliers: [], events: [], treasury: [], integrations: [], review: [], timeLogs: [], log: []
  };
}
/* Datos tomados de la descripción del negocio (se cargan solo si el usuario lo elige) */
function seedFromDescription(st) {
  const S = st.settings;
  S.brands = [
    { id: 'br_estrategia', name: 'EstrategIA', color: '#00D9FF', dark: '#0A0E27', alt: '#FF00FF', desc: 'Tecnología, productividad y soluciones digitales' },
    { id: 'br_yoyir', name: "YOYI'R", color: '#8B5A8E', dark: '#8B5A8E', alt: '#D9A5C4', desc: 'Organización, bienestar y planificación' },
    { id: 'br_ebooks', name: 'Ebooks para la vida', color: '#31595A', dark: '#31595A', alt: '#C9756B', desc: 'Educación y recursos editoriales' }
  ];
  S.channels = ['Sitio web propio', 'Etsy', 'Amazon', 'Apple Books', 'Aplicaciones web independientes'].map(n => ({ id: uid('ch'), name: n, commission: null }));
  const cat = (b, arr) => arr.forEach(n => S.categories.push({ brandId: b, name: n }));
  cat('br_ebooks', ['Vida y bienestar', 'Aprender y dominar', 'Dinero inteligente', 'Trabajo y carrera', 'Creatividad y pasiones']);
  cat('br_yoyir', ['Agendas digitales', 'Planificadores', 'PDF interactivos', 'Recursos para tablet', 'Organización personal', 'Planificadores financieros', 'Estudio, hábitos y bienestar', 'Paquetes digitales']);
  cat('br_estrategia', ['Microaplicaciones HTML', 'Herramientas para empresas', 'Conciliación y facturación', 'Análisis de Excel y CSV', 'Finanzas, ventas e inventario', 'Herramientas para freelancers', 'Productividad', 'Automatización']);
  S.formats = ['PDF', 'PDF interactivo', 'EPUB', 'HTML interactivo', 'ZIP', 'App web', 'Microapp HTML descargable'];
  S.expenseGroups = [
    { name: 'Infraestructura tecnológica', items: ['Hosting', 'Servidores', 'Dominios', 'Bases de datos', 'Almacenamiento', 'Copias de seguridad', 'Certificados / servicios técnicos', 'Correo transaccional', 'Servicios externos y APIs'] },
    { name: 'Desarrollo y producción', items: ['Herramientas de programación', 'Herramientas de IA', 'Software de diseño', 'Servicios de edición', 'Recursos gráficos', 'Herramientas de pruebas', 'Servicios técnicos externos'] },
    { name: 'Comercialización', items: ['Publicidad Google Ads', 'Publicidad Meta', 'Publicidad otras plataformas', 'Comisiones de marketplaces', 'Comisiones de pasarelas de pago', 'Promociones y campañas', 'Adquisición de clientes'] },
    { name: 'Administración', items: ['Servicios contables', 'Impuestos', 'Comisiones bancarias', 'Otros gastos administrativos'] }
  ];
  if (!S.currencies.some(c => c.code === 'ARS')) S.currencies.push({ code: 'ARS', symbol: '$' });
  if (!S.currencies.some(c => c.code === 'USD')) S.currencies.push({ code: 'USD', symbol: 'US$' });
  st.integrations = [{ id: uid('in'), name: 'estrategia.site (tienda principal)', kind: 'tienda', method: 'manual', status: 'sin_conectar', projectId: '', lastSync: '', errors: [], notes: 'Flask + SQLAlchemy · MySQL/MariaDB en Hostinger. Preparada para importar pedidos, pagos y suscripciones.' }];
}
let state = emptyState();
const ui = loadUI();
function loadUI() { let u = { route: 'pulso', theme: null, lens: 'all', period: { preset: 'mes' }, tabs: {}, filters: {} }; try { const s = JSON.parse(localStorage.getItem('eco-ui') || 'null'); if (s) u = Object.assign(u, s); } catch (e) { } return u; }
function saveUI() { try { localStorage.setItem('eco-ui', JSON.stringify({ theme: ui.theme, lens: ui.lens, period: ui.period, tabs: ui.tabs, filters: ui.filters })); } catch (e) { } }

let busy = false;
async function transact(desc, fn) {
  if (busy) { toast('Hay una operación guardándose, esperá un instante', 'bad'); throw new Error('busy'); }
  busy = true; const snap = clone(state);
  try { const r = fn(state); await DataStore.save(state); return r; }
  catch (e) { state = snap; console.error(e); toast(e && e.userMsg ? e.userMsg : 'No se pudo guardar: ' + (e.message || e), 'bad'); throw e; }
  finally { busy = false; }
}
function UErr(m) { const e = new Error(m); e.userMsg = m; return e; }
function logAct(st, action, detail) { st.log.push({ id: uid('lg'), at: new Date().toISOString(), action, detail }); if (st.log.length > 6000) st.log.splice(0, st.log.length - 6000); }

/* ---------- lookups ---------- */
const byId = (arr, id) => arr.find(x => x.id === id);
const brand = id => byId(state.settings.brands, id);
const proj = id => byId(state.projects, id);
const product = id => byId(state.products, id);
const plan = id => byId(state.plans, id);
const cust = id => byId(state.customers, id);
const channel = id => byId(state.settings.channels, id);
const supplier = id => byId(state.suppliers, id);
const account = id => byId(state.settings.accounts, id);
const brandColor = id => brand(id)?.color || 'var(--c8)';
const brandChip = id => { const b = brand(id); return b ? `<span class="brand-chip"><i style="background:${esc(b.color)}"></i>${esc(b.name)}</span>` : '<span class="muted small">Sin marca</span>'; };
const versionOf = (p, vid) => p?.versions?.find(v => v.id === vid);
const versionLabel = v => v ? [v.format, v.language, v.version ? 'v' + v.version : ''].filter(Boolean).join(' · ') : '';

/* =====================================================================
   MONEDAS Y TIPOS DE CAMBIO
   ===================================================================== */
const base = () => state.settings.baseCurrency;
const curSym = code => state.settings.currencies.find(c => c.code === code)?.symbol || code || '';
function locale() { return state.settings.numFormat === 'en' ? 'en-US' : 'es-AR'; }
function fmtN(n, d = 2) { return Math.abs(Number(n) || 0).toLocaleString(locale(), { minimumFractionDigits: d, maximumFractionDigits: d }); }
function money(n, cur = base()) { return (Number(n) < 0 ? '−' : '') + (curSym(cur) ? curSym(cur) + ' ' : '') + fmtN(n); }
function moneyShort(n, cur = base()) {
  const a = Math.abs(n), sym = curSym(cur); let s;
  if (a >= 1e9) s = (a / 1e9).toLocaleString(locale(), { maximumFractionDigits: 1 }) + 'B';
  else if (a >= 1e6) s = (a / 1e6).toLocaleString(locale(), { maximumFractionDigits: 1 }) + 'M';
  else if (a >= 1e4) s = (a / 1e3).toLocaleString(locale(), { maximumFractionDigits: 0 }) + 'k';
  else s = a.toLocaleString(locale(), { maximumFractionDigits: a < 10 ? 2 : 0 });
  return (n < 0 ? '−' : '') + (sym ? sym + ' ' : '') + s;
}
const fnum = (n, d = 0) => (Number(n) || 0).toLocaleString(locale(), { maximumFractionDigits: d });
const pct = n => (Number(n) || 0).toLocaleString(locale(), { maximumFractionDigits: 1 }) + '%';
/* Tipo de cambio: 1 unidad de `cur` = rate unidades de la moneda de referencia. Usa el último ≤ fecha. */
function rateFor(cur, date) {
  if (!cur || cur === base()) return { rate: 1, date, source: 'misma moneda' };
  let best = null;
  for (const r of state.rates) if (r.currency === cur && r.date <= date && (!best || r.date > best.date)) best = r;
  return best;
}
/* Conversión explícita; si no hay tipo de cambio devuelve null (nunca se mezcla sin convertir) */
function conv(amount, cur, date, miss) {
  const r = rateFor(cur, date || todayStr());
  if (!r) { if (miss) miss.add(cur + '@' + (date || '')); return null; }
  return (Number(amount) || 0) * r.rate;
}

/* =====================================================================
   PERÍODOS
   ===================================================================== */
const PRESETS = [['hoy', 'Hoy'], ['semana', 'Últimos 7 días'], ['mes', 'Este mes'], ['mesAnt', 'Mes anterior'], ['trimestre', 'Este trimestre'], ['anio', 'Este año'], ['12m', 'Últimos 12 meses'], ['todo', 'Todo'], ['custom', 'Personalizado']];
function resolveRange(p = ui.period) {
  const t = todayStr(), d = new Date();
  switch (p.preset) {
    case 'hoy': return { from: t, to: t };
    case 'semana': return { from: addDays(t, -6), to: t };
    case 'mes': return { from: fmtDate(new Date(d.getFullYear(), d.getMonth(), 1)), to: t };
    case 'mesAnt': return { from: fmtDate(new Date(d.getFullYear(), d.getMonth() - 1, 1)), to: fmtDate(new Date(d.getFullYear(), d.getMonth(), 0)) };
    case 'trimestre': return { from: fmtDate(new Date(d.getFullYear(), Math.floor(d.getMonth() / 3) * 3, 1)), to: t };
    case 'anio': return { from: `${d.getFullYear()}-01-01`, to: t };
    case '12m': return { from: addDays(addMonths(t, -12), 1), to: t };
    case 'todo': { const all = [...state.sales.map(s => s.date), ...state.expenses.map(e => e.date)].filter(Boolean).sort(); return { from: all[0] && all[0] < t ? all[0] : t, to: t }; }
    case 'custom': return { from: p.from || addDays(t, -29), to: p.to || t };
    default: return { from: addDays(t, -29), to: t };
  }
}
function prevRange(r) { const len = daysBetween(r.from, r.to) + 1; return { from: addDays(r.from, -len), to: addDays(r.from, -1) }; }
const inR = (d, r) => d && d >= r.from && d <= r.to;
function periodLabel() { const p = PRESETS.find(x => x[0] === ui.period.preset); return p ? p[1] : 'Período'; }

/* =====================================================================
   VENTAS: importes
   ===================================================================== */
function saleCalc(s) {
  const gross = round2((num(s.qty) || 0) * (num(s.unitPrice) || 0));
  const discount = round2(Math.min(gross, Math.max(0, num(s.discount) || 0)));
  const refunded = round2(Math.min(gross - discount, Math.max(0, num(s.refunded) || 0)));
  const net = round2(gross - discount - refunded);
  const commission = round2(Math.max(0, num(s.commission) || 0));
  const tax = round2(Math.max(0, num(s.tax) || 0));
  return { gross, discount, refunded, net, commission, tax, liquid: round2(net - commission - tax) };
}
const saleBrand = s => s.brandId || product(s.productId)?.brandId || proj(s.projectId)?.brandId || '';
const saleProject = s => s.projectId || product(s.productId)?.projectId || '';
const lensOk = brandId => ui.lens === 'all' || brandId === ui.lens;

/* =====================================================================
   GASTOS: estado y asignación (un gasto se cuenta UNA vez en el total)
   ===================================================================== */
const expStatus = e => e.status === 'pagado' ? 'pagado' : (e.dueDate && e.dueDate < todayStr() ? 'vencido' : 'pendiente');
function allocShares(e) {
  const a = e.alloc || { mode: 'general', targets: [] }, t = (a.targets || []).filter(x => x.id);
  if (a.mode === 'general' || !t.length) return [];
  if (a.mode === 'pct') return t.map(x => ({ type: x.type, id: x.id, share: (Number(x.pct) || 0) / 100 }));
  return t.map(x => ({ type: x.type, id: x.id, share: 1 / t.length }));
}
const shareBrand = sh => sh.type === 'brand' ? sh.id : proj(sh.id)?.brandId || '';
/* parte del gasto que corresponde a la lente actual (1 = todo) */
function expLensShare(e) {
  if (ui.lens === 'all') return 1;
  return allocShares(e).filter(sh => shareBrand(sh) === ui.lens).reduce((a, sh) => a + sh.share, 0);
}
const expProjectShare = (e, pid) => allocShares(e).filter(sh => sh.type === 'project' && sh.id === pid).reduce((a, sh) => a + sh.share, 0);
const expGroup = e => String(e.category || '').split(' › ')[0] || 'Sin categoría';

/* =====================================================================
   MÉTRICAS DEL PERÍODO (con lente de marca)
   ===================================================================== */
function metrics(r) {
  const miss = new Set();
  const m = { gross: 0, discount: 0, refunded: 0, net: 0, commission: 0, tax: 0, liquid: 0, collected: 0, expenses: 0, invest: 0, result: 0, salesCount: 0, units: 0, recurring: 0, oneTime: 0, unassigned: 0,
    byBrand: {}, byProduct: {}, byChannel: {}, byCountry: {}, byProject: {}, byDay: {}, expByGroup: {}, expByDay: {}, commByChannel: {}, customersNew: 0, customersRec: 0, miss };
  const firstBuy = {};
  for (const s of state.sales) if (s.status !== 'anulada' && s.customerId) { if (!firstBuy[s.customerId] || s.date < firstBuy[s.customerId]) firstBuy[s.customerId] = s.date; }
  const buyers = new Set();
  for (const s of state.sales) {
    if (s.status === 'anulada' || !inR(s.date, r)) continue;
    const b = saleBrand(s); if (!lensOk(b)) continue;
    const c = saleCalc(s), k = x => conv(x, s.currency, s.date, miss);
    const g = k(c.gross); if (g == null) continue;
    const net = k(c.net), com = k(c.commission), tax = k(c.tax);
    m.gross += g; m.discount += k(c.discount); m.refunded += k(c.refunded); m.net += net; m.commission += com; m.tax += tax; m.liquid += net - com - tax;
    m.salesCount++; m.units += num(s.qty) || 0;
    if (s.type === 'suscripcion') m.recurring += net; else m.oneTime += net;
    m.byBrand[b || '_'] = (m.byBrand[b || '_'] || 0) + net;
    if (s.productId) { const x = m.byProduct[s.productId] || (m.byProduct[s.productId] = { net: 0, units: 0 }); x.net += net; x.units += num(s.qty) || 0; }
    const ch = s.channelId || '_'; m.byChannel[ch] = (m.byChannel[ch] || 0) + net; m.commByChannel[ch] = (m.commByChannel[ch] || 0) + com;
    const co = s.country || 'Sin país'; m.byCountry[co] = (m.byCountry[co] || 0) + net;
    const pj = saleProject(s); if (pj) { const x = m.byProject[pj] || (m.byProject[pj] = { net: 0, cost: 0 }); x.net += net; x.cost += com + tax; }
    const dd = m.byDay[s.date] || (m.byDay[s.date] = { net: 0, exp: 0 }); dd.net += net - com - tax;
    if (s.customerId) buyers.add(s.customerId);
  }
  for (const cid of buyers) { if (inR(firstBuy[cid], r)) m.customersNew++; else m.customersRec++; }
  for (const t of state.treasury) {
    if (t.type !== 'cobro' || !inR(t.date, r)) continue;
    const s = byId(state.sales, t.saleId); const b = s ? saleBrand(s) : ''; if (!lensOk(b)) continue;
    const v = conv(t.amount, t.currency, t.date, miss); if (v != null) m.collected += v;
  }
  for (const e of state.expenses) {
    if (!inR(e.date, r)) continue;
    const share = expLensShare(e); if (!share) continue;
    const v = conv(e.amount, e.currency, e.date, miss); if (v == null) continue;
    const val = v * share;
    if (e.nature === 'inversion') { m.invest += val; continue; }
    m.expenses += val;
    if (ui.lens === 'all' && !allocShares(e).length) m.unassigned += val;
    const g = expGroup(e); m.expByGroup[g] = (m.expByGroup[g] || 0) + val;
    const dd = m.byDay[e.date] || (m.byDay[e.date] = { net: 0, exp: 0 }); dd.exp += val;
    for (const sh of allocShares(e)) if (sh.type === 'project') { const x = m.byProject[sh.id] || (m.byProject[sh.id] = { net: 0, cost: 0 }); x.cost += v * sh.share; }
  }
  m.result = m.net - m.commission - m.tax - m.expenses;
  for (const k of ['gross', 'discount', 'refunded', 'net', 'commission', 'tax', 'liquid', 'collected', 'expenses', 'invest', 'result', 'recurring', 'oneTime', 'unassigned']) m[k] = round2(m[k]);
  return m;
}

/* =====================================================================
   SUSCRIPCIONES (estado a una fecha a partir de sus eventos)
   ===================================================================== */
const planMonthly = p => p ? (p.period === 'anual' ? Number(p.price) / 12 : Number(p.price)) : 0;
function subStateAt(sub, d) {
  let st = 'none';
  for (const ev of [...sub.events].sort((a, b) => a.date.localeCompare(b.date))) {
    if (ev.date > d) break;
    if (ev.type === 'prueba') st = 'trial';
    else if (['alta', 'conversion', 'reactivacion', 'renovacion'].includes(ev.type)) st = 'paying';
    else if (ev.type === 'cancelacion') st = 'canceled';
    else if (ev.type === 'pausa') st = 'paused';
  }
  return st;
}
function subPlanAt(sub, d) { let pid = sub.planId; const evs = [...sub.events].sort((a, b) => a.date.localeCompare(b.date)); for (const ev of evs) { if (ev.date > d) break; if (ev.planId) pid = ev.planId; } return plan(pid) || plan(sub.planId); }
function saasMetrics(r) {
  const miss = new Set(), t = todayStr();
  const subs = state.subs.filter(s => lensOk(proj(s.projectId)?.brandId));
  const activeNow = subs.filter(s => s.status === 'activa');
  let mrr = 0; for (const s of activeNow) { const p = plan(s.planId); const v = conv(planMonthly(p), p?.currency, t, miss); if (v != null) mrr += v; }
  const startD = addDays(r.from, -1);
  const payingStart = subs.filter(s => subStateAt(s, startD) === 'paying').length;
  const ev = subs.flatMap(s => s.events.map(e => ({ ...e, sub: s }))).filter(e => inR(e.date, r));
  const cancels = ev.filter(e => e.type === 'cancelacion').length;
  const newPaying = ev.filter(e => e.type === 'alta' || e.type === 'conversion').length;
  const trials = ev.filter(e => e.type === 'prueba').length, conversions = ev.filter(e => e.type === 'conversion').length;
  const renewOk = ev.filter(e => e.type === 'renovacion').length, renewFail = ev.filter(e => e.type === 'pago_fallido').length;
  const months = Math.max(1, (daysBetween(r.from, r.to) + 1) / 30.4);
  const churn = payingStart > 0 ? cancels / payingStart * 100 : null;
  const churnMonthly = churn != null ? churn / months : null;
  const arpu = activeNow.length ? mrr / activeNow.length : null;
  const ltv = arpu != null && churnMonthly ? arpu / (churnMonthly / 100) : null;
  // CAC: gastos de Comercialización del período / clientes nuevos que pagan (suscripción o compra)
  let mkt = 0; for (const e of state.expenses) { if (!inR(e.date, r) || expGroup(e) !== 'Comercialización') continue; const sh = expLensShare(e); if (!sh) continue; const v = conv(e.amount, e.currency, e.date, miss); if (v != null) mkt += v * sh; }
  const mm = metrics(r); const newCust = mm.customersNew;
  const cac = mkt > 0 && newCust > 0 ? mkt / newCust : null;
  const trialEnded = ev.filter(e => e.type === 'conversion').length + subs.filter(s => s.events.some(e => e.type === 'prueba') && s.events.some(e => e.type === 'cancelacion' && inR(e.date, r)) && !s.events.some(e => e.type === 'conversion')).length;
  return { mrr: round2(mrr), arr: round2(mrr * 12), active: activeNow.length, trial: subs.filter(s => s.status === 'prueba').length, unpaid: subs.filter(s => s.status === 'impaga').length, payingStart, cancels, newPaying, trials, conversions, convRate: trialEnded ? conversions / trialEnded * 100 : null, renewOk, renewFail, churn, churnMonthly, arpu, ltv, cac, mkt: round2(mkt), newCust, miss };
}

/* =====================================================================
   RENTABILIDAD POR PROYECTO / APLICACIÓN
   ===================================================================== */
function projectPnL(pid, r) {
  const miss = new Set(); let rev = 0, com = 0, tax = 0, cost = 0, invest = 0, ads = 0, sales = 0;
  for (const s of state.sales) {
    if (s.status === 'anulada' || saleProject(s) !== pid || (r && !inR(s.date, r))) continue;
    const c = saleCalc(s); const n = conv(c.net, s.currency, s.date, miss); if (n == null) continue;
    rev += n; com += conv(c.commission, s.currency, s.date) || 0; tax += conv(c.tax, s.currency, s.date) || 0; sales++;
  }
  for (const e of state.expenses) {
    if (r && !inR(e.date, r)) continue;
    const sh = expProjectShare(e, pid); if (!sh) continue;
    const v = conv(e.amount, e.currency, e.date, miss); if (v == null) continue;
    if (e.nature === 'inversion') invest += v * sh; else { cost += v * sh; if (expGroup(e) === 'Comercialización') ads += v * sh; }
  }
  const p = proj(pid);
  if (!r && p?.initialInvestment) { const v = conv(p.initialInvestment, p.investCurrency || base(), p.launchDate || todayStr(), miss); if (v != null) invest += v; }
  const op = rev - com - tax - cost;
  const hours = sum(state.timeLogs.filter(t => t.projectId === pid && (!r || inR(t.date, r))), t => t.hours);
  return { rev: round2(rev), com: round2(com), tax: round2(tax), cost: round2(cost), ads: round2(ads), op: round2(op), invest: round2(invest), sales, hours, recovery: invest > 0 ? op / invest * 100 : null, miss };
}
function projectPaying(pid) {
  const subs = state.subs.filter(s => s.projectId === pid && s.status === 'activa');
  const buyers = new Set(state.sales.filter(s => s.status !== 'anulada' && saleProject(s) === pid && s.customerId).map(s => s.customerId));
  return { subs: subs.length, buyers: buyers.size };
}
const latestUsers = p => (p.userStats || []).slice().sort((a, b) => b.date.localeCompare(a.date))[0] || null;

/* =====================================================================
   ALERTAS Y CALENDARIO (derivados)
   ===================================================================== */
function calendarEvents(from, to) {
  const out = [], S = state.settings;
  for (const e of state.expenses) if (e.status !== 'pagado' && e.dueDate && inR(e.dueDate, { from, to })) out.push({ date: e.dueDate, title: 'Vence: ' + e.concept, type: 'pago', tone: expStatus(e) === 'vencido' ? 'bad' : 'warn', ref: { kind: 'expense', id: e.id }, amount: money(e.amount, e.currency) });
  for (const r of state.resources) if (r.status !== 'cancelado' && r.renewalDate && inR(r.renewalDate, { from, to })) out.push({ date: r.renewalDate, title: 'Renovación: ' + r.name, type: 'pago', tone: 'warn', ref: { kind: 'resource', id: r.id }, amount: r.cost ? money(r.cost, r.currency) : '' });
  for (const s of state.subs) if (['activa', 'impaga'].includes(s.status) && s.nextRenewal && inR(s.nextRenewal, { from, to })) out.push({ date: s.nextRenewal, title: 'Renueva: ' + (cust(s.customerId)?.name || 'suscriptor') + ' · ' + (proj(s.projectId)?.name || ''), type: 'actualizacion', tone: 'info', ref: { kind: 'sub', id: s.id } });
  for (const p of state.products) if (p.launchDate && inR(p.launchDate, { from, to })) out.push({ date: p.launchDate, title: 'Lanzamiento: ' + p.name, type: 'lanzamiento', tone: 'ok', ref: { kind: 'product', id: p.id } });
  for (const p of state.projects) if (p.launchDate && inR(p.launchDate, { from, to })) out.push({ date: p.launchDate, title: 'Lanzamiento app: ' + p.name, type: 'lanzamiento', tone: 'ok', ref: { kind: 'project', id: p.id } });
  for (const ev of state.events) if (inR(ev.date, { from, to })) out.push({ date: ev.date, title: ev.title, type: ev.type, tone: statusOf(EVENT_TYPES, ev.type).tone, ref: { kind: 'event', id: ev.id }, urgency: ev.urgency });
  return out.sort((a, b) => a.date.localeCompare(b.date));
}
function computeAlerts() {
  const A = [], t = todayStr(), S = state.settings, win = Number(S.alerts.upcomingDays) || 14;
  const brandsOf = ref => { if (!ref) return null; if (ref.kind === 'expense') { const e = byId(state.expenses, ref.id); const b = e ? [...new Set(allocShares(e).map(shareBrand))] : []; return b.length ? b : null; } if (ref.kind === 'resource') { const r = byId(state.resources, ref.id); const b = [...new Set((r?.projectIds || []).map(id => proj(id)?.brandId).filter(Boolean))]; return b.length ? b : null; } if (ref.kind === 'sub') { const s = byId(state.subs, ref.id); return s ? [proj(s.projectId)?.brandId] : null; } if (ref.kind === 'project') return [proj(ref.id)?.brandId]; return null; };
  const push = (urg, title, detail, ref, projectId) => A.push({ urg, title, detail, ref, projectId, brands: brandsOf(ref) });
  for (const e of state.expenses) { const st = expStatus(e); if (st === 'vencido') push('alta', 'Pago vencido: ' + e.concept, `Venció el ${dDate(e.dueDate)} · ${money(e.amount, e.currency)}`, { kind: 'expense', id: e.id }); else if (st === 'pendiente' && e.dueDate && daysBetween(t, e.dueDate) <= win) push(daysBetween(t, e.dueDate) <= 3 ? 'alta' : 'media', 'Vence pronto: ' + e.concept, `${dDate(e.dueDate)} (en ${daysBetween(t, e.dueDate)} días) · ${money(e.amount, e.currency)}`, { kind: 'expense', id: e.id }); }
  for (const r of state.resources) {
    if (r.status !== 'cancelado' && r.renewalDate) { const d = daysBetween(t, r.renewalDate); if (d < 0) push('alta', 'Renovación vencida: ' + r.name, `Era el ${dDate(r.renewalDate)}`, { kind: 'resource', id: r.id }); else if (d <= win) push(d <= 3 ? 'alta' : 'media', 'Renovación próxima: ' + r.name, `${dDate(r.renewalDate)} (en ${d} días)${r.cost ? ' · ' + money(r.cost, r.currency) : ''}`, { kind: 'resource', id: r.id }); }
    const h = (r.priceHistory || []).slice().sort((a, b) => a.date.localeCompare(b.date)); if (h.length >= 2 && h[h.length - 1].cost > h[h.length - 2].cost && h[h.length - 2].currency === h[h.length - 1].currency) push('media', 'Aumentó de precio: ' + r.name, `${money(h[h.length - 2].cost, h[h.length - 2].currency)} → ${money(h[h.length - 1].cost, h[h.length - 1].currency)} (+${pct((h[h.length - 1].cost / h[h.length - 2].cost - 1) * 100)})`, { kind: 'resource', id: r.id });
    if (r.status === 'sin_uso' && r.cost) push('media', 'Servicio sin uso que sigue costando: ' + r.name, 'Evaluá cancelarlo.', { kind: 'resource', id: r.id });
  }
  for (const s of state.subs) if (s.status === 'impaga') push('alta', 'Suscripción con pago fallido', `${cust(s.customerId)?.name || 'Cliente'} · ${proj(s.projectId)?.name || ''}`, { kind: 'sub', id: s.id }, s.projectId);
    else if (s.status === 'activa' && s.nextRenewal && s.nextRenewal < t) push('media', 'Renovación sin registrar', `${cust(s.customerId)?.name || 'Cliente'} · ${proj(s.projectId)?.name || ''} debía renovar el ${dDate(s.nextRenewal)}`, { kind: 'sub', id: s.id }, s.projectId);
  const r30 = { from: addDays(t, -29), to: t }, nd = Number(S.alerts.noSalesDays) || 30;
  for (const p of state.projects) {
    if (['publicada'].includes(p.status) && p.kind !== 'interna') { const last = state.sales.filter(s => s.status !== 'anulada' && saleProject(s) === p.id).map(s => s.date).sort().pop(); const since = last ? daysBetween(last, t) : (p.launchDate ? daysBetween(p.launchDate, t) : null); if (since != null && since >= nd) push('media', 'Sin ventas: ' + p.name, last ? `Última venta hace ${since} días` : `Publicada hace ${since} días, sin ventas registradas`, { kind: 'project', id: p.id }, p.id); }
    const pl = projectPnL(p.id, r30); if (pl.cost > 0 && pl.cost > pl.rev && p.kind !== 'interna') push(pl.rev ? 'media' : 'baja', 'Cuesta más de lo que genera: ' + p.name, `Últimos 30 días: ingresos ${money(pl.rev)} vs. costos ${money(pl.cost + pl.com + pl.tax)}`, { kind: 'project', id: p.id }, p.id);
  }
  // caída por canal: últimos 30 días vs 30 anteriores
  const prev = { from: addDays(t, -59), to: addDays(t, -30) }, drop = Number(S.alerts.dropPct) || 30;
  const chSum = rr => { const o = {}; for (const s of state.sales) if (s.status !== 'anulada' && inR(s.date, rr)) { const v = conv(saleCalc(s).net, s.currency, s.date); if (v != null) o[s.channelId] = (o[s.channelId] || 0) + v; } return o; };
  const a = chSum(r30), b = chSum(prev);
  for (const [ch, v] of Object.entries(b)) { const now = a[ch] || 0; if (v > 0 && (v - now) / v * 100 >= drop) push('media', 'Caída de ventas en ' + (channel(ch)?.name || 'canal'), `Últimos 30 días ${money(now)} vs. ${money(v)} (−${pct((v - now) / v * 100)})`, { kind: 'channel', id: ch }); }
  for (const i of state.integrations) if (i.status === 'error') push('alta', 'Integración con errores: ' + i.name, (i.errors || []).slice(-1)[0] || '', { kind: 'integration', id: i.id });
  if (state.review.length) push('media', `${state.review.length} posible(s) duplicado(s) para revisar`, 'Importaciones que coinciden con registros existentes.', { kind: 'review' });
  const order = { alta: 0, media: 1, baja: 2 };
  return A.sort((x, y) => order[x.urg] - order[y.urg]);
}
/* =====================================================================
   UI BASE: iconos, modales, toasts, tooltips, gráficos
   ===================================================================== */
