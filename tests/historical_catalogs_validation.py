"""Synthetic historical conversion and normalized catalog validation; local runner only."""
import copy,json,os,secrets,subprocess,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path[:0]=[str(ROOT/'tools'),str(ROOT/'tests')]
import local_mariadb as db
import backup_local as backup
from backup_import import fixture
from controlled_migration import prepare_target
OUT=ROOT/'.runtime'/('historical-close-'+secrets.token_hex(6));OUT.mkdir(parents=True)
checks=[]
def check(name,ok):
 checks.append({'check':name,'passed':bool(ok)});(OUT/'results.json').write_text(json.dumps({'checks':checks},indent=2));print(('PASS ' if ok else 'FAIL ')+name,flush=True);assert ok,name
def php(code,env):
 p=subprocess.run(db.PHP_ARGS+['-r',code],env=env,cwd=ROOT,text=True,capture_output=True,encoding='utf-8',creationflags=db.FLAGS)
 if p.returncode:raise RuntimeError(p.stderr+' '+p.stdout[-1800:])
 return json.loads(p.stdout)
def bridge(action,**kwargs):
 p=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/historical_conversion.php')],input=json.dumps({'action':action,'environment':'pruebas',**kwargs}),env=admin,cwd=ROOT,text=True,capture_output=True,encoding='utf-8',creationflags=db.FLAGS)
 try:return json.loads(p.stdout)
 except ValueError:raise RuntimeError(p.stderr+' '+p.stdout)
