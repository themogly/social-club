// Prompt 198 — the evidence: a basket, locked and unlocked WITHOUT leaving the screen, still there.
//
// The reported bug was not the lock mechanism (prompt 120 preserves state and always did) but the ROUTE to
// it: with the only control on /counter, reaching it crossed prompt 196's unsaved-work confirm and the sale
// was gone. This drives the real browser: put a line in the bar basket, lock from the overflow, unlock with
// a PIN, and read the basket back.
//
// Needs a RUNNING SERVER and the dev seed:
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/prove-lock-in-place.mjs
//
// Exits non-zero if the basket does not survive, if locking navigates, or if the lock is under 44x44.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

// The sign-in preamble is `counter-session.mjs` (prompts 223/226) — one copy, not ten. Everything below is
// this harness's own: its viewports, its contexts, its measurements.
import { BASE, SEDE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/198';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
let failed = false;
const rows = [];

for (const vp of [{ name: '1180x820', width: 1180, height: 820 }, { name: '820x1180', width: 820, height: 1180 }]) {
  const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, reducedMotion: 'reduce' });
  const page = await ctx.newPage();

  await signInToCounter(page, '/counter', { sede: SEDE });

  // --- a basket in progress on the bar ---
  await page.goto(`${BASE}/counter/bar`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);
  const article = await page.$('[data-product], [data-article]');
  if (article) { await article.click(); await page.waitForTimeout(900); }

  const before = await page.evaluate(() => ({
    lines: document.querySelectorAll('[data-basket-line], [data-bar-line]').length,
    text: document.body.innerText.includes('Empty basket') || document.body.innerText.includes('Cesta vacía') ? 'EMPTY' : 'HAS LINES',
    url: location.pathname,
  }));
  await page.screenshot({ path: `${OUT}/${vp.name}-1-basket-before.png` });

  // --- lock IN PLACE, from the overflow ---
  await page.click('[data-counter-overflow-trigger]');
  await page.waitForTimeout(300);
  const lock = await page.$('[data-counter-overflow-lock]');
  if (!lock) { console.error(`FAIL @ ${vp.name}: no in-place lock in the overflow`); failed = true; await ctx.close(); continue; }

  const box = await lock.boundingBox();
  if (!box || box.width < 44 || box.height < 44) {
    console.error(`FAIL @ ${vp.name}: lock item ${Math.round(box?.width ?? 0)}x${Math.round(box?.height ?? 0)}, under 44x44`);
    failed = true;
  }
  await page.screenshot({ path: `${OUT}/${vp.name}-2-overflow-open.png` });

  // No dialog must appear — locking preserves work, so it must never ask.
  let confirmed = false;
  page.on('dialog', async (d) => { confirmed = true; await d.dismiss(); });

  await lock.click();
  await page.waitForTimeout(1500);

  const locked = await page.evaluate(() => ({
    url: location.pathname,
    surface: document.querySelector('[data-counter-surface]')?.getAttribute('data-surface-mode') ?? 'none',
  }));
  await page.screenshot({ path: `${OUT}/${vp.name}-3-locked.png` });

  if (locked.url !== '/counter/bar') { console.error(`FAIL @ ${vp.name}: locking navigated to ${locked.url}`); failed = true; }
  if (confirmed) { console.error(`FAIL @ ${vp.name}: locking asked to confirm losing work`); failed = true; }

  // --- unlock, in place ---
  for (const d of '1234') await page.click(`[data-counter-surface] button:has-text("${d}")`).catch(() => {});
  await page.click('[data-counter-surface-unlock]').catch(() => {});
  await page.waitForTimeout(1600);

  const after = await page.evaluate(() => ({
    text: document.body.innerText.includes('Empty basket') || document.body.innerText.includes('Cesta vacía') ? 'EMPTY' : 'HAS LINES',
    url: location.pathname,
  }));
  await page.screenshot({ path: `${OUT}/${vp.name}-4-basket-after.png` });

  rows.push({ viewport: vp.name, before: before.text, locked: locked.surface, after: after.text, navigated: locked.url !== '/counter/bar' ? locked.url : 'no', confirmDialog: confirmed ? 'YES' : 'no' });

  if (before.text === 'HAS LINES' && after.text !== 'HAS LINES') {
    console.error(`FAIL @ ${vp.name}: the basket did not survive the lock`);
    failed = true;
  }

  await ctx.close();
}

await browser.close();
console.table(rows);
console.log(failed ? '\nRESULT: FAIL' : '\nRESULT: ALL PASS — basket survives an in-place lock');
process.exit(failed ? 1 : 0);
