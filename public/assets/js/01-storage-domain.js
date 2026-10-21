function currentEnv() { try { return localStorage.getItem('eco-env') || 'produccion'; } catch (e) { return 'produccion'; } }
const DataStore = {
  env: currentEnv(),
  get label() { return 'este navegador · ' + (this.env === 'pruebas' ? 'ambiente de PRUEBAS' : 'producción'); },
  DB: 'centro-operaciones', STORE: 'kv',
  get KEY() { return 'estado-' + this.env; },
  _db: null,
  _remote: null, _remoteVersion: 0, _csrf: '', _financial: null, _operational: false,
  async _remoteEnabled() {
    if (this._remote !== null) return this._remote;
    try {
      const r = await fetch('api.php?action=session', { credentials: 'same-origin', cache: 'no-store' });
      const data = await r.json();
      if(!r.ok||!data.ok||!data.authenticated)throw new Error('authority_unavailable');
      this._readOnly=data.migration_read_only===true;
      this._csrf = data.csrf || '';
      this._remote = r.ok && data.authenticated && data.business_storage === 'sql';
      this._financial = r.ok && data.authenticated && data.financial_api_enabled === true && data.business_storage === 'sql';
    } catch (_) { throw UErr('No se pudo confirmar la fuente de datos. No se guardar\u00e1 una copia local alternativa.'); }
    return this._remote;
  },
  _open() { return new Promise((res, rej) => { if (!('indexedDB' in window)) return rej(new Error('Sin IndexedDB')); const r = indexedDB.open(this.DB, 1); r.onupgradeneeded = () => r.result.createObjectStore(this.STORE); r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error); }); },
  async load() {
    if (await this._remoteEnabled()) {
      const r = await fetch('api.php?action=business_state&environment=' + encodeURIComponent(this.env), { credentials: 'same-origin', cache: 'no-store' });
      const data = await r.json();
      if (!r.ok || !data.ok) throw new Error(data.code || 'No se pudo leer MariaDB');
      this._remoteVersion = data.version || 0;
      const loaded = data.found ? data.state : emptyState();
      if (this._financial) await refreshFinancial(loaded);
      return loaded;
    }
    try { const db = this._db || (this._db = await this._open()); const v = await new Promise((res, rej) => { const q = db.transaction(this.STORE, 'readonly').objectStore(this.STORE).get(this.KEY); q.onsuccess = () => res(q.result || null); q.onerror = () => rej(q.error); }); if (v) return v; } catch (e) { console.warn(e); }
    try { const s = localStorage.getItem('co-' + this.KEY); return s ? JSON.parse(s) : null; } catch (e) { return null; }
  },
  async save(data) {
    await this._remoteEnabled();
    if(this._readOnly)throw UErr('Vista SQL de verificaci\u00f3n: no se permiten escrituras.');
    if (await this._remoteEnabled()) {
      if (!this._csrf) { const s = await fetch('api.php?action=session', { credentials: 'same-origin', cache: 'no-store' }); const d = await s.json(); this._csrf = d.csrf || ''; }
      const persisted = this._financial ? { ...data, sales: [], expenses: [], treasury: [], ...(this._operational ? {subs: []} : {}) } : data;
      const body = { environment: this.env, operation_id: 'state_' + uid('op'), expected_version: this._remoteVersion, state: persisted };
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
async function financialEnabled(){await DataStore._remoteEnabled();return DataStore._financial===true;}
async function refreshFinancial(target = state) {
  const r = await fetch('api.php?action=financial_state&environment='+encodeURIComponent(DataStore.env), {credentials:'same-origin',cache:'no-store'});
  const d = await r.json(); if (!r.ok || !d.ok) throw UErr('No se pudo recuperar el estado financiero. Recarg? antes de operar.');
  DataStore._operational=d.operationalReady===true;
  for (const key of ['sales','expenses','treasury']) target[key] = d[key] || [];
  if(DataStore._operational)target.subs=d.subs||[];
  target._sqlPrices=d.planPrices || [];
  if(d.subscriptionCustomers){const customers=new Map((target.customers||[]).map(x=>[x.id,x]));for(const customer of d.subscriptionCustomers)if(!customers.has(customer.id))customers.set(customer.id,customer);target.customers=[...customers.values()];}
}
async function financialCommand(command) {
  if (!(await financialEnabled())) return null;
  if(DataStore._readOnly)throw UErr('Vista SQL de verificaci\u00f3n: no se permiten operaciones.');
  const key='centro-pending-financial-'+DataStore.env;
  const pending=sessionStorage.getItem(key);
  const body=pending?JSON.parse(pending):{environment:DataStore.env,operation_id:'fin_'+uid('op'),command};
  sessionStorage.setItem(key,JSON.stringify(body));
  let r,d;
  try { r=await fetch('api.php?action=financial_command',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':DataStore._csrf},body:JSON.stringify(body)}); d=await r.json(); }
  catch (_) { throw UErr('Respuesta pendiente. Volv? a intentar para recuperar la misma operaci?n sin duplicarla.'); }
  if (!r.ok || !d.ok) {
    if (r.status<500 && ![401,403].includes(r.status)) sessionStorage.removeItem(key);
    throw UErr((d.code==='operational_migration_required'?'Esta operaci\u00f3n requiere la migraci\u00f3n 017. No se modificaron los datos.':d.code==='version_conflict'?'Los datos cambiaron. Recarg? antes de operar.':'Operaci?n no registrada: '+(d.code||'error')));
  }
  await refreshFinancial();
  sessionStorage.removeItem(key);
  if(pending){const recovered=UErr('Se recuper? la operaci?n pendiente. Revis? el resultado antes de registrar otra.');recovered.financialCommitted=true;throw recovered;}
  return d.result;
}
async function transactFinancial(desc,command,applyLocal) {
  if(busy)throw UErr('Hay una operaci?n guard?ndose.');
  busy=true;
  try {
    const result=await financialCommand(command);if(result===null)return null;
    return result;
  } catch(e){toast(e.userMsg||e.message,'bad');throw e;} finally{busy=false;}
}
function copyFinancial(target){for(const key of ['sales','expenses','treasury','subs','_sqlPrices'])target[key]=clone(state[key]);}
function sqlPrice(planId,date) {
  const prices=(state._sqlPrices||[]).filter(p=>p.plan_id===planId && p.provenance==='known' && p.valid_from && p.valid_from<=date && (!p.valid_to||date<p.valid_to));
  if(prices.length!==1)throw UErr('Falta un precio hist?rico SQL inequ?voco para este plan y fecha.');
  return prices[0];
}
async function routeFinancialDraft(before,after) {
  const changed=key=>JSON.stringify(before[key])!==JSON.stringify(after[key]);
  if(changed('subs')) {
    if(!DataStore._operational)throw UErr('Esta operaci\u00f3n requiere la migraci\u00f3n 017; las suscripciones existentes se conservan.');
    const subs=after.subs.filter(s=>JSON.stringify(s)!==JSON.stringify(before.subs.find(x=>x.id===s.id)));
    if(subs.length!==1 || after.subs.length<before.subs.length)throw UErr('La eliminaci?n de suscripciones no tiene equivalencia SQL segura.');
    const sub=subs[0],old=before.subs.find(x=>x.id===sub.id),events=sub.events.filter(e=>!old?.events.some(x=>x.id===e.id));
    if(events.length!==1)throw UErr('Solo se permite un evento de suscripci\u00f3n por operaci\u00f3n.');
    const newCustomer=after.customers.find(c=>c.id===sub.customerId&&!before.customers.some(x=>x.id===c.id));
    if(JSON.stringify(before.customers)!==JSON.stringify(after.customers) && (old||!newCustomer||after.customers.length!==before.customers.length+1||JSON.stringify(after.customers.filter(c=>c.id!==newCustomer.id))!==JSON.stringify(before.customers)))throw UErr('El cambio de clientes debe registrarse por separado.');
    const event=events[0],date=event.date;
    const sale=after.sales.find(x=>x.id===event.saleId);
    const price=(!old||sale||event.type==='cambio_plan')?sqlPrice(sub.planId,date):null;
    if(sale && (sale.commission||sale.tax||sale.discount||sale.refunded||sale.unitPrice<=0))throw UErr('Comisiones, impuestos e importes cero requieren una equivalencia contable revisada; operaci?n bloqueada en SQL.');
    const cash=after.treasury.find(t=>t.saleId===event.saleId);
    const command={kind:!old?'subscription_register':sale?'subscription_renew':'subscription_status',subscription_id:sub.id,expected_version:old?old._sqlVersion:0,plan_id:sub.planId,customer_id:sub.customerId,date,event:event.type,trial:event.type==='prueba',price_snapshot_id:price?.id,note:event.note||''};
    if(newCustomer)command.customer=newCustomer;
    if(sale)Object.assign(command,{sale_id:sale.id,amount:round2(sale.unitPrice).toFixed(2),currency:sale.currency,settle_now:sale.payStatus==='cobrado',confirmed:sale.payStatus==='cobrado',account_id:cash?.accountTo||undefined});
    await financialCommand(command);copyFinancial(after);return true;
  }
  if(changed('sales')&&!changed('subs')&&!changed('expenses')){
    const added=after.sales.filter(x=>!before.sales.some(y=>y.id===x.id));
    if(added.length===1&&after.sales.length===before.sales.length+1&&JSON.stringify(after.sales.filter(x=>x.id!==added[0].id))===JSON.stringify(before.sales)){
      const x=added[0],cash=after.treasury.filter(t=>!before.treasury.some(y=>y.id===t.id));
      if(x.discount||x.refunded||x.commission||x.tax||!Number.isInteger(x.qty)||x.qty<1||x.unitPrice<=0)throw UErr('Descuentos, reembolsos y comisiones requieren una equivalencia contable revisada.');
      if((x.payStatus==='cobrado'&&(cash.length!==1||cash[0].saleId!==x.id||!cash[0].accountTo))||(x.payStatus!=='cobrado'&&cash.length))throw UErr('Indic\u00e1 una cuenta para registrar el cobro sin duplicarlo.');
      const customer=after.customers.find(c=>c.id===x.customerId&&!before.customers.some(y=>y.id===c.id));
      if(JSON.stringify(after.customers)!==JSON.stringify(before.customers)&&(!customer||after.customers.length!==before.customers.length+1||JSON.stringify(after.customers.filter(c=>c.id!==customer.id))!==JSON.stringify(before.customers)))throw UErr('El cambio de clientes debe registrarse por separado.');
      await financialCommand({kind:'sale_register',sale_id:x.id,customer_id:x.customerId||null,customer,amount:round2(x.qty*x.unitPrice).toFixed(2),currency:x.currency,date:x.date,settle_now:x.payStatus==='cobrado',confirmed:x.payStatus==='cobrado',account_id:cash[0]?.accountTo,payment_date:cash[0]?.date,lines:[{product_id:x.productId||null,version_id:x.versionId||null,description:x.concept||'',quantity:String(x.qty),unit_price:round2(x.unitPrice).toFixed(2),line_total:round2(x.qty*x.unitPrice).toFixed(2)}],record:x});copyFinancial(after);return true;
    }
  }
  if(changed('sales') && !changed('subs') && !changed('expenses') && !changed('treasury')) {
    const edited=after.sales.filter(x=>JSON.stringify(x)!==JSON.stringify(before.sales.find(y=>y.id===x.id)));
    const fields=['type','projectId','planId','subId','brandId','channelId','country','concept','coupon','delivery','origin','source','externalId','notes'];
    if(edited.length===1&&after.sales.length===before.sales.length){
      const x=edited[0],old=before.sales.find(y=>y.id===x.id);
      const protectedFields=['id','date','currency','customerId','productId','versionId','qty','unitPrice','discount','refunded','commission','tax','payStatus','status'];
      if(old?._sqlVersion&&protectedFields.every(k=>JSON.stringify(x[k])===JSON.stringify(old[k]))){
        const record=Object.fromEntries(fields.filter(k=>k in x).map(k=>[k,x[k]]));
        await financialCommand({kind:'sale_edit',sale_id:x.id,expected_version:old._sqlVersion,record});copyFinancial(after);return true;
      }
      if(old?._sqlVersion && x.currency===old.currency && x.date===old.date && x.payStatus===old.payStatus && x.status===old.status && !x.discount && !x.refunded && !x.commission && !x.tax && !(old._sqlLines?.length>1) && Number.isInteger(x.qty) && x.qty>0){
        const record=Object.fromEntries(fields.filter(k=>k in x).map(k=>[k,x[k]]));
        await financialCommand({kind:'sale_edit',sale_id:x.id,expected_version:old._sqlVersion,amount:round2(x.qty*x.unitPrice).toFixed(2),customer_id:x.customerId||null,lines:[{product_id:x.productId||null,version_id:x.versionId||null,description:x.concept||'',quantity:String(x.qty),unit_price:round2(x.unitPrice).toFixed(2),line_total:round2(x.qty*x.unitPrice).toFixed(2)}],record});copyFinancial(after);return true;
      }
    }
  }
  if(changed('expenses') && !changed('sales')) {
    const edited=after.expenses.filter(x=>JSON.stringify(x)!==JSON.stringify(before.expenses.find(y=>y.id===x.id)));
    if(edited.length===1 && after.expenses.length===before.expenses.length+1 && !changed('treasury')){
      const x=edited[0];if(x.status!=='pendiente')throw UErr('Registr\u00e1 el pago desde el gasto para conservar su movimiento.');
      await financialCommand({kind:'expense_register',expense_id:x.id,amount:round2(x.amount).toFixed(2),currency:x.currency,date:x.date,supplier_id:x.supplierId||null,record:x});copyFinancial(after);return true;
    }
    if(edited.length===1 && after.expenses.length===before.expenses.length){
      const x=edited[0],old=before.expenses.find(y=>y.id===x.id);
      if(!old?._sqlVersion || x.status!==old.status || changed('treasury'))throw UErr('Este cambio alterar\u00eda pagos existentes; registr\u00e1 el pago o su reversi\u00f3n por separado.');
      await financialCommand({kind:'expense_edit',expense_id:x.id,amount:round2(x.amount).toFixed(2),currency:x.currency,date:x.date,supplier_id:x.supplierId||null,expected_version:old._sqlVersion,record:x});copyFinancial(after);return true;
    }
  }
  if(changed('treasury') && !changed('sales') && !changed('expenses')) {
    const added=after.treasury.filter(x=>!before.treasury.some(y=>y.id===x.id));
    if(added.length===1 && after.treasury.length===before.treasury.length+1){
      const t=added[0],kind={aporte:'capital_contribution',retiro:'withdrawal',ajuste:'treasury_adjustment'}[t.type];
      if(kind){await financialCommand({kind,movement_id:t.id,account_id:t.type==='retiro'?t.accountFrom:t.accountTo,amount:round2(t.amount).toFixed(2),currency:t.currency,date:t.date,confirmed:true,note:t.note||''});copyFinancial(after);return true;}
    }
  }
  if(['sales','expenses','treasury'].some(changed))throw UErr('Esta operaci?n no tiene una equivalencia SQL segura. Se conserva el historial; no se aplic? el cambio.');
  return false;
}

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
  try { const sql=await financialEnabled();const draft=sql?clone(state):state;const r=fn(draft); if(sql && await routeFinancialDraft(snap,draft)){state=draft;return r;} await DataStore.save(draft);state=draft;return r; }
  catch (e) { if(!e.financialCommitted)state = snap; console.error(e); toast(e && e.userMsg ? e.userMsg : 'No se pudo guardar: ' + (e.message || e), 'bad'); throw e; }
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


// Full, read-only local backup. The manifest binds each independent source copy.
const ControlledBackup = {
  format: 'centro-operaciones-controlled-backup',
  parse(text) {
    const value=JSON.parse(text),tokens=text.match(/"(?:\\.|[^"\\])*"|[{}\[\]:,]|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|true|false|null/g)||[];let i=0;
    const consume=()=>{const token=tokens[i++];if(token==='{'){const seen=new Set();if(tokens[i]!=='}'){do{const key=JSON.parse(tokens[i++]);if(seen.has(key))throw UErr('Hay propiedades JSON duplicadas; no se descartaron datos.');seen.add(key);i++;consume();if(tokens[i]!==',')break;i++;}while(true);}i++;}else if(token==='['){if(tokens[i]!==']'){do{consume();if(tokens[i]!==',')break;i++;}while(true);}i++;}};
    consume();return value;
  },
  async hash(text) { return [...new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(text)))].map(x=>x.toString(16).padStart(2,'0')).join(''); },
  sanitize(value,path,redactions) {
    if(Array.isArray(value))return value.map((v,i)=>this.sanitize(v,path.concat(i),redactions));
    if(value&&typeof value==='object'){
      if(Object.getPrototypeOf(value)!==Object.prototype)throw UErr('El respaldo contiene un tipo no JSON; no se gener\u00f3 una copia incompleta.');
      const result={};
      for(const [key,v] of Object.entries(value)){
        if(/password|passwd|secret|token|api[_-]?key|credential|authorization|session|cookie|^_?csrf$|^pin$/i.test(key)){
          if(v!=='')redactions.push({path:path.concat(key),reason:'private_field_excluded'});Object.defineProperty(result,key,{value:'',enumerable:true,writable:true,configurable:true});
        }else Object.defineProperty(result,key,{value:this.sanitize(v,path.concat(key),redactions),enumerable:true,writable:true,configurable:true});
      }
      return result;
    }
    if(typeof value==='string'&&(/https?:\/\/[^/\s]+:[^/\s]+@/i.test(value)||/[?&](?:token|access_token|api_key|password|secret)=/i.test(value)||/\b(?:Bearer\s+[A-Za-z0-9._-]+|ghp_[A-Za-z0-9]+|sk-[A-Za-z0-9]{16,})/.test(value))){redactions.push({path,reason:'credential_value_excluded'});return '';}
    if(value===null||typeof value==='string'||typeof value==='boolean'||typeof value==='number'&&Number.isFinite(value))return value;
    throw UErr('El respaldo contiene un valor no JSON; no se gener\u00f3 una copia incompleta.');
  },
  async localCopies() {
    const copies=[];
    if('indexedDB' in window){
      const databases=indexedDB.databases?await indexedDB.databases():null;
      if(!databases||databases.some(d=>d.name===DataStore.DB)){
        const db=await new Promise((resolve,reject)=>{const request=indexedDB.open(DataStore.DB);request.onupgradeneeded=()=>request.transaction.abort();request.onsuccess=()=>resolve(request.result);request.onerror=()=>{if(request.error?.name==='AbortError')resolve(null);else reject(request.error);};request.onblocked=()=>reject(UErr('Cerr\u00e1 las otras pesta\u00f1as antes de exportar.'));});
        if(db){try{
          if(!db.objectStoreNames.contains(DataStore.STORE))throw UErr('El almac\u00e9n local no coincide; no se gener\u00f3 un respaldo parcial.');
          const rows=await new Promise((resolve,reject)=>{const tx=db.transaction(DataStore.STORE,'readonly'),store=tx.objectStore(DataStore.STORE),keys=store.getAllKeys(),values=store.getAll();tx.oncomplete=()=>resolve(keys.result.map((key,i)=>({key,value:values.result[i]})));tx.onerror=()=>reject(tx.error);tx.onabort=()=>reject(tx.error);});
          for(const row of rows){if(!['estado-pruebas','estado-produccion'].includes(row.key))throw UErr('Hay claves locales desconocidas que requieren revisi\u00f3n.');copies.push({id:'indexedDB:'+row.key,environment:row.key.slice(7),source:'indexedDB',key:row.key,value:row.value});}
        }finally{db.close();}}
      }
    }
    for(const environment of ['produccion','pruebas']){const key='co-estado-'+environment,raw=localStorage.getItem(key);if(raw!==null){let value;try{value=this.parse(raw);}catch(_){throw UErr('Una copia local no es JSON v\u00e1lido. Se conserv\u00f3 intacta y la exportaci\u00f3n se detuvo.');}copies.push({id:'localStorage:'+key,environment,source:'localStorage',key,value,raw});}}
    return copies;
  },
  async create() {
    if(busy)throw UErr('Esper\u00e1 a que termine el guardado antes de exportar.');
    const copies=await this.localCopies();
    copies.push({id:'visible:'+DataStore.env,environment:DataStore.env,source:'visible',key:'state',value:clone(state)});
    const snapshots=[],redactions=[];
    for(const copy of copies){const exclusions=[];const clean=this.sanitize(copy.value,[copy.id],exclusions);redactions.push(...exclusions);const payload=copy.raw&&exclusions.length===0?copy.raw:JSON.stringify(clean);snapshots.push({id:copy.id,environment:copy.environment,source:copy.source,key:copy.key,payload,bytes:new TextEncoder().encode(payload).length,sha256:await this.hash(payload)});}
    const preferences={};for(const key of ['eco-env','eco-ui']){const v=localStorage.getItem(key);if(v!==null){if(key==='eco-env'){if(!['produccion','pruebas'].includes(v))throw UErr('Ambiente local desconocido.');preferences[key]=v;}else{let parsed;try{parsed=JSON.parse(v);}catch(_){throw UErr('Las preferencias locales no son JSON v\u00e1lido.');}preferences[key]=JSON.stringify(this.sanitize(parsed,['preferences',key],redactions));}}}
    const exportedAt=new Date().toISOString(),entries=snapshots.map(({payload,...entry})=>entry);
    const manifestPayload=JSON.stringify({format:this.format,version:1,exportedAt,entries,preferences,redactions});
    return {format:this.format,version:1,exportedAt,snapshots,preferences,redactions,manifest:{algorithm:'SHA-256',payload:manifestPayload,sha256:await this.hash(manifestPayload)}};
  },
  async verify(archive) {
    if(!archive||archive.format!==this.format||archive.version!==1||archive.manifest?.algorithm!=='SHA-256'||!Array.isArray(archive.snapshots)||archive.snapshots.length<1||archive.snapshots.length>5||typeof archive.manifest.payload!=='string'||!Array.isArray(archive.redactions))throw UErr('Formato de respaldo no admitido.');
    if(await this.hash(archive.manifest.payload)!==archive.manifest.sha256)throw UErr('El manifiesto del respaldo no coincide.');
    const bound=this.parse(archive.manifest.payload),ids=new Set();
    const entries=[];
    for(const entry of archive.snapshots){
      if(!entry||!['produccion','pruebas'].includes(entry.environment)||!['indexedDB','localStorage','visible'].includes(entry.source)||ids.has(entry.id)||typeof entry.payload!=='string')throw UErr('Origen de respaldo inv\u00e1lido.');
      const key=entry.source==='visible'?'state':(entry.source==='indexedDB'?'estado-':'co-estado-')+entry.environment;
      if(entry.key!==key||entry.id!==(entry.source==='visible'?'visible:'+entry.environment:entry.source+':'+key))throw UErr('La copia tiene un origen ambiguo.');
      ids.add(entry.id);if(new TextEncoder().encode(entry.payload).length!==entry.bytes||await this.hash(entry.payload)!==entry.sha256)throw UErr('Una copia del respaldo no coincide con su hash.');
      const value=this.parse(entry.payload),exclusions=[],clean=this.sanitize(value,[entry.id],exclusions);if(JSON.stringify(clean)!==JSON.stringify(value))throw UErr('El respaldo contiene credenciales privadas.');
      const {payload,...descriptor}=entry;entries.push(descriptor);
    }
    if(!archive.preferences||typeof archive.preferences!=='object'||Array.isArray(archive.preferences))throw UErr('Preferencias de respaldo no v\u00e1lidas.');
    for(const [key,value] of Object.entries(archive.preferences)){
      if(!['eco-env','eco-ui'].includes(key)||typeof value!=='string')throw UErr('El respaldo contiene preferencias privadas o desconocidas.');
      if(key==='eco-env'&&!['produccion','pruebas'].includes(value))throw UErr('Ambiente del respaldo desconocido.');
      if(key==='eco-ui'){const parsed=this.parse(value),redactions=[],clean=this.sanitize(parsed,['preferences',key],redactions);if(JSON.stringify(clean)!==JSON.stringify(parsed))throw UErr('El respaldo contiene credenciales privadas.');}
    }
    const actual={format:archive.format,version:archive.version,exportedAt:archive.exportedAt,entries,preferences:archive.preferences,redactions:archive.redactions};
    if(JSON.stringify(bound)!==JSON.stringify(actual))throw UErr('La identidad o el inventario del respaldo cambi\u00f3.');
    return archive;
  },
  async restoreState(archive) {
    await this.verify(archive);
    if(await DataStore._remoteEnabled())throw UErr('La restauraci\u00f3n local no puede escribir en SQL.');
    const entry=archive.snapshots.find(x=>x.id==='visible:'+DataStore.env);
    if(!entry)throw UErr('El respaldo no tiene una copia visible para este ambiente.');
    const data=this.parse(entry.payload);
    if(data.version!==1||!data.settings)throw UErr('El estado de negocio no es compatible.');
    const existing=(await this.localCopies()).filter(x=>x.environment===DataStore.env);
    if(existing.some(x=>JSON.stringify(x.value)!==JSON.stringify(data)))throw UErr('Este ambiente ya contiene datos distintos. Restaur\u00e1 en un perfil nuevo para conservar ambas copias.');
    return data;
  }
};
