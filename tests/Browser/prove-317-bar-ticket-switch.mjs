// Prompt 317 — the bar ticket is off by default, per sede. Throwaway database, 1180×820:
//   1. a bar sale → *Opciones* has no *Ver / imprimir ticket*, and the ticket's URL answers 404;
//   2. *Sedes → Barra → Ofrecer ticket de barra* switched on for the sede → a bar sale → the ticket is back.
import { chromium } from 'playwright';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/317';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(700); };

await signInToCounter(page, '/counter/till', { account: 'owner' });
if (await page.locator('input[wire\\:model="floatInput"]').count()) {
    await page.fill('input[wire\\:model="floatInput"]', '50');
    await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
}
const sell = async () => {
    await page.goto(`${BASE}/counter/bar`, { waitUntil: 'networkidle' });
    await page.locator('[data-catalogue-item="bar"]:visible').first().click(); await settle(page);
    await page.locator('[wire\\:click^="quickCash"]').first().click(); await settle(page);
    await page.click('[data-commit-action]'); await settle(page);
    await page.locator('[data-last-sale-options]').click(); await page.waitForTimeout(300);
    return (await page.locator('[role="menu"]:visible').innerText().catch(() => '')).replace(/\s+/g, ' ');
};

let menu = await sell();
check('off by default: Opciones has no ticket', ! menu.includes('Ver / imprimir ticket') && menu.includes('Anular'), menu);
await page.screenshot({ path: `${OUT}/off.png` });
const order = await page.evaluate(() => window.Livewire.all().map((c) => c.$wire.lastOrderId).find(Boolean));
const response = await page.goto(`${BASE}/counter/bar/receipt/${order}`);
check('off: the ticket URL answers 404', response.status() === 404, String(response.status()));

// The owner switches it on for the sede.
const panel = await browser.newPage({ viewport: { width: 1440, height: 900 } });
await signIn(panel, { account: 'owner' });
await panel.goto(`${BASE}/locations`, { waitUntil: 'networkidle' });
await panel.locator('.fi-ta-row', { hasText: 'Central Branch' }).first().locator('a[href*="/edit"]').first().click(); await settle(panel);
const toggle = panel.locator('.fi-fo-field:has-text("Ofrecer ticket de barra") button[role="switch"]').first();
check('Sedes → Barra offers the switch, off', (await toggle.getAttribute('aria-checked')) === 'false');
await toggle.click(); await panel.waitForTimeout(300);
await panel.screenshot({ path: `${OUT}/sede-switch.png` });
await panel.getByRole('button', { name: 'Guardar cambios' }).click(); await settle(panel);

menu = await sell();
check('on: the ticket is back in Opciones', menu.includes('Ver / imprimir ticket'), menu);
await page.screenshot({ path: `${OUT}/on.png` });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
