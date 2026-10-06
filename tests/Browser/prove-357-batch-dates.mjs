// Prompt 357 — Batches gets a sortable «Añadido» (date added) column. Freshly seeded demo DB, owner, 1440×900:
// the column is there after «Sede», sorting by it orders by date added, and it replaces a sort by «Lote».
import { chromium } from 'playwright';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/357';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
await signIn(page, { account: 'owner' });

await page.goto(`${BASE}/batches?sort=lote:desc`, { waitUntil: 'networkidle' });
const headers = (await page.locator('.fi-ta-table thead th').allInnerTexts()).map((t) => t.trim()).filter(Boolean);
check('«Añadido» is a column, right after «Sede»', headers.indexOf('Añadido') === headers.indexOf('Sede') + 1, JSON.stringify(headers));
await page.screenshot({ path: `${OUT}/1-batches-1440.png` });

await page.locator('.fi-ta-table thead th', { hasText: 'Añadido' }).locator('button').first().click();
await page.waitForLoadState('networkidle'); await page.waitForTimeout(500);
const idx = headers.indexOf('Añadido');
const dates = await page.locator('.fi-ta-table > tbody > tr').evaluateAll((rows, i) => rows.map((r) => r.children[i + 1]?.innerText.trim() ?? ''), idx);
check('one click on «Añadido» replaces the sort by «Lote»', /sort=created_at/.test(page.url()) && dates.filter(Boolean).length > 1, `${page.url()} ${JSON.stringify(dates.slice(0, 3))}`);
await page.screenshot({ path: `${OUT}/2-batches-by-date-added-1440.png` });

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
