// Prompt 335 — the owner changes sede with a till still open. Freshly seeded demo DB (DBFILE), at 1180×820, as the owner:
//   1. open the till at Central Branch (float €50);
//   2. switch to North Branch: the counter asks; confirm → North Branch, with its own «Abrir caja»;
//   3. switch back: Central Branch's till is still open with its float; close it normally.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/335';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };
const central = sql("select id from locations where name = 'Central Branch'");
const north = sql("select id from locations where name = 'North Branch'");
const openTill = (loc) => sql(`select id || '|' || float_cents from till_sessions where location_id = '${loc}' and status = 'OPEN'`);

// 1. The till at Central Branch.
await signInToCounter(page, '/counter/till', { sede: 'Central Branch' });
await page.fill('input[wire\\:model="floatInput"]', '50');
await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle();
const till = openTill(central);
check('the till is open at Central Branch with €50', till.endsWith('|5000'), till);

// 2. To North Branch: asked, then switched.
await page.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
await page.click('button[data-counter-sede-current]');
await page.click(`[data-counter-sede="${north}"]`); await settle();
const ask = page.locator('[data-counter-sede-switch-confirm]');
const text = (await ask.innerText().catch(() => '')).replace(/\s+/g, ' ');
check('the counter asks: «La caja de Central Branch sigue abierta.»', /La caja de Central Branch sigue abierta\./.test(text) && /¿Cambiar a North Branch\?/.test(text), text);
await page.screenshot({ path: `${OUT}/1-asked-1180.png` });
await page.click('[data-counter-sede-switch-go]'); await settle();
check('confirmed: the counter is at North Branch', (await page.locator('[data-counter-sede-current]').first().getAttribute('data-counter-sede-current')) === north);
check('and Central Branch\'s till is exactly as it was', openTill(central) === till, openTill(central));
await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
check('North Branch shows its own «Abrir caja»', new URL(page.url()).pathname === '/counter/till' && await page.locator('input[wire\\:model="floatInput"]').count() === 1, page.url());
await page.screenshot({ path: `${OUT}/2-north-open-till-1180.png` });
check('the switch is on the audit log', sql("select count(*) from audit_logs where action = 'counter.sede.switched_with_open_till'") === '1');

// 3. Back to Central Branch: the till is still open; close it.
await page.click('button[data-counter-sede-current]');
await page.click(`[data-counter-sede="${central}"]`); await settle();
check('back at Central Branch with no question (North has no open till)', await page.locator('[data-counter-sede-switch-confirm]').count() === 0
    && (await page.locator('[data-counter-sede-current]').first().getAttribute('data-counter-sede-current')) === central);
await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
check('its till is still open, with its float', await page.locator('[data-close-till]').count() === 1 && openTill(central) === till);
await page.click('[data-close-till]'); await settle();
// The last close of the day asks for the blind flower recount first when flower was sold today (47/318) — the
// normal close. Weigh each batch at what the system holds (grams, the scale's reading), then the cash count.
for (const input of await page.locator('input[id^="reweigh-"]').all()) {
    const id = (await input.getAttribute('id')).slice('reweigh-'.length);
    const cg = Number(sql(`select remaining_cg from batches where id = '${id}'`));
    await input.fill((cg / 100).toFixed(2));
}
if (await page.locator('input[id^="reweigh-"]').count()) {
    await page.getByRole('button', { name: 'Confirmar recuento' }).click(); await settle();
    const next = page.locator('button[wire\\:click="proceedToCount"], button[wire\\:click="continueToCount"]').first();
    if (await next.count()) { await next.click(); await settle(); }
}
await page.fill('#count', '50');
await page.locator('form[wire\\:submit="submitCount"] button[type="submit"]').click(); await settle();
const confirm = page.locator('button[wire\\:click="confirmClose"], button[wire\\:click^="close"]').first();
if (await confirm.count()) { await confirm.click(); await settle(); }
check('and it closes normally', sql(`select status from till_sessions where id = '${till.split('|')[0]}'`) === 'CLOSED', sql(`select status from till_sessions where id = '${till.split('|')[0]}'`));
await page.screenshot({ path: `${OUT}/3-closed-1180.png` });

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
