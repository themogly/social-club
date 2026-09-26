// Prompt 252 — proof on the REAL app that the receipt opens as a SHEET over the POS, never a new tab: after a
// commit, "Ver / imprimir recibo" opens a dialog with the ticket in an iframe, `context.pages().length` stays
// 1, and Escape and the Android back gesture (page.goBack()) close it with the URL still /counter/pos and the
// basket column intact.
//
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DevAdminSeeder
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/shoot-receipt-sheet.mjs
//
// PRECONDITION: a committed dispensation must be reachable on /counter/pos (a socio with an active membership,
// a priced+stocked genetic, an open till at the sede) — the dev seed provides one. Commit steps mirror the
// counter flow; if the seed changes, the selectors below are where to adjust.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, SEDE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/252';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const results = [];

for (const vp of [{ w: 1280, h: 800, tag: 'land' }, { w: 800, h: 1280, tag: 'port' }]) {
  for (const theme of ['light', 'dark']) {
    const context = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, colorScheme: theme });
    const page = await context.newPage();
    const label = `${theme} ${vp.tag}`;

    if (! await signInToCounter(page, '/counter/pos', { sede: SEDE })) {
      results.push([`${label}: sign in`, false]); await context.close(); continue;
    }

    // Reach a committed state: the receipt affordance only renders after a commit (data-receipt-open appears).
    // The dev seed + counter flow are expected to leave one reachable; wait for the affordance.
    const opener = await page.waitForSelector('[data-receipt-open]', { timeout: 8000 }).catch(() => null);
    if (! opener) {
      results.push([`${label}: a committed receipt is reachable`, false]); await context.close(); continue;
    }

    await opener.click();
    const dialog = await page.waitForSelector('[data-receipt-sheet] [role="dialog"]', { timeout: 4000 }).catch(() => null);
    const oneTab = context.pages().length === 1;
    const hasFrame = !! await page.$('[data-receipt-frame]');
    await page.screenshot({ path: `${OUT}/receipt-sheet-open-${theme}-${vp.tag}.png` });
    results.push([`${label}: sheet open, still one tab`, !!dialog && oneTab && hasFrame]);

    // Escape closes the sheet; the URL is unchanged and the basket column is still there.
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    const closedByEsc = ! await page.$('[data-receipt-sheet] [role="dialog"]:visible');
    const onPos = new URL(page.url()).pathname === '/counter/pos';
    const cart = !! await page.$('[data-cart-column]');
    results.push([`${label}: Escape closes, still /counter/pos with the cart`, closedByEsc && onPos && cart]);

    // The Android back gesture also closes the sheet rather than leaving the page.
    await opener.click();
    await page.waitForTimeout(200);
    await page.goBack();
    await page.waitForTimeout(300);
    const closedByBack = ! await page.$('[data-receipt-sheet] [role="dialog"]:visible');
    const stillPos = new URL(page.url()).pathname === '/counter/pos';
    results.push([`${label}: back gesture closes, still /counter/pos`, closedByBack && stillPos]);

    await context.close();
  }
}

await browser.close();
let ok = true;
for (const [text, pass] of results) { console.log(`${pass ? 'PASS' : 'FAIL'}  ${text}`); ok = ok && pass; }
process.exitCode = ok ? 0 : 1;
