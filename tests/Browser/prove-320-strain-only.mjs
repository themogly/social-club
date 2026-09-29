// Prompt 320 — creating a strain is just the strain. Throwaway database, as the owner:
//   1. Genéticas → Crear: only the strain's fields (no quantity, sede or price); create "Prueba 320" with its details;
//   2. land on the strains list; the notification's «Crear lote» opens the batch form with the strain chosen;
//   3. a split batch (Central Branch + North Branch, 100 g, 9 €/g);
//   4. the strain is at the Central Branch counter.
import { chromium } from 'playwright';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/320';
const NAME = 'Prueba 320';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(600); };
const field = (p, id) => p.locator(`.fi-fo-field:has(label[for="form.${id}"])`);

// The create form at the tablet width and in dark mode (a look, no action).
for (const [w, h, scheme] of [[820, 1180, 'light'], [1440, 900, 'dark']]) {
    const look = await browser.newPage({ viewport: { width: w, height: h }, colorScheme: scheme });
    await signIn(look, { account: 'owner' });
    if (scheme === 'dark') await look.evaluate(() => localStorage.setItem('theme', 'dark'));
    await look.goto(`${BASE}/genetics/create`, { waitUntil: 'networkidle' }); await settle(look);
    await look.screenshot({ path: `${OUT}/create-${w}x${h}-${scheme}.png`, fullPage: true });
    await look.close();
}

const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
await signIn(page, { account: 'owner' });

// 1. The strain alone.
await page.goto(`${BASE}/genetics/create`, { waitUntil: 'networkidle' });
const batchFields = await page.locator('input[id="form.grams"], input[id="form.units"], [id="form.location_id"], input[id="form.price_per_gram_eur"], input[id="form.cost_per_gram_eur"], input[id="form.batch_no"]').count();
check('the create form has no batch, sede or price field', batchFields === 0, `${batchFields} found`);
check('it is one form, not a wizard', await page.locator('.fi-sc-wizard').count() === 0);
await page.fill('input[id="form.name"]', NAME);
await page.locator('input[id="form.name"]').blur(); await settle(page);
await page.fill('input[id="form.thc_pct"]', '18.5');
await page.click('button[type="submit"]:has-text("Crear")'); await settle(page);

// 2. The list, and the hand-off.
check('it lands on the strains list', /\/genetics(\?|$)/.test(page.url()), page.url());
const note = page.locator('.fi-no-notification', { hasText: /Crear lote/ });
check('the notification points to «Crear lote»', await note.count() > 0);
await page.screenshot({ path: `${OUT}/list-notification-1440.png` });
const rowText = ((await page.locator('.fi-ta-row', { hasText: NAME }).first().textContent()) ?? '').replace(/\s+/g, ' ');
check('the new strain reads «Sin existencias» in the list', /Sin existencias/.test(rowText), rowText.slice(0, 120));
await note.getByRole('link', { name: /Crear lote/ }).first().click(); await settle(page);
check('«Crear lote» opens the batch form with ?genetic=', /\/batches\/create\?genetic=/.test(page.url()), page.url());
const chosen = ((await field(page, 'genetic_id').textContent()) ?? '').replace(/\s+/g, ' ');
check('the strain is already chosen', chosen.includes(NAME), chosen.trim().slice(0, 80));
await page.screenshot({ path: `${OUT}/batch-preselected-1440.png`, fullPage: true });

// 3. A split batch with a price.
for (const sede of ['Central Branch', 'North Branch']) {
    const choice = page.locator('[role="option"]:visible', { hasText: sede }).first();
    if (! await choice.isVisible()) await field(page, 'location_id').locator('.fi-select-input').first().click();
    await choice.click(); await page.waitForTimeout(700);
}
await page.keyboard.press('Escape'); await settle(page);
await page.fill('input[id="form.grams"]', '100');
await page.getByRole('button', { name: 'Repartir a partes iguales' }).click(); await settle(page);
await page.fill('input[id="form.sale_price_eur"]', '9');
await page.locator('body').click({ position: { x: 5, y: 5 } }); await settle(page);
await page.click('button[type="submit"]:has-text("Crear")'); await settle(page);
const cont = page.locator('.fi-modal-window button:has-text("Continuar")');
if (await cont.count()) { await cont.first().click(); await settle(page); }
check('the split batch is created', /\/batches(\?|$)/.test(page.url()), page.url());

// 4. At the counter.
const pos = await browser.newPage({ viewport: { width: 1180, height: 820 } });
await signInToCounter(pos, '/counter/till', { account: 'owner', sede: 'Central Branch' });
if (await pos.locator('input[wire\\:model="floatInput"]').count()) {
    await pos.fill('input[wire\\:model="floatInput"]', '50');
    await pos.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(pos);
}
await pos.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
await pos.fill('#member-lookup', 'M-00027'); await pos.press('#member-lookup', 'Enter'); await settle(pos);
await pos.click('[data-member-lookup-result]').catch(() => {}); await settle(pos);
check('the strain is at the Central Branch counter', await pos.locator('[data-catalogue-item="genetics"]:visible', { hasText: NAME }).count() > 0);
await pos.screenshot({ path: `${OUT}/counter-1180.png` });
check('no page errors', errors.length === 0, errors.slice(0, 2).join(' | '));

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
