// Prompt 336 — *Ajustar precio*'s two fields each take the basket column's full width. Freshly seeded demo DB (DBFILE),
// as the manager (333's «Aprobado por responsable» pre-filled), at 820×1180 and 1180×820, in Spanish and in English:
//   - the reason reads in full (scrollWidth ≤ clientWidth) with the pre-filled text;
//   - both inputs are at least 44 px tall and 16 px text (no zoom on focus);
//   - they are stacked (the reason's top is below the amount's bottom);
//   - no button row in the basket column clips its label;
//   - 333's pin: the adjustment still moves the button and «Justo».
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/336';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(500); };

for (const [locale, approved, justo] of [['es', 'Aprobado por responsable', 'Justo'], ['en', 'Manager approved', 'Exact']]) {
    sql(`update users set locale = '${locale}' where email like 'manager@%'`);
    for (const [w, h] of [[820, 1180], [1180, 820]]) {
        const tag = `${locale} ${w}x${h}`;
        const page = await (await browser.newContext({ viewport: { width: w, height: h } })).newPage();
        await signInToCounter(page, '/counter/till', { account: 'manager' });
        if (await page.locator('input[wire\\:model="floatInput"]').count()) {
            await page.fill('input[wire\\:model="floatInput"]', '50');
            await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
        }
        await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
        await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle(page);
        await page.click('[data-member-lookup-result]'); await settle(page);
        await page.locator('[data-catalogue-item="genetics"]:is([data-type="FLOWER"],[data-type="HASH"]):not([disabled]):visible').first().click(); await settle(page);
        await page.locator('[data-weight-pad] button', { hasText: /^2$/ }).click();
        await page.click('[data-add-line]'); await settle(page);
        await page.click('[data-price-override-toggle]'); await page.waitForTimeout(300);
        await page.locator('#price-override-reason').scrollIntoViewIfNeeded();

        const m = await page.evaluate(() => {
            const box = (el) => { const r = el.getBoundingClientRect(); return { top: r.top, bottom: r.bottom, height: r.height, width: r.width, sw: el.scrollWidth, cw: el.clientWidth, font: parseFloat(getComputedStyle(el).fontSize), value: el.value }; };
            const clipped = [...document.querySelectorAll('[data-cart-column] button:not([hidden])')]
                .filter((b) => b.offsetParent !== null && b.scrollWidth > b.clientWidth + 1)
                .map((b) => b.innerText.trim().slice(0, 30));
            return { amount: box(document.getElementById('price-override-amount')), reason: box(document.getElementById('price-override-reason')), clipped };
        });
        check(`${tag}: the reason reads «${approved}» in full`, m.reason.value === approved && m.reason.sw <= m.reason.cw, `value «${m.reason.value}» ${m.reason.sw}/${m.reason.cw}px`);
        check(`${tag}: both inputs ≥ 44 px tall, 16 px text`, m.amount.height >= 44 && m.reason.height >= 44 && m.amount.font >= 16 && m.reason.font >= 16, `${m.amount.height}/${m.reason.height}px, ${m.amount.font}/${m.reason.font}px`);
        check(`${tag}: stacked, each the column's width`, m.reason.top >= m.amount.bottom && Math.abs(m.reason.width - m.amount.width) < 2, `amount ${Math.round(m.amount.width)}px, reason ${Math.round(m.reason.width)}px`);
        check(`${tag}: no button in the basket column clips its label`, m.clipped.length === 0, JSON.stringify(m.clipped));
        await page.screenshot({ path: `${OUT}/adjust-${locale}-${w}x${h}.png` });

        await page.fill('#price-override-amount', '5'); await page.press('#price-override-amount', 'Enter'); await settle(page);
        await page.getByRole('button', { name: justo, exact: true }).click(); await settle(page);
        const button = (await page.locator('[data-commit-action]').innerText()).replace(/ /g, ' ');
        check(`${tag}: 333 pin — the button and «${justo}» follow the adjustment`, /(5[.,]00 €|€5[.,]00)/.test(button) && (await page.inputValue('#pos-cash-tendered')) === '5,00', button.replace(/\s+/g, ' '));
        await page.context().close();
    }
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
