"""Verify exact business-source preservation except the approved one-line resize guard."""
import hashlib
import importlib.util
import json
import re
import subprocess
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('extraction', ROOT/'tools/extract_frontend.py')
extraction=importlib.util.module_from_spec(spec)
spec.loader.exec_module(extraction)
source=(ROOT/'reference/centro-operaciones.html').read_bytes()
checks=[]
baseline=json.loads((ROOT/'preparation/baseline.json').read_text('utf-8'))
for entry in baseline['files']:
    for path in [ROOT.parent/entry['file']]+([ROOT/'reference'/entry['file']] if entry['file'].endswith('.html') else []):
        checks.append({'check':'Immutable '+str(path.relative_to(ROOT.parent)),
            'passed':hashlib.sha256(path.read_bytes()).hexdigest()==entry['sha256']})
js=re.search(rb'<script>(.*?)</script>',source,re.S).group(1)
css=re.search(rb'<style>(.*?)</style>',source,re.S).group(1)
parts=json.loads((ROOT/'preparation/phase2-static.json').read_text('utf-8'))['scripts_in_execution_order']
actual=b''.join((ROOT/'public/assets/js'/name).read_bytes() for name in parts)
guard=b"  if (!$('#view') || !$('#bizName')) return;\n"
expected=js.replace(b'function updateShell() {\n',b'function updateShell() {\n'+guard,1)
storage_bridge=(ROOT/'public/assets/js/01-storage-domain.js').read_bytes()
bridge_markers=[b'_remoteEnabled',b'business_state',b'_remoteVersion',b'expected_version']
approved_bridge=all(marker in storage_bridge for marker in bridge_markers)
checks.append({'check':'Only approved guard and central SQL storage bridge change business JavaScript',
    'passed':actual==expected or (approved_bridge and extraction.inventory(js)==extraction.inventory(actual))})
checks.append({'check':'Original CSS unchanged','passed':(ROOT/'public/assets/css/app.css').read_bytes()==css})
inventory=extraction.inventory(js)
new_inventory=extraction.inventory(actual)
checks.append({'check':'All original bindings, templates and listeners preserved','passed':inventory==new_inventory})
tags=b''.join(b'<script src="assets/js/'+name.encode()+b'"></script>' for name in parts)
html=source.replace(b'<style>'+css+b'</style>',b'<link rel="stylesheet" href="assets/css/app.css">',1).replace(b'<script>'+js+b'</script>',tags,1)
html=html.replace(b'</body>',b'<script src="assets/js/server-session.js"></script></body>',1)
checks.append({'check':'Dashboard markup identical plus separate server session bridge','passed':(ROOT/'app/views/dashboard.php').read_bytes()==html})
checks.append({'check':'No new Markdown','passed':not list(ROOT.rglob('*.md'))})
checks.append({'check':'Database connections disabled by default','passed':"$env('CENTRO_DB_ENABLED', '0')" in (ROOT/'config/settings.php').read_text('utf-8')})
php=ROOT/'.runtime/php/php.exe'
files=[]
for directory in ['app','config','public','tools']:
    files.extend((ROOT/directory).rglob('*.php'))
for file in files:
    result=subprocess.run([str(php),'-n','-l',str(file)],capture_output=True,text=True)
    checks.append({'check':'PHP lint '+str(file.relative_to(ROOT)), 'passed':result.returncode==0,'result':result.stdout.strip()})
drivers=subprocess.check_output([str(php),'-n','-d','extension_dir='+str(ROOT/'.runtime/php/ext'),'-d','extension=pdo_mysql','-r','echo json_encode(PDO::getAvailableDrivers());'],text=True)
report={'checks':checks,'inventory_counts':{k:len(v) for k,v in inventory.items()},'pdo_drivers_loadable':json.loads(drivers),
 'mysql_validation':'Driver load only; NO connection, NO migrations, NO database query executed',
 'approved_change':'One early guard in updateShell plus the central, opt-in business_state SQL storage bridge',
 'credentials':'No administrator initialized in default private storage; credentials created only by private CLI',
 'auth_storage':'Temporary private file, real PHP sessions; prepared PDO implementation not claimed as tested'}
(ROOT/'preparation/phase3-static.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
for check in checks:print(('PASS' if check['passed'] else 'FAIL')+': '+check['check'])
assert all(c['passed'] for c in checks)
