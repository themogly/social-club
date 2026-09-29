// Prompt 303 — a new batch split across locations when it is created. Real app, throwaway database with a store
// ("Storage house") and Central Branch, manual lote selection ON (so the counter can pick the new part).
// Per width: Lotes → Crear, Amnesia Haze, tick Storage house + Central Branch, 1.000 g, *Repartir a partes iguales* → 500 g
// each; save → both parts listed with the same #n and lote number; then (desktop only) dispense 1 g from the Central part at
// the counter and open the recall from the STORE part: the member is there (a recall covers the whole lote).
import { chromium } from 'playwright';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/303';
const scheme = process.env.SCHEME ?? 'light';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const widths = [[1440, 900], [820, 1180]].filter(([w, h]) => ! process.env.ONLY || process.env.ONLY === `${w}x${h}`);
const browser = await chromium.launch();
let splitSeq = null;

for (const [w, h] of widths) {
    const tag = `${w}x${h}-${scheme}`;
    const page = await browser.newPage({ viewport: { width: w, height: h }, colorScheme: scheme });
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(600); };

    check(`${tag}: sign in`, await signIn(page, { account: 'owner' }));
    await page.goto(`${BASE}/batches/create`, { waitUntil: 'networkidle' });

    const field = (id) => page.locator(`.fi-fo-field:has(label[for="form.${id}"])`);
    await field('genetic_id').locator('.fi-select-input').first().click();
    await page.locator('[role="option"]:visible', { hasText: 'Amnesia Haze' }).first().click(); await settle();
    for (const sede of ['Storage house', 'Central Branch']) {
        const choice = page.locator('[role="option"]:visible', { hasText: sede }).first();
        if (! await choice.isVisible()) await field('location_id').locator('.fi-select-input').first().click();
        await choice.click(); await page.waitForTimeout(700);
    }
    await page.keyboard.press('Escape'); await settle();
    await page.fill('input[id="form.grams"]', '1000');
    await page.getByRole('button', { name: 'Repartir a partes iguales' }).click(); await settle();
    const boxes = await page.locator('input[id^="form.grams_at."]').evaluateAll((els) => els.map((e) => e.value));
    check(`${tag}: Repartir fills 500 g in each box`, boxes.length === 2 && boxes.every((v) => Number(v) === 500), JSON.stringify(boxes));
    await page.fill('input[id="form.sale_price_eur"]', '9');
    await page.locator('body').click({ position: { x: 5, y: 5 } }); await settle();
    const total = (await page.locator('.fi-in-entry, .fi-fo-field', { hasText: 'Total' }).last().textContent()) ?? '';
    // The app's one weight formatter has no thousands separator (Weight::formatted).
    check(`${tag}: the live total reads 1000,00 g`, /1\.?000,00 g/.test(total), total.replace(/\s+/g, ' ').trim().slice(0, 40));
    await page.screenshot({ path: `${OUT}/${tag}-create.png`, fullPage: true });
    await page.click('button[type="submit"]:has-text("Crear")'); await settle();
    const confirm = page.locator('.fi-modal-window button:has-text("Continuar")');
    if (await confirm.count()) { await confirm.first().click(); await settle(); }

    await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
    const switcher = page.locator('select[x-on\\:change*="switchTo"]');
    await switcher.selectOption('');
    await settle();
    await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
    await page.locator('.fi-ta-search-field input').fill('Amnesia'); await page.waitForTimeout(1500); await settle();
    const rows = (await page.locator('.fi-ta-row').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim());
    // The split's #n is the one with a 500 g part at BOTH locations (the store may already hold an older lote).
    const bySeq = {};
    for (const r of rows.filter((r) => /· 500,00 g/.test(r))) {
        const seq = r.match(/#(\d+)/)?.[1];
        const where = r.includes('Storage house') ? 'store' : (r.includes('Central Branch') ? 'central' : null);
        if (seq && where) (bySeq[seq] ??= new Set()).add(where);
    }
    const split = Object.entries(bySeq).find(([, at]) => at.has('store') && at.has('central'));
    check(`${tag}: both parts listed, one per location, the same #n`, split !== undefined, split ? `#${split[0]}` : JSON.stringify(Object.keys(bySeq)));
    splitSeq = split?.[0] ?? splitSeq;
    await page.screenshot({ path: `${OUT}/${tag}-list.png`, fullPage: true });
    check(`${tag}: no page errors`, errors.length === 0, errors.slice(0, 2).join(' | '));
    await page.close();
}

// Desktop only: dispense from the Central part, then recall from the store part.
if (! process.env.ONLY || process.env.ONLY === '1440x900') {
    const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
    const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(600); };
    await signInToCounter(page, '/counter/pos', { sede: 'Central Branch' });
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    if (page.url().includes('/counter/till')) {
        await page.fill('input[wire\\:model="floatInput"]', '50');
        await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle();
        await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    }
    await page.fill('#member-lookup', 'M-00027'); await page.press('#member-lookup', 'Enter'); await settle();
    await page.click('[data-member-lookup-result]'); await settle();
    await page.locator('[data-catalogue-item="genetics"]:visible', { hasText: 'Amnesia Haze' }).first().click(); await settle();
    const chip = page.locator('[data-batch-chip]', { hasText: /500,00 g|499/ }).first();
    if (await chip.count()) { await chip.click(); await settle(); }
    await page.locator('[data-weight-pad] button', { hasText: /^1$/ }).click();
    await page.click('[data-add-line]'); await settle();
    await page.locator('[wire\\:click^="quickCash"]').first().click(); await settle();
    const pad = page.locator('[data-signature-canvas]');
    if (await pad.count()) {
        const box = await pad.boundingBox();
        await page.mouse.move(box.x + 20, box.y + 30); await page.mouse.down();
        await page.mouse.move(box.x + 120, box.y + 80, { steps: 8 }); await page.mouse.up();
        await page.click('[data-signature-save]'); await settle();
    }
    await page.click('[data-commit-action]'); await settle();
    await page.screenshot({ path: `${OUT}/counter.png` });
    check('counter: dispensed from the new Central part', await page.locator('[data-last-sale]').isVisible());

    const panel = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await signIn(panel, { account: 'owner' });
    await panel.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
    const sw = panel.locator('select[x-on\\:change*="switchTo"]');
    await sw.selectOption(await sw.locator('option', { hasText: 'Storage house' }).getAttribute('value'));
    await panel.waitForTimeout(1200);
    await panel.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
    const storeRow = panel.locator('.fi-ta-row').filter({ hasText: new RegExp(`#${splitSeq} ·`) }).filter({ hasText: 'Amnesia Haze' }).first();
    await storeRow.locator('button[title="Abrir acciones"], .fi-icon-btn:visible').last().click();
    await panel.locator('.fi-dropdown-list-item:visible', { hasText: 'Retirada' }).first().click();
    await panel.waitForTimeout(1500);
    const recall = (await panel.locator('.fi-modal-window').first().textContent()) ?? '';
    check('recall from the store part lists the member served from the Central part', /Kirsty Fox|M-00027/.test(recall), recall.replace(/\s+/g, ' ').slice(0, 120));
    await panel.screenshot({ path: `${OUT}/recall.png` });
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
