// Prompt 268 — the dispensary tender panel on the REAL app at iPad portrait (820×1180): a member with a €0 wallet shows
// no wallet box; €20 + €20 + €10 reads €50,00 with the "Falta" (or "Cambio") line beneath.
//
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DevAdminSeeder
//   npm run build && php artisan serve --port=8123
//   MEMBER_NO=M-00006 node tests/Browser/shoot-tender-panel.mjs   (a member with a €0 wallet at the sede)
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, SEDE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/268';
mkdirSync(OUT, { recursive: true });
const MEMBER_NO = process.env.MEMBER_NO ?? 'M-00006';
const results = [];
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 820, height: 1180 }, hasTouch: true });
const page = await ctx.newPage();
page.setDefaultTimeout(8000);

if (! await signInToCounter(page, '/counter/till', { sede: SEDE })) {
  results.push(['sign in', false]);
} else {
  const float = await page.$('input[data-till-float]');
  if (float) { await float.fill('100'); await page.click('[data-till-open-action]'); await page.waitForTimeout(1000); }
  await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });

  await page.fill('input[data-member-lookup]', MEMBER_NO);
  await page.waitForSelector('[data-member-lookup-result]').catch(() => {});
  await page.click('[data-member-lookup-result]').catch(() => {});
  await page.waitForTimeout(700);
  await page.click('[wire\\:click^="chooseGenetic"]').catch(() => {});
  await page.waitForTimeout(500);
  for (const d of ['5']) { await page.click(`button[wire\\:click="pad('${d}')"]`).catch(() => {}); }
  await page.click('button[wire\\:click="addLine"]').catch(() => {});
  await page.waitForTimeout(800);

  const walletBox = await page.$('#wallet');
  await page.locator('[data-change-due], [data-cash-shortfall]').first().scrollIntoViewIfNeeded().catch(() => {});
  await page.screenshot({ path: `${OUT}/1-zero-wallet-no-box.png` });
  results.push(['€0 wallet: no wallet box', ! walletBox]);

  for (const note of ['2000', '2000', '1000']) {
    await page.click(`button[wire\\:click="quickCash(${note})"]`);
    await page.waitForTimeout(600);
  }
  const tendered = await page.inputValue('input[wire\\:model\\.live\\.debounce\\.400ms="cashTendered"]');
  const hasLine = !! await page.$('[data-cash-shortfall], [data-change-due]');
  await page.locator('[data-change-due], [data-cash-shortfall]').first().scrollIntoViewIfNeeded().catch(() => {});
  await page.screenshot({ path: `${OUT}/2-after-20-20-10.png` });
  results.push([`€20 + €20 + €10 → "${tendered}" with a Falta/Cambio line`, tendered === '50,00' && hasLine]);
}

await browser.close();
let ok = true;
for (const [text, pass] of results) { console.log(`${pass ? 'PASS' : 'FAIL'}  ${text}`); ok = ok && pass; }
process.exitCode = ok ? 0 : 1;
