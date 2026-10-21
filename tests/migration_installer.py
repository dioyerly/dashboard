"""Synthetic-only integration tests for the private PHP schema installer."""
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))
sys.path.insert(0, str(ROOT / 'tests'))
import local_mariadb as db
from run_operational_completion import fresh

OUT = ROOT / '.runtime' / 'migration-installer-validation'
OUT.mkdir(parents=True, exist_ok=True)
RESULTS = []

def check(name, ok):
    RESULTS.append({'check': name, 'passed': bool(ok)})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    (OUT / 'results.json').write_text(json.dumps({'checks': RESULTS}, indent=2), encoding='utf-8')
    assert ok, name

def make_private(name):
    path = OUT / 'private' / name
    path.mkdir(parents=True, exist_ok=True)
    return path

def new_database():
    name = 'centro_inst_' + secrets.token_hex(6)
    app_user = 'centro_app_' + secrets.token_hex(5)
    app_password = secrets.token_hex(24)
    db.sql(f"CREATE DATABASE `{name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER '{app_user}'@'127.0.0.1' IDENTIFIED BY '{app_password}';")
    db.sql(f"GRANT SELECT,INSERT,UPDATE,DELETE ON `{name}`.* TO '{app_user}'@'127.0.0.1';")
    return {'name': name, 'user': app_user, 'password': app_password}

def environment(cfg, private):
    env = os.environ.copy()
    env.update(db.environment(cfg))
    port = str(db.state()['port'])
    env.update({
        'CENTRO_ENV': 'local', 'CENTRO_AUTH_DRIVER': 'mysql', 'CENTRO_DB_ENABLED': '1',
        'CENTRO_DB_HOST': '127.0.0.1', 'CENTRO_DB_PORT': port, 'CENTRO_DB_NAME': cfg['name'],
        'CENTRO_DB_USER': cfg['user'], 'CENTRO_DB_PASSWORD': cfg['password'],
        'CENTRO_MIGRATION_DB_ENABLED': '1', 'CENTRO_MIGRATION_DB_HOST': '127.0.0.1',
        'CENTRO_MIGRATION_DB_PORT': port, 'CENTRO_MIGRATION_DB_NAME': cfg['name'],
        'CENTRO_PRIVATE_DIR': str(private), 'CENTRO_SQL_API_ENABLED': '0',
        'CENTRO_BUSINESS_API_ENABLED': '0', 'CENTRO_FINANCIAL_API_ENABLED': '0',
        'CENTRO_BUSINESS_STORAGE': 'local',
    })
    user, password = db.prep_credentials()
    env['CENTRO_MIGRATION_DB_USER'] = user
    env['CENTRO_MIGRATION_DB_PASSWORD'] = password or db.state()['root_password']
    return env

def invoke(package, env, *args, input_text=None):
    script = package / 'tools' / 'migrate.php'
    result = subprocess.run(db.PHP_ARGS + [str(script), *args], cwd=package, env=env,
        input=input_text, text=True, capture_output=True, creationflags=db.FLAGS)
    return result

def clone_release(label):
    destination = OUT / ('release-' + label + '-' + secrets.token_hex(4))
    destination.mkdir(parents=True)
    for folder in ('app', 'config', 'database'):
        shutil.copytree(ROOT / folder, destination / folder)
    shutil.copytree(ROOT / 'public', destination / 'public_html')
    (destination / 'tools').mkdir()
    shutil.copy2(ROOT / 'tools' / 'migrate.php', destination / 'tools' / 'migrate.php')
    shutil.copy2(ROOT / 'tools' / 'create_admin.php', destination / 'tools' / 'create_admin.php')
    return destination

