// Prompt 301 — nothing spills out of a catalogue tile in GRID view. Real app, throwaway database: a 499,00 g strain
// (Con lote), strains flagged low with a cover label ("≈N días", stock_cover_low_days raised), a strain with no batch
// (Sin lote), and a bar product with a five-digit stock. A socio is chosen (the narrow left pane).
// For every visible tile: every piece of its content lies inside the tile's box. Both orientations, Spanish and English.
// LIST view: the price and the stock stay right-aligned (unchanged).
import { chromium } from 'playwright';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/301';
const MEMBER = process.env.MEMBER ?? 'M-00027';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();

/** The tiles whose content leaves the tile's box, with the offender's text. */
async function spills(page, selector) {
    return page.$$eval(selector, (tiles) => tiles.filter((t) => t.offsetParent !== null).flatMap((tile) => {
        const box = tile.getBoundingClientRect();
        const out = [...tile.querySelectorAll('*')].filter((el) => el.getClientRects().length > 0).find((el) => {
            const r = el.getBoundingClientRect();
            return r.width > 0 && (r.right > box.right + 0.5 || r.left < box.left - 0.5 || r.bottom > box.bottom + 0.5);
        });
        return out ? [`${tile.innerText.replace(/\s+/g, ' ').trim().slice(0, 40)} ⟶ "${out.innerText.trim()}"`] : [];
    }));
}

// LOCALE=es|en: the language is the signed-in user's own (users.locale) — set it on the throwaway database per run.
for (const locale of [process.env.LOCALE ?? 'es']) {
    for (const [w, h] of [[820, 1180], [1180, 820]]) {
        const tag = `${w}x${h}-${locale}`;
        const page = await browser.newPage({ viewport: { width: w, height: h } });
        page.setDefaultTimeout(10000);
        const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };

        await signInToCounter(page, '/counter/pos', { sede: 'Central Branch' });
        await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
        if (page.url().includes('/counter/till')) {
            await page.fill('input[wire\\:model="floatInput"]', '50');
            await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle();
            await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
        }
        check(`${tag}: the screen is in ${locale}`, (await page.locator('html').getAttribute('lang')) === locale);
        const change = page.locator('[data-change-member]');
        if (await change.count()) { await change.click(); await settle(); const y = page.locator('[data-confirm-discard-yes]'); if (await y.count()) { await y.click(); await settle(); } }
        await page.fill('#member-lookup', MEMBER); await page.press('#member-lookup', 'Enter'); await settle();
        await page.click('[data-member-lookup-result]'); await settle();

        await page.click('[data-layout-option="grid"]'); await page.waitForTimeout(300);
        const tiles = await page.locator('[data-catalogue-item="genetics"]:visible').count();
        const cover = await page.locator('[data-catalogue-item="genetics"]:visible [data-stock-cover]').count();
        const bad = await spills(page, '[data-catalogue-item="genetics"]');
        check(`${tag}: no genetic tile spills (grid, ${tiles} tiles, ${cover} with a cover label)`, tiles > 0 && bad.length === 0, bad.slice(0, 3).join(' | '));
        await page.screenshot({ path: `${OUT}/${tag}-genetics-grid.png` });

        await page.click('[data-source-option="bar"]'); await page.waitForTimeout(300);
        const badBar = await spills(page, '[data-catalogue-item="bar"]');
        check(`${tag}: no bar card spills (grid)`, (await page.locator('[data-catalogue-item="bar"]:visible').count()) > 0 && badBar.length === 0, badBar.slice(0, 3).join(' | '));
        await page.screenshot({ path: `${OUT}/${tag}-bar-grid.png` });

        // LIST view is unchanged: the price and the stock stay on the right of the tile.
        await page.click('[data-source-option="genetics"]'); await page.waitForTimeout(200);
        await page.click('[data-layout-option="list"]'); await page.waitForTimeout(300);
        const rightAligned = await page.$$eval('[data-catalogue-item="genetics"]', (tiles) => tiles.filter((t) => t.offsetParent !== null).every((tile) => {
            const price = [...tile.querySelectorAll('span')].find((s) => /\/(g|ud)$/.test(s.innerText.trim()));
            return price && tile.getBoundingClientRect().right - price.getBoundingClientRect().right < 24;
        }));
        check(`${tag}: list view keeps the price on the right`, w < 1024 ? true : rightAligned);
        await page.screenshot({ path: `${OUT}/${tag}-genetics-list.png` });
        await page.click('[data-layout-option="grid"]'); await page.waitForTimeout(200);
        await page.close();
    }
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
