"""Isolated SQL/API tests. Never writes the dashboard's IndexedDB or review database."""
import concurrent.futures
import contextlib
import hashlib
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import sys
import time
import urllib.request
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import local_mariadb as db
OUT=Path(os.environ.get('CENTRO_FOUNDATION_OUTPUT',str(ROOT/'preparation/phase4b-foundation')))
OUT.mkdir(exist_ok=True)


def php(code,env,payload=None):
    x=subprocess.run(db.PHP_ARGS+['-r',code],env=env,cwd=ROOT,input=json.dumps(payload) if payload is not None else None,capture_output=True,text=True)
    if x.returncode:raise RuntimeError('Isolated PHP test failed: '+x.stderr[:1500])
    return x.stdout


@contextlib.contextmanager
def server(env):
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    log=open(Path(env['CENTRO_PRIVATE_DIR'])/'http.log','a')
    p=subprocess.Popen(db.PHP_ARGS+['-S','127.0.0.1:'+str(port),'-t',str(ROOT/'public')],env=env,stdout=log,stderr=log,creationflags=db.FLAGS)
    base='http://127.0.0.1:'+str(port)+'/'
    try:
        for _ in range(80):
            try:urllib.request.urlopen(base+'login.php',timeout=.5).close();break
            except OSError:time.sleep(.1)
        else:raise RuntimeError('Test HTTP startup failed')
        yield base
    finally:p.terminate();p.wait(timeout=10);log.close()