def verified_backup(cfg, env, label):
    runtime = db.RUNTIME
    client = db.PREP_CLIENT if db.PREP_CLIENT.exists() else runtime / 'client.ini'
    private_dir = Path(env['CENTRO_PRIVATE_DIR'])
    dump = private_dir / f'{label}-{secrets.token_hex(5)}.sql'
    with dump.open('wb') as output:
        result = subprocess.run([str(runtime / 'bin' / 'mariadb-dump.exe'), '--defaults-file=' + str(client),
            '--host=127.0.0.1', '--port=' + str(db.state()['port']), '--protocol=tcp', '--skip-ssl',
            '--single-transaction', cfg['name']], stdout=output, stderr=subprocess.PIPE, creationflags=db.FLAGS)
    if result.returncode or dump.stat().st_size < 100:
        raise RuntimeError('Synthetic backup generation failed')
    restore_name = 'centro_restore_' + secrets.token_hex(6)
    db.sql(f"CREATE DATABASE `{restore_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")
    raw = dump.read_bytes()
    rewritten = re.sub(rb'(?i)\bUSE\s+`?' + cfg['name'].encode() + rb'`?\s*;', b'USE `' + restore_name.encode() + b'`;', raw)
    imported = subprocess.run([str(runtime / 'bin' / 'mariadb.exe'), '--defaults-file=' + str(client),
        '--host=127.0.0.1', '--port=' + str(db.state()['port']), '--protocol=tcp', '--skip-ssl',
        '--database=' + restore_name], input=rewritten, stdout=subprocess.PIPE, stderr=subprocess.PIPE, creationflags=db.FLAGS)
    if imported.returncode:
        raise RuntimeError('Synthetic backup restore test failed')
    rows = db.sql(f"SELECT COUNT(*) FROM `{restore_name}`.schema_migrations;")
    if rows != '16':
        raise RuntimeError('Synthetic backup restore verification failed')
    digest = hashlib.sha256(raw).hexdigest()
    manifest = private_dir / f'{label}-{secrets.token_hex(5)}.backup.json'
    manifest.write_text(json.dumps({'database': cfg['name'], 'backup_file': str(dump), 'sha256': digest,
        'restore_tested': True, 'restore_target': restore_name,
        'restore_tested_at': datetime.datetime.now(datetime.timezone.utc).isoformat()}, indent=2), encoding='utf-8')
    return manifest, digest, restore_name

def migration_args(database, manifest, digest):
    return ('--database=' + database, '--execute-update', '--backup-manifest=' + str(manifest),
        '--backup-sha256=' + digest, '--confirm-update=' + database + ':' + digest)

