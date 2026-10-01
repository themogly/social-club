// Prompt 340 — a batch's price, seen and changed on a phone. Freshly seeded demo DB (DBFILE), as the owner:
//   390×844 — the batch page: the current price, «Precio» and «Trasladar» on screen, «Borrar» neither first nor alone;
//             the list: each row's price under its name; ⋮ on the first and last row keeps every item reachable, «Precio»
//             first, and it opens the price form;
//   1280×800 — the «Precio» column as before; 1180×820 — 321's check: an open menu is not covered by the next row.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/340';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(600); };
const inView = async (p, loc) => { const b = await loc.boundingBox(); const v = p.viewportSize(); return !! b && b.y >= 0 && b.y + b.height <= v.height && b.x >= 0 && b.x + b.width <= v.width; };

const batch = sql("select id from batches where deleted_at is null and remaining_cg > 0 and price_per_gram_cents is not null order by created_at limit 1");

// --- The phone -----------------------------------------------------------------------------------------------------------
const phone = await (await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true })).newPage();
await signIn(phone);
await phone.goto(`${BASE}/batches/${batch}/edit`, { waitUntil: 'networkidle' });
const header = phone.locator('.fi-header-actions-ctn');
const buttons = header.locator('a.fi-btn:visible, button.fi-btn:visible');
const labels = (await buttons.allInnerTexts()).map((t) => t.trim());
check('390: the page shows the current price and «Cambiar precio»', await phone.locator('[data-batch-current-price]').isVisible() && await phone.locator('[data-batch-change-price]').isVisible());
check('390: «Precio» and «Trasladar» are on screen', await inView(phone, header.getByRole('button', { name: 'Precio' })) && await inView(phone, header.getByRole('button', { name: /Trasladar|Asignar a sede/ })), JSON.stringify(labels));
check('390: «Borrar» is neither the first nor the only visible header button', ! labels.some((l) => /^Borrar$/.test(l)) && labels.length > 1, JSON.stringify(labels));
const heights = await buttons.evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().height)));
check('390: every header button is at least 44 px tall', heights.every((h) => h >= 44), JSON.stringify(heights));
await phone.screenshot({ path: `${OUT}/1-batch-page-390.png`, fullPage: true });
await header.getByRole('button', { name: 'Precio' }).click(); await settle(phone);
check('390: «Precio» opens the price form', await phone.getByText('Guardar precio').isVisible());
await phone.keyboard.press('Escape'); await settle(phone);

await phone.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
const rows = phone.locator('.fi-ta-row');
const rowCount = await rows.count();
const prices = await phone.locator('[data-batch-row-price]:visible').count();
check('390: each row shows its price under the name', rowCount > 0 && prices >= Math.min(rowCount, 3), `${prices} prices / ${rowCount} rows`);
await phone.screenshot({ path: `${OUT}/2-list-390.png`, fullPage: true });
for (const [which, row] of [['first', rows.first()], ['last', rows.last()]]) {
    await row.scrollIntoViewIfNeeded();
    await row.locator('.fi-ta-actions .fi-dropdown-trigger, .fi-ta-actions button[aria-haspopup]').last().click(); await phone.waitForTimeout(700);
    const panel = phone.locator('.fi-dropdown-panel:visible').last();
    const items = panel.locator('.fi-dropdown-list-item:visible');
    const n = await items.count();
    const firstLabel = (await items.first().innerText()).trim();
    let reachable = true;
    for (let i = 0; i < n; i++) {
        const item = items.nth(i);
        await item.scrollIntoViewIfNeeded(); // inside the panel, if it scrolls
        if (! await inView(phone, item)) reachable = false;
    }
    const pb = await panel.boundingBox();
    check(`390: ⋮ on the ${which} row — every item reachable, «Precio» first, the panel inside the screen`, reachable && /^Precio$/.test(firstLabel) && pb.y >= 0 && pb.y + pb.height <= 844, `${n} items, first «${firstLabel}», panel ${Math.round(pb.y)}–${Math.round(pb.y + pb.height)}`);
    await phone.screenshot({ path: `${OUT}/3-menu-${which}-390.png` });
    if (which === 'last') {
        await items.first().click(); await settle(phone);
        check('390: tapping «Precio» in the menu opens the price form', await phone.getByText('Guardar precio').isVisible());
    } else {
        await phone.keyboard.press('Escape'); await phone.mouse.click(5, 5); await phone.waitForTimeout(400);
    }
}
await phone.context().close();

// --- Desktop: the column, and 321 ------------------------------------------------------------------------------------------
const desk = await (await browser.newContext({ viewport: { width: 1280, height: 800 } })).newPage();
await signIn(desk);
await desk.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
check('1280: the «Precio» column shows as before, and the under-name price is hidden', await desk.locator('th', { hasText: 'Precio' }).first().isVisible()
    && await desk.locator('[data-batch-row-price]:visible').count() === 0);
await desk.setViewportSize({ width: 1180, height: 820 });
const firstRow = desk.locator('.fi-ta-row').first();
await firstRow.locator('.fi-ta-actions .fi-dropdown-trigger, .fi-ta-actions button[aria-haspopup]').last().click(); await desk.waitForTimeout(700);
const menu = desk.locator('.fi-dropdown-panel:visible').last();
const lastItem = menu.locator('.fi-dropdown-list-item:visible').last();
const box = await lastItem.boundingBox();
const onTop = await desk.evaluate(({ x, y }) => !! document.elementFromPoint(x, y)?.closest('.fi-dropdown-panel'), { x: box.x + box.width / 2, y: box.y + box.height / 2 });
check('1180: 321 — the open menu is not covered by the next row', onTop);
await desk.screenshot({ path: `${OUT}/4-menu-1180.png` });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
