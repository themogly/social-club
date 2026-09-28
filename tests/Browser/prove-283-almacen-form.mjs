// Prompt 283 — the location form follows the type selector: a store asks no opening hours and no accent colour, and
// its first section is "Datos de la ubicación". Save one, reopen it, and the three fields are still absent.
import { chromium } from 'playwright';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/283';
let ok = true;
const check = (name, pass, detail = '') => { console.log(`${pass ? 'PASS' : 'FAIL'} ${name} ${detail}`); ok &&= pass; };

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
check('sign in', await signIn(page));
await page.goto(`${BASE}/locations/create`, { waitUntil: 'networkidle' });

const shown = (text) => page.getByText(text, { exact: true }).first().isVisible().catch(() => false);
const fields = async () => ({
    opening: await shown('Hora de apertura'),
    closing: await shown('Hora de cierre'),
    accent: await shown('Color de acento'),
    // Required → the label carries a marker, so match it as a substring.
    cutoff: await page.locator('label:has-text("Corte del día operativo")').first().isVisible().catch(() => false),
    sedeHeading: await shown('Datos de la sede'),
    storeHeading: await shown('Datos de la ubicación'),
});

async function chooseKind(label) {
    const wrapper = page.locator('.fi-fo-field:has-text("Tipo de ubicación")').first();
    const native = wrapper.locator('select');
    if (await native.count()) {
        await native.selectOption({ label });
    } else {
        await wrapper.locator('button').first().click();
        await page.getByRole('option', { name: label, exact: true }).first().click();
    }
    await page.waitForTimeout(1200);
}

let f = await fields();
check('sede: hours, accent and "Datos de la sede" shown', f.opening && f.closing && f.accent && f.sedeHeading && ! f.storeHeading, JSON.stringify(f));
await page.screenshot({ path: `${OUT}/create-sede-1440.png`, fullPage: true });

await page.locator('input[id$="name"]').first().fill('Cultivo Norte');
await chooseKind('Almacén / cultivo');
f = await fields();
check('almacén: hours and accent gone, cutoff kept, "Datos de la ubicación"', ! f.opening && ! f.closing && ! f.accent && f.cutoff && f.storeHeading && ! f.sedeHeading, JSON.stringify(f));
check('almacén: store helper text on the cutoff', await page.getByText('Decide a qué día se asignan los movimientos de stock del almacén').isVisible());
await page.screenshot({ path: `${OUT}/create-almacen-1440.png`, fullPage: true });

await chooseKind('Sede');
f = await fields();
check('back to sede: everything returns', f.opening && f.closing && f.accent && f.sedeHeading, JSON.stringify(f));
await chooseKind('Almacén / cultivo');

await page.getByRole('button', { name: 'Crear', exact: true }).click();
await page.waitForURL((u) => /\/locations\/[^/]+\/edit$/.test(u.pathname), { timeout: 15000 }).catch(() => {});
await page.waitForLoadState('networkidle');
check('saved and on the edit page', /\/locations\/[^/]+\/edit$/.test(new URL(page.url()).pathname), page.url());
await page.reload({ waitUntil: 'networkidle' });
f = await fields();
check('reopened store: hours and accent absent, cutoff present', ! f.opening && ! f.closing && ! f.accent && f.cutoff && f.storeHeading, JSON.stringify(f));
await page.screenshot({ path: `${OUT}/edit-almacen-1440.png`, fullPage: true });

await page.setViewportSize({ width: 390, height: 844 });
await page.emulateMedia({ colorScheme: 'dark' });
await page.reload({ waitUntil: 'networkidle' });
await page.screenshot({ path: `${OUT}/edit-almacen-390-dark.png`, fullPage: true });

await page.setViewportSize({ width: 1440, height: 900 });
await page.emulateMedia({ colorScheme: 'light' });
await page.goto(`${BASE}/locations`, { waitUntil: 'networkidle' });
await page.screenshot({ path: `${OUT}/list-1440.png`, fullPage: true });
const storeRow = page.locator('tr:has-text("Cultivo Norte")').first();
check('list: no "Sin precios" warning on the store', ! (await storeRow.innerText()).includes('Sin precios'));

await browser.close();
process.exit(ok ? 0 : 1);
