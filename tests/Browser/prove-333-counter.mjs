// Prompt 333 — the counter, on a freshly seeded demo DB (DBFILE, read with sqlite3; M-00028 owes €20 at Central Branch):
//   1. 820×1180, the catalogue scrolled to its last strain: a tap brings the keypad fully into view, focused;
//      1180×820, the keypad already on screen: another tap scrolls nothing.
//   2. a basket adjusted to €15 with Enter (no blur): the button, «Justo» and «Falta» all follow;
//   3. as staff (PIN 3456): no «Aprobado por responsable», and a waiver without a reason is refused;
//      as the manager (PIN 2345): the adjustment's reason is pre-filled and commits as «Aprobado por responsable» by
//      them; the waiver's pre-selected option waives the fee with the same reason.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/333';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];

async function counter(account, size) {
    const context = await browser.newContext({ viewport: size });
    const page = await context.newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    await signInToCounter(page, '/counter/till', { account });
    if (await page.locator('input[wire\\:model="floatInput"]').count()) {
        await page.fill('input[wire\\:model="floatInput"]', '50');
        await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
    }
    return page;
}
async function settle(page) { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); }
async function member(page, no) {
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    await page.fill('#member-lookup', no); await page.press('#member-lookup', 'Enter'); await settle(page);
    await page.click('[data-member-lookup-result]'); await settle(page);
}
const inView = (page) => page.evaluate(() => {
    const pad = document.querySelector('[data-weight-entry] [data-weight-pad]')?.getBoundingClientRect();
    return !! pad && pad.top >= 0 && pad.bottom <= window.innerHeight;
});
const focused = (page) => page.evaluate(() => !! document.activeElement?.closest('[data-weight-entry]'));
const money = (s) => s.replace(/ /g, ' ');

// --- 1. The keypad comes into view ------------------------------------------------------------------------------------------
let page = await counter('manager', { width: 820, height: 1180 });
await member(page, 'M-00001');
const strains = page.locator('[data-catalogue-item="genetics"]:is([data-type="FLOWER"],[data-type="HASH"]):not([disabled]):visible'); // weighed: the keypad
const last = strains.last();
await last.scrollIntoViewIfNeeded(); await page.waitForTimeout(300);
check('820×1180: scrolled down to the last strain, the keypad is not on screen', ! (await page.locator('[data-weight-entry]').count()) || ! (await inView(page)));
await last.click(); await settle(page); await page.waitForTimeout(600);
check('a tap brings the keypad fully into view', await inView(page));
check('and focus is in the keypad panel', await focused(page));
await page.keyboard.press('2');
check('the physical keyboard types into the pad', /2/.test(await page.locator('[data-weight-display]').innerText()));
await page.screenshot({ path: `${OUT}/1-keypad-in-view-820x1180.png` });
await page.context().close();

page = await counter('manager', { width: 1180, height: 820 });
await member(page, 'M-00001');
const wide = page.locator('[data-catalogue-item="genetics"]:is([data-type="FLOWER"],[data-type="HASH"]):not([disabled]):visible');
await wide.first().click(); await settle(page); await page.waitForTimeout(600);
const scrollOf = () => page.evaluate(() => [document.querySelector('[data-selection-pane]')?.scrollTop ?? 0, window.scrollY]);
const before = await scrollOf();
check('1180×820: the keypad is on screen', await inView(page));
await wide.nth(1).click(); await settle(page); await page.waitForTimeout(600);
const after = await scrollOf();
check('another strain tap with the keypad visible scrolls nothing', JSON.stringify(before) === JSON.stringify(after), `${before} → ${after}`);

