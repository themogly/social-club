// Prompt 328 — Vapeador. Throwaway database (DBFILE, read with sqlite3), as the owner:
//   1. create a vape strain at 1.0 g per unit (Peso por unidad, no THC mg);
//   2. Crear lote: Vapeador → the strain → 20 units;
//   3. the counter: filter by Vapeador, dispense 1 → «Restante hoy» drops by 1.00 g;
//   4. the stock report lists it under Vapeador.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/328';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(700); };
const field = (p, id) => p.locator(`.fi-fo-field:has(label[for="form.${id}"])`);
const pick = async (p, id, text) => {
    const native = field(p, id).locator('select');
    if (await native.count()) { await native.selectOption({ label: text }); await settle(p); return; }
    await field(p, id).locator('.fi-select-input').first().click();
    await p.locator('[role="option"]:visible', { hasText: text }).first().click(); await settle(p);
};

const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
check('sign in', await signIn(page, { account: 'owner' }));

// 1. The strain.
await page.goto(`${BASE}/genetics/create`, { waitUntil: 'networkidle' });
await page.fill('input[id="form.name"]', 'Vape 328'); await page.locator('input[id="form.name"]').blur(); await settle(page);
await pick(page, 'product_type', 'Vapeador');
check('a vape asks «Peso por unidad (g)» and no THC mg', await page.locator('input[id="form.grams_per_unit_g"]').isVisible() && await page.locator('input[id="form.thc_mg_per_unit"]').count() === 0);
await page.fill('input[id="form.grams_per_unit_g"]', '1');
await page.screenshot({ path: `${OUT}/1-vape-strain-1440.png`, fullPage: true });
await page.click('button[type="submit"]:has-text("Crear")'); await settle(page);
check('saved as a unit product of 1.00 g', sql("select product_type || '/' || unit_type || '/' || grams_per_unit_cg from genetics where name = 'Vape 328'") === 'VAPE/UNIT/100');

// 2. Its batch.
await page.goto(`${BASE}/batches/create`, { waitUntil: 'networkidle' });
await field(page, 'location_id').locator('.fi-select-input').first().click();
await page.locator('[role="option"]:visible', { hasText: 'Central Branch' }).first().click(); await page.waitForTimeout(500);
await page.keyboard.press('Escape'); await settle(page);
await pick(page, 'product_type', 'Vapeador');
await pick(page, 'genetic_id', 'Vape 328');
check('the quantity is in units', await page.locator('input[id="form.units"]').isVisible() && await page.locator('input[id="form.grams"]').count() === 0);
await page.fill('input[id="form.units"]', '20');
await page.fill('input[id="form.sale_price_eur"]', '15');
await page.locator('body').click({ position: { x: 5, y: 5 } }); await settle(page);
await page.click('button[type="submit"]:has-text("Crear")'); await settle(page);
const cont = page.locator('.fi-modal-window button:has-text("Continuar")');
if (await cont.count()) { await cont.first().click(); await settle(page); }
check('20 units in stock', sql("select b.remaining_units from batches b join genetics g on g.id = b.genetic_id where g.name = 'Vape 328'") === '20');

// 3. The counter.
const pos = await browser.newPage({ viewport: { width: 1180, height: 820 } });
await signInToCounter(pos, '/counter/till', { account: 'owner', sede: 'Central Branch' });
if (await pos.locator('input[wire\\:model="floatInput"]').count()) {
    await pos.fill('input[wire\\:model="floatInput"]', '50');
    await pos.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(pos);
}
await pos.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
await pos.fill('#member-lookup', 'M-00027'); await pos.press('#member-lookup', 'Enter'); await settle(pos);
await pos.click('[data-member-lookup-result]').catch(() => {}); await settle(pos);
const remaining = async () => Number(((await pos.locator('text=Restante hoy').locator('xpath=..').innerText().catch(() => '')).match(/(\d+\.\d{2}) g/) ?? [])[1] ?? NaN);
const before = await remaining();
await pos.getByRole('button', { name: /Filtros/ }).first().click().catch(() => {}); await pos.waitForTimeout(300);
await pos.locator(`[x-on\\:click="filter('productType', 'VAPE')"]`).first().click(); await pos.waitForTimeout(500);
const shown = (await pos.locator('[data-catalogue-item="genetics"]:visible').allInnerTexts()).map((t) => t.split('\n')[0].trim());
check('the Vapeador filter shows only the vape', shown.length === 1 && /Vape 328/.test(shown[0]), JSON.stringify(shown));
await pos.screenshot({ path: `${OUT}/2-filter-1180.png` });
await pos.keyboard.press('Escape');
await pos.locator('[data-catalogue-item="genetics"]:visible', { hasText: 'Vape 328' }).first().click(); await settle(pos);
await pos.click('[data-add-line]'); await settle(pos);
await pos.locator('[wire\\:click^="quickCash"]').first().click(); await settle(pos);
const pad = pos.locator('[data-signature-canvas]');
if (await pad.count()) {
    const b = await pad.boundingBox();
    await pos.mouse.move(b.x + 20, b.y + 30); await pos.mouse.down();
    await pos.mouse.move(b.x + 120, b.y + 80, { steps: 8 }); await pos.mouse.up();
    await pos.click('[data-signature-save]'); await settle(pos);
}
await pos.click('[data-commit-action]'); await settle(pos);
const after = await remaining();
check('one vape takes 1.00 g off «Restante hoy»', Math.abs((before - after) - 1) < 0.001, `${before} → ${after}`);
await pos.screenshot({ path: `${OUT}/3-dispensed-1180.png` });

// 4. The stock report.
await page.goto(`${BASE}/informes/stock`, { waitUntil: 'networkidle' }); await settle(page);
const report = (await page.locator('main').innerText()).replace(/\s+/g, ' ');
check('the stock report lists it under Vapeador', /Vape 328.{0,80}Vapeador|Vapeador.{0,80}Vape 328/.test(report), (report.match(/.{0,60}Vape 328.{0,60}/) ?? [''])[0]);
await page.screenshot({ path: `${OUT}/4-stock-report-1440.png`, fullPage: true });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
