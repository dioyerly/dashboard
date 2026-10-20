"""Block 3: synthetic JSON -> real local PDO -> independent SQL export.
All fixtures, SQL dumps, targets and raw exports are ignored and project-private.
Only aggregate evidence is written to preparation/phase4b-backups/.
"""
import concurrent.futures
import copy
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import backup_local as tool
from backup_model import Model, TABLES
import local_mariadb as db

OUT=ROOT/'preparation/phase4b-backups'


def fixture():
    return {'version':1,'setupDone':True,'createdAt':'2026-10-16T10:00:00Z','settings':{
        'business':{'name':'Synthetic import','owner':'Test','site':'https://ejemplo.test'},'baseCurrency':'USD','numFormat':'es',
        'currencies':[{'code':'USD','symbol':'US$'},{'code':'ARS','symbol':'$'}],
        'brands':[{'id':'br_one','name':'Brand','color':'#123456','dark':'#123456','alt':'#abcdef','desc':'Test'}],
        'channels':[{'id':'ch_one','name':'Web','commission':None}],
        'categories':[{'brandId':'br_one','name':'Books'}],'formats':['PDF'],'languages':['Spanish'],
        'payMethods':['Card'],'expenseGroups':[{'name':'Operating','items':['Hosting','Tools']}],
        'accounts':[{'id':'ac_one','name':'Bank','currency':'USD'}],
        'alerts':{'upcomingDays':14,'noSalesDays':30,'dropPct':30},'hourly':{'rate':None,'currency':''},'pin':'PRIVATE-OLD-PIN'},
        'projects':[{'id':'pr_one','brandId':'br_one','name':'Project','kind':'suscripcion','status':'publicada',
            'createdAt':'2026-10-15T12:34:56.123-03:00','initialInvestment':None,'investCurrency':'',
            'userStats':[{'date':'2026-10-15','registered':4,'active':7},{'date':'2026-10-15','registered':5,'active':8,'origin':'manual'}]}],
        'customers':[{'id':'cu_one','name':'Person','email':'PERSON@EJEMPLO.TEST','createdAt':'','consent':False,'origin':'manual'}],
        'plans':[{'id':'pl_one','projectId':'pr_one','name':'Monthly','price':12.50,'currency':'USD','period':'mensual','trialDays':7}],
        'products':[{'id':'pd_one','brandId':'br_one','projectId':'pr_one','name':'Book','category':'Books','status':'publicado',
            'createdAt':'2026-10-15T12:00:00Z','updatedAt':'2026-10-16T12:00:00Z',
            'versions':[{'id':'vr_one','format':'PDF','language':'Historical free label','version':'1.0','price':9.99,'currency':'USD','channels':['ch_one']}],
            'resources':['/example/file.zip','/example/file.zip'],'bundle':[]},
            {'id':'pd_bundle','brandId':'br_one','name':'Bundle','status':'retirado','archivedAt':'2026-10-16T12:00:00Z',
             'versions':[{'id':'vr_bundle','format':'PDF','language':'','version':'2','price':15,'currency':'USD','channels':[]}],
             'bundle':['pd_one'],'resources':[]}],
        **{k:[] for k in ['rates','sales','subs','expenses','resources','suppliers','events','treasury','integrations','review','timeLogs','log']}}


def encoded(value):return json.dumps(value,ensure_ascii=False).encode('utf-8')


def original_demo():
    # This fixture was generated from createDemoState() in the immutable demo
    # with the local browser test harness. Production never loads it.
    demo=ROOT/'reference/centro-operaciones-DEMO.html'
    expected='78942e47c709628852145c70113cd8f1a2638301ffd5714cf856ee8acba07122'
    if hashlib.sha256(demo.read_bytes()).hexdigest()!=expected:
        raise RuntimeError('Original demo source changed; regenerate the reviewed fixture')
    fixture=ROOT/'tests/fixtures/original-demo-v1.json'
    raw=fixture.read_bytes()
    if hashlib.sha256(raw).hexdigest()!='6258f8e1261e57815e32d1400bbb5a51d9a31a2a3b0e24bc11f88d83d4889d54':
        raise RuntimeError('Original demo fixture hash mismatch')
    json.loads(raw)
    return raw


