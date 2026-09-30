// Prompt 332 — *Escanear con cámara* on every counter member search. Throwaway database with camera_scan_enabled (and the
// Bar's socio panel) on at Central Branch; TOKEN = a member's QR-card token (IssueMemberToken). At 1180×820:
//   1. Recepción, Socios, Dispensario and Barra each show the camera button in their member search;
//   2. on Socios a scanned card opens that member's fee panel. Headless Chrome has no camera, so the decoded token is
//      handed to the lookup exactly as the scanner does after decoding (`$wire.submitCameraScan(token)`).
import { chromium } from 'playwright';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/332';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };

await signInToCounter(page, '/counter/till');
if (await page.locator('input[wire\\:model="floatInput"]').count()) {
    await page.fill('input[wire\\:model="floatInput"]', '50');
    await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle();
}

const supported = await page.evaluate(() => 'BarcodeDetector' in window);
for (const [name, path] of [['Recepción', '/counter/checkin'], ['Socios', '/counter/members'], ['Dispensario', '/counter/pos'], ['Barra', '/counter/bar']]) {
    await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' });
    const lookup = page.locator('#member-lookup').first();
    const camera = page.locator('[data-camera-scan]').first();
    const inDom = await camera.count() === 1;
    // The button shows only where the browser can decode (BarcodeDetector); in the DOM either way.
    const visible = supported ? await camera.locator('button').first().isVisible() : inDom;
    check(`${name}: the member search has «Escanear con cámara»`, await lookup.count() === 1 && inDom && visible, supported ? 'visible' : 'in the DOM (this browser has no BarcodeDetector)');
    await page.screenshot({ path: `${OUT}/${name.normalize('NFD').replace(/[^\w]/g, '').toLowerCase()}-1180.png` });
}

await page.goto(`${BASE}/counter/members`, { waitUntil: 'networkidle' });
await page.evaluate((token) => {
    const el = document.querySelector('[data-camera-scan]').closest('[wire\\:id]');
    return window.Livewire.find(el.getAttribute('wire:id')).submitCameraScan(token);
}, process.env.TOKEN);
await settle();
const main = (await page.locator('main').innerText()).replace(/\s+/g, ' ');
check('a scanned card on Socios opens that member\'s fee panel', /M-00001/.test(main) && /Cuota:.*Pendiente:/.test(main), main.slice(0, 120));
await page.screenshot({ path: `${OUT}/socios-scanned-1180.png` });

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
