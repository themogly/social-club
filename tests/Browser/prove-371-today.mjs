// Prompt 371 — proof on the running app (a throwaway csc:seed-staging DB, accounts in English, one of today's dispensations
// voided): the home's «Hoy» panel opens the day's sheet; a manager sees amounts, staff see none; a row opens its receipt in
// the counter's sheet; the print layout. 1180×820 and 820×1180, manager and staff.
//   BASE_URL=http://127.0.0.1:8360 node tests/Browser/prove-371-today.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/371';
mkdirSync(OUT, { recursive: true });
const results = [];
const check = (label, pass, detail = '') => results.push([label, !!pass, detail]);
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };
const browser = await chromium.launch();

for (const account of ['manager', 'staff']) {
  for (const [w, h, theme] of [[1180, 820, 'light'], [820, 1180, 'dark']]) {
    const tag = `${account}-${w}x${h}-${theme}`;
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, hasTouch: true, colorScheme: theme });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => check(`${tag} page error: ${e.message}`, false));
    await signInToCounter(page, '/counter', { account });
    await page.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });

    const panel = page.locator('a[data-panel="today"]');
    check(`${tag}: the home «Today» panel is a link`, (await panel.count()) === 1 && (await panel.getAttribute('href'))?.endsWith('/counter/hoy'));
    check(`${tag}: it says «See the day»`, (await panel.innerText()).includes('See the day'));
    await panel.scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/home-${tag}.png` });

    await panel.click();
    await settle(page);
    check(`${tag}: lands on the sheet`, page.url().endsWith('/counter/hoy'));
    const rows = await page.locator('[data-sheet-row]').count();
    const count = (await page.locator('[data-sheet-count]').innerText()).trim();
    check(`${tag}: rows listed`, rows > 0, `${rows} rows · ${count}`);
    check(`${tag}: the voided sale is struck through`, (await page.locator('[data-sheet-voided]').count()) >= 1);
    const text = await page.locator('[data-today-sheet]').innerText();
    if (account === 'staff') check(`${tag}: no € anywhere for staff`, !text.includes('€'));
    else check(`${tag}: the manager sees amounts`, text.includes('€') && (await page.locator('[data-sheet-money]').count()) > 0);
    check(`${tag}: no horizontal page scroll`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await page.screenshot({ path: `${OUT}/sheet-${tag}.png` });
    await page.locator('[data-sheet-voided]').first().scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/voided-${tag}.png` });
    await page.locator('[data-sheet-totals]').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/totals-${tag}.png` });

    // A row opens its receipt in the counter's sheet (252), and Close brings the sheet back.
    await page.locator('[data-sheet-row="dispensary"]:not([data-sheet-voided]) button').first().click();
    await page.waitForTimeout(800);
    const dialog = page.locator('[data-receipt-sheet] [role="dialog"]');
    check(`${tag}: a row opens its receipt in-page`, await dialog.isVisible() && page.url().endsWith('/counter/hoy'));
    await page.screenshot({ path: `${OUT}/receipt-${tag}.png` });
    await page.click('[data-receipt-close]');
    await page.waitForTimeout(400);
    check(`${tag}: Close returns to the sheet`, !(await dialog.isVisible()));

    if (w === 1180) {
      await page.emulateMedia({ media: 'print' });
      await page.evaluate(() => window.scrollTo(0, 0));
      check(`${tag}: print hides the chrome and the controls`, !(await page.locator('[data-counter-topbar]').first().isVisible()) && !(await page.locator('[data-sheet-controls]').first().isVisible()));
      await page.screenshot({ path: `${OUT}/print-${tag}.png`, fullPage: true });
      await page.emulateMedia({ media: 'screen' });
    }
    await ctx.close();
  }
}

await browser.close();
for (const [label, pass, detail] of results) console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  — ${detail}` : ''}`);
const failed = results.filter(([, pass]) => !pass).length;
console.log(`${results.length - failed}/${results.length} passed`);
process.exit(failed === 0 ? 0 : 1);
