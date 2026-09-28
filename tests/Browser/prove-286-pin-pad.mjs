// Prompt 286 — the PIN answers straight away and cannot be typed over while it is checked. Real app, "Fast 4G"-style
// throttling, tablet sizes. PRECONDITION: legacy (bcrypt-12) people "Legado N" with PIN 51NN at the sede, served with
// BCRYPT_ROUNDS=12; the dev seed's owner (1234).
import { chromium } from 'playwright';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/286';
const LEGACY = process.env.LEGACY_PIN ?? '5177';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };

const [w, h] = (process.env.VIEWPORT ?? '1180x820').split('x').map(Number);
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: w, height: h } });
const page = await ctx.newPage();
const cdp = await ctx.newCDPSession(page);
await cdp.send('Network.enable');
await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: (9 * 1024 * 1024) / 8, uploadThroughput: (1.5 * 1024 * 1024) / 8 });

check('sign in', await signInToCounter(page, '/counter/till'));

let updates = [];
page.on('request', (r) => { if (r.url().includes('/livewire') && r.method() === 'POST') updates.push({ at: Date.now(), url: r.url() }); });

async function raisePad() {
    if (! await page.isVisible('[data-counter-surface-unlock]')) {
        await page.click('[data-counter-switch-operator]');
        await page.waitForSelector('[data-counter-surface-unlock]', { state: 'visible' });
    }
}
async function type(pin) {
    for (const d of pin) await page.click(`[data-counter-surface] [data-pin-pad] button:has-text("${d}")`);
}
async function timedSubmit(label) {
    updates = [];
    const t0 = Date.now();
    await page.click('[data-counter-surface-unlock]');
    // Hammer the pad while it checks: digits and the confirm, as an impatient operator would.
    const hammer = (async () => {
        for (let i = 0; i < 6; i++) {
            await page.click('[data-counter-surface] [data-pin-pad] button:has-text("9")', { timeout: 300, force: true }).catch(() => {});
            await page.click('[data-counter-surface-unlock]', { timeout: 300, force: true }).catch(() => {});
        }
    })();
    const state = await page.waitForFunction(() => {
        const pad = document.querySelector('[data-pin-pad]');
        return pad && ['success', 'error'].includes(pad.dataset.pinState) ? pad.dataset.pinState : null;
    }, null, { timeout: 20000 }).then((h) => h.jsonValue());
    const ms = Date.now() - t0;
    await hammer;
    check(`${label}: one request while checking`, updates.length === 1, `(${updates.length})`);
    return { state, ms };
}

// 1. A legacy person's first PIN, then the same PIN after the idle lock.
await raisePad();
await type(LEGACY);
const first = await timedSubmit('legacy first PIN');
await page.screenshot({ path: `${OUT}/${w}x${h}-success.png` });
check('legacy first PIN signs in', first.state === 'success', `${first.ms} ms`);
const greeting = await page.textContent('[data-surface-heading]');
check('the pad greets by first name', /Legado|Hola|Hi/.test(greeting ?? ''), greeting ?? '');
await page.waitForTimeout(1200);
const skip = await page.$('[data-clock-skip]');
if (skip && await skip.isVisible()) { await skip.click(); await page.waitForTimeout(800); }

await page.evaluate(() => window.Alpine.store('counter').lockNow());
await page.waitForSelector('[data-counter-surface-unlock]', { state: 'visible' });
await type(LEGACY);
const second = await timedSubmit('upgraded PIN');
check('the second PIN is near-instant', second.state === 'success' && second.ms < first.ms, `${second.ms} ms (first ${first.ms} ms)`);
await page.waitForTimeout(1200);

// 2. A wrong PIN: shakes, clears, says what is left.
await page.evaluate(() => window.Alpine.store('counter').lockNow());
await page.waitForSelector('[data-counter-surface-unlock]', { state: 'visible' });
await type('0000');
const wrong = await timedSubmit('wrong PIN');
check('a wrong PIN is reported as an error', wrong.state === 'error', `${wrong.ms} ms`);
await page.waitForTimeout(500);
const alert = await page.textContent('[data-counter-surface-feedback]').catch(() => '');
check('it says how many attempts are left', /\d/.test(alert ?? ''), alert ?? '');
const dots = await page.$$eval('[data-pin-pad] .rounded-full', (els) => els.length);
check('the dots cleared', dots === 0, `(${dots})`);
await page.screenshot({ path: `${OUT}/${w}x${h}-wrong.png` });

// 3. Lockout: the keys are disabled with the countdown.
for (let i = 0; i < 6; i++) {
    if (await page.isDisabled('[data-counter-surface-unlock]')) break;
    await type('0000');
    await page.click('[data-counter-surface-unlock]');
    await page.waitForFunction(() => ['error', 'ready'].includes(document.querySelector('[data-pin-pad]')?.dataset.pinState), null, { timeout: 20000 });
    await page.waitForTimeout(700);
}
const locked = await page.isVisible('[data-pin-lockout]');
const keys = await page.$$eval('[data-pin-pad] .grid button, [data-counter-surface-unlock]', (els) => els.map((b) => b.disabled));
check('lockout shows the countdown and disables every key', locked && keys.length === 13 && keys.every(Boolean), JSON.stringify({ locked, keys }));
await page.screenshot({ path: `${OUT}/${w}x${h}-lockout.png` });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
