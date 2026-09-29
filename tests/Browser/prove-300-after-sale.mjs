// Prompt 300 — after a sale, one small line instead of a receipt-and-void panel. Real app, throwaway database.
// Per orientation: record a contribution → one line, no open void form, no full-width email button; Opciones → the
// receipt sheet opens and closes; Anular… → the sheet, Anular disabled until a reason, then voided; record another, add a
// line → the line is gone.
import { chromium } from 'playwright';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/300';
const scheme = process.env.SCHEME ?? 'light';
// A socio with today's allowance left, per orientation (two 1 g contributions each).
const MEMBERS = (process.env.MEMBERS ?? 'M-00027,M-00028').split(',');
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();

for (const [i, [w, h]] of [[1180, 820], [820, 1180]].entries()) {
    const who = MEMBERS[i] ?? MEMBERS[0];
    const tag = `${w}x${h}-${scheme}`;
    const page = await browser.newPage({ viewport: { width: w, height: h }, colorScheme: scheme });
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };

    await signInToCounter(page, '/counter/pos', { sede: 'Central Branch' });
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    if (page.url().includes('/counter/till')) {
        await page.fill('input[wire\\:model="floatInput"]', '50');
        await page.click('form[wire\\:submit="open"] button[type="submit"]');
        await settle();
        await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    }
    const change = page.locator('[data-change-member]');
    if (await change.count()) { await change.click(); await settle(); const yes = page.locator('[data-confirm-discard-yes]'); if (await yes.count()) { await yes.click(); await settle(); } }

    const contribute = async () => {
        // 299: with a socio still chosen the search is hidden; staff press *Cambiar socio* first.
        if (await page.locator('[data-change-member]').count()) {
            await page.click('[data-change-member]'); await settle();
            const yes = page.locator('[data-confirm-discard-yes]');
            if (await yes.count()) { await yes.click(); await settle(); }
        }
        await page.fill('#member-lookup', who); await page.press('#member-lookup', 'Enter'); await settle();
        await page.click('[data-member-lookup-result]'); await settle();
        await page.locator('[data-catalogue-item="genetics"]:not([disabled])').first().click(); await settle();
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
    };

    await contribute();
    const line = page.locator('[data-last-sale]');
    check(`${tag}: one line after the contribution`, await line.isVisible(), ((await line.textContent()) ?? '').replace(/\s+/g, ' ').trim().slice(0, 60));
    const lineBox = await line.boundingBox();
    check(`${tag}: the line stays compact (≤ 64 px)`, lineBox !== null && lineBox.height <= 64, `${Math.round(lineBox?.height ?? 0)}px`);
    check(`${tag}: the line shows the time in full`, /\d{1,2}:\d{2}/.test((await line.locator('p').innerText()) ?? ''));
    check(`${tag}: no open void form on the screen`, ! await page.locator('#pos-void-reason').isVisible());
    check(`${tag}: no full-width email button`, (await page.getByText('Enviar comprobante por email').count()) === 0);
    await page.screenshot({ path: `${OUT}/${tag}-line.png` });

    await page.click('[data-last-sale-options]'); await page.waitForTimeout(300);
    check(`${tag}: Opciones lists receipt and void`, await page.locator('[data-receipt-open]').isVisible() && await page.locator('[data-last-sale-void]').isVisible());
    await page.screenshot({ path: `${OUT}/${tag}-options.png` });
    await page.click('[data-receipt-open]'); await page.waitForTimeout(800);
    check(`${tag}: the receipt sheet opens`, await page.locator('[data-receipt-sheet] [role="dialog"]').isVisible());
    await page.click('[data-receipt-close]'); await page.waitForTimeout(400);

    if (! await page.locator('[data-last-sale-void]').isVisible()) { await page.click('[data-last-sale-options]'); await page.waitForTimeout(300); }
    await page.click('[data-last-sale-void]'); await page.waitForTimeout(400);
    const confirm = page.locator('[data-last-sale-void-confirm]');
    check(`${tag}: Anular is disabled until a reason`, await confirm.isDisabled());
    await page.fill('#pos-void-reason', 'Peso equivocado');
    await page.waitForTimeout(200);
    check(`${tag}: Anular is enabled once there is a reason`, await confirm.isEnabled());
    await page.screenshot({ path: `${OUT}/${tag}-void-sheet.png` });
    await confirm.click(); await settle();
    check(`${tag}: voided — the line is gone`, (await page.locator('[data-last-sale]').count()) === 0);

    await contribute();
    await page.locator('[data-catalogue-item="genetics"]:not([disabled])').first().click(); await settle();
    await page.locator('[data-weight-pad] button', { hasText: /^1$/ }).click();
    await page.click('[data-add-line]'); await settle();
    check(`${tag}: the next line clears it`, (await page.locator('[data-last-sale]').count()) === 0);
    check(`${tag}: no page errors`, errors.length === 0, errors.slice(0, 2).join(' | '));
    await page.close();
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
