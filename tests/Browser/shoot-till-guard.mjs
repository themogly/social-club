// Prompt 241 — proof on the REAL app that the till guard fires: no till → deep-linking a counter screen lands
// on the open-till screen; open the till → the screen is reachable again. This is 236's before/after, now true.
//
//   php artisan tinker --execute='App\Models\TillSession::where("status","OPEN")->update(["status"=>"CLOSED"]);'
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/shoot-till-guard.mjs
//
// Phase A (no till) runs for BOTH themes before Phase B opens one, because the dev DB is shared across
// contexts — a till opened for the light shot would otherwise be open for the dark no-till shot.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/241';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const results = [];
const ctxFor = (theme) => browser.newContext({ viewport: { width: 1024, height: 768 }, colorScheme: theme });

// --- Phase A: NO TILL — the guard redirects the dispensary to the open-till screen ---
const opened = {};
for (const theme of ['light', 'dark']) {
  const ctx = await ctxFor(theme);
  const page = await ctx.newPage();
  if (! await signInToCounter(page, '/counter/checkin', { sede: 'Central Branch' })) {
    console.error(`[${theme}] could not sign in`); process.exitCode = 1; await ctx.close(); continue;
  }
  await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
  const to = new URL(page.url()).pathname;
  const onTill = await page.$('[data-till-open-screen]');
  await page.screenshot({ path: `${OUT}/no-till-deeplink-pos-${theme}.png` });
  results.push([`${theme} no-till /counter/pos → ${to}`, to === '/counter/till' && !!onTill]);
  opened[theme] = { ctx, page };
}

// --- Phase B: OPEN THE TILL (once), then the dispensary is reachable in both themes ---
const first = opened.light ?? opened.dark;
if (first) {
  const { page } = first;
  const floatInput = await page.$('[data-till-float] input, input[wire\\:model="floatInput"]');
  if (floatInput) { await floatInput.fill('100'); }
  const openBtn = await page.$('[data-till-open-action]');
  if (openBtn) { await openBtn.click(); await page.waitForLoadState('networkidle'); await page.waitForTimeout(800); }
}
for (const theme of ['light', 'dark']) {
  const o = opened[theme]; if (! o) continue;
  await o.page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
  const to = new URL(o.page.url()).pathname;
  const onPos = await o.page.$('[data-cart-column], [data-counter-blocker]');
  await o.page.screenshot({ path: `${OUT}/till-open-pos-${theme}.png` });
  results.push([`${theme} till-open /counter/pos → ${to}`, to === '/counter/pos' && !!onPos]);
  await o.ctx.close();
}

await browser.close();
let ok = true;
for (const [label, pass] of results) { console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}`); ok = ok && pass; }
process.exitCode = ok ? 0 : 1;
