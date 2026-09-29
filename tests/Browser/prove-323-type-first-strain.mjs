// Prompt 323 — Crear lote: the product type first, then the strain. Throwaway database with "Cali" (Flor, Híbrida, THC 22 %)
// and "Cali Hash" (Hachís) added, as the owner:
//   1. Lotes → Crear: Genética is disabled with «Elige primero el tipo»; choose Flor, type "cali" → only flower strains,
//      read "Cali · Híbrida · THC 22%";
//   2. choose it, switch to Hachís → the strain clears;
//   3. a new strain's «Crear lote» (320) opens with the type AND the strain filled;
//   plus 820×1180 and dark screenshots.
import { chromium } from 'playwright';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/323';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(600); };
const field = (p, id) => p.locator(`.fi-fo-field:has(label[for="form.${id}"])`);
const pick = async (p, id, text) => {
    const native = field(p, id).locator('select');
    if (await native.count()) { await native.selectOption({ label: text }); await settle(p); return; }
    await field(p, id).locator('.fi-select-input').first().click();
    await p.locator('[role="option"]:visible', { hasText: text }).first().click(); await settle(p);
};
const chosen = async (p, id) => ((await field(p, id).locator('.fi-select-input-value-label, select option:checked').first().textContent().catch(() => '')) ?? '').trim();

const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
check('sign in', await signIn(page, { account: 'owner' }));

// 1. Type first.
await page.goto(`${BASE}/batches/create`, { waitUntil: 'networkidle' });
const strain = field(page, 'genetic_id');
const disabled = await strain.evaluate((el) => !! el.querySelector('[disabled], .fi-disabled, [aria-disabled="true"]'));
check('Genética is disabled before a type is chosen', disabled);
check('it says «Elige primero el tipo»', /Elige primero el tipo/.test(await strain.innerText()), (await strain.innerText()).replace(/\s+/g, ' ').slice(0, 80));
await page.screenshot({ path: `${OUT}/1-type-first-1440.png`, fullPage: true });
await pick(page, 'product_type', 'Flor');
await strain.locator('.fi-select-input').first().click();
await page.keyboard.type('cali'); await page.waitForTimeout(1200);
const offered = (await page.locator('[role="option"]:visible').allInnerTexts()).map((t) => t.trim()).filter(Boolean);
check('only flower strains are offered, with variety and THC', offered.length === 1 && offered[0] === 'Cali · Híbrida · THC 22%', JSON.stringify(offered));
await page.screenshot({ path: `${OUT}/2-flor-cali-1440.png`, fullPage: true });
await page.locator('[role="option"]:visible', { hasText: 'Cali · Híbrida' }).first().click(); await settle(page);
check('the strain is chosen', /Cali · Híbrida · THC 22%/.test(await chosen(page, 'genetic_id')), await chosen(page, 'genetic_id'));

// 2. Switching the type clears it.
await pick(page, 'product_type', 'Hachís');
const after = await chosen(page, 'genetic_id');
check('switching to Hachís clears the flower strain', ! /Cali · Híbrida/.test(after), after || '(empty)');
await page.screenshot({ path: `${OUT}/3-hachis-cleared-1440.png`, fullPage: true });

// 3. The 320 hand-off.
await page.goto(`${BASE}/genetics/create`, { waitUntil: 'networkidle' });
await page.fill('input[id="form.name"]', 'Cali Nueva');
await page.locator('input[id="form.name"]').blur(); await settle(page);
await pick(page, 'product_type', 'Hachís');
await page.click('button[type="submit"]:has-text("Crear")'); await settle(page);
await page.locator('.fi-no-notification').getByRole('link', { name: /Crear lote/ }).first().click(); await settle(page);
const type = await chosen(page, 'product_type');
const handed = await chosen(page, 'genetic_id');
check('«Crear lote» from a new strain fills the type and the strain', /Hachís/.test(type) && /Cali Nueva/.test(handed), `${type} / ${handed}`);
await page.screenshot({ path: `${OUT}/4-handoff-1440.png`, fullPage: true });

// Looks: the tablet width and dark.
await page.setViewportSize({ width: 820, height: 1180 });
await page.goto(`${BASE}/batches/create`, { waitUntil: 'networkidle' }); await settle(page);
await pick(page, 'product_type', 'Flor');
await page.screenshot({ path: `${OUT}/5-create-820.png`, fullPage: true });
await page.evaluate(() => localStorage.setItem('theme', 'dark'));
await page.setViewportSize({ width: 1440, height: 900 });
await page.goto(`${BASE}/batches/create`, { waitUntil: 'networkidle' }); await settle(page);
await page.screenshot({ path: `${OUT}/6-create-1440-dark.png`, fullPage: true });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
