// The counter's sign-in preamble, shared by the browser harnesses that drive the REAL app (prompt 223).
//
// Extracted from `measure-close-guard.mjs` (222) when a second harness needed the same thirty lines: log in,
// choose the sede, clear the PIN pad. Three copies of a login flow is how one of them quietly stops matching
// the app.
//
// Needs a running server and the dev seed:
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DevAdminSeeder
//   npm run build && php artisan serve --port=8123

export const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8123';

const EMAIL = 'owner@club.test';
const PASSWORD = 'password';
const PIN = ['1', '2', '3', '4'];

/**
 * Sign in and clear the counter's blocking chain, leaving the page on `url`.
 *
 * @returns {Promise<boolean>} false when the counter never became usable — the caller reports it, because
 *                             "the harness could not get in" and "the feature is broken" are different
 *                             findings and must not read the same.
 */
export async function signInToCounter(page, url = '/counter/members') {
    await page.goto(`${BASE}/login`);
    await page.fill('input[type="email"]', EMAIL);
    await page.fill('input[type="password"]', PASSWORD);
    await page.press('input[type="password"]', 'Enter');
    await page.waitForLoadState('networkidle');

    await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });

    const sede = await page.$('[data-counter-sede-menu] form button');
    if (sede) { await sede.click(); await page.waitForLoadState('networkidle'); }

    const pad = await page.$('[data-counter-surface-unlock]');
    if (pad) {
        for (const d of PIN) await page.click(`[data-counter-surface] button:has-text("${d}")`).catch(() => {});
        await pad.click();
        await page.waitForTimeout(1200);
    }

    return !! await page.$('[data-counter-topbar]');
}

/** Open the sign-up modal and enter the staff wizard, leaving it on step 1. */
export async function openStaffWizard(page) {
    await page.click('[data-alta-toggle]');
    await page.waitForSelector('[data-alta-modal]');
    await page.click('[data-alta-staff-form]');
    await page.waitForSelector('[data-alta-stepper]');
}
