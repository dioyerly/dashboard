"""Synthetic block-7 regression: migration 015, restricted grants and snapshot plan."""
import copy
import hashlib
import json
import secrets
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))
from backup_import import fixture, encoded
import backup_local as tool
import local_mariadb as db


def main():
    checks = []

    def check(name, ok):
        checks.append({'check': name, 'passed': bool(ok)})
        print(('PASS: ' if ok else 'FAIL: ') + name, flush=True)
        assert ok, name

    target = tool.prepare(profile='block7')
    cfg, _ = tool.environment(target)
    source = fixture()
    source['sales'] = [{'id': 'historical_sale', 'currency': 'USD', 'amount': '5.00', 'date': '2026-10-16'}]
    source['log'] = [{'id': 'historical_log', 'action': 'Synthetic historical action', 'date': '2026-10-16'}]
    raw = encoded(source)
    model, report = tool.diagnose(raw, 'pruebas', target)
    if not report['can_import']:
        print(json.dumps(report.get('sql_preflight', {})))
        print(json.dumps([i for i in report['issues'] if i['level'] in ('error', 'review')]))
    check('Block-7 preflight succeeds with application account', report['can_import'])
    migrations = db.sql(f"SELECT version,checksum FROM `{cfg['name']}`.schema_migrations WHERE version IN ('014_original_financial_snapshots','015_original_activity_snapshots') ORDER BY version;")
    check('Both snapshot migrations have current checksums', all(hashlib.sha256((ROOT / 'database/migrations' / (v + '.sql')).read_bytes()).hexdigest() in migrations for v in ('014_original_financial_snapshots', '015_original_activity_snapshots')))
    check('Plan retains both snapshot collections in dependency order', list(model.rows)[-2:] == ['original_financial_snapshots', 'original_activity_snapshots'] and len(model.rows['original_activity_snapshots']) == 1)
    op = 'import_' + secrets.token_hex(16)
    # Fail on the final historical activity row: all preceding writes must roll back.
    failed = tool.import_backup(target, raw, 'pruebas', op, fail_after=sum(map(len, model.rows.values())))
    check('Late snapshot failure rolls back environment, snapshots, receipt and audit', failed.get('code') == 'injected_import_failure' and all(db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.`{t}`;") == '0' for t in ['environments', 'original_financial_snapshots', 'original_activity_snapshots', 'business_operations', 'business_audit']))
    result = tool.import_backup(target, raw, 'pruebas', op)
    check('Retry after rollback imports both snapshot tables', result.get('ok') and not result['data']['replayed'])
    exported = tool.export_backup(target, 'pruebas', op)
    check('Independent SQL export preserves exact payload and content hashes', exported['ok'] and all(json.loads(exported['normalized_entities'][table][0]['payload']) == source[collection][0] and hashlib.sha256(exported['normalized_entities'][table][0]['payload'].encode()).hexdigest() == exported['normalized_entities'][table][0]['content_hash'] for table, collection in [('original_financial_snapshots', 'sales'), ('original_activity_snapshots', 'log')]))
    check('All mapped rows round-trip through actual SQL', tool.compare(target, raw, 'pruebas', op)['ok'])
    replay = tool.import_backup(target, raw, 'pruebas', op)
    check('Identical retry replays without duplicate snapshots', replay.get('ok') and replay['data']['replayed'] and db.sql(f"SELECT COUNT(*) FROM `{cfg['name']}`.original_activity_snapshots;") == '1')
    changed = copy.deepcopy(source)
    changed['log'][0]['action'] = 'Changed historical action'
    check('Changed snapshot payload conflicts on reused operation', tool.import_backup(target, encoded(changed), 'pruebas', op).get('code') == 'idempotency_conflict')
    db.sql(f"UPDATE `{cfg['name']}`.original_activity_snapshots SET payload='{{}}';")
    check('Verification detects tampered snapshot SQL instead of echoing source', not tool.compare(target, raw, 'pruebas', op)['ok'])
    print(json.dumps({'checks': checks}))


if __name__ == '__main__':
    main()
