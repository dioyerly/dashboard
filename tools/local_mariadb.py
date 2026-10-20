"""Project-only MariaDB lifecycle. No services, PATH changes or remote connections."""
import argparse
import configparser
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import time

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / '.runtime/mariadb'
STATE = RUNTIME / 'local.json'
PREP_CLIENT = Path(os.environ.get('CENTRO_PREP_CLIENT_INI', str(RUNTIME/'prep.ini')))
PHP = ROOT / '.runtime/php/php.exe'
PHP_ARGS = [str(PHP), '-n', '-d', 'extension_dir='+str(PHP.parent/'ext'), '-d', 'extension=pdo_mysql']
FLAGS = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0


def state():
    value = json.loads(STATE.read_text('utf-8'))
    assert value['host'] == '127.0.0.1'
    return value


def prep_credentials():
    """Read the private preparation account without exposing it to application config."""
    if not PREP_CLIENT.exists():
        return os.environ.get('CENTRO_PREP_DB_USER', 'root'), os.environ.get('CENTRO_PREP_DB_PASSWORD')
    parser = configparser.ConfigParser(interpolation=None)
    parser.read(PREP_CLIENT, encoding='utf-8')
    section = parser['client']
    return (os.environ.get('CENTRO_PREP_DB_USER', section.get('user', 'root')),
            os.environ.get('CENTRO_PREP_DB_PASSWORD', section.get('password', '')))


def sql(statement):
    value = state()
    client = PREP_CLIENT if PREP_CLIENT.exists() else RUNTIME/'client.ini'
    command = [str(RUNTIME/'bin/mariadb.exe'), '--defaults-file='+str(client),
               '--protocol=tcp', '--host=127.0.0.1', '--port='+str(value['port']), '--skip-ssl', '--batch', '--skip-column-names']
    result = subprocess.run(command, input=statement, text=True, capture_output=True, creationflags=FLAGS)
    if result.returncode:
        raise RuntimeError('Local SQL command failed; no credentials or SQL details printed')
    return result.stdout.strip()


def initialize():
    if STATE.exists():
        raise RuntimeError('Local configuration exists; initialization refused')
    if (RUNTIME/'data').exists():
        raise RuntimeError('Data directory exists; initialization refused')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    password = secrets.token_hex(32)
    value = {'host':'127.0.0.1', 'port':port, 'root_password':password}
    STATE.write_text(json.dumps(value, indent=2), encoding='utf-8')
    template = RUNTIME/'bootstrap.ini'
    template.write_text('[mysqld]\nbind-address=127.0.0.1\nskip-name-resolve\n', encoding='utf-8')
    result = subprocess.run([str(RUNTIME/'bin/mariadb-install-db.exe'), '--datadir='+str(RUNTIME/'data'),
                             '--password='+password, '--port='+str(port), '--config='+str(template)],
                            capture_output=True, text=True, creationflags=FLAGS)
    (RUNTIME/'initialization.log').write_text(result.stdout.replace(password,'[redacted]')+result.stderr.replace(password,'[redacted]'))
    if result.returncode:
        raise RuntimeError('MariaDB initialization failed; see private initialization.log')
    (RUNTIME/'my.ini').write_text('[mysqld]\nbasedir='+RUNTIME.as_posix()+'\ndatadir='+(RUNTIME/'data').as_posix()+
        '\nport='+str(port)+'\nbind-address=127.0.0.1\nskip-name-resolve\ncharacter-set-server=utf8mb4\n'+
        'collation-server=utf8mb4_unicode_ci\ninnodb-buffer-pool-size=64M\n'+
        'log-error='+(RUNTIME/'server-error.log').as_posix()+'\npid-file='+(RUNTIME/'server.pid').as_posix()+
        '\ntmpdir='+RUNTIME.as_posix()+'\n', encoding='utf-8')
    (RUNTIME/'client.ini').write_text('[client]\nuser=root\npassword='+password+'\n', encoding='utf-8')


