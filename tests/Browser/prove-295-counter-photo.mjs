// Prompt 295 — the counter's staff alta: Hacer foto (the FRONT camera for a face) hands its picture to the field's own
// input, which Livewire uploads as if the file had been chosen; Elegir archivo never forces the camera. Throwaway DB.
import { chromium } from 'playwright';
import { signInToCounter } from './counter-session.mjs';
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
const errors = []; page.on('pageerror', (e) => errors.push(e.message));
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
check('sign in', await signInToCounter(page, '/counter/members'));
await page.goto(page.url().replace(/\/counter.*/, '/counter/till'), { waitUntil: 'networkidle' });
if (await page.$('input[wire\\:model="floatInput"]')) { await page.fill('input[wire\\:model="floatInput"]', '50'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await page.waitForTimeout(1500); }
await page.goto(page.url().replace(/\/counter.*/, '/counter/members'), { waitUntil: 'networkidle' });
await page.locator('button', { hasText: 'Nuevo socio' }).first().click();
await page.waitForTimeout(1500);
await page.locator('[data-alta-staff-form]').click({ timeout: 8000 }).catch((e) => console.log('staff form', e.message.split('\n')[0]));
await page.waitForTimeout(1500);
const cam = page.locator('input[data-camera-for="alta-photo"]');
check('Hacer foto opens the front camera', await cam.count() === 1 && await cam.getAttribute('capture') === 'user');
const photo = { name: 'cara.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64') };
const uploads = []; page.on('request', (r) => { if (r.url().includes('livewire') && r.url().includes('upload')) uploads.push(r.url()); });
await cam.setInputFiles(photo);
await page.waitForTimeout(2500);
const shown = (await page.locator('#alta-photo').locator('xpath=../..//*[@data-file-name]').first().textContent())?.trim();
check('the picture reaches the field and is uploaded', await page.$eval('#alta-photo', (i) => i.files.length) === 1 && shown === 'cara.png' && uploads.length >= 1, `${shown}, ${uploads.length} upload(s)`);
check('Elegir archivo does not force the camera', ! await page.$eval('#alta-photo', (i) => i.hasAttribute('capture')));
await page.screenshot({ path: `${(process.env.OUT ?? 'storage/app')}/alta-295.png` });
check('no page errors', errors.length === 0, errors.join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
