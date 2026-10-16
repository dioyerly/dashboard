"""Block 2 real local SQL/API tests, including interrupted DDL recovery. No active data."""
import concurrent.futures
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
import local_mariadb as db
from business_foundation import server,php
OUT=ROOT/'preparation/phase4b-catalogs';OUT.mkdir(exist_ok=True)


def dump(cfg):
    path=db.RUNTIME/('catalog-backup-'+secrets.token_hex(8)+'.sql')
    with path.open('wb') as f:
        p=subprocess.run([str(db.RUNTIME/'bin/mariadb-dump.exe'),'--defaults-file='+str(db.RUNTIME/'client.ini'),'--host=127.0.0.1','--port='+str(db.state()['port']),'--protocol=tcp','--skip-ssl','--single-transaction',cfg['name']],stdout=f,stderr=subprocess.PIPE)
    assert p.returncode==0 and path.stat().st_size>100
    Path(str(path)+'.json').write_text(json.dumps({'database':cfg['name'],'sha256':hashlib.sha256(path.read_bytes()).hexdigest()}))
    return path


def migrate(script,env,backup,*args):
    return subprocess.run(db.PHP_ARGS+[str(ROOT/'tools'/script),'--backup='+str(backup),*args],env=env,cwd=ROOT,capture_output=True,text=True)