cfg,app,admin,target,password=prepare_target(OUT)
s=fixture();s['settings']['pin']='';s['settings']['accounts'] += [{'id':'ac_two','name':'Cash','currency':'USD'}]
sale={'id':'legacy_sale','createdAt':'2024-01-02T03:04:05Z','type':'unica','date':'2024-01-01','customerId':'cu_one','productId':'pd_one','versionId':'vr_one','qty':2,'unitPrice':10,'currency':'USD','discount':0,'commission':0,'tax':0,'refunded':0,'status':'valida','payStatus':'cobrado','concept':'Original sale','history':[{'at':'2024-01-02T03:04:05Z','text':'Original preserved'}]}
s['sales']=[sale,{**sale,'id':'ambiguous_sale','discount':1},{**sale,'id':'missing_receipt'}]
s['expenses']=[{'id':'legacy_expense','date':'2024-01-03','amount':3.25,'currency':'USD','status':'pagado','accountId':'ac_one','payDate':'2024-01-04','concept':'Original expense'}]
s['treasury']=[{'id':'legacy_receipt','type':'cobro','date':'2024-01-02','amount':20,'currency':'USD','accountTo':'ac_one','saleId':'legacy_sale'}, {'id':'legacy_payment','type':'pago','date':'2024-01-04','amount':3.25,'currency':'USD','accountFrom':'ac_one','expenseId':'legacy_expense'}, {'id':'legacy_capital','type':'aporte','date':'2024-01-01','amount':100,'currency':'USD','accountTo':'ac_one'}, {'id':'legacy_withdrawal','type':'retiro','date':'2024-01-05','amount':2,'currency':'USD','accountFrom':'ac_one'}, {'id':'legacy_adjustment','type':'ajuste','date':'2024-01-06','amount':1,'currency':'USD','accountTo':'ac_one'}, {'id':'legacy_transfer','type':'transferencia','date':'2024-01-07','amount':5,'amountTo':5,'currency':'USD','currencyTo':'USD','accountFrom':'ac_one','accountTo':'ac_two'}]
raw=json.dumps(s,ensure_ascii=False).encode();original=OUT/'original.json';original.write_bytes(raw)
model,report=backup.diagnose(raw,'pruebas',target);check('historical import preflight',report['can_import'])
operation='import_hist_'+secrets.token_hex(12);result=backup.import_backup(target,raw,'pruebas',operation);check('historical import succeeds without financial movements',result['ok'] and db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.fin_movements;")=='0')
preview=bridge('preview');(OUT/'preview.json').write_text(json.dumps(preview,indent=2));check('conversion preview executes contracts and rolls back',preview['ok'] and db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.fin_movements;")=='0')
if not preview['ok']:raise RuntimeError(preview)
plan=preview['data'];check('six unequivocal financial groups are convertible',len(plan['candidates'])==6)
check('adjustments and missing cash evidence stay historical',len(plan['pending'])==2 and any(x['reason']=='historical_collection_evidence_incomplete' for x in plan['pending']))
selected=[x['key'] for x in plan['candidates']]
check('confirmation hash is mandatory',bridge('confirm',confirmation_sha256='0'*64,selected=selected).get('code')=='historical_preview_conflict')
converted=bridge('confirm',confirmation_sha256=plan['confirmation_sha256'],selected=selected);(OUT/'converted.json').write_text(json.dumps(converted,indent=2));check('explicit selected conversion succeeds atomically',converted['ok'] and converted['data']['comparison']['matched'])
check('seven existing cash effects recorded once',db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.fin_movements;")=='7')
check('balances preserve original cash and transfer values',db.sql(f"SELECT CONCAT(id,':',balance) FROM (SELECT a.id,COALESCE(SUM(CASE m.direction WHEN 'in' THEN m.amount ELSE -m.amount END),0) balance FROM `{cfg['name']}`.accounts a LEFT JOIN `{cfg['name']}`.fin_movements m ON m.environment_id=a.environment_id AND m.account_id=a.id GROUP BY a.id) x ORDER BY id;")=='ac_one:110.75\nac_two:5.00')
check('sale and expense dates unchanged',db.sql(f"SELECT CONCAT(issued_on,':',total) FROM `{cfg['name']}`.fin_sales;")=='2024-01-01:20.00' and db.sql(f"SELECT CONCAT(incurred_on,':',total) FROM `{cfg['name']}`.fin_expenses;")=='2024-01-03:3.25')
check('sale lines and allocations preserve references',db.sql(f"SELECT CONCAT(product_id,':',version_id,':',quantity,':',line_total) FROM `{cfg['name']}`.fin_sale_lines;")=='pd_one:vr_one:2.000000:20.00')
check('snapshots and independent import comparison remain intact',backup.compare(target,raw,'pruebas',operation).get('ok',False) and db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.original_financial_snapshots;")=='10')
replay=bridge('confirm',confirmation_sha256=plan['confirmation_sha256'],selected=selected);check('replaying confirmed conversion adds no movement',replay['ok'] and all(x['replayed'] for x in replay['data']['results']) and db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.fin_movements;")=='7')
# App account privileges are expanded only inside this new isolated schema.
db.sql(f"GRANT SELECT,INSERT,UPDATE,DELETE ON `{cfg['name']}`.* TO '{cfg['user']}'@'127.0.0.1';")
admin['CENTRO_TEST_SOURCE']=str(original)
code="""require 'app/Database.php';require 'app/BusinessState.php';$c=require 'config/settings.php';$p=Centro\\Database::connection($c['db']);$s=json_decode(file_get_contents(getenv('CENTRO_TEST_SOURCE')),true);$s['sales']=[];$s['expenses']=[];$s['treasury']=[];$service=new Centro\\BusinessState($p);echo json_encode($service->save('pruebas','state_catalog_initial_0001',1,$s,0));"""
check('state and existing normalized catalogs synchronize',php(code,admin)['version']==1)
(OUT/'private-access.json').write_text(json.dumps({'config':cfg,'environment':app,'admin_environment':admin,'password':password,'email':'migration-preview@example.invalid','source':str(original)},indent=2))
php_test=subprocess.run(db.PHP_ARGS+[str(ROOT/'tests/historical_catalogs.php')],env=admin,cwd=ROOT,capture_output=True,text=True,encoding='utf-8',creationflags=db.FLAGS)
(OUT/'php-results.json').write_text(php_test.stdout)
if php_test.returncode:raise RuntimeError(php_test.stderr+' '+php_test.stdout[-3500:])
extra=json.loads(php_test.stdout);checks.extend(extra['checks']);(OUT/'results.json').write_text(json.dumps({'checks':checks},indent=2));print('PASS PHP catalog/conversion checks',len(extra['checks']),flush=True)
http=subprocess.run([sys.executable,str(ROOT/'tests/historical_catalogs_http.py'),str(OUT/'private-access.json')],cwd=ROOT,capture_output=True,text=True,encoding='utf-8',creationflags=db.FLAGS)
if http.returncode:raise RuntimeError(http.stderr+' '+http.stdout[-2000:])
extra=json.loads((OUT/'http-results.json').read_text());checks.extend(extra['checks']);(OUT/'results.json').write_text(json.dumps({'checks':checks},indent=2));print(http.stdout.strip(),flush=True)
check('independent original source remains byte-identical',original.read_bytes()==raw)
print('RESULTS',OUT,flush=True)
