// Prompt 289 — a registered counter opens on the PIN pad, never on a password. Real app, throwaway database:
// register as the manager; clear ONLY the session cookie → the PIN pad; a staff PIN, a bar basket, the idle lock → the
// basket survives; revoke from the panel as the owner → the tablet falls back to the login.
import { chromium } from 'playwright';
import { BASE, DEV_ACCOUNTS, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/289';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const [w, h] = (process.env.VIEWPORT ?? '1180x820').split('x').map(Number);

async function pin(page, digits) {
    await page.waitForSelector('[data-counter-surface-unlock]', { state: 'visible' });
    for (const d of digits) await page.click(`[data-counter-surface] [data-pin-pad] button:has-text("${d}")`);
    await page.click('[data-counter-surface-unlock]');
    await page.waitForTimeout(1500);
    const skip = await page.$('[data-clock-skip]');
    if (skip && await skip.isVisible()) { await skip.click(); await page.waitForTimeout(700); }
}

const browser = await chromium.launch();
const tablet = await browser.newContext({ viewport: { width: w, height: h } });
const page = await tablet.newPage();

// 1. Register as the manager (password once, then PIN).
check('manager signs in', await signInToCounter(page, '/counter/till', { account: 'manager' }));
if (! await page.isVisible('[data-counter-terminal]')) {
    await page.click('[data-counter-switch-operator]').catch(() => {});
    await pin(page, DEV_ACCOUNTS.manager.pin);
}
await page.click('[data-counter-terminal]');
await page.waitForSelector('[data-terminal-dialog]');
await page.fill('#terminal-name', 'Tablet barra');
await page.selectOption('#terminal-sede', { label: 'Central Branch' });
await page.fill('#terminal-pin', DEV_ACCOUNTS.manager.pin);
await page.screenshot({ path: `${OUT}/${w}x${h}-register.png` });
await page.click('[data-terminal-register]');
await page.waitForTimeout(1500);
const cookies = await tablet.cookies();
check('the terminal cookie is set, HttpOnly', cookies.some((c) => c.name === 'csc_terminal' && c.httpOnly), cookies.map((c) => c.name).join(','));

// 2. Clear the SESSION cookie only (a new day) → the PIN pad, never the password form.
const sessionCookie = cookies.find((c) => c.name.endsWith('-session'));
await tablet.clearCookies({ name: sessionCookie.name });
await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
check('a new day opens on the counter', new URL(page.url()).pathname.startsWith('/counter'), page.url());
check('…on the PIN pad', await page.isVisible('[data-counter-surface-unlock]'));
check('…with the password way in one tap away', await page.isVisible('[data-terminal-password-login]'));
await page.screenshot({ path: `${OUT}/${w}x${h}-new-day.png` });

// 3. Staff PIN, a bar basket, the idle lock → the basket survives.
await page.goto(`${BASE}/counter/bar`, { waitUntil: 'networkidle' });
await pin(page, DEV_ACCOUNTS.staff.pin);
await page.locator('[data-product]').first().click();
await page.waitForTimeout(1200);
const linesBefore = await page.locator('[wire\\:click^="incrementLine"]').count();
await page.evaluate(() => window.Alpine.store('counter').lockNow());
await page.waitForTimeout(1500);
check('the idle lock shows the pad', await page.isVisible('[data-counter-surface-unlock]'));
await pin(page, DEV_ACCOUNTS.staff.pin);
const linesAfter = await page.locator('[wire\\:click^="incrementLine"]').count();
check('the basket survived the lock', linesBefore > 0 && linesAfter === linesBefore, `${linesBefore} → ${linesAfter}`);

// 4. Revoke from the panel as the owner → the tablet falls back to the login.
const office = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const panel = await office.newPage();
check('the owner signs in to the panel', await signIn(panel, { account: 'owner' }));
await panel.goto(`${BASE}/mostradores-registrados`, { waitUntil: 'networkidle' });
check('the panel lists the tablet', await panel.isVisible('text=Tablet barra'));
await panel.screenshot({ path: `${OUT}/panel-list.png` });
await panel.locator('[data-counter-terminal-row] button:has-text("Revocar")').first().click();
await panel.locator('.fi-modal-window button:has-text("Confirmar"), .fi-modal-window button[type="submit"]').first().click();
await panel.waitForTimeout(1500);

await page.evaluate(() => window.Alpine.store('counter').lockNow());
await page.waitForTimeout(1000);
await tablet.clearCookies({ name: sessionCookie.name });
await page.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
check('the revoked tablet lands on the login', new URL(page.url()).pathname.startsWith('/login'), page.url());

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
