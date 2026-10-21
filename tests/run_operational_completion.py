"""Synthetic local MariaDB + PHP verification. Python is a test runner only."""
import json,os,subprocess,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import local_mariadb as db
PHP=db.PHP_ARGS

def fresh(last=17):
    cfg=db.database()
    env=os.environ.copy();env.update(db.environment(cfg))
    admin=os.environ.copy();u,p=db.prep_credentials();admin.update(db.environment({'name':cfg['name'],'user':u,'password':p or db.state()['root_password']}))
    code="require 'app/Database.php'; $c=require 'config/settings.php'; $p=Centro\\Database::connection($c['db']); foreach(glob('database/migrations/*.sql') as $f){if(str_starts_with(basename($f),'001_'))continue; $p->exec(file_get_contents($f));$q=$p->prepare('INSERT INTO schema_migrations(version,checksum) VALUES(?,?)');$q->execute([basename($f,'.sql'),hash_file('sha256',$f)]);}"
    assert isinstance(last,int) and 1<=last<=17
    code=code.replace("if(str_starts_with",f"if((int)basename($f)>{last})continue;if(str_starts_with")
    result=subprocess.run(PHP+['-r',code],cwd=ROOT,env=admin,text=True,capture_output=True,creationflags=db.FLAGS)
    if result.returncode: raise RuntimeError(result.stderr)
    db.sql("GRANT SELECT,INSERT,UPDATE,DELETE ON `"+cfg['name']+"`.* TO '"+cfg['user']+"'@'127.0.0.1';")
    return cfg,env

def run_test(test,env):
    result=subprocess.run(PHP+[str(ROOT/'tests'/test)],cwd=ROOT,env=env,text=True,capture_output=True,creationflags=db.FLAGS)
    out=ROOT/'.runtime/operations-work'/ (test+'.json')
    out.parent.mkdir(parents=True,exist_ok=True)
    out.write_text(result.stdout,encoding='utf-8')
    if result.returncode:
        print('FAIL',test, result.stderr[:1800], flush=True)
        try:
            data=json.loads(result.stdout);print(json.dumps([x for x in data.get('checks',[]) if not x['passed']],ensure_ascii=False),flush=True)
        except ValueError: print(result.stdout[-1600:])
    else:
        try: count=len(json.loads(result.stdout)['checks'])
        except (ValueError,KeyError,TypeError): count='all'
        print('PASS',test,count,'checks',flush=True)
    return result.returncode==0

if __name__=='__main__':
    suites=[['operational_completion.php','operational_followup.php'],['financial_completion_54.php'],['financial_sales_advanced.php','financial_sales_lines.php'],['financial_concurrency_53f.php'],['financial_contracts.php'],['financial_decimal.php'],['financial_sales_real.php'],['financial_lines_53c.php'],['financial_security_53c.php'],['financial_execution_53d.php'],['financial_transfers_expenses_53e.php']]
    ok=True
    for suite in suites:
        cfg,env=fresh()
        if suite[0]=='operational_completion.php':
            private=ROOT/'.runtime/operations-work/test-environment.json';private.write_text(json.dumps({'config':cfg,'environment':env}),encoding='utf-8')
        for test in suite:ok=run_test(test,env) and ok
    cfg,env=fresh(last=16)
    ok=run_test('operational_compatibility.php',env) and ok
    sys.exit(0 if ok else 1)
