// Prompt 351 — the dispensary's strain list in price order. Freshly seeded demo DB (DBFILE), owner, at 1180×820 and
// 820×1180: Central Branch on its default (de mayor a menor), North Branch set to Alfabético.
//   1. the most expensive weight product is at the top, weight before units;  2. €↑ reverses it with no request;
//   3. a reload keeps €↑; grid shows the same order;  4. the next business day is back to the default;
//   5. switching sede gives that sede's default.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/351';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { env: { ...process.env, DB_DATABASE: DB } }).toString().trim().split('\n').pop();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(500); };

const org = sql('select id from organisations limit 1');
const north = sql("select id from locations where name = 'North Branch'");
tinker(`app(App\\Support\\ActiveScope::class)->setOrganisation('${org}'); App\\Support\\Settings::set('dispensary_sort', 'alpha', App\\Enums\\SettingType::STRING, '${north}');`);

// The genetics cards as the eye sees them: visible ones, in on-screen order (top, then left).
async function shown(page) {
    const cards = await page.locator('[data-catalogue-item="genetics"]:visible').evaluateAll((els) => els.map((el) => {
        const r = el.getBoundingClientRect();
        const price = (el.innerText.match(/(\d+[.,]\d{2})\s*€\/(g|ud|unit)/) ?? []);
        return { name: el.dataset.search, unit: price[2] !== 'g', price: Math.round(Number((price[1] ?? 'NaN').replace(',', '.')) * 100), stock: ! /Sin lote|No batch/.test(el.innerText), y: Math.round(r.top), x: Math.round(r.left) };
    }));
    return cards.sort((a, b) => a.y - b.y || a.x - b.x);
}
const weight = (cards) => cards.filter((c) => c.stock && !c.unit).map((c) => c.price);
const noStockLast = (cards) => { const i = cards.findIndex((c) => !c.stock); return i === -1 || cards.slice(i).every((c) => !c.stock); };
const sorted = (xs, dir) => xs.every((v, i) => i === 0 || (dir === 'desc' ? xs[i - 1] >= v : xs[i - 1] <= v));
const firstUnitAfterWeight = (cards) => { const inStock = cards.filter((c) => c.stock); const i = inStock.findIndex((c) => c.unit); return noStockLast(cards) && (i === -1 || inStock.slice(i).every((c) => c.unit)); };
const pressed = (page) => page.locator('[data-sort-option][aria-pressed="true"]').getAttribute('data-sort-option');

async function toPos(page) {
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    if (await page.locator('input[wire\\:model="floatInput"]').count()) {
        await page.fill('input[wire\\:model="floatInput"]', '50'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
        await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    }
    await settle(page);
    await pickMember(page);
}

// The catalogue shows once an active member is at the counter.
let memberNo = 'M-00001'; // active at Central; North's is M-00002
async function pickMember(page) {
    if (! await page.locator('#member-lookup').isVisible().catch(() => false)) return;
    await page.fill('#member-lookup', memberNo); await page.press('#member-lookup', 'Enter'); await settle(page);
    await page.locator('[data-member-lookup-result]').first().click(); await settle(page);
}

for (const [w, h] of [[1180, 820], [820, 1180]]) {
    const context = await browser.newContext({ viewport: { width: w, height: h } });
    const page = await context.newPage();
    memberNo = 'M-00001';
    page.on('pageerror', (e) => errors.push(e.message));
    await signInToCounter(page, '/counter/pos', { account: 'owner', sede: 'Central Branch' });
    await page.evaluate(() => { try { localStorage.removeItem('csc.dispensarySort'); } catch {} });
    await toPos(page);
    await page.locator('[data-layout-option="list"]').click(); await settle(page);

    // 1. Default: highest first.
    let cards = await shown(page);
    check(`${w}: Central starts on €↓ — the most expensive flower at the top, weight before units`, await pressed(page) === 'price_desc'
        && sorted(weight(cards), 'desc') && firstUnitAfterWeight(cards) && cards[0].price === Math.max(...weight(cards)), JSON.stringify(cards.slice(0, 4).map((c) => `${c.name} ${c.price}`)));
    await page.screenshot({ path: `${OUT}/1-default-desc-${w}.png` });

    // 2. €↑ — instantly, no request.
    let requests = 0;
    const count = (r) => { if (r.url().includes('/livewire')) requests++; };
    page.on('request', count);
    await page.locator('[data-sort-option="price_asc"]').click(); await page.waitForTimeout(400);
    page.off('request', count);
    cards = await shown(page);
    check(`${w}: €↑ reverses it with no request`, requests === 0 && sorted(weight(cards), 'asc') && firstUnitAfterWeight(cards), `requests ${requests} · ${JSON.stringify(weight(cards))}`);
    await page.screenshot({ path: `${OUT}/2-asc-${w}.png` });

    // 3. A reload keeps it; the grid shows the same order.
    await page.reload({ waitUntil: 'networkidle' }); await settle(page); await pickMember(page);
    const listOrder = (await shown(page)).map((c) => c.name);
    check(`${w}: a reload is still €↑`, await pressed(page) === 'price_asc' && sorted(weight(await shown(page)), 'asc'));
    await page.locator('[data-layout-option="grid"]').click(); await settle(page);
    const gridOrder = (await shown(page)).map((c) => c.name);
    check(`${w}: the grid shows the same order as the list`, JSON.stringify(gridOrder) === JSON.stringify(listOrder), JSON.stringify(gridOrder.slice(0, 4)));
    await page.screenshot({ path: `${OUT}/3-grid-asc-${w}.png` });
    await page.locator('[data-layout-option="list"]').click(); await settle(page);

    // A–Z: the alphabetical order the counter had before 351.
    await page.locator('[data-sort-option="alpha"]').click(); await page.waitForTimeout(300);
    const az = (await shown(page)).map((c) => c.name);
    check(`${w}: A–Z is alphabetical`, JSON.stringify(az) === JSON.stringify([...az].sort((a, b) => a.localeCompare(b, 'es', { sensitivity: 'base' }))), JSON.stringify(az.slice(0, 4)));
    await page.locator('[data-sort-option="price_asc"]').click(); await page.waitForTimeout(300);

    // 4. The next business day: what was saved yesterday no longer applies.
    await page.evaluate(() => { const s = JSON.parse(localStorage.getItem('csc.dispensarySort')); s.date = '2000-01-01'; localStorage.setItem('csc.dispensarySort', JSON.stringify(s)); });
    await page.reload({ waitUntil: 'networkidle' }); await settle(page); await pickMember(page);
    check(`${w}: on the next business day it is back to the sede default`, await pressed(page) === 'price_desc');
    await page.locator('[data-sort-option="price_asc"]').click(); await page.waitForTimeout(300);

    // 5. Switch sede: North's default (Alfabético), not Central's €↑.
    await page.locator('[data-counter-sede-current]').click();
    await page.locator(`[data-counter-sede="${north}"]`).click(); await settle(page);
    const confirm = page.locator('[data-counter-sede-switch-confirm] button[type="submit"]');
    if (await confirm.count() && await confirm.first().isVisible()) { await confirm.first().click(); await settle(page); }
    memberNo = 'M-00002';
    await toPos(page);
    const northCards = await shown(page);
    check(`${w}: switching sede gives that sede's default (North: A–Z)`, await pressed(page) === 'alpha' && northCards.length > 0,
        `${await pressed(page)} · ${JSON.stringify(northCards.map((c) => c.name).slice(0, 4))}`);
    await page.screenshot({ path: `${OUT}/4-north-alpha-${w}.png` });
    await context.close();
}

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
