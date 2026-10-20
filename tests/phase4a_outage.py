"""Real HTTP outage/recovery and PHP restart with one existing SQL administrator."""
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


def run():
    config=db.database();env=os.environ.copy();env.update(db.environment(config))
    private=ROOT/'storage/private'/('phase4a-outage-'+secrets.token_hex(8));private.mkdir(parents=True)
    env['CENTRO_PRIVATE_DIR']=str(private)
    password=secrets.token_urlsafe(24);email='phase4a-outage@ejemplo.test'
    init=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/create_admin.php'),'--email='+email,'--password-stdin'],input=password+'\n',text=True,env=env,capture_output=True)
    assert init.returncode==0
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    base='http://127.0.0.1:'+str(port)+'/'
    checks=[];process=None
    log=open(private/'server.log','a')
    def check(name,value):
        checks.append({'check':name,'passed':bool(value)})
        print(('PASS: ' if value else 'FAIL: ')+name,flush=True)
        assert value,name
    def start_php():
        p=subprocess.Popen(db.PHP_ARGS+['-S','127.0.0.1:'+str(port),'-t',str(ROOT/'public')],env=env,stdout=log,stderr=log,creationflags=db.FLAGS)
        for _ in range(60):
            try:
                urllib.request.urlopen(base+'login.php',timeout=.5).close();return p
            except OSError:time.sleep(.1)
        p.terminate();p.wait();raise RuntimeError('Test PHP did not start')
    try:
        process=start_php()
        with sync_playwright() as pw:
            request=pw.request.new_context()
            token=request.get(base+'api.php?action=session').json()['csrf']
            check('SQL login before restart',request.post(base+'api.php?action=login',headers={'X-CSRF-Token':token},data={'email':email,'password':password}).status==200)
            process.terminate();process.wait(timeout=10);process=start_php()
            check('PHP restart preserves authenticated session and SQL administrator',request.get(base+'api.php?action=session').json()['authenticated'])
            try:
                db.stop()
                response=request.get(base+'api.php?action=session')
                check('Database outage returns generic HTTP 503',response.status==503 and response.json()=={'ok':False,'code':'service_unavailable'})
                check('No SQL user, password or DSN exposed',all(s not in response.text() for s in [config['user'],config['password'],'SQLSTATE','mysql:host']))
                check('Dashboard fails closed while SQL is unavailable',request.get(base,max_redirects=0).status==503)
                check('No automatic fallback user file created',not (private/'auth.json').exists())
            finally:db.start()
            check('Same session recovers after database restart',request.get(base+'api.php?action=session').json()['authenticated'])
            check('Protected dashboard recovers',request.get(base).status==200)
            token=request.get(base+'api.php?action=session').json()['csrf']
            check('Logout after recovery destroys access',request.post(base+'api.php?action=logout',headers={'X-CSRF-Token':token}).status==200 and request.get(base,max_redirects=0).status==303)
    finally:
        if process is not None:process.terminate();process.wait(timeout=10)
        log.close()
        (ROOT/'preparation/phase4a-outage-results.json').write_text(json.dumps({'checks':checks,'expected_log_errors':'Two intentional unavailable-database requests; no SQL details in HTTP'},indent=2))


if __name__=='__main__':run()
