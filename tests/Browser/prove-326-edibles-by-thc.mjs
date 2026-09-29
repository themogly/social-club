// Prompt 326 — edibles by THC (mg); the grams they count as are worked out. Throwaway database (DBFILE, read with sqlite3):
//   1. as the owner, create an edible at 10 mg → "Cuenta como 0.07 g por unidad", no grams input;
//   2. create a pre-roll at 1 g ("Peso por unidad (g)");
//   3. stock the edible (Crear lote) and dispense 2 at the counter → "Restante hoy" drops by 0.14 g;
//   4. change the equivalence to 100 → the edible now counts as 0.10 g.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/326';
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

// 1. An edible at 10 mg.
await page.goto(`${BASE}/genetics/create`, { waitUntil: 'networkidle' });
await page.fill('input[id="form.name"]', 'Gominola 326'); await page.locator('input[id="form.name"]').blur(); await settle(page);
await pick(page, 'product_type', 'Comestible');
check('an edible asks no grams', await page.locator('input[id="form.grams_per_unit_g"]').count() === 0);
await page.fill('input[id="form.thc_mg_per_unit"]', '10'); await page.waitForTimeout(900); await settle(page);
const countsAs = (await page.locator('[data-edible-counts-as]').innerText().catch(() => '')).trim();
check('it reads «Cuenta como 0.07 g por unidad»', /Cuenta como 0\.07 g por unidad/.test(countsAs), countsAs);
await page.screenshot({ path: `${OUT}/1-edible-10mg-1440.png`, fullPage: true });
await page.click('button[type="submit"]:has-text("Crear")'); await settle(page);
check('saved as 10 mg and 7 cg', sql("select thc_mg_per_unit || '/' || grams_per_unit_cg from genetics where name = 'Gominola 326'") === '10/7');

// 2. A pre-roll at 1 g.
await page.goto(`${BASE}/genetics/create`, { waitUntil: 'networkidle' });
await page.fill('input[id="form.name"]', 'Porro 326'); await page.locator('input[id="form.name"]').blur(); await settle(page);
await pick(page, 'product_type', 'Preliado');
check('a pre-roll asks «Peso por unidad (g)» and no THC mg', /Peso por unidad \(g\)/.test(await page.locator('main').innerText()) && await page.locator('input[id="form.thc_mg_per_unit"]').count() === 0);
await page.fill('input[id="form.grams_per_unit_g"]', '1');
await page.screenshot({ path: `${OUT}/2-preroll-1440.png`, fullPage: true });
await page.click('button[type="submit"]:has-text("Crear")'); await settle(page);
check('the pre-roll saved 1 g', sql("select grams_per_unit_cg from genetics where name = 'Porro 326'") === '100');

// 3. Stock it and dispense two.
await page.goto(`${BASE}/batches/create`, { waitUntil: 'networkidle' });
for (const sede of ['Central Branch']) {
    await field(page, 'location_id').locator('.fi-select-input').first().click();
    await page.locator('[role="option"]:visible', { hasText: sede }).first().click(); await page.waitForTimeout(500);
}
await page.keyboard.press('Escape'); await settle(page);
await pick(page, 'product_type', 'Comestible');
await pick(page, 'genetic_id', 'Gominola 326');
await page.fill('input[id="form.units"]', '20');
await page.fill('input[id="form.sale_price_eur"]', '3');
await page.locator('body').click({ position: { x: 5, y: 5 } }); await settle(page);
await page.click('button[type="submit"]:has-text("Crear")'); await settle(page);
const cont = page.locator('.fi-modal-window button:has-text("Continuar")');
if (await cont.count()) { await cont.first().click(); await settle(page); }
check('the edible has a batch', sql("select count(*) from batches b join genetics g on g.id = b.genetic_id where g.name = 'Gominola 326'") === '1');

const pos = await browser.newPage({ viewport: { width: 1180, height: 820 } });
await signInToCounter(pos, '/counter/till', { account: 'owner', sede: 'Central Branch' });
if (await pos.locator('input[wire\\:model="floatInput"]').count()) {
    await pos.fill('input[wire\\:model="floatInput"]', '50');
    await pos.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(pos);
}
await pos.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
await pos.fill('#member-lookup', 'M-00027'); await pos.press('#member-lookup', 'Enter'); await settle(pos);
await pos.click('[data-member-lookup-result]').catch(() => {}); await settle(pos);
const remaining = async () => {
    const text = (await pos.locator('text=Restante hoy').locator('xpath=..').innerText().catch(() => '')).replace(/\s+/g, ' ');
    return Number((text.match(/(\d+\.\d{2}) g/) ?? [])[1] ?? NaN);
};
const before = await remaining();
const card = pos.locator('[data-catalogue-item="genetics"]:visible', { hasText: 'Gominola 326' }).first();
check('the counter reads the edible by mg', /10 mg THC/.test(await card.innerText()), (await card.innerText()).replace(/\s+/g, ' ').slice(0, 100));
await card.click(); await settle(pos);
await pos.click('button[wire\\:click="stepUnits(1)"]'); await settle(pos); // a unit product: the stepper starts at 1 → 2
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
const after = await remaining();
check('two edibles take 0.14 g off «Restante hoy»', Math.abs((before - after) - 0.14) < 0.001, `${before} → ${after}`);
check('the line stored 14 cg', sql("select l.grams_cg from dispensation_lines l join genetics g on g.id = l.genetic_id where g.name = 'Gominola 326'") === '14');
await pos.screenshot({ path: `${OUT}/3-counter-1180.png` });

// 4. The equivalence at 100.
await page.goto(`${BASE}/manage-settings`, { waitUntil: 'networkidle' });
const setting = page.locator('input[id="form.edible_thc_mg_per_gram"], input[id="data.edible_thc_mg_per_gram"]').first();
check('the setting shows its default, 150', await setting.inputValue() === '150', await setting.inputValue());
await setting.fill('100');
await page.getByRole('button', { name: /Guardar/ }).first().click(); await settle(page);
check('the edible now counts as 0.10 g', sql("select grams_per_unit_cg from genetics where name = 'Gominola 326'") === '10');
check('the past dispensation kept 14 cg', sql("select l.grams_cg from dispensation_lines l join genetics g on g.id = l.genetic_id where g.name = 'Gominola 326'") === '14');
const id = sql("select id from genetics where name = 'Gominola 326'");
await page.goto(`${BASE}/genetics/${id}/edit`, { waitUntil: 'networkidle' }); await settle(page);
const now = (await page.locator('[data-edible-counts-as]').innerText().catch(() => '')).trim();
check('its form reads «Cuenta como 0.10 g por unidad»', /0\.10 g/.test(now), now);
await page.screenshot({ path: `${OUT}/4-after-100-1440.png`, fullPage: true });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
