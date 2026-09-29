// Prompt 305 — tomorrow, acted out. Real app, throwaway database: "Amnesia" split on day 1 at Storage house 800 g and
// Central Branch 200 g (303). In the browser: *Añadir existencias en otra sede* → North Branch 150 g; *Recuento* on the
// Central part, weighed at 196,5 g → the difference reads −3,50 g and the part becomes 196,50 g; *Partes del lote* lists
// all three. One width per run (ONLY=1440x900|820x1180) on a fresh database copy.
import { chromium } from 'playwright';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/305';
const [w, h] = (process.env.ONLY ?? '1440x900').split('x').map(Number);
const tag = `${w}x${h}`;
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${tag}: ${name} ${detail}`); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: w, height: h } });
page.setDefaultTimeout(10000);
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(600); };

check('sign in', await signIn(page, { account: 'owner' }));
await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
const sw = page.locator('select[x-on\\:change*="switchTo"]');
await sw.selectOption(await sw.locator('option', { hasText: 'Storage house' }).getAttribute('value')); await settle();
await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
const row = () => page.locator('.fi-ta-row').filter({ hasText: 'Recuento día 1' }).first(); // the day-1 lote (not the seeded Amnesia Haze)
const menu = async (item) => {
    await row().locator('button[title="Abrir acciones"], .fi-icon-btn:visible').last().click();
    await page.locator('.fi-dropdown-list-item:visible', { hasText: item }).first().click(); await settle();
};

// 2. Añadir existencias en otra sede → North Branch 150 g.
await menu('Añadir existencias en otra sede');
const modal = page.locator('.fi-modal-window').last();
await page.screenshot({ path: `${OUT}/${tag}-add-parts-open.png` });
const sedes = modal.locator('.fi-fo-field', { hasText: 'Sedes' });
let options;
await sedes.locator('.fi-select-input').first().click(); await page.waitForTimeout(400);
options = (await page.locator('[role="option"]:visible').allTextContents()).map((t) => t.trim());
await page.locator('[role="option"]:visible', { hasText: 'North Branch' }).first().click();
await modal.locator('h2, .fi-modal-heading').first().click(); // closes the dropdown (Escape would close the modal)
check('offers only the sedes without a part', options.includes('North Branch') && ! options.includes('Central Branch') && ! options.includes('Storage house'), JSON.stringify(options));
await settle();
await modal.locator('input[id*="grams_at"]').first().fill('150');
await page.screenshot({ path: `${OUT}/${tag}-add-parts.png` });
await modal.getByRole('button', { name: 'Añadir' }).click(); await settle();
check('notified where it was added', await page.getByText('Añadido a North Branch').isVisible().catch(() => false));

// 3. Recuento on the Central part: weighed 196,5 g.
await sw.selectOption(await sw.locator('option', { hasText: 'Central Branch' }).getAttribute('value')); await settle();
await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
await menu('Recuento');
const rec = page.locator('.fi-modal-window').last();
check('shows the system figure', /En sistema: 200,00 g/.test((await rec.textContent()) ?? ''));
await rec.locator('input[id$="counted"]').fill('196.5');
await rec.locator('input[id$="counted"]').blur(); await settle();
const diff = (await rec.locator('.fi-in-entry, .fi-fo-field', { hasText: 'Diferencia' }).last().textContent()) ?? '';
check('the difference reads −3,50 g', /−3,50 g/.test(diff), diff.replace(/\s+/g, ' ').trim());
await page.screenshot({ path: `${OUT}/${tag}-recount.png` });
await rec.getByRole('button', { name: 'Guardar recuento' }).click(); await settle();
check('the part now reads 196,50 g', /196[.,]50 g/.test((await row().textContent()) ?? ''), ((await row().textContent()) ?? '').replace(/\s+/g, ' ').slice(0, 90));

// 4. Partes del lote on the batch page.
await row().locator('a[href*="/edit"]').first().click(); await settle();
const parts = (await page.locator('[data-lote-part]').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim());
check('Partes del lote lists all three', parts.length === 3 && ['Storage house', 'Central Branch', 'North Branch'].every((s) => parts.some((p) => p.includes(s))), JSON.stringify(parts));
await page.screenshot({ path: `${OUT}/${tag}-page.png`, fullPage: true });
check('no page errors', errors.length === 0, errors.slice(0, 2).join(' | '));

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
