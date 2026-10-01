// Prompt 339 — in the INSTALLED app, Back can never walk out of the counter. 313's guard skipped its listener whenever a
// page loaded on its own guard entry (every reload, every Back into an earlier page), so those pages let Back through, and
// the walk reached the first entry — Android's exit, which app pinning turns into the splash loop. Standalone is emulated
// by overriding matchMedia (Playwright has no display-mode); each tab starts on about:blank, which stands for the exit.
// Throwaway database, the owner at 1180×820.
//
// Honesty note: Chrome's real Back button also skips history entries that a page pushed before any tap (the "history
// manipulation intervention"); history.back() — all a test can press — does not. So these prove the page's own logic;
// the pinned tablet is the only proof of the whole thing (verification/real-device-checks.md, 339).
import { chromium } from 'playwright';
import { BASE, signInToCounter } from './counter-session.mjs';

const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1180, height: 820 } });
const first = await context.newPage();
check('owner at the counter', await signInToCounter(first, '/counter/till', { account: 'owner' }));
if (await first.locator('input[wire\\:model="floatInput"]').count()) {
    await first.fill('input[wire\\:model="floatInput"]', '50');
    await first.click('form[wire\\:submit="open"] button[type="submit"]'); await first.waitForTimeout(1500);
}

const STANDALONE = () => {
    const real = window.matchMedia.bind(window);
    window.matchMedia = (query) => (/display-mode:\s*(standalone|fullscreen)/.test(query)
        ? { matches: true, media: query, onchange: null, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {}, dispatchEvent: () => false }
        : real(query));
};
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };
async function open(url, standalone) {
    const page = await context.newPage();
    if (standalone) await page.addInitScript(STANDALONE);
    await page.goto('about:blank');
    await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
    return page;
}
const back = async (page, times = 1) => {
    for (let i = 0; i < times; i++) { await page.evaluate(() => history.back()).catch(() => {}); await page.waitForTimeout(300); }
    await settle(page);
};
const onCounter = (page) => page.url().startsWith(`${BASE}/counter`);

// 1. Walking back through four full page loads never reaches the exit.
{
    const page = await open('/counter', true);
    for (const to of ['/counter/pos', '/counter/bar', '/counter/till']) {
        await page.evaluate((u) => location.assign(u), `${BASE}${to}`); await settle(page);
    }
    await back(page, 10);
    check('hub → Dispensario → Barra → Caja, then Back ×10: still on a counter screen, never the exit', onCounter(page), page.url());

    // Back INTO an earlier counter page (it loads on its guard entry — 313's early return), then keep pressing.
    await page.evaluate(() => history.go(-3)).catch(() => {}); await settle(page);
    await back(page, 10);
    check('after landing on an earlier screen\'s entry, Back ×10 still never reaches the exit', onCounter(page), page.url());
    await page.close();
}

// 2. After a reload, Back keeps the page.
{
    const page = await open('/counter/pos', true);
    await page.reload({ waitUntil: 'networkidle' }); await settle(page);
    await back(page, 5);
    check('after a reload, Back ×5 keeps the screen', page.url() === `${BASE}/counter/pos`, page.url());
    await page.close();
}

// 3. Overlays: Back closes them, and the next Back is swallowed.
{
    const page = await open('/counter/members', true);
    const modal = page.locator('[data-alta-modal]');
    await page.click('[data-alta-toggle]'); await page.waitForTimeout(700);
    const opened = await modal.isVisible();
    await back(page);
    const closed = ! await modal.isVisible();
    await back(page, 3);
    check('the sign-up modal: Back closes it, then Back stays', opened && closed && page.url() === `${BASE}/counter/members`, `${opened}/${closed} ${page.url()}`);
    await page.close();

    const hours = await open('/counter/checkin', true);
    await hours.evaluate(() => window.Livewire.dispatch('counter-my-hours')); await hours.waitForTimeout(900);
    const dialog = hours.locator('[data-my-hours]');
    const shown = await dialog.isVisible();
    await back(hours);
    const gone = ! await dialog.isVisible().catch(() => false);
    await back(hours, 3);
    check('«Mis horas»: Back closes it, then Back stays', shown && gone && hours.url() === `${BASE}/counter/checkin`, `${shown}/${gone}`);
    await hours.close();
}

// 4. A normal browser tab: nothing is guarded (pin).
{
    const page = await open('/counter/pos', false);
    const length = await page.evaluate(() => history.length);
    await back(page);
    check('a normal tab: history untouched and Back leaves', length === 2 && page.url() === 'about:blank', `length=${length} ${page.url()}`);
    await page.close();
}

// 5. The head script guards the launch before app.js: with app.js blocked, the entry is still root + guard.
{
    const page = await context.newPage();
    await page.addInitScript(STANDALONE);
    await page.route(/\/build\/assets\/app-.*\.js$/, (route) => route.abort());
    await page.goto('about:blank');
    await page.goto(`${BASE}/counter`, { waitUntil: 'domcontentloaded' });
    const state = await page.evaluate(() => ({ state: history.state, length: history.length }));
    check('the <head> script sets the root and a guard before app.js (app.js blocked)', !! state.state?.cscGuard && state.length >= 3, JSON.stringify(state));
    await page.close();
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
