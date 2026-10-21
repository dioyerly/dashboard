"""Authenticated HTTP, CSRF, SQL UI and IndexedDB regression on disposable servers."""
import json,os,secrets,socket,subprocess,sys,time
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import local_mariadb as db
OUT=ROOT/'.runtime/operations-work';OUT.mkdir(exist_ok=True)
from run_operational_completion import fresh,run_test
cfg,test_env=fresh();assert run_test('operational_completion.php',test_env)
private={'config':cfg,'environment':test_env}
env=private['environment'];password=secrets.token_urlsafe(24);env['CENTRO_TEST_PASSWORD']=password
code="require 'app/Database.php';$c=require 'config/settings.php';$p=Centro\\Database::connection($c['db']);$q=$p->prepare('UPDATE auth_users SET password_hash=? WHERE id=1');$q->execute([password_hash(getenv('CENTRO_TEST_PASSWORD'),PASSWORD_DEFAULT)]);"
r=subprocess.run(db.PHP_ARGS+['-r',code],cwd=ROOT,env=env,capture_output=True,text=True,creationflags=db.FLAGS)
assert r.returncode==0,r.stderr
servers=[];logs=[];checks=[];server_specs={}
def check(name,ok):
    checks.append({'check':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True);assert ok,name

def serve(sql,source_env=None):
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    e=(source_env or env).copy();e['CENTRO_PRIVATE_DIR']=str(OUT/('http-private-'+secrets.token_hex(6)));e.update({'CENTRO_BUSINESS_API_ENABLED':'1' if sql else '0','CENTRO_BUSINESS_STORAGE':'sql' if sql else 'local','CENTRO_FINANCIAL_API_ENABLED':'1' if sql else '0'})
    log=open(OUT/(('http-sql-' if sql else 'http-local-')+str(port)+'.log'),'w');logs.append(log)
    proc=subprocess.Popen(db.PHP_ARGS+['-S',f'127.0.0.1:{port}','-t',str(ROOT/'public')],cwd=ROOT,env=e,stdout=log,stderr=log,creationflags=db.FLAGS);servers.append(proc)
    for _ in range(50):
        try:
            with socket.create_connection(('127.0.0.1',port),timeout=.2):break
        except OSError:time.sleep(.1)
    base=f'http://127.0.0.1:{port}/'
    server_specs[base]={'process':proc,'environment':e,'log':log,'port':port}
    return base

def restart_server(base):
    spec=server_specs[base];old=spec['process'];old.terminate();old.wait(timeout=10)
    process=subprocess.Popen(old.args,cwd=ROOT,env=spec['environment'],stdout=spec['log'],stderr=spec['log'],creationflags=db.FLAGS)
    servers.append(process);spec['process']=process
    for _ in range(50):
        if process.poll() is not None:raise RuntimeError('Synthetic PHP restart failed')
        try:
            with socket.create_connection(('127.0.0.1',spec['port']),timeout=.2):return old.pid,process.pid
        except OSError:time.sleep(.1)
    raise RuntimeError('Synthetic PHP restart timed out')

def login(request):
    s=request.get('api.php?action=session').json()
    r=request.post('api.php?action=login',headers={'X-CSRF-Token':s['csrf']},data={'email':'operations@example.invalid','password':password})
    assert r.status==200
    return request.get('api.php?action=session').json()['csrf']

try:
    sql=serve(True);local=serve(False)
    with sync_playwright() as pw:
        api=pw.request.new_context(base_url=sql)
        body={'environment':'pruebas','operation_id':'http_contribution_0001','command':{'kind':'capital_contribution','amount':'3.00','currency':'USD','date':'2026-10-21','account_id':'cash','confirmed':True}}
        check('anonymous command denied',api.post('api.php?action=financial_command',data=body).status==401)
        csrf=login(api);headers={'X-CSRF-Token':csrf}
        check('CSRF mandatory',api.post('api.php?action=financial_command',data=body).status==403)
        a=api.post('api.php?action=financial_command',headers=headers,data=body);b=api.post('api.php?action=financial_command',headers=headers,data=body)
        check('authenticated contribution records once',a.status==200 and not a.json()['replayed'] and b.json()['replayed'])
        changed=json.loads(json.dumps(body));changed['command']['amount']='4.00'
        check('changed payload conflicts',api.post('api.php?action=financial_command',headers=headers,data=changed).status==409)
        check('JSON content type required',api.post('api.php?action=financial_command',headers={**headers,'Content-Type':'text/plain'},data=json.dumps(body)).status==415)
        check('oversized body rejected',api.post('api.php?action=financial_command',headers={**headers,'Content-Type':'application/json'},data=' '*65537).status==413)
        state=api.get('api.php?action=financial_state&environment=pruebas').json()
        check('projection returns exact SQL balance and subscriptions',state['ok'] and state['balances'][0]['balance'] in ['103.00','0.00'] and len(state['subs'])==1)
        fixture=json.loads((ROOT/'tests/fixtures/original-demo-v1.json').read_text(encoding='utf-8'))
        for key in ['sales','expenses','treasury','subs','products','customers','plans','projects','resources','suppliers','events','integrations','review','timeLogs','log','rates']:fixture[key]=[]
        fixture['settings']['baseCurrency']='USD';fixture['settings']['pin']=''
        fixture['settings']['currencies']=[{'code':'USD','symbol':'$'}]
        # The normalized fixture keeps all categories but uses its actual SQL brand.
        for category in fixture['settings']['categories']:category['brandId']='brand'
        if fixture['settings']['hourly'].get('currency'):fixture['settings']['hourly']['currency']='USD'
        fixture['settings']['accounts']=[{'id':'cash','name':'Cash','currency':'USD'}]
        fixture['settings']['brands']=[{'id':'brand','name':'Synthetic','color':'#112233','dark':'#000000','alt':'#ffffff','desc':''}]
        fixture['projects']=[{'id':'project','name':'App','brandId':'brand','kind':'suscripcion','status':'publicada','launchDate':'2020-01-01','stats':[]}]
        fixture['plans']=[{'id':'plan','projectId':'project','name':'Monthly','price':10,'currency':'USD','period':'mensual','trialDays':7}]
        fixture['customers']=[{'id':'customer','name':'Synthetic','email':'synthetic@example.invalid','createdAt':'2020-01-01','country':'AR'}]
        load=api.get('api.php?action=business_state&environment=pruebas').json()
        fixture['_catalogRevision']=load['state']['_catalogRevision']
        saved=api.post('api.php?action=business_state',headers=headers,data={'environment':'pruebas','operation_id':'state_http_fixture_00000001','expected_version':load['version'],'state':fixture})

        if saved.status!=200:print('Fixture rejection:',saved.json(),flush=True)
        check('nonfinancial fixture save',saved.status==200)
        browser=pw.chromium.launch(headless=True)
        context=browser.new_context(storage_state=api.storage_state(),bypass_csp=True)
        context.route('**/*',lambda route:route.continue_() if route.request.url.startswith(sql) else route.abort())
        page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        page.add_init_script("localStorage.setItem('eco-env','pruebas')")
        page.goto(sql,wait_until='domcontentloaded');page.wait_for_function('DataStore._financial===true && state.subs.length===1',timeout=15000)
        check('SQL financial state loaded on boot',page.evaluate('state.subs.length===1 && state.subs[0]._sqlVersion===6 && state.expenses[0].concept==="New rent"'))
        for route in ['pulso','marcas','apps','ventas','suscripciones','gastos','finanzas','infra','calendario','clientes','integraciones','reportes','ajustes']:
            page.evaluate('(r)=>go(r)',route);page.wait_for_timeout(70)
            check('original view '+route,page.locator('#view').count()==1)
        # Existing transaction callbacks route through SQL; no template changes needed.
        check('treasury callback reaches SQL',page.evaluate("""async()=>{const n=state.treasury.length;await transact('tes',st=>st.treasury.push({id:uid('tr'),type:'aporte',date:'2026-10-21',accountFrom:'',accountTo:'cash',amount:2,currency:'USD'}));return state.treasury.length===n+1;}"""))
        check('subscription creation callback reaches SQL',page.evaluate("""async()=>{await transact('suscripcion',st=>st.subs.push({id:'browser_sub',planId:'plan',projectId:'project',customerId:'customer',status:'prueba',startDate:'2026-10-21',events:[{id:uid('ev'),type:'prueba',date:'2026-10-21',planId:'plan'}]}));return state.subs.some(x=>x.id==='browser_sub'&&x._sqlVersion===1);} """))
        page.evaluate("subPaymentForm(state.subs.find(x=>x.id==='browser_sub'))")
        page.locator('#spF [name="accountId"]').select_option('cash')
        page.locator('.overlay [data-save]').last.click()
        page.wait_for_function("state.subs.find(x=>x.id==='browser_sub')._sqlVersion===2")
        check('original renewal form creates a linked SQL sale',page.evaluate("state.sales.filter(x=>x.subId==='browser_sub').length===1"))
        page.evaluate("while(modalStack.length)modalStack[0].close()")
        check('withdrawal and adjustment callbacks preserve SQL directions',page.evaluate("""async()=>{for(const [type,amount] of [['retiro',2],['ajuste',1]])await transact('tes',st=>st.treasury.push({id:uid('tr'),type,date:'2026-10-21',accountFrom:type==='retiro'?'cash':'',accountTo:type==='ajuste'?'cash':'',amount,currency:'USD'}));return state.treasury.some(x=>x.type==='retiro'&&x.accountFrom==='cash')&&state.treasury.some(x=>x.type==='ajuste'&&x.accountTo==='cash');}"""))
        check('concept sale and inline client use one atomic SQL command',page.evaluate("""async()=>{await transact('venta',st=>createSale(st,{_id:'http_concept',type:'unica',date:'2026-10-01',collectedDate:'2026-10-02',projectId:'project',brandId:'brand',concept:'Synthetic concept sale',currency:'USD',qty:1,unitPrice:10,payStatus:'cobrado',accountId:'cash'}, {name:'Inline sale client',email:'sale-client@example.invalid'}));return state.sales.some(x=>x.id==='http_concept'&&x._sqlVersion===2)&&state.treasury.some(x=>x.saleId==='http_concept'&&x.date==='2026-10-02');}"""))
        check('descriptive change on collected sale keeps its cash unchanged',page.evaluate("""async()=>{const before=JSON.stringify(state.treasury);await transact('venta',st=>{st.sales.find(x=>x.id==='http_concept').delivery='pendiente';});return state.sales.find(x=>x.id==='http_concept').delivery==='pendiente'&&JSON.stringify(state.treasury)===before;}"""))
        for total in [3,5]:
            page.evaluate("openSale('http_concept')")
            page.locator('[data-refund]').last.click()
            page.locator('.overlay input[name="amt"]').last.fill(str(total))
            page.locator('.overlay [data-yes]').last.click()
            page.wait_for_function("(amount)=>state.sales.find(x=>x.id===\"http_concept\").refunded===amount",arg=total)
        check('original cumulative refund form records only the increment',page.evaluate("state.sales.find(x=>x.id==='http_concept').refunded===5&&state.treasury.filter(t=>t.saleId==='http_concept').length===1"))
        page.evaluate("while(modalStack.length)modalStack[0].close()")
        check('single-line edit callback revises uncollected sale atomically',page.evaluate("""async()=>{await transact('venta',st=>createSale(st,{_id:'http_pending',date:'2026-10-01',projectId:'project',concept:'Pending synthetic sale',currency:'USD',qty:1,unitPrice:10,payStatus:'pendiente'}));await transact('venta',st=>{const x=st.sales.find(x=>x.id==='http_pending');x.qty=2;x.unitPrice=6;});return state.sales.find(x=>x.id==='http_pending')._sqlTotal==='12.00';}"""))
        check('recurring expense callback creates a fresh pending SQL period',page.evaluate("""async()=>{const e=state.expenses[0];await transact('gasto',st=>st.expenses.push({...clone(e),id:'http_next_expense',date:'2026-11-21',status:'pendiente',payDate:'',createdAt:new Date().toISOString()}));return state.expenses.some(x=>x.id==='http_next_expense'&&x._sqlVersion===1)&&!state.treasury.some(t=>t.expenseId==='http_next_expense');}"""))
        check('expense allocation edit reaches SQL without changing payments',page.evaluate("""async()=>{const before=JSON.stringify(state.treasury);await transact('gasto',st=>{st.expenses.find(x=>x.id==='http_next_expense').alloc={mode:'single',targets:[{type:'project',id:'project',pct:null}]};});return JSON.stringify(state.treasury)===before&&state.expenses.find(x=>x.id==='http_next_expense').alloc.targets[0].id==='project';}"""))
        check('inline subscription customer survives authoritative SQL projection',page.evaluate("""async()=>{await transact('suscripcion',st=>{st.customers.push({id:'http_sub_customer',name:'Inline subscription client',email:'sub-client@example.invalid',country:'AR',createdAt:'2026-10-21',notes:''});st.subs.push({id:'http_inline_sub',projectId:'project',planId:'plan',customerId:'http_sub_customer',status:'prueba',startDate:'2026-10-21',events:[{id:uid('ev'),type:'prueba',date:'2026-10-21',planId:'plan'}]});});await refreshFinancial();return state.subs.some(x=>x.id==='http_inline_sub'&&x.customerId==='http_sub_customer');}"""))
        # Simulate a committed operation whose HTTP acknowledgement is lost.
        def lose_ack(route):
            route.fetch();route.abort()
        page.route('**/api.php?action=financial_command',lose_ack,times=1)
        check('uncertain response retains the operation token',page.evaluate("""async()=>{try{await transact('tes',st=>st.treasury.push({id:uid('tr'),type:'aporte',date:'2026-10-21',accountTo:'cash',amount:1,currency:'USD'}));return false;}catch(e){return !!sessionStorage.getItem('centro-pending-financial-pruebas');}}"""))
        before_recovery=api.get('api.php?action=financial_state&environment=pruebas').json()['treasury']
        check('retry reconciles the committed operation',page.evaluate("""async()=>{try{await transact('tes',st=>st.treasury.push({id:uid('tr'),type:'aporte',date:'2026-10-21',accountTo:'cash',amount:1,currency:'USD'}));return false;}catch(e){return e.financialCommitted===true&&!sessionStorage.getItem('centro-pending-financial-pruebas');}}"""))
        after_recovery=api.get('api.php?action=financial_state&environment=pruebas').json()['treasury']
        check('lost acknowledgement does not duplicate cash',before_recovery==after_recovery)
        check('unsafe deletion blocked without local divergence',page.evaluate("""async()=>{const old=JSON.stringify(state.treasury);try{await transact('tes',st=>st.treasury=[]);return false;}catch(e){return JSON.stringify(state.treasury)===old;}}"""))
        page.reload(wait_until='domcontentloaded');page.wait_for_timeout(200)
        check('SQL persists after reload',page.evaluate("state.subs.some(x=>x.id==='browser_sub')"))
        check('inline customer names and edited metadata survive reload',page.evaluate("state.customers.some(x=>x.id==='http_sub_customer'&&x.name==='Inline subscription client')&&state.sales.find(x=>x.id==='http_concept').delivery==='pendiente'&&state.sales.find(x=>x.id==='http_concept').refunded===5"))
        # An independent authenticated session must see the same normalized operations.
        relog=pw.request.new_context(base_url=sql);login(relog)
        recovered=relog.get('api.php?action=financial_state&environment=pruebas').json()
        check('financial and subscription state survives a new login',any(x['id']=='http_inline_sub' for x in recovered['subs']) and any(x['id']=='http_pending' and x['_sqlTotal']=='12.00' for x in recovered['sales']))
        page.screenshot(path=str(OUT/'sql-dashboard.png'),full_page=True)
        check('no JavaScript runtime errors in 13 views',not errors)
        other=pw.request.new_context(base_url=local);login(other)
        check('SQL disabled endpoint remains closed',other.post('api.php?action=financial_command',data=body).status==404)
        lc=browser.new_context(storage_state=other.storage_state(),bypass_csp=True);lc.route('**/*',lambda route:route.continue_() if route.request.url.startswith(local) else route.abort());lp=lc.new_page();lp.add_init_script("localStorage.setItem('eco-env','pruebas')")
        lp.goto(local,wait_until='domcontentloaded');lp.wait_for_timeout(200)
        check('IndexedDB path remains enabled',lp.evaluate('DataStore._remote===false && DataStore._financial===false'))
        lp.evaluate("""async()=>{state=emptyState();state.setupDone=true;await transact('tes',st=>st.treasury.push({id:'local_only',type:'aporte',amount:7,currency:'USD',date:'2026-10-21',accountTo:'cash'}));} """)
        lp.reload(wait_until='domcontentloaded');lp.wait_for_timeout(200)
        check('IndexedDB survives reload',lp.evaluate("state.treasury.some(x=>x.id==='local_only')"))
        csrf2=other.get('api.php?action=session').json()['csrf'];other.post('api.php?action=logout',headers={'X-CSRF-Token':csrf2},data={})
        check('logout expires server authorization',other.get('api.php?action=financial_state&environment=pruebas').status==404)
        legacy_cfg,legacy_env=fresh(last=16)
        legacy_env['CENTRO_PRIVATE_DIR']=str(OUT/('legacy-private-'+secrets.token_hex(6)))
        created=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/create_admin.php'),'--email=operations@example.invalid','--password-stdin'],input=password+'\n',cwd=ROOT,env=legacy_env,capture_output=True,text=True,creationflags=db.FLAGS)
        assert created.returncode==0,'legacy synthetic administrator initialization'
        legacy=serve(True,legacy_env);legacy_api=pw.request.new_context(base_url=legacy);legacy_csrf=login(legacy_api)
        preserved=json.loads(json.dumps(fixture));preserved['subs']=[{'id':'legacy_unmigrated','projectId':'project','planId':'plan','customerId':'customer','status':'activa','startDate':'2026-01-01','nextRenewal':'2026-11-01','events':[]}]
        saved=legacy_api.post('api.php?action=business_state',headers={'X-CSRF-Token':legacy_csrf},data={'environment':'pruebas','operation_id':'state_legacy_fixture_000001','expected_version':0,'state':preserved})
        check('schema 016 accepts a preserved nonfinancial snapshot',saved.status==200)
        legacy_context=browser.new_context(storage_state=legacy_api.storage_state(),bypass_csp=True);legacy_context.route('**/*',lambda route:route.continue_() if route.request.url.startswith(legacy) else route.abort())
        legacy_page=legacy_context.new_page();legacy_page.add_init_script("localStorage.setItem('eco-env','pruebas')");legacy_page.goto(legacy,wait_until='domcontentloaded')
        legacy_page.wait_for_function("DataStore._financial===true&&state.subs.some(x=>x.id==='legacy_unmigrated')")
        check('schema 016 preserves unmigrated subscriptions on load',legacy_page.evaluate("DataStore._operational===false&&state.subs.some(x=>x.id==='legacy_unmigrated')"))
        legacy_page.evaluate("async()=>{await transact('settings',st=>{st.settings.business.name='Synthetic legacy update';});}")
        check('unrelated save does not erase unmigrated subscriptions',legacy_api.get('api.php?action=business_state&environment=pruebas').json()['state']['subs']==preserved['subs'])
        restart_api=pw.request.new_context(base_url=sql)
        restart_csrf=login(restart_api)
        before_restart=restart_api.get('api.php?action=financial_state&environment=pruebas').json()
        business_before_restart=restart_api.get('api.php?action=business_state&environment=pruebas').json()
        signed_out=restart_api.post('api.php?action=logout',headers={'X-CSRF-Token':restart_csrf},data={})
        check('explicit logout closes the synthetic SQL session',signed_out.status==200 and restart_api.get('api.php?action=financial_state&environment=pruebas').status==401)
        previous_pid,new_pid=restart_server(sql)
        check('only the isolated PHP test server is restarted',previous_pid!=new_pid)
        login(restart_api)
        after_restart=restart_api.get('api.php?action=financial_state&environment=pruebas').json()
        check('complete financial history and balances persist after logout and server restart',before_restart==after_restart)
        check('business_state persists independently after server restart',business_before_restart==restart_api.get('api.php?action=business_state&environment=pruebas').json())
        # Each financial row stays in its own SQL source of truth.
        snapshot=restart_api.get('api.php?action=business_state&environment=pruebas').json()['state']
        check('business_state contains no duplicate SQL financial collections',all(snapshot.get(k,[])==[] for k in ['sales','expenses','treasury','subs']))
        browser.close()
    (OUT/'http-results.json').write_text(json.dumps({'checks':checks},indent=2),encoding='utf-8')
finally:
    for proc in servers:
        if proc.poll() is None:proc.terminate()
        proc.wait(timeout=10)
    for log in logs:log.close()
