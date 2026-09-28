// Prompt 281 — the panel's Registro de jornada: flags, one correction (an annulment with its reason), the PDF sheet.
import { chromium } from 'playwright';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/281';
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true });
const page = await ctx.newPage();
let ok = await signIn(page);
await page.goto(`${BASE}/registro-de-jornada`, { waitUntil: 'networkidle' });
const rows = await page.$$('[data-jornada-row="period"]');
console.log(`${rows.length ? 'PASS' : 'FAIL'} periods listed (${rows.length})`); ok &&= rows.length > 0;
await page.screenshot({ path: `${OUT}/panel-1440-light.png`, fullPage: true });

const before = (await page.$$('[data-jornada-row="annulled"]')).length;
await page.locator('[data-jornada-row="period"] button:has-text("Anular")').first().click();
await page.waitForSelector('.fi-modal-window textarea, .fi-modal-window input[type="text"]', { timeout: 8000 });
await page.locator('.fi-modal-window textarea, .fi-modal-window input[type="text"]').first().fill('Fichaje duplicado por error');
await page.screenshot({ path: `${OUT}/panel-annul-modal.png` });
await page.locator('.fi-modal-window button[type="submit"]').first().click();
await page.waitForTimeout(2000);
const after = (await page.$$('[data-jornada-row="annulled"]')).length;
console.log(`${after === before + 1 ? 'PASS' : 'FAIL'} annulment shows struck through (${before} → ${after})`); ok &&= after === before + 1;

const [download] = await Promise.all([
    page.waitForEvent('download', { timeout: 15000 }).catch(() => null),
    page.locator('button:has-text("Hoja mensual (PDF)")').first().click(),
]);
let pdfOk = false;
if (download) {
    const path = `${OUT}/${download.suggestedFilename()}`;
    await download.saveAs(path);
    const { readFileSync } = await import('node:fs');
    pdfOk = readFileSync(path).subarray(0, 4).toString() === '%PDF';
    console.log(`${pdfOk ? 'PASS' : 'FAIL'} PDF sheet downloaded: ${download.suggestedFilename()}`);
} else {
    console.log('FAIL no PDF download');
}
ok &&= pdfOk;

await page.setViewportSize({ width: 1024, height: 768 });
await page.emulateMedia({ colorScheme: 'dark' });
await page.goto(`${BASE}/registro-de-jornada`, { waitUntil: 'networkidle' });
await page.screenshot({ path: `${OUT}/panel-1024-dark.png`, fullPage: true });
const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
console.log(`${overflow ? 'FAIL' : 'PASS'} no horizontal page scroll at 1024`); ok &&= ! overflow;
await page.setViewportSize({ width: 390, height: 844 });
await page.reload({ waitUntil: 'networkidle' });
const overflow390 = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
console.log(`${overflow390 ? 'FAIL' : 'PASS'} no horizontal page scroll at 390`); ok &&= ! overflow390;
await page.screenshot({ path: `${OUT}/panel-390-dark.png`, fullPage: true });
await browser.close();
process.exit(ok ? 0 : 1);
