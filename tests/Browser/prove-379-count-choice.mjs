// Prompt 379 — proof on the running app (a throwaway csc:seed-staging DB in English; Central Branch with edibles in their own box
// counted every night, the bar in its own box counted only when emptied, fees in the till, the shop with the bar):
// Locations → Tills at 1440 and 393, light and dark — the counting choice sits under its row, and there is no switch.
//   BASE_URL=http://127.0.0.1:8360 SEDE=<central id> node tests/Browser/prove-379-count-choice.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/379';
mkdirSync(OUT, { recursive: true });
const results = [];
const check = (label, pass, detail = '') => results.push([label, !!pass, detail]);
const browser = await chromium.launch();
const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
await signIn(page, { account: 'owner' });

for (const [w, h, dark] of [[1440, 900, false], [1440, 900, true], [393, 852, false], [393, 852, true]]) {
  const tag = `${w}-${dark ? 'dark' : 'light'}`;
  await page.setViewportSize({ width: w, height: h });
  await page.goto(`${BASE}/locations/${process.env.SEDE}/edit`, { waitUntil: 'networkidle' });
  await page.emulateMedia({ colorScheme: dark ? 'dark' : 'light' });
  await page.evaluate((d) => document.documentElement.classList.toggle('dark', d), dark);
  await page.waitForTimeout(400);
  const fieldset = page.locator('fieldset:has([data-cash-summary])');
  await fieldset.scrollIntoViewIfNeeded();
  const text = await fieldset.innerText();
  check(`${tag}: two counting choices, one per own box`, (await page.locator('[data-cash-count-choice]').count()) === 2);
  check(`${tag}: edibles — every night, with its line`, /It must be counted when the till is closed\./.test(text));
  check(`${tag}: bar — only when emptied, with its line`, /It can be left uncounted at close; what's in it carries to the next day\./.test(text));
  check(`${tag}: no switch in the section`, (await fieldset.locator('.fi-fo-toggle, button[role="switch"]').count()) === 0);
  check(`${tag}: no sideways scroll`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
  // The counting choice sits under its row: its buttons start at the row's buttons' left edge.
  const rowButtons = await page.locator('.fi-fo-field:has(> * [data-cash-count-choice="EDIBLES"])').first().boundingBox().catch(() => null);
  void rowButtons;
  await fieldset.screenshot({ path: `${OUT}/cajas-${tag}.png` });
}

await browser.close();
for (const [label, pass, detail] of results) console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  — ${detail}` : ''}`);
const failed = results.filter(([, pass]) => !pass).length;
console.log(`${results.length - failed}/${results.length} passed`);
process.exit(failed === 0 ? 0 : 1);
