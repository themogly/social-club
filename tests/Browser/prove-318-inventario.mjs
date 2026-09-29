// Prompt 318 — Inventario, on a throwaway database (DBFILE = its path, read with sqlite3 to know the true figures, since
// the count is blind). As the owner:
//   1. desktop: *Inventario* → *Nuevo inventario* for the sede;
//   2. tablet 820×1180: count every batch (half the sitting), leave;
//   3. the counter: dispense 5 g from a batch already counted;
//   4. tablet: come back, count the products (one *No contado*), review, give the reason the tolerance asks for, apply;
//   5. the sold batch ends at counted − 5 g (the sale not counted twice); the list and the review on desktop.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/318';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(600); };
const confirmModal = async (page) => {
    // The footer's submit, by its label (a confirmation modal puts «Cancelar» first).
    await page.locator('.fi-modal-window:visible .fi-modal-footer-actions button').filter({ hasNotText: /^\s*(Cancelar|Cancel)\s*$/ }).first().click(); await settle(page);
};

// 1. Start the count (desktop).
const desk = await browser.newPage({ viewport: { width: 1440, height: 900 } });
await signIn(desk, { account: 'owner' });
await desk.goto(`${BASE}/inventario`, { waitUntil: 'networkidle' });
check('the empty list is designed', await desk.locator('[data-count-empty]').isVisible());
await desk.screenshot({ path: `${OUT}/1-list-empty-1440.png` });
await desk.getByRole('button', { name: /Nuevo inventario|New stock count/ }).click(); await settle(desk);
await desk.screenshot({ path: `${OUT}/2-new-modal-1440.png` });
await confirmModal(desk);
const countUrl = desk.url();
check('starting opens the count', /count=/.test(countUrl), countUrl);
const takeId = new URL(countUrl).searchParams.get('count');
const sede = sql(`select l.name from stock_takes t join locations l on l.id = t.location_id where t.id = '${takeId}'`);
const lines = sql(`select id || '|' || countable_type || '|' || countable_id from stock_take_lines where stock_take_id = '${takeId}'`).split('\n').map((r) => r.split('|'));
check('the count has a line per batch in stock and per active product', lines.length > 0, `${lines.length} lines at ${sede}`);

// 2. First sitting on the tablet: every batch.
const tab = await browser.newPage({ viewport: { width: 820, height: 1180 } });
await signIn(tab, { account: 'owner' });
await tab.goto(countUrl, { waitUntil: 'networkidle' });
check('the count is blind (no system figure on screen)', await tab.locator('[data-count-system]').count() === 0);
await tab.screenshot({ path: `${OUT}/3-count-blind-820.png`, fullPage: true });

const truth = (type, id) => type.endsWith('Article')
    ? { unit: true, qty: Number(sql(`select stock from articles where id = '${id}'`)) }
    : (() => { const [cg, units] = sql(`select coalesce(remaining_cg, ''), coalesce(remaining_units, '') from batches where id = '${id}'`).split('|');
        return units !== '' ? { unit: true, qty: Number(units) } : { unit: false, qty: Number(cg) }; })();
const typed = (t, delta = 0) => t.unit ? String(t.qty + delta) : ((t.qty + delta) / 100).toFixed(2);
const counted = {};
const save = async (lineId, value) => {
    await tab.fill(`#entry-${lineId}`, value);
    await tab.locator(`[data-count-line="${lineId}"] form button[type="submit"]`).first().click(); await settle(tab);
};

const batches = lines.filter(([, type]) => type.endsWith('Batch'));
for (const [i, [lineId, type, id]] of batches.entries()) {
    const t = truth(type, id);
    const delta = i === 0 ? (t.unit ? -1 : -100) : i === 1 && ! t.unit ? -Math.round(t.qty * 0.1) : 0; // −1 g; −10 % (needs a reason)
    await save(lineId, typed(t, delta));
    counted[id] = { lineId, unit: t.unit, value: t.qty + delta };
}
const savedFirst = await tab.locator('[data-count-saved]').count();
check('the first sitting saved every batch with who and when', savedFirst === batches.length, `${savedFirst}/${batches.length}`);
await tab.screenshot({ path: `${OUT}/4-first-sitting-820.png`, fullPage: true });

