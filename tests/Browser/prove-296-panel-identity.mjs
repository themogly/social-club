// Post-296 audit fix 3 — a registered tablet plus a PIN opens the panel only after the password, once a shift; revoking
// the tablet signs out whoever is on it. Real app, throwaway database:
// register as the manager; clear ONLY the session cookie (a new day) → PIN → `/` asks "Confirma tu identidad"; a wrong
// password is refused; the right one opens the panel; revoke from the owner's browser → the tablet is signed out.
import { chromium } from 'playwright';
import { BASE, DEV_ACCOUNTS, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/296-fix3';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const [w, h] = (process.env.VIEWPORT ?? '1180x820').split('x').map(Number);
const scheme = process.env.SCHEME ?? 'light';

async function pin(page, digits) {
    await page.waitForSelector('[data-counter-surface-unlock]', { state: 'visible' });
    for (const d of digits) await page.click(`[data-counter-surface] [data-pin-pad] button:has-text("${d}")`);
    await page.click('[data-counter-surface-unlock]');
    await page.waitForTimeout(1500);
    const skip = await page.$('[data-clock-skip]');
    if (skip && await skip.isVisible()) { await skip.click(); await page.waitForTimeout(700); }
}

const browser = await chromium.launch();
const tablet = await browser.newContext({ viewport: { width: w, height: h }, colorScheme: scheme });
const page = await tablet.newPage();

// 1. Register the tablet as the manager.
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
await page.click('[data-terminal-register]');
await page.waitForTimeout(1500);

// 2. A new day: the PIN, then the panel asks for the password.
const sessionCookie = (await tablet.cookies()).find((c) => c.name.endsWith('-session'));
await tablet.clearCookies({ name: sessionCookie.name });
await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
await pin(page, DEV_ACCOUNTS.manager.pin);
await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
check('the panel asks the PIN session for its password', new URL(page.url()).pathname === '/confirmar-identidad', page.url());
await page.screenshot({ path: `${OUT}/${w}x${h}-${scheme}-confirm.png` });

await page.fill('input[type="password"]', 'no-es-la-clave');
await page.press('input[type="password"]', 'Enter');
await page.waitForTimeout(1500);
check('a wrong password is refused', new URL(page.url()).pathname === '/confirmar-identidad' && await page.isVisible('.fi-fo-field-wrp-error-message, [data-validation-error]'));
await page.screenshot({ path: `${OUT}/${w}x${h}-${scheme}-wrong.png` });

await page.fill('input[type="password"]', DEV_ACCOUNTS.manager.password);
await page.press('input[type="password"]', 'Enter');
await page.waitForURL((url) => url.pathname !== '/confirmar-identidad', { timeout: 10000 }).catch(() => {});
check('the right password opens the panel', new URL(page.url()).pathname === '/', page.url());

// 3. Revoke from the owner's browser → the tablet is signed out on its next request, no lock needed.
const office = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const panel = await office.newPage();
check('the owner signs in to the panel', await signIn(panel, { account: 'owner' }));
await panel.goto(`${BASE}/mostradores-registrados`, { waitUntil: 'networkidle' });
await panel.locator('[data-counter-terminal-row] button:has-text("Revocar")').first().click();
await panel.locator('.fi-modal-window button:has-text("Confirmar"), .fi-modal-window button[type="submit"]').first().click();
await panel.waitForTimeout(1500);

await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
check('the revoked tablet is signed out', new URL(page.url()).pathname.startsWith('/login'), page.url());

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
