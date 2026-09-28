// Prompt 281 — a period left open from an earlier business day: the next PIN asks when it ended, first.
import { chromium } from 'playwright';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/281';
const PIN = process.env.DEV_PIN ?? '3456';
const [w, h] = (process.env.VIEWPORT ?? '820x1180').split('x').map(Number);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: w, height: h } });
let ok = await signIn(page);
await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
const sede = await page.$('[data-counter-sede-menu] form button');
if (sede) { await sede.click(); await page.waitForLoadState('networkidle'); }
for (const d of PIN.split('')) await page.click(`[data-counter-surface] button:has-text("${d}")`);
await page.click('[data-counter-surface-unlock]');
await page.waitForTimeout(1200);
const card = await page.$('[data-clock-declare]');
const shown = !! card && await card.isVisible();
console.log(`${shown ? 'PASS' : 'FAIL'} declare card shown`); ok &&= shown;
await page.screenshot({ path: `${OUT}/${w}x${h}-declare.png` });
if (shown) {
    const box = await card.boundingBox();
    console.log(`${box.y + box.height <= h ? 'PASS' : 'FAIL'} declare card above the fold (bottom ${Math.round(box.y + box.height)})`);
    // Submitting without a time is refused with a message, not a crash.
    await page.click('[data-clock-declare-submit]');
    await page.waitForTimeout(1000);
    const fb = await page.$('[data-clock-feedback]');
    console.log(`${fb ? 'PASS' : 'FAIL'} empty declare refused: ${fb ? (await fb.innerText()) : ''}`); ok &&= !! fb;
    const min = await page.$eval('[data-clock-declare] input[type="datetime-local"]', (e) => e.min);
    const end = min.slice(0, 11) + '22:30';
    await page.fill('[data-clock-declare] input[type="datetime-local"]', end);
    await page.fill('[data-clock-declare] input[type="text"], [data-clock-declare] textarea', 'Me olvidé de fichar');
    await page.click('[data-clock-declare-submit]');
    await page.waitForTimeout(1500);
    const still = await page.$('[data-clock-declare]');
    const gone = ! still || ! await still.isVisible();
    console.log(`${gone ? 'PASS' : 'FAIL'} declared ${end}, then clocked in`); ok &&= gone;
    const out = await page.isVisible('[data-counter-clock-out]');
    console.log(`${out ? 'PASS' : 'FAIL'} Fichar salida shown`); ok &&= out;
    await page.screenshot({ path: `${OUT}/${w}x${h}-declare-done.png` });
}
await browser.close();
process.exit(ok ? 0 : 1);
