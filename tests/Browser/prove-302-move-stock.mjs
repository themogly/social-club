// Prompt 302 — moving stock is a visible button. Real app, throwaway database with a store ("Storage house") holding an
// Amnesia Haze batch and a batch of a DELETED strain ("Storage weed").
// Per width (1440×900, 820×1180): the store's list shows the row button (label at desktop, icon at tablet) and the deleted
// strain named "(eliminada)"; move part of the Amnesia batch with the ROW button; open it and move the rest with the
// HEADER button.
import { chromium } from 'playwright';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/302';
const scheme = process.env.SCHEME ?? 'light';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();

// ONLY=820x1180 runs one width (each run moves the store's stock, so give each width a fresh database copy).
const widths = [[1440, 900], [820, 1180]].filter(([w, h]) => ! process.env.ONLY || process.env.ONLY === `${w}x${h}`);
for (const [w, h] of widths) {
    const tag = `${w}x${h}-${scheme}`;
    const page = await browser.newPage({ viewport: { width: w, height: h }, colorScheme: scheme });
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(600); };
    const pickDestination = async (modal, label) => {
        const field = modal.locator('.fi-fo-field', { hasText: 'Destino' });
        const native = field.locator('select');
        if (await native.count()) { await native.selectOption({ label }); return; }
        await field.locator('.fi-select-input').first().click();
        await page.locator('[role="option"]:visible', { hasText: label }).first().click();
    };

    check(`${tag}: sign in`, await signIn(page, { account: 'owner' }));
    await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
    const switcher = page.locator('select[x-on\\:change*="switchTo"]');
    await switcher.selectOption(await switcher.locator('option', { hasText: 'Storage house' }).getAttribute('value'));
    await settle();
    await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });

    const rows = page.locator('.fi-ta-row');
    const deleted = rows.filter({ hasText: 'Storage weed (eliminada)' });
    check(`${tag}: the deleted strain's batch is named, not blank`, await deleted.count() === 1);
    check(`${tag}: …with its type`, /Flor/.test((await deleted.first().textContent()) ?? ''));
    const amnesia = rows.filter({ hasText: 'Amnesia Haze' }).first();
    const button = amnesia.locator('button, a').filter({ has: page.locator('svg') }).filter({ hasText: /Asignar a sede|^$/ }).first();
    const labelled = await amnesia.getByText('Asignar a sede', { exact: true }).isVisible().catch(() => false);
    check(`${tag}: the row button is outside the ⋮ (${w >= 1024 ? 'labelled' : 'icon'})`, w >= 1024 ? labelled : ! labelled);
    await page.screenshot({ path: `${OUT}/${tag}-list.png`, fullPage: true });

    // Row button → move 100 g to Central Branch.
    const grams = (text) => Number(((text ?? '').match(/(\d+)[.,]00 g\s+\S*\s*[\d,]+ €/) ?? [])[1] ?? NaN);
    const before = Number(((await amnesia.textContent()) ?? '').match(/Storage house\s+(\d+)[.,]00 g/)?.[1] ?? NaN);
    const trigger = amnesia.locator('[wire\\:click*="\'transfer\'"]:visible').first(); // labeledFrom renders an icon copy and a labelled copy
    const box = await trigger.boundingBox();
    check(`${tag}: the row button is a 44 px target`, box !== null && box.height >= 44 && box.width >= 44, `${Math.round(box?.width ?? 0)}×${Math.round(box?.height ?? 0)}`);
    await trigger.click(); await settle();
    const modal = page.locator('.fi-modal-window').last();
    await pickDestination(modal, 'Central Branch');
    await modal.locator('input[id$="quantity"]').fill('100');
    await page.screenshot({ path: `${OUT}/${tag}-row-modal.png` });
    await modal.getByRole('button', { name: 'Trasladar' }).click(); await settle();
    const remainingRow = (await rows.filter({ hasText: 'Amnesia Haze' }).first().textContent()) ?? '';
    const after = Number(remainingRow.match(/Storage house\s+(\d+)[.,]00 g/)?.[1] ?? NaN);
    check(`${tag}: the row move took 100 g from the store`, after === before - 100, `${before} → ${after}`);

    // Open the batch → header button → move everything left.
    await rows.filter({ hasText: 'Amnesia Haze' }).first().locator('a[href*="/edit"]').first().click(); await settle();
    check(`${tag}: the batch page shows what is left`, ((await page.locator('[data-batch-remaining]').textContent()) ?? '').includes(`${after},00 g`), `${after} g`);
    const header = page.locator('.fi-header').getByRole('button', { name: 'Asignar a sede' });
    check(`${tag}: the page has the move button in its header`, await header.isVisible());
    await page.screenshot({ path: `${OUT}/${tag}-page.png`, fullPage: true });
    await header.click(); await settle();
    const pageModal = page.locator('.fi-modal-window').last();
    await pickDestination(pageModal, 'Central Branch');
    await pageModal.locator('.fi-fo-field', { hasText: 'Todo lo que queda' }).locator('button[role="switch"]').click();
    await page.waitForTimeout(400);
    await pageModal.getByRole('button', { name: 'Trasladar' }).click(); await settle();
    const sede = await page.evaluate(() => { const w = document.querySelector('[id="form.location_id"]')?.closest('.fi-fo-field'); return w?.querySelector('.fi-select-input-value-label, .fi-select-input-btn')?.innerText ?? ''; });
    check(`${tag}: after moving everything the page shows the new location`, /Central Branch/.test(sede) && ! /Storage house/.test(sede), sede);
    await page.screenshot({ path: `${OUT}/${tag}-page-after.png`, fullPage: true });
    check(`${tag}: no page errors`, errors.length === 0, errors.slice(0, 2).join(' | '));
    await page.close();
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
