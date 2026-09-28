// Prompt 291 — Descuentos y ajustes on the real app (throwaway database with the fixture seeded across both sedes):
// the owner's report in the rollup and per sede, sort by discretionary %, one operator's detail, the CSV, the dashboard
// alert at a 1 % threshold landing on the 7-day report; the manager sees only their sede; staff are refused.
import { chromium } from 'playwright';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/291';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();

for (const [w, h] of [[1440, 900], [820, 1180]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, acceptDownloads: true });
    const page = await ctx.newPage();
    check(`${w} owner signs in`, await signIn(page, { account: 'owner' }));
    await page.goto(`${BASE}/informes/descuentos`, { waitUntil: 'networkidle' });
    const text = await page.textContent('body');
    check(`${w} rollup shows both sedes' discounts`, text.includes('Terapéutico') && text.includes('Norte −5%'));
    check(`${w} the five cards`, ['Descuentos de socio', 'Ajustes de precio', 'Cuotas condonadas', 'Líneas manuales', 'Total cedido'].every((t) => text.includes(t)));
    await page.screenshot({ path: `${OUT}/${w}-rollup.png`, fullPage: true });

    if (w === 1440) {
        await page.locator('th button:has-text("Discrecional (%)"), th:has-text("Discrecional (%)")').first().click().catch(() => {});
        await page.waitForTimeout(800);
        await page.locator('a:has-text("Club Staff")').first().click();
        await page.waitForLoadState('networkidle');
        check('an operator name opens their detail', page.url().includes('operator='), page.url());
        const detail = await page.textContent('body');
        check('…filtered to that person', detail.includes('Mechero') && ! detail.includes('Cliente habitual'));
        await page.screenshot({ path: `${OUT}/operator-detail.png`, fullPage: true });

        const [download] = await Promise.all([
            page.waitForEvent('download', { timeout: 15000 }).catch(() => null),
            page.locator('button:has-text("CSV"), a:has-text("CSV")').first().click(),
        ]);
        check('the CSV downloads', !! download, download?.suggestedFilename() ?? '');

        await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
        const alert = page.locator('a[href*="informes/descuentos?days=7"]').first();
        check('the dashboard alert appears at 1 %', await alert.count() > 0);
        await alert.click();
        await page.waitForLoadState('networkidle');
        check('…and lands on the 7-day report', page.url().includes('informes/descuentos'), page.url());
    }
    await ctx.close();
}

const mgr = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
check('manager signs in', await signIn(mgr, { account: 'manager' }));
await mgr.goto(`${BASE}/informes/descuentos`, { waitUntil: 'networkidle' });
const mText = await mgr.textContent('body');
check('the manager sees only their sede', mText.includes('Terapéutico') && ! mText.includes('Norte −5%'));

const staff = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
await signIn(staff, { account: 'staff' });
const res = await staff.goto(`${BASE}/informes/descuentos`, { waitUntil: 'networkidle' });
check('staff are refused', res.status() === 403 || ! staff.url().includes('informes/descuentos'), `${res.status()} ${staff.url()}`);

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
