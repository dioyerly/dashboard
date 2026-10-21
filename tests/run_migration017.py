"""Apply the unchanged 017 through its guarded CLI to fresh synthetic 016 data."""
import hashlib
import json
import os
import secrets
import subprocess
import sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path[:0]=[str(ROOT/'tests'),str(ROOT/'tools')]
from run_operational_completion import fresh,run_test
import local_mariadb as db
import backup_local as backup
OUT=ROOT/'.runtime/migration017-validation'
OUT.mkdir(parents=True,exist_ok=True)
checks=[]
def check(name,ok):
    checks.append({'check':name,'passed':bool(ok)})
    print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    (OUT/'results.json').write_text(json.dumps({'checks':checks},indent=2))
    assert ok,name

def php(env,phase):
    p=subprocess.run(db.PHP_ARGS+[str(ROOT/'tests/migration017_contract.php'),phase],cwd=ROOT,env=env,text=True,capture_output=True,creationflags=db.FLAGS)
    (OUT/(phase+'-'+secrets.token_hex(4)+'.json')).write_text(p.stdout,encoding='utf-8')
    if p.returncode:raise RuntimeError('Migration contract phase failed: '+p.stderr[:500])
    data=json.loads(p.stdout)
    if 'checks' in data:check('all structural and post-upgrade contract checks pass',all(x['passed'] for x in data['checks']))
    return data

def registration(cfg):
    target=OUT/('target-'+cfg['name']+'.json')
    target.write_text(json.dumps({'purpose':'synthetic-operational-migration','database':cfg['name'],'host':'127.0.0.1','port':db.state()['port']}))
    user,password=db.prep_credentials()
    env=os.environ.copy();env.update(db.environment({**cfg,'user':user,'password':password or db.state()['root_password']}))
    return target,env,backup.dump(cfg)

def migrate(env,target=None,dump=None):
    args=[]
    if target is not None:args+=['--synthetic-target='+str(target)]
    if dump is not None:args+=['--backup='+str(dump)]
    p=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/migrate_operational_completion_local.php'),*args],cwd=ROOT,env=env,text=True,capture_output=True,creationflags=db.FLAGS)
    (OUT/('migration-'+secrets.token_hex(5)+'.log')).write_text(p.stdout+p.stderr,encoding='utf-8')
    return p

cfg,app=fresh(last=16)
check('only a newly created synthetic 016 schema is selected',cfg['name'].startswith('centro_auth_') and run_test('operational_compatibility.php',app))
php(app,'seed');before=php(app,'snapshot')
target,prep,dump=registration(cfg)
check('missing target authorization blocks migration',migrate(prep).returncode!=0)
check('application credentials do not have schema creation privileges',migrate(app,target,dump).returncode!=0)
check('failed unauthorized attempts leave all prior rows unchanged',php(app,'snapshot')==before)
r=migrate(prep,target,dump)
check('actual local CLI applies 017 after 001-016',r.returncode==0 and 'applied' in r.stdout)
check('upgrade preserves every pre-existing table row byte for byte',php(app,'snapshot')==before)
check('001-017 are recorded once with immutable source checksums',db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.schema_migrations;")=='17' and db.sql(f"SELECT checksum FROM `{cfg['name']}`.schema_migrations WHERE version='017_operational_completion';")==hashlib.sha256((ROOT/'database/migrations/017_operational_completion.sql').read_bytes()).hexdigest())
r=migrate(prep,target,dump)
check('repeated migration verifies checksum without writing rows',r.returncode==0 and 'already applied' in r.stdout and php(app,'snapshot')==before)
contract=php(app,'verify')
post=php(app,'snapshot')
check('migration repeats safely after actual 017 operations',migrate(prep,target,dump).returncode==0 and php(app,'snapshot')==post)
# A checksum mismatch is exercised only in this newly created synthetic schema.
name=cfg['name'];old=hashlib.sha256((ROOT/'database/migrations/017_operational_completion.sql').read_bytes()).hexdigest()
db.sql(f"UPDATE `{name}`.schema_migrations SET checksum=REPEAT('0',64) WHERE version='017_operational_completion';")
check('registered checksum mismatch is refused',migrate(prep,target,dump).returncode!=0 and php(app,'snapshot')==post)
db.sql(f"UPDATE `{name}`.schema_migrations SET checksum='{old}' WHERE version='017_operational_completion';")
# Preserve a deliberately partial synthetic schema for inspection; never auto-delete DDL.
partial,partial_app=fresh(last=16)
partial_target,partial_prep,partial_dump=registration(partial)
first=(ROOT/'database/migrations/017_operational_completion.sql').read_text().split(';')[0]
db.sql('USE `'+partial['name']+'`;'+first+';')
r=migrate(partial_prep,partial_target,partial_dump)
check('partial 017 DDL is refused without automatic retry or deletion',r.returncode!=0 and 'Partial or unregistered' in r.stderr and db.sql(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{partial['name']}' AND TABLE_NAME='fin_subscription_evidence';")=='0' and db.sql(f"SELECT COUNT(*) FROM `{partial['name']}`.schema_migrations;")=='16')
(OUT/'contract-results.json').write_text(json.dumps(contract,indent=2))
(OUT/'target-access.json').write_text(json.dumps({'config':cfg,'application_environment':app,'target':str(target)},indent=2))
print('PASS migration 017: '+str(len(checks))+' lifecycle checks + '+str(len(contract['checks']))+' SQL contract checks',flush=True)
