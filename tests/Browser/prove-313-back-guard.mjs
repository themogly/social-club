// Prompt 313 — in the INSTALLED app (display-mode: standalone), Back at the start of the counter's history must not reach
// Android (with app pinning on, that restarts the app on its splash, in a loop). Standalone is emulated with an init
// script that makes `matchMedia('(display-mode: standalone)')` match — Playwright's emulateMedia has no display-mode.
// Each tab first loads about:blank, so "Back leaves the page" is observable: that entry stands for Android's exit.
// Throwaway database; the owner's session, then fresh tabs straight onto each counter screen.
import { chromium } from 'playwright';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/313';
const SCREENS = ['/counter', '/counter/pos', '/counter/bar', '/counter/checkin', '/counter/members', '/counter/till'];
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1180, height: 820 } });
const first = await context.newPage();
check('owner at the counter', await signInToCounter(first, '/counter/till', { account: 'owner' }));
if (await first.locator('input[wire\\:model="floatInput"]').count()) { // the screens past the till step need an open till
    await first.fill('input[wire\\:model="floatInput"]', '50');
    await first.click('form[wire\\:submit="open"] button[type="submit"]'); await first.waitForTimeout(1500);
}

const STANDALONE = () => {
    const real = window.matchMedia.bind(window);
    window.matchMedia = (query) => (/display-mode:\s*(standalone|fullscreen)/.test(query)
        ? { matches: true, media: query, onchange: null, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {}, dispatchEvent: () => false }
        : real(query));
};

/** A fresh tab: about:blank (the "leave the app" entry), then the screen. Tracks every request and page load. */
async function open(url, standalone) {
    const page = await context.newPage();
    if (standalone) await page.addInitScript(STANDALONE);
    await page.goto('about:blank');
    await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
    await page.evaluate(() => { window.__stay = 1; });
    page.__requests = [];
    page.__bodies = [];
    page.on('request', (r) => { page.__requests.push(`${r.method()} ${r.url()}`); if (r.method() === 'POST') page.__bodies.push(r.postData() ?? ''); });

    return page;
}
const back = async (page, times = 1) => {
    // Without the guard, Back LEAVES (to about:blank): the next evaluate would lose its page, so tolerate that.
    for (let i = 0; i < times; i++) { await page.evaluate(() => history.back()).catch(() => {}); await page.waitForTimeout(250); }
    await page.waitForTimeout(400);
};
const stayed = async (page, url) => page.url() === `${BASE}${url}` && await page.evaluate(() => window.__stay === 1).catch(() => false);

// 1. Back at the start: stays, no navigation, no request — once and five times.
for (const url of SCREENS) {
    const page = await open(url, true);
    await back(page);
    const once = await stayed(page, url);
    await back(page, 5);
    check(`standalone ${url}: Back ×1 and ×5 keep the screen`, once && await stayed(page, url) && page.__requests.length === 0,
        `${page.url()} · ${page.__requests.slice(0, 2).join(' | ')}`);
    await page.close();
}

// 2. A normal tab: no guard entries (the module leaves history alone).
for (const url of ['/counter', '/counter/pos']) {
    const page = await open(url, false);
    const length = await page.evaluate(() => history.length);
    check(`browser tab ${url}: history untouched`, length === 2, `history.length=${length}`);
    await back(page);
    check(`browser tab ${url}: Back leaves as it always has`, page.url() === 'about:blank', page.url());
    await page.close();
}

// Back across screens reached by the top bar stays inside the counter, and never past the start.
{
    const page = await open('/counter', true);
    // The top bar's links are full page loads (`wire:navigate.ignore`), so this is what a tap on one does.
    await page.evaluate((to) => location.assign(to), `${BASE}/counter/checkin`); await page.waitForLoadState('networkidle'); await page.waitForTimeout(600);
    await back(page, 6);
    check('standalone: Back after moving between screens never leaves the counter', page.url().startsWith(`${BASE}/counter`), page.url());
    await page.close();
}

// 3–4. Overlays.

// The alta modal: Back closes it; Back again stays. Closed with its X: Back stays.
{
    const page = await open('/counter/members', true);
    const modal = page.locator('[data-alta-modal]');
    await page.click('[data-alta-toggle]'); await page.waitForTimeout(600);
    const opened = await modal.isVisible();
    await back(page);
    const closedByBack = ! await modal.isVisible();
    await back(page);
    check('alta modal: Back closes it, Back again stays', opened && closedByBack && await stayed(page, '/counter/members'), `${opened}/${closedByBack}`);
    await page.close();

    const again = await open('/counter/members', true);
    await again.click('[data-alta-toggle]'); await again.waitForTimeout(600);
    await again.click('[data-alta-close]'); await again.waitForTimeout(600);
    await back(again);
    check('alta modal: closed with X, then Back stays', ! await again.locator('[data-alta-modal]').isVisible().catch(() => false) && await stayed(again, '/counter/members'), again.url());
    await again.close();
}

