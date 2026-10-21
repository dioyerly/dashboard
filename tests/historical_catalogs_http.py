"""Authenticated HTTP checks for synthetic historical conversion and catalog authority."""
import json,os,secrets,socket,subprocess,sys,time
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'));import local_mariadb as db
access=json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'));OUT=Path(sys.argv[1]).parent
checks=[];servers=[];logs=[]
def check(name,ok):
 checks.append({'check':name,'passed':bool(ok)});(OUT/'http-results.json').write_text(json.dumps({'checks':checks},indent=2));assert ok,name
def serve(local=False,port=None):
 env=access['environment'].copy();env.update({'CENTRO_BUSINESS_API_ENABLED':'0' if local else '1','CENTRO_BUSINESS_STORAGE':'local' if local else 'sql','CENTRO_FINANCIAL_API_ENABLED':'0' if local else '1'})
 if port is None:
  with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 log=open(OUT/('http-'+secrets.token_hex(3)+'.log'),'w');logs.append(log)
 p=subprocess.Popen(db.PHP_ARGS+['-S','127.0.0.1:'+str(port),'-t',str(ROOT/'public')],cwd=ROOT,env=env,stdout=log,stderr=log,creationflags=db.FLAGS);servers.append(p)
 for _ in range(60):
  try:
   with socket.create_connection(('127.0.0.1',port),timeout=.2):return p,port,'http://127.0.0.1:'+str(port)+'/'
  except OSError:time.sleep(.1)
 raise RuntimeError('test_server_unavailable')
def login(api,base):
 s=api.get(base+'api.php?action=session').json();r=api.post(base+'api.php?action=login',headers={'X-CSRF-Token':s['csrf']},data={'email':access['email'],'password':access['password']});assert r.status==200;return api.get(base+'api.php?action=session').json()['csrf']
