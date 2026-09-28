// Prompt 295 — Shane's catalogue notes, in a real browser at 1180×820 as the owner (throwaway DB):
//   · Añadir variedad with a price below cost: the warning on leaving Precio, "Volver y corregir" puts the cursor back
//     in the price, fixed → no warning, the photo step's Hacer foto hands its file to FilePond, Crear → the list;
//   · a new product: no Categoría field, and it lands on the list.
import { chromium } from 'playwright';
import { BASE, signIn } from './counter-session.mjs';

const OUT = process.env.OUT ?? 'storage/app';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
// A 1×1 PNG, so the harness carries no binary fixture.
const photo = { name: 'foto.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64') };

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
page.setDefaultTimeout(15000);
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(400); };
const next = async () => { await page.locator('button:visible', { hasText: /^\s*(Siguiente|Next)\s*$/ }).first().click(); await settle(); };
const field = (name) => page.locator(`[id$=".${name}"]:visible`).first();

check('sign in', await signIn(page));

// --- Añadir variedad ----------------------------------------------------------------------------------------------
await page.goto(`${BASE}/genetics/create`, { waitUntil: 'networkidle' });
await field('name').fill(`Prueba 295 ${Date.now()}`);
await next();                                   // Variedad → Tipo
await next();                                   // Tipo → Cantidad
await field('grams').fill('100');
await field('cost_per_gram_eur').fill('9.50');
await next();                                   // Cantidad → Sede
await page.locator('select[id$=".location_id"]:visible').selectOption({ index: 1 }).catch(() => {}); // blank in "Todas las sedes"
await next();                                   // Sede → Precio
await field('price_per_gram_eur').fill('8');
await next();                                   // leaving Precio: the warning
const modal = page.locator('.fi-modal-window:visible', { hasText: /menor que el coste|below cost/ });
check('leaving Precio below cost opens the warning', await modal.count() === 1, (await modal.textContent().catch(() => ''))?.replace(/\s+/g, ' ').slice(0, 140));
await page.screenshot({ path: `${OUT}/prove-295-warning.png` });
await modal.locator('button', { hasText: /Volver y corregir|Go back and fix/ }).click();
await page.waitForTimeout(600);
const focused = await page.evaluate(() => document.activeElement?.id ?? '');
check('Volver y corregir puts the cursor in the price', focused.endsWith('.price_per_gram_eur'), focused);
await field('price_per_gram_eur').fill('12');
await next();                                   // no warning now → Foto
check('a price above cost moves on with no warning', await page.locator('.fi-modal-window:visible').count() === 0);

const buttons = page.locator('[data-camera-or-file]:visible');
check('the photo step offers Hacer foto and Elegir archivo', await buttons.count() === 1 && /Hacer foto|Take photo/.test(await buttons.textContent()) && /Elegir archivo|Choose file/.test(await buttons.textContent()));
await buttons.locator('input[capture="environment"]').setInputFiles(photo);
await page.waitForTimeout(1500);
check('Hacer foto hands its picture to the field', await page.locator('.filepond--item').count() === 1);
const [chooser] = await Promise.all([page.waitForEvent('filechooser', { timeout: 5000 }).catch(() => null), buttons.locator('button', { hasText: /Elegir archivo|Choose file/ }).click()]);
check('Elegir archivo opens the file picker', chooser !== null && ! (await chooser.element().getAttribute('capture')));
await page.screenshot({ path: `${OUT}/prove-295-photo-step.png` });

await page.locator('button:visible', { hasText: /^\s*(Crear|Create)\s*$/ }).first().click();
await settle();
check('creating the strain lands on the strains list', new URL(page.url()).pathname === '/genetics', page.url());
await page.screenshot({ path: `${OUT}/prove-295-strain-list.png` });

// --- A new product -----------------------------------------------------------------------------------------------
await page.goto(`${BASE}/articles/create`, { waitUntil: 'networkidle' });
check('the product form has no Categoría', await page.locator('label', { hasText: /^\s*(Categoría|Category)\s*$/ }).count() === 0);
await page.screenshot({ path: `${OUT}/prove-295-product-form.png` });
// The product's Sede is Filament's searchable select: open it and take the first sede.
const sedeField = page.locator('.fi-fo-field', { has: page.locator('[id$=".location_id"], label:has-text("Sede")') }).first();
await sedeField.locator('button, [role="combobox"]').first().click().catch(() => {});
await page.locator('[role="option"]:visible').first().click().catch(() => {});
await field('name').fill(`Producto 295 ${Date.now()}`);
await field('price_eur').fill('2');
await field('stock').fill('5');
await page.locator('button:visible', { hasText: /^\s*(Crear|Create)\s*$/ }).first().click();
await settle();
check('a new product lands on the products list', new URL(page.url()).pathname === '/articles', page.url());

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
