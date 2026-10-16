"""Cache the original Google Fonts assets privately for reproducible browser tests."""
import hashlib
import json
import re
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CACHE = ROOT / 'tests/assets/fonts'
CACHE.mkdir(parents=True, exist_ok=True)
html = (ROOT / 'reference/centro-operaciones.html').read_text('utf-8')
url = re.search(r'<link href="(https://fonts.googleapis.com/[^\"]+)"', html).group(1)
css = urllib.request.urlopen(url, timeout=30).read()
(CACHE / 'original.css').write_bytes(css)
assets = {}
for font_url in sorted(set(re.findall(r'url\((https://fonts.gstatic.com/[^)]+)\)', css.decode()))):
    data = urllib.request.urlopen(font_url, timeout=30).read()
    filename = hashlib.sha256(font_url.encode()).hexdigest() + Path(font_url).suffix
    (CACHE / filename).write_bytes(data)
    assets[font_url] = {'file':filename,'sha256':hashlib.sha256(data).hexdigest()}
    print('Cached font:',len(data),'bytes',flush=True)
manifest = {'css_url':url,'css_file':'original.css','css_sha256':hashlib.sha256(css).hexdigest(),'assets':assets,
            'scope':'Test assets only, original application keeps its Google Fonts links unchanged'}
(CACHE / 'manifest.json').write_text(json.dumps(manifest,indent=2),encoding='utf-8')
print('Font cache ready:',len(assets),'assets')