def main():
    db.start()
    prep = new_database()
    env = environment(prep, make_private(prep['name']))
    package = clone_release('clean-install')
    check('explicit target is mandatory', invoke(package, env).returncode != 0)
    plan = invoke(package, env, '--database=' + prep['name'], '--plan')
    if plan.returncode: print('INSTALLER PLAN ERROR: ' + plan.stderr.strip() + ' ' + plan.stdout.strip(), flush=True)
    check('clean install plan lists 001-017 without creating objects', plan.returncode == 0 and 'Pending: 17' in plan.stdout and db.sql(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{prep['name']}';") == '0')
    enabled = env.copy(); enabled['CENTRO_FINANCIAL_API_ENABLED'] = '1'
    check('installer refuses while SQL business operations are enabled', invoke(package, enabled, '--database=' + prep['name'], '--plan').returncode != 0 and db.sql(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{prep['name']}';") == '0')
    installed = invoke(package, env, '--database=' + prep['name'], '--execute-install', '--confirm-install=' + prep['name'])
    check('clean synthetic installation applies all 17 migrations', installed.returncode == 0 and installed.stdout.count('APPLIED ') == 17 and 'COMPLETE applied=' in installed.stdout)
    check('existing schema_migrations ledger records each exact source checksum once', db.sql(f"SELECT COUNT(*) FROM `{prep['name']}`.schema_migrations;") == '17' and db.sql(f"SELECT COUNT(DISTINCT version) FROM `{prep['name']}`.schema_migrations;") == '17' and db.sql(f"SELECT checksum FROM `{prep['name']}`.schema_migrations WHERE version='017_operational_completion';") == hashlib.sha256((ROOT / 'database/migrations/017_operational_completion.sql').read_bytes()).hexdigest())
    check('clean schema contains foreign keys and 017 structures', int(db.sql(f"SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='{prep['name']}';")) >= 20 and db.sql(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{prep['name']}' AND TABLE_NAME IN ('fin_entity_metadata','fin_subscription_evidence');") == '2')
    app_ddl_code = "$c=require 'config/settings.php'; $d=$c['db']; $p=new PDO('mysql:host='.$d['host'].';port='.$d['port'].';dbname='.$d['name'].';charset=utf8mb4',$d['user'],$d['password']); try{$p->exec('CREATE TABLE installer_runtime_ddl_probe (id INT PRIMARY KEY)');echo 'created';}catch(Throwable){echo 'denied';}"
    app_ddl = subprocess.run(db.PHP_ARGS + ['-r', app_ddl_code], cwd=ROOT, env=env, text=True, capture_output=True, creationflags=db.FLAGS)
    check('runtime database account is separate and cannot perform DDL', env['CENTRO_DB_USER'] != env['CENTRO_MIGRATION_DB_USER'] and app_ddl.returncode == 0 and app_ddl.stdout == 'denied' and db.sql(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{prep['name']}' AND TABLE_NAME='installer_runtime_ddl_probe';") == '0')
    repeat_plan = invoke(package, env, '--database=' + prep['name'], '--plan')
    repeat_exec = invoke(package, env, '--database=' + prep['name'], '--execute-update')
    check('repeated execution is idempotent and performs no migration twice', repeat_plan.returncode == 0 and 'Pending: none' in repeat_plan.stdout and repeat_exec.returncode == 0 and 'No pending migrations' in repeat_exec.stdout and db.sql(f"SELECT COUNT(*) FROM `{prep['name']}`.schema_migrations;") == '17')
    dirty = new_database()
    db.sql(f"CREATE TABLE `{dirty['name']}`.preexisting_data (id INT PRIMARY KEY);")
    dirty_env = environment(dirty, make_private(dirty['name']))
    dirty_plan = invoke(package, dirty_env, '--database=' + dirty['name'], '--plan')
    check('database with existing objects and no ledger is never treated as empty', dirty_plan.returncode != 0 and 'not empty' in dirty_plan.stderr.lower() and db.sql(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{dirty['name']}';") == '1')

    empty_ledger = new_database()
    db.sql(f"CREATE TABLE `{empty_ledger['name']}`.schema_migrations (version VARCHAR(64) NOT NULL PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;")
    empty_env = environment(empty_ledger, make_private(empty_ledger['name']))
    empty_plan = invoke(package, empty_env, '--database=' + empty_ledger['name'], '--plan')
    check('empty migration ledger is refused as ambiguous', empty_plan.returncode != 0 and 'ledger is empty' in empty_plan.stderr.lower())

    locked = new_database()
    locked_env = environment(locked, make_private(locked['name']))
    lock_code = "$c=require 'config/settings.php'; $d=$c['migration_db']; $p=new PDO('mysql:host='.$d['host'].';port='.$d['port'].';dbname='.$d['name'].';charset=utf8mb4',$d['user'],$d['password']); $n='centro_migrate_'.substr(hash('sha256',$d['name']),0,32); $q=$p->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$n]); if((int)$q->fetchColumn()!==1) exit(3); echo 'LOCKED'; echo chr(10); flush(); sleep(12);"
    lock_process = subprocess.Popen(db.PHP_ARGS + ['-r', lock_code], cwd=ROOT, env=locked_env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, creationflags=db.FLAGS)
    lock_ready = lock_process.stdout.readline().strip() == 'LOCKED'
    lock_result = invoke(package, locked_env, '--database=' + locked['name'], '--execute-install', '--confirm-install=' + locked['name']) if lock_ready else None
    if lock_process.poll() is None: lock_process.terminate()
    check('database advisory lock prevents concurrent migration execution', lock_ready and lock_result is not None and lock_result.returncode != 0 and 'lock is busy' in lock_result.stderr.lower() and db.sql(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{locked['name']}';") == '0')

    tampered = clone_release('tampered')
    first_migration = tampered / 'database' / 'migrations' / '001_auth.sql'
    first_migration.write_text(first_migration.read_text(encoding='utf-8') + '\n-- synthetic checksum tamper\n', encoding='utf-8')
    tamper_result = invoke(tampered, env, '--database=' + prep['name'], '--plan')
    check('changed checksum of an applied migration is refused without writes', tamper_result.returncode != 0 and 'checksum' in tamper_result.stderr.lower() and db.sql(f"SELECT COUNT(*) FROM `{prep['name']}`.schema_migrations;") == '17')

    password = 'Synthetic-Admin-' + secrets.token_hex(8)
    admin = subprocess.run(db.PHP_ARGS + [str(package / 'tools' / 'create_admin.php'), '--email=installer-test@example.invalid', '--password-stdin'],
        cwd=ROOT, env=env, input=password + '\n', text=True, capture_output=True, creationflags=db.FLAGS)
    check('temporary CLI administrator is created using password hash and no default', admin.returncode == 0 and password not in admin.stdout + admin.stderr and 'initialized' in admin.stdout.lower())
    auth_code = "require 'app/bootstrap.php'; $_SERVER['HTTPS']='on'; $_SERVER['SCRIPT_NAME']='/index.php'; Centro\\Security::session($config); $pw=rtrim((string)fgets(STDIN)); $login=$auth->login('installer-test@example.invalid',$pw,'127.0.0.1'); $user=$auth->current(); echo json_encode(['login'=>$login['ok']??false,'user'=>($user['id']??0)===1,'csrf'=>Centro\\Security::validCsrf(Centro\\Security::csrf())]);"
    auth = subprocess.run(db.PHP_ARGS + ['-r', auth_code], cwd=ROOT, env=env, input=password + '\n', text=True, capture_output=True, creationflags=db.FLAGS)
    try: auth_result = json.loads(auth.stdout)
    except ValueError: auth_result = {}
    if auth.returncode or auth_result != {'login': True, 'user': True, 'csrf': True}: print('AUTH TEST OUTPUT: ' + auth.stdout.strip() + ' ' + auth.stderr.strip(), flush=True)
    check('synthetic administrator authenticates and session CSRF is valid', auth.returncode == 0 and auth_result == {'login': True, 'user': True, 'csrf': True})

    legacy, _legacy_env = fresh(last=16)
    legacy_env = environment(legacy, make_private(legacy['name']))
    backup_manifest, backup_hash, restore_name = verified_backup(legacy, legacy_env, 'upgrade16')
    rejected_manifest = Path(legacy_env['CENTRO_PRIVATE_DIR']) / 'unverified-backup.json'
    manifest_data = json.loads(backup_manifest.read_text(encoding='utf-8'))
    manifest_data['restore_tested'] = False
    rejected_manifest.write_text(json.dumps(manifest_data), encoding='utf-8')
    rejected = invoke(package, legacy_env, *migration_args(legacy['name'], rejected_manifest, backup_hash))
    check('update refuses an unverified backup before any DDL', rejected.returncode != 0 and 'restore-test evidence' in rejected.stderr.lower() and db.sql(f"SELECT COUNT(*) FROM `{legacy['name']}`.schema_migrations;") == '16' and db.sql(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{legacy['name']}' AND TABLE_NAME='fin_entity_metadata';") == '0')
    update = invoke(package, legacy_env, *migration_args(legacy['name'], backup_manifest, backup_hash))
    check('verified-backup update applies only migration 017 to synthetic 001-016', update.returncode == 0 and update.stdout.count('APPLIED ') == 1 and 'APPLIED 017_operational_completion' in update.stdout)
    count17 = db.sql(f"SELECT COUNT(*) FROM `{legacy['name']}`.schema_migrations;")
    repeat_update = invoke(package, legacy_env, *migration_args(legacy['name'], backup_manifest, backup_hash))
    check('017 update repeats safely with no duplicate DDL or ledger rows', repeat_update.returncode == 0 and 'No pending migrations' in repeat_update.stdout and count17 == '17' and db.sql(f"SELECT COUNT(*) FROM `{legacy['name']}`.schema_migrations;") == '17')
    check('verified backup was restored to separate synthetic schema', restore_name != legacy['name'] and db.sql(f"SELECT COUNT(*) FROM `{restore_name}`.schema_migrations;") == '16')

    broken, _ = fresh(last=16)
    broken_env = environment(broken, make_private(broken['name']))
    broken_manifest, broken_hash, _ = verified_backup(broken, broken_env, 'failed017')
    broken_release = clone_release('partial-failure')
    (broken_release / 'database/migrations/017_operational_completion.sql').write_text(
        'CREATE TABLE installer_partial_probe (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;\nTHIS IS INTENTIONALLY INVALID SQL;\n', encoding='utf-8')
    args = migration_args(broken['name'], broken_manifest, broken_hash)
    failed = invoke(broken_release, broken_env, *args)
    marker = Path(broken_env['CENTRO_PRIVATE_DIR']) / 'migration-in-progress.json'
    retry = invoke(broken_release, broken_env, *args)
    check('partial DDL error stops immediately and retry is blocked by private marker', failed.returncode == 2 and 'DDL may be partial' in failed.stderr and marker.is_file() and retry.returncode != 0 and 'interrupted migration marker' in retry.stderr.lower())
    check('failed 017 is not falsely recorded or rolled back', db.sql(f"SELECT COUNT(*) FROM `{broken['name']}`.schema_migrations WHERE version='017_operational_completion';") == '0' and db.sql(f"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{broken['name']}' AND TABLE_NAME='installer_partial_probe';") == '1')
    print(f'PASS migration installer: {len(RESULTS)} synthetic integration checks', flush=True)

if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print('FAIL migration installer integration test; inspect private synthetic output.', flush=True)
        raise