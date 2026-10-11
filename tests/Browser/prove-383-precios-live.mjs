// Prompt 383 §1 — on Precios de la sede, leaving a cell must reach the server by itself: the below-cost warning and the grey
// defaults follow what was typed WITHOUT any other action. 382 used `wire:model.blur`, which in Livewire 4 only syncs on the
// client — the warning appeared only after some other request, and a changed standard price left the old defaults showing.
// A Livewire unit test bypasses the browser binding, so this has to be checked in a browser.
//
//   needs a running server and the dev seed (Central Branch with Amnesia Haze and Critical Kush in stock, at a cost);
//   BASE_URL=… node tests/Browser/prove-383-precios-live.mjs        (screenshots in storage/app/screenshots/383)
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signIn } from './counter-session.mjs';

const OUT = process.env.OUT ?? 'storage/app/screenshots/383';
mkdirSync(OUT, { recursive: true });
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
let livewireRequests = 0;
page.on('request', (r) => { if (r.url().includes('/livewire')) livewireRequests++; });

check('owner signs in', await signIn(page, { account: 'owner' }));
await page.goto(`${BASE}/precios`, { waitUntil: 'networkidle' });

const row = (strain) => page.locator(`table[data-prices-table] tr[data-prices-row]:has-text("${strain}")`).first();
const cell = (strain, column) => row(strain).locator(`[data-price-cell="${column}"]`);

// 1. A below-cost value, then Tab — and nothing else.
const before = livewireRequests;
await cell('Amnesia Haze', 'local_price_per_gram_cents').locator('input').fill('1');
await page.keyboard.press('Tab');
await page.waitForLoadState('networkidle');
await page.waitForTimeout(400);
check('leaving the cell sent it', livewireRequests > before, `${livewireRequests - before} request(s)`);
check('the cell warns below cost by itself', await cell('Amnesia Haze', 'local_price_per_gram_cents').getAttribute('data-price-below-cost') !== null);
await page.screenshot({ path: `${OUT}/precios-live-below-cost-1440.png`, fullPage: false });

// 2. A new standard price, then Tab — the same row's blank Personal cells show the NEW default.
await cell('Critical Kush', 'price_per_gram_cents').locator('input').fill('20.00');
await page.keyboard.press('Tab');
await page.waitForLoadState('networkidle');
await page.waitForTimeout(400);
const hint = await cell('Critical Kush', 'staff_price_per_gram_cents').locator('input').getAttribute('placeholder');
check('the blank Personal cell shows the new default', hint === '16.00 · −20%', `placeholder «${hint}»`);

await browser.close();
const failed = results.filter((ok) => ! ok).length;
console.log(failed === 0 ? `ALL ${results.length} PASSED` : `${failed} FAILED`);
process.exit(failed === 0 ? 0 : 1);
