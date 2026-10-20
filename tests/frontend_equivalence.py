"""Real Chromium comparisons against immutable HTML, using disposable contexts only."""
import argparse
import hashlib
import io
import json
import os
import re
import threading
from datetime import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from PIL import Image, ImageChops
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
PHASE3 = os.environ.get('CENTRO_TEST_PHASE3') == '1'
OUT = Path(os.environ.get('CENTRO_TEST_OUTPUT', str(ROOT / ('preparation/phase3-browser' if PHASE3 else 'preparation/phase2-browser'))))
FIXED = datetime.fromisoformat('2026-10-15T15:00:00+00:00')
ORIGINAL = (ROOT / 'reference/centro-operaciones.html').read_bytes()
DEMO = (ROOT / 'reference/centro-operaciones-DEMO.html').read_text('utf-8')
MAKE_FIXTURE = re.search(r'function createDemoState\(\) \{.*?\n\}', DEMO, re.S).group(0)
ROUTES = ['pulso', 'marcas', 'apps', 'ventas', 'suscripciones', 'gastos', 'finanzas', 'infra', 'calendario', 'clientes', 'integraciones', 'reportes', 'ajustes']


class ReferenceHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path.split('?')[0] in ('/', '/baseline.html'):
            self.send_response(200)
            self.send_header('Content-Type', 'text/html; charset=utf-8')
            self.send_header('Content-Length',str(len(ORIGINAL)))
            self.end_headers()
            self.wfile.write(ORIGINAL)
        elif self.path == '/favicon.ico':
            self.send_response(204)
            self.end_headers()
        else:
            self.send_error(404)

    def log_message(self, *_):
        pass


def settle(page, delay=1250):
    page.bring_to_front()
    page.mouse.move(0, 0)
    page.evaluate('document.fonts.ready')
    page.evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))')
    page.wait_for_timeout(delay)
    page.evaluate("document.querySelectorAll('svg').forEach(s => { if(s.querySelector('animate,animateTransform') && s.pauseAnimations) {s.pauseAnimations();s.setCurrentTime(0)} })")
    # Let the paused SMIL state reach the compositor before screenshot capture.
    page.evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))')


def install_fixture(page, fixture, theme='light'):
    page.evaluate("""async ({fixture,theme}) => {
      while(modalStack.length) modalStack[0].close();
      state=clone(fixture); await DataStore.save(state); unlocked=true;
      Object.assign(ui,{route:'pulso',theme,lens:'all',period:{preset:'mes'},tabs:{},filters:{}});
      saveUI(); document.querySelector('#root').innerHTML=''; applyTheme(); render();
    }""", {'fixture': fixture, 'theme': theme})
    page.wait_for_selector('#view')


def navigate(page, route):
    page.locator(f'#dock a[href="#/{route}"]').click()
    page.wait_for_function('(r) => ui.route===r && !!document.querySelector("#view .page")', arg=route)


def screenshot_pair(pages, name, checks):
    shots = []
    texts = []
    for label, page in zip(('original', 'modular'), pages):
        settle(page)
        texts.append(page.locator('#root').inner_text())
        shots.append(page.screenshot(path=str(OUT / f'{name}-{label}.png'), full_page=True, animations='allow'))
    images = [Image.open(io.BytesIO(data)).convert('RGB') for data in shots]
    same_size = images[0].size == images[1].size
    diff = ImageChops.difference(*images) if same_size else None
    bbox = diff.getbbox() if diff else None
    equal = same_size and bbox is None
    different_pixels=0
    max_delta=0
    if diff and bbox:
        channels=diff.split()
        maximum=ImageChops.lighter(ImageChops.lighter(channels[0],channels[1]),channels[2])
        different_pixels=images[0].width*images[0].height-maximum.histogram()[0]
        max_delta=max(hi for lo,hi in diff.getextrema())
    # Rounded backdrop/shadow edges can rasterize 1-2 RGB levels differently across compositor layers.
    # Accept at most ten such pixels in the whole image; retain raw equality and the full diff.
    antialias_only=PHASE3 and same_size and different_pixels<=10 and max_delta<=2
    if diff and bbox:
        diff.save(OUT / f'{name}-difference.png')
    checks.append({'check': name, 'passed': (equal or antialias_only) and texts[0] == texts[1], 'pixels_equal': equal,
                   'text_equal': texts[0] == texts[1], 'sizes': [im.size for im in images], 'difference_box': bbox,
                   'different_pixels':different_pixels,'maximum_channel_delta':max_delta,
                   'rasterization_tolerance':{'maximum_pixels':10,'maximum_channel_delta':2} if PHASE3 else None,
                   'screenshots': [f'{name}-original.png', f'{name}-modular.png']})
    print(f'{"PASS" if checks[-1]["passed"] else "FAIL"}: {name}', flush=True)