def start():
    try:
        sql('SELECT 1;')
        return
    except (RuntimeError, OSError):
        pass
    value = state()
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', value['port']))
    log = open(RUNTIME/'console.log', 'a')
    process = subprocess.Popen([str(RUNTIME/'bin/mariadbd.exe'), '--defaults-file='+str(RUNTIME/'my.ini'), '--console'],
        stdout=log, stderr=log, cwd=RUNTIME, creationflags=FLAGS | (subprocess.DETACHED_PROCESS if os.name=='nt' else 0))
    log.close()
    for _ in range(100):
        if process.poll() is not None:
            raise RuntimeError('Local MariaDB stopped; inspect private logs')
        try:
            if sql('SELECT 1;') == '1': return
        except RuntimeError: pass
        time.sleep(.1)
    raise RuntimeError('Local MariaDB startup timed out')


def stop():
    value = state()
    result = subprocess.run([str(RUNTIME/'bin/mariadb-admin.exe'), '--defaults-file='+str(RUNTIME/'client.ini'),
        '--protocol=tcp', '--host=127.0.0.1', '--port='+str(value['port']), '--skip-ssl', 'shutdown'], capture_output=True, creationflags=FLAGS)
    if result.returncode: raise RuntimeError('Local MariaDB shutdown failed')
    for _ in range(100):
        with socket.socket() as sock:
            if sock.connect_ex(('127.0.0.1',value['port'])) != 0: return
        time.sleep(.1)
    raise RuntimeError('Local MariaDB shutdown timed out')


def database():
    """Create a fresh isolated schema and restricted user; never copy an administrator."""
    name = 'centro_auth_'+secrets.token_hex(6)
    user = 'centro_'+secrets.token_hex(6)
    password = secrets.token_hex(32)
    sql("CREATE DATABASE `"+name+"` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"+
        "CREATE USER '"+user+"'@'127.0.0.1' IDENTIFIED BY '"+password+"';")
    # Migration statements are executed through actual PHP PDO, not a CLI substitute.
    env = os.environ.copy()
    prep_user, prep_password = prep_credentials()
    prep_password = prep_password or state()['root_password']
    env.update(environment({'name':name,'user':prep_user,'password':prep_password}))
    code = "require 'app/Database.php'; $c=require 'config/settings.php'; $p=Centro\\Database::connection($c['db']); $s=file_get_contents('database/migrations/001_auth.sql'); foreach(explode(';',$s) as $q){if(trim(preg_replace('/--[^\\r\\n]*/','',$q))!=='')$p->exec($q);} $q=$p->prepare('INSERT INTO schema_migrations(version,checksum) VALUES (?,?)'); $q->execute(['001_auth',hash('sha256',$s)]);"
    result = subprocess.run(PHP_ARGS+['-r',code], cwd=ROOT, env=env, capture_output=True, text=True, creationflags=FLAGS)
    if result.returncode: raise RuntimeError('Local PDO migration failed')
    sql("GRANT SELECT ON `"+name+"`.schema_migrations TO '"+user+"'@'127.0.0.1';"+
        ''.join("GRANT SELECT, INSERT, UPDATE, DELETE ON `"+name+"`."+table+" TO '"+user+"'@'127.0.0.1';"
                for table in ['auth_users','auth_login_limits']))
    return {'name':name,'user':user,'password':password}


def environment(config):
    value = state()
    return {'CENTRO_ENV':'local','CENTRO_AUTH_DRIVER':'mysql','CENTRO_DB_ENABLED':'1',
        'CENTRO_DB_HOST':'127.0.0.1','CENTRO_DB_PORT':str(value['port']),
        'CENTRO_DB_NAME':config['name'],'CENTRO_DB_USER':config['user'],'CENTRO_DB_PASSWORD':config['password']}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['initialize','start','stop'])
    args = parser.parse_args()
    {'initialize':initialize,'start':start,'stop':stop}[args.action]()
    print('Local MariaDB: '+args.action+' completed')
