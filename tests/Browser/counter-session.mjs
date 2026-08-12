// The counter's sign-in preamble, shared by every browser harness that drives the REAL app.
//
// Extracted by prompt 223 with the line *"Three copies of a login flow is how one of them quietly stops
// matching the app."* — and then wired to ONE consumer of ten, which is this project's most-repeated defect
// (OpensMemberships 203→211, the MRZ partial 179→215, the application field list 210→215, the signature
// canvas →220, and this). Prompt 226 ported the other nine, and
// `tests/Feature/Counter/OneLoginPreambleTest.php` is what keeps consumer number eleven from rolling its own.
//
// Needs a running server and the dev seed:
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DevAdminSeeder
//   npm run build && php artisan serve --port=8123
//
// ENV OVERRIDES, which came in from the harnesses rather than being left behind in them: `AUDIT_*` (the
// audit sweeps) and `DEV_*` (the prove-* scripts) were two names for the same three values. Both are
// honoured, AUDIT first, then DEV, then the dev seed — so an existing invocation of any of the nine keeps
// working exactly as it did.

export const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8123';
export const EMAIL = process.env.AUDIT_EMAIL ?? process.env.DEV_EMAIL ?? 'owner@club.test';
export const PASSWORD = process.env.AUDIT_PASSWORD ?? process.env.DEV_PASSWORD ?? 'password';
export const PIN = process.env.AUDIT_PIN ?? process.env.DEV_PIN ?? '1234';
export const SEDE = process.env.AUDIT_SEDE ?? 'Central Branch';

/**
 * Sign in, and nothing else — for the harnesses that shoot an ADMIN page and never touch the counter.
 *
 * The `waitForURL` is `shoot-lockdown`'s hard-won lesson, moved in rather than left behind: Filament's login
 * is a Livewire form, so the redirect happens CLIENT-side after the response and `networkidle` can resolve
 * while the URL is still `/login`. Waiting on the load state alone reads a perfectly good login as a failure.
 *
 * @returns {Promise<boolean>} false when the session never left `/login`.
 */
export async function signIn(page) {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle', timeout: 20000 });
    await page.fill('input[type="email"]', EMAIL);
    await page.fill('input[type="password"]', PASSWORD);
    await page.press('input[type="password"]', 'Enter');

    return await page.waitForURL((url) => ! url.pathname.startsWith('/login'), { timeout: 20000 })
        .then(() => true)
        .catch(() => false);
}

/**
 * Sign in and clear the counter's blocking chain (sede → PIN), leaving the page on `url`.
 *
 * @param {import('playwright').Page} page
 * @param {string} url                     where to land — the caller's own screen
 * @param {{ sede?: string|null }} options  `sede` names the sede to choose; null takes whichever is first,
 *                                          which is what a single-sede install and most harnesses want
 * @returns {Promise<boolean>} false when the counter never became usable. Callers FAIL on false rather than
 *                             skipping: "the harness could not get in" and "the feature is broken" are
 *                             different findings and must not read the same.
 */
export async function signInToCounter(page, url = '/counter/members', { sede = null } = {}) {
    if (! await signIn(page)) {
        return false;
    }

    await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });

    // The sede switcher, when the operator works in more than one (prompt 03/14).
    const sedeButton = await page.$(sede
        ? `[data-counter-sede-menu] form button:has-text("${sede}")`
        : '[data-counter-sede-menu] form button');
    if (sedeButton) {
        await sedeButton.click();
        await page.waitForLoadState('networkidle');
    }

    // The PIN pad, when the counter surface is up (prompt 173).
    const pad = await page.$('[data-counter-surface-unlock]');
    if (pad) {
        for (const digit of PIN.split('')) {
            await page.click(`[data-counter-surface] button:has-text("${digit}")`).catch(() => {});
        }
        await pad.click();
        await page.waitForTimeout(1200);
    }

    return !! await page.$('[data-counter-topbar]');
}

/** Open the sign-up modal and enter the staff wizard, leaving it on step 1 (prompts 221/223). */
export async function openStaffWizard(page) {
    await page.click('[data-alta-toggle]');
    await page.waitForSelector('[data-alta-modal]');
    await page.click('[data-alta-staff-form]');
    await page.waitForSelector('[data-alta-stepper]');
}
