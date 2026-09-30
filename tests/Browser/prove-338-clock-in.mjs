// Prompt 338 — opening a till clocks the opener in. Freshly seeded demo DB (DBFILE), Central Branch, 1180×820. The close
// needs `till.close`, which staff do not hold by default, so this runs as the manager (PIN 2345). The top bar's own
// «Fichar entrada» is prompt 341's; here the bar's clock status is today's «Fichar salida», which follows the same event.
//   1. clocked in, till open → close it: it clocks out (312), and «Fichar salida» leaves the bar;
//   2. open the till: clocked in (TILL_OPEN) with «Deshacer» on the next screen, «Fichar salida» back;
//   3. «Deshacer» annuls it and «Fichar salida» leaves again; lock and unlock → 281's «Fichar entrada» with the PIN;
//   4. «Mis horas» lists the day's periods.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/338';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(700); };
const central = sql("select id from locations where name = 'Central Branch'");
const idOf = (email) => sql(`select id from users where email like '${email}%'`);
const events = (user) => sql(`select type || ':' || source from staff_clock_events where user_id = '${user}' order by recorded_at, rowid`).split('\n').filter(Boolean);
const pin = async (p, digits) => { for (const d of digits) await p.locator('[data-counter-surface] button, button').filter({ hasText: new RegExp(`^${d}$`) }).first().click(); await p.click('[data-counter-surface-unlock]'); await settle(p); await p.waitForTimeout(900); };

async function counter(account) {
    const page = await (await browser.newContext({ viewport: { width: 1180, height: 820 } })).newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    await signInToCounter(page, '/counter/till', { account, sede: 'Central Branch' });
    await settle(page);
    return page;
}

// --- 1. The manager closes the till -----------------------------------------------------------------------------------
const manager = idOf('manager@');
let page = await counter('manager');
if (await page.locator('[data-clock-in]').isVisible().catch(() => false)) { await page.click('[data-clock-in]'); await settle(page); }
if (await page.locator('input[wire\\:model="floatInput"]').count()) {
    await page.fill('input[wire\\:model="floatInput"]', '50');
    await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
    await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
}
const before = events(manager).length;
await page.click('[data-close-till]'); await settle(page);
for (const input of await page.locator('input[id^="reweigh-"]').all()) {
    const id = (await input.getAttribute('id')).slice('reweigh-'.length);
    await input.fill((Number(sql(`select remaining_cg from batches where id = '${id}'`)) / 100).toFixed(2));
}
if (await page.locator('input[id^="reweigh-"]').count()) { await page.getByRole('button', { name: 'Confirmar recuento' }).click(); await settle(page); }
await page.fill('#count', '50');
await page.locator('form[wire\\:submit="submitCount"] button[type="submit"]').click(); await settle(page);
check('closing the till clocks the manager out (TILL_CLOSE)', events(manager).slice(before).includes('OUT:TILL_CLOSE'), events(manager).slice(before).join(' '));
check('the bar\'s «Fichar salida» has gone (the state event reached the bar)', ! await page.locator('[data-counter-clock-out]').isVisible());
await page.screenshot({ path: `${OUT}/1-closed-clock-in-button-1180.png` });

// --- 2. Open: clocked in, then undone -------------------------------------------------------------------------------------
await page.getByRole('button', { name: /Abrir|Nueva caja|Empezar/ }).first().click().catch(() => {});
await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
await page.fill('input[wire\\:model="floatInput"]', '50');
await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
const notice = page.locator('[data-clock-in-notice]');
check('opening clocks the manager in (TILL_OPEN), shown on the next screen with «Deshacer»', events(manager).at(-1) === 'IN:TILL_OPEN' && await notice.isVisible() && /Entrada fichada a las \d\d:\d\d\./.test(await notice.innerText())
    && await page.locator('[data-counter-clock-out]').isVisible(), `${events(manager).at(-1)} · ${page.url()}`);
await page.screenshot({ path: `${OUT}/2-clocked-in-on-open-1180.png` });
await page.click('[data-clock-in-undo]'); await settle(page);
check('«Deshacer» annuls it, and «Fichar salida» leaves the bar again', events(manager).at(-1) === 'ANNUL:TILL_OPEN' && ! await page.locator('[data-counter-clock-out]').isVisible() && ! await notice.isVisible());

// --- 3. Clocking in again, the way there is today: lock, PIN, 281's question ------------------------------------------------
await page.click('[data-counter-lock]'); await settle(page);
await pin(page, '2345');
await page.locator('[data-clock-in]').click(); await settle(page);
check('lock → PIN → «Fichar entrada» writes a PIN clock-in and «Fichar salida» is back', events(manager).at(-1) === 'IN:PIN' && await page.locator('[data-counter-clock-out]').isVisible(), events(manager).at(-1));
await page.click('[data-counter-my-hours]'); await settle(page);
const hours = (await page.locator('[data-my-hours]').innerText()).replace(/\s+/g, ' ');
check('«Mis horas» lists the day\'s periods', await page.locator('[data-my-hours-row]').count() >= 1, hours.slice(0, 160));
await page.screenshot({ path: `${OUT}/3-my-hours-1180.png` });
await page.context().close();

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