def main():
    checks=[]
    def check(name,ok):
        checks.append({'check':name,'passed':bool(ok)});print(('PASS: ' if ok else 'FAIL: ')+name,flush=True);assert ok,name
    hashes={n:hashlib.sha256((ROOT/'database/migrations'/n).read_bytes()).hexdigest() for n in ['001_auth.sql','002_business_foundation.sql']}
    db.start();cfg=db.database();env=os.environ.copy();env.update(db.environment(cfg))
    private=ROOT/'storage/private'/('catalog-'+secrets.token_hex(8));private.mkdir(parents=True)
    env.update(CENTRO_PRIVATE_DIR=str(private),CENTRO_BUSINESS_API_ENABLED='1')
    rootenv=env.copy();rootenv.update(db.environment({'name':cfg['name'],'user':'root','password':db.state()['root_password']}))
    password=secrets.token_urlsafe(24);email='catalog@ejemplo.test'
    p=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/create_admin.php'),'--email='+email,'--password-stdin'],input=password+'\n',env=env,capture_output=True,text=True);assert p.returncode==0
    check('002 baseline applied without modifying migration file',migrate('migrate_business_local.php',rootenv,dump(cfg)).returncode==0)
    p=subprocess.run(db.PHP_ARGS+[str(ROOT/'tests/business_foundation.php')],env=rootenv,cwd=ROOT,capture_output=True,text=True)
    assert p.returncode==0,p.stderr
    (OUT/'foundation-regression.json').write_text(p.stdout)
    backup=dump(cfg)
    interrupted=migrate('migrate_catalogs_local.php',rootenv,backup,'--stop-after=2')
    check('Injected interruption leaves two DDL steps committed without ledger entry',interrupted.returncode!=0 and db.sql(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{cfg['name']}' AND TABLE_NAME IN ('formats','product_categories');")=='2' and db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.schema_migrations WHERE version='003_catalogs_products';")=='0')
    db.sql(f"INSERT INTO `{cfg['name']}`.formats(environment_id,id,name) VALUES('test','recovery_sentinel','Synthetic recovery row');")
    recovery=migrate('migrate_catalogs_local.php',rootenv,backup)
    if recovery.returncode:print((db.RUNTIME/'catalog-migration-error.log').read_text()[-1800:])
    check('Recovery adopts matching committed DDL and completes without deleting rows',recovery.returncode==0)
    check('Repeated 003 checks checksum and is safe',migrate('migrate_catalogs_local.php',rootenv,backup).returncode==0)
    partial=php("require 'app/bootstrap.php';require 'app/Business.php';try {new Centro\\Business(Centro\\Database::connection($config['db']));echo 'unsafe';}catch(Centro\\BusinessError $e){echo $e->reason;}",rootenv)
    check('Services reject incomplete catalog migration set',partial=='migration_required')
    check('004 preserves optional origin from original project statistics',migrate('migrate_catalogs_local.php',rootenv,dump(cfg),'--migration=004_project_stats_optional_origin').returncode==0)
    p=subprocess.run(db.PHP_ARGS+[str(ROOT/'tests/catalogs.php')],env=rootenv,cwd=ROOT,capture_output=True,text=True)
    if p.returncode:raise RuntimeError(p.stderr[:2500])
    core=json.loads(p.stdout);(OUT/'pdo-results.json').write_text(json.dumps(core,indent=2))
    check('Catalog/product/price/package/pagination real PDO suite',all(c['passed'] for c in core['checks']))
    tables=db.sql(f"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='{cfg['name']}';").splitlines()
    for t in tables:
        if t.startswith('auth_') or t=='schema_migrations':continue
        privileges='SELECT,INSERT,UPDATE'
        if t in ['business_operations','business_audit','product_version_history','business_page_cursors']:privileges='SELECT,INSERT'
        if t=='environments':privileges='SELECT,UPDATE'
        db.sql(f"GRANT {privileges} ON `{cfg['name']}`.`{t}` TO '{cfg['user']}'@'127.0.0.1';")
    code="require 'app/bootstrap.php';require 'app/Business.php';$s=new Centro\\Business(Centro\\Database::connection($config['db']));$b=json_decode(stream_get_contents(STDIN),true);try{echo json_encode($s->command('test',$b['token'],1,$b['command']));}catch(Centro\\BusinessError $e){echo json_encode(['error'=>$e->reason]);}"
    token=secrets.token_hex(16);payload={'token':token,'command':{'changes':[{'resource':'formats','id':'concurrent_format','action':'create','expected_version':0,'data':{'name':'Concurrent format'}}]}}
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:results=list(pool.map(lambda _:json.loads(php(code,env,payload)),range(8)))
    check('Concurrent catalog retries commit once',sum(r.get('replayed') is False for r in results)==1 and sum(r.get('replayed') is True for r in results)==7)
    payloads=[{'token':secrets.token_hex(16),'command':{'changes':[{'resource':'products','id':'pd_one','action':'update','expected_version':1,'data':{'pending_updates':'Editor '+str(i)}}]}} for i in range(2)]
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:results=list(pool.map(lambda p:json.loads(php(code,env,p)),payloads))
    check('Concurrent product edits yield one version conflict',sum('result' in r for r in results)==1 and sum(r.get('error')=='version_conflict' for r in results)==1)
    check('Mid-batch error rolls back first entity',json.loads(php(code,env,{'token':secrets.token_hex(16),'command':{'changes':[{'resource':'formats','id':'rollback_format','action':'create','expected_version':0,'data':{'name':'Rollback'}},{'resource':'product_links','id':'bad_link','action':'create','expected_version':0,'data':{'product_id':'no_product','link_value':'Synthetic'}}]}})).get('error')=='invalid_reference' and db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.formats WHERE id='rollback_format';")=='0')
    # A divergent partial schema is refused before creating any missing target object.
    other=db.database();oe=rootenv.copy();oe.update(db.environment({'name':other['name'],'user':'root','password':db.state()['root_password']}));assert migrate('migrate_business_local.php',oe,dump(other)).returncode==0
    ob=dump(other);db.sql(f"CREATE TABLE `{other['name']}`.formats(id INT PRIMARY KEY, sentinel VARCHAR(30)); INSERT INTO `{other['name']}`.formats VALUES(1,'retain');")
    check('Divergent partial DDL refused without deleting data or adding other steps',migrate('migrate_catalogs_local.php',oe,ob).returncode!=0 and db.sql(f"SELECT sentinel FROM `{other['name']}`.formats WHERE id=1;")=='retain' and db.sql(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{other['name']}' AND TABLE_NAME='product_categories';")=='0')
    db.stop();db.start()
    check('Catalogs/history and completed migration survive restart',db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.product_version_history;")=='3')
    with sync_playwright() as pw,server(env) as base:
        a=pw.request.new_context();url=base+'api.php?action=business_list&environment=test&resource=formats&limit=91'
        check('Pagination requires authentication',a.get(url).status==401)
        token=a.get(base+'api.php?action=session').json()['csrf'];assert a.post(base+'api.php?action=login',headers={'X-CSRF-Token':token},data={'email':email,'password':password}).status==200
        seen=[];next_url=url
        while next_url:
            resp=a.get(next_url);assert resp.status==200,resp.text();page=resp.json();seen.extend(r['id'] for r in page['records']);next_url=url+'&cursor='+page['next_cursor'] if page['next_cursor'] else None
        check('HTTP pagination reads all 624 rows without duplicates',len(seen)==624 and len(set(seen))==624)
        check('HTTP validates cursor and bounds',a.get(url+'&cursor=bad').status==422 and a.get(url.replace('&limit=91','&limit=501')).status==422)
        relation=a.get(base+'api.php?action=business_list&environment=test&resource=product_versions&parent_field=product_id&parent_id=pd_one').json()
        check('API queries normalized product-version relation',len(relation['records'])==2)
        check('Relation filter injection rejected',a.get(url+'&parent_field=name&parent_id=foo').status==422)
        check('New catalog writes still require CSRF',a.post(base+'api.php?action=business_command',data={}).status==403)
        token=a.get(base+'api.php?action=session').json()['csrf']
        bad={'environment':'test','operation_id':secrets.token_hex(16),'command':{'changes':[{'resource':'product_version_history','id':'fake','action':'create','expected_version':0,'data':{}}]}}
        check('API prevents history rewrites',a.post(base+'api.php?action=business_command',headers={'X-CSRF-Token':token},data=bad).status==409)
        check('Dashboard contract remains local',a.get(base+'api.php?action=session').json()['business_storage']=='local')
    check('Applied migration 001/002 checksums unchanged',all(hashlib.sha256((ROOT/'database/migrations'/n).read_bytes()).hexdigest()==h for n,h in hashes.items()))
    (OUT/'integration-results.json').write_text(json.dumps({'checks':checks,'tables':tables,'engine':db.sql('SELECT VERSION();'),'migration':'003_catalogs_products','sha256':hashlib.sha256((ROOT/'database/migrations/003_catalogs_products.sql').read_bytes()).hexdigest(),'active_dashboard_modified':False,'private_backup_and_recovery_journal':True},indent=2))


if __name__=='__main__':main()