try:
 process,port,base=serve()
 with sync_playwright() as pw:
  api=pw.request.new_context();check('catalog HTTP requires authentication',api.get(base+'api.php?action=business_state&environment=pruebas').status==401);csrf=login(api,base);headers={'X-CSRF-Token':csrf}
  loaded=api.get(base+'api.php?action=business_state&environment=pruebas').json();body={'environment':'pruebas','operation_id':'state_http_catalog_atomic_0001','expected_version':loaded['version'],'state':loaded['state']};body['state']['settings']['business']['owner']='HTTP catalog owner'
  check('catalog HTTP requires CSRF',api.post(base+'api.php?action=business_state',data=body).status==403)
  a=api.post(base+'api.php?action=business_state',headers=headers,data=body);b=api.post(base+'api.php?action=business_state',headers=headers,data=body);check('catalog HTTP idempotency receipt',a.status==200 and b.status==200 and b.json()['replayed'])
  stale={**body,'operation_id':'state_http_catalog_stale_0001'};check('stale catalog HTTP write conflicts',api.post(base+'api.php?action=business_state',headers=headers,data=stale).status==409)
  current=api.get(base+'api.php?action=business_state&environment=pruebas').json();forged={'environment':'pruebas','operation_id':'state_http_financial_forged_0001','expected_version':current['version'],'state':{**current['state'],'sales':[{'id':'forged'}]}};check('business state rejects duplicate financial persistence',api.post(base+'api.php?action=business_state',headers=headers,data=forged).status==422)
  browser=pw.chromium.launch(headless=True);ctx=browser.new_context(storage_state=api.storage_state(),bypass_csp=True);ctx.route('**/*',lambda route:route.continue_() if route.request.url.startswith(base) else route.abort());page=ctx.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.add_init_script("localStorage.setItem('eco-env','pruebas')");page.goto(base,wait_until='domcontentloaded');page.wait_for_function('DataStore._financial===true&&state.sales.length===1')
  check('historical SQL creation dates and history reach browser',page.evaluate("state.sales[0].createdAt==='2024-01-02T03:04:05Z'&&state.sales[0].history[0].text==='Original preserved'"))
  before=page.evaluate("async()=>JSON.stringify(await ControlledBackup.localCopies())")
  page.evaluate("""async()=>{await transact('catalogos',st=>{st.settings.brands.push({id:'brand_http',name:'Browser SQL brand',color:'#123456',dark:'#123456',alt:'#abcdef',desc:''});st.customers.push({id:'customer_http',name:'Browser SQL customer',email:'browser@example.invalid',country:'',createdAt:'2024-01-01',notes:'',origin:'manual'});st.products.push({id:'product_http',name:'Browser SQL product',brandId:'brand_http',projectId:'',category:'',status:'borrador',versions:[],bundle:[],resources:[]});});await transact('catalogos',st=>{st.settings.brands.find(x=>x.id==='brand_http').name='Browser updated SQL brand';});}""")
  check('browser callbacks read normalized SQL after each save',page.evaluate("state.settings.brands.some(x=>x.id==='brand_http'&&x.name==='Browser updated SQL brand')&&state.products.some(x=>x.id==='product_http')"))
  rows=api.get(base+'api.php?action=business_list&environment=pruebas&resource=brands').json()['records'];check('catalog callback reaches normalized table',any(r['id']=='brand_http' and r['name']=='Browser updated SQL brand' for r in rows))
  check('SQL catalog writes do not touch browser originals',page.evaluate("async()=>JSON.stringify(await ControlledBackup.localCopies())")==before)
  page.reload(wait_until='domcontentloaded');page.wait_for_function("state.customers.some(x=>x.id==='customer_http')");check('catalog SQL persistence after reload',page.evaluate("state.settings.brands.some(x=>x.id==='brand_http'&&x.name==='Browser updated SQL brand')"))
  for route in ['pulso','marcas','apps','ventas','suscripciones','gastos','finanzas','infra','calendario','clientes','integraciones','reportes','ajustes']:page.evaluate('(r)=>go(r)',route)
  check('all 13 original views execute without JS errors',not errors)
  financial=api.get(base+'api.php?action=financial_state&environment=pruebas').json();state=api.get(base+'api.php?action=business_state&environment=pruebas').json();check('converted cash effects remain single',len(financial['treasury'])==7)
  check('logout closes authorization',api.post(base+'api.php?action=logout',headers=headers,data={}).status==200 and api.get(base+'api.php?action=business_state&environment=pruebas').status==401)
  process.terminate();process.wait(timeout=10);process,_,base=serve(port=port);login(api,base)
  check('converted financial state persists after login and PHP restart',api.get(base+'api.php?action=financial_state&environment=pruebas').json()==financial)
  check('normalized catalogs persist after login and PHP restart',api.get(base+'api.php?action=business_state&environment=pruebas').json()==state)
  _,_,local=serve(local=True);localapi=pw.request.new_context();login(localapi,local);lc=browser.new_context(storage_state=localapi.storage_state(),bypass_csp=True);lc.route('**/*',lambda route:route.continue_() if route.request.url.startswith(local) else route.abort());lp=lc.new_page();lp.add_init_script("localStorage.setItem('eco-env','pruebas')");lp.goto(local,wait_until='domcontentloaded');lp.wait_for_function('DataStore._remote===false');lp.evaluate("async()=>{state=emptyState();state.setupDone=true;await transact('local',st=>st.customers.push({id:'local_preserved',name:'Only local'}));}");lp.reload(wait_until='domcontentloaded');lp.wait_for_function("state.customers.some(x=>x.id==='local_preserved')");check('SQL disabled retains IndexedDB behavior',lp.evaluate('DataStore._remote===false&&DataStore._financial===false'))
  browser.close()
 print('PASS historical/catalog HTTP',len(checks),'checks')
finally:
 for p in servers:
  if p.poll() is None:p.terminate();p.wait(timeout=10)
 for log in logs:log.close()
