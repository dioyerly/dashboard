"""Preserve the consolidated original views, styles and event bindings."""
import importlib.util,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE='e55f9f134153e3f47cc99e2e1f0e693171d57328'
spec=importlib.util.spec_from_file_location('extraction',ROOT/'tools/extract_frontend.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
checks=0
for name in ['app/views/dashboard.php','public/assets/css/app.css','public/assets/css/auth.css','reference/centro-operaciones.html','reference/centro-operaciones-DEMO.html']:
    original=subprocess.check_output(['git','show',BASE+':'+name],cwd=ROOT)
    assert original==(ROOT/name).read_bytes(),name
    checks+=1
for path in sorted((ROOT/'public/assets/js').glob('*.js')):
    original=subprocess.check_output(['git','show',BASE+':'+path.relative_to(ROOT).as_posix()],cwd=ROOT)
    before=module.inventory(original);after=module.inventory(path.read_bytes())
    for key in ['screens','actions','change_handlers','input_handlers','forms','identifiers','data_attributes','event_listeners','reports']:
        assert before[key]==after[key],(path.name,key)
        checks+=1
assert sum(len(module.inventory(p.read_bytes())['screens']) for p in (ROOT/'public/assets/js').glob('*.js'))==13
checks+=1
print(f'PASS {checks} checks: original HTML/CSS, references, 13 views and original UI bindings preserved')