// 3. A 5 g dispensation at the counter, from a batch already counted.
const before = Object.fromEntries(sql(`select id || '|' || coalesce(remaining_cg, remaining_units) from batches where id in (${Object.keys(counted).map((k) => `'${k}'`).join(',')})`).split('\n').map((r) => r.split('|')));
const pos = await browser.newPage({ viewport: { width: 1180, height: 820 } });
await signInToCounter(pos, '/counter/till', { account: 'owner' });
if (await pos.locator('input[wire\\:model="floatInput"]').count()) {
    await pos.fill('input[wire\\:model="floatInput"]', '50');
    await pos.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(pos);
}
await pos.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
await pos.fill('#member-lookup', 'M-00027'); await pos.press('#member-lookup', 'Enter'); await settle(pos);
await pos.click('[data-member-lookup-result]').catch(() => {}); await settle(pos);
await pos.locator('[data-catalogue-item="genetics"]:visible').first().click(); await settle(pos);
const chip = pos.locator('[data-batch-chip]').first();
if (await chip.count()) { await chip.click(); await settle(pos); }
await pos.keyboard.type('5'); await pos.waitForTimeout(300);
await pos.click('[data-add-line]'); await settle(pos);
await pos.locator('[wire\\:click^="quickCash"]').first().click(); await settle(pos);
const pad = pos.locator('[data-signature-canvas]');
if (await pad.count()) {
    const box = await pad.boundingBox();
    await pos.mouse.move(box.x + 20, box.y + 30); await pos.mouse.down();
    await pos.mouse.move(box.x + 120, box.y + 80, { steps: 8 }); await pos.mouse.up();
    await pos.click('[data-signature-save]'); await settle(pos);
}
await pos.click('[data-commit-action]'); await settle(pos);
const override = pos.locator('#override-reason');
if (await override.isVisible().catch(() => false)) { // the socio's daily limit: the owner overrides it, with the reason recorded
    await override.fill('Prueba 318: autorizado por la dirección');
    await pos.getByRole('button', { name: /Autorizar y registrar|Authorise and record/ }).click(); await settle(pos);
}
await pos.screenshot({ path: `${OUT}/counter-sale-1180.png` });
const after = Object.fromEntries(sql(`select id || '|' || coalesce(remaining_cg, remaining_units) from batches where id in (${Object.keys(counted).map((k) => `'${k}'`).join(',')})`).split('\n').map((r) => r.split('|')));
const sold = Object.keys(before).find((id) => Number(before[id]) - Number(after[id]) === 500);
check('5 g were dispensed from a batch already counted', sold !== undefined, sold ?? JSON.stringify({ before, after }));

// 4. Second sitting: the products; one cannot be counted.
await tab.goto(countUrl, { waitUntil: 'networkidle' });
await tab.check('input[wire\\:model\\.live="pendingOnly"]'); await settle(tab);
await tab.screenshot({ path: `${OUT}/5-pending-only-820.png`, fullPage: true });
const products = lines.filter(([, type]) => type.endsWith('Article'));
for (const [i, [lineId, type, id]] of products.entries()) {
    if (i === 0) {
        await tab.locator(`[data-count-line="${lineId}"] button:has-text("No contado"), [data-count-line="${lineId}"] button:has-text("Not counted")`).first().click();
        await tab.fill(`#skip-${lineId}`, 'Caja precintada en el almacén');
        await tab.locator(`[data-count-line="${lineId}"] form`).nth(1).locator('button[type="submit"]').click(); await settle(tab);
        continue;
    }
    await save(lineId, typed(truth(type, id), i === 1 ? -1 : 0));
}
const pending = await tab.locator('[data-count-line]').count();
check('nothing left pending', pending === 0, `${pending} still pending`);

