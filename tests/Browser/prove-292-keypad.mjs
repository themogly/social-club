// Prompt 292 — the dispensary keypad answers instantly, and the € preview shows the right grams. Real app, a club-sized
// catalogue (20 strains, 30 products), tablet throttling (4× CPU, 60 ms RTT, ~10 Mbit/s). PRECONDITION: the throwaway
// database from DECISIONS (calculator ON at the sede; "Stinky feet" at €10/g, "Nueve cincuenta" at €9.50/g), and the
// server-side expected grams for the parity table in PARITY_JSON (computed with DispensaryPos::resolveGramsCg).
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/292';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const [w, h] = (process.env.VIEWPORT ?? '1180x820').split('x').map(Number);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: w, height: h } });
page.setDefaultTimeout(10000);
let requests = 0;
page.on('request', (r) => { if (r.url().includes('/livewire') && r.method() === 'POST') requests++; });

check('sign in', await signInToCounter(page, '/counter/pos'));
if (page.url().includes('/counter/till')) {
    await page.fill('input[wire\\:model="floatInput"]', '50');
    await page.click('form[wire\\:submit="open"] button[type="submit"]');
    await page.waitForTimeout(1500);
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
}
const cdp = await page.context().newCDPSession(page);
await cdp.send('Network.enable');
await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 60, downloadThroughput: 1_250_000, uploadThroughput: 500_000 });
await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });

const lookup = page.locator('#member-lookup');
await lookup.fill('M-00001'); await lookup.press('Enter'); await page.waitForTimeout(1500);
await page.click('[data-member-lookup-result]'); await page.waitForTimeout(2000);
const fee = await page.$('form[wire\\:submit="collectMemberFee"] button[type="submit"]');
if (fee) { await fee.click(); await page.waitForTimeout(2000); }

async function choose(name) {
    await page.locator('[data-product]', { hasText: name }).first().click();
    await page.waitForSelector('[data-weight-pad]');
    await page.waitForLoadState('networkidle');
}
const display = () => page.textContent('[data-weight-display]').then((t) => (t ?? '').replace(/\s+/g, ' ').trim());
const key = (k) => page.click(`[data-weight-pad] button:has-text("${k}")`);

// 1. A key press makes no request and shows at once.
await choose('Stinky feet');
requests = 0;
let t0 = Date.now();
await key('2');
await page.waitForFunction(() => document.querySelector('[data-weight-display]')?.textContent.includes('2'), null, { polling: 'raf' });
const tapMs = Date.now() - t0;
check('one key: no request, on screen at once', requests === 0, `${tapMs} ms, ${requests} requests`);

// 2. Ten rapid taps: ten characters, in order, none lost.
await page.click('[data-weight-pad] button[aria-label]'); // backspace
requests = 0;
t0 = Date.now();
for (const d of '1234567890') await page.click(`[data-weight-pad] button:has-text("${d}")`, { delay: 0 });
const rapidMs = Date.now() - t0;
check('ten rapid taps → ten characters in order', (await display()).startsWith('1234567890'), `${await display()} in ${rapidMs} ms, ${requests} requests`);
for (let i = 0; i < 10; i++) await page.click('[data-weight-pad] button[aria-label]');

// 3. The owner's photo case: €20 at €10/g → 2,00 g, and the basket line is 2,00 g.
await page.click('[data-calculator-toggle] button:has-text("Calculadora")');
await key('2'); await key('0');
const preview = (await page.textContent('[data-entry-preview-grams]'))?.trim();
check('€20 at €10/g previews 2,00 g', preview === '2,00 g', preview ?? '');
await page.screenshot({ path: `${OUT}/${w}x${h}-calculator.png` });
requests = 0;
await page.click('[data-add-line]');
await page.waitForLoadState('networkidle'); await page.waitForTimeout(800);
const cart = await page.textContent('[data-cart-column]');
check('the basket line is 2,00 g', (cart ?? '').includes('2,00 g'), `${requests} request`);

// 4. A double tap on "Añadir a la cesta" adds ONE line.
await choose('Stinky feet');
await key('1');
const linesBefore = await page.locator('[wire\\:click^="removeLine"]').count();
requests = 0;
await page.dblclick('[data-add-line]');
await page.waitForLoadState('networkidle'); await page.waitForTimeout(800);
const linesAfter = await page.locator('[wire\\:click^="removeLine"]').count();
check('a double tap adds one line', linesAfter === linesBefore + 1, `${linesBefore} → ${linesAfter}, ${requests} request(s)`);

// 5. The keyboard types into the pad; Enter on a focused pad key does not add.
await choose('Stinky feet');
await page.click('[data-calculator-toggle] button:has-text("Gramos")').catch(() => {});
await page.keyboard.press('3'); await page.keyboard.press('.'); await page.keyboard.press('5');
check('the keyboard types into the pad', (await display()).startsWith('3,5'), await display());
await page.focus('[data-weight-pad] button:has-text("7")');
const beforeEnter = await page.locator('[wire\\:click^="removeLine"]').count();
requests = 0;
await page.keyboard.press('Enter');
await page.waitForTimeout(800);
check('Enter on a pad key does not add to the basket', await page.locator('[wire\\:click^="removeLine"]').count() === beforeEnter && requests === 0, `${requests} requests`);

// 6. Parity: the browser preview = DispensaryPos::resolveGramsCg for the same input, both modes, both rates.
const expected = JSON.parse(readFileSync(process.env.PARITY_JSON, 'utf8'));
const fmt = (cg) => (cg === null || cg <= 0) ? '' : `${Math.floor(cg / 100)},${String(cg % 100).padStart(2, '0')} g`;
let mismatches = [];
for (const name of ['Stinky feet', 'Nueve cincuenta']) {
    await choose(name);
    for (const mode of ['grams', 'calculator']) {
        await page.click(`[data-calculator-toggle] button:has-text("${mode === 'grams' ? 'Gramos' : 'Calculadora'}")`);
        for (const row of expected.filter((r) => r.genetic === name && r.mode === mode)) {
            while ((await display()).replace(/[ g€]/g, '') !== '0') await page.click('[data-weight-pad] button[aria-label]');
            for (const ch of row.value) await key(ch === ',' ? ',' : ch);
            const shown = await page.$eval('[data-entry-preview]', (el) => el.offsetParent === null ? '' : (el.querySelector('[data-entry-preview-grams]')?.textContent ?? '').trim());
            if (shown !== fmt(row.cg)) mismatches.push(`${name}/${mode}/"${row.value}": browser "${shown}" vs server "${fmt(row.cg)}"`);
        }
    }
}
check(`parity with resolveGramsCg (${expected.length} cases)`, mismatches.length === 0, mismatches.slice(0, 4).join(' | '));

await browser.close();
console.log(JSON.stringify({ tapMs, rapidMs }));
process.exit(results.every(Boolean) ? 0 : 1);
