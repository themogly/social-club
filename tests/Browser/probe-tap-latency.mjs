// Tap-to-screen latency on the counter under tablet-like conditions (4x CPU slowdown, 60 ms RTT, ~10 Mbit/s down,
// ~4 Mbit/s up). Ben's probe from prompt 293, adapted to the counter's current markup and with the trivial waits
// replaced: every step now waits for ITS OWN on-screen change (quick cash for the tendered figure, bar +1 for the
// quantity, the category filter for the visible cards), so no timing understates the real one.
//
// Selectors are the ones main and the 293 branch share, so the same script measures before and after.
//   BASE_URL=http://127.0.0.1:8135 OUT=/path/tap.jsonl node tests/Browser/probe-tap-latency.mjs
import { chromium } from 'playwright';
import fs from 'node:fs';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = process.env.OUT ?? 'storage/app/probe-tap-latency.jsonl';
fs.writeFileSync(OUT, '');
const rec = (o) => { fs.appendFileSync(OUT, JSON.stringify(o) + '\n'); console.log(JSON.stringify(o)); };

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
page.setDefaultTimeout(10000);
let lwBytes = 0;
let lwRequests = 0;
page.on('response', async (r) => {
    if (! r.url().includes('/livewire') || r.request().method() !== 'POST') return;
    lwRequests++;
    lwBytes += (await r.body().catch(() => Buffer.alloc(0))).length;
});

await signInToCounter(page, '/counter/pos');
if (page.url().includes('/counter/till')) {
    await page.fill('input[wire\\:model="floatInput"]', '50');
    await page.click('form[wire\\:submit="open"] button[type="submit"]');
    await page.waitForLoadState('networkidle');
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
}
const cdp = await page.context().newCDPSession(page);
await cdp.send('Network.enable');
await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 60, downloadThroughput: 1_250_000, uploadThroughput: 500_000 });
await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });

async function timed(name, act, until, arg = null) {
    lwBytes = 0; lwRequests = 0;
    const t0 = Date.now();
    try {
        await act();
        await page.waitForFunction(until, arg, { polling: 'raf', timeout: 10000 });
        const ms = Date.now() - t0;
        await page.waitForLoadState('networkidle');
        rec({ name, tapToScreenMs: ms, requests: lwRequests, kbDown: +(lwBytes / 1024).toFixed(1) });
    } catch (e) { rec({ name, error: e.message.split('\n')[0] }); }
    await page.waitForTimeout(300);
}

const visibleProduct = (source) => `[data-product]${source === 'genetics' ? ':not([data-article-card])' : '[data-article-card]'}:not([disabled]) >> visible=true`;

await page.fill('#member-lookup', 'M-00001');
await page.press('#member-lookup', 'Enter');
await page.waitForSelector('[data-member-lookup-result]');
await timed('select member', () => page.click('[data-member-lookup-result]'),
    () => [...document.querySelectorAll('[data-product]:not([data-article-card]):not([disabled])')].some((el) => el.offsetParent !== null));
const fee = await page.$('form[wire\\:submit="collectMemberFee"] button[type="submit"]');
if (fee) { await fee.click(); await page.waitForLoadState('networkidle'); }

await timed('choose genetic', () => page.locator(visibleProduct('genetics')).first().click(), () => !! document.querySelector('[data-add-line]'));

for (const d of ['2', '5']) {
    await timed(`keypad single tap "${d}"`, () => page.locator('[data-weight-pad] button', { hasText: new RegExp(`^${d}$`) }).click(),
        (d) => (document.querySelector('[data-weight-display]')?.textContent ?? '').includes(d), d);
}
await page.locator('[data-weight-pad] button[aria-label]').last().click();
await page.locator('[data-weight-pad] button[aria-label]').last().click();

// Rapid entry: a person typing "12,5" at a normal pace (150 ms between taps).
lwBytes = 0; lwRequests = 0;
const t0 = Date.now();
for (const d of ['1', '2', ',', '5']) { page.locator('[data-weight-pad] button', { hasText: new RegExp(`^${d}$`) }).click().catch(() => {}); await page.waitForTimeout(150); }
try {
    await page.waitForFunction(() => (document.querySelector('[data-weight-display]')?.textContent ?? '').includes('12,5'), null, { polling: 'raf', timeout: 10000 });
    rec({ name: 'rapid "12,5" (4 taps, 150 ms apart) until the display shows it', tapToScreenMs: Date.now() - t0, requests: lwRequests, kbDown: +(lwBytes / 1024).toFixed(1) });
} catch (e) { rec({ name: 'rapid 12,5', error: e.message.split('\n')[0] }); }
await page.waitForLoadState('networkidle');
await page.locator('[data-weight-pad] button[aria-label]').last().click();
await page.locator('[data-weight-pad] button[aria-label]').last().click();
await page.locator('[data-weight-pad] button[aria-label]').last().click();
await page.locator('[data-weight-pad] button', { hasText: /^1$/ }).click();

const lines = () => document.querySelectorAll('[wire\\:click^="removeLine"]').length;
const before = await page.evaluate(lines);
await timed('add to basket', () => page.click('[data-add-line]'), (n) => document.querySelectorAll('[wire\\:click^="removeLine"]').length > n && ! document.querySelector('[data-add-line]'), before);

await timed('quick cash €20', () => page.locator('[wire\\:click^="quickCash"]').last().click(), () => (document.querySelector('#pos-cash-tendered')?.value ?? '') !== '');
await timed('clear tendered', () => page.click('[wire\\:click="clearTendered"]'), () => (document.querySelector('#pos-cash-tendered')?.value ?? '') === '');
const now = await page.evaluate(lines);
await timed('remove basket line', () => page.locator('[wire\\:click^="removeLine"]').first().click(), (n) => document.querySelectorAll('[wire\\:click^="removeLine"]').length < n, now);

// Bar screen
await cdp.send('Emulation.setCPUThrottlingRate', { rate: 1 });
await page.goto(`${BASE}/counter/bar`, { waitUntil: 'networkidle' });
await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
await timed('bar: add article', () => page.locator(visibleProduct('bar')).first().click(), () => !! document.querySelector('[wire\\:click^="incrementLine"]'));
const qty = () => document.querySelector('[wire\\:click^="incrementLine"]')?.previousElementSibling?.textContent?.trim();
const q0 = await page.evaluate(qty);
await timed('bar: +1', () => page.locator('[wire\\:click^="incrementLine"]').first().click(),
    (q) => document.querySelector('[wire\\:click^="incrementLine"]')?.previousElementSibling?.textContent?.trim() !== q, q0);
const shown = () => [...document.querySelectorAll('[data-article-card]')].filter((el) => el.offsetParent !== null).length;
const s0 = await page.evaluate(shown);
const chip = 'button[wire\\:click^="filterCategory"][aria-pressed="false"]:visible, button[data-view-only][aria-pressed="false"]:not([data-layout-option]):visible';
await timed('bar: category filter', () => page.locator(chip).first().click(),
    (n) => [...document.querySelectorAll('[data-article-card]')].filter((el) => el.offsetParent !== null).length !== n, s0);

rec({ name: 'bar DOM nodes', domNodes: await page.evaluate(() => document.querySelectorAll('*').length) });
await browser.close();
