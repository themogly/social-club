// Prompt 306 — the pre-launch pass's rough edges, in a real browser on a throwaway database:
//   · the last-sale line is ONE line box at 1180×820 and 820×1180 (dispensary and bar), the time never alone;
//   · desktop Chrome accepts a typed "1000,01" in *Crear lote* (it refused a comma in <input type="number">), and the
//     batch then reads "1000,01 g" on the list;
//   · the *Recuento* dialog has no English "In system".
import { chromium } from 'playwright';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/306';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(700); };
const oneLine = (page) => page.$eval('[data-last-sale-summary]', (p) => {
    const tops = new Set([...p.children].map((c) => Math.round(c.getBoundingClientRect().top)));
    return { lines: tops.size, text: p.innerText.replace(/\s+/g, ' ').trim(), title: p.getAttribute('title') };
});

for (const [w, h] of [[1180, 820], [820, 1180]]) {
    const tag = `${w}x${h}`;
    const page = await browser.newPage({ viewport: { width: w, height: h } });
    await signInToCounter(page, '/counter/till', { account: 'owner' });
    if (await page.locator('input[wire\\:model="floatInput"]').count()) {
        await page.fill('input[wire\\:model="floatInput"]', '50');
        await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
    }

    // The dispensary: one sale.
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    const change = page.locator('[data-change-member]');
    if (await change.count()) { await change.click(); await settle(page); const y = page.locator('[data-confirm-discard-yes]'); if (await y.count()) { await y.click(); await settle(page); } }
    await page.fill('#member-lookup', 'M-00027'); await page.press('#member-lookup', 'Enter'); await settle(page);
    await page.click('[data-member-lookup-result]').catch(() => {}); await settle(page);
    await page.locator('[data-catalogue-item="genetics"]:visible').first().click(); await settle(page);
    const chip = page.locator('[data-batch-chip]').first();
    if (await chip.count()) { await chip.click(); await settle(page); }
    // 2 g, then 1 g: the member's daily limit is 3,5 g, so the second visit leaves 0,50 g — the amber state (item 5).
    await page.locator('[data-weight-pad] button', { hasText: w === 1180 ? /^2$/ : /^1$/ }).click();
    await page.click('[data-add-line]'); await settle(page);
    await page.locator('[wire\\:click^="quickCash"]').first().click(); await settle(page);
    const pad = page.locator('[data-signature-canvas]');
    if (await pad.count()) {
        const box = await pad.boundingBox();
        await page.mouse.move(box.x + 20, box.y + 30); await page.mouse.down();
        await page.mouse.move(box.x + 120, box.y + 80, { steps: 8 }); await page.mouse.up();
        await page.click('[data-signature-save]'); await settle(page);
    }
    await page.click('[data-commit-action]'); await settle(page);
    const pos = await oneLine(page).catch(() => ({ lines: 0 }));
    check(`${tag} dispensary: the last-sale line is one line`, pos.lines === 1, JSON.stringify(pos));
    await page.locator('[data-last-sale]').screenshot({ path: `${OUT}/${tag}-pos-last-sale.png` }).catch(() => {});
    if (w === 820) {
        // The same member again: 0,50 g of 3,50 g left reads amber, not green.
        await page.fill('#member-lookup', 'M-00027').catch(() => {}); await page.press('#member-lookup', 'Enter').catch(() => {}); await settle(page);
        await page.click('[data-member-lookup-result]').catch(() => {}); await settle(page);
        const state = await page.getAttribute('[data-member-allowance] [data-daily-remaining]', 'data-daily-remaining').catch(() => null);
        const cls = await page.getAttribute('[data-member-allowance] [data-daily-remaining]', 'class').catch(() => '');
        check('a quarter or less of the day left reads amber', state === 'low' && cls.includes('text-warning'), `${state} · ${cls}`);
        await page.locator('[data-member-allowance]').screenshot({ path: `${OUT}/allowance-low.png` }).catch(() => {});
    }

    // The bar: one sale.
    await page.goto(`${BASE}/counter/bar`, { waitUntil: 'networkidle' });
    const product = page.locator('[data-catalogue-item="bar"]:visible, [data-bar-article]:visible').first();
    if (await product.count()) {
        await product.click(); await settle(page);
        await page.locator('[wire\\:click^="quickCash"]').first().click().catch(() => {}); await settle(page);
        await page.click('[data-commit-action]').catch(() => {}); await settle(page);
        const bar = await oneLine(page).catch(() => ({ lines: 0 }));
        check(`${tag} bar: the last-sale line is one line`, bar.lines === 1, JSON.stringify(bar));
    } else {
        console.log(`SKIP ${tag} bar: no product to sell in the fixture`);
    }
    await page.close();
}

// Desktop Chrome, en-GB: a Spanish comma typed into Crear lote.
const desk = await browser.newPage({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });
await signIn(desk, { account: 'owner' });
await desk.goto(`${BASE}/batches/create`, { waitUntil: 'networkidle' });
await desk.locator('.fi-fo-field:has(label[for="form.genetic_id"]) .fi-select-input').first().click();
await desk.locator('[role="option"]:visible').first().click(); await settle(desk);
const sede = desk.locator('.fi-fo-field:has(label[for="form.location_id"]) .fi-select-input').first();
if (await sede.count()) { await sede.click(); await desk.locator('[role="option"]:visible', { hasText: 'Central Branch' }).first().click(); await desk.keyboard.press('Escape'); await settle(desk); }
await desk.fill('input[id="form.label"]', 'Coma 306');
await desk.locator('input[id="form.grams"]').pressSequentially('1000,01');
const typed = await desk.inputValue('input[id="form.grams"]');
check('desktop Chrome keeps a typed "1000,01"', typed === '1000,01', typed);
await desk.fill('input[id="form.sale_price_eur"]', '10,50');
await desk.click('button[type="submit"]:has-text("Crear")'); await settle(desk);
const confirm = desk.locator('.fi-modal-window button:has-text("Continuar")');
if (await confirm.count()) { await confirm.first().click(); await settle(desk); }
await desk.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
const row = ((await desk.locator('.fi-ta-row', { hasText: 'Coma 306' }).first().textContent().catch(() => '')) ?? '').replace(/\s+/g, ' ');
check('the batch reads "1000,01 g" on the list', row.includes('1000,01 g') && ! /\d\.\d{2} g/.test(row), row.slice(0, 120));
await desk.screenshot({ path: `${OUT}/batches-list.png` });

// Recuento: no "In system".
await desk.locator('.fi-ta-row', { hasText: 'Coma 306' }).first().locator('button[title="Open actions"], button[title="Abrir acciones"], .fi-icon-btn:visible').last().click();
await desk.locator('.fi-dropdown-list-item:visible', { hasText: 'Recuento' }).first().click(); await settle(desk);
const modal = (await desk.locator('.fi-modal-window').last().innerHTML().catch(() => '')) ?? '';
check('the Recuento dialog has no "In system"', modal.includes('En sistema') && ! modal.includes('In system'));
await desk.screenshot({ path: `${OUT}/recount.png` });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
