"""CLI for offline diagnosis and synthetic-only PDO partial backup staging.

Examples (all data/credentials/reports private, no HTTP endpoints):
 python tools/backup_local.py diagnose --source .runtime/sample.json --environment pruebas
 python tools/backup_local.py prepare
 python tools/backup_local.py import --target <printed-private-target-path> --source .runtime/sample.json --environment pruebas --operation import_<stable-id>
 python tools/backup_local.py verify --target <path> --source .runtime/sample.json --environment pruebas --operation import_<same-id>
 python tools/backup_local.py export --target <path> --environment pruebas --operation import_<same-id>
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys

import local_mariadb as db
from backup_model import Model, TABLES, projection, digest

ROOT=Path(__file__).resolve().parents[1]
TARGETS=db.RUNTIME/'import-targets'
OUTPUT=db.RUNTIME/'backup-imports'


def private_path(path):
    resolved=Path(path).resolve()
    if not resolved.is_relative_to((ROOT/'.runtime').resolve()):
        raise ValueError('private_runtime_path_required')
    return resolved


def source(path):
    resolved=Path(path).resolve()
    if not resolved.is_relative_to(ROOT.resolve()):raise ValueError('source_must_be_inside_project')
    return resolved.read_bytes()


def environment(target):
    target=private_path(target)
    if target.parent!=TARGETS.resolve():raise ValueError('registered_target_required')
    cfg=json.loads(target.read_text('utf-8'))
    if cfg.get('purpose')!='synthetic-backup-import' or cfg.get('database')!=cfg.get('name') or cfg['host']!='127.0.0.1' or cfg['port']!=db.state()['port']:
        raise ValueError('registered_target_required')
    env=os.environ.copy();env.update(db.environment(cfg));env['CENTRO_BUSINESS_API_ENABLED']='0'
    return cfg,env


def bridge(target,body):
    cfg,env=environment(target)
    p=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/backup_bridge.php')],input=json.dumps(body,ensure_ascii=False),env=env,cwd=ROOT,
                     capture_output=True,text=True,encoding='utf-8',creationflags=db.FLAGS)
    try:return json.loads(p.stdout)
    except (ValueError,TypeError):return {'ok':False,'code':'private_bridge_failure'}


def dump(cfg):
    OUTPUT.mkdir(parents=True,exist_ok=True)
    path=OUTPUT/('destination-'+secrets.token_hex(12)+'.sql')
    with path.open('xb') as f:
        p=subprocess.run([str(db.RUNTIME/'bin/mariadb-dump.exe'),'--defaults-file='+str(db.RUNTIME/'client.ini'),
            '--host=127.0.0.1','--port='+str(db.state()['port']),'--protocol=tcp','--skip-ssl','--single-transaction',cfg['name']],
            stdout=f,stderr=subprocess.PIPE,creationflags=db.FLAGS)
    if p.returncode or path.stat().st_size<100:raise ValueError('destination_backup_failed')
    Path(str(path)+'.json').write_text(json.dumps({'database':cfg['name'],'sha256':hashlib.sha256(path.read_bytes()).hexdigest()}),encoding='utf-8')
    return path


def prepare(profile='block3',demo_costs=False):
    """Only creates a new schema; cannot nominate the review or an existing schema."""
    if profile not in ('block3','block4','block7') or demo_costs and profile!='block4':raise ValueError('invalid_profile')
    db.start();cfg=db.database()
    # Register only the freshly created synthetic schema before any extended DDL.
    # A failed preparation remains private and unusable until migration gates pass.
    TARGETS.mkdir(parents=True,exist_ok=True)
    marker={**cfg,'database':cfg['name'],'host':'127.0.0.1','port':db.state()['port'],'purpose':'synthetic-backup-import',
            'profile':profile,'normalize_known_demo_costs':bool(demo_costs)}
    path=TARGETS/(cfg['name']+'.json')
    with path.open('x',encoding='utf-8') as f:json.dump(marker,f)
    rootenv=os.environ.copy();rootenv.update(db.environment({'name':cfg['name'],'user':'root','password':db.state()['root_password']}))
    for name,extra in [('migrate_business_local.php',[]),('migrate_catalogs_local.php',[]),
                       ('migrate_catalogs_local.php',['--migration=004_project_stats_optional_origin'])]:
        backup=dump(cfg)
        p=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools'/name),'--backup='+str(backup),*extra],env=rootenv,cwd=ROOT,capture_output=True,creationflags=db.FLAGS)
        if p.returncode:raise ValueError('synthetic_target_migration_failed')
    if profile in ('block4','block7'):
        for migration in ('005_configuration_rates','006_historical_absence'):
            p=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/migrate_catalogs_local.php'),'--backup='+str(dump(cfg)),'--migration='+migration],
                env=rootenv,cwd=ROOT,capture_output=True,creationflags=db.FLAGS)
            if p.returncode:raise ValueError('configuration_migration_failed')
    if profile=='block7':
        p=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/migrate_administrative_activity_local.php'),'--backup='+str(dump(cfg))],env=rootenv,cwd=ROOT,capture_output=True,creationflags=db.FLAGS)
        if p.returncode:raise ValueError('activity_migration_failed')
        p=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/migrate_activity_source_metadata_local.php'),'--backup='+str(dump(cfg))],env=rootenv,cwd=ROOT,capture_output=True,creationflags=db.FLAGS)
        if p.returncode:raise ValueError('activity_metadata_migration_failed')
    # A distinct temporary administrator, never copied from file auth/review or the backup.
    password=secrets.token_urlsafe(32)
    p=subprocess.run(db.PHP_ARGS+[str(ROOT/'tools/create_admin.php'),'--email=backup-import@ejemplo.test','--password-stdin'],
                     input=password+'\n',text=True,env={**rootenv,'CENTRO_PRIVATE_DIR':str(OUTPUT/'auth-private')},capture_output=True,creationflags=db.FLAGS)
    if p.returncode:raise ValueError('synthetic_administrator_failed')
    from configuration_model import EXTRA_TABLES
    from backup_model import ACTIVITY_TABLES
    for table in TABLES+(EXTRA_TABLES if profile in ('block4','block7') else [])+(ACTIVITY_TABLES if profile=='block7' else [])+['environments','business_operations','business_audit']:
        db.sql(f"GRANT SELECT,INSERT ON `{cfg['name']}`.`{table}` TO '{cfg['user']}'@'127.0.0.1';")
    db.sql(f"GRANT SELECT ON `{cfg['name']}`.business_page_cursors TO '{cfg['user']}'@'127.0.0.1';")
    return path


def model_for(raw,environment_name,target=None,profile='block3',demo_costs=False):
    if target:
        cfg,_=environment(target);profile=cfg.get('profile','block3');demo_costs=cfg.get('normalize_known_demo_costs',False)
    if profile in ('block4','block7'):
        from configuration_model import ConfigurationModel
        model=ConfigurationModel(raw,environment_name,demo_costs,activity=profile=='block7')
        if profile=='block7':
            from configuration_model import EXTRA_TABLES
            ordered=TABLES+EXTRA_TABLES+__import__('backup_model').ACTIVITY_TABLES
            model.rows={k:model.rows[k] for k in ordered}
        return model
    return Model(raw,environment_name,activity=profile=='block7')


def diagnose(raw,environment_name,target=None,profile='block3',demo_costs=False):
    model=model_for(raw,environment_name,target,profile,demo_costs);report=model.report()
    if target and report['can_import']:
        result=bridge(target,{'action':'preflight','plan':model.plan()})
        report['sql_preflight']=result
        if not result['ok']:report['can_import']=False
    return model,report


def import_backup(target,raw,environment_name,operation,fail_after=0,lose_ack=False,backup=None):
    model,report=diagnose(raw,environment_name,target)
    if not report['can_import']:return {'ok':False,'code':'prevalidation_rejected','diagnosis':report}
    OUTPUT.mkdir(parents=True,exist_ok=True)
    # Preserve the entire original, including unsupported data, privately and byte for byte.
    # This archive is evidence only; neither SQL import nor export reads business values from it.
    archive=OUTPUT/('source-'+model.raw_hash+'.json')
    try:
        with archive.open('xb') as f:f.write(raw)
    except FileExistsError:
        if hashlib.sha256(archive.read_bytes()).hexdigest()!=model.raw_hash:
            raise ValueError('private_source_archive_mismatch')
    cfg,_=environment(target)
    backup=Path(backup) if backup else dump(cfg)
    result=bridge(target,{'action':'import','plan':model.plan(),'operation':operation,'backup':str(backup),'fail_after':fail_after,'lose_ack':lose_ack})
    # Persistent verification without recording rows/secrets in public preparation artifacts.
    OUTPUT.mkdir(parents=True,exist_ok=True)
    audit=OUTPUT/('result-'+secrets.token_hex(12)+'.json')
    audit.write_text(json.dumps({'operation':operation,'source_sha256':model.raw_hash,'result':result},ensure_ascii=False,indent=2),encoding='utf-8')
    return result


def export_backup(target,environment_name,operation):
    result=bridge(target,{'action':'export','environment':environment_name,'operation':operation})
    if not result['ok']:return result
    data=result['data']
    return {'ok':True,'complete_migration':False,'supported_original':projection(data['receipt']['mapping'],data['rows']),
            'normalized_entities':data['rows'],'receipt':data['receipt'],'environment':data['environment']}


def compare(target,raw,environment_name,operation):
    model=model_for(raw,environment_name,target)
    if not model.report()['can_import']:return {'ok':False,'code':'prevalidation_rejected'}
    exported=export_backup(target,environment_name,operation)
    if not exported['ok']:return exported
    expected=projection(model.mapping,model.rows)
    checks=[]
    for table,rows in model.rows.items():
        actual={r['id']:r for r in exported['normalized_entities'][table]}
        passed=len(actual)==len(rows) and all(r['id'] in actual and all(
            str(actual[r['id']].get(k))==str(v) for k,v in r.items()) for r in rows)
        checks.append({'table':table,'expected':len(rows),'actual':len(actual),'fields_ids_relationships_equal':passed})
    source_bound=exported['receipt']['source_sha256']==model.raw_hash
    projection_equal=expected==exported['supported_original']
    env_equal=exported['environment']['id']==environment_name and exported['environment']['code']==environment_name
    return {'ok':source_bound and projection_equal and env_equal and all(c['fields_ids_relationships_equal'] for c in checks),
            'complete_migration':False,'source_sha256_matches':source_bound,'supported_projection_equal':projection_equal,
            'environment_matches':env_equal,
            'supported_projection_sha256':digest(expected),'tables':checks,
            'normalizations':model.normalizations,'pending':model.report()['issues']}


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('action',choices=['diagnose','prepare','import','export','verify'])
    p.add_argument('--source');p.add_argument('--target');p.add_argument('--environment',choices=['produccion','pruebas'])
    p.add_argument('--operation');p.add_argument('--output')
    p.add_argument('--profile',choices=['block3','block4','block7'],default='block3')
    p.add_argument('--normalize-known-demo-costs',action='store_true',help='Explicit synthetic-DEMO-only correction; original bytes retained')
    a=p.parse_args()
    try:
        if a.action=='prepare':
            result={'ok':True,'private_target':str(prepare(a.profile,a.normalize_known_demo_costs))}
        else:
            if not a.environment:raise ValueError('explicit_environment_required')
            if a.action=='diagnose':result=diagnose(source(a.source),a.environment,a.target,a.profile,a.normalize_known_demo_costs)[1]
            elif a.action=='import':result=import_backup(a.target,source(a.source),a.environment,a.operation)
            elif a.action=='verify':result=compare(a.target,source(a.source),a.environment,a.operation)
            else:result=export_backup(a.target,a.environment,a.operation)
        # Potentially sensitive exports/diagnoses never go to stdout or versioned directories.
        OUTPUT.mkdir(parents=True,exist_ok=True)
        path=private_path(a.output) if a.output else OUTPUT/(a.action+'-'+secrets.token_hex(12)+'.json')
        with path.open('x',encoding='utf-8') as f:json.dump(result,f,ensure_ascii=False,indent=2)
        print(json.dumps({'ok':result.get('ok',result.get('can_import',False)),'private_report':str(path),
                          **({'private_target':result['private_target']} if 'private_target' in result else {})}))
        return 0 if result.get('ok',result.get('can_import',False)) else 1
    except (ValueError,TypeError,OSError,KeyError):
        print(json.dumps({'ok':False,'code':'local_backup_command_failed'}));return 1


if __name__=='__main__':sys.exit(main())
