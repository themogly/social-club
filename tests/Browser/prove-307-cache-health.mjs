// Prompt 307 — *Salud del sistema*'s Caché card against a REAL Redis. Two throwaway servers on the same database copy,
// both CACHE_STORE=redis: BASE_URL with the local Redis up, DOWN_URL pointed at a closed Redis port. Signed in on the
// first (sessions are in the database, cookies ignore the port), the card reads *Accesible* there and *No accesible*
// on the second, with the corrected queue sentence. Light and dark, 1440×900 and 390×844.
import { chromium } from 'playwright';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/307';
const DOWN = process.env.DOWN_URL ?? 'http://127.0.0.1:8150';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();

for (const [w, h, scheme] of [[1440, 900, 'light'], [390, 844, 'dark']]) {
    const tag = `${w}x${h}-${scheme}`;
    const page = await browser.newPage({ viewport: { width: w, height: h }, colorScheme: scheme });
    page.setDefaultTimeout(10000);
    check(`${tag}: sign in`, await signIn(page, { account: 'owner' }));

    const card = () => page.locator('section, .fi-section').filter({ hasText: 'Almacén' }).filter({ hasText: /Caché|Cache/ }).last();
    await page.goto(`${BASE}/salud-del-sistema`, { waitUntil: 'networkidle' });
    const up = (await card().textContent()) ?? '';
    check(`${tag}: Redis up → Accesible`, /Accesible|Reachable/.test(up) && ! /No accesible|Unreachable/.test(up), up.replace(/\s+/g, ' ').trim().slice(0, 80));
    await card().scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/${tag}-up.png` });

    await page.goto(`${DOWN}/salud-del-sistema`, { waitUntil: 'networkidle' });
    const down = (await card().textContent()) ?? '';
    check(`${tag}: Redis down → No accesible, queues sentence`, /No accesible|Unreachable/.test(down) && /las colas tampoco procesan|queues aren't processing/.test(down), down.replace(/\s+/g, ' ').trim().slice(0, 160));
    await card().scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/${tag}-down.png` });
    await page.close();
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
