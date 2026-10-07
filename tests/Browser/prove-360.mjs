// Prompt 360 — proof on the running app (a throwaway csc:seed-staging DB, staff granted till.close + stock.take as the
// guides' "about" says):
//   R. the end-of-day weigh, at 820×1180 touch: a count that is OFF asks ONE blind question (no jar, no amount); answering
//      it… is left for M (here we only Cancel);
//   M. the same weigh with every jar typed at exactly its system figure (read with tinker into JARS) asks nothing and
//      reveals the variances;
//   Z. Inventario's «Nuevo inventario» modal with «Incluir lotes a cero».
//   BASE_URL=http://127.0.0.1:8360 JARS='{"<batch name>": "12.34", …}' node tests/Browser/prove-360.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/360';
mkdirSync(OUT, { recursive: true });
const JARS = JSON.parse(process.env.JARS ?? '{}');
const results = [];
const check = (label, pass, detail = '') => results.push([label, !!pass, detail]);
const browser = await chromium.launch();

async function tillClose(page) {
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  const float = page.locator('input[wire\\:model="floatInput"]');
  if (await float.count()) { await float.fill('100'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await page.waitForLoadState('networkidle'); }
  await page.click('button[wire\\:click="startClose"]');
  await page.waitForSelector('[data-reweigh-batch]');
}

for (const theme of ['dark', 'light']) {
  const ctx = await browser.newContext({ viewport: { width: 820, height: 1180 }, hasTouch: true, isMobile: true, colorScheme: theme });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => check(`page error: ${e.message}`, false));
  await signInToCounter(page, '/counter', { account: 'staff' });
  await tillClose(page);

  // R — off: one jar typed far from anything, the rest «Can't count it».
  const jars = page.locator('[data-reweigh-batch]');
  const n = await jars.count();
  await jars.nth(0).locator('input[inputmode="decimal"]').fill('1');
  for (let i = 1; i < n; i++) { await page.locator('[data-reweigh-not-counted-toggle]').nth(i).click(); await page.waitForTimeout(300); }
  check(`R ${theme}: no per-jar reason field`, (await page.locator('[data-reweigh-reason]').count()) === 0);
  await page.click('form[wire\\:submit="submitReweigh"] button[type="submit"]');
  await page.waitForTimeout(800);
  const box = page.locator('[data-reweigh-reason-box]');
  check(`R ${theme}: exactly one reason box`, (await box.count()) === 1);
  const text = (await box.innerText().catch(() => '')) ?? '';
  check(`R ${theme}: blind — no grams, no jar named`, !/\d+[.,]\d{2}\s?g/.test(text) && !Object.keys(JARS).some((name) => text.includes(name.split(' · ')[0])), text.slice(0, 60));
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: `${OUT}/R-reason-box-${theme}-820.png` });
  await page.click('[data-reweigh-reason-box] button[wire\\:click="cancelClose"]');
  await page.waitForTimeout(600);

  // M — every jar exactly at its system figure: no question, the count commits (light only: it closes the day's count).
  if (theme === 'light' && Object.keys(JARS).length) {
    await page.click('button[wire\\:click="startClose"]');
    await page.waitForSelector('[data-reweigh-batch]');
    for (let i = 0; i < n; i++) {
      const name = (await jars.nth(i).locator('label').first().innerText()).trim();
      const grams = Object.entries(JARS).find(([k]) => name.startsWith(k))?.[1];
      await jars.nth(i).locator('input[inputmode="decimal"]').fill(grams ?? '0');
    }
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: `${OUT}/M-all-match-before-${theme}-820.png` });
    await page.click('form[wire\\:submit="submitReweigh"] button[type="submit"]');
    await page.waitForTimeout(1200);
    check(`M ${theme}: matching count asks nothing`, (await page.locator('[data-reweigh-reason-box]').count()) === 0);
    check(`M ${theme}: and moves on to the cash count with the variances revealed`, await page.isVisible('text=/sin diferencia|no difference/i'));
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: `${OUT}/M-all-match-after-${theme}-820.png` });
  }
  await ctx.close();
}

// Z — the start modal's toggle.
{
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 820 } });
  const page = await ctx.newPage();
  await signIn(page, { account: 'manager' });
  await page.goto(`${BASE}/inventario`, { waitUntil: 'networkidle' });
  await page.click('button:has-text("New stock count")');
  await page.waitForTimeout(800);
  check('Z: «Include empty batches» in the start modal', await page.isVisible('text=Include empty batches'));
  await page.screenshot({ path: `${OUT}/Z-include-empty-1280.png` });
  await ctx.close();
}

await browser.close();
let ok = true;
for (const [label, pass, detail] of results) { console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  (${detail})` : ''}`); ok &&= pass; }
console.log(`${results.filter((r) => r[1]).length}/${results.length}`);
process.exit(ok ? 0 : 1);
