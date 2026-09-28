// Prompt 282 — the club's batch names on the REAL counter (manual lote chips, a 60-character name truncating inside the
// 44px floor) and in the panel (the Lotes search finds "verano", a transferred lote renamed shows on both halves).
//
// PRECONDITION (dev seed): at Central Branch `dispensary_batch_selection = manual`, and one strain with two open batches
// named "Cosecha verano 2026" and a 60-character name.
import { chromium } from 'playwright';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/282';
const STRAIN = process.env.STRAIN ?? 'Amnesia Haze';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };

const browser = await chromium.launch();

if (process.env.PART !== 'panel') {
    for (const [w, h] of [[1180, 820], [820, 1180]]) {
        const page = await browser.newPage({ viewport: { width: w, height: h } });
        check(`${w}x${h} counter sign in`, await signInToCounter(page, '/counter/pos'));
        if (page.url().includes('/counter/till')) {
            await page.fill('input[wire\\:model="floatInput"]', '50');
            await page.click('form[wire\\:submit="open"] button[type="submit"]');
            await page.waitForTimeout(1500);
            await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
        }
        const lookup = await page.$('#member-lookup');
        await lookup.fill(process.env.MEMBER ?? 'M-');
        await lookup.press('Enter');
        await page.waitForTimeout(1000);
        await page.click('[data-member-lookup-result]');
        await page.waitForTimeout(1500);
        // A fee due blocks the dispensary; collect it in cash, as staff would.
        const fee = await page.$('form[wire\\:submit="collectMemberFee"] button[type="submit"]');
        if (fee) { await fee.click(); await page.waitForTimeout(1500); }
        await page.locator('[data-product]', { hasText: STRAIN }).first().click();
        await page.waitForTimeout(1200);
        const chips = await page.$$('[data-batch-chip]');
        check(`${w}x${h} manual lote chips shown`, chips.length >= 2, `(${chips.length})`);
        const text = (await page.$$eval('[data-batch-chip]', (els) => els.map((e) => e.innerText.replace(/\s+/g, ' ')))).join(' | ');
        check(`${w}x${h} chips carry the names`, text.includes('Cosecha verano 2026') && text.includes('Cosecha de primavera'), text);
        const boxes = await page.$$eval('[data-batch-chip]', (els) => els.map((e) => {
            const r = e.getBoundingClientRect();
            const parent = e.parentElement.getBoundingClientRect();
            return { h: r.height, overflow: r.right > parent.right + 1 };
        }));
        check(`${w}x${h} chips keep 44px and stay inside the row`, boxes.every((b) => b.h >= 44 && ! b.overflow), JSON.stringify(boxes));
        const title = await page.getAttribute('[data-batch-chip]:has-text("primavera")', 'title');
        check(`${w}x${h} long name has the full title`, (title ?? '').includes('bancal número 12'), title ?? '');
        await page.locator('[data-batch-mode="manual"]').scrollIntoViewIfNeeded();
        await page.screenshot({ path: `${OUT}/${w}x${h}-chips.png` });
        await page.close();
    }
}

if (process.env.PART !== 'counter') {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    // A session that has adopted a sede keeps the panel shut until someone identifies at the PIN (267).
    check('panel sign in', await signInToCounter(page, '/counter'));
    await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
    check('on the Lotes list', page.url().endsWith('/batches'), page.url());
    await page.fill('input[type="search"]', 'verano');
    await page.waitForTimeout(1500);
    const rows = await page.$$eval('.fi-ta-row', (els) => els.map((e) => e.innerText.replace(/\s+/g, ' ')));
    check('Lotes search "verano" finds the named batch', rows.length >= 1 && rows.every((r) => /verano/i.test(r)), rows.join(' | ').slice(0, 300));
    await page.screenshot({ path: `${OUT}/panel-search.png` });

    // Edit it: the title is the display name; rename; the parts message when the lote has two parts.
    await page.locator('.fi-ta-row', { hasText: 'verano' }).first().locator('a').first().click();
    await page.waitForLoadState('networkidle');
    const h1 = await page.textContent('h1');
    check('edit title is the display name', /Cosecha verano 2026/.test(h1 ?? ''), h1 ?? '');
    await page.fill('input[id$="label"]', `Cosecha verano 2026 — ${Date.now() % 10000}`);
    await page.click('button[type="submit"]:has-text("Guardar"), button[type="submit"]:has-text("Save")');
    await page.waitForTimeout(2000);
    const toast = await page.$$eval('.fi-no-notification', (els) => els.map((e) => e.innerText.replace(/\s+/g, ' ')).join(' | '));
    check('rename reports where the name applies', /2 partes|2 parts/.test(toast), toast);
    await page.screenshot({ path: `${OUT}/panel-renamed.png` });
    await page.close();
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
