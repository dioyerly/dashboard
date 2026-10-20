"""Real local PDO, restart, outage and concurrency checks. No remote SQL."""
import concurrent.futures
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import local_mariadb as db


def php(code,env,input=None):
    result=subprocess.run(db.PHP_ARGS+['-r',code],cwd=ROOT,env=env,input=input,text=True,capture_output=True,creationflags=db.FLAGS)
    if result.returncode:raise RuntimeError('Isolated PHP check failed (details withheld)')
    return result.stdout


def run():
    checks=[]
    def check(name,value):
        checks.append({'check':name,'passed':bool(value)})
        print(('PASS: ' if value else 'FAIL: ')+name,flush=True)
        assert value,name
    db.start()
    config=db.database(); env=os.environ.copy();env.update(db.environment(config))
    private=ROOT/'storage/private'/('phase4a-'+secrets.token_hex(8));private.mkdir(parents=True)
    env['CENTRO_PRIVATE_DIR']=str(private)
    password=secrets.token_urlsafe(24)
    result=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/create_admin.php'),'--email=phase4a@ejemplo.test','--password-stdin'],
        input=password+'\n',env=env,cwd=ROOT,text=True,capture_output=True,creationflags=db.FLAGS)
    check('Administrator initialized through existing private CLI',result.returncode==0)
    result=subprocess.run(db.PHP_ARGS+[str(ROOT/'tests/phase4a_pdo.php')],input=password+'\n',env=env,cwd=ROOT,text=True,capture_output=True,creationflags=db.FLAGS)
    check('PDO schema, constraints and transaction suite',result.returncode==0)
    detail=json.loads(result.stdout)
    code="require 'app/bootstrap.php'; echo hash('sha256',json_encode($store->admin()));"
    before=php(code,env)
    check('Administrator survives independent PHP processes',php(code,env)==before)
    key=secrets.token_hex(32)
    worker="require 'app/bootstrap.php'; echo $store->attempt(['"+key+"'=>5],time(),900);"
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        results=list(pool.map(lambda _:php(worker,env),range(8)))
    check('Concurrent rate limiter admits exactly five of eight attempts',results.count('0')==5 and sum(int(x)>0 for x in results)==3)
    db.stop()
    try:
        sanitized=php("require 'app/Database.php'; $c=require 'config/settings.php'; try {Centro\\Database::connection($c['db']); exit(2);} catch(RuntimeException $e){echo $e->getMessage();}",env)
        check('Connection outage returns sanitized exception',sanitized=='Database unavailable')
    finally:db.start()
    check('Administrator survives MariaDB restart',php(code,env)==before)
    check('PDO reconnects after restart',php("require 'app/bootstrap.php'; echo $store->admin()!==null?'ok':'missing';",env)=='ok')
    # Retain only ignored private configuration for the subsequent outage/review tests.
    (ROOT/'.runtime/mariadb/pdo-test.json').write_text(json.dumps({'config':config,'private':str(private)},indent=2))
    out={'checks':checks,'pdo':detail,'version':db.sql('SELECT VERSION();'),'bind_address':db.sql("SELECT @@bind_address;"),'port':db.state()['port'],
         'migration':'001_auth.sql','remote_connections':False}
    (ROOT/'preparation/phase4a-pdo-results.json').write_text(json.dumps(out,indent=2),encoding='utf-8')


if __name__=='__main__':run()
