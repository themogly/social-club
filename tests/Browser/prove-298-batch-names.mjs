// Prompt 298 — batches say what they are. Real app, throwaway database (the demo seed, whose batches now come through
// IntakeBatch), manual lote selection switched on beforehand:
// Añadir stock twice for Amnesia Haze (no number, then "GROW-17") → the list reads strain / "#n · entrada …", the lote
// numbers only with their column on; the batch page shows the lote number with a copy button; the recall names it; at the
// counter the lote chips read "#n · dd mmm" and never a lote number; the closing recount lists strain · description.
import { chromium } from 'playwright';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/298';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const scheme = process.env.SCHEME ?? 'light';
const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: scheme });
const page = await context.newPage();
page.setDefaultTimeout(15000);
const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };
const LOTE = /[A-Z]{3}-\d{6}-\d+|GROW-17/;

check('sign in', await signInToCounter(page, '/counter', { sede: 'Central Branch' }));

// 1. Añadir stock, twice.
async function addStock(ownNumber) {
    await page.goto(`${BASE}/batches/create`, { waitUntil: 'networkidle' });
    const genetic = page.locator('.fi-fo-field:has(label[for="form.genetic_id"])').locator('.fi-select-input, .fi-fo-select').first();
    await genetic.click();
    await page.locator('[role="option"]:visible', { hasText: 'Amnesia Haze' }).first().click();
    await settle();
    const sede = page.locator('.fi-fo-field:has(label[for="form.location_id"])');
    await sede.locator('.fi-select-input, .fi-fo-select').first().click(); // its hidden option list holds every name: always pick
    await page.locator('[role="option"]:visible', { hasText: 'Central Branch' }).first().click();
    await settle();
    await page.fill('input[id="form.grams"]', '250');
    await page.fill('input[id="form.sale_price_eur"]', '9');
    if (ownNumber) await page.fill('input[id="form.batch_no"]', ownNumber);
    await page.screenshot({ path: `${OUT}/${scheme}-create${ownNumber ? '-own' : ''}.png`, fullPage: true });
    await page.click('button[type="submit"]:has-text("Crear")');
    await settle();
    const confirm = page.locator('.fi-modal-window button:has-text("Confirmar"), .fi-modal-window button:has-text("Guardar")');
    if (await confirm.count()) { await confirm.first().click(); await settle(); }
}
await addStock(null);
await addStock('GROW-17');

// 2. The list.
await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
await page.locator('.fi-ta-search-field input').fill('Amnesia');
await page.waitForTimeout(1500); // the search is debounced
await settle();
const rows = (await page.locator('.fi-ta-row').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim());
check('the list leads with the strain and a description', rows.length >= 4 && rows.every((r) => /Amnesia Haze/.test(r) && /#\d+ · entrada/.test(r)), rows[0]?.slice(0, 80));
check('…with no lote number while its column is off', rows.every((r) => ! LOTE.test(r)));
await page.screenshot({ path: `${OUT}/${scheme}-list.png`, fullPage: true });

await page.locator('[title="Alternar columnas"]').first().click();
await page.locator('label:has-text("Nº lote") input[type="checkbox"]:visible').first().check();
const apply = page.locator('button:visible', { hasText: 'Aplicar' });
if (await apply.count()) await apply.first().click(); // Filament defers column changes until "Aplicar"
await settle();
await page.keyboard.press('Escape');
const withNumbers = (await page.locator('.fi-ta-row').allTextContents()).join(' ');
check('switching the column on shows the lote numbers', /GROW-17/.test(withNumbers) && /AMN-\d{6}-\d+/.test(withNumbers));
await page.screenshot({ path: `${OUT}/${scheme}-list-numbers.png`, fullPage: true });

// 3. The batch's own page.
await page.locator('.fi-ta-row', { hasText: 'GROW-17' }).locator('a:has-text("Editar"), a[href*="/edit"]').first().click();
await settle();
check('the batch page shows its lote number', await page.locator('.fi-in-entry, .fi-fo-field', { hasText: 'Nº de lote' }).filter({ hasText: 'GROW-17' }).count() > 0);
await page.screenshot({ path: `${OUT}/${scheme}-batch-page.png`, fullPage: true });

// 4. The recall names the lote number.
await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
await page.locator('.fi-ta-search-field input').fill('GROW-17');
await page.waitForTimeout(1500);
await settle();
await page.locator('.fi-ta-row').first().locator('button[title="Abrir acciones"], .fi-icon-btn:visible').last().click();
await page.locator('.fi-dropdown-list-item:visible', { hasText: 'Retirada' }).first().click();
await settle();
check('the recall names the lote number', /GROW-17/.test((await page.locator('.fi-modal-window').first().textContent()) ?? ''));
await page.screenshot({ path: `${OUT}/${scheme}-recall.png` });
await page.keyboard.press('Escape');

// 5. The counter: chips, then a sale so the batch is touched, then the closing recount.
await page.setViewportSize({ width: 1180, height: 820 });
await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
if (page.url().includes('/counter/till')) {
    await page.fill('input[wire\\:model="floatInput"]', '50');
    await page.click('form[wire\\:submit="open"] button[type="submit"]');
    await settle();
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
}
await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle();
await page.click('[data-member-lookup-result]'); await settle();
const fee = await page.$('form[wire\\:submit="collectMemberFee"] button[type="submit"]');
if (fee) { await fee.click(); await settle(); }
await page.locator('[data-catalogue-item="genetics"]:visible', { hasText: 'Amnesia Haze' }).first().click(); await settle();
const chips = (await page.locator('[data-batch-chip]').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim());
check('the lote chips read "#n · dd mmm"', chips.length > 0 && chips.every((c) => /#\d+ · \d{1,2} \w+/.test(c)), chips.join(' | '));
check('…and never a lote number', chips.every((c) => ! LOTE.test(c)));
await page.screenshot({ path: `${OUT}/${scheme}-chips.png` });

await page.locator('[data-weight-pad] button', { hasText: /^1$/ }).click();
await page.click('[data-add-line]'); await settle();
await page.locator('[wire\\:click^="quickCash"]').first().click(); await settle();
const pad = page.locator('[data-signature-canvas]');
if (await pad.count()) {
    const box = await pad.boundingBox();
    await page.mouse.move(box.x + 20, box.y + 30); await page.mouse.down();
    await page.mouse.move(box.x + 120, box.y + 80, { steps: 8 }); await page.mouse.up();
    await page.click('[data-signature-save]'); await settle();
}
await page.click('[data-commit-action]'); await settle();

await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
await page.click('[wire\\:click="startClose"]'); await settle();
const recount = (await page.locator('[data-reweigh-batch] label').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim());
check('the recount lists strain · description', recount.length > 0 && recount.every((r) => / · (#\d+ · entrada|.+)/.test(r)), recount.slice(0, 2).join(' | '));
check('…and never a lote number', recount.every((r) => ! LOTE.test(r)));
await page.screenshot({ path: `${OUT}/${scheme}-recount.png`, fullPage: true });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
