// Prompt 293 — the counter's round-trip budget, on the REAL app with a club-sized catalogue (40 strains, 50 products) or
// double (80/100): view-only controls (filters, layout, catalogue tab, search) make ZERO Livewire requests; each basket /
// member / payment action returns at most 40 KB. Prints one JSON line per measurement; exits non-zero over budget.
import { chromium } from 'playwright';
import { BASE, signInToCounter } from './counter-session.mjs';

const BUDGET_KB = 40;
const results = [];
const rows = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
page.setDefaultTimeout(10000);
let reqs = 0;
let kb = 0;
page.on('response', async (r) => {
    if (! r.url().includes('/livewire') || r.request().method() !== 'POST') return;
    reqs++;
    kb += ((await r.body().catch(() => Buffer.alloc(0))).length) / 1024;
});
const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };

async function measure(label, act, { server = true } = {}) {
    reqs = 0; kb = 0;
    try { await act(); } catch (e) { rows.push({ label, error: e.message.split('\n')[0] }); check(label, false, e.message.split('\n')[0]); return; }
    await settle();
    const row = { label, requests: reqs, kb: +kb.toFixed(1) };
    rows.push(row);
    if (server === 'report') console.log(`INFO ${label} (carries the catalogue by design, not budgeted) ${row.kb} KB in ${reqs} request(s)`);
    else if (server) check(`${label} ≤ ${BUDGET_KB} KB`, reqs >= 1 && kb <= BUDGET_KB, `${row.kb} KB in ${reqs} request(s)`);
    else check(`${label}: no request`, reqs === 0, `${reqs} request(s)`);
}

// RequireOpenTill sends a screen with no open till to the open-till form first; open one and come back.
async function open(path) {
    await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' });
    if (page.url().includes('/counter/till')) {
        await page.fill('input[wire\\:model="floatInput"]', '50');
        await page.click('form[wire\\:submit="open"] button[type="submit"]');
        await settle();
        await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' });
    }
}

check('sign in', await signInToCounter(page, '/counter/pos'));
await open('/counter/pos');

// --- Dispensario ------------------------------------------------------------------------------------------------
await measure('pos: select member', async () => {
    await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle();
    reqs = 0; kb = 0;
    await page.click('[data-member-lookup-result]');
}, { server: 'report' }); // the first socio brings the working screen — and with it the whole catalogue — onto the page
const fee = await page.$('form[wire\\:submit="collectMemberFee"] button[type="submit"]');
if (fee) { await fee.click(); await settle(); }

const visibleProduct = (source) => `[data-product]${source === 'genetics' ? ':not([data-article-card])' : '[data-article-card]'}:not([disabled]) >> visible=true`;
await measure('pos: open filters', () => page.locator('button:has-text("Filtros")').first().click(), { server: false });
await measure('pos: product type filter', () => page.locator('[role="group"][aria-label="Tipo"] button').nth(1).click(), { server: false });
await measure('pos: product type reset', () => page.locator('[role="group"][aria-label="Tipo"] button').first().click(), { server: false });
await measure('pos: strain filter', () => page.locator('[role="group"][aria-label="Variedad"] button').nth(1).click(), { server: false });
await measure('pos: strain reset', () => page.locator('[role="group"][aria-label="Variedad"] button').first().click(), { server: false });
await measure('pos: grid layout', () => page.click('[data-layout-option="grid"]'), { server: false });
await measure('pos: list layout', () => page.click('[data-layout-option="list"]'), { server: false });
await measure('pos: genetic search', () => page.locator('input[aria-label="Buscar genética…"]').first().pressSequentially('Varie', { delay: 60 }), { server: false });
await measure('pos: clear genetic search', () => page.locator('input[aria-label="Buscar genética…"]').first().fill(''), { server: false });
await measure('pos: category filter', () => page.locator('[role="group"][aria-label="Categoría"] button:visible').nth(1).click(), { server: false });
await measure('pos: category reset', () => page.locator('[role="group"][aria-label="Categoría"] button:visible').first().click(), { server: false });
await measure('pos: choose genetic', () => page.locator(visibleProduct('genetics')).first().click());
await page.locator('[data-weight-pad] button:has-text("1")').click();
await measure('pos: add to basket', () => page.click('[data-add-line]'));
await measure('pos: bar tab', () => page.click('[data-source-option="bar"]'), { server: false });
await measure('pos: article search', () => page.locator('[data-article-search]').first().pressSequentially('Art', { delay: 60 }), { server: false });
await measure('pos: clear article search', () => page.locator('[data-article-search]').first().fill(''), { server: false });
await measure('pos: bar category filter', () => page.locator('[role="group"][aria-label="Categoría"] button:visible').nth(1).click(), { server: false });
await measure('pos: bar category reset', () => page.locator('[role="group"][aria-label="Categoría"] button:visible').first().click(), { server: false });
await measure('pos: add bar article', () => page.locator(visibleProduct('bar')).first().click());
await measure('pos: back to dispensary tab', () => page.click('[data-source-option="genetics"]'), { server: false });
await measure('pos: type cash tendered', () => page.locator('#pos-cash-tendered').pressSequentially('20', { delay: 120 }));
await measure('pos: quick cash', () => page.locator('[wire\\:click^="quickCash"]').first().click());
await measure('pos: clear tendered', () => page.click('[wire\\:click="clearTendered"]'));
await measure('pos: remove line', () => page.locator('[wire\\:click^="removeLine"]').first().click());

// --- Barra ------------------------------------------------------------------------------------------------------
await open('/counter/bar');
console.log(`INFO bar screen at ${page.url()}`);
await measure('bar: article search', () => page.locator('input[aria-label="Buscar producto…"]').first().pressSequentially('Art', { delay: 60 }), { server: false });
await measure('bar: clear article search', () => page.locator('input[aria-label="Buscar producto…"]').first().fill(''), { server: false });
await measure('bar: grid layout', () => page.click('[data-layout-option="grid"]'), { server: false });
await measure('bar: list layout', () => page.click('[data-layout-option="list"]'), { server: false });
await measure('bar: category filter', () => page.locator('button[data-view-only][aria-pressed="false"]:not([data-layout-option]):visible').first().click(), { server: false });
await measure('bar: large layout', () => page.click('[data-layout-option="large"]'), { server: false });
await measure('bar: category tile reset', () => page.locator('[data-category-tile]:visible').first().click(), { server: false });
await measure('bar: grid layout again', () => page.click('[data-layout-option="grid"]'), { server: false });
await measure('bar: add article', () => page.locator(visibleProduct('bar')).first().click());
await measure('bar: +1', () => page.locator('[wire\\:click^="incrementLine"]').first().click());
await measure('bar: -1', () => page.locator('[wire\\:click^="decrementLine"]').first().click());
await measure('bar: quick cash', () => page.locator('[wire\\:click^="quickCash"]').last().click());
await measure('bar: clear tendered', () => page.click('[wire\\:click="clearTendered"]'));

await browser.close();
console.log(JSON.stringify(rows));
process.exit(results.every(Boolean) ? 0 : 1);