// --- 2. The adjustment moves every figure (Enter, no blur) -----------------------------------------------------------------
await page.locator('[data-weight-pad] button', { hasText: /^3$/ }).click();
await page.click('[data-add-line]'); await settle(page);
const resolved = money(await page.locator('[data-commit-action]').innerText());
await page.click('[data-price-override-toggle]'); await page.waitForTimeout(200);
check('the manager\'s reason is pre-filled «Aprobado por responsable»', (await page.inputValue('#price-override-reason')) === 'Aprobado por responsable');
await page.fill('#price-override-amount', '15'); await page.press('#price-override-amount', 'Enter'); await settle(page);
const button = money(await page.locator('[data-commit-action]').innerText());
check('Enter applies it: the button reads · 15', /15[.,]00 €/.test(button), `${resolved.replace(/\s+/g, ' ')} → ${button.replace(/\s+/g, ' ')}`);
await page.getByRole('button', { name: 'Justo' }).click(); await settle(page);
check('«Justo» fills in 15,00', (await page.inputValue('#pos-cash-tendered')) === '15,00');
await page.fill('#pos-cash-tendered', '10'); await page.waitForTimeout(900); await settle(page);
const summary = money(await page.locator('[data-tender-summary]').innerText());
check('«Falta» is worked out from 15: 5,00', /5[.,]00 €/.test(summary), summary.replace(/\s+/g, ' '));
await page.screenshot({ path: `${OUT}/2-adjusted-1180x820.png` });
await page.getByRole('button', { name: 'Justo' }).click(); await settle(page);
const pad = page.locator('[data-signature-canvas]');
if (await pad.count()) {
    const box = await pad.boundingBox();
    await page.mouse.move(box.x + 20, box.y + 30); await page.mouse.down();
    await page.mouse.move(box.x + 120, box.y + 80, { steps: 8 }); await page.mouse.up();
    await page.click('[data-signature-save]'); await settle(page);
}
await page.click('[data-commit-action]'); await settle(page);
const managerId = sql("select id from users where email like 'manager@%'");
const d = sql("select total_cents || '|' || cash_cents || '|' || price_override_reason || '|' || price_override_by from dispensations where price_override_by is not null order by created_at desc limit 1");
check('committed: 15,00 in cash, «Aprobado por responsable», by the manager', d === `1500|1500|Aprobado por responsable|${managerId}`, d);
await page.context().close();

// --- 3. Staff still give a reason; the manager waives with the pre-selected option -------------------------------------------
page = await counter('staff', { width: 1180, height: 820 });
await page.goto(`${BASE}/counter/members`, { waitUntil: 'networkidle' });
await page.fill('#member-lookup', 'M-00028'); await page.press('#member-lookup', 'Enter'); await settle(page);
await page.click('[data-member-lookup-result]'); await settle(page);
await page.click('[wire\\:click="toggleWaive"]'); await settle(page);
check('staff: no «Aprobado por responsable» option', await page.locator('[data-waive-reason="MANAGER_APPROVED"]').count() === 0);
await page.click('[data-fee-waive-submit]'); await settle(page);
check('staff: a waiver without a reason is refused', sql("select count(*) from membership_fee_payments where method = 'WAIVED'") === '0');
await page.screenshot({ path: `${OUT}/3-staff-waiver-1180x820.png` });
await page.context().close();

page = await counter('manager', { width: 1180, height: 820 });
await page.goto(`${BASE}/counter/members`, { waitUntil: 'networkidle' });
await page.fill('#member-lookup', 'M-00028'); await page.press('#member-lookup', 'Enter'); await settle(page);
await page.click('[data-member-lookup-result]'); await settle(page);
await page.click('[wire\\:click="toggleWaive"]'); await settle(page);
check('manager: «Aprobado por responsable» is offered first and pre-selected', await page.locator('[data-waive-reason="MANAGER_APPROVED"]').isChecked()
    && (await page.locator('[data-waive-reason]').first().getAttribute('data-waive-reason')) === 'MANAGER_APPROVED');
await page.screenshot({ path: `${OUT}/4-manager-waiver-1180x820.png` });
await page.click('[data-fee-waive-submit]'); await settle(page);
const w = sql(`select amount_cents || '|' || reason || '|' || recorded_by from membership_fee_payments where method = 'WAIVED'`);
check('manager: the €20 fee is waived, «Aprobado por responsable», by them', w === `2000|Aprobado por responsable|${managerId}`, w);
await page.context().close();

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
