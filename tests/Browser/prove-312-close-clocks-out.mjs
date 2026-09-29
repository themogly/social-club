// Prompt 312 — closing the till clocks the CLOSER out, with an undo; everyone else is listed and uses their own PIN.
// Throwaway database (prep: staff and manager clocked in, staff may close, today's recount done). 1180×820:
//   1. staff close the till → "Salida fichada a las HH:MM" with *Deshacer*; undo it; the period is open again;
//   2. reopen and close again → the manager is listed under *Aún con jornada abierta*; a wrong PIN is refused, the
//      manager's own PIN clocks them out;
//   3. *Registro de jornada* shows both clock-outs, and the undone one struck through as *Anulado*.
import { chromium } from 'playwright';
import { BASE, accountPin, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/312';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(700); };
const openTill = async () => {
    if (await page.locator('input[wire\\:model="floatInput"]').count()) {
        await page.fill('input[wire\\:model="floatInput"]', '50');
        await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle();
        await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' }); // opening returns to where the counter was headed
    }
};
const closeTill = async () => {
    await page.click('[data-close-till]'); await settle();
    await page.fill('input[wire\\:model="countInput"]', '50');
    await page.click('form[wire\\:submit="submitCount"] button[type="submit"]'); await settle();
};

check('staff at the counter', await signInToCounter(page, '/counter/till', { account: 'staff' }));
await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
await openTill();
await closeTill();
const done = ((await page.locator('[data-clock-out-done]').textContent().catch(() => '')) ?? '').replace(/\s+/g, ' ').trim();
check('closing clocks the closer out, with Deshacer', /Salida fichada a las \d{2}:\d{2}\./.test(done) && await page.locator('[data-clock-out-undo]').isVisible(), done);
await page.screenshot({ path: `${OUT}/closed-clocked-out.png` });
await page.click('[data-clock-out-undo]'); await settle();
check('Deshacer takes it back', ! await page.locator('[data-clock-out-done]').count() && /Salida deshecha/.test(await page.content()));
await page.screenshot({ path: `${OUT}/undone.png` });

// Close again: the manager is still clocked in here.
await page.click('button[wire\\:click="finishClose"]').catch(() => {}); await settle();
await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
await openTill();
await closeTill();
const list = ((await page.locator('[data-still-clocked-in]').textContent().catch(() => '')) ?? '').replace(/\s+/g, ' ');
check('the manager is listed, the closer is not', /Aún con jornada abierta/.test(list) && /Club \(desde \d{2}:\d{2}\)/.test(list) && (list.match(/desde/g) ?? []).length === 1, list.trim().slice(0, 100));
await page.screenshot({ path: `${OUT}/still-clocked-in.png` });
await page.locator('[data-clock-out-other]').first().click(); await settle();
await page.fill('[data-other-pin]', '0000'); await page.click('[data-other-pin-confirm]'); await settle();
check('a wrong PIN is refused', await page.locator('[data-other-pin-feedback]').isVisible());
await page.fill('[data-other-pin]', accountPin('manager')); await page.click('[data-other-pin-confirm]'); await settle();
check('the manager\'s own PIN clocks them out', ! await page.locator('[data-still-clocked-in]').count() && /Salida fichada: Club\./.test(await page.content()));
await page.screenshot({ path: `${OUT}/manager-clocked-out.png` });

// The hours report, as the owner.
const panel = await browser.newPage({ viewport: { width: 1440, height: 900 } });
await signIn(panel, { account: 'owner' });
await panel.goto(`${BASE}/registro-de-jornada`, { waitUntil: 'networkidle' });
const report = (await panel.locator('body').innerText()).replace(/\s+/g, ' ');
// The report shows each period (it has no source column — the sources are read from the record by the sandbox check);
// the undone clock-out stays visible, struck through as *Anulado* (append-only, 281).
check('the hours report shows both clock-outs and the undone one as Anulado', (report.match(/Club Manager|Club Staff/g) ?? []).length >= 2 && report.includes('Anulado'), '');
await panel.screenshot({ path: `${OUT}/registro.png`, fullPage: true });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
