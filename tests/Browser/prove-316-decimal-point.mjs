// Prompt 316 — a decimal point everywhere, in Spanish (the owner's language), 1180×820, throwaway database:
// the batch list; a counter visit (the keypad's key, a typed comma, the preview, the basket, the tender, the commit and
// the last-sale line); the till close's arqueo; and the Registro de dispensación page. No figure may read "12,50".
import { chromium } from 'playwright';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/316';
const COMMA = /\d,\d{1,2}\s?(g|€|%|h)\b|\d,\d{2}(?!\d)/;
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(700); };
const figures = async (page, selector = 'main, body') => (await page.locator(selector).first().innerText()).replace(/\s+/g, ' ');

// The batch list.
const panel = await browser.newPage({ viewport: { width: 1440, height: 900 } });
await signIn(panel, { account: 'owner' });
await panel.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
let text = await figures(panel);
check('the batch list uses a point', /\d\.\d{2} g/.test(text) && /\d\.\d{2} €/.test(text) && ! COMMA.test(text), (text.match(COMMA) ?? [''])[0]);
await panel.screenshot({ path: `${OUT}/batches.png` });

// A counter visit.
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
await signInToCounter(page, '/counter/till', { account: 'owner' });
if (await page.locator('input[wire\\:model="floatInput"]').count()) {
    const prefill = await page.inputValue('input[wire\\:model="floatInput"]');
    check('the till float is prefilled with a point', ! /,/.test(prefill), prefill);
    await page.fill('input[wire\\:model="floatInput"]', '50');
    await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
}
await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
await page.fill('#member-lookup', 'M-00027'); await page.press('#member-lookup', 'Enter'); await settle(page);
await page.click('[data-member-lookup-result]').catch(() => {}); await settle(page);
await page.locator('[data-catalogue-item="genetics"]:visible').first().click(); await settle(page);
const chip = page.locator('[data-batch-chip]').first();
if (await chip.count()) { await chip.click(); await settle(page); }
const keys = (await page.locator('[data-weight-pad] button').allTextContents()).map((k) => k.trim());
check('the keypad\'s decimal key is a point', keys.includes('.') && ! keys.includes(','), JSON.stringify(keys));
await page.keyboard.type('1,5'); await page.waitForTimeout(300); // a Spanish keyboard's comma is read as the same key
const preview = await figures(page, '[data-entry-preview]');
check('a typed comma becomes 1.50 g in the preview', /1\.50 g/.test(preview) && ! COMMA.test(preview), preview.slice(0, 80));
await page.click('[data-add-line]'); await settle(page);
text = await figures(page);
check('the basket and the tender use a point', /\d\.\d{2}\s?€/.test(text) && ! COMMA.test(text), (text.match(COMMA) ?? [''])[0]);
await page.screenshot({ path: `${OUT}/basket.png` });
await page.locator('[wire\\:click^="quickCash"]').first().click(); await settle(page);
const pad = page.locator('[data-signature-canvas]');
if (await pad.count()) {
    const box = await pad.boundingBox();
    await page.mouse.move(box.x + 20, box.y + 30); await page.mouse.down();
    await page.mouse.move(box.x + 120, box.y + 80, { steps: 8 }); await page.mouse.up();
    await page.click('[data-signature-save]'); await settle(page);
}
await page.click('[data-commit-action]'); await settle(page);
const last = await figures(page, '[data-last-sale]').catch(() => '');
check('the last-sale line uses a point', /\d\.\d{2}\s?€ · 1\.50 g/.test(last) && ! COMMA.test(last), last.slice(0, 80));
await page.screenshot({ path: `${OUT}/last-sale.png` });

// The till close's arqueo (the on-screen Z figures).
await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
await page.click('[data-close-till]').catch(() => {}); await settle(page);
const reweigh = page.locator('[data-reweigh-not-counted-toggle]');
if (await reweigh.count()) {
    for (let i = 0; i < await reweigh.count(); i++) { await reweigh.nth(i).click().catch(() => {}); }
    for (const reason of await page.locator('[data-reweigh-reason]').all()) { await reason.fill('Prueba 316').catch(() => {}); }
    await page.locator('button:has-text("Confirmar recuento"), button:has-text("Guardar recuento")').first().click().catch(() => {}); await settle(page);
}
if (await page.locator('input[wire\\:model="countInput"]').count()) {
    await page.fill('input[wire\\:model="countInput"]', '65');
    await page.click('form[wire\\:submit="submitCount"] button[type="submit"]'); await settle(page);
    text = await figures(page);
    check('the arqueo uses a point', /\d\.\d{2}\s?€/.test(text) && ! COMMA.test(text), (text.match(COMMA) ?? [''])[0]);
    await page.screenshot({ path: `${OUT}/arqueo.png`, fullPage: true });
} else {
    console.log('SKIP arqueo: the close asked for the flower recount first');
}

// The registro de dispensación.
await panel.goto(`${BASE}/documentos/registro-dispensacion`, { waitUntil: 'networkidle' });
text = await figures(panel);
check('the registro de dispensación uses a point', /\d\.\d{2}/.test(text) && ! COMMA.test(text), (text.match(COMMA) ?? [''])[0]);
await panel.screenshot({ path: `${OUT}/registro.png`, fullPage: true });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