def main():
    checks=[]
    def check(name,value):
        checks.append({'check':name,'passed':bool(value)});print(('PASS: ' if value else 'FAIL: ')+name,flush=True);assert value,name
    db.start();cfg=db.database();env=os.environ.copy();env.update(db.environment(cfg))
    private=ROOT/'storage/private'/('foundation-'+secrets.token_hex(8));private.mkdir(parents=True)
    env.update(CENTRO_PRIVATE_DIR=str(private),CENTRO_BUSINESS_API_ENABLED='1')
    password=secrets.token_urlsafe(24);email='foundation@ejemplo.test'
    x=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/create_admin.php'),'--email='+email,'--password-stdin'],input=password+'\n',env=env,capture_output=True,text=True);assert x.returncode==0
    backup=db.RUNTIME/('before-foundation-'+secrets.token_hex(8)+'.sql')
    with backup.open('wb') as file:
        x=subprocess.run([str(db.RUNTIME/'bin/mariadb-dump.exe'),'--defaults-file='+str(db.RUNTIME/'client.ini'),'--protocol=tcp','--host=127.0.0.1','--port='+str(db.state()['port']),'--skip-ssl','--single-transaction',cfg['name']],stdout=file,stderr=subprocess.PIPE)
    check('Private destination SQL backup created before migration',x.returncode==0 and backup.stat().st_size>100)
    Path(str(backup)+'.json').write_text(json.dumps({'database':cfg['name'],'sha256':hashlib.sha256(backup.read_bytes()).hexdigest()}))
    rootenv=env.copy();rootenv.update(db.environment({'name':cfg['name'],'user':'root','password':db.state()['root_password']}))
    x=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/migrate_business_local.php')],env=rootenv,cwd=ROOT,capture_output=True,text=True)
    check('Missing destination backup blocks migration before DDL',x.returncode!=0 and db.sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='"+cfg['name']+"';")=='3')
    for attempt in range(2):
        x=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/migrate_business_local.php'),'--backup='+str(backup)],env=rootenv,cwd=ROOT,capture_output=True,text=True)
        check('PDO migration execution' if attempt==0 else 'Migration retry verifies checksum without reapplying',x.returncode==0)
    # Application permissions exclude physical deletion and alteration of schema/receipts.
    for table in ['currencies','brands','channels','projects','customers','accounts','business_settings']:
        db.sql(f"GRANT SELECT,INSERT,UPDATE ON `{cfg['name']}`.{table} TO '{cfg['user']}'@'127.0.0.1';")
    for table in ['business_operations','business_audit']:
        db.sql(f"GRANT SELECT,INSERT ON `{cfg['name']}`.{table} TO '{cfg['user']}'@'127.0.0.1';")
    db.sql(f"GRANT SELECT ON `{cfg['name']}`.environments TO '{cfg['user']}'@'127.0.0.1';")
    x=subprocess.run(db.PHP_ARGS+[str(ROOT/'tests/business_foundation.php')],env=rootenv,cwd=ROOT,capture_output=True,text=True)
    if x.returncode:raise RuntimeError(x.stderr[:2000])
    core=json.loads(x.stdout);(OUT/'pdo-results.json').write_text(json.dumps(core,indent=2))
    check('Real service/constraint/rollback suite',all(c['passed'] for c in core['checks']))
    code="require 'app/bootstrap.php'; require 'app/Business.php'; $s=new Centro\\Business(Centro\\Database::connection($config['db'])); $b=json_decode(stream_get_contents(STDIN),true); try {echo json_encode($s->command('test',$b['token'],1,$b['command']));} catch(Centro\\BusinessError $e){echo json_encode(['error'=>$e->reason]);}"
    token=secrets.token_hex(16)
    change={'resource':'customers','action':'create','id':'concurrent','expected_version':0,'data':{'name':'Concurrent','origin':'manual'}}
    payload={'token':token,'command':{'changes':[change]}}
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:results=list(pool.map(lambda _:json.loads(php(code,env,payload)),range(8)))
    check('Eight concurrent retries create exactly one result and seven replays',sum(x.get('replayed') is False for x in results)==1 and sum(x.get('replayed') is True for x in results)==7)
    edits=[{'token':secrets.token_hex(16),'command':{'changes':[{'resource':'brands','action':'update','id':'br_test','expected_version':1,'data':{'name':'Concurrent '+str(i)}}]}} for i in range(2)]
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:results=list(pool.map(lambda p:json.loads(php(code,env,p)),edits))
    check('Two simultaneous editors produce one commit and one conflict',sum('result' in x for x in results)==1 and sum(x.get('error')=='version_conflict' for x in results)==1)
    before=php("require 'app/bootstrap.php'; $p=Centro\\Database::connection($config['db']);echo $p->query('SELECT COUNT(*) FROM business_operations')->fetchColumn();",env)
    db.stop()
    try:db.start()
    finally:db.start()
    after=php("require 'app/bootstrap.php'; $p=Centro\\Database::connection($config['db']);echo $p->query('SELECT COUNT(*) FROM business_operations')->fetchColumn();",env)
    check('Committed commands survive MariaDB and PHP process restart',before==after)
    with sync_playwright() as pw,server(env) as base:
        a=pw.request.new_context();b=pw.request.new_context()
        endpoint=base+'api.php?action=business_command';listing=base+'api.php?action=business_list&environment=test&resource=customers'
        check('Anonymous business API requires authentication',a.get(listing).status==401)
        for req in [a,b]:
            token=req.get(base+'api.php?action=session').json()['csrf']
            assert req.post(base+'api.php?action=login',headers={'X-CSRF-Token':token},data={'email':email,'password':password}).status==200
        token=a.get(base+'api.php?action=session').json()['csrf'];headers={'X-CSRF-Token':token}
        check('Business write rejects missing CSRF',a.post(endpoint,data={}).status==403)
        check('Business write enforces POST',a.get(endpoint).status==405)
        check('Business write enforces JSON content type',a.post(endpoint,headers={**headers,'Content-Type':'text/plain'},data='{}').status==415)
        check('Malformed JSON rejected',a.post(endpoint,headers={**headers,'Content-Type':'application/json'},data='{').status==400)
        check('Oversized JSON rejected',a.post(endpoint,headers={**headers,'Content-Type':'application/json'},data=' '*65537).status==413)
        data={'environment':'test','operation_id':secrets.token_hex(16),'command':{'changes':[{'resource':'customers','id':'http_cu','action':'create','expected_version':0,'data':{'name':'HTTP customer','origin':'manual'}}]}}
        response=a.post(endpoint,headers=headers,data=data)
        check('Authenticated API commits command',response.status==200 and response.json()['ok'])
        check('Second session reads committed record',any(x['id']=='http_cu' for x in b.get(listing).json()['records']))
        check('HTTP retry returns same committed result',a.post(endpoint,headers=headers,data=data).json()['replayed'])
        data['command']['changes'][0]['data']['name']='Different payload'
        check('HTTP token payload conflict returns controlled 409',a.post(endpoint,headers=headers,data=data).status==409)
        data['operation_id']=secrets.token_hex(16);data['command']['changes'][0]['data']['name']='Duplicate ID'
        error_response=a.post(endpoint,headers=headers,data=data)
        check('HTTP stale/create conflict is controlled',error_response.json()['code']=='version_conflict')
        check('Dashboard still reports exclusively local business storage',a.get(base+'api.php?action=session').json()['business_storage']=='local' and a.get(base+'api.php?action=session').json()['mysql_sync'] is False)
        check('API errors never include SQL or database credentials',all(secret not in error_response.text() for secret in [cfg['password'],'SQLSTATE','mysql:host']))
    disabled=env.copy();disabled['CENTRO_BUSINESS_API_ENABLED']='0'
    with sync_playwright() as pw,server(disabled) as base:
        a=pw.request.new_context();token=a.get(base+'api.php?action=session').json()['csrf'];assert a.post(base+'api.php?action=login',headers={'X-CSRF-Token':token},data={'email':email,'password':password}).status==200
        check('Default-off feature gate denies even authenticated requests',a.get(base+'api.php?action=business_list&environment=test&resource=brands').status==404)
    # Auth is healthy, business schema absent: exercise a real SQL failure at HTTP boundary.
    missing=db.database();missing_env=env.copy();missing_env.update(db.environment(missing))
    missing_password=secrets.token_urlsafe(24)
    x=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/create_admin.php'),'--email='+email,'--password-stdin'],input=missing_password+'\n',env=missing_env,capture_output=True,text=True);assert x.returncode==0
    with sync_playwright() as pw,server(missing_env) as base:
        a=pw.request.new_context();token=a.get(base+'api.php?action=session').json()['csrf'];assert a.post(base+'api.php?action=login',headers={'X-CSRF-Token':token},data={'email':email,'password':missing_password}).status==200
        failure=a.get(base+'api.php?action=business_list&environment=test&resource=brands')
        check('Actual SQL schema failure returns generic 503 without SQL details',failure.status==503 and failure.json()=={'ok':False,'code':'service_unavailable'})
    report={'checks':checks,'migration':'002_business_foundation','migration_sha256':hashlib.sha256((ROOT/'database/migrations/002_business_foundation.sql').read_bytes()).hexdigest(),'engine':db.sql('SELECT VERSION();'),'backup':'Private SQL dump under .runtime/mariadb (not versioned)','review_database_modified':False,'dashboard_business_sql_activated':False}
    (OUT/'integration-results.json').write_text(json.dumps(report,indent=2))


if __name__=='__main__':main()
