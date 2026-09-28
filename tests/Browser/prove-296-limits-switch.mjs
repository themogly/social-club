// Prompt 296 — the consumption-limits switch in a real browser at 1180×820, as the owner (throwaway DB):
//   on with the modal's defaults (3,5 g / 100 g, its counts shown) → the counter shows the allowance and blocks 10 g;
//   off → no limit anywhere on the counter, the same 10 g commits, and the dashboard's legal stock ceiling is unchanged.
import { chromium } from 'playwright';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = process.env.OUT ?? 'storage/app';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
page.setDefaultTimeout(15000);
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };

const ceiling = async () => {
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    return (await page.locator('text=/socios activos ×/').allTextContents()).join(' | ').replace(/\s+/g, ' ');
};
const toggle = () => page.locator('label:has-text("Aplicar límites de consumo")').locator('xpath=..').locator('button[role="switch"], input[type="checkbox"]').first();
const modal = () => page.locator('.fi-modal-window:visible');

async function serveTenGrams(tag) {
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    if (page.url().includes('/counter/till')) {
        await page.fill('input[wire\\:model="floatInput"]', '50');
        await page.click('form[wire\\:submit="open"] button[type="submit"]');
        await settle();
        await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    }
    const close = page.locator('[wire\\:click^="clearMember"]').first();
    if (await close.count()) { await close.click().catch(() => {}); await settle(); }
    await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle();
    await page.click('[data-member-lookup-result]'); await settle();
    const fee = await page.$('form[wire\\:submit="collectMemberFee"] button[type="submit"]');
    if (fee) { await fee.click(); await settle(); }
    const html = await page.content();
    await page.locator('[data-catalogue-item="genetics"]:not([disabled]):visible').first().click(); await settle();
    for (const d of ['1', '0']) await page.locator('[data-weight-pad] button', { hasText: new RegExp(`^${d}$`) }).click();
    await page.click('[data-add-line]'); await settle();
    // Refused at "Añadir" (over the limit) — the block, before any money is asked for.
    if (await page.locator('[wire\\:click^="removeLine"]').count() === 0) {
        await page.screenshot({ path: `${OUT}/prove-296-${tag}.png` });
        return { html, committed: false, text: (await page.locator('body').textContent()).replace(/\s+/g, ' ') };
    }
    const pad = page.locator('[data-signature-canvas]');
    if (await pad.count()) {
        const box = await pad.boundingBox();
        await page.mouse.move(box.x + 20, box.y + 30); await page.mouse.down();
        await page.mouse.move(box.x + 120, box.y + 80, { steps: 8 }); await page.mouse.up();
        await page.click('[data-signature-save]'); await settle();
    }
    await page.locator('[wire\\:click^="quickCash"]').first().click(); await settle();
    await page.click('[data-commit-action]'); await settle();
    await page.screenshot({ path: `${OUT}/prove-296-${tag}.png` });
    return { html, committed: (await page.locator('[wire\\:click^="removeLine"]').count()) === 0, text: (await page.locator('body').textContent()).replace(/\s+/g, ' ') };
}

check('sign in', await signInToCounter(page, '/counter/pos'));

async function flip() {
    await page.goto(`${BASE}/manage-settings`, { waitUntil: 'networkidle' });
    await toggle().click(); await settle();
    return (await modal().textContent().catch(() => '')).replace(/\s+/g, ' ');
}

// Whatever state the DB is in, start from ON with the prompt's defaults: 3,5 g a day, 100 g a month.
let text = await flip();
if (/Desactivar límites/.test(text)) {
    await modal().locator('button', { hasText: /^\s*Desactivar\s*$/ }).click(); await settle();
    text = await flip();
}
check('switching on asks for the defaults, with the counts', /Activar límites de consumo/.test(text) && /sin límite propio/.test(text) && /superaría/.test(text), text.slice(0, 220));
await page.screenshot({ path: `${OUT}/prove-296-enable-modal.png` });
await modal().locator('input[id$="daily_limit_g"]').fill('3.5');
await modal().locator('input[id$="monthly_limit_g"]').fill('100');
await modal().locator('button', { hasText: /^\s*Activar\s*$/ }).click(); await settle();

const on = await serveTenGrams('on');
check('on: the allowance shows', on.html.includes('data-member-allowance'));
check('on: 10 g is blocked', ! on.committed, on.text.match(/(Se requiere[^.]*\.|Supera[^.]*\.|límite[^.]*\.)/)?.[0] ?? '');

// --- Switch OFF: the same 10 g, for a socio now far over 3,5 g a day ------------------------------------------------
const ceilingBefore = await ceiling();
text = await flip();
check('switching off asks first', /dejará de comprobar límites/.test(text));
await modal().locator('button', { hasText: /^\s*Desactivar\s*$/ }).click(); await settle();

const off = await serveTenGrams('off');
check('off: the counter shows no allowance', ! off.html.includes('data-member-allowance') && ! off.html.includes('Restante hoy'));
check('off: 10 g commits with no block or override', off.committed && ! /autorización de un responsable/.test(off.text));
check('off: the legal stock ceiling is unchanged', (await ceiling()) === ceilingBefore, ceilingBefore.slice(0, 140));

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
