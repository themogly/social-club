// Prompt 294 — creating an article in the "Todas las sedes" view: an error on Sede (never a crash), then saved at the
// chosen sede, sold at that sede's bar only; with a sede in the top bar the field is pre-filled.
import { chromium } from 'playwright';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/294';
const NAME = `Papers ${Date.now() % 10000}`;
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
check('sign in (PIN opens the panel)', await signInToCounter(page, '/counter'));

async function switchTo(label) {
    await page.goto(`${BASE}/articles/create`, { waitUntil: 'networkidle' });
    const select = page.locator('select[x-on\\:change*="switchTo"]');
    const value = label === null ? '' : await select.locator('option', { hasText: label }).getAttribute('value');
    await select.selectOption(value ?? '');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800);
}

// 1. The rollup: no sede chosen → a field error, not a 500.
await switchTo(null);
check('still on the create page in the rollup', page.url().includes('/articles/create'), page.url());
await page.fill('input[id$="name"]', NAME);
await page.fill('input[id$="price_eur"]', '1');
let serverError = false;
page.on('response', (r) => { if (r.status() >= 500) serverError = true; });
await page.click('button[type="submit"]:has-text("Crear")');
await page.waitForTimeout(1500);
const fieldError = await page.locator('[data-field-wrapper] .fi-fo-field-wrp-error-message, .fi-fo-field-wrp-error-message').allTextContents();
check('no sede → an error on the field, no 500', ! serverError && fieldError.length > 0, JSON.stringify(fieldError));
await page.screenshot({ path: `${OUT}/rollup-error.png`, fullPage: true });

// 2. Choose North Branch → saved there.
const sede = page.locator('.fi-fo-select-wrp', { hasText: 'Sede' }).locator('.fi-fo-select').first();
await sede.click();
await page.locator('[role="option"]', { hasText: 'North Branch' }).first().click();
await page.waitForTimeout(400);
await page.click('button[type="submit"]:has-text("Crear")');
await page.waitForTimeout(2000);
check('saved after choosing North Branch', ! serverError && /\/articles\/[^/]+\/edit|\/articles$/.test(page.url()), page.url());
await page.screenshot({ path: `${OUT}/saved.png`, fullPage: true });

// 3. With Central Branch in the top bar the field is pre-filled.
await switchTo('Central Branch');
const prefilled = (await page.locator('.fi-fo-select-wrp', { hasText: 'Sede' }).first().textContent()) ?? '';
check('Central Branch pre-fills the Sede field', /Central Branch/.test(prefilled), prefilled.replace(/\s+/g, ' ').trim().slice(0, 60));
await page.screenshot({ path: `${OUT}/central-prefilled.png`, fullPage: true });

await browser.close();
console.log(`ARTICLE=${NAME}`);
process.exit(results.every(Boolean) ? 0 : 1);
