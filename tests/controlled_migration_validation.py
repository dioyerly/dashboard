"""Synthetic-only end-to-end local backup, SQL staging and reversible preview."""
import copy
import hashlib
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import sys
import time
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
sys.path[:0]=[str(ROOT/'tools'),str(ROOT/'tests')]
import local_mariadb as db
import backup_local as backup
from backup_import import fixture
from controlled_migration import stage
from run_operational_completion import fresh
OUT=ROOT/'.runtime'/('controlled-validation-'+secrets.token_hex(6));OUT.mkdir(parents=True)
checks=[];servers=[];logs=[]
def check(name,ok):
    checks.append({'check':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    (OUT/'results.json').write_text(json.dumps({'checks':checks},indent=2))
    assert ok,name

def serve(env,port=None):
    if port is None:
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    log=open(OUT/('server-'+secrets.token_hex(4)+'.log'),'w');logs.append(log)
    process=subprocess.Popen(db.PHP_ARGS+['-S','127.0.0.1:'+str(port),'-t',str(ROOT/'public')],cwd=ROOT,env=env,stdout=log,stderr=log,creationflags=db.FLAGS);servers.append(process)
    for _ in range(60):
        if process.poll() is not None:raise RuntimeError('synthetic_server_stopped')
        try:
            with socket.create_connection(('127.0.0.1',port),timeout=.2):return process,port,'http://127.0.0.1:'+str(port)+'/'
        except OSError:time.sleep(.1)
    raise RuntimeError('synthetic_server_timeout')

def stop(process):
    if process.poll() is None:process.terminate()
    process.wait(timeout=10)

def login(request,base,email,password):
    session=request.get(base+'api.php?action=session');assert session.status==200,session.status
    response=request.post(base+'api.php?action=login',headers={'X-CSRF-Token':session.json()['csrf']},data={'email':email,'password':password});assert response.status==200,response.status
    return request.get(base+'api.php?action=session').json()['csrf']

def private_validate(path,entry='indexedDB:estado-pruebas'):
    output=OUT/('validation-'+secrets.token_hex(4)+'.json')
    return subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/controlled_backup.php'),'--source='+str(path),'--output='+str(output),'--entry='+entry,'--environment=pruebas'],cwd=ROOT,capture_output=True,text=True,creationflags=db.FLAGS)

def resign(archive):
    for e in archive['snapshots']:e['sha256']=hashlib.sha256(e['payload'].encode()).hexdigest();e['bytes']=len(e['payload'].encode())
    bound={'format':archive['format'],'version':1,'exportedAt':archive['exportedAt'],'entries':[{k:v for k,v in e.items() if k!='payload'} for e in archive['snapshots']],'preferences':archive['preferences'],'redactions':archive['redactions']}
    payload=json.dumps(bound,ensure_ascii=False,separators=(',',':'));archive['manifest']={'algorithm':'SHA-256','payload':payload,'sha256':hashlib.sha256(payload.encode()).hexdigest()}

cfg,env=fresh();env.update({'CENTRO_PRIVATE_DIR':str(OUT/'source-auth'),'CENTRO_BUSINESS_API_ENABLED':'0','CENTRO_BUSINESS_STORAGE':'local','CENTRO_FINANCIAL_API_ENABLED':'0'})
password=secrets.token_urlsafe(24);email='source-backup@example.invalid'
created=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/create_admin.php'),'--email='+email,'--password-stdin'],input=password+'\n',cwd=ROOT,env=env,text=True,capture_output=True,creationflags=db.FLAGS);assert created.returncode==0
source=fixture();source['settings']['business']['name']='Synthetic caf\u00e9 \u00f1';source['rates']=[{'id':'rate_one','currency':'ARS','date':'2026-10-16','rate':'0.000000000123456789','source':'manual'}];source['resources']=[{'id':'res_hist','name':'Synthetic resource','cost':'1.2345','currency':'USD','projectIds':['pr_one']}]
source['events']=[{'id':'calendar_hist','date':'2026-10-16','title':'Synthetic calendar','nested':{'order':[2,1,2]}}]
source['review']=[{'id':'review_hist','note':'Synthetic review','nested':{'value':0.12345678901234567}}]
source['suppliers']=[{'id':'supplier_one','name':'Synthetic supplier','kind':'hosting','url':'','contact':'','taxId':'','notes':''}]
source['timeLogs']=[{'id':'time_one','projectId':'pr_one','date':'2026-10-16','hours':'1.25','kind':'desarrollo','note':''}]
source['integrations']=[{'id':'integration_one','name':'Synthetic integration','kind':'manual','method':'manual','status':'activa','projectId':'pr_one','lastSync':None,'errors':[],'stats':{'ratio':0.125},'notes':''}]
source['subs']=[{'id':'sub_one','projectId':'pr_one','planId':'pl_one','customerId':'cu_one','status':'prueba','startDate':'2026-10-16','nextRenewal':'2026-10-23','origin':'manual','createdAt':'2026-10-16T00:00:00Z','events':[{'id':'sub_event','date':'2026-10-16','type':'prueba','planId':'pl_one'}]}]
try:
    process,port,base=serve(env)
    with sync_playwright() as pw:
        api=pw.request.new_context();login(api,base,email,password)
        browser=pw.chromium.launch(headless=True);context=browser.new_context(storage_state=api.storage_state(),bypass_csp=True)
        context.route('**/*',lambda route:route.continue_() if route.request.url.startswith(base) or route.request.url.startswith('blob:') else route.abort())
        page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.add_init_script("localStorage.setItem('eco-env','pruebas')")
        page.goto(base,wait_until='domcontentloaded');page.wait_for_function('DataStore._remote===false')
        page.evaluate("""async(value)=>{state=structuredClone(value);createSale(state,{_id:'sale_hist',date:'2026-10-16',productId:'pd_one',versionId:'vr_one',customerId:'cu_one',currency:'USD',qty:1,unitPrice:10.25,payStatus:'cobrado',accountId:'ac_one'});state.expenses.push({id:'expense_hist',concept:'Synthetic expense',amount:2.5,currency:'USD',date:'2026-10-16',status:'pagado',accountId:'ac_one',payDate:'2026-10-16',alloc:{mode:'general',targets:[]}});state.treasury.push({id:'payment_hist',date:'2026-10-16',type:'pago',expenseId:'expense_hist',accountFrom:'ac_one',amount:2.5,currency:'USD'});await DataStore.save(state);const db=await DataStore._open();await new Promise((resolve,reject)=>{const tx=db.transaction(DataStore.STORE,'readwrite');tx.objectStore(DataStore.STORE).put({...structuredClone(state),settings:{...state.settings,business:{...state.settings.business,name:'Other synthetic environment'}}},'estado-produccion');tx.oncomplete=resolve;tx.onerror=reject;});db.close();localStorage.setItem('co-estado-pruebas',JSON.stringify({...structuredClone(state),settings:{...state.settings,business:{...state.settings.business,name:'Divergent fallback retained'}}}));localStorage.setItem('co-estado-produccion',JSON.stringify(state));localStorage.setItem('private-session','PRIVATE-SESSION-EXCLUDED');sessionStorage.setItem('private-token','PRIVATE-TOKEN-EXCLUDED');unlocked=true;go('ajustes');}""",source)
        original_balance=page.evaluate("accountBalance('ac_one')")
        storage_hash="async()=>ControlledBackup.hash(JSON.stringify(await ControlledBackup.localCopies()))"
        before=page.evaluate(storage_hash)
        with page.expect_download() as download:page.locator('[data-act="backup"]').click()
        archive_path=OUT/'downloaded-backup.json';download.value.save_as(str(archive_path));raw=archive_path.read_bytes();archive=json.loads(raw)
        check('complete download contains both environments, both stores and visible state',len(archive['snapshots'])==5)
        check('export never writes to the original business storage',page.evaluate(storage_hash)==before)
        check('PIN session keys and tokens are excluded without modifying originals',all(x not in raw.decode() for x in ['PRIVATE-OLD-PIN','PRIVATE-SESSION-EXCLUDED','PRIVATE-TOKEN-EXCLUDED']) and page.evaluate("state.settings.pin==='PRIVATE-OLD-PIN'"))
        check('browser verifies every snapshot and manifest hash',page.evaluate('async a=>!!(await ControlledBackup.verify(a))',archive))
        check('PHP independently validates the downloaded archive',private_validate(archive_path).returncode==0)
        compared=private_validate(archive_path);report=json.loads(Path(json.loads(compared.stdout)['private_report']).read_text(encoding='utf-8'))['source_comparison']
        check('local source comparison never selects merges or writes',report['selection'] is None and not report['merge_performed'] and not report['sources_modified'])
        check('source comparison separates environments and identifies settings differences',len(report['pairs'])==4 and any(d['collection']=='settings' for pair in report['pairs'] for d in pair['differences']))
        changed_sources=copy.deepcopy(archive);entry=next(e for e in changed_sources['snapshots'] if e['id']=='localStorage:co-estado-pruebas');value=json.loads(entry['payload']);value['customers'][0]['name']='Different copied customer';value['products'].reverse();value['customers'].append(copy.deepcopy(value['customers'][0]));entry['payload']=json.dumps(value,ensure_ascii=False);resign(changed_sources);path=OUT/'compared-copies.json';path.write_text(json.dumps(changed_sources));compared=private_validate(path);differences=json.loads(Path(json.loads(compared.stdout)['private_report']).read_text(encoding='utf-8'))['source_comparison']['pairs']
        check('comparison identifies duplicate IDs changed content and original ordering',any(d.get('duplicate_identity') for pair in differences for d in pair['differences']) and any(d['collection']=='products' for pair in differences for d in pair['differences']))
        check('all payload hashes match UTF-8 bytes',all(hashlib.sha256(e['payload'].encode()).hexdigest()==e['sha256'] and len(e['payload'].encode())==e['bytes'] for e in archive['snapshots']))
        damaged=copy.deepcopy(archive);damaged['snapshots'][0]['payload']+=' '
        path=OUT/'damaged.json';path.write_text(json.dumps(damaged));check('modified payload is rejected before SQL',private_validate(path).returncode!=0)
        duplicate=copy.deepcopy(archive);duplicate['snapshots'][0]['payload']=duplicate['snapshots'][0]['payload'].replace('"version":1','"version":1,"version":1',1);resign(duplicate)
        path=OUT/'duplicate.json';path.write_text(json.dumps(duplicate));check('duplicate properties are rejected despite recomputed hashes',private_validate(path).returncode!=0)
        secret=copy.deepcopy(archive);payload=json.loads(secret['snapshots'][0]['payload']);payload['settings']['password']='PRIVATE-REJECT';secret['snapshots'][0]['payload']=json.dumps(payload,separators=(',',':'));resign(secret)
        path=OUT/'secret.json';path.write_text(json.dumps(secret));check('private values are rejected despite recomputed hashes',private_validate(path).returncode!=0)
        result=stage(archive_path,'indexedDB:estado-pruebas','pruebas',OUT/'sql-stage',exercise_rollback=True)
        check('full SQL preflight import independent export and field comparison pass',result['ok'] and not result['full_activation_allowed'])
        access=json.loads(Path(result['private_access']).read_text(encoding='utf-8'));plan=json.loads(Path(result['private_plan']).read_text(encoding='utf-8'));target=Path(access['target']);selected=Path(OUT/'sql-stage/selected-source.json').read_bytes()
        check('late partial failure rolls back before successful retry',json.loads((OUT/'sql-stage/rollback.json').read_text(encoding='utf-8'))['ok'])
        check('historical collections are explicitly not counted as operational',set(['sales','expenses','treasury','resources','events','review','log']).issubset(plan['historical_collections']))
        check('source disagreement and incomplete authority block full activation',any(b['code']=='source_copies_disagree_explicit_selection_preserved' for b in plan['blockers']) and not plan['full_activation_allowed'])
        sql_export=json.loads((OUT/'sql-stage/independent-sql-export.json').read_text(encoding='utf-8'))
        check('exact decimal snapshots and nested ordering survive SQL',any('10.25' in r['payload'] for r in sql_export['normalized_entities']['original_financial_snapshots']) and any(json.loads(r['payload']).get('nested',{}).get('order')==[2,1,2] for r in sql_export['normalized_entities']['original_activity_snapshots']))
        check('exchange rates keep all 18 decimal places',sql_export['normalized_entities']['exchange_rates'][0]['rate']=='0.000000000123456789')
        replay=backup.import_backup(target,selected,'pruebas',plan['operation']);check('repeat import returns the durable receipt without duplicate rows',replay['ok'] and replay['data']['replayed'])
        check('different operation cannot merge into the occupied target',backup.import_backup(target,selected,'pruebas','import_'+secrets.token_hex(16)).get('code')=='destination_not_empty')
        changed=json.loads(selected);changed['projects'][0]['name']='Changed synthetic name';changed_raw=json.dumps(changed).encode();check('same operation with changed source conflicts',backup.import_backup(target,changed_raw,'pruebas',plan['operation']).get('code')=='idempotency_conflict')
        count=db.sql(f"SELECT COUNT(*) FROM `{access['config']['name']}`.fin_movements;");check('historical cash never creates live financial movements',count=='0')
        stop(process);preview=access['environment'].copy();preview['CENTRO_CONTROLLED_PLAN']=result['private_plan'];preview['CENTRO_CONTROLLED_MODE']='preview'
        process,_,_=serve(preview,port);login(context.request,base,access['email'],access['password']);page.reload(wait_until='domcontentloaded');page.wait_for_function("DataStore._readOnly===true&&DataStore._remote===true&&state.sales.some(x=>x.id==='sale_hist')")
        check('authority can enter an explicit read-only SQL preview',page.evaluate("state.sales.some(x=>x.id==='sale_hist')&&state.subs.some(x=>x.id==='sub_one')"))
        check('original displayed balances are preserved without executing cash',original_balance==7.75 and page.evaluate("accountBalance('ac_one')")==original_balance)
        check('Unicode business configuration survives independent SQL staging',page.evaluate("state.settings.business.name")==source['settings']['business']['name'])
        csrf=context.request.get(base+'api.php?action=session').json()['csrf']
        body={'environment':'pruebas','operation_id':'state_preview_1234567890123456','expected_version':1,'state':json.loads(selected)}
        check('server rejects writes even when client controls are bypassed',context.request.post(base+'api.php?action=business_state',headers={'X-CSRF-Token':csrf},data=body).status==423)
        check('client does not fall back to a second local writer',page.evaluate("async()=>{try{await DataStore.save(state);return false;}catch(e){return true;}}") and page.evaluate(storage_hash)==before)
        for route in ['pulso','marcas','apps','ventas','suscripciones','gastos','finanzas','infra','calendario','clientes','integraciones','reportes','ajustes']:
            page.evaluate('(r)=>go(r)',route);page.wait_for_timeout(50);check('unchanged view '+route,page.locator('#view').count()==1)
        check('all original views run without JavaScript errors',not errors)
        check('SQL preview has no financial API writer',context.request.get(base+'api.php?action=session').json()['financial_api_enabled'] is False)
        check('server also rejects financial commands in preview',context.request.post(base+'api.php?action=financial_command',headers={'X-CSRF-Token':csrf},data={'environment':'pruebas','operation_id':'blocked_preview_123456','command':{'kind':'capital_contribution'}}).status==423)
        name=access['config']['name'];db.sql(f"UPDATE `{name}`.projects SET name='Synthetic changed target' WHERE id='pr_one';")
        check('changed SQL target invalidates the verified authority plan',context.request.get(base+'api.php?action=session').status==503)
        db.sql(f"UPDATE `{name}`.projects SET name='Project' WHERE id='pr_one';")
        saved=context.request.get(base+'api.php?action=business_state&environment=pruebas').json()
        context.request.post(base+'api.php?action=logout',headers={'X-CSRF-Token':csrf},data={});stop(process);process,_,_=serve(preview,port)
        login(context.request,base,access['email'],access['password']);check('verified SQL preview survives logout and server restart',context.request.get(base+'api.php?action=business_state&environment=pruebas').json()==saved)
        stop(process);activation=preview.copy();activation['CENTRO_CONTROLLED_MODE']='activate';process,_,_=serve(activation,port)
        check('full activation remains blocked server-side',context.request.get(base+'api.php?action=session').status==503)
        check('unknown server authority cannot silently save into IndexedDB',page.evaluate("async()=>{DataStore._remote=null;try{await DataStore.save(state);return false;}catch(e){return true;}}") and page.evaluate(storage_hash)==before)
        stop(process);rollback=preview.copy();rollback['CENTRO_CONTROLLED_MODE']='rollback';process,_,_=serve(rollback,port)
        login(context.request,base,access['email'],access['password']);page.reload(wait_until='domcontentloaded');page.wait_for_function("DataStore._remote===false&&state.settings.pin==='PRIVATE-OLD-PIN'&&state.sales.some(x=>x.id==='sale_hist')")
        check('rollback restores the original local authority without copying SQL cash',page.evaluate(storage_hash)==before and page.evaluate("state.settings.pin==='PRIVATE-OLD-PIN'&&state.sales.some(x=>x.id==='sale_hist')"))
        fresh_context=browser.new_context(bypass_csp=True);fresh_context.route('**/*',lambda route:route.continue_() if route.request.url.startswith(base) else route.abort());login(fresh_context.request,base,access['email'],access['password'])
        restored=fresh_context.new_page();restored.add_init_script("localStorage.setItem('eco-env','pruebas')");restored.goto(base,wait_until='domcontentloaded');restored.wait_for_function('DataStore._remote===false');restored.evaluate("state.setupDone=true;go('ajustes')")
        restored.locator('#restoreF').set_input_files(str(archive_path));restored.locator('[data-yes]').last.click();restored.wait_for_function("state.sales.some(x=>x.id==='sale_hist')")
        visible=next(e for e in archive['snapshots'] if e['id']=='visible:pruebas')
        check('existing restore control recovers the complete verified business state',restored.evaluate('async()=>ControlledBackup.hash(JSON.stringify(await DataStore.load()))')==visible['sha256'])
        changed=copy.deepcopy(archive)
        for e in changed['snapshots']:
            if e['id']=='visible:pruebas':data=json.loads(e['payload']);data['settings']['business']['name']='Different backup';e['payload']=json.dumps(data,separators=(',',':'))
        resign(changed)
        check('restoration refuses to overwrite different existing data',restored.evaluate('async a=>{try{await ControlledBackup.restoreState(a);return false;}catch(e){return true;}}',changed))
        check('independent downloaded backup remains byte-identical',archive_path.read_bytes()==raw and (OUT/'sql-stage/original-archive.json').read_bytes()==raw)
        check('field inventory distinguishes JSON arrays and objects',any(x['path']==['settings'] and x['type']=='object' for x in json.loads((OUT/'sql-stage/verified-archive.json').read_text(encoding='utf-8'))['inventory']) and any(x['path']==['sales'] and x['type']=='array' for x in json.loads((OUT/'sql-stage/verified-archive.json').read_text(encoding='utf-8'))['inventory']))
        check('duplicate local JSON is detected by the browser parser',restored.evaluate("()=>{try{ControlledBackup.parse('{\"version\":1,\"version\":1}');return false;}catch(e){return true;}}"))
        empty_context=browser.new_context(bypass_csp=True)
        empty_context.route('**/*',lambda route:route.continue_() if route.request.url.startswith(base) else route.abort())
        empty_page=empty_context.new_page();empty_page.goto(base+'login.php',wait_until='domcontentloaded')
        empty_page.add_script_tag(content=(ROOT/'public/assets/js/00-utilities.js').read_text(encoding='utf-8'))
        empty_page.add_script_tag(content=(ROOT/'public/assets/js/01-storage-domain.js').read_text(encoding='utf-8'))
        check('export does not create a missing IndexedDB database',empty_page.evaluate("async()=>{const before=JSON.stringify(await indexedDB.databases());await ControlledBackup.create();return before===JSON.stringify(await indexedDB.databases());}"))
        browser.close()
    print('PASS controlled migration checks:',len(checks),'private evidence:',OUT,flush=True)
finally:
    for process in servers:stop(process)
    for log in logs:log.close()