def close_modals(page):
    while page.locator('.overlay:not(.closing)').count():
        page.keyboard.press('Escape')
        page.wait_for_timeout(250)


def visual(pages, fixture, checks):
    for profile, viewport, theme in [('desktop-light', {'width':1440,'height':900}, 'light'),
                                      ('mobile-dark', {'width':390,'height':844}, 'dark')]:
        for page in pages:
            page.set_viewport_size(viewport)
            install_fixture(page, fixture, theme)
        for route in ROUTES:
            for page in pages:
                navigate(page, route)
            screenshot_pair(pages, f'{profile}-{route}', checks)
    # Also cover the opposite theme on each viewport.
    for profile, viewport, theme in [('desktop-dark', {'width':1440,'height':900}, 'dark'),
                                      ('mobile-light', {'width':390,'height':844}, 'light')]:
        for page in pages:
            page.set_viewport_size(viewport)
            install_fixture(page, fixture, theme)
        screenshot_pair(pages, profile+'-pulso', checks)


def controls(page, fixture, label):
    results = {}
    page.bring_to_front()
    page.emulate_media(reduced_motion='reduce')
    install_fixture(page, fixture)
    results['registries'] = page.evaluate('({screens:Object.keys(VIEWS), actions:Object.keys(ACT), changes:Object.keys(CHG), inputs:Object.keys(INP), reports:Object.keys(REPORTS())})')
    if PHASE3:
        # Compile fetched source in a separate blank test page. Never weaken the app's CSP to use eval.
        checker_context=page.context.browser.new_context()
        checker=checker_context.new_page()
        for script in page.locator('script').evaluate_all('(els)=>els.map(s=>({src:s.src,text:s.textContent}))'):
            code=page.context.request.get(script['src']).text() if script['src'] else script['text']
            assert checker.evaluate('(code)=>typeof new Function(code)==="function"',code)
        checker_context.close()
        results['syntax']={'parsed':True}
    else:
        results['syntax'] = page.evaluate("""async () => {
          const scripts=[...document.scripts];
          for(const s of scripts) new Function(s.src ? await (await fetch(s.src)).text() : s.textContent);
          return {parsed:true};
        }""")
    # Exercise real dock, tabs and all 15 report selectors.
    for route in ROUTES:
        navigate(page, route)
        page.wait_for_timeout(60)
        results['screen_'+route] = page.locator('#view .page').inner_text()
    for route, key in [('ventas','ventas'),('gastos','gastos'),('finanzas','fin'),('suscripciones','subs'),('integraciones','integ')]:
        navigate(page, route)
        tabs = page.locator(f'[data-act="tab"][data-k="{key}"]').evaluate_all('(els)=>els.map(e=>e.dataset.t)')
        for tab in tabs:
            page.locator(f'[data-act="tab"][data-k="{key}"][data-t="{tab}"]').click()
            results[f'tab_{key}_{tab}'] = page.locator('#view .page').inner_text()
    navigate(page, 'reportes')
    for report in page.evaluate('Object.keys(REPORTS())'):
        page.locator(f'[data-act="pickRep"][data-id="{report}"]').click()
        results['report_'+report] = page.locator('#view .page').inner_text()
    print(label+': navigation, tabs and 15 reports checked',flush=True)
    # Lens and custom period, using actual UI controls.
    page.locator('[data-act="setLens"]').nth(1).click()
    results['lens'] = page.evaluate('ui.lens')
    page.locator('[data-act="setLens"]').first.click()
    page.locator('[data-act="pickPeriod"]').click()
    page.locator('[data-p="custom"]').click()
    page.locator('#pFrom').fill('2026-09-01')
    page.locator('#pTo').fill('2026-10-15')
    page.locator('[data-ok]').click()
    results['period'] = page.evaluate('resolveRange()')
    page.locator('[data-act="toggleTheme"]').click()
    results['theme'] = page.evaluate('ui.theme')
    # Command palette via keyboard; 15 existing creation/import commands.
    commands = page.evaluate('CREATE_CMDS.map(([id,label])=>({id,label}))')
    for command in commands:
        page.keyboard.press('Control+k')
        search = page.locator('.palette input')
        search.fill(command['label'])
        page.keyboard.press('Enter')
        page.wait_for_timeout(400)
        results['command_'+command['id']] = page.locator('.overlay:not(.closing) h2').all_text_contents()
        assert results['command_'+command['id']], command
        close_modals(page)
    print(label+': 15 palette commands checked',flush=True)
    # Search and filters.
    navigate(page, 'ventas')
    page.locator('[data-act="tab"][data-t="ventas"]').click()
    page.locator('[data-input="salesQ"]').fill('zzzz-no-match')
    page.wait_for_timeout(350)
    results['sales_search'] = page.locator('#view .page').inner_text()
    page.locator('[data-input="salesQ"]').fill('')
    page.wait_for_timeout(350)
    page.locator('[data-act="tab"][data-t="catalogo"]').click()
    page.locator('[data-input="catQ"]').fill('zzzz-no-match')
    page.wait_for_timeout(350)
    results['catalog_search'] = page.locator('#view .page').inner_text()
    navigate(page, 'clientes')
    page.locator('[data-input="custQ"]').fill('zzzz-no-match')
    page.wait_for_timeout(350)
    results['customer_search'] = page.locator('#view .page').inner_text()
    # Save a customer through its form and check persistence through reload.
    page.locator('[data-act="newCustomer"]').click()
    page.locator('#cuF [name="name"]').fill('Prueba Fase 2')
    page.locator('#cuF [name="email"]').fill('fase2@ejemplo.test')
    page.locator('.overlay:not(.closing) [data-save]').click()
    page.wait_for_function("state.customers.some(c=>c.email==='fase2@ejemplo.test')")
    page.wait_for_timeout(300)
    page.reload(wait_until='domcontentloaded')
    page.wait_for_selector('#view')
    results['customer_persisted'] = page.evaluate("state.customers.filter(c=>c.email==='fase2@ejemplo.test').map(c=>({name:c.name,email:c.email}))")
    assert len(results['customer_persisted']) == 1
    print(label+': customer form and reload persistence checked',flush=True)
    # Save a paid sale: source business behavior must be preserved, including exactly one receipt.
    navigate(page, 'ventas')
    page.locator('[data-act="newSale"]').click()
    page.locator('#slF [name="productId"]').select_option('pd_love')
    page.locator('#slF [name="qty"]').fill('2')
    page.locator('#slF [name="unitPrice"]').fill('100')
    page.locator('#slF [name="discount"]').fill('20')
    page.locator('#slF [name="refunded"]').fill('30')
    page.locator('#slF [name="commission"]').fill('10')
    page.locator('#slF [name="tax"]').fill('5')
    page.locator('#slF [name="payStatus"]').select_option('cobrado')
    page.locator('#slF [name="externalId"]').fill('PHASE2-UI-SALE')
    page.locator('.overlay:not(.closing) [data-save]').click()
    page.wait_for_function("state.sales.some(s=>s.externalId==='PHASE2-UI-SALE')")
    results['sale'] = page.evaluate("""()=>{const s=state.sales.find(s=>s.externalId==='PHASE2-UI-SALE');return {calc:saleCalc(s),receipts:state.treasury.filter(t=>t.saleId===s.id).map(t=>t.amount)}}""")
    assert results['sale']['calc']['liquid'] == 135 and results['sale']['receipts'] == [135]
    close_modals(page)
    # CSV upload with header mapping, then JSON events and duplicate prevention.
    navigate(page, 'integraciones')
    page.locator('[data-act="importCSV"]').click()
    page.locator('#icSrc').fill('Prueba CSV Fase 2')
    csv = 'fecha,producto,importe,moneda,id\n2026-10-15,'+fixture['products'][0]['name']+',321,ARS,PHASE2-CSV\n'
    page.locator('#icFile').set_input_files({'name':'phase2.csv','mimeType':'text/csv','buffer':csv.encode()})
    page.wait_for_timeout(400)
    results['csv_preview'] = page.locator('.overlay:not(.closing)').inner_text()
    if page.locator('#icGo').is_enabled():
        page.locator('#icGo').click()
        page.wait_for_timeout(350)
        results['csv_imported'] = page.evaluate("state.sales.filter(s=>s.externalId==='PHASE2-CSV').length")
        assert results['csv_imported'] == 1
    else:
        raise AssertionError('CSV preview did not enable import')
    close_modals(page)
    page.locator('[data-act="importJSON"]').click()
    event = [{'source':'Prueba JSON Fase 2','external_id':'PHASE2-EVENT','type':'sale','date':'2026-10-15','amount':456,'currency':'ARS','product':fixture['products'][0]['name']}]
    page.locator('#ijT').fill(json.dumps(event))
    page.locator('.overlay:not(.closing) [data-go]').click()
    page.wait_for_timeout(250)
    page.locator('.overlay:not(.closing) [data-go]').click()
    page.wait_for_timeout(250)
    results['json_event'] = {'feedback':page.locator('#ijRes').inner_text(), 'sales':page.evaluate("state.sales.filter(s=>s.externalId==='PHASE2-EVENT').length")}
    assert results['json_event']['sales'] == 1
    print(label+': paid sale, CSV and JSON duplicate checks completed',flush=True)
    close_modals(page)
    # Real CSV / Excel exports and backup download.
    navigate(page, 'reportes')
    page.locator('[data-act="pickRep"][data-id="ventas"]').click()
    for fmt in ('csv','xls'):
        with page.expect_download() as download:
            page.locator(f'[data-act="exportRep"][data-f="{fmt}"]').click()
        data = Path(download.value.path()).read_bytes()
        results['export_'+fmt] = {'extension':Path(download.value.suggested_filename).suffix,'sha256':hashlib.sha256(data).hexdigest(),'bytes':len(data)}
        assert data
    navigate(page, 'ajustes')
    with page.expect_download() as download:
        page.locator('[data-act="backup"]').click()
    backup = Path(download.value.path()).read_bytes()
    before = json.loads(backup)
    results['backup_counts'] = {key:len(value) for key,value in before.items() if isinstance(value,list)}
    page.locator('#restoreF').set_input_files({'name':'phase2-backup.json','mimeType':'application/json','buffer':backup})
    page.locator('[data-yes]').click()
    page.wait_for_timeout(400)
    results['restore_counts'] = page.evaluate('Object.fromEntries(Object.entries(state).filter(([k,v])=>Array.isArray(v)).map(([k,v])=>[k,v.length]))')
    # Restoration adds precisely one audit entry.
    assert all(results['restore_counts'][k] == v + (1 if k=='log' else 0) for k,v in results['backup_counts'].items())
    # Existing PIN, lock, wrong PIN and unlock, all through controls.
    page.locator('[data-act="setPin"]').click()
    page.locator('.overlay:not(.closing) [name="pin"]').fill('2468')
    page.locator('[data-yes]').click()
    page.wait_for_timeout(300)
    page.locator('[data-act="lockNow"]').click()
    page.locator('#lockF [name="pin"]').fill('0000')
    page.locator('#lockF button').click()
    results['wrong_pin'] = page.locator('#lockErr').inner_text()
    page.locator('#lockF [name="pin"]').fill('2468')
    page.locator('#lockF button').click()
    page.wait_for_selector('#view')
    results['unlocked'] = page.locator('#view').is_visible()
    return results


