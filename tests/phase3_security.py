"""PHP authentication tests: file by default, isolated local MariaDB by explicit opt-in."""
import argparse
import contextlib
import json
import os
import secrets
import socket
import subprocess
import tempfile
import time
import sys
import urllib.request
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
PHP=ROOT/'.runtime/php/php.exe'
MYSQL=os.environ.get('CENTRO_TEST_AUTH_DRIVER') == 'mysql'
if MYSQL:
    sys.path.insert(0,str(ROOT/'tools'))
    import local_mariadb
PHP_ARGS=local_mariadb.PHP_ARGS if MYSQL else [str(PHP),'-n']
OUT=Path(os.environ.get('CENTRO_TEST_OUTPUT',str(ROOT/'preparation/phase3-browser')))
OUT.mkdir(exist_ok=True)
EMAIL='phase3-test@ejemplo.test'

@contextlib.contextmanager
def server(**options):
    private=Path(tempfile.mkdtemp(prefix='phase3-test-',dir=ROOT/'storage/private'))
    password=secrets.token_urlsafe(24)
    env=os.environ.copy()
    env.update({'CENTRO_PRIVATE_DIR':str(private),'CENTRO_ENV':'local','CENTRO_AUTH_DRIVER':'file','CENTRO_DB_ENABLED':'0',
        'CENTRO_IDLE_SECONDS':'600','CENTRO_ABSOLUTE_SECONDS':'1800','CENTRO_RATE_WINDOW':'3'})
    if MYSQL:
        env.update(local_mariadb.environment(local_mariadb.database()))
    env.update(options)
    init=subprocess.run(PHP_ARGS+[str(ROOT/'tools/create_admin.php'),'--email='+EMAIL,'--password-stdin'],input=password+'\n',text=True,capture_output=True,env=env)
    assert init.returncode==0,init.stderr
    with socket.socket() as s:
        s.bind(('127.0.0.1',0))
        port=s.getsockname()[1]
    log=open(private/'server.log','w',encoding='utf-8')
    process=subprocess.Popen(PHP_ARGS+['-S','127.0.0.1:'+str(port),'-t',str(ROOT/'public')],
        env=env,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
    base='http://127.0.0.1:'+str(port)+'/'
    try:
        for _ in range(60):
            if process.poll() is not None: raise RuntimeError('Test PHP server stopped')
            try:
                urllib.request.urlopen(base+'login.php',timeout=.5).close()
                break
            except urllib.error.HTTPError as error:
                if options.get('CENTRO_ENV')=='production' and error.code==426:break
                time.sleep(.1)
            except OSError:time.sleep(.1)
        else:raise RuntimeError('Test PHP server unavailable')
        yield base,password,private,env
    finally:
        process.terminate()
        process.wait(timeout=10)
        log.close()


def login(request,base,password):
    session=request.get(base+'api.php?action=session').json()
    result=request.post(base+'api.php?action=login',headers={'X-CSRF-Token':session['csrf']},data={'email':EMAIL,'password':password})
    return result,session['csrf']


def admin_record(private,env):
    if not MYSQL:
        return json.loads((private/'auth.json').read_text('utf-8'))['admin']
    result=subprocess.run(PHP_ARGS+['-r',"require 'app/bootstrap.php'; echo json_encode($store->admin());"],
        cwd=ROOT,env=env,capture_output=True,text=True)
    assert result.returncode==0,'Cannot read isolated SQL test administrator'
    return json.loads(result.stdout)


def security():
    report={'checks':[],'storage':'Real private file auth and PHP sessions, not a MySQL simulation',
        'mysql':'Local MariaDB via PDO' if MYSQL else 'Not connected','browser_errors':[]}
    if MYSQL:report['storage']='MariaDB administrator and rate limits; private PHP file sessions'
    def check(name,condition,**detail):
        report['checks'].append({'check':name,'passed':bool(condition),**detail})
        print(('PASS' if condition else 'FAIL')+': '+name,flush=True)
        assert condition,name
    try:
        with sync_playwright() as pw,server() as (base,password,private,env):
            request=pw.request.new_context()
            response=request.get(base,max_redirects=0)
            check('Anonymous dashboard blocked before HTML is emitted',response.status==303 and 'login.php' in response.headers['location'] and 'id="root"' not in response.text())
            check('Private API unauthenticated returns 401 JSON',request.get(base+'api.php?action=business').status==401)
            check('Login API enforces POST',request.get(base+'api.php?action=login').status==405)
            check('Login rejects missing CSRF',request.post(base+'api.php?action=login',data={'email':EMAIL,'password':password}).status==403)
            status=request.get(base+'api.php?action=session')
            token=status.json()['csrf']
            check('Local storage mode explicitly reported',status.json()['business_storage']=='local' and status.json()['mysql_sync'] is False)
            cookies=request.storage_state()['cookies']
            before=next(c for c in cookies if c['name']=='CENTROSESSID')
            check('Cookie is HttpOnly and SameSite Strict',before['httpOnly'] and before['sameSite']=='Strict')
            wrong=request.post(base+'api.php?action=login',headers={'X-CSRF-Token':token},data={'email':EMAIL,'password':secrets.token_urlsafe(24)})
            check('Wrong password refused generically',wrong.status==401 and wrong.json()['code']=='invalid_credentials')
            unknown=request.post(base+'api.php?action=login',headers={'X-CSRF-Token':token},data={'email':'unknown@ejemplo.test','password':password})
            check('Unknown email receives same error',unknown.status==401 and unknown.json()==wrong.json())
            success,old_csrf=login(request,base,password)
            check('Correct credentials authenticate with password_verify',success.status==200 and success.json()['ok'])
            after=next(c for c in request.storage_state()['cookies'] if c['name']=='CENTROSESSID')
            check('Session ID regenerates at authentication',before['value']!=after['value'])
            token=request.get(base+'api.php?action=session').json()['csrf']
            check('CSRF token rotates at authentication',token!=old_csrf)
            check('Old CSRF cannot log out authenticated session',request.post(base+'api.php?action=logout',headers={'X-CSRF-Token':old_csrf}).status==403)
            check('Missing CSRF cannot touch session',request.post(base+'api.php?action=touch').status==403)
            check('Authenticated dashboard contains original shell entry',request.get(base).status==200 and 'id="root"' in request.get(base).text())
            check('Business writes are not implemented',request.post(base+'api.php?action=sales',headers={'X-CSRF-Token':token},data={}).status==404)
            check('No registration endpoint',request.post(base+'api.php?action=register',data={}).status==404 and request.get(base+'register.php').status==404)
            paths=['app/views/dashboard.php','config/settings.php','storage/private/auth/auth.json','storage/private/auth/sessions/test','database/migrations/001_auth.sql','tools/create_admin.php','.runtime/php/php.exe','reference/centro-operaciones.html','preparation/phase3-static.json','../config/settings.php','%2e%2e/config/settings.php']
            for path in paths:
                response=request.get(base+path,max_redirects=0)
                check('HTTP cannot retrieve private path: '+path,response.status==404)
            record=admin_record(private,env)
            check('Only a password hash is persisted',record['password_hash'].startswith('$2') and password not in json.dumps(record))
            second=subprocess.run(PHP_ARGS+[str(ROOT/'tools/create_admin.php'),'--email=second@ejemplo.test','--password-stdin'],input=secrets.token_urlsafe(24)+'\n',text=True,capture_output=True,env=env)
            check('Private initialization refuses a second administrator',second.returncode!=0 and admin_record(private,env)==record)
            check('Credentials absent from all public source files',all(password.encode() not in f.read_bytes() and record['password_hash'].encode() not in f.read_bytes() for f in (ROOT/'public').rglob('*') if f.is_file()))
            captured=after['value']
            response=request.post(base+'api.php?action=logout',headers={'X-CSRF-Token':token})
            check('Logout destroys server session',response.status==200 and request.get(base+'api.php?action=business').status==401)
            replay=pw.request.new_context(extra_http_headers={'Cookie':'CENTROSESSID='+captured})
            check('Destroyed session ID cannot be replayed',replay.get(base+'api.php?action=business').status==401)
            # Fresh sessions do not reset the rate-limit buckets.
            success,_=login(request,base,password)
            check('Fresh login after logout works',success.status==200)
            for _ in range(5):
                fresh=pw.request.new_context()
                result,_=login(fresh,base,secrets.token_urlsafe(24))
                fresh.dispose()
                check('Failure counted across independent sessions',result.status==401)
            fresh=pw.request.new_context()
            result,_=login(fresh,base,password)
            check('Repeated attempts blocked even with correct password',result.status==429 and int(result.headers['retry-after'])>0)
            time.sleep(3.1)
            result,_=login(fresh,base,password)
            check('Access recovers after configured limit window',result.status==200)
            browser=pw.chromium.launch(headless=True)
            font_root=ROOT/'tests/assets/fonts'
            manifest=json.loads((font_root/'manifest.json').read_text())
            def fonts(route):
                if route.request.url==manifest['css_url']:route.fulfill(path=str(font_root/manifest['css_file']),content_type='text/css')
                elif route.request.url in manifest['assets']:route.fulfill(path=str(font_root/manifest['assets'][route.request.url]['file']),content_type='font/ttf')
                else:route.abort()
            for name,width,height in [('desktop',1440,900),('mobile',390,844)]:
                context=browser.new_context(viewport={'width':width,'height':height},reduced_motion='reduce')
                context.route('https://fonts.googleapis.com/**',fonts)
                context.route('https://fonts.gstatic.com/**',fonts)
                page=context.new_page()
                page.on('pageerror',lambda error:report['browser_errors'].append(str(error)))
                page.goto(base)
                page.wait_for_selector('#server-login button:not([disabled])')
                page.evaluate('document.fonts.ready')
                page.bring_to_front()
                page.evaluate('Promise.all(document.getAnimations().map(animation => animation.finished))')
                page.evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))')
                page.screenshot(path=str(OUT/f'login-{name}.png'),full_page=True)
                check('Login form fits '+name,page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
                page.locator('#email').fill(EMAIL)
                page.locator('#password').fill(password)
                page.locator('#server-login button').click()
                page.wait_for_selector('#obForm')
                check('Browser login reaches protected onboarding: '+name,page.url.endswith('index.php'))
                page.keyboard.press('Alt+Shift+q')
                page.wait_for_url('**/logout.php')
                page.locator('button[type=submit]').click()
                page.wait_for_selector('#server-login')
                check('Account shortcut and real logout: '+name,page.url.endswith('login.php'))
                context.close()
            browser.close()
            check('Login and logout browser flows have no JS errors',not report['browser_errors'])
        for expiry,settings in [('idle',{'CENTRO_IDLE_SECONDS':'2'}),('absolute',{'CENTRO_ABSOLUTE_SECONDS':'2'})]:
            with sync_playwright() as pw,server(**settings) as (base,password,private,env):
                request=pw.request.new_context()
                success,old=login(request,base,password)
                check(expiry+' expiry setup authenticated',success.status==200)
                token=request.get(base+'api.php?action=session').json()['csrf']
                time.sleep(2.2)
                expired=request.post(base+'api.php?action=touch',headers={'X-CSRF-Token':token})
                check(expiry+' expiry returns coherent 401',expired.status==401 and expired.json()['code']=='session_expired')
                check(expiry+' expiry blocks dashboard',request.get(base,max_redirects=0).status==303)
        tls_code="require 'app/Security.php'; $_SERVER['HTTP_X_FORWARDED_PROTO']='https'; $untrusted=Centro\\Security::secureTransport(); $_SERVER['HTTPS']='on'; echo json_encode(['forwarded'=>$untrusted,'https'=>Centro\\Security::secureTransport()]);"
        tls=json.loads(subprocess.check_output([str(PHP),'-n','-r',tls_code],cwd=ROOT,text=True))
        check('HTTPS detection requires server flag, not spoofable forwarded header',tls=={'forwarded':False,'https':True})
        with server(CENTRO_ENV='production') as (base,password,private,env):
            try:urllib.request.urlopen(base+'login.php')
            except urllib.error.HTTPError as error:check('Production refuses plaintext HTTP',error.code==426)
    except Exception as error:
        report['checks'].append({'check':'Security suite completed','passed':False,'error_type':type(error).__name__})
        raise
    finally:
        (OUT/'security-results.json' if MYSQL or os.environ.get('CENTRO_TEST_OUTPUT') else ROOT/'preparation/phase3-security.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')


def regression(suites):
    for suite in suites:
        with server(CENTRO_RATE_WINDOW='900') as (base,password,private,env):
            env.update({'CENTRO_TEST_PHASE3':'1','CENTRO_TEST_LOGIN_EMAIL':EMAIL,'CENTRO_TEST_LOGIN_PASSWORD':password})
            print('Regression suite: '+suite,flush=True)
            result=subprocess.run([os.sys.executable,str(ROOT/'tests/frontend_equivalence.py'),'--suite',suite,'--base',base],env=env)
            assert result.returncode==0,'Regression failed: '+suite


if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--suite',choices=['security','regression'],required=True)
    parser.add_argument('--regression-suites',nargs='+',choices=['resize','controls','visual','motion'],default=['resize','controls','visual','motion'])
    args=parser.parse_args()
    if args.suite=='security':security()
    else:regression(args.regression_suites)
