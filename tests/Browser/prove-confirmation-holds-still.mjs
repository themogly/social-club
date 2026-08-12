// Prompt 202 — the confirmation carries the outcome, renders ONCE, and does not shove the Charge button.
//
// Two things here are invisible to a PHP test and to a static capture:
//
//   1. **Geometry across consecutive charges.** The confirmation renders directly ABOVE the Charge button,
//      inside the cart column's pinned bottom block. Whether the button MOVES when that block grows is a
//      layout question — it depends on the column being a fixed-height flex with a scrolling middle. Get it
//      wrong and the operator's thumb lands somewhere else on the second sale of a busy evening. Measured
//      after each of three charges, not reasoned about.
//   2. **The change survives the reset in a real round trip.** `resetBasketState()` clears `cashTendered`;
//      the change is derived from it. The PHP test proves the value is frozen onto the settled outcome —
//      this proves the operator can still read it on screen after Livewire has re-rendered the column.
//
// Needs a RUNNING SERVER (like prove-commit-click.mjs, and for the same reason — real Livewire round trips):
//   npm run build
//   php artisan serve --port=8123
//   node tests/Browser/prove-confirmation-holds-still.mjs
//
// Exits non-zero if the Charge button moves, if a confirmation renders twice, or if no change is stated.

import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';

// The sign-in preamble is `counter-session.mjs` (prompts 223/226) — one copy, not ten. Everything below is
// this harness's own: its viewport, its listeners, its measurements.
import { signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/202';

await mkdir(OUT, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

// --- log in, reach a working bar counter -----------------------------------------------------------
await signInToCounter(page, '/counter/bar');

if (!(await page.$('[data-commit-action]'))) {
  fail('no Charge button on the bar screen — the counter chain is still blocking; nothing was measured');
  await browser.close();
  process.exit(1);
}

// --- three consecutive charges ---------------------------------------------------------------------
const chargeY = [];
const rounds = [];

for (let i = 1; i <= 3; i++) {
  await page.click('[data-product]:not([disabled])');
  await page.waitForTimeout(700);

  // A note far larger than the line, so there is always change to state.
  await page.fill('#bar-cash-tendered', '50,00');
  await page.waitForTimeout(700);

  const before = (await page.$eval('[data-commit-action]', (n) => n.getBoundingClientRect().y));

  await page.click('[data-commit-action]');
  await page.waitForTimeout(1600);

  const after = (await page.$eval('[data-commit-action]', (n) => n.getBoundingClientRect().y));

  const outcomes = await page.$$eval('[data-settled-outcome]', (n) => n.length);
  const liveRegions = await page.$$eval('[data-commit-feedback], [data-blocked-feedback]', (n) => n.length);
  const change = await page.$eval('[data-outcome-change]', (n) => n.textContent.trim()).catch(() => null);
  const tendered = await page.$eval('#bar-cash-tendered', (n) => n.value).catch(() => null);

  chargeY.push(after);
  rounds.push({ round: i, chargeY_before: before, chargeY_after: after, outcomes, liveRegions, change, tendered });

  if (outcomes !== 1) fail(`round ${i}: ${outcomes} outcome blocks on screen, expected exactly 1`);
  if (liveRegions !== 1) fail(`round ${i}: ${liveRegions} live regions on screen, expected exactly 1`);
  if (!change) fail(`round ${i}: no change stated after tendering €50,00`);
  if (tendered !== '') fail(`round ${i}: the tender field still reads "${tendered}" — the reset did not run`);

  await page.screenshot({ path: `${OUT}/charge-${i}.png` });
}

console.table(rounds);

// --- the geometry claim ------------------------------------------------------------------------------
const spread = Math.max(...chargeY) - Math.min(...chargeY);
console.log(`Charge button y after each charge: ${chargeY.map((v) => v.toFixed(1)).join(' · ')}  (spread ${spread.toFixed(1)}px)`);

if (spread > 1) fail(`the Charge button moved ${spread.toFixed(1)}px across three charges — an operator's thumb lands elsewhere on the second sale`);

// --- and it clears on the next basket action ---------------------------------------------------------
await page.click('[data-product]:not([disabled])');
await page.waitForTimeout(900);
const lingering = await page.$$eval('[data-settled-outcome]', (n) => n.length);
if (lingering !== 0) fail(`the previous charge's outcome survived the next basket action (${lingering} still on screen)`);
await page.screenshot({ path: `${OUT}/cleared-on-next-basket.png` });

await browser.close();
console.log(failed ? 'RESULT: FAIL' : 'RESULT: PASS');
process.exit(failed ? 1 : 0);
