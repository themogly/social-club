// Prompt 325 — a membership can be corrected after it is created. Throwaway database (DBFILE, read with sqlite3), as the
// owner, on M-00001 (a paid membership at Central Branch), given a second one at North Branch like Ben's photo:
//   1. Cambiar tarifa on the North one (fee still owed → what is owed follows the tier);
//   2. Corregir fechas: its expiry;
//   3. Cobrar cuota: its outstanding fee, cash, with North's till open;
//   4. Anular the Central one as a duplicate (paid → «Mantener la cuota pagada»);
//   5. every step in the audit log with its reason; the counter at Central no longer serves M-00001.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/325';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(700); };
const MEMBER = sql("select id from members where member_no = 'M-00001'");
const membershipAt = (sede) => sql(`select ms.id from memberships ms join locations l on l.id = ms.location_id where ms.member_id = '${MEMBER}' and l.name = '${sede}' order by ms.created_at desc limit 1`);
const modal = (p) => p.locator('.fi-modal-window:visible').last();
const pick = async (p, id, text) => {
    const field = modal(p).locator(`.fi-fo-field:has(label[for$="${id}"])`);
    const native = field.locator('select');
    if (await native.count()) { await native.selectOption({ label: text }); await settle(p); return; }
    await field.locator('.fi-select-input').first().click();
    await p.locator('[role="option"]:visible', { hasText: text }).first().click(); await settle(p);
};
const submit = async (p) => { await modal(p).locator('.fi-modal-footer-actions button').filter({ hasNotText: /^\s*(Cancelar|Cancel)\s*$/ }).first().click(); await settle(p); };
const rowAction = async (p, membershipId, label) => {
    const row = p.locator(`.fi-ta-row:has([wire\\:key*="${membershipId}"]), tr[wire\\:key*="${membershipId}"]`).first();
    const trigger = row.locator('.fi-dropdown-trigger button');
    if (await trigger.count()) { await trigger.first().click(); await p.waitForTimeout(300); }
    await p.locator(`.fi-dropdown-panel:visible button:has-text("${label}"), tr[wire\\:key*="${membershipId}"] button:has-text("${label}")`).first().click();
    await settle(p);
};

// North's till open (cash needs it).
const counter = await browser.newPage({ viewport: { width: 1180, height: 820 } });
await signInToCounter(counter, '/counter/till', { account: 'owner', sede: 'North Branch' });
if (await counter.locator('input[wire\\:model="floatInput"]').count()) {
    await counter.fill('input[wire\\:model="floatInput"]', '50');
    await counter.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(counter);
}

const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
check('sign in', await signIn(page, { account: 'owner' }));
await page.goto(`${BASE}/members/${MEMBER}`, { waitUntil: 'networkidle' });
await page.getByRole('tab', { name: /Membresías/ }).click().catch(() => {}); await settle(page);

// A second membership at North (the duplicate situation of Ben's photo), through Alta de membresía.
await page.getByRole('button', { name: 'Alta de membresía' }).click(); await settle(page);
await pick(page, 'location_id', 'North Branch');
await pick(page, 'tier_id', 'Member');
await submit(page);
const north = membershipAt('North Branch');
const central = membershipAt('Central Branch');
check('M-00001 has two memberships', north !== '' && central !== '', `${central} / ${north}`);
await page.screenshot({ path: `${OUT}/0-two-memberships-1440.png`, fullPage: true });

// 1. Cambiar tarifa.
await rowAction(page, north, 'Cambiar tarifa');
await pick(page, 'tier_id', 'Therapeutic');
await modal(page).locator('input[id$="reason"]').fill('Es terapéutico, se equivocó de tarifa');
await page.screenshot({ path: `${OUT}/1-change-tier-1440.png` });
await submit(page);
check('the tier changed and what is owed followed it', sql(`select t.name || '/' || ms.fee_cents from memberships ms join membership_tiers t on t.id = ms.tier_id where ms.id = '${north}'`) === 'Therapeutic/1000');

// 2. Corregir fechas.
await rowAction(page, north, 'Corregir fechas');
const expiry = new Date(Date.now() + 200 * 86400000).toISOString().slice(0, 10);
await modal(page).locator('input[id$="expires_at"]').fill(expiry); await settle(page);
await modal(page).locator('input[id$="reason"]').fill('Caducidad mal puesta');
await page.screenshot({ path: `${OUT}/2-correct-dates-1440.png` });
await submit(page);
check('the expiry moved', sql(`select substr(expires_at, 1, 10) from memberships where id = '${north}'`) === expiry, sql(`select expires_at from memberships where id = '${north}'`));

// 3. Cobrar cuota.
await rowAction(page, north, 'Cobrar cuota');
await page.screenshot({ path: `${OUT}/3-collect-1440.png` });
await submit(page);
check('the €10 was collected into North\'s open till', sql(`select p.amount_cents || '/' || p.method || '/' || (p.till_session_id is not null) from membership_fee_payments p where p.membership_id = '${north}'`) === '1000/CASH/1');

// 4. Anular the Central one.
await rowAction(page, central, 'Anular');
await modal(page).locator('input[id$="reason"]').fill('Duplicada: se atiende en North');
for (const box of await modal(page).locator('input[type="checkbox"]').all()) await box.check();
await page.screenshot({ path: `${OUT}/4-cancel-1440.png` });
await submit(page);
check('the Central membership is cancelled, the row kept', sql(`select status from memberships where id = '${central}'`) === 'CANCELLED');
await page.screenshot({ path: `${OUT}/5-after-1440.png`, fullPage: true });

// 5. The audit log, with reasons; the counter.
const audits = sql(`select action || '|' || coalesce(json_extract("after", '$.reason'), '') from audit_logs where action in ('membership.tier.changed', 'membership.dates.corrected', 'membership.cancelled') order by rowid`).split('\n');
check('every correction is in the audit log with its reason', audits.length === 3 && audits.every((a) => a.split('|')[1] !== ''), JSON.stringify(audits));
// A second tablet at Central (the North one keeps its till open, so it can't simply switch).
const central2 = await (await browser.newContext({ viewport: { width: 1180, height: 820 } })).newPage();
await signInToCounter(central2, '/counter/till', { account: 'owner', sede: 'Central Branch' });
if (await central2.locator('input[wire\\:model="floatInput"]').count()) {
    await central2.fill('input[wire\\:model="floatInput"]', '50');
    await central2.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(central2);
}
await central2.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
await central2.fill('#member-lookup', 'M-00001'); await central2.press('#member-lookup', 'Enter'); await settle(central2);
await central2.click('[data-member-lookup-result]').catch(() => {}); await settle(central2);
const pos = (await central2.locator('main').innerText()).replace(/\s+/g, ' ');
check('the Central counter no longer serves M-00001', /Sin membresía|membresía activa|no tiene membresía|sin membresía/i.test(pos), pos.slice(0, 200));
await central2.screenshot({ path: `${OUT}/6-counter-central-1180.png` });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
