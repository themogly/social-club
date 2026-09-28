// Prompt 281 — drive the registro de jornada on the REAL counter: the clock-in question, the idle unlock that
// must NOT ask, "Mis horas", and "Fichar salida" with the PIN, at the two tablet orientations.
import { chromium } from 'playwright';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/281';
const PIN = process.env.DEV_PIN ?? '3456';
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };

async function typePin(page) {
    for (const d of PIN.split('')) {
        await page.click(`[data-counter-surface] button:has-text("${d}")`);
    }
    await page.click('[data-counter-surface-unlock]');
    await page.waitForTimeout(1200);
}

async function smallTargets(page, selector) {
    return page.$$eval(selector, (els) => els.filter((e) => e.offsetParent !== null)
        .map((e) => ({ t: (e.innerText || e.getAttribute('aria-label') || '').trim().slice(0, 30), h: e.getBoundingClientRect().height, w: e.getBoundingClientRect().width }))
        .filter((r) => r.h < 44 || r.w < 44));
}

const [w, h] = (process.env.VIEWPORT ?? '1180x820').split('x').map(Number);
const tag = `${w}x${h}`;
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: w, height: h }, colorScheme: process.env.SCHEME ?? 'light' });

check('sign in', await signIn(page));
await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
const sede = await page.$('[data-counter-sede-menu] form button');
if (sede) { await sede.click(); await page.waitForLoadState('networkidle'); }

// 1. The first PIN of the shift asks the question.
await typePin(page);
const q = await page.$('[data-clock-in-question]');
check('clock-in question shown after first PIN', !! q && await q.isVisible());
await page.screenshot({ path: `${OUT}/${tag}-${process.env.SCHEME ?? 'light'}-1-question.png` });
if (q) {
    const box = await q.boundingBox();
    check('question fits above the fold', box.y + box.height <= h, `bottom=${Math.round(box.y + box.height)} h=${h}`);
    const small = await smallTargets(page, '[data-clock-in-question] button');
    check('question buttons meet 44px', small.length === 0, JSON.stringify(small));
}
await page.click('[data-clock-in]');
await page.waitForTimeout(1200);
check('surface closes after Fichar entrada', await page.$eval('[data-counter-surface]', (e) => e.dataset.surfaceMode) === 'none'
    || ! await page.isVisible('[data-counter-surface] [data-counter-surface-unlock]'));
const out = await page.$('[data-counter-clock-out]');
check('top bar shows Fichar salida', !! out && await out.isVisible());

// 2. The idle lock, twice: the unlock never asks.
for (const n of [1, 2]) {
    await page.evaluate(() => window.Alpine.store('counter').lockNow());
    await page.waitForTimeout(1200);
    await typePin(page);
    const asked = await page.$('[data-clock-in-question]');
    check(`idle unlock ${n} asks nothing`, ! asked || ! await asked.isVisible());
}

// 3. Mis horas.
await page.click('[data-counter-my-hours]');
await page.waitForTimeout(1000);
check('Mis horas opens', await page.isVisible('[data-my-hours]'));
check('Mis horas lists the open period', (await page.$$('[data-my-hours-row]')).length >= 1);
await page.screenshot({ path: `${OUT}/${tag}-${process.env.SCHEME ?? 'light'}-2-my-hours.png` });
await page.goBack();
await page.waitForTimeout(800);
check('Back closes Mis horas and stays on the counter', ! await page.isVisible('[data-my-hours]') && page.url().includes('/counter'));

// 4. Fichar salida asks for the PIN, then locks the counter.
await page.click('[data-counter-clock-out]');
await page.waitForTimeout(1000);
check('clock-out asks for the PIN', await page.isVisible('[data-counter-surface-unlock]'));
await page.screenshot({ path: `${OUT}/${tag}-${process.env.SCHEME ?? 'light'}-3-clock-out-pin.png` });
const small = await smallTargets(page, '[data-clock-out-cancel], [data-counter-clock-out], [data-counter-my-hours]');
check('clock-out controls meet 44px', small.length === 0, JSON.stringify(small));
await typePin(page);
const mode = await page.$eval('[data-counter-surface]', (e) => e.dataset.surfaceMode);
check('counter locks after clocking out', mode === 'unidentified' || await page.isVisible('[data-counter-surface-unlock]'), `mode=${mode}`);
await page.screenshot({ path: `${OUT}/${tag}-${process.env.SCHEME ?? 'light'}-4-after-clock-out.png` });

await browser.close();
process.exit(results.every((r) => r.ok) ? 0 : 1);
