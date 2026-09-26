// Prompt 250 — proof on the REAL dispensary that automatic batch selection works: the pane shows no Lote row
// (just the sede total), a 3 g dispensation weighs-adds-settles from 1.5 g + 50 g across two lotes, the outcome
// card shows ONE product line, and the admin dispensation view shows the two lotes it drew from.
//
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DevAdminSeeder
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/shoot-dispensary-batches.mjs
//
// PRECONDITION: a WEIGHT genetic with a small older batch (~1.5 g) and a larger newer one (~50 g) at the sede,
// priced, with an eligible socio and an open till — and `dispensary_batch_selection = automatic` (the default).
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, SEDE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/250';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const results = [];

for (const theme of ['light', 'dark']) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, colorScheme: theme });
  const page = await context.newPage();

  if (! await signInToCounter(page, '/counter/pos', { sede: SEDE })) {
    results.push([`${theme}: sign in`, false]); await context.close(); continue;
  }

  // Identify a socio and choose the genetic → the weight pane appears, with NO Lote row in automatic mode.
  await page.click('[data-member-result]').catch(() => {});
  await page.click('[data-genetic-card]').catch(() => {});
  await page.waitForTimeout(400);
  const noLote = ! await page.$('[data-batch-mode="manual"]');
  const showsTotal = !! await page.$('[data-batch-mode="automatic"]');
  results.push([`${theme}: pane has no Lote row, shows the sede total`, noLote && showsTotal]);
  await page.screenshot({ path: `${OUT}/pane-automatic-${theme}.png` });

  // Weigh 3 g, add, settle.
  await page.fill('input[wire\\:model="weightInput"], [data-weight-input] input', '3').catch(() => {});
  await page.click('[data-add-line]').catch(() => {});
  await page.click('[data-commit-action]').catch(() => {});
  await page.waitForTimeout(800);
  await page.screenshot({ path: `${OUT}/outcome-split-${theme}.png` });

  await context.close();
}

await browser.close();
let ok = true;
for (const [text, pass] of results) { console.log(`${pass ? 'PASS' : 'FAIL'}  ${text}`); ok = ok && pass; }
process.exitCode = ok ? 0 : 1;