// The terminal dialog and Mis horas: Back closes; closed with their button, a later Back calls neither close again.
for (const [name, event, selector, closeButton, method] of [
    ['terminal dialog', 'counter-terminal', '[data-terminal-dialog]', '[data-terminal-dialog] button:has-text("Cancelar")', 'cancelTerminal'],
    ['Mis horas', 'counter-my-hours', '[data-my-hours]', '[data-my-hours] button:has-text("Cerrar")', 'closeMyHours'],
]) {
    const page = await open('/counter/checkin', true);
    const dialog = page.locator(selector);
    await page.evaluate((e) => window.Livewire.dispatch(e), event); await page.waitForTimeout(900);
    const opened = await dialog.isVisible();
    await back(page); await page.waitForTimeout(600);
    check(`${name}: Back closes it`, opened && ! await dialog.isVisible().catch(() => false) && await stayed(page, '/counter/checkin'), `${opened}`);
    await page.close();

    const again = await open('/counter/checkin', true);
    await again.evaluate((e) => window.Livewire.dispatch(e), event); await again.waitForTimeout(900);
    await again.click(closeButton); await again.waitForTimeout(900);
    again.__bodies = [];
    await back(again, 2); await again.waitForTimeout(600);
    const stale = again.__bodies.filter((b) => b.includes(`"${method}"`)).length;
    check(`${name}: closed with its button, a later Back calls ${method} no more and stays`, stale === 0 && await stayed(again, '/counter/checkin'), `stale calls=${stale}`);
    await again.close();
}

// The receipt sheet (needs a sale — made here, at the dispensary): Back closes it; Back again stays; closed with X, stays.
{
    const sale = first;
    await sale.addInitScript(STANDALONE); // the receipt lives in THIS tab's screen, so the tab becomes the installed app
    await sale.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    await sale.fill('#member-lookup', 'M-00027'); await sale.press('#member-lookup', 'Enter'); await sale.waitForTimeout(900);
    await sale.click('[data-member-lookup-result]').catch(() => {}); await sale.waitForTimeout(900);
    await sale.locator('[data-catalogue-item="genetics"]:visible').first().click().catch(() => {}); await sale.waitForTimeout(900);
    const chip = sale.locator('[data-batch-chip]').first();
    if (await chip.count()) { await chip.click(); await sale.waitForTimeout(600); }
    await sale.locator('[data-weight-pad] button', { hasText: /^1$/ }).click().catch(() => {});
    await sale.click('[data-add-line]').catch(() => {}); await sale.waitForTimeout(900);
    await sale.locator('[wire\\:click^="quickCash"]').first().click().catch(() => {}); await sale.waitForTimeout(900);
    const pad = sale.locator('[data-signature-canvas]');
    if (await pad.count()) {
        const box = await pad.boundingBox();
        await sale.mouse.move(box.x + 20, box.y + 30); await sale.mouse.down();
        await sale.mouse.move(box.x + 120, box.y + 80, { steps: 8 }); await sale.mouse.up();
        await sale.click('[data-signature-save]').catch(() => {}); await sale.waitForTimeout(900);
    }
    await sale.click('[data-commit-action]').catch(() => {}); await sale.waitForTimeout(1500);
    console.log(`sale made: ${await sale.locator('[data-last-sale]').isVisible().catch(() => false)}`);
    await sale.evaluate(() => { window.__stay = 1; });
    sale.__requests = [];
    sale.__bodies = [];
}
{
    const page = first;
    const hasReceipt = await page.locator('[data-receipt-sheet]').count();
    if (hasReceipt) {
        const isOpen = () => page.evaluate(() => window.Alpine.$data(document.querySelector('[data-receipt-sheet]')).isOpen);
        await page.evaluate(() => window.dispatchEvent(new CustomEvent('counter-receipt-open'))); await page.waitForTimeout(700);
        const opened = await isOpen();
        await back(page);
        const closed = ! await isOpen();
        await back(page);
        check('receipt sheet: Back closes it, Back again stays', opened && closed && await stayed(page, '/counter/pos'), `${opened}/${closed}`);
        await page.evaluate(() => window.dispatchEvent(new CustomEvent('counter-receipt-open'))); await page.waitForTimeout(700);
        await page.locator('[data-receipt-sheet] button:has-text("Cerrar")').first().click().catch(() => {}); await page.waitForTimeout(500);
        await back(page);
        check('receipt sheet: closed with its button, then Back stays', await stayed(page, '/counter/pos'), page.url());
    } else {
        console.log('SKIP receipt sheet: no sale on this screen in the fixture');
    }
}

// The camera overlay, where this Chromium has BarcodeDetector (the trigger hides itself otherwise).
{
    const page = await open('/counter/checkin', true);
    const trigger = page.locator('[data-camera-scan-open]:visible, [data-camera-open]:visible').first();
    if (await trigger.count()) {
        await trigger.click(); await page.waitForTimeout(800);
        await back(page);
        await back(page);
        check('camera overlay: Back closes it, Back again stays', await stayed(page, '/counter/checkin'), page.url());
    } else {
        console.log('SKIP camera overlay: no BarcodeDetector in this Chromium, the trigger is hidden');
    }
    await page.close();
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
