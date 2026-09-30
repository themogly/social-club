// Prompt 337 — Recepción switched off per sede. Freshly seeded demo DB (DBFILE), as the owner, at 1180×820:
//   1. in the panel, North Branch: the check-in gate on, then «Mostrar Recepción» off — the gate goes off with it;
//   2. the counter at North Branch: no Recepción tile, no «En el local», no «Entradas»; a «Nuevo socio» tile in its place
//      opens Socios with the sign-up; /counter/checkin sends to the hub, saying why; a member who never checked in is served;
//   3. Central Branch: Recepción as before.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/337';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(600); };
const north = sql("select id from locations where name = 'North Branch'");
const central = sql("select id from locations where name = 'Central Branch'");
const setting = (key) => sql(`select value from settings where key = '${key}' and location_id = '${north}'`);

// 1. The panel.
const panel = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
await signIn(panel);
await panel.goto(`${BASE}/locations/${north}/edit`, { waitUntil: 'networkidle' });
const gate = panel.getByRole('switch', { name: 'Solo dispensar a socios que han entrado' });
if ((await gate.getAttribute('aria-checked')) !== 'true') await gate.click();
await panel.getByRole('button', { name: 'Guardar cambios', exact: true }).click(); await settle(panel);
check('the gate stored on first', setting('restrict_pos_to_checked_in') === '1', setting('restrict_pos_to_checked_in'));
await panel.goto(`${BASE}/locations/${north}/edit`, { waitUntil: 'networkidle' });
await panel.getByRole('switch', { name: 'Mostrar Recepción en el mostrador' }).click(); await settle(panel);
const gateNow = panel.getByRole('switch', { name: 'Solo dispensar a socios que han entrado' });
check('turning Recepción off disables the gate and turns it off, saying why', (await gateNow.getAttribute('aria-checked')) === 'false'
    && await gateNow.isDisabled() && await panel.getByText('Requiere Recepción.').isVisible());
await panel.screenshot({ path: `${OUT}/1-sede-form-1440.png`, fullPage: true });
await panel.getByRole('button', { name: 'Guardar cambios', exact: true }).click(); await settle(panel);
const off = (v) => v === '0' || v === ''; // a false Setting is stored as ''
check('saved: Recepción off, the gate off', off(setting('reception_enabled')) && off(setting('restrict_pos_to_checked_in')), `${setting('reception_enabled')} ${setting('restrict_pos_to_checked_in')}`);
await panel.context().close();

// 2. The counter at North Branch.
const page = await (await browser.newContext({ viewport: { width: 1180, height: 820 } })).newPage();
page.on('pageerror', (e) => errors.push(e.message));
await signInToCounter(page, '/counter/till', { sede: 'North Branch' });
if (await page.locator('input[wire\\:model="floatInput"]').count()) {
    await page.fill('input[wire\\:model="floatInput"]', '50');
    await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
}
await page.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
check('North Branch hub: no Recepción, no «En el local», no «Entradas»', await page.locator('[data-counter-home-tile="counter.checkin"]').count() === 0
    && await page.locator('[data-panel="presence"]').count() === 0 && await page.locator('[data-figure="check_ins"]').count() === 0);
const tile = page.locator('[data-counter-home-tile-new-member]');
check('a «Nuevo socio» tile sits first among the tiles', await tile.count() === 1
    && (await page.locator('[data-counter-home-tiles] .grid > a').first().getAttribute('data-counter-home-tile-new-member')) !== null);
await page.screenshot({ path: `${OUT}/2-hub-north-1180.png` });
await tile.click(); await settle(page);
check('it opens Socios with the sign-up open', new URL(page.url()).pathname === '/counter/members' && await page.getByText('¿Cómo vais a rellenar la solicitud?').isVisible(), page.url());
await page.screenshot({ path: `${OUT}/3-sign-up-open-1180.png` });

await page.goto(`${BASE}/counter/checkin`, { waitUntil: 'networkidle' });
check('/counter/checkin goes to the hub, saying why', new URL(page.url()).pathname === '/counter' && /Recepción está desactivada en esta sede\./.test(await page.locator('main').innerText()));

const member = sql(`select m.member_no from members m join memberships ms on ms.member_id = m.id where ms.location_id = '${north}' and ms.status = 'ACTIVE' and m.status = 'ACTIVE' and (m.carencia_ends_at is null or m.carencia_ends_at < datetime('now')) and not exists (select 1 from check_ins c where c.member_id = m.id and c.checked_out_at is null) limit 1`);
const before = Number(sql(`select count(*) from dispensations where location_id = '${north}'`));
await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
await page.fill('#member-lookup', member); await page.press('#member-lookup', 'Enter'); await settle(page);
await page.click('[data-member-lookup-result]'); await settle(page);
const fee = await page.$('form[wire\\:submit="collectMemberFee"] button[type="submit"]');
if (fee) { await fee.click(); await settle(page); }
await page.locator('[data-catalogue-item="genetics"]:is([data-type="FLOWER"],[data-type="HASH"]):not([disabled]):visible').first().click(); await settle(page);
await page.locator('[data-weight-pad] button', { hasText: /^1$/ }).click();
await page.click('[data-add-line]'); await settle(page);
await page.locator('[wire\\:click^="quickCash"]').first().click(); await settle(page);
const pad = page.locator('[data-signature-canvas]');
if (await pad.count()) {
    const box = await pad.boundingBox();
    await page.mouse.move(box.x + 20, box.y + 30); await page.mouse.down();
    await page.mouse.move(box.x + 120, box.y + 80, { steps: 8 }); await page.mouse.up();
    await page.click('[data-signature-save]'); await settle(page);
}
await page.click('[data-commit-action]'); await settle(page);
check(`a member who never checked in (${member}) is served`, Number(sql(`select count(*) from dispensations where location_id = '${north}'`)) === before + 1);

// 3. Central Branch: as before.
await page.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
await page.click('button[data-counter-sede-current]');
await page.click(`[data-counter-sede="${central}"]`); await settle(page);
const ask = page.locator('[data-counter-sede-switch-go]');
if (await ask.count()) { await ask.click(); await settle(page); }
// Central has no till open yet: the counter asks for one first (236), then the hub.
await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
if (await page.locator('input[wire\\:model="floatInput"]').count()) {
    await page.fill('input[wire\\:model="floatInput"]', '50');
    await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
}
await page.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
check('Central Branch: Recepción and its figures as before, no «Nuevo socio» tile', await page.locator('[data-counter-home-tile="counter.checkin"]').count() === 1
    && await page.locator('[data-panel="presence"]').count() === 1 && await page.locator('[data-counter-home-tile-new-member]').count() === 0);
await page.screenshot({ path: `${OUT}/4-hub-central-1180.png` });

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
