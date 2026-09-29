// Prompt 310 — the owner's "403 on Reponer", acted out on a throwaway database. One browser, two tabs: *Productos* in one,
// the counter in the other. Someone else's PIN on the counter makes the session theirs (a PIN session, unconfirmed); back
// on *Productos*, *Reponer* lands on *Confirma tu identidad* — not Livewire's "403" box — and nothing was restocked;
// confirming comes back to *Productos*, and *Reponer* then works.
import { chromium } from 'playwright';
import { accountPassword, BASE, enterPin, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/310';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1180, height: 820 } });
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(700); };

// 1. The owner on the counter (their own PIN after the password: not a PIN session), and Productos in another tab.
const counter = await context.newPage();
check('owner at the counter', await signInToCounter(counter, '/counter/till', { account: 'owner' }));
const panel = await context.newPage();
const errors = [];
panel.on('pageerror', (e) => errors.push(e.message));
await panel.goto(`${BASE}/articles`, { waitUntil: 'networkidle' });
check('Productos opens', panel.url().endsWith('/articles'), panel.url());
const row = panel.locator('.fi-ta-row').first();
const name = ((await row.locator('.fi-ta-text').first().textContent()) ?? '').trim();
const stockOf = async () => ((await panel.locator('.fi-ta-row', { hasText: name }).first().textContent()) ?? '').replace(/\s+/g, ' ');
const before = await stockOf();

// 2. Someone else's PIN on the counter tab: the session becomes the manager's, unconfirmed.
await counter.bringToFront();
await counter.locator('[data-counter-switch-operator]').first().click().catch(async () => { await counter.locator('[data-counter-lock]').first().click(); });
await settle(counter);
await enterPin(counter, { account: 'manager' });
await settle(counter);

// 3. Back on Productos: Reponer.
await panel.bringToFront();
const reponer = async () => {
    await panel.locator('.fi-ta-row', { hasText: name }).first().locator('button, a').filter({ hasText: 'Reponer' }).first().click();
    await settle(panel);
};
await reponer();
const modal = panel.locator('.fi-modal-window:visible');
if (await modal.count()) {
    await modal.locator('input').first().fill('4');
    await modal.getByRole('button', { name: /Enviar|Reponer|Confirmar|Guardar/ }).last().click();
    await settle(panel);
}
check('the press lands on Confirma tu identidad, not an error box', /confirm/i.test(panel.url()) && await panel.getByText('Confirma tu identidad').first().isVisible(), panel.url());
check('no Livewire error box', (await panel.locator('#livewire-error').count()) === 0);
await panel.screenshot({ path: `${OUT}/confirm.png` });

// 4. Confirm → back on Productos; nothing was restocked by the refused press; Reponer now works.
await panel.fill('input[type="password"]', accountPassword('manager'));
await panel.getByRole('button', { name: 'Confirmar' }).click();
await settle(panel);
check('confirming comes back to Productos', panel.url().endsWith('/articles'), panel.url());
check('the refused press restocked nothing', (await stockOf()) === before, (await stockOf()).slice(0, 80));
await reponer();
await panel.locator('.fi-modal-window:visible input').first().fill('4');
await panel.locator('.fi-modal-window:visible').getByRole('button', { name: /Enviar|Reponer|Confirmar|Guardar/ }).last().click();
await settle(panel);
check('Reponer works after confirming', await panel.getByText('Stock repuesto').first().isVisible().catch(() => false));
await panel.screenshot({ path: `${OUT}/restocked.png` });
check('no page errors', errors.length === 0, errors.slice(0, 2).join(' | '));

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
