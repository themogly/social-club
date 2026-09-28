// Prompt 293 — one full visit on the real app, at both tablet orientations: socio → flower → bar → cash → commit.
// Behaviour must be exactly as before 293; this checks each step's on-screen result, the client-side filters, that the
// catalogue's stock figure follows the sale, and that nothing logs an error. Screenshots go to OUT (default storage/app).
import { chromium } from 'playwright';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = process.env.OUT ?? 'storage/app';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();

for (const [w, h] of [[1180, 820], [820, 1180]]) {
    const tag = `${w}x${h}`;
    const context = await browser.newContext({ viewport: { width: w, height: h } });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(e.message));
    const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(400); };

    await signInToCounter(page, '/counter/pos');
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    if (page.url().includes('/counter/till')) {
        await page.fill('input[wire\\:model="floatInput"]', '50');
        await page.click('form[wire\\:submit="open"] button[type="submit"]');
        await settle();
        await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    }
    // Start from an empty counter.
    const close = page.locator('[wire\\:click^="clearMember"]').first();
    if (await close.count()) {
        await close.click().catch(() => {}); await settle();
        const discard = page.locator('[wire\\:click="clearMember(true)"]').first();
        if (await discard.count()) { await discard.click().catch(() => {}); await settle(); }
    }

    await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle();
    await page.click('[data-member-lookup-result]'); await settle();
    const fee = await page.$('form[wire\\:submit="collectMemberFee"] button[type="submit"]');
    if (fee) { await fee.click(); await settle(); }

    const genetics = page.locator('[data-catalogue-item="genetics"]:visible');
    const shown = await genetics.count();
    check(`${tag}: the catalogue is on screen with the socio`, shown > 0, `${shown} genetics`);
    await page.screenshot({ path: `${OUT}/visit-293-${tag}-1-member.png` });

    await page.locator('input[aria-label="Buscar genética…"]').fill('Varie');
    await page.waitForTimeout(200);
    const narrowed = await genetics.count();
    await page.locator('input[aria-label="Buscar genética…"]').fill('');
    await page.waitForTimeout(200);
    check(`${tag}: search narrows in the browser and clears back`, narrowed <= shown && (await genetics.count()) === shown, `${shown} → ${narrowed} → ${await genetics.count()}`);

    const first = page.locator('[data-catalogue-item="genetics"]:not([disabled]):visible').first();
    const stockBefore = (await first.locator('.tabular-nums').last().textContent())?.trim();
    const name = (await first.locator('.truncate').first().textContent())?.trim();
    await first.click(); await settle();
    check(`${tag}: choosing a genetic opens the weight entry`, await page.locator('[data-add-line]').isVisible());
    await page.locator('[data-weight-pad] button', { hasText: /^1$/ }).click();
    await page.click('[data-add-line]'); await settle();
    check(`${tag}: the flower line is in the basket`, (await page.locator('[wire\\:click^="removeLine"]').count()) === 1);
    await page.screenshot({ path: `${OUT}/visit-293-${tag}-2-flower.png` });

    // The toggles say which option is on — and only that one.
    const pressed = async (sel) => page.$$eval(sel, (els) => els.filter((e) => e.classList.contains('bg-brand')).map((e) => e.dataset.sourceOption ?? e.dataset.layoutOption));
    await page.click('[data-layout-option="grid"]');
    await page.waitForTimeout(200);
    const cols = await page.$eval('[data-layout] [data-catalogue-item="genetics"]', (el) => getComputedStyle(el.parentElement).display);
    check(`${tag}: grid is a grid`, cols === 'grid' && JSON.stringify(await pressed('[data-layout-option]')) === '["grid"]', `${cols} ${JSON.stringify(await pressed('[data-layout-option]'))}`);
    await page.screenshot({ path: `${OUT}/visit-293-${tag}-2b-grid.png` });
    await page.click('[data-layout-option="list"]');
    await page.waitForTimeout(200);
    await page.click('[data-source-option="bar"]');
    await page.waitForTimeout(200);
    check(`${tag}: only Barra is pressed`, JSON.stringify(await pressed('[data-source-option]')) === '["bar"]', JSON.stringify(await pressed('[data-source-option]')));
    const articles = await page.locator('[data-catalogue-item="bar"]:visible').count();
    check(`${tag}: the Barra tab shows the bar catalogue with no request`, articles > 0 && (await genetics.count()) === 0, `${articles} articles`);
    await page.locator('[data-catalogue-item="bar"]:not([disabled]):visible').first().click(); await settle();
    check(`${tag}: the bar line is in the cart`, await page.locator('[data-cart-bar-section] li').count() >= 1);
    await page.screenshot({ path: `${OUT}/visit-293-${tag}-3-bar.png` });

    await page.locator('[wire\\:click^="quickCash"]').first().click(); await settle();
    const tendered = await page.inputValue('#pos-cash-tendered');
    check(`${tag}: quick cash fills the tendered figure`, tendered !== '', tendered);
    await page.screenshot({ path: `${OUT}/visit-293-${tag}-4-cash.png` });

    // Where the sede asks for the socio's signature, sign and save it first — as the operator would.
    const pad = page.locator('[data-signature-canvas]');
    if (await pad.count()) {
        const box = await pad.boundingBox();
        await page.mouse.move(box.x + 20, box.y + 30); await page.mouse.down();
        await page.mouse.move(box.x + 120, box.y + 80, { steps: 8 }); await page.mouse.up();
        await page.click('[data-signature-save]'); await settle();
    }
    await page.click('[data-commit-action]'); await settle();
    const flash = await page.locator('[role="status"], [data-flash]').allTextContents();
    check(`${tag}: the visit commits`, (await page.locator('[wire\\:click^="removeLine"]').count()) === 0, flash.join(' | ').slice(0, 80));
    await page.click('[data-source-option="genetics"]').catch(() => {});
    await page.waitForTimeout(200);
    const card = page.locator('[data-catalogue-item="genetics"]:visible', { hasText: name ?? '' }).first();
    const stockAfter = (await card.locator('.tabular-nums').last().textContent())?.trim();
    check(`${tag}: the catalogue's stock follows the sale`, stockAfter !== stockBefore, `${name}: ${stockBefore} → ${stockAfter}`);
    await page.screenshot({ path: `${OUT}/visit-293-${tag}-5-committed.png` });

    check(`${tag}: no console errors`, errors.length === 0, errors.slice(0, 3).join(' | '));
    await context.close();
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