// Review: the −10 % line needs a reason; apply is refused until it has one.
await tab.getByRole('tab', { name: /Revisar|Review/ }).click(); await settle(tab);
await tab.screenshot({ path: `${OUT}/6-review-820.png`, fullPage: true });
const needs = tab.locator('[data-count-needs-reason]');
check('a difference above the tolerance asks for a reason', await needs.count() >= 1, `${await needs.count()} rows`);
await tab.getByRole('button', { name: /Aplicar ajustes|Apply adjustments/ }).click(); await settle(tab);
await confirmModal(tab);
check('applying without the reason is refused', sql(`select status from stock_takes where id = '${takeId}'`) === 'OPEN');
for (const row of await tab.locator('tr[data-count-difference]:has([data-count-needs-reason])').all()) {
    await row.locator('select').selectOption('MERMA'); await settle(tab);
    await row.locator('input[type="text"]').fill('Secado en el bote'); await row.locator('input[type="text"]').blur(); await settle(tab);
}
await tab.screenshot({ path: `${OUT}/7-review-reasons-820.png`, fullPage: true });
await tab.getByRole('button', { name: /Aplicar ajustes|Apply adjustments/ }).click(); await settle(tab);
await tab.screenshot({ path: `${OUT}/7b-apply-modal-820.png` });
await confirmModal(tab);
await tab.screenshot({ path: `${OUT}/7c-after-apply-820.png` });
check('the count is applied', sql(`select status from stock_takes where id = '${takeId}'`) === 'COMMITTED');

// 5. The maths: the sold batch ends at what was counted minus the 5 g sold — not minus 10 g, not back at the count.
const soldNow = Number(sql(`select coalesce(remaining_cg, remaining_units) from batches where id = '${sold}'`));
check('the sale between counting and applying is not counted twice', soldNow === counted[sold].value - 500, `now ${soldNow}, counted ${counted[sold].value}`);
const adjustments = Number(sql(`select count(*) from stock_movements where stock_take_id = '${takeId}' and type = 'ADJUSTMENT'`));
check('one adjustment per line with a difference, with its reason', adjustments >= 2 && sql(`select count(*) from stock_movements where stock_take_id = '${takeId}' and reason like '%Merma%'`) !== '0', `${adjustments} adjustments`);
const skipped = products[0];
check('the not-counted product was not touched', sql(`select count(*) from stock_movements where stock_take_id = '${takeId}' and stockable_id = '${skipped[2]}'`) === '0');
await tab.screenshot({ path: `${OUT}/8-applied-820.png`, fullPage: true });

await desk.goto(`${BASE}/inventario`, { waitUntil: 'networkidle' });
check('the list shows the applied count with its net difference', await desk.locator('[data-count-row="COMMITTED"]').count() === 1);
await desk.screenshot({ path: `${OUT}/9-list-1440.png` });
await desk.goto(`${countUrl}&mode=review`, { waitUntil: 'networkidle' });
await desk.screenshot({ path: `${OUT}/10-review-1440.png`, fullPage: true });
const [download] = await Promise.all([desk.waitForEvent('download'), desk.getByRole('button', { name: /Informe \(PDF\)|Report \(PDF\)/ }).click()]);
check('the PDF report downloads', /\.pdf$/.test(download.suggestedFilename()), download.suggestedFilename());
await download.saveAs(`${OUT}/inventario.pdf`);
// Dark: Filament's own theme switch (localStorage) and a reload, then the count screen on the tablet too.
for (const [p, name] of [[desk, '11-review-1440-dark'], [tab, '12-review-820-dark']]) {
    await p.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
    await p.evaluate(() => localStorage.setItem('theme', 'dark'));
    await p.goto(`${countUrl}&mode=review`, { waitUntil: 'networkidle' }); await settle(p);
    await p.screenshot({ path: `${OUT}/${name}.png`, fullPage: true });
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
