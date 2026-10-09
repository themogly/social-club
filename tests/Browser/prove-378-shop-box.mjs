// Prompt 378 — proof on the running app (a throwaway csc:seed-staging DB in English; Central Branch, no till open):
//   S. Locations → Tills: four rows (edibles, bar, shop, fees) at 1440 and 393, light and dark; «Everything apart» saved;
//   P. the product form's Bar / Shop choice: Lighter marked Shop;
//   H. a mixed bar order (Soft drink €2.00 + Lighter €1.00, cash) → the hub's last-sale line names both boxes (1180×820);
//   C. the close screen asks for the shop box (820×1180).
//   BASE_URL=http://127.0.0.1:8360 SEDE=<central id> ARTICLE=<Lighter at Central> PART=SP|H|C node tests/Browser/prove-378-shop-box.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/378';
mkdirSync(OUT, { recursive: true });
const SEDE = process.env.SEDE;
const part = (p) => !process.env.PART || process.env.PART.includes(p);
const results = [];
const check = (label, pass, detail = '') => results.push([label, !!pass, detail]);
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };
const browser = await chromium.launch();

// --- S + P. The settings and the product ---------------------------------------------------------------------------------
if (part('S') || part('P')) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => check(`S page error: ${e.message}`, false));
  await signIn(page, { account: 'owner' });
  const fieldset = () => page.locator('fieldset:has([data-cash-summary])');

  for (const [w, h, dark] of [[1440, 900, false], [1440, 900, true], [393, 852, false], [393, 852, true]]) {
    const tag = `${w}-${dark ? 'dark' : 'light'}`;
    await page.setViewportSize({ width: w, height: h });
    await page.goto(`${BASE}/locations/${SEDE}/edit`, { waitUntil: 'networkidle' });
    await page.emulateMedia({ colorScheme: dark ? 'dark' : 'light' });
    await page.evaluate((d) => document.documentElement.classList.toggle('dark', d), dark);
    await page.locator('[data-cash-preset="all_apart"]').click();
    await settle(page);
    const text = await fieldset().innerText();
    check(`S ${tag}: four rows, the shop with three choices`, /Shop \(products\)/.test(text) && /With the bar/.test(text) && /Bar \(drinks, food\)/.test(text));
    check(`S ${tag}: the sentence names the shop`, /edibles, bar, shop and fees boxes/.test(text), (await page.locator('[data-cash-summary]').innerText()).trim());
    check(`S ${tag}: no sideways scroll`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await fieldset().screenshot({ path: `${OUT}/cajas-${tag}.png` });
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${BASE}/locations/${SEDE}/edit`, { waitUntil: 'networkidle' });
  await page.locator('[data-cash-preset="all_apart"]').click();
  await settle(page);
  await page.locator('button[type="submit"]:has-text("Save changes")').first().click();
  await settle(page);
  check('S: saved', (await page.locator('body').innerText()).includes('Saved'));

  // P. Lighter → Shop.
  await page.goto(`${BASE}/articles/${process.env.ARTICLE}/edit`, { waitUntil: 'networkidle' });
  const shop = page.locator('.fi-fo-toggle-buttons label:has-text("Shop")').first();
  await shop.scrollIntoViewIfNeeded();
  await shop.click();
  await settle(page);
  await page.screenshot({ path: `${OUT}/product-form-shop.png` });
  await page.locator('button[type="submit"]:has-text("Save changes")').first().click();
  await settle(page);
  check('P: Lighter saved as Shop', (await page.locator('body').innerText()).includes('Saved'));
  await ctx.close();
}

async function openTill(page) {
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  const float = page.locator('input[wire\\:model="floatInput"]');
  if (await float.count()) { await float.fill('100'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page); }
}

// --- H. A mixed bar order ---------------------------------------------------------------------------------------------------
if (part('H')) {
  const ctx = await browser.newContext({ viewport: { width: 1180, height: 820 }, hasTouch: true });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => check(`H page error: ${e.message}`, false));
  await signInToCounter(page, '/counter', { account: 'manager' });
  await openTill(page);
  await page.goto(`${BASE}/counter/bar`, { waitUntil: 'networkidle' });
  await page.locator('[data-catalogue-item="bar"][data-search*="Soft drink"]').first().click();
  await settle(page);
  await page.locator('[data-catalogue-item="bar"][data-search*="Lighter"]').first().click();
  await settle(page);
  await page.locator('[wire\\:click^="quickCash"]').first().click();
  await settle(page);
  await page.click('[data-commit-action]');
  await page.waitForURL(/\/counter\/?$/, { timeout: 6000 }).catch(() => {});
  await settle(page);
  const boxes = (await page.locator('[data-hub-last-sale] [data-last-sale-boxes]').innerText().catch(() => '')).trim();
  check('H: the hub line names the shop box and the bar box', /shop box/.test(boxes) && /bar box/.test(boxes), boxes);
  await page.screenshot({ path: `${OUT}/hub-shop-and-bar-1180.png` });
  await ctx.close();
}

// --- C. The close asks for the shop box ---------------------------------------------------------------------------------------
if (part('C')) {
  const ctx = await browser.newContext({ viewport: { width: 820, height: 1180 }, hasTouch: true });
  const page = await ctx.newPage();
  await signInToCounter(page, '/counter', { account: 'manager' });
  await openTill(page);
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  await page.click('[data-close-till]');
  await settle(page);
  if (await page.locator('[data-reweigh-batch]').count()) {
    for (let i = 0; i < await page.locator('[data-reweigh-not-counted-toggle]').count(); i++) { await page.locator('[data-reweigh-not-counted-toggle]').nth(i).click(); await page.waitForTimeout(200); }
    await page.click('form[wire\\:submit="submitReweigh"] button[type="submit"]');
    await settle(page);
  }
  check('C: the close asks for the shop box', (await page.locator('[data-pot-count="SHOP"]').count()) === 1);
  check('C: …and the bar box', (await page.locator('[data-pot-count="BAR"]').count()) === 1);
  await page.locator('[data-pot-count="SHOP"]').scrollIntoViewIfNeeded().catch(() => {});
  await page.screenshot({ path: `${OUT}/close-shop-box-820.png`, fullPage: true });
  await ctx.close();
}

await browser.close();
for (const [label, pass, detail] of results) console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  — ${detail}` : ''}`);
const failed = results.filter(([, pass]) => !pass).length;
console.log(`${results.length - failed}/${results.length} passed`);
process.exit(failed === 0 ? 0 : 1);
