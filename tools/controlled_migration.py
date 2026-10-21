"""Local preparation tool only; production uses native JavaScript and PHP.
Never reads a browser profile, nominates an existing schema or activates real SQL.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import backup_local as backup
import local_mariadb as db

ROOT=Path(__file__).resolve().parents[1]
HISTORICAL={'sales':'original_financial_snapshots','expenses':'original_financial_snapshots','treasury':'original_financial_snapshots','resources':'original_activity_snapshots','events':'original_activity_snapshots','review':'original_activity_snapshots','log':'original_activity_snapshots'}
OPERATIONAL={'projects':'projects','customers':'customers','products':'products','plans':'plans','rates':'exchange_rates','suppliers':'suppliers','subs':'subscriptions','timeLogs':'time_logs','integrations':'integrations'}


def call(args,env=None,input=None):
    p=subprocess.run(db.PHP_ARGS+[str(x) for x in args],cwd=ROOT,env=env,input=input,capture_output=True,text=True,encoding='utf-8',creationflags=db.FLAGS)
    if p.returncode:raise ValueError('controlled_php_step_failed')
    return p.stdout


def prepare_target(out):
    cfg=db.database()
    marker={**cfg,'database':cfg['name'],'host':'127.0.0.1','port':db.state()['port'],'purpose':'synthetic-backup-import','profile':'block7','normalize_known_demo_costs':False}
    backup.TARGETS.mkdir(parents=True,exist_ok=True)
    target=backup.TARGETS/(cfg['name']+'.json')
    with target.open('x',encoding='utf-8') as f:json.dump(marker,f)
    user,password=db.prep_credentials();admin=os.environ.copy();admin.update(db.environment({**cfg,'user':user,'password':password or db.state()['root_password']}))
    gate=out/'synthetic-target.json'
    gate.write_text(json.dumps({'purpose':'synthetic-operational-migration','database':cfg['name'],'host':'127.0.0.1','port':db.state()['port']}))
    steps=[('migrate_business_local.php',[]),('migrate_catalogs_local.php',[])]
    steps += [('migrate_catalogs_local.php',['--migration='+v]) for v in ['004_project_stats_optional_origin','005_configuration_rates','006_historical_absence']]
    steps += [(name,[]) for name in ['migrate_financial_local.php','migrate_financial_lines_local.php','migrate_financial_transfers_local.php','migrate_financial_revisions_local.php','migrate_financial_line_revisions_local.php','migrate_administrative_activity_local.php','migrate_activity_source_metadata_local.php','migrate_original_snapshots_local.php','migrate_original_activity_local.php','migrate_business_state_local.php']]
    steps += [('migrate_operational_completion_local.php',['--synthetic-target='+str(gate)])]
    for name,extra in steps:
        dump=backup.dump(cfg)
        call([ROOT/'tools'/name,'--backup='+str(dump),*extra],admin)
    tables=list(dict.fromkeys(backup.TABLES+__import__('configuration_model').EXTRA_TABLES+__import__('backup_model').ACTIVITY_TABLES+__import__('backup_model').SNAPSHOT_TABLES+__import__('backup_model').ACTIVITY_SNAPSHOT_TABLES+['environments','business_operations','business_audit','business_state']))
    for table in tables:db.sql(f"GRANT SELECT,INSERT ON `{cfg['name']}`.`{table}` TO '{cfg['user']}'@'127.0.0.1';")
    # Financial tables are read-only to this preview account. No legacy cash is executed.
    for table in ['fin_sales','fin_expenses','fin_movements','fin_operation_receipts','fin_audit']:db.sql(f"GRANT SELECT ON `{cfg['name']}`.`{table}` TO '{cfg['user']}'@'127.0.0.1';")
    db.sql(f"GRANT SELECT ON `{cfg['name']}`.business_page_cursors TO '{cfg['user']}'@'127.0.0.1';")
    app=os.environ.copy();app.update(db.environment(cfg));app['CENTRO_BUSINESS_API_ENABLED']='0'
    password=secrets.token_urlsafe(24)
    private=out/'auth'
    app['CENTRO_PRIVATE_DIR']=str(private)
    call([ROOT/'tools/create_admin.php','--email=migration-preview@example.invalid','--password-stdin'],app,password+'\n')
    return cfg,app,admin,target,password


def stage(archive,entry,environment,out,exercise_rollback=False):
    archive=backup.private_path(archive);out=backup.private_path(out)
    if out.exists():raise ValueError('new_validation_directory_required')
    out.mkdir(parents=True)
    original=archive.read_bytes();independent=out/'original-archive.json'
    with independent.open('xb') as f:f.write(original)
    report=out/'verified-archive.json'
    call([ROOT/'tools/controlled_backup.php','--source='+str(independent),'--output='+str(report),'--entry='+entry,'--environment='+environment])
    verified=json.loads(report.read_text(encoding='utf-8'))
    raw=verified['entry']['payload'].encode('utf-8');source=out/'selected-source.json'
    source.write_bytes(raw)
    cfg,app,admin,target,password=prepare_target(out)
    model,preflight=backup.diagnose(raw,environment,target)
    (out/'preflight.json').write_text(json.dumps(preflight,ensure_ascii=False,indent=2),encoding='utf-8')
    if not preflight['can_import']:raise ValueError('preflight_rejected_source_preserved')
    operation='import_'+hashlib.sha256(raw+environment.encode()).hexdigest()[:32]
    if exercise_rollback:
        n=sum(len(rows) for rows in model.rows.values())
        failed=backup.import_backup(target,raw,environment,operation,fail_after=max(1,n))
        if failed.get('code')!='injected_import_failure' or db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.environments;")!='0':raise ValueError('rollback_not_proven')
        (out/'rollback.json').write_text(json.dumps({'ok':True,'late_failure_at_row':n}))
    imported=backup.import_backup(target,raw,environment,operation)
    if not imported.get('ok'):raise ValueError('import_rejected_source_preserved')
    exported=backup.export_backup(target,environment,operation)
    comparison=backup.compare(target,raw,environment,operation)
    if not exported.get('ok') or not comparison.get('ok'):raise ValueError('independent_sql_comparison_failed')
    (out/'independent-sql-export.json').write_text(json.dumps(exported,ensure_ascii=False,indent=2),encoding='utf-8')
    (out/'comparison.json').write_text(json.dumps(comparison,ensure_ascii=False,indent=2),encoding='utf-8')
    replay=backup.import_backup(target,raw,environment,operation)
    if not replay.get('ok') or not replay['data']['replayed']:raise ValueError('idempotent_replay_failed')
    if db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.fin_movements;")!='0' or db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.fin_sales;")!='0' or db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.fin_expenses;")!='0':raise ValueError('historical_data_became_live_cash')
    # Raw JSON is a read-only reconstruction, never counted as operational migration.
    # Store its exact bytes without PHP/Python numeric re-encoding.
    seed="require 'app/Database.php';$c=require 'config/settings.php';$p=Centro\\Database::connection($c['db']);$raw=stream_get_contents(STDIN);$q=$p->prepare('INSERT INTO business_state(environment_id,state_json,state_hash,row_version,updated_by) VALUES(?,?,?,1,1)');$q->execute([getenv('CENTRO_MIGRATION_ENV'),$raw,hash('sha256',$raw)]);"
    seed_env=admin.copy();seed_env['CENTRO_MIGRATION_ENV']=environment
    call(['-r',seed],seed_env,raw.decode('utf-8'))
    fingerprint_code="require 'app/Database.php';require 'app/MigrationMode.php';$c=require 'config/settings.php';echo json_encode(Centro\\MigrationMode::fingerprint(Centro\\Database::connection($c['db']),getenv('CENTRO_MIGRATION_ENV')),JSON_THROW_ON_ERROR);"
    fingerprints=json.loads(call(['-r',fingerprint_code],seed_env))
    value=json.loads(raw)
    blockers=[{'code':'preparation_only_no_real_activation','reason':'This run authorizes synthetic verification only.'},{'code':'source_freeze_required_before_real_cutover'},{'code':'reverse_reconciliation_required_before_writable_cutover'}]
    for collection,table in HISTORICAL.items():
        if value.get(collection):blockers.append({'collection':collection,'code':'historical_only_not_operational','table':table,'count':len(value[collection])})
    if value.get('plans') or value.get('subs'):blockers.append({'code':'historical_prices_and_trial_evidence_require_review'})
    if any(value.get(key) for key in OPERATIONAL):blockers.append({'code':'normalized_catalog_ui_write_adapters_require_consolidation'})
    if verified['redactions']:blockers.append({'code':'private_fields_excluded_reconfigure_separately'})
    copies=[c for c in verified['source_copies'] if c['environment']==environment]
    if len({c['sha256'] for c in copies})>1:blockers.append({'code':'source_copies_disagree_explicit_selection_preserved'})
    plan={'purpose':'synthetic-controlled-migration','database':cfg['name'],'environment':environment,'archive_path':str(independent),'archive_sha256':hashlib.sha256(original).hexdigest(),'source_sha256':hashlib.sha256(raw).hexdigest(),'selected_entry':entry,'operation':operation,'verified':True,'full_activation_allowed':False,'authority':'historical-read-only-preview','operational_collections':OPERATIONAL,'historical_collections':HISTORICAL,'blockers':blockers,'rollback':{'mode':'rollback','original_storage_unchanged':True,'requires_no_operational_sql_writes':True,'archive':str(independent)},'sql_fingerprint':fingerprints}
    (out/'activation-plan.json').write_text(json.dumps(plan,ensure_ascii=False,indent=2),encoding='utf-8')
    (out/'private-access.json').write_text(json.dumps({'config':cfg,'environment':app,'target':str(target),'email':'migration-preview@example.invalid','password':password,'plan':str(out/'activation-plan.json')},indent=2),encoding='utf-8')
    if archive.read_bytes()!=original or independent.read_bytes()!=original:raise ValueError('independent_archive_changed')
    return {'ok':True,'verified':True,'complete_migration':False,'full_activation_allowed':False,'private_plan':str(out/'activation-plan.json'),'private_access':str(out/'private-access.json'),'blocker_count':len(blockers)}

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--archive',required=True);parser.add_argument('--entry',required=True);parser.add_argument('--environment',choices=['produccion','pruebas'],required=True);parser.add_argument('--out',required=True)
    args=parser.parse_args()
    try:print(json.dumps(stage(args.archive,args.entry,args.environment,args.out)))
    except (ValueError,KeyError,OSError,TypeError):print(json.dumps({'ok':False,'code':'controlled_migration_rejected_source_preserved'}));sys.exit(1)
