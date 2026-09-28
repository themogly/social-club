// Prompt 297 — a product's sede can be corrected until it has history, and a product can be created at any choice of
// sedes at once. Real app, throwaway database with three sedes (Central, North, South Branch):
// "Papers" at Central by mistake → edited to North; one sale → the sede is locked with its reason; "Mechero" at two of the
// three with its own stock each; "Papel" at *Todas las sedes*; Mechero's price changed with one other sede ticked.
// SELL_URL (optional): a script run between steps to sell one Papers; the harness prints `SELL Papers` and waits for it.
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/297';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const [w, h] = (process.env.VIEWPORT ?? '1440x900').split('x').map(Number);
const scheme = process.env.SCHEME ?? 'light';
const tag = `${w}x${h}-${scheme}`;
// Names the seed does not use (it has "Rolling papers"); a row is matched on its exact name.
const PAPERS = 'Papers 297';
const MECHERO = 'Mechero 297';
const PAPEL = 'Papel 297';
const row = (name) => page.locator('.fi-ta-row').filter({ has: page.getByText(name, { exact: true }) });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: w, height: h }, colorScheme: scheme });
check('sign in', await signInToCounter(page, '/counter'));

async function sede(label) {
    await page.goto(`${BASE}/articles`, { waitUntil: 'networkidle' });
    const select = page.locator('select[x-on\\:change*="switchTo"]');
    const value = label === null ? '' : await select.locator('option', { hasText: label }).getAttribute('value');
    await select.selectOption(value ?? '');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800);
}

async function pick(fieldLabel, ...options) {
    const field = page.locator('.fi-fo-field', { hasText: fieldLabel }).locator('.fi-fo-select, .fi-select-input').first();
    for (const option of options) {
        // A multi-select stays open after a pick; clicking the field again would close it.
        const choice = page.locator('[role="option"]:visible', { hasText: option }).first();
        if (! await choice.isVisible()) await field.click();
        await choice.click();
        await page.waitForTimeout(700);
    }
    await page.keyboard.press('Escape');
}

async function listed(name) {
    await page.goto(`${BASE}/articles`, { waitUntil: 'networkidle' });
    await page.locator('.fi-ta-search-field input').fill(name); // the seed's list runs to several pages
    await page.waitForTimeout(1500);
    return await row(name).count();
}

async function create(name, price, sedes, stock = {}) {
    await page.goto(`${BASE}/articles/create`, { waitUntil: 'networkidle' });
    await pick('Sedes', ...sedes);
    await page.fill('input[id="form.name"]', name);
    await page.fill('input[id="form.price_eur"]', price);
    for (const [sedeName, units] of Object.entries(stock)) {
        await page.locator('.fi-fo-field', { hasText: `Existencias en ${sedeName}` }).locator('input').fill(String(units));
    }
    await page.screenshot({ path: `${OUT}/${tag}-create-${name}.png`, fullPage: true });
    await page.click('button[type="submit"]:has-text("Crear")');
    await page.waitForURL((u) => u.pathname === '/articles', { timeout: 10000 }).catch(() => {});
}

// 1. Papers at Central by mistake, then moved to North.
await sede(null);
await create(PAPERS, '1', ['Central Branch']);
await sede('Central Branch');
await row(PAPERS).locator('a:has-text("Editar"), button:has-text("Editar")').first().click();
await page.waitForLoadState('networkidle');
await pick('Sede', 'North Branch');
await page.screenshot({ path: `${OUT}/${tag}-papers-move.png`, fullPage: true });
await page.click('button[type="submit"]:has-text("Guardar")');
await page.waitForTimeout(2000);
await sede('North Branch');
check('Papers is at North now', await listed(PAPERS) === 1);
await sede('Central Branch');
check('…and not at Central', await listed(PAPERS) === 0);

// 2. One sale → locked, with the reason.
if (process.env.SELL_CMD) execSync(process.env.SELL_CMD, { stdio: 'inherit' });
await sede('North Branch');
await row(PAPERS).locator('a:has-text("Editar"), button:has-text("Editar")').first().click();
await page.waitForLoadState('networkidle');
const reason = await page.locator('text=Ya tiene ventas o movimientos de stock en North Branch').count();
check('after a sale the sede is locked with its reason', reason > 0);
await page.screenshot({ path: `${OUT}/${tag}-papers-locked.png`, fullPage: true });

// 3. Mechero at two of three, its own stock at each.
await sede(null);
await create(MECHERO, '1.50', ['Central Branch', 'South Branch'], { 'Central Branch': 10, 'South Branch': 4 });
for (const [s, n] of [['Central Branch', 1], ['South Branch', 1], ['North Branch', 0]]) {
    await sede(s);
    check(`Mechero at ${s}: ${n}`, await listed(MECHERO) === n);
}

// 4. Papel at Todas las sedes.
await sede(null);
await create(PAPEL, '0.50', ['Todas las sedes']);
check('Papel at all three', await listed(PAPEL) === 3);

// 5. Mechero's price at Central, also applied at South.
await sede('Central Branch');
await row(MECHERO).locator('a:has-text("Editar"), button:has-text("Editar")').first().click();
await page.waitForLoadState('networkidle');
await page.fill('input[id="form.price_eur"]', '2');
await page.locator('.fi-fo-field', { hasText: 'Aplicar los cambios también en' }).locator('label', { hasText: 'South Branch' }).click();
await page.screenshot({ path: `${OUT}/${tag}-mechero-apply.png`, fullPage: true });
await page.click('button[type="submit"]:has-text("Guardar")');
await page.waitForTimeout(2000);
await sede('South Branch');
const southRow = (await row(MECHERO).first().textContent()) ?? '';
check('South got the new price and kept its own stock', /2,00/.test(southRow) && /\b4\b/.test(southRow), southRow.replace(/\s+/g, ' ').trim());
await page.screenshot({ path: `${OUT}/${tag}-list-south.png`, fullPage: true });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
