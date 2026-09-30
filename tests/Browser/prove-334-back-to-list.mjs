// Prompt 334 — saving returns to the list; every record page has «← {list}». Freshly seeded demo DB (DBFILE), as the
// owner, at 1440×900 and 820×1180:
//   1. edit a tier, Guardar → the tiers list;
//   2. open a batch, «← Lotes» → the batches list;
//   3. a member: «Guardar y seguir editando» stays, and the Descuentos tab assigns a discount right there;
//   4. a product with a changed field: «← Productos» asks first (the browser's leave-page prompt).
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/334';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(500); };
const path = (p) => new URL(p.url()).pathname;

for (const [w, h] of [[1440, 900], [820, 1180]]) {
    const tag = `${w}x${h}`;
    const page = await (await browser.newContext({ viewport: { width: w, height: h } })).newPage();
    await signIn(page);

    // 1. A tier: save → the list.
    const tier = sql('select id from membership_tiers order by name limit 1');
    await page.goto(`${BASE}/membership-tiers/${tier}/edit`, { waitUntil: 'networkidle' });
    const back = page.locator('[data-back-to-list]').first();
    const box = await back.boundingBox();
    check(`${tag}: the tier's edit page shows «← Tarifas», at least 44 px tall`, /Tarifas/.test(await back.innerText()) && box.height >= 44, `${box?.height}px`);
    await page.screenshot({ path: `${OUT}/1-tier-edit-${tag}.png` });
    await page.getByRole('button', { name: 'Guardar cambios', exact: true }).click(); await settle(page);
    check(`${tag}: Guardar lands on the tiers list`, path(page) === '/membership-tiers', path(page));

    // 2. A batch: «← Lotes».
    const batch = sql('select id from batches where deleted_at is null limit 1');
    await page.goto(`${BASE}/batches/${batch}/edit`, { waitUntil: 'networkidle' });
    await page.locator('[data-back-to-list]').first().click(); await settle(page);
    check(`${tag}: «← Lotes» goes to the batches list`, path(page) === '/batches', path(page));

    // 3. The member hub: save and keep editing, then a discount from the tab below.
    const member = sql("select id from members where member_no = 'M-00003'");
    await page.goto(`${BASE}/members/${member}/edit`, { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Guardar y seguir editando' }).click(); await settle(page);
    check(`${tag}: «Guardar y seguir editando» stays on the member`, path(page) === `/members/${member}/edit`, path(page));
    if (w === 1440) {
        const before = Number(sql(`select count(*) from member_discounts where member_id = '${member}'`));
        await page.getByRole('tab', { name: 'Descuentos' }).click(); await settle(page);
        await page.getByRole('button', { name: 'Asignar descuento' }).click(); await settle(page);
        const modal = page.locator('.fi-modal-window:visible').last();
        // A searchable Filament select (not native): open it, take the first discount.
        await modal.locator('.fi-select-input-btn, .fi-select-input [role="combobox"], .fi-select-input button').first().click();
        await page.locator('[role="option"]:visible, .fi-select-input-option:visible').first().click();
        const reason = modal.locator('textarea, input[id$="reason"]').first();
        if (await reason.count()) await reason.fill('Prueba 334');
        await modal.getByRole('button', { name: /Asignar|Enviar|Guardar/ }).last().click(); await settle(page);
        const after = Number(sql(`select count(*) from member_discounts where member_id = '${member}'`));
        check(`${tag}: a discount assigned from the tab right after saving`, after === before + 1 && path(page) === `/members/${member}/edit`, `${before} → ${after}`);
        await page.screenshot({ path: `${OUT}/3-member-discount-${tag}.png` });
    }

    // 4. A product, a changed field, «← Productos»: the leave-page prompt.
    const article = sql('select id from articles limit 1');
    await page.goto(`${BASE}/articles/${article}/edit`, { waitUntil: 'networkidle' });
    await page.locator('input[id$="name"]').first().fill('Cambiado sin guardar');
    await page.waitForTimeout(300);
    let asked = null;
    page.once('dialog', async (d) => { asked = d.type(); await d.dismiss(); });
    await page.locator('[data-back-to-list]').first().click().catch(() => {});
    await page.waitForTimeout(1200);
    check(`${tag}: «← Productos» with unsaved changes asks first, and staying keeps the form`, asked === 'beforeunload' && path(page) === `/articles/${article}/edit`, `${asked} ${path(page)}`);
    await page.screenshot({ path: `${OUT}/4-product-unsaved-${tag}.png` });
    await page.context().close();
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
