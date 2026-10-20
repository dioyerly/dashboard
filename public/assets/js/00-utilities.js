
'use strict';
/* =====================================================================
   CENTRO DE OPERACIONES — ECOSISTEMA DIGITAL (archivo único)
   ---------------------------------------------------------------------
   ALMACENAMIENTO: todo pasa por `DataStore`. Para usar un servidor
   (Flask/MySQL, Supabase, etc.) reemplazá SOLO load() y save(data).
   Hay dos ambientes separados: "produccion" y "pruebas" (claves distintas).
   ===================================================================== */

/* ---------- utilidades ---------- */
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const uid = (p = 'id') => p + '_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
const round2 = n => Math.round((Number(n) || 0) * 100 + Number.EPSILON * 100) / 100;
const num = v => { if (v === '' || v == null) return null; const n = Number(String(v).trim().replace(',', '.')); return Number.isFinite(n) ? n : null; };
const clone = o => JSON.parse(JSON.stringify(o));
const pad = n => String(n).padStart(2, '0');
const fmtDate = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const todayStr = () => fmtDate(new Date());
const nowTime = () => { const d = new Date(); return pad(d.getHours()) + ':' + pad(d.getMinutes()); };
const parseD = s => { const [y, m, d] = String(s).slice(0, 10).split('-').map(Number); return new Date(y, (m || 1) - 1, d || 1); };
const addDays = (s, n) => { const d = parseD(s); d.setDate(d.getDate() + n); return fmtDate(d); };
const addMonths = (s, n) => { const d = parseD(s), day = d.getDate(); d.setDate(1); d.setMonth(d.getMonth() + n); const last = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate(); d.setDate(Math.min(day, last)); return fmtDate(d); };
const daysBetween = (a, b) => Math.round((parseD(b) - parseD(a)) / 864e5);
const dDate = s => s ? `${s.slice(8, 10)}/${s.slice(5, 7)}/${s.slice(0, 4)}` : '';
const dShort = s => s ? `${s.slice(8, 10)}/${s.slice(5, 7)}` : '';
const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const MONTHS_L = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const initials = s => String(s || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0] || '').join('').toUpperCase() || '?';
const debounce = (fn, ms = 200) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
const norm = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
const sum = (arr, f) => arr.reduce((a, x) => a + (Number(f(x)) || 0), 0);

/* ---------- catálogos de estados (no son datos del negocio) ---------- */
const L = (id, label, tone = '') => ({ id, label, tone });
const APP_KINDS = [L('descargable', 'Producto descargable'), L('pago_unico', 'App web de pago único'), L('suscripcion', 'App por suscripción'), L('desarrollo', 'En desarrollo (aún sin ingresos)'), L('interna', 'Herramienta interna')];
const APP_STATUS = [L('idea', 'Idea', ''), L('desarrollo', 'Desarrollo', 'info'), L('pruebas', 'Pruebas', 'warn'), L('publicada', 'Publicada', 'ok'), L('pausada', 'Pausada', 'warn'), L('retirada', 'Retirada', 'bad')];
const PROD_STATUS = [L('borrador', 'Borrador', ''), L('publicado', 'Publicado', 'ok'), L('actualizar', 'Requiere actualización', 'warn'), L('pausado', 'Pausado', 'warn'), L('retirado', 'Retirado', 'bad')];
const SALE_TYPES = [L('unica', 'Venta única', 'info'), L('suscripcion', 'Cobro de suscripción', 'gold'), L('externo', 'Ingreso externo', '')];
const PAY_STATUS = [L('pendiente', 'A cobrar', 'warn'), L('cobrado', 'Cobrado', 'ok')];
const DELIVERY = [L('no_aplica', 'No aplica', ''), L('entregado', 'Entregado', 'ok'), L('pendiente', 'Entrega pendiente', 'warn'), L('incidencia', 'Incidencia', 'bad')];
const SUB_STATUS = [L('prueba', 'En prueba', 'info'), L('activa', 'Activa', 'ok'), L('impaga', 'Pago fallido', 'warn'), L('pausada', 'Pausada', ''), L('cancelada', 'Cancelada', 'bad')];
const SUB_EVENTS = { alta: 'Alta (primer pago)', prueba: 'Inicio de prueba', conversion: 'Prueba → pago', renovacion: 'Renovación cobrada', pago_fallido: 'Pago fallido', cancelacion: 'Cancelación', cambio_plan: 'Cambio de plan', reactivacion: 'Reactivación', pausa: 'Pausa' };
const EXP_STATUS = [L('pendiente', 'Pendiente', 'warn'), L('pagado', 'Pagado', 'ok'), L('vencido', 'Vencido', 'bad')];
const FREQ = [L('unico', 'Único'), L('mensual', 'Mensual'), L('trimestral', 'Trimestral'), L('semestral', 'Semestral'), L('anual', 'Anual')];
const FREQ_MONTHS = { unico: 0, mensual: 1, trimestral: 3, semestral: 6, anual: 12 };
const RES_TYPES = [L('hosting', 'Hosting / servidor'), L('dominio', 'Dominio'), L('base_datos', 'Base de datos'), L('almacenamiento', 'Almacenamiento'), L('repositorio', 'Repositorio'), L('email', 'Correo transaccional'), L('servicio', 'Servicio externo / API'), L('herramienta', 'Herramienta / software')];
const RES_STATUS = [L('activo', 'En uso', 'ok'), L('sin_uso', 'Sin uso', 'warn'), L('cancelado', 'Cancelado', 'bad')];
const ORIGINS = [L('manual', 'Manual'), L('importado', 'Importado'), L('integracion', 'Integración'), L('estimado', 'Estimado')];
const TREAS_TYPES = { cobro: 'Cobro de venta', pago: 'Pago de gasto', transferencia: 'Transferencia entre cuentas', retiro: 'Retiro personal', aporte: 'Aporte / ingreso de capital', ajuste: 'Ajuste de saldo' };
const EVENT_TYPES = [L('campana', 'Campaña publicitaria', 'gold'), L('lanzamiento', 'Lanzamiento', 'ok'), L('actualizacion', 'Actualización de app', 'info'), L('pago', 'Pago / factura', 'warn'), L('otro', 'Otro', '')];
const INTEG_KINDS = [L('tienda', 'Tienda principal'), L('app', 'Aplicación propia'), L('pagos', 'Procesador de pagos'), L('marketplace', 'Marketplace'), L('infra', 'Infraestructura')];
const INTEG_METHODS = [L('manual', 'Carga manual'), L('csv', 'Archivo CSV / Excel'), L('json', 'Eventos JSON'), L('api', 'API (a futuro)'), L('webhook', 'Webhook (a futuro)')];
const INTEG_STATUS = [L('sin_conectar', 'Sin conectar', ''), L('activa', 'Activa', 'ok'), L('error', 'Con errores', 'bad'), L('pausada', 'Pausada', 'warn')];
const statusOf = (list, id) => list.find(s => s.id === id) || { id, label: id || '—', tone: '' };
const badge = (list, id) => { const s = statusOf(list, id); return `<span class="badge ${s.tone}"><span class="dot"></span>${esc(s.label)}</span>`; };
const originTag = o => o && o !== 'manual' ? `<span class="origin ${esc(o)}">${esc(statusOf(ORIGINS, o).label)}</span>` : '';

/* =====================================================================
   ALMACENAMIENTO (reemplazable)
   ===================================================================== */