def run(suite, base):
    OUT.mkdir(parents=True, exist_ok=True)
    server = ThreadingHTTPServer(('127.0.0.1', 0), ReferenceHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    report = {'suite':suite,'checks':[], 'errors':[], 'console_errors':[], 'failed_requests':[], 'contexts':'Fresh in-memory Playwright contexts, no personal browser profile',
              'normalization':'Fixed Date only (real timers and requestAnimationFrame), identical synthetic fixture, prefers-reduced-motion for deterministic comparisons, await fonts and two frames; SMIL paused at zero; no application code changes'}
    if PHASE3:report['visual_tolerance']='At most 10 rasterized pixels per image, each RGB channel delta <= 2/255; raw equality/diff always recorded, no layout/text tolerance'
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            report['chromium'] = browser.version
            contexts = [browser.new_context(viewport={'width':1440,'height':900},locale='es-AR',timezone_id='America/Argentina/Buenos_Aires',device_scale_factor=1,reduced_motion='reduce') for _ in range(2)]
            font_root=ROOT/'tests/assets/fonts'
            font_manifest=json.loads((font_root/'manifest.json').read_text('utf-8'))
            def font_route(route):
                if route.request.url==font_manifest['css_url']:
                    route.fulfill(path=str(font_root/font_manifest['css_file']),content_type='text/css')
                elif route.request.url in font_manifest['assets']:
                    asset=font_manifest['assets'][route.request.url]
                    data=(font_root/asset['file']).read_bytes()
                    assert hashlib.sha256(data).hexdigest()==asset['sha256']
                    route.fulfill(body=data,content_type='font/ttf')
                else:
                    route.abort()
            for context in contexts:
                context.route('https://fonts.googleapis.com/**',font_route)
                context.route('https://fonts.gstatic.com/**',font_route)
            report['fonts_source']='Original Google Fonts CSS and binaries cached privately, requests fulfilled identically for both pages; app links unchanged'
            pages = [c.new_page() for c in contexts]
            contexts[0].route(f'http://127.0.0.1:{server.server_port}/**',
                              lambda route:route.fulfill(body=ORIGINAL,content_type='text/html; charset=utf-8'))
            report['reference_transport']='Original immutable HTML fulfilled verbatim by Playwright at a separate loopback origin; modular version served by actual PHP'
            if PHASE3:
                request=contexts[1].request
                token=request.get(base+'api.php?action=session').json()['csrf']
                result=request.post(base+'api.php?action=login',headers={'X-CSRF-Token':token},
                    data={'email':os.environ['CENTRO_TEST_LOGIN_EMAIL'],'password':os.environ['CENTRO_TEST_LOGIN_PASSWORD']})
                assert result.status==200, result.status
                report['server_authentication']='Real PHP session authenticated via API in disposable test storage; not bypassed'
            for label, page, url in zip(('original','modular'),pages,(f'http://127.0.0.1:{server.server_port}/baseline.html',base)):
                page.on('pageerror', lambda e,label=label:report['errors'].append({'page':label,'message':str(e),'stack':e.stack}))
                page.on('console', lambda e,label=label:report['console_errors'].append({'page':label,'message':e.text}) if e.type=='error' else None)
                page.on('requestfailed', lambda r,label=label:report['failed_requests'].append({'page':label,'url':r.url,'failure':r.failure}))
                page.add_init_script("""(() => {
                  const NativeDate=Date;
                  const fixed=Date.parse('2026-10-15T15:00:00Z');
                  globalThis.Date=class extends NativeDate {
                    constructor(...args){super(...(args.length?args:[fixed]))}
                    static now(){return fixed}
                  };
                })();""")
                page.goto(url, wait_until='domcontentloaded')
                page.wait_for_selector('#obForm')
                page.evaluate('document.fonts.ready')
            report['fonts'] = [page.evaluate("[...document.fonts].map(f=>({family:f.family,status:f.status}))") for page in pages]
            fixture = pages[0].evaluate('() => ('+MAKE_FIXTURE+')()')
            if suite == 'visual':
                screenshot_pair(pages,'onboarding-desktop',report['checks'])
                for page in pages:
                    page.set_viewport_size({'width':390,'height':844})
                screenshot_pair(pages,'onboarding-mobile',report['checks'])
                visual(pages,fixture,report['checks'])
                for page in pages:
                    page.evaluate("state.settings.pin='2468'; unlocked=false; document.querySelector('#root').innerHTML=''; render()")
                screenshot_pair(pages,'lock-mobile',report['checks'])
            elif suite == 'controls':
                # Test original onboarding via actual inputs before loading the synthetic fixture.
                for page in pages:
                    page.locator('#obForm [name="name"]').fill('Prueba Fase 2')
                    page.locator('#obForm [name="owner"]').fill('Pruebas locales')
                    page.locator('#obForm [name="base"]').select_option('ARS')
                    page.locator('#obForm button').click()
                    page.wait_for_selector('#view')
                    assert page.evaluate('state.setupDone && state.sales.length===0 && state.customers.length===0')
                report['checks'].append({'check':'Onboarding via form without invented transactions','passed':True})
                original_result = controls(pages[0],fixture,'original')
                print('Original controls completed',flush=True)
                modular_result = controls(pages[1],fixture,'modular')
                for key in original_result:
                    passed=original_result[key] == modular_result[key]
                    report['checks'].append({'check':key,'passed':passed})
                    if not passed:
                        report.setdefault('differences',{})[key]={'original':original_result[key],'modular':modular_result[key]}
                report['results']={'original':original_result,'modular':modular_result}
            elif suite == 'resize':
                for page in pages:
                    page.bring_to_front()
                    page.set_viewport_size({'width':390,'height':844})
                    page.wait_for_timeout(500)
                by_page={label:[e for e in report['errors'] if e['page']==label] for label in ('original','modular')}
                if PHASE3:
                    report['checks'].append({'check':'Onboarding resize fixed: baseline error, zero modular errors',
                        'passed':len(by_page['original'])==1 and 'updateShell' in by_page['original'][0]['stack'] and not by_page['modular']})
                    report['errors'].clear()
                    for page in pages:
                        install_fixture(page,fixture)
                        page.evaluate("state.settings.pin='2468';unlocked=false;document.querySelector('#root').innerHTML='';render()")
                        page.set_viewport_size({'width':1440,'height':900})
                        page.wait_for_timeout(500)
                    report['checks'].append({'check':'Lock resize fixed: baseline error, zero modular errors',
                        'passed':any(e['page']=='original' for e in report['errors']) and not any(e['page']=='modular' for e in report['errors'])})
                    report['status']='Approved minimal guard fixes resize in onboarding and lock; baseline intentionally unchanged'
                else:
                    report['checks'].append({'check':'Inherited onboarding resize error reproduced in both versions',
                        'passed':all(len(by_page[label])==1 and 'updateShell' in by_page[label][0]['stack'] and 'textContent' in by_page[label][0]['message'] for label in by_page)})
                    report['status']='KNOWN ORIGINAL DEFECT REPRODUCED, NOT FIXED: resize calls updateShell while onboarding has no shell elements'
            else:
                motion_results=[]
                for label,page in zip(('original','modular'),pages):
                    page.bring_to_front()
                    page.emulate_media(reduced_motion='no-preference')
                    install_fixture(page,fixture)
                    page.evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))')
                    page.wait_for_timeout(120)
                    initial=page.evaluate("""()=>({
                      motion:matchMedia('(prefers-reduced-motion: reduce)').matches,
                      offset:parseFloat(getComputedStyle(document.querySelector('.chart path.draw')).strokeDashoffset),
                      running:document.getAnimations().filter(a=>a.playState==='running').length
                    })""")
                    page.wait_for_timeout(2200)
                    end=page.evaluate("""()=>({
                      offset:parseFloat(getComputedStyle(document.querySelector('.chart path.draw')).strokeDashoffset),
                      nodesVisible:[...document.querySelectorAll('.map .node')].every(n=>getComputedStyle(n).opacity==='1'),
                      counters:[...document.querySelectorAll('[data-count]')].map(n=>n.textContent),
                      lensWidth:parseFloat(getComputedStyle(document.querySelector('.lens-pill')).width)
                    })""")
                    page.screenshot(path=str(OUT/f'normal-motion-{label}.png'),full_page=True)
                    motion_results.append({'page':label,'initial':initial,'end':end})
                    report['checks'].append({'check':label+': normal animations run and settle',
                        'passed':not initial['motion'] and initial['offset']>0 and initial['running']>0 and end['offset']==0 and end['nodesVisible'] and end['lensWidth']>0})
                report['results']=motion_results
                report['checks'].append({'check':'Normal-motion final counters equal',
                    'passed':motion_results[0]['end']['counters']==motion_results[1]['end']['counters']})
            if suite != 'resize':
                if PHASE3:
                    report['checks'].append({'check':'No modular errors; original resize defect only on baseline',
                        'passed':all(e['page']=='original' and 'updateShell' in e.get('stack','') for e in report['errors'])})
                else:
                    report['checks'].append({'check':'No uncaught JavaScript errors','passed':not report['errors']})
            browser.close()
    except Exception as e:
        report['checks'].append({'check':'Suite completed','passed':False,'error':str(e)})
        raise
    finally:
        server.shutdown()
        (OUT / f'{suite}-results.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
    failures=[c for c in report['checks'] if not c['passed']]
    print(f'{suite}: {len(report["checks"])} checks, {len(failures)} failures; {len(report["errors"])} JS errors; {len(report["console_errors"])} console errors',flush=True)
    assert not failures, failures


if __name__ == '__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--suite',choices=['visual','controls','motion','resize'],required=True)
    parser.add_argument('--base',default='http://127.0.0.1:8080/')
    args=parser.parse_args()
    run(args.suite,args.base)
