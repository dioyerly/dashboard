"""Extract exact source bytes into ordered classic scripts; verify without mutation."""
import argparse
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'reference/centro-operaciones.html'
PARTS = [
    ('00-utilities.js', None),
    ('01-storage-domain.js', b'function currentEnv()'),
    ('02-interface.js', b'const ICONS ='),
    ('03-overview-projects.js', b'VIEWS.pulso ='),
    ('04-sales.js', b'VIEWS.ventas ='),
    ('05-subscriptions.js', b'const custOpts ='),
    ('06-expenses-finances.js', b'VIEWS.gastos ='),
    ('07-resources-calendar-customers.js', b'VIEWS.infra ='),
    ('08-integrations.js', b'VIEWS.integraciones ='),
    ('09-reports-settings.js', b'const MNY ='),
    ('10-boot.js', b'(async function boot()'),
]


def sha(data):
    return hashlib.sha256(data).hexdigest()


def inventory(data):
    text = data.decode('utf-8')
    patterns = {
        'screens': r'^VIEWS\.(\w+)\s*=',
        'named_functions': r'\bfunction\s+([\w$]+)\s*\(',
        'actions': r'^ACT\.(\w+)\s*=',
        'change_handlers': r'^CHG\.(\w+)\s*=',
        'input_handlers': r'^INP\.(\w+)\s*=',
        'constants': r'^const\s+([\w$]+)\s*=',
        'forms': r'<form\b[^>]*>',
        'identifiers': r'\bid="([^"\r\n]*)"',
        'data_attributes': r'\bdata-[\w-]+(?:="[^"\r\n]*")?',
        'event_listeners': r'addEventListener\(\s*[\'"]([^\'"]+)',
        'reports': r'^    (\w+): \{ label:',
    }
    return {key: re.findall(pattern, text, re.M) for key, pattern in patterns.items()}


def run(verify=False):
    if (ROOT / 'app/bootstrap.php').exists():
        raise RuntimeError('Phase 3 is active: phase-2 extraction must not overwrite authenticated entry or approved fixes. Use tests/phase3_static.py.')
    source = SOURCE.read_bytes()
    baseline = json.loads((ROOT / 'preparation/baseline.json').read_text('utf-8'))
    checks = []
    for entry in baseline['files']:
        checks.append({'check': 'Original intact: ' + entry['file'],
                       'passed': sha((ROOT.parent / entry['file']).read_bytes()) == entry['sha256']})
        if entry['file'].endswith('.html'):
            checks.append({'check': 'Reference intact: ' + entry['file'],
                           'passed': sha((ROOT / 'reference' / entry['file']).read_bytes()) == entry['sha256']})
    assert all(c['passed'] for c in checks), 'Original/reference hash mismatch; stop'
    styles = list(re.finditer(rb'<style>(.*?)</style>', source[:source.index(b'</head>')], re.S))
    scripts = list(re.finditer(rb'<script>(.*?)</script>', source, re.S))
    assert len(styles) == len(scripts) == 1
    css, js = styles[0].group(1), scripts[0].group(1)
    starts = [0] + [js.index(marker) for _, marker in PARTS[1:]] + [len(js)]
    assert starts == sorted(starts)
    expected_parts = [(name, js[starts[i]:starts[i+1]]) for i, (name, _) in enumerate(PARTS)]
    tags = b''.join(b'<script src="assets/js/' + name.encode() + b'"></script>' for name, _ in PARTS)
    expected_html = source.replace(styles[0].group(0), b'<link rel="stylesheet" href="assets/css/app.css">', 1)
    expected_html = expected_html.replace(scripts[0].group(0), tags, 1)
    expected = [('assets/css/app.css', css), ('index.php', expected_html)]
    expected += [('assets/js/' + name, data) for name, data in expected_parts]
    if not verify:
        for relative, data in expected:
            target = ROOT / 'public' / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
    for relative, data in expected:
        actual = (ROOT / 'public' / relative).read_bytes()
        checks.append({'check': 'Exact bytes: ' + relative, 'passed': actual == data,
                       'sha256': sha(actual), 'bytes': len(actual)})
    joined = b''.join((ROOT / 'public/assets/js' / name).read_bytes() for name, _ in PARTS)
    checks.append({'check': 'Concatenated JavaScript equals complete original block', 'passed': joined == js,
                   'sha256': sha(joined)})
    checks.append({'check': 'CSS equals complete original block', 'passed': (ROOT / 'public/assets/css/app.css').read_bytes() == css,
                   'sha256': sha(css)})
    # A reversible substitution proves that every remaining byte of HTML is unchanged.
    reconstructed = (ROOT / 'public/index.php').read_bytes().replace(tags, scripts[0].group(0), 1)
    reconstructed = reconstructed.replace(b'<link rel="stylesheet" href="assets/css/app.css">', styles[0].group(0), 1)
    checks.append({'check': 'Reconstructed document equals complete original', 'passed': reconstructed == source})
    original_inventory, extracted_inventory = inventory(js), inventory(joined)
    for name in original_inventory:
        checks.append({'check': 'Inventory including order: ' + name,
                       'passed': original_inventory[name] == extracted_inventory[name],
                       'count': len(original_inventory[name]), 'unique_count': len(set(original_inventory[name]))})
    checks.append({'check': 'All classic scripts ordered, blocking, before body end',
                   'passed': tags in expected_html and expected_html.index(tags) < expected_html.index(b'</body>')
                   and b' async' not in tags and b' defer' not in tags and b'type="module"' not in tags})
    allowed = {relative for relative, _ in expected}
    public_files = {p.relative_to(ROOT / 'public').as_posix() for p in (ROOT / 'public').rglob('*') if p.is_file()}
    checks.append({'check': 'Public contains only entry, extracted scripts and CSS', 'passed': public_files == allowed,
                   'files': sorted(public_files)})
    report = {'phase': 2, 'method': 'Byte-for-byte extraction without normalization or logic edits',
              'source': str(SOURCE), 'scripts_in_execution_order': [name for name, _ in PARTS],
              'checks': checks, 'inventory': extracted_inventory,
              'storage': 'Original IndexedDB/localStorage behavior unchanged; no API or MySQL',
              'limit': 'Static results do not by themselves establish browser equivalence'}
    (ROOT / 'preparation/phase2-static.json').write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
    for check in checks:
        print(('PASS' if check['passed'] else 'FAIL') + ': ' + check['check'])
    assert all(c['passed'] for c in checks)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--verify', action='store_true')
    run(parser.parse_args().verify)
