// Prompt 285 — staff hours on the dashboard and on the "Horas del personal" report, driven through the REAL app.
//
//   Run with counter-session.mjs's usual env (base URL + the account to sign in as) and ROLE=owner|manager|staff.
//
// ROLE=owner shoots the dashboard (rollup + each sede) and the report (this month + a custom 90 days) at 1440 and
// 820×1180, light and dark; ROLE=manager checks only their sede appears; ROLE=staff checks none of it appears.
import { chromium } from 'playwright';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/285';
const ROLE = process.env.ROLE ?? 'owner';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
check('sign in', await signIn(page));

async function sede(label) {
    const select = page.locator('select[aria-label="Sede activa"], select[aria-label="Active location"]').first();
    if (! await select.count()) {
        return false;
    }
    const value = await select.evaluate((el, text) => [...el.options].find((o) => o.textContent.trim() === text)?.value ?? null, label);
    if (value === null) {
        return false;
    }
    await select.selectOption(value);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800);

    return true;
}

async function shoot(name, { width = 1440, height = 900, dark = false, full = true } = {}) {
    await page.setViewportSize({ width, height });
    await page.emulateMedia({ colorScheme: dark ? 'dark' : 'light' });
    await page.evaluate((d) => document.documentElement.classList.toggle('dark', d), dark);
    await page.waitForTimeout(900); // charts re-draw
    await page.screenshot({ path: `${OUT}/${name}-${width}${dark ? '-dark' : ''}.png`, fullPage: full });
}

const noHScroll = async () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);

if (ROLE === 'owner') {
    for (const [label, key] of [['Todas las sedes', 'rollup'], ['Central Branch', 'central'], ['North Branch', 'north']]) {
        await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
        check(`switch to ${label}`, await sede(label));
        await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1500);
        const rows = await page.locator('[data-staff-now-row]').count();
        check(`${key}: Personal ahora present`, await page.locator('[data-staff-now]').count() === 1, `rows=${rows}`);
        check(`${key}: hours chart present`, await page.locator('[data-staff-hours-chart] canvas').count() >= 1 || await page.locator('[data-staff-hours-chart]').count() === 1);
        check(`${key}: open-shift alert`, await page.locator('[data-alert="staff_open_shifts"]').count() <= 1);
        await page.locator('button:has-text("Este mes")').first().click();
        await page.waitForTimeout(2500);
        await shoot(`dashboard-${key}-month`);
        if (key === 'rollup') {
            await shoot(`dashboard-${key}-month`, { dark: true });
            await shoot(`dashboard-${key}-month`, { width: 820, height: 1180 });
            check('820: no horizontal scroll', await noHScroll());
            await page.setViewportSize({ width: 1440, height: 900 });
        }
    }

    // The report, in the rollup: this month, then a custom 90 days.
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await sede('Todas las sedes');
    await page.goto(`${BASE}/informes/horas-del-personal`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);
    check('report: 4 stat cards', await page.locator('[data-staff-cards] .csc-card').count() === 4);
    check('report: 2 heatmaps', await page.locator('.csc-heat-pair .csc-section').count() === 2);
    const pair = await page.locator('.csc-heat-pair .csc-section').evaluateAll((els) => els.map((e) => e.getBoundingClientRect().top));
    check('report 1440: heatmaps side by side', pair.length === 2 && Math.abs(pair[0] - pair[1]) < 4, JSON.stringify(pair));
    await shoot('report-month');
    await shoot('report-month', { dark: true });
    await shoot('report-month', { width: 820, height: 1180 });
    const stacked = await page.locator('.csc-heat-pair .csc-section').evaluateAll((els) => els.map((e) => e.getBoundingClientRect().top));
    check('report 820: heatmaps stacked', stacked.length === 2 && stacked[1] > stacked[0] + 50, JSON.stringify(stacked));
    check('report 820: no horizontal scroll', await noHScroll());
    await shoot('report-month', { width: 820, height: 1180, dark: true });

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.locator('button:has-text("Personalizado")').first().click();
    await page.waitForTimeout(800);
    await page.locator('input[type="date"]').nth(0).fill('2026-07-01');
    await page.locator('input[type="date"]').nth(1).fill('2026-09-28');
    await page.locator('input[type="date"]').nth(1).dispatchEvent('change');
    await page.waitForTimeout(3000);
    check('report 90 days: grouped by week', (await page.content()).includes('Agrupadas por semana'));
    await shoot('report-90d');
    // Each name links to its registro, filtered to that person and month.
    const href = await page.locator('.csc-rep-link').first().getAttribute('href');
    check('report: name links to the registro for that person', href !== null && href.includes('registro-de-jornada') && href.includes('personId='), href ?? '');
} else if (ROLE === 'manager') {
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    const html = await page.content();
    check('manager: Personal ahora present', await page.locator('[data-staff-now]').count() === 1);
    check('manager: no North staff on the dashboard', ! html.includes('Luis Gómez'));
    await page.locator('button:has-text("Este mes")').first().click();
    await page.waitForTimeout(2500);
    await shoot('dashboard-manager-month');
    await page.goto(`${BASE}/informes/horas-del-personal`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);
    const report = await page.content();
    check('manager report: Central staff present', report.includes('Marta Ruiz'));
    check('manager report: no North staff', ! report.includes('Luis Gómez'));
    check('manager report: no scope choice beyond their sede', await page.locator('select.csc-select option').count() <= 1);
    await shoot('report-manager');
} else {
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    check('staff: never on the dashboard', await page.locator('[data-staff-now], [data-staff-hours-chart], [data-alert^="staff_"]').count() === 0, page.url());
    const response = await page.goto(`${BASE}/informes/horas-del-personal`);
    check('staff: report refused', (response?.status() ?? 0) === 403 || ! page.url().includes('horas-del-personal'), String(response?.status()));
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
