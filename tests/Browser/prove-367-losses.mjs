// Prompt 367 — proof on the running app (a throwaway csc:seed-staging DB in English with a week of losses at Central Branch):
//   R. Reports → Losses as the owner on this week, at 1440 and 393, light and dark: the headline and chart, the sections,
//      the by-person table, and the Stock lost detail;
//   D. the dashboard's «Losses yesterday» line;
//   M. a manager sees their location only.
//   BASE_URL=http://127.0.0.1:8360 SEDE=<central id> node tests/Browser/prove-367-losses.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/367';
mkdirSync(OUT, { recursive: true });
const SEDE = process.env.SEDE;
const results = [];
const check = (label, pass, detail = '') => results.push([label, !!pass, detail]);
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };
const theme = async (page, dark) => {
  await page.emulateMedia({ colorScheme: dark ? 'dark' : 'light' });
  await page.evaluate((d) => document.documentElement.classList.toggle('dark', d), dark);
};
const browser = await chromium.launch();

// --- R. The report ---------------------------------------------------------------------------------------------------------
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => check(`R page error: ${e.message}`, false));
  await signIn(page, { account: 'owner' });

  for (const [w, h, dark] of [[1440, 900, false], [1440, 900, true], [393, 852, false], [393, 852, true]]) {
    const tag = `${w}-${dark ? 'dark' : 'light'}`;
    await page.setViewportSize({ width: w, height: h });
    await page.goto(`${BASE}/informes/perdidas?period=week&scope=${SEDE}`, { waitUntil: 'networkidle' });
    await theme(page, dark);
    await settle(page);
    const total = (await page.locator('[data-losses-headline] .csc-card').first().innerText()).replace(/\s+/g, ' ');
    check(`R ${tag}: the headline`, /Total lost/.test(total) && /% of takings/.test(total), total);
    check(`R ${tag}: five sections`, (await page.locator('[data-losses-section]').count()) === 5);
    check(`R ${tag}: the chart has a bar per day`, (await page.locator('.csc-loss-bar').count()) === 7);
    check(`R ${tag}: no sideways scroll`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await page.screenshot({ path: `${OUT}/headline-${tag}.png` });
    await page.locator('.csc-loss-sections').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/sections-${tag}.png`, fullPage: false });
    await page.locator('section:has(h2:text-is("By person"))').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/by-person-${tag}.png` });
  }

  // A section's total opens its list.
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${BASE}/informes/perdidas?period=week&scope=${SEDE}`, { waitUntil: 'networkidle' });
  await theme(page, false);
  await page.locator('[data-losses-section="existencias"]').click();
  await settle(page);
  check('R: the section link opens its detail', (await page.locator('[data-losses-detail="existencias"]').count()) === 1);
  const detail = await page.locator('section:has([data-losses-detail])').innerText();
  check('R: the detail names the wastage, who and why', /Wastage/.test(detail) && /Club Owner/.test(detail) && /Caducado/.test(detail), detail.slice(0, 200));
  await page.locator('[data-losses-detail]').scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${OUT}/detail-stock-1440.png` });
  await page.setViewportSize({ width: 393, height: 852 });
  await settle(page);
  await page.locator('[data-losses-detail]').scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${OUT}/detail-stock-393.png` });

  // The CSV downloads.
  await page.setViewportSize({ width: 1440, height: 900 });
  const [download] = await Promise.all([page.waitForEvent('download'), page.locator('button:has-text("CSV")').click()]);
  check('R: the CSV downloads', /perdidas/.test(download.suggestedFilename()), download.suggestedFilename());

  // D. The dashboard's line.
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  const line = page.locator('[data-losses-yesterday]');
  check('D: the dashboard shows Losses yesterday', (await line.count()) === 1 && /Losses yesterday/.test(await line.innerText()), (await line.innerText().catch(() => '')).replace(/\s+/g, ' '));
  await line.scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${OUT}/dashboard-line-1440.png` });
  await line.locator('a').click();
  await settle(page);
  check('D: the line opens Losses on yesterday', page.url().includes('period=yesterday'), page.url());
  await ctx.close();
}

// --- M. The manager --------------------------------------------------------------------------------------------------------
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await signIn(page, { account: 'manager' });
  await page.goto(`${BASE}/informes/perdidas?period=week`, { waitUntil: 'networkidle' });
  const options = await page.locator('select[wire\\:model\\.live="scope"] option').allInnerTexts().catch(() => []);
  check('M: no All locations for a manager', !options.includes('All locations'), options.join(', '));
  await ctx.close();
}

await browser.close();
for (const [label, pass, detail] of results) console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  — ${detail}` : ''}`);
const failed = results.filter(([, pass]) => !pass).length;
console.log(`${results.length - failed}/${results.length} passed`);
process.exit(failed === 0 ? 0 : 1);