def main():
    OUT.mkdir(exist_ok=True)
    checks=[]
    def check(name,value):
        checks.append({'check':name,'passed':bool(value)})
        print(('PASS: ' if value else 'FAIL: ')+name,flush=True)
        (OUT/'import-results.json').write_text(json.dumps({'checks':checks,'complete_migration':False},indent=2))
        assert value,name
    before={str(p.relative_to(ROOT)):hashlib.sha256(p.read_bytes()).hexdigest() for folder in ['public','reference'] for p in (ROOT/folder).rglob('*') if p.is_file()}
    db.start();target=tool.prepare();cfg,env=tool.environment(target)
    empty=lambda:db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.environments;")=='0'
    good=fixture();raw=encoded(good);original_hash=hashlib.sha256(raw).hexdigest()
    private=db.RUNTIME/'backup-imports'/('fixture-'+secrets.token_hex(8)+'.json');private.write_bytes(raw)
    model,report=tool.diagnose(raw,'pruebas',target)
    if not report['can_import']:
        print(json.dumps(report.get('sql_preflight',{})),flush=True)
        print(json.dumps([i for i in report['issues'] if i['level'] in ('error','review')]),flush=True)
    check('Valid synthetic v1 preflight is read-only and SQL-compatible',report['can_import'] and empty())
    # The PHP bridge refuses the actual review configuration before connection.
    reviewenv=os.environ.copy();reviewenv.update({k:str(v) for k,v in json.loads((db.RUNTIME/'review.json').read_text())['environment'].items()})
    denied=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/backup_bridge.php')],input=json.dumps({'action':'preflight','plan':model.plan()}),
                          text=True,capture_output=True,env=reviewenv,cwd=ROOT)
    check('Review database cannot be nominated as an import target',json.loads(denied.stdout).get('code')=='isolated_import_target_required')
    remote=env.copy();remote['CENTRO_DB_HOST']='remote.invalid'
    denied=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/backup_bridge.php')],input=json.dumps({'action':'preflight','plan':model.plan()}),
                          text=True,capture_output=True,env=remote,cwd=ROOT)
    check('Remote target refused before connecting',json.loads(denied.stdout).get('code')=='isolated_import_target_required')
    collision=copy.deepcopy(good);collision['settings']['formats']=['Café','CAFE']
    # Exercise the independent PDO guard directly, bypassing the offline warning.
    collision_model=Model(encoded(collision),'pruebas')
    collision_result=tool.bridge(target,{'action':'preflight','plan':collision_model.plan()})
    check('Real SQL collation collision is detected before writes',collision_result.get('code')=='duplicate_normalized_key' and empty())
    check('Optional absent fields are distinguished from invalid fields',any(i['level']=='optional' for i in report['issues']) and model.rows['projects'][0]['launch_date'] is None and model.rows['project_user_stats'][0]['origin'] is None)
    check('Same-day statistics preserve sequence and active can exceed registered',model.rows['project_user_stats'][1]['sequence_no']==1 and model.rows['project_user_stats'][0]['active']==7)
    check('Historical prices have unknown dates and no invented history',model.rows['plan_prices'][0]['valid_from'] is None and model.rows['plan_prices'][0]['provenance']=='unknown' and len(model.rows['product_version_history'])==2)
    check('PIN absent from plan, rows, reports and mapping',b'PRIVATE-OLD-PIN' not in encoded(model.plan()) and b'PRIVATE-OLD-PIN' not in encoded(report))
    cases={}
    cases['Corrupt JSON rejected']=b'{"version":'
    cases['Repeated JSON property rejected']=b'{"version":1,"version":1}'
    cases['Unsupported version rejected']=encoded({**good,'version':2})
    changed=copy.deepcopy(good);changed['products'][1]['id']='pd_one';cases['Duplicate historical ID rejected']=encoded(changed)
    changed=copy.deepcopy(good);del changed['products'][0]['id'];cases['Missing ID rejected']=encoded(changed)
    changed=copy.deepcopy(good);changed['products'][0]['brandId']='missing';cases['Missing relationship rejected']=encoded(changed)
    changed=copy.deepcopy(good);changed['products'][0]['futureField']={'important':True};cases['Unknown supported field requires review']=encoded(changed)
    changed=copy.deepcopy(good);changed['products'][0]['versions'][0]['currency']='EUR';cases['Unconfigured currency rejected']=encoded(changed)
    changed=copy.deepcopy(good);changed['projects'][0]['launchDate']='2026-02-30';cases['Invalid date rejected']=encoded(changed)
    changed=copy.deepcopy(good);changed['plans'][0]['price']=1.001;cases['Unrepresentable money rejected without rounding']=encoded(changed)
    changed=copy.deepcopy(good);changed['settings']['brands'][0]['archivedAt']='2026-10-16T00:00:00Z';cases['Active reference to archived parent requires review']=encoded(changed)
    changed=copy.deepcopy(good);changed['products'][0]['bundle']=['pd_bundle'];cases['Indirect bundle cycle rejected before writes']=encoded(changed)
    changed=copy.deepcopy(good);changed['settings']['formats']=['Café','CAFE'];cases['Collation name collision requires review']=encoded(changed)
    changed=copy.deepcopy(good);changed['products'][0]['name']=' Book';cases['Ambiguous whitespace rejected without trimming']=encoded(changed)
    changed=copy.deepcopy(good);changed['projects'][0]['secretToken']='PRIVATE-SECRET';cases['Unknown secret excluded and requires review']=encoded(changed)
    changed=copy.deepcopy(good);changed['products'][0]['resources']=['https://ejemplo.test/file?token=PRIVATE-SECRET'];cases['Embedded credential rejected rather than imported as link']=encoded(changed)
    changed=copy.deepcopy(good);changed['plans'][0]['priceHistory']=[{'price':2,'date':'2020-01-01'}];cases['Unspecified historical price format requires review instead of losing entries']=encoded(changed)
    changed=copy.deepcopy(good);del changed['customers'][0]['origin'];cases['Unknown customer origin is not invented to satisfy NOT NULL']=encoded(changed)
    changed=copy.deepcopy(good);del changed['customers'][0]['consent'];cases['Missing customer consent requires review instead of becoming false']=encoded(changed)
    changed=copy.deepcopy(good);del changed['plans'][0]['trialDays'];cases['Missing trial duration requires review instead of inventing zero']=encoded(changed)
    changed=copy.deepcopy(good);changed['settings']['hourly']['currency']='EUR';cases['Pending hourly currency still receives validation']=encoded(changed)
    changed=copy.deepcopy(good);changed['settings']['alerts']['dropPct']=101;cases['Pending alert values still receive validation']=encoded(changed)
    for name,source in cases.items():
        result=tool.import_backup(target,source,'pruebas','import_'+secrets.token_hex(16))
        check(name,result.get('code')=='prevalidation_rejected' and empty() and b'PRIVATE-SECRET' not in encoded(result))
    # Pending financial rows remain in source and are reported; no financial table is created.
    pending=copy.deepcopy(good);pending['sales']=[{'id':'sl_one','productId':'pd_one','currency':'USD','amount':5,'date':'2026-10-16','future':{'x':1}}]
    m=Model(encoded(pending),'pruebas')
    check('Unsupported collections and every nested field explicitly inventoried',m.report()['can_import'] and any(i['path']==['sales',0,'future','x'] for i in m.issues) and 'sales' not in m.rows)
    badpending=copy.deepcopy(pending);badpending['sales'][0]['productId']='not_here'
    check('Critical reference errors also rejected in non-imported financial rows',not Model(encoded(badpending),'pruebas').report()['can_import'])
    op='import_'+secrets.token_hex(16)
    fail=tool.import_backup(target,raw,'pruebas',op,fail_after=12)
    check('Mid-import failure rolls back rows, environment, receipt and audit',fail.get('code')=='injected_import_failure' and empty() and db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.business_operations;")=='0')
    result=tool.import_backup(target,raw,'pruebas',op)
    if not result['ok']:print(json.dumps(result)[:1000],flush=True)
    check('Retry after rollback imports complete consistent partial dataset',result['ok'] and not result['data']['replayed'])
    compared=tool.compare(target,raw,'pruebas',op)
    (OUT/'comparison-results.json').write_text(json.dumps(compared,ensure_ascii=False,indent=2))
    if not compared['ok']:print(json.dumps(compared)[:2000],flush=True)
    check('Source to MariaDB to independent export compares every mapped field',compared['ok'] and compared['supported_projection_equal'] and not compared['complete_migration'])
    exported=tool.export_backup(target,'pruebas',op)
    check('Nested order, duplicate resource links, IDs and nullable stats survive export',exported['supported_original']['products'][0]['resources']==good['products'][0]['resources'] and exported['supported_original']['projects'][0]['userStats']==good['projects'][0]['userStats'])
    check('Archived product and its version retained, status not confused with archive',next(r for r in exported['normalized_entities']['products'] if r['id']=='pd_bundle')['archived_at'] is not None and next(r for r in exported['normalized_entities']['product_versions'] if r['id']=='vr_bundle')['archived_at'] is not None)
    replay=tool.import_backup(target,raw,'pruebas',op)
    check('Same operation and payload replay without duplicate rows',replay['ok'] and replay['data']['replayed'] and tool.compare(target,raw,'pruebas',op)['ok'])
    changed=copy.deepcopy(good);changed['products'][0]['name']='Changed'
    check('Same operation with changed source conflicts',tool.import_backup(target,encoded(changed),'pruebas',op).get('code')=='idempotency_conflict')
    check('New operation cannot merge or overwrite existing destination',tool.import_backup(target,raw,'pruebas','import_'+secrets.token_hex(16)).get('code')=='destination_not_empty')
    # Actual SQL read, not receipt payload reuse: modifying a target value must fail verification.
    db.sql(f"UPDATE `{cfg['name']}`.products SET name='Tampered' WHERE id='pd_one';")
    check('Verification detects changed SQL values rather than echoing source',not tool.compare(target,raw,'pruebas',op)['ok'])
    db.sql(f"UPDATE `{cfg['name']}`.products SET name='Book' WHERE id='pd_one';")
    db.sql(f"UPDATE `{cfg['name']}`.environments SET code='produccion';")
    check('Verification also detects altered environment identity',not tool.compare(target,raw,'pruebas',op)['ok'])
    db.sql(f"UPDATE `{cfg['name']}`.environments SET code='pruebas';")
    # Missing/bad backup is rejected, even for a replay, without changing existing data.
    badbackup=db.RUNTIME/'backup-imports'/('bad-'+secrets.token_hex(6)+'.sql');badbackup.write_text('x'*150)
    Path(str(badbackup)+'.json').write_text(json.dumps({'database':cfg['name'],'sha256':'0'*64}))
    check('Backup hash mismatch prevents writes',tool.import_backup(target,raw,'pruebas',op,backup=badbackup).get('code')=='backup_manifest_mismatch')
    large=copy.deepcopy(good);large['settings']['formats'] += ['Format '+str(i) for i in range(620)]
    large_raw=encoded(large);large_target=tool.prepare();large_op='import_'+secrets.token_hex(16)
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        runs=list(pool.map(lambda _:tool.import_backup(large_target,large_raw,'pruebas',large_op),range(4)))
    check('Four concurrent retries commit once and return three durable replays',all(r['ok'] for r in runs) and sum(not r['data']['replayed'] for r in runs)==1)
    check('More than 500 records import and export completely without truncation',tool.compare(large_target,large_raw,'pruebas',large_op)['ok'] and len(tool.export_backup(large_target,'pruebas',large_op)['normalized_entities']['formats'])==621)
    # A different token racing against another import must conflict, never merge.
    conflict_target=tool.prepare()
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        collisions=list(pool.map(lambda token:tool.import_backup(conflict_target,raw,'pruebas',token),['import_'+secrets.token_hex(16) for _ in range(2)]))
    check('Concurrent different operations yield one commit and one occupied-target conflict',sum(r['ok'] for r in collisions)==1 and sum(r.get('code')=='destination_not_empty' for r in collisions)==1)
    ack_target=tool.prepare();ack_op='import_'+secrets.token_hex(16)
    lost=tool.import_backup(ack_target,raw,'pruebas',ack_op,lose_ack=True)
    retry=tool.import_backup(ack_target,raw,'pruebas',ack_op)
    check('Lost post-commit acknowledgement recovers by durable receipt',lost.get('code')=='injected_acknowledgement_loss' and retry['ok'] and retry['data']['replayed'] and tool.compare(ack_target,raw,'pruebas',ack_op)['ok'])
    demo_raw=original_demo();demo_model=Model(demo_raw,'pruebas');demo_report=demo_model.report()
    # The demo intentionally includes unrelated modules. Keep any actual incompatibilities visible.
    (OUT/'original-demo-diagnosis.json').write_text(json.dumps(demo_report,ensure_ascii=False,indent=2))
    check('Original demo is completely inventoried without source normalization',demo_report['collections']['products']==10 and demo_report['collections']['sales']>64 and not demo_report['complete_migration'])
    if demo_report['can_import']:
        demo_target=tool.prepare();demo_op='import_'+secrets.token_hex(16)
        imported=tool.import_backup(demo_target,demo_raw,'pruebas',demo_op)
        check('Original demo supported subset imports and round-trips with pending finance excluded',imported['ok'] and tool.compare(demo_target,demo_raw,'pruebas',demo_op)['ok'])
    else:
        check('Original demo incompatibilities reject import before writes',bool([i for i in demo_report['issues'] if i['level'] in ('error','review')]))
    demo_supported=json.loads(demo_raw)
    for key in ['rates','sales','subs','expenses','resources','suppliers','events','treasury','integrations','review','timeLogs','log']:
        demo_supported[key]=[]
    demo_target=tool.prepare();demo_op='import_'+secrets.token_hex(16);demo_supported_raw=encoded(demo_supported)
    imported=tool.import_backup(demo_target,demo_supported_raw,'pruebas',demo_op)
    check('Derived synthetic demo catalogs, products and associations round-trip',imported['ok'] and tool.compare(demo_target,demo_supported_raw,'pruebas',demo_op)['ok'])
    db.stop();db.start()
    check('Imported administrator, receipt and data persist after MariaDB restart',tool.compare(target,raw,'pruebas',op)['ok'])
    check('Original private backup remains byte-identical',hashlib.sha256(private.read_bytes()).hexdigest()==original_hash)
    check('Private complete source archive preserves pending data and original PIN only outside SQL',(tool.OUTPUT/('source-'+original_hash+'.json')).read_bytes()==raw and 'PRIVATE-OLD-PIN' not in json.dumps(tool.export_backup(target,'pruebas',op)))
    check('Frontend, originals and demo are byte-identical to initial state',all(hashlib.sha256((ROOT/p).read_bytes()).hexdigest()==h for p,h in before.items()))
    check('Review configuration keeps business SQL disabled',json.loads((db.RUNTIME/'review.json').read_text())['environment'].get('CENTRO_BUSINESS_API_ENABLED','0')=='0')
    (OUT/'import-results.json').write_text(json.dumps({'checks':checks,'engine':db.sql('SELECT VERSION();'),
        'migrations':['001_auth','002_business_foundation','003_catalogs_products','004_project_stats_optional_origin'],
        'new_migrations':[],'source_unchanged':True,'complete_migration':False,'frontend_sql_enabled':False},indent=2))


if __name__=='__main__':main()
