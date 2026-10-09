// Prompt 376 — proof on the running app (a throwaway csc:seed-staging DB in English): Locations shows each state of the
// «Prices» badge — all priced (Central), some (North, one strain with neither a batch price nor a fallback), none (East),
// nothing in stock (South), a store (blank) — at 1440 and 393, light and dark; the partial badge links to the batches to price.
//   BASE_URL=http://127.0.0.1:8360 node tests/Browser/prove-376-price-coverage.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/376';
mkdirSync(OUT, { recursive: true });
const results = [];
const check = (label, pass, detail = '') => results.push([label, !!pass, detail]);
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
await signIn(page, { account: 'owner' });

for (const [w, h, dark] of [[1440, 900, false], [1440, 900, true], [393, 852, false], [393, 852, true]]) {
  const tag = `${w}-${dark ? 'dark' : 'light'}`;
  await page.setViewportSize({ width: w, height: h });
  await page.goto(`${BASE}/locations`, { waitUntil: 'networkidle' });
  await page.emulateMedia({ colorScheme: dark ? 'dark' : 'light' });
  await page.evaluate((d) => document.documentElement.classList.toggle('dark', d), dark);
  await page.waitForTimeout(400);
  const body = await page.locator('table').first().innerText();
  check(`${tag}: Central — every strain priced`, /Prices: 11 of 11/.test(body));
  check(`${tag}: North — one strain unpriced`, /Prices: 5 of 6/.test(body));
  check(`${tag}: East — none priced`, /No prices \(0 of 1\)/.test(body));
  check(`${tag}: South — nothing in stock`, /Out of stock/.test(body));
  check(`${tag}: no sideways scroll`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
  await page.screenshot({ path: `${OUT}/sedes-${tag}.png`, fullPage: true });
}

// The partial badge's tooltip names the strain and its link opens the batches to price.
await page.setViewportSize({ width: 1440, height: 900 });
await page.goto(`${BASE}/locations`, { waitUntil: 'networkidle' });
const north = page.locator('a:has-text("Prices: 5 of 6")');
await north.hover();
await page.waitForTimeout(500);
const tooltip = (await page.locator('.tippy-content').first().innerText().catch(() => '')).trim();
check('North: the tooltip names the unpriced strain', /^No price: .+\. Set it on the batch/.test(tooltip), tooltip);
await page.screenshot({ path: `${OUT}/sedes-tooltip-1440.png` });
await north.click();
await page.waitForLoadState('networkidle');
check('North: the badge opens the batches to price', page.url().includes('/batches') && decodeURIComponent(page.url()).includes('genetic_id'), page.url());
await page.screenshot({ path: `${OUT}/batches-to-price-1440.png` });

await browser.close();
for (const [label, pass, detail] of results) console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  — ${detail}` : ''}`);
const failed = results.filter(([, pass]) => !pass).length;
console.log(`${results.length - failed}/${results.length} passed`);
process.exit(failed === 0 ? 0 : 1);
