// Prompt 352 — the last two decimal commas on the counter. Freshly seeded demo DB (DBFILE), owner, 1180×820, Spanish and
// English: choose a strain — the quick buttons read «3.5 g»; add 2 g and tap «Justo» — the cash box reads the total with a
// point; tap 5 € — it reads that plus 5.00.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/352';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(500); };
const cents = (s) => Math.round(Number((s.match(/(\d+[.,]\d{2})/) ?? [])[1]?.replace(',', '.') ?? NaN) * 100);

const page = await (await browser.newContext({ viewport: { width: 1180, height: 820 } })).newPage();
page.on('pageerror', (e) => errors.push(e.message));
await signInToCounter(page, '/counter/pos', { account: 'owner', sede: 'Central Branch' });

sql("update users set locale = 'es' where email like 'owner@%'");
for (const locale of ['es', 'en']) {
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    if (await page.locator('input[wire\\:model="floatInput"]').count()) {
        await page.fill('input[wire\\:model="floatInput"]', '50'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
        await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    }
    if (await page.locator('#member-lookup').isVisible().catch(() => false)) {
        await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle(page);
        await page.click('[data-member-lookup-result]'); await settle(page);
    }
    await page.locator('[data-catalogue-item="genetics"]:is([data-type="FLOWER"],[data-type="HASH"]):not([disabled]):visible').first().click(); await settle(page);

    const labels = (await page.locator('[data-weight-preset] span:first-child').allInnerTexts()).map((t) => t.trim());
    check(`${locale}: the quick buttons read with a point`, labels.includes('3.5 g') && ! labels.some((l) => l.includes(',')), JSON.stringify(labels));
    await page.screenshot({ path: `${OUT}/1-presets-${locale}.png` });

    await page.locator('[data-weight-pad] button', { hasText: /^2$/ }).click();
    await page.click('[data-add-line]'); await settle(page);
    const total = cents(await page.locator('[data-visit-total]').innerText());
    await page.getByRole('button', { name: /^(Justo|Exact)$/ }).click(); await settle(page);
    const justo = await page.inputValue('#pos-cash-tendered');
    check(`${locale}: «Justo» writes the total with a point`, /^\d+\.\d{2}$/.test(justo) && cents(justo) === total, `${justo} (total ${total})`);

    await page.locator('button[wire\\:click="quickCash(500)"]').click(); await settle(page);
    const plusFive = await page.inputValue('#pos-cash-tendered');
    check(`${locale}: «5 €» adds 5.00`, /^\d+\.\d{2}$/.test(plusFive) && cents(plusFive) === total + 500, plusFive);
    await page.screenshot({ path: `${OUT}/2-justo-plus-5-${locale}.png` });

    // Drop the basket and switch language for the second pass.
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    // The second pass in English: the operator's own language (the topbar switcher writes the same column).
    sql("update users set locale = 'en' where email like 'owner@%'");
}

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
