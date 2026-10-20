"""Block 4 real MariaDB migrations/imports and executable financial contracts.
No active data, browser profiles, production DB or financial API is touched.
"""
import concurrent.futures
import copy
from decimal import Decimal
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'));sys.path.insert(0,str(ROOT/'tests'))
import backup_local as tool
import local_mariadb as db
from backup_import import fixture,encoded,original_demo
from configuration_model import ConfigurationModel,exact,DEMO_COSTS

OUT=ROOT/'preparation/phase4b-configuration'


def main():
    OUT.mkdir(exist_ok=True);checks=[]
    baseline={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in (ROOT/'database/migrations').glob('00[1-4]_*.sql')}
    frontend={str(p.relative_to(ROOT)):hashlib.sha256(p.read_bytes()).hexdigest() for d in ['public','reference'] for p in (ROOT/d).rglob('*') if p.is_file()}
    def check(name,ok):
        checks.append({'check':name,'passed':bool(ok)});print(('PASS: ' if ok else 'FAIL: ')+name,flush=True)
        (OUT/'results.json').write_text(json.dumps({'checks':checks},indent=2),encoding='utf-8')
        assert ok,name
    def php(code,env,payload=None):
        p=subprocess.run(db.PHP_ARGS+['-r',code],env=env,cwd=ROOT,input=None if payload is None else json.dumps(payload),capture_output=True,text=True,encoding='utf-8')
        if p.returncode:raise RuntimeError(p.stderr[:1200])
        return p.stdout
    db.start();old=tool.prepare();oc,_=tool.environment(old)
    rootenv=os.environ.copy();rootenv.update(db.environment({'name':oc['name'],'user':'root','password':db.state()['root_password']}))
    # Existing populated 001-004 target: no backfills or changed history on ALTER.
    php("require 'app/Database.php';require 'app/Business.php';$c=require 'config/settings.php';$p=Centro\\Database::connection($c['db']);$p->exec(\"INSERT INTO environments(id,code,label) VALUES('test','pruebas','Synthetic')\");$s=new Centro\\Business($p);$s->command('test',str_repeat('a',16),1,['changes'=>["
        "['resource'=>'brands','id'=>'b','action'=>'create','expected_version'=>0,'data'=>['name'=>'Brand','color'=>'#123456','dark'=>'#123456','alt'=>'#123456']],"
        "['resource'=>'projects','id'=>'p','action'=>'create','expected_version'=>0,'data'=>['name'=>'Project','brand_id'=>'b','kind'=>'interna','status'=>'idea']],"
        "['resource'=>'plans','id'=>'pl','action'=>'create','expected_version'=>0,'data'=>['project_id'=>'p','name'=>'Plan','status'=>'activa','trial_days'=>0]],"
        "['resource'=>'customers','id'=>'c','action'=>'create','expected_version'=>0,'data'=>['name'=>'Customer','consent'=>1,'origin'=>'manual']]]]);",rootenv)
    def migrate(migration,backup,*args):
        return subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/migrate_catalogs_local.php'),'--migration='+migration,'--backup='+str(backup),*args],env=rootenv,cwd=ROOT,capture_output=True).returncode
    b=tool.dump(oc)
    check('005 interruption after first DDL is detectable',migrate('005_configuration_rates',b,'--stop-after=1')!=0)
    check('005 forward recovery adopts existing table',migrate('005_configuration_rates',b)==0)
    check('005 repeat verifies immutable checksum',migrate('005_configuration_rates',b)==0)
    gate=php("require 'app/Database.php';require 'app/Business.php';$c=require 'config/settings.php';try{new Centro\\Business(Centro\\Database::connection($c['db']));echo 'bad';}catch(Centro\\BusinessError $e){echo $e->reason;}",rootenv)
    check('Services reject half-applied block 4',gate=='migration_required')
    b=tool.dump(oc)
    check('006 interruption after nullable ALTER preserves data',migrate('006_historical_absence',b,'--stop-after=1')!=0)
    check('006 nullable ALTER safely resumes',migrate('006_historical_absence',b)==0)
    check('Migration preserves explicit true/manual and zero trial days',db.sql(f"SELECT CONCAT(consent,':',origin) FROM `{oc['name']}`.customers WHERE id='c';")=='1:manual' and db.sql(f"SELECT trial_days FROM `{oc['name']}`.plans WHERE id='pl';")=='0')
    target=tool.prepare('block4');cfg,env=tool.environment(target)
    data=fixture();del data['customers'][0]['origin'];del data['customers'][0]['consent'];del data['plans'][0]['trialDays']
    data['timezone']='America/Argentina/Buenos_Aires'
    data['settings']['hourly']={'rate':'12.123456789012345678','currency':'USD'}
    data['settings']['alerts']={'upcomingDays':14,'noSalesDays':30,'dropPct':'0.125'}
    data['rates']=[{'id':'rate_a','currency':'ARS','date':'2026-10-15','rate':'0.000000000123456789','source':'Synthetic quote'},
                   {'id':'rate_b','currency':'ARS','date':'2026-10-16','rate':'0.000000000223456789'},
                   {'id':'rate_c','currency':'ARS','date':'2026-10-17','rate':1e-9}]
    raw=encoded(data);m,diag=tool.diagnose(raw,'pruebas',target)
    if not diag['can_import']:
        print(json.dumps([i for i in diag['issues'] if i['level'] in ['error','review']]));print(diag.get('sql_preflight'))
    check('Full metadata/rates and missing historical values pass read-only preflight',diag['can_import'])
    check('Missing consent/origin/trialDays become NULL, not fabricated defaults',m.rows['customers'][0]['consent'] is None and m.rows['customers'][0]['origin'] is None and m.rows['plans'][0]['trial_days'] is None)
    op='import_'+secrets.token_hex(16)
    failure=tool.import_backup(target,raw,'pruebas',op,fail_after=6)
    check('New profile rolls back mid-import including environment/receipt',failure.get('code')=='injected_import_failure' and db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.environments;")=='0')
    result=tool.import_backup(target,raw,'pruebas',op)
    if not result['ok']:print(json.dumps(result)[:1800])
    check('Real PDO import into 005/006 schema succeeds',result['ok'])
    compared=tool.compare(target,raw,'pruebas',op)
    (OUT/'comparison.json').write_text(json.dumps(compared,ensure_ascii=False,indent=2),encoding='utf-8')
    if not compared['ok']:print(json.dumps(compared)[:1600])
    check('Original to SQL to export compares all supported fields',compared['ok'])
    ex=tool.export_backup(target,'pruebas',op)
    check('Absent source fields remain absent on export',all(k not in ex['supported_original']['customers'][0] for k in ('consent','origin')) and 'trialDays' not in ex['supported_original']['plans'][0])
    check('SQL distinguishes NULL from explicit false/zero',db.sql(f"SELECT CONCAT(consent IS NULL,origin IS NULL) FROM `{cfg['name']}`.customers;")=='11')
    check('18-digit rate and hourly precision survives exactly',ex['normalized_entities']['exchange_rates'][0]['rate']=='0.000000000123456789' and ex['normalized_entities']['dataset_settings'][0]['hourly_rate']=='12.123456789012345678')
    check('JSON numeric exponent quote also persists exactly',ex['normalized_entities']['exchange_rates'][2]['rate']=='0.000000001000000000')
    check('Historical prices still have unknown dates and no reconstructed chronology',ex['normalized_entities']['plan_prices'][0]['valid_from'] is None)
    check('Exact decimal helper rejects float and overprecision',all(rejects(lambda v=v:exact(v)) for v in [1.234,'1.1234567890123456789','-1','NaN']))
    check('Zero exact hourly value is distinct from NULL',exact(0)=='0.000000000000000000')
    check('Numeric scientific notation expands exactly without binary float',exact(Decimal('1E-9'))=='0.000000001000000000')
    check('Malformed JSON shapes fail closed in new profile',all(not ConfigurationModel(x,'pruebas').report()['can_import'] for x in [b'null',b'[]',b'{}',b'{"version":1,"settings":null}']))
    nullable=copy.deepcopy(data);nullable['customers'][0].update(origin=None,consent=None);nullable['plans'][0]['trialDays']=None
    nullable['createdAt']=None;nullable['setupDone']=None
    check('Explicit unknown historical fields and metadata accept NULL',tool.diagnose(encoded(nullable),'pruebas',target)[1]['can_import'])
    nozone=fixture();nozone.pop('createdAt');nozone.pop('setupDone')
    n=ConfigurationModel(encoded(nozone),'pruebas')
    check('Missing timezone/setup/date do not become machine timezone or false',n.report()['can_import'] and all(n.rows['dataset_settings'][0][k] is None for k in ['timezone','source_created_at','setup_done']))
    bad=copy.deepcopy(data);bad['customers'][0]['origin']=''
    check('Empty historical string is not silently treated as NULL',not tool.diagnose(encoded(bad),'pruebas',target)[1]['can_import'])
    bad=copy.deepcopy(data);bad['timezone']=None;bad['settings']['timezone']='UTC'
    check('Conflicting timezone fields cannot overwrite an explicit unknown',not tool.diagnose(encoded(bad),'pruebas',target)[1]['can_import'])
    bad=copy.deepcopy(data);bad['rates'][1]['date']=bad['rates'][0]['date']
    check('Duplicate currency/date quote rejected before import',not tool.diagnose(encoded(bad),'pruebas',target)[1]['can_import'])
    bad=copy.deepcopy(data);bad['rates'][0]['rate']='0'
    check('Zero rate rejected',not tool.diagnose(encoded(bad),'pruebas',target)[1]['can_import'])
    bad=copy.deepcopy(data);bad['timezone']='Invented/Zone'
    check('Unknown timezone rejected without default',not tool.diagnose(encoded(bad),'pruebas',target)[1]['can_import'])
    bad=copy.deepcopy(data);bad['products'][0]['versions'][0]['price']='1.234'
    check('More precise unsupported product price is never rounded',not tool.diagnose(encoded(bad),'pruebas',target)[1]['can_import'])
    # Real SQL constraints are exercised independently of PHP validation.
    for label,sql in [
        ('SQL rejects nonpositive quote',"UPDATE exchange_rates SET rate=0"),
        ('SQL rejects threshold over 100',"UPDATE dataset_settings SET drop_pct=101"),
        ('SQL rejects wrong currency FK',"UPDATE dataset_settings SET hourly_currency='EUR'"),
        ('SQL rejects invalid nullable consent',"UPDATE customers SET consent=2"),
        ('SQL rejects negative nullable trial days',"UPDATE plans SET trial_days=-1")]:
        rootenv2=os.environ.copy();rootenv2.update(db.environment({'name':cfg['name'],'user':'root','password':db.state()['root_password']}))
        code="require 'app/Database.php';$c=require 'config/settings.php';$p=Centro\\Database::connection($c['db']);try{$p->exec("+json.dumps(sql)+");echo 'accepted';}catch(PDOException){echo 'rejected';}"
        check(label,php(code,rootenv2)=='rejected')
    # New configuration uses existing versioned transactional services and audit.
    rootenv2=os.environ.copy();rootenv2.update(db.environment({'name':cfg['name'],'user':'root','password':db.state()['root_password']}))
    code="require 'app/Database.php';require 'app/Business.php';$c=require 'config/settings.php';$s=new Centro\\Business(Centro\\Database::connection($c['db']));$b=json_decode(stream_get_contents(STDIN),true);try{echo json_encode($s->command('pruebas',$b['operation'],1,$b['command']));}catch(Centro\\BusinessError $e){echo json_encode(['error'=>$e->reason]);}"
    payload={'operation':secrets.token_hex(16),'command':{'changes':[{'resource':'dataset_settings','id':'main','action':'update','expected_version':1,'data':{'hourly_rate':'13.000000000000000000'}}]}}
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:results=list(pool.map(lambda _:json.loads(php(code,rootenv2,payload)),range(4)))
    check('Four concurrent configuration retries apply once and replay three',sum(r.get('replayed') is False for r in results)==1 and sum(r.get('replayed') is True for r in results)==3)
    payload['operation']=secrets.token_hex(16)
    check('Stale configuration edit conflicts',json.loads(php(code,rootenv2,payload)).get('error')=='version_conflict')
    payload={'operation':secrets.token_hex(16),'command':{'changes':[{'resource':'business_settings','id':'main','action':'update','expected_version':1,'data':{'base_currency':'ARS'}}]}}
    check('Existing exchange history prevents silently changing its base',json.loads(php(code,rootenv2,payload)).get('error')=='exchange_base_conflict')
    payload={'operation':secrets.token_hex(16),'command':{'changes':[{'resource':'exchange_rates','id':'rate_a','action':'update','expected_version':1,'data':{'base_currency_id':'ARS'}}]}}
    check('A quote cannot be relabeled with a different historical base',json.loads(php(code,rootenv2,payload)).get('error')=='immutable_quote_identity')
    # Independent fresh target: lost acknowledgement, concurrent imports and explicit false/zero.
    fresh=tool.prepare('block4');raw2=encoded(fixture());op2='import_'+secrets.token_hex(16)
    lost=tool.import_backup(fresh,raw2,'pruebas',op2,lose_ack=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:again=list(pool.map(lambda _:tool.import_backup(fresh,raw2,'pruebas',op2),range(3)))
    check('Lost acknowledgement recovered by three concurrent durable replays',lost.get('code')=='injected_acknowledgement_loss' and all(r['ok'] and r['data']['replayed'] for r in again))
    check('Explicit false consent and zero values retain meaning',tool.export_backup(fresh,'pruebas',op2)['supported_original']['customers'][0]['consent'] is False)
    # DEMO normalization is opt-in, narrowly scoped, evidenced and never modifies original bytes.
    demo=original_demo();demo_hash=hashlib.sha256(demo).hexdigest()
    seed_hashes=[]
    for seed in ('1','2'):
        p=subprocess.run([sys.executable,'-c',"import sys,json,hashlib;sys.path.insert(0,'tools');from configuration_model import ConfigurationModel;m=ConfigurationModel(sys.stdin.buffer.read(),'pruebas',True);print(hashlib.sha256(json.dumps(m.plan(),ensure_ascii=False).encode()).hexdigest())"],
            input=demo,cwd=ROOT,env={**os.environ,'PYTHONHASHSEED':seed},capture_output=True)
        assert p.returncode==0
        seed_hashes.append(p.stdout)
    check('Full backup plan stays identical across independent CLI process hash seeds',seed_hashes[0]==seed_hashes[1])
    check('Known DEMO artifacts require explicit normalization',not ConfigurationModel(demo,'pruebas').report()['can_import'])
    demo_target=tool.prepare('block4',demo_costs=True);demo_op='import_'+secrets.token_hex(16)
    demo_model,demo_diag=tool.diagnose(demo,'pruebas',demo_target)
    if not demo_diag['can_import']:print(json.dumps([i for i in demo_diag['issues'] if i['level'] in ('error','review')])[:1500]);print(demo_diag.get('sql_preflight'))
    check('Explicit known DEMO normalization passes without treating artifacts as economic precision',demo_diag['can_import'])
    repairs=[n for n in demo_model.normalizations if n.get('rule')=='authorized_known_demo_cost_times_0_82']
    check('Exactly three auditable known-cost repairs use decimal arithmetic',len(repairs)==3 and {r['after'] for r in repairs}=={'10659.18','12.30','4919.18'} and Decimal(12999)*Decimal('0.82')==Decimal('10659.18'))
    imported=tool.import_backup(demo_target,demo,'pruebas',demo_op)
    check('DEMO supported subset including settings/rates imports and round-trips',imported['ok'] and tool.compare(demo_target,demo,'pruebas',demo_op)['ok'])
    cli=subprocess.run([sys.executable,str(ROOT/'tools/backup_local.py'),'import','--target',str(demo_target),
        '--source',str(tool.OUTPUT/('source-'+demo_hash+'.json')),'--environment','pruebas','--operation',demo_op],
        cwd=ROOT,env={**os.environ,'PYTHONHASHSEED':'123'},capture_output=True,text=True)
    cli_report=json.loads(cli.stdout)
    check('A separate CLI process replays the full DEMO import safely',cli.returncode==0 and json.loads(Path(cli_report['private_report']).read_text('utf-8'))['data']['replayed'])
    check('Original DEMO bytes remain intact and pending infrastructure is not migrated',hashlib.sha256(demo).hexdigest()==demo_hash and 'resources' not in tool.export_backup(demo_target,'pruebas',demo_op)['normalized_entities'])
    check('Known demo switch cannot normalize an arbitrary business backup',not ConfigurationModel(raw,'pruebas',True).report()['can_import'])
    unknown=fixture();unknown['resources']=[{'id':'resource_real','cost':'1.23456789','currency':'USD'}]
    check('Legitimate descriptive precision retained without DEMO rounding',ConfigurationModel(encoded(unknown),'pruebas').report()['can_import'])
    # Validate future financial contracts, not a pretend financial executor.
    contract=json.loads(php("require 'tests/financial_contracts.php';",rootenv2))
    (OUT/'financial-contract-results.json').write_text(json.dumps(contract,indent=2),encoding='utf-8')
    check('Executable financial contract suite passes',all(c['passed'] for c in contract['checks']))
    db.stop();db.start()
    check('Metadata/rates, receipt and absent values persist after restart',tool.compare(fresh,raw2,'pruebas',op2)['ok']
          and db.sql(f"SELECT rate FROM `{cfg['name']}`.exchange_rates WHERE id='rate_a';")=='0.000000000123456789'
          and db.sql(f"SELECT hourly_rate FROM `{cfg['name']}`.dataset_settings;")=='13.000000000000000000')
    check('Immutable migrations 001-004 are unchanged',all(hashlib.sha256((ROOT/'database/migrations'/name).read_bytes()).hexdigest()==h for name,h in baseline.items()))
    check('Frontend and original/demo bytes unchanged',all(hashlib.sha256((ROOT/name).read_bytes()).hexdigest()==h for name,h in frontend.items()))
    (OUT/'results.json').write_text(json.dumps({'checks':checks,'engine':db.sql('SELECT VERSION();'),'complete_migration':False,'financial_execution_implemented':False},indent=2),encoding='utf-8')


def rejects(fn):
    try:fn();return False
    except (ValueError,TypeError):return True


if __name__=='__main__':main()
