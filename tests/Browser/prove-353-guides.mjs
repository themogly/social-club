// Prompt 353 — *Guías*. A site seeded with `csc:seed-staging` (DBFILE), accounts in English.
//   1. Tablet 1180×820, staff at the counter: ⋯ → Guías lists the three staff guides; open the cash guide, tap an image
//      (it opens full size; Back closes it and stays on the guide), download its PDF.
//   2. A phone (390×844) that is not signed in: /docs asks for the PIN on the counter's pad; a staff PIN shows only the
//      staff guides; /counter on that phone is not reachable. A manager's PIN shows all four. Light and dark.
//   3. Five wrong PINs lock the phone out: the right PIN is then refused with the same message.
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';
import { BASE, accountPin, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/353';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(400); };
const listed = (p) => p.locator('[data-guide-link]').evaluateAll((els) => els.map((e) => e.dataset.guideLink));
const STAFF = ['counter-quick-start', 'cash-at-the-counter', 'clocking-in-and-out'];

// --- 1. The tablet ---------------------------------------------------------------------------------------------------------
const tablet = await (await browser.newContext({ viewport: { width: 1180, height: 820 }, acceptDownloads: true })).newPage();
tablet.on('pageerror', (e) => errors.push(e.message));
await signInToCounter(tablet, '/counter', { account: 'staff', sede: 'Central Branch' });
await tablet.click('[data-counter-more]');
await tablet.click('[data-counter-guides]'); await settle(tablet);
check('tablet: ⋯ → Guías lists the three staff guides, not the manager guide', JSON.stringify(await listed(tablet)) === JSON.stringify(STAFF), JSON.stringify(await listed(tablet)));
await tablet.screenshot({ path: `${OUT}/1-counter-index-1180.png` });

await tablet.click('[data-guide-link="cash-at-the-counter"]'); await settle(tablet);
check('tablet: the cash guide shows its contents, a table and its images', await tablet.locator('[data-guide-toc]').isVisible()
    && await tablet.locator('[data-guide-body] table').count() > 0 && await tablet.locator('[data-guide-image]').count() === 8);
await tablet.screenshot({ path: `${OUT}/2-counter-guide-1180.png` });
const guideUrl = tablet.url();
await tablet.locator('[data-guide-image]').first().scrollIntoViewIfNeeded();
await tablet.locator('[data-guide-image]').first().click(); await tablet.waitForTimeout(300);
const zoomed = await tablet.locator('[data-guide-zoom]').isVisible();
await tablet.screenshot({ path: `${OUT}/3-image-zoom-1180.png` });
await tablet.goBack(); await tablet.waitForTimeout(400);
check('tablet: an image opens full size, and Back closes it without leaving the guide', zoomed && ! await tablet.locator('[data-guide-zoom]').isVisible() && tablet.url() === guideUrl);
const [download] = await Promise.all([tablet.waitForEvent('download'), tablet.click('[data-guide-pdf]')]);
const pdf = readFileSync(await download.path());
check('tablet: «Download PDF» saves the guide as a PDF, and the page stays put', pdf.subarray(0, 4).toString() === '%PDF' && tablet.url() === guideUrl,
    `${download.suggestedFilename()} ${Math.round(pdf.length / 1024)} KB`);

// --- 2. A phone that is not signed in --------------------------------------------------------------------------------------
async function phone(scheme) {
    const page = await (await browser.newContext({ viewport: { width: 390, height: 844 }, colorScheme: scheme, isMobile: true, hasTouch: true })).newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    return page;
}
async function typePin(page, pin) {
    for (const d of pin) await page.locator('[data-docs-pin] [data-pin-pad] button', { hasText: new RegExp(`^${d}$`) }).click();
    await page.click('[data-docs-submit]'); await settle(page);
}

for (const scheme of ['light', 'dark']) {
    const p = await phone(scheme);
    await p.goto(`${BASE}/docs`, { waitUntil: 'networkidle' });
    check(`phone (${scheme}): /docs asks for the PIN on the counter's pad`, await p.locator('[data-docs-pin] [data-pin-pad]').isVisible());
    await p.screenshot({ path: `${OUT}/4-docs-pin-390-${scheme}.png` });
    await typePin(p, accountPin('staff'));
    check(`phone (${scheme}): a staff PIN shows only the three staff guides, in their own language`, JSON.stringify(await listed(p)) === JSON.stringify(STAFF)
        && await p.evaluate(() => document.documentElement.lang) === 'en', JSON.stringify(await listed(p)));
    await p.screenshot({ path: `${OUT}/5-docs-index-390-${scheme}.png` });
    await p.click('[data-guide-link="counter-quick-start"]'); await settle(p);
    const overflow = await p.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check(`phone (${scheme}): a guide reads at 390 with no sideways scroll`, overflow <= 0, `overflow ${overflow}px`);
    await p.screenshot({ path: `${OUT}/6-docs-guide-390-${scheme}.png`, fullPage: false });
    if (scheme === 'light') {
        await p.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
        check('phone: /counter is not reachable with the guides session (it asks for a sign-in)', /\/login/.test(p.url()) && ! await p.locator('[data-counter-topbar]').count(), p.url());
        await p.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
        check('phone: nor the dispensary', /\/login/.test(p.url()), p.url());
    }
    await p.context().close();
}

const mgr = await phone('light');
await mgr.goto(`${BASE}/docs`, { waitUntil: 'networkidle' });
await typePin(mgr, accountPin('manager'));
check('phone: a manager PIN shows all four', JSON.stringify(await listed(mgr)) === JSON.stringify([...STAFF, 'manager-guide']), JSON.stringify(await listed(mgr)));
await mgr.context().close();

// --- 3. Five wrong PINs ----------------------------------------------------------------------------------------------------
const thief = await phone('light');
await thief.goto(`${BASE}/docs`, { waitUntil: 'networkidle' });
for (const wrong of ['0000', '0001', '0002', '0003', '0004']) await typePin(thief, wrong);
await typePin(thief, accountPin('staff'));
check('phone: after five wrong PINs the right one is refused, with the same message', await thief.locator('[data-docs-error]').isVisible() && await thief.locator('[data-guide-link]').count() === 0,
    await thief.locator('[data-docs-error]').innerText().catch(() => ''));
await thief.screenshot({ path: `${OUT}/7-docs-locked-390.png` });

// --- 4. The panel: Ayuda → Manual lists the guides, a guide opens in the panel -----------------------------------------
for (const scheme of ['light', 'dark']) {
    const panel = await (await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: scheme })).newPage();
    panel.on('pageerror', (e) => errors.push(e.message));
    await signIn(panel, { account: 'manager' });
    await panel.goto(`${BASE}/ayuda/manual`, { waitUntil: 'networkidle' });
    check(`panel (${scheme}): the Manual lists all four guides for a manager, above its topics`, (await listed(panel)).length === 4
        && await panel.locator('[data-manual-guides]').isVisible());
    await panel.screenshot({ path: `${OUT}/8-panel-manual-1440-${scheme}.png` });
    await panel.click('[data-guide-link="manager-guide"]'); await settle(panel);
    check(`panel (${scheme}): the manager guide opens in the panel, with its way back`, await panel.locator('[data-guide-body]').isVisible() && await panel.locator('[data-way-back]').isVisible());
    await panel.screenshot({ path: `${OUT}/9-panel-guide-1440-${scheme}.png` });
    await panel.context().close();
}

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
