'use strict';
// Characterize existing mixed operations in memory. No SQL, browser data or network.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const source = name => fs.readFileSync(path.join(root, 'public/assets/js', name), 'utf8');
const domain = source('01-storage-domain.js');
const sales = source('04-sales.js');
function declaration(text, name, async = false) {
  const match = text.match(new RegExp('^' + (async ? 'async ' : '') + 'function ' + name + '\\([^]*?^}', 'm'));
  assert(match, 'Existing function not found: ' + name);
  return match[0];
}
const utilities = source('00-utilities.js').split('\n').filter(line => /^const (uid|round2|num|clone|norm) = /.test(line)).join('\n');
const context = vm.createContext({console: {error() {}}, structuredClone});
vm.runInContext(utilities + '\n' + declaration(domain, 'emptyState') + '\n' +
  declaration(domain, 'saleCalc') + '\n' + declaration(sales, 'createSale') + '\n' +
  declaration(domain, 'transact', true) + `
  let busy = false, state = emptyState();
  const toasts = [];
  function toast(message) { toasts.push(message); }
  // Rendering/audit adapters only; financial calculations above come from actual source.
  function money(value) { return String(value); }
  function saleTitle(sale) { return sale.concept || 'Synthetic sale'; }
  function logAct(st, action, detail) { st.log.push({action, detail}); }
  const DataStore = {async save() { return true; }};
  async function financialEnabled() { return false; } // Exercise the unchanged local branch.
  const sale = {date:'2026-10-16', currency:'ARS', qty:2, unitPrice:100,
    discount:20, refunded:30, commission:10, tax:5, payStatus:'cobrado'};
  const customer = {name:'Synthetic contract', email:'contract@ejemplo.test'};
`, context);
const checks = [];
async function check(name, expression, kind = 'existing_behavior') {
  assert.equal(await vm.runInContext(expression, context), true, name);
  checks.push({check:name, passed:true, kind});
  process.stdout.write('VERIFIED: ' + name + '\n');
}
(async () => {
  await check('One existing sale command creates customer, sale and linked receipt', `(() => {
    state=emptyState(); createSale(state,sale,customer);
    return state.customers.length===1 && state.sales.length===1 && state.treasury.length===1 &&
      state.sales[0].customerId===state.customers[0].id && state.treasury[0].saleId===state.sales[0].id && state.treasury[0].amount===135;
  })()`);
  await check('Customer email deduplication currently uses only this in-memory state', `(() => {
    createSale(state,sale,customer);return state.customers.length===1 && state.sales.length===2;
  })()`);
  await check('Existing transact restores memory when the single local save rejects', `(async () => {
    state=emptyState();DataStore.save=async()=>{throw new Error('Synthetic save failure');};
    let rejected=false;try {await transact('contract',st=>createSale(st,sale,customer));}catch {rejected=true;}
    return rejected && state.customers.length===0 && state.sales.length===0 && state.treasury.length===0 && !busy;
  })()`);
  await check('Modeled SQL-first split leaves committed catalog after local failure despite memory rollback', `(async () => {
    state=emptyState();let modeledRemoteCatalog;
    DataStore.save=async st=>{modeledRemoteCatalog=clone(st.customers);throw new Error('Modeled local commit failure');};
    try {await transact('contract',st=>createSale(st,sale,customer));}catch {}
    return state.customers.length===0 && state.sales.length===0 && modeledRemoteCatalog.length===1;
  })()`, 'fault_model_only_not_real_sql');
  await check('Modeled local-first split leaves sale referencing uncommitted customer after SQL rejection', `(async () => {
    state=emptyState();let modeledLocalFinance;const modeledRemoteCatalog=[];
    DataStore.save=async st=>{modeledLocalFinance=clone(st.sales);throw new Error('Modeled SQL rejection');};
    try {await transact('contract',st=>createSale(st,sale,customer));}catch {}
    return state.sales.length===0 && modeledLocalFinance.length===1 &&
      !modeledRemoteCatalog.some(c=>c.id===modeledLocalFinance[0].customerId);
  })()`, 'fault_model_only_not_real_sql');
  fs.mkdirSync(path.join(root,'.runtime/operations-work'),{recursive:true});
  fs.writeFileSync(path.join(root,'.runtime/operations-work/phase4b-contract-results.json'), JSON.stringify({
    scope:'Three existing-function characterizations and two in-memory fault models of a proposed naive split; no real SQL migration or browser persistence exercised',
    checks, business_storage_modified:false, sql_executed:false,
    conclusion:'Existing memory rollback cannot coordinate separate durable stores; a recovery protocol and explicit restore scope are required before activation.'
  }, null, 2)+'\n');
})().catch(error => {console.error(error);process.exitCode=1;});
