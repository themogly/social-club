// Probe: every server round trip the counter makes during ordinary use, with its time and size. Ben's inventory from
// prompt 293, adapted to the current markup: since 293 the catalogue's tab, filters, layout and search are the
// browser's (they appear here as steps with NO round trip), and a card is tapped through its data attributes.
//   BASE_URL=http://127.0.0.1:8135 OUT=storage/app/probe-roundtrips.jsonl node tests/Browser/probe-roundtrips.mjs
import { chromium } from 'playwright';
import fs from 'node:fs';
const OUT = process.env.OUT ?? 'storage/app/probe-roundtrips.jsonl';
fs.writeFileSync(OUT, '');
import { BASE, signInToCounter } from './counter-session.mjs';

const log = [];
let current = 'setup';

function describe(postData) {
    try {
        const body = JSON.parse(postData);
        return (body.components ?? []).map((c) => {
            const snap = JSON.parse(c.snapshot);
            const name = snap.memo?.name ?? '?';
            const calls = (c.calls ?? []).map((x) => `${x.method}(${(x.params ?? []).map((p) => JSON.stringify(p)).join(',').slice(0, 30)})`);
            const updates = Object.keys(c.updates ?? {});
            return `${name}: ${[...calls, ...updates.map((u) => `set ${u}`)].join(' + ') || '(refresh)'}`;
        }).join(' | ');
    } catch { return '?'; }
}

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
page.setDefaultTimeout(5000);

page.on('requestfinished', async (req) => {
    if (! req.url().includes('/livewire') || req.method() !== 'POST') return;
    const t = req.timing();
    const res = await req.response();
    const body = res ? await res.body().catch(() => Buffer.alloc(0)) : Buffer.alloc(0);
    const e = { step: current, what: describe(req.postData() ?? ''), ms: Math.round(t.responseEnd), kb: +(body.length / 1024).toFixed(1) };
    log.push(e); fs.appendFileSync(OUT, JSON.stringify(e) + '\n');
});

async function step(name, fn) {
    current = name;
    try { await fn(); } catch (e) { fs.appendFileSync(OUT, JSON.stringify({ step: name, what: 'ERROR ' + e.message.split('\n')[0] }) + '\n'); }
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(400);
}

const ok = await signInToCounter(page, '/counter/till');
console.log('signed in:', ok, page.url());

// --- Caja: open the till if needed
await step('caja: open till', async () => {
    const open = page.locator('button:has-text("Abrir caja")').first();
    if (await open.isVisible().catch(() => false)) {
        const amt = page.locator('input[inputmode="decimal"]').first();
        if (await amt.isVisible().catch(() => false)) await amt.fill('100');
        await open.click();
    }
});

// --- Dispensario
await step('pos: load', async () => { await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' }); });
await step('pos: type member search (M-00001)', async () => { await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); });
await step('pos: select member', async () => { await page.locator('[data-member-lookup-result]').first().click(); });
await step('pos: open filters (view only)', async () => { await page.locator('button:has-text("Filtros")').first().click(); });
await step('pos: product type filter (view only)', async () => { await page.locator('[role="group"][aria-label="Tipo"] button:visible').nth(1).click(); });
await step('pos: product type filter reset (view only)', async () => { await page.locator('[role="group"][aria-label="Tipo"] button:visible').first().click(); });
await step('pos: type genetic search (view only)', async () => { await page.locator('input[aria-label="Buscar genética…"]').pressSequentially('Amne', { delay: 120 }); });
await step('pos: clear genetic search (view only)', async () => { await page.locator('input[aria-label="Buscar genética…"]').fill(''); });
await step('pos: choose genetic', async () => { await page.locator('[data-catalogue-item="genetics"]:not([disabled]):visible').first().click(); });
for (const d of ['2', ',', '5']) {
    await step(`pos: keypad "${d}" (browser since 292)`, async () => { await page.locator('[data-weight-pad] button', { hasText: new RegExp(`^${d}$`) }).click(); });
}
await step('pos: add to basket', async () => { await page.click('[data-add-line]'); });
await step('pos: switch to bar catalogue (view only)', async () => { await page.click('[data-source-option="bar"]'); });
await step('pos: add bar article', async () => { await page.locator('[data-catalogue-item="bar"]:not([disabled]):visible').first().click(); });
await step('pos: type cash tendered "20"', async () => { await page.locator('#pos-cash-tendered').pressSequentially('20', { delay: 150 }); });
await step('pos: quick cash button', async () => { await page.locator('[wire\\:click^="quickCash"]').first().click(); });
await step('pos: clear tendered', async () => { await page.locator('[wire\\:click="clearTendered"]').click(); });
await step('pos: remove line', async () => { await page.locator('[wire\\:click^="removeLine"]').first().click(); });

// --- Barra
await step('bar: load', async () => { await page.goto(`${BASE}/counter/bar`, { waitUntil: 'networkidle' }); });
await step('bar: category filter (view only)', async () => { await page.locator('button[data-view-only][aria-pressed="false"]:not([data-layout-option]):visible').first().click(); });
await step('bar: add article', async () => { await page.locator('[data-catalogue-item="bar"]:not([disabled]):visible').first().click(); });
await step('bar: +1', async () => { await page.locator('[wire\\:click^="incrementLine"]').first().click(); });
await step('bar: -1', async () => { await page.locator('[wire\\:click^="decrementLine"]').first().click(); });
await step('bar: type article search (view only)', async () => { await page.locator('input[aria-label="Buscar producto…"]').pressSequentially('Agu', { delay: 120 }); });

// --- Recepción / Socios / Caja: load and idle
await step('checkin: load', async () => { await page.goto(`${BASE}/counter/checkin`, { waitUntil: 'networkidle' }); });
await step('checkin: idle 20s (polling?)', async () => { await page.waitForTimeout(20000); });
await step('members: load', async () => { await page.goto(`${BASE}/counter/members`, { waitUntil: 'networkidle' }); });
await step('members: type search (M-00001)', async () => { await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await page.waitForTimeout(800); });
await step('members: select member', async () => { await page.locator('[data-member-lookup-result]').first().click(); });
await step('till: load', async () => { await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' }); });
await step('till: idle 10s', async () => { await page.waitForTimeout(10000); });

await browser.close();
console.log(JSON.stringify(log, null, 1));
