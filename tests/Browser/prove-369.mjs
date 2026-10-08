// Prompt 369 §4 — the over-stock warning keeps «Añadir a la cesta» in view: on a throwaway csc:seed-staging DB with "Polen de
// casa" (2.80 g in the jar, 30.00 g sealed), type 4 g and add, then check the button's box is inside the viewport at
// 1180×820 and 820×1180 (light and dark).
//   BASE_URL=http://127.0.0.1:8360 node tests/Browser/prove-369.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/369';
mkdirSync(OUT, { recursive: true });
const results = [];
const check = (label, pass, detail = '') => results.push([label, !!pass, detail]);
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };
const browser = await chromium.launch();

for (const [w, h, theme] of [[1180, 820, 'light'], [820, 1180, 'dark']]) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, hasTouch: true, colorScheme: theme });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => check(`page error: ${e.message}`, false));
  await signInToCounter(page, '/counter', { account: 'staff' });
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  const float = page.locator('input[wire\\:model="floatInput"]');
  if (await float.count()) { await float.fill('100'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page); }
  await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
  if (await page.locator('#member-lookup').isVisible().catch(() => false)) {
    await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle(page);
    await page.locator('[data-member-lookup-result]').first().click(); await settle(page);
  }
  await page.locator('[data-catalogue-item="genetics"][data-search="Polen de casa"]').first().click();
  await settle(page);
  await page.locator('[data-weight-pad] button:text-is("4")').click();
  await page.click('[data-add-line]');
  await settle(page);
  check(`${w}×${h}: the warning shows`, (await page.locator('[data-stock-short]').count()) === 1);
  const box = await page.locator('[data-add-line]').boundingBox();
  check(`${w}×${h}: «Add to basket» is inside the viewport`, box !== null && box.y >= 0 && box.y + box.height <= h, box ? `y ${Math.round(box.y)}–${Math.round(box.y + box.height)} of ${h}` : 'no box');
  const warn = await page.locator('[data-stock-short]').boundingBox();
  check(`${w}×${h}: the warning is compact (≤ 2 rows)`, warn !== null && warn.height <= 130, warn ? `${Math.round(warn.height)} px` : '');
  await page.screenshot({ path: `${OUT}/over-stock-${w}x${h}-${theme}.png` });
  await ctx.close();
}

await browser.close();
for (const [label, pass, detail] of results) console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  — ${detail}` : ''}`);
const failed = results.filter(([, pass]) => !pass).length;
console.log(`${results.length - failed}/${results.length} passed`);
process.exit(failed === 0 ? 0 : 1);
