// Prompt 308 — as the owner on a throwaway database (some batches emptied, "Lemon Test" deleted with no stock,
// "Amnesia Haze" holding stock): *Lotes* hides the empty batches with a hint, newest first, and *Ver todos* shows them;
// deleting a strain with stock is refused saying where; a second "amnesia haze" is refused; *Genéticas* filtered by
// *Solo registros eliminados* restores Lemon Test. ONLY=1440x900|820x1180 picks a width (default 1440x900).
import { chromium } from 'playwright';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/308';
const [w, h] = (process.env.ONLY ?? '1440x900').split('x').map(Number);
const tag = `${w}x${h}-${process.env.SCHEME ?? 'light'}`;
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${tag}: ${name} ${detail}`); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: w, height: h }, colorScheme: process.env.SCHEME ?? 'light' });
page.setDefaultTimeout(10000);
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(600); };

check('sign in', await signIn(page, { account: 'owner' }));
await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
const sw = page.locator('select[x-on\\:change*="switchTo"]');
await sw.selectOption(''); await settle(); // every sede: the whole club's list
await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });

// 1. Lotes: in stock only, newest first, with the hint.
const hint = page.locator('[data-show-empty-batches]');
const hintText = ((await page.locator('.fi-ta-header').textContent().catch(() => '')) ?? '').replace(/\s+/g, ' ');
check('the hint says how many empty batches are hidden', /Se ocultan \d+ lotes vacíos/.test(hintText), hintText.trim().slice(0, 80));
const restantes = async () => page.locator('.fi-ta-row').evaluateAll((rows) => rows.map((r) => r.innerText.match(/(\d+[.,]\d{2}) g/)?.[1] ?? '?'));
const total = async () => Number(((await page.locator('body').innerText().catch(() => '')) ?? '').match(/de (\d+) resultados/)?.[1] ?? 0);
const shown = await restantes();
const shownTotal = await total();
check('no empty batch on the default list', shown.length > 0 && ! shown.some((g) => /^0[.,]00$/.test(g)), JSON.stringify(shown.slice(0, 8)));
await page.screenshot({ path: `${OUT}/${tag}-lotes.png`, fullPage: true });
await hint.click(); await settle();
const allTotal = await total();
const chip = ((await page.locator('.fi-ta-filter-indicators').textContent().catch(() => '')) ?? '').replace(/\s+/g, ' ');
// The "de N resultados" overview is hidden on a tablet; where it shows, the hidden count must add up exactly.
const addsUp = shownTotal === 0 || allTotal === shownTotal + Number(hintText.match(/Se ocultan (\d+)/)?.[1] ?? -1);
check('Ver todos shows the empty ones too', chip.includes('Existencias: Todos') && ! await hint.isVisible() && addsUp, `${chip.trim()} · ${shownTotal} → ${allTotal}`);
await page.screenshot({ path: `${OUT}/${tag}-lotes-todos.png`, fullPage: true });

// 2. Delete a strain that still has stock.
await page.goto(`${BASE}/genetics`, { waitUntil: 'networkidle' });
await page.locator('.fi-ta-search-field input').fill('Amnesia Haze'); await page.waitForTimeout(1200); await settle();
await page.locator('.fi-ta-row', { hasText: 'Amnesia Haze' }).first().locator('button[title="Abrir acciones"], .fi-icon-btn:visible').last().click();
await page.locator('.fi-dropdown-list-item:visible', { hasText: 'Editar' }).first().click(); await settle();
await page.getByRole('button', { name: 'Borrar' }).first().click(); await settle();
await page.locator('.fi-modal-window:visible').getByRole('button', { name: 'Borrar' }).click(); await settle();
const refusal = ((await page.locator('.fi-no-notification').first().textContent().catch(() => '')) ?? '').replace(/\s+/g, ' ').trim();
check('deleting a strain with stock is refused, saying where', /No se puede borrar: quedan .*g en /.test(refusal), refusal.slice(0, 120));
await page.screenshot({ path: `${OUT}/${tag}-delete-refused.png` });

// 3. A second "Amnesia Haze".
await page.goto(`${BASE}/genetics/create`, { waitUntil: 'networkidle' });
await page.fill('input[id$="name"]', 'amnesia  haze');
await page.getByRole('button', { name: 'Siguiente' }).first().click(); await settle();
const nameError = ((await page.locator('.fi-fo-field', { has: page.locator('input[id$="name"]') }).textContent()) ?? '').replace(/\s+/g, ' ');
check('a second "amnesia haze" is refused', nameError.includes('Ya existe una genética con este nombre.'), nameError.trim().slice(0, 100));
await page.screenshot({ path: `${OUT}/${tag}-duplicate.png` });
await page.fill('input[id$="name"]', 'lemon test');
await page.getByRole('button', { name: 'Siguiente' }).first().click(); await settle();
const restoreLink = page.locator('[data-restore-genetic]');
check('a deleted strain\'s name offers it back', await restoreLink.isVisible(), ((await restoreLink.textContent().catch(() => '')) ?? '').trim());
await page.screenshot({ path: `${OUT}/${tag}-duplicate-deleted.png` });

// 4. Genéticas → Solo registros eliminados → restore Lemon Test.
await page.goto(`${BASE}/genetics`, { waitUntil: 'networkidle' });
await page.locator('button[title="Filtrar"]').first().click(); await page.waitForTimeout(500);
const trashed = page.locator('.fi-fo-field', { hasText: 'Registros eliminados' });
if (await trashed.locator('select').count()) {
    await trashed.locator('select').selectOption({ label: 'Solo registros eliminados' });
} else {
    await trashed.locator('.fi-select-input').first().click();
    await page.locator('[role="option"]:visible', { hasText: 'Solo registros eliminados' }).first().click();
}
await page.locator('.fi-dropdown-panel:visible button, .fi-ta-filters button').filter({ hasText: /Aplicar/ }).first().click(); await settle();
await page.screenshot({ path: `${OUT}/${tag}-trashed-filter.png`, fullPage: true });
await page.locator('.fi-ta-row', { hasText: 'Lemon Test' }).first().locator('button[title="Abrir acciones"], .fi-icon-btn:visible').last().click();
await page.locator('.fi-dropdown-list-item:visible', { hasText: 'Editar' }).first().click(); await settle();
await page.getByRole('button', { name: 'Restaurar' }).first().click(); await settle();
await page.locator('.fi-modal-window:visible').getByRole('button', { name: 'Restaurar' }).click(); await settle();
check('Lemon Test restored', ! (await page.getByRole('button', { name: 'Restaurar' }).first().isVisible().catch(() => false)));
await page.screenshot({ path: `${OUT}/${tag}-restored.png` });
check('no page errors', errors.length === 0, errors.slice(0, 2).join(' | '));

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
