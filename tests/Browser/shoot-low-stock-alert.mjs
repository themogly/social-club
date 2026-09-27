// Prompt 269 — the tester's check on the REAL app: a variety set under its "Aviso de stock bajo" shows up in "Requiere atención"
// on the panel dashboard AND on the counter hub, and the picker still badges it.
//
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DevAdminSeeder
//   (then give ONE variety at the sede a threshold above its stock, e.g. through Variedades → Precios → Aviso de stock bajo)
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/shoot-low-stock-alert.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, SEDE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/269';
mkdirSync(OUT, { recursive: true });
const ALERT = /variedad(es)? con stock bajo|variet(y|ies) running low/i;
const results = [];
const browser = await chromium.launch();

// The panel dashboard, desktop.
{
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  if (! await signIn(page)) {
    results.push(['panel sign in', false]);
  } else {
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    const text = await page.innerText('body');
    await page.getByText(ALERT).first().scrollIntoViewIfNeeded().catch(() => {});
    await page.screenshot({ path: `${OUT}/1-panel-dashboard.png` });
    results.push(['panel dashboard: "Requiere atención" lists the low variety', ALERT.test(text)]);
  }
}

// The counter hub, tablet.
{
  const page = await (await browser.newContext({ viewport: { width: 1180, height: 820 }, hasTouch: true })).newPage();
  if (! await signInToCounter(page, '/counter/till', { sede: SEDE })) {
    results.push(['counter sign in', false]);
  } else {
    const float = await page.$('input[data-till-float]');
    if (float) { await float.fill('100'); await page.click('[data-till-open-action]'); await page.waitForTimeout(1000); }
    await page.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
    const text = await page.innerText('body');
    await page.screenshot({ path: `${OUT}/2-counter-hub.png` });
    results.push(['counter hub: the alert is there', ALERT.test(text)]);
  }
}

await browser.close();
let ok = true;
for (const [text, pass] of results) { console.log(`${pass ? 'PASS' : 'FAIL'}  ${text}`); ok = ok && pass; }
process.exitCode = ok ? 0 : 1;
