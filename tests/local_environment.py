"""Verify the portable local PHP setup and the public document root only."""
import hashlib
import json
import subprocess
import urllib.error
import urllib.request
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
PHP=ROOT/'.runtime/php/php.exe'
URL='http://127.0.0.1:8080'
checks=[]
version=subprocess.check_output([str(PHP),'-n','-r','echo PHP_VERSION;'],text=True).strip()
modules=subprocess.check_output([str(PHP),'-n','-m'],text=True).splitlines()
checks.append({'check':'Portable PHP >= 8.3','passed':tuple(map(int,version.split('.')[:2])) >= (8,3),'version':version})
lint=subprocess.run([str(PHP),'-n','-l',str(ROOT/'public/index.php')],capture_output=True,text=True)
checks.append({'check':'PHP entry syntax','passed':lint.returncode==0,'result':lint.stdout.strip()})
package=ROOT/'.runtime/php-8.4.26-nts-Win32-vs17-x64.zip'
digest=hashlib.sha256(package.read_bytes()).hexdigest()
checks.append({'check':'Official PHP package SHA256','passed':digest=='da68394f9193b7f6b89d0c76861a4034ae10efee7fd55a7255d8118c2acf70d7','sha256':digest})

def get(path):
    try:
        with urllib.request.urlopen(URL+path,timeout=10) as response:
            return response.status,response.headers.get('Content-Type'),response.read()
    except urllib.error.HTTPError as response:
        return response.code,response.headers.get('Content-Type'),response.read()

for file in (ROOT/'public').rglob('*'):
    if not file.is_file(): continue
    path='/'+file.relative_to(ROOT/'public').as_posix()
    status,mime,body=get(path)
    checks.append({'check':'HTTP exact content: '+path,'passed':status==200 and body==file.read_bytes(),'status':status,'mime':mime})
index=(ROOT/'public/index.php').read_bytes()
for path in ['/reference/centro-operaciones.html','/preparation/baseline.json','/storage/private/test.json','/tools/serve.ps1','/.runtime/php/php.exe']:
    status,mime,body=get(path)
    checks.append({'check':'Private path does not expose files: '+path,'passed':status==404 or (status==200 and body==index),'status':status,
                   'note':'PHP built-in server may return the public index as fallback; it must never return private contents.'})
checks.append({'check':'No Markdown added in project','passed':not list(ROOT.rglob('*.md'))})
report={'checks':checks,'php_modules':modules,'php_ini':'-n: no system php.ini loaded or changed',
        'source':'https://www.php.net/downloads.php?os=windows&version=8.4',
        'package_url':'https://downloads.php.net/~windows/releases/archives/php-8.4.26-nts-Win32-vs17-x64.zip',
        'scope':'Loopback-only PHP development server; no API, authentication, MySQL, Hostinger or deployment',
        'limitations':'Local runtime validation only, not Hostinger production configuration; pdo_mysql is not enabled in this phase'}
(ROOT/'preparation/phase2-environment.json').write_text(json.dumps(report,indent=2),encoding='utf-8')
for check in checks: print(('PASS' if check['passed'] else 'FAIL')+': '+check['check'])
assert all(check['passed'] for check in checks)
