// Prompt 245 — after "Cambiar de persona" (a Livewire morph), the surface must hold EXACTLY ONE PIN card, not
// two. Counts [data-surface-heading] on POS/Bar/Till, portrait + landscape. Fails against main (x-if duplicates).
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signInToCounter } from './counter-session.mjs';
const OUT = 'storage/app/screenshots/245';
mkdirSync(OUT, { recursive: true });
const browser = await chromium.launch();
let ok = true;
for (const [name, w, h] of [['portrait', 800, 1280], ['landscape', 1280, 800]]) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h } });
  const page = await ctx.newPage();
  // Land on the till (needs no open till → the surface/PIN shows after identifying), then open one so the POS is reachable.
  if (! await signInToCounter(page, '/counter/till', { sede: 'Central Branch' })) { console.error(`[${name}] signin failed`); ok = false; await ctx.close(); continue; }
  const fi = await page.$('[data-till-float] input, input[wire\\:model="floatInput"]'); if (fi) await fi.fill('100');
  const ob = await page.$('[data-till-open-action]'); if (ob) { await ob.click(); await page.waitForLoadState('networkidle'); await page.waitForTimeout(600); }
  for (const screen of ['/counter/pos', '/counter/bar', '/counter/till']) {
    await page.goto(`${BASE}${screen}`, { waitUntil: 'networkidle' });
    // Cambiar de persona — dispatch the switch (the chip does this), which morphs the page.
    await page.evaluate(() => window.Livewire.dispatch('counter-switch-operator'));
    await page.waitForTimeout(900);
    const headings = await page.$$eval('[data-surface-heading]', els => els.filter(e => e.offsetParent !== null || getComputedStyle(e).display !== 'none').length);
    const total = await page.$$eval('[data-surface-heading]', els => els.length);
    await page.screenshot({ path: `${OUT}/switch-${screen.split('/').pop()}-${name}.png` });
    const pass = total === 1;
    if (! pass) ok = false;
    console.log(`${pass ? 'PASS' : 'FAIL'} ${name} ${screen} → ${total} PIN card(s) in DOM (${headings} visible)`);
    // re-identify for the next screen
    for (const d of '1234') await page.click(`[data-counter-surface] button:has-text("${d}")`).catch(()=>{});
    const unlock = await page.$('[data-counter-surface-unlock]'); if (unlock) { await unlock.click(); await page.waitForTimeout(700); }
  }
  await ctx.close();
}
await browser.close();
process.exitCode = ok ? 0 : 1;
