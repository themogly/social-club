// Prompt 374 — proof on the running app (a throwaway csc:seed-staging DB in English; Central Branch with «Everything apart»):
//   H. a real sale with an edible paid in cash → the counter home's last-sale line says which box (1180×820);
//   R. the receipt sheet from the hub and from a «Today» row, at 1180×820 and 820×1180: the frame is ≥ 60 % of the
//      viewport and the receipt's total is inside it;
//   T. the «Today» totals as a manager (Contributions and Bar & shop apart, the cash by pot) and as staff (no money);
//   C. Locations → Tills at 1440 and 393: one row per kind of money.
//   BASE_URL=http://127.0.0.1:8360 SEDE=<central id> node tests/Browser/prove-374-fixes.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/374';
mkdirSync(OUT, { recursive: true });
const SEDE = process.env.SEDE;
const results = [];
const check = (label, pass, detail = '') => results.push([label, !!pass, detail]);
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };
const browser = await chromium.launch();
// PART=HR | T | C runs one part (the login rate limit trips after about four sign-ins in a row).
const part = (p) => !process.env.PART || process.env.PART.includes(p);

async function openTill(page) {
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  const float = page.locator('input[wire\\:model="floatInput"]');
  if (await float.count()) { await float.fill('100'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page); }
}

/** The receipt frame's height, and whether the receipt's total line is inside the visible frame. */
async function frameCheck(page, label, vh) {
  const frame = page.locator('[data-receipt-frame]');
  await frame.waitFor({ timeout: 5000 });
  await page.waitForTimeout(800);
  const box = await frame.boundingBox();
  check(`${label}: frame ≥ 60 % of the viewport (${Math.round(box?.height ?? 0)} px of ${vh})`, (box?.height ?? 0) >= vh * 0.6);
  const inner = page.frameLocator('[data-receipt-frame]');
  const total = inner.locator('text=/Total/i').last();
  const totalBox = await total.boundingBox().catch(() => null);
  check(`${label}: the receipt's total is inside the visible frame`, totalBox !== null && box !== null && totalBox.y + totalBox.height <= box.y + box.height + 1,
    totalBox ? `total at ${Math.round(totalBox.y)}, frame ends ${Math.round(box.y + box.height)}` : 'no total line');
}

// --- H + R. The hub after a sale, and its receipt ---------------------------------------------------------------------------
for (const [w, h] of part('H') ? [[1180, 820], [820, 1180]] : []) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, hasTouch: true });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => check(`H page error: ${e.message}`, false));
  await signInToCounter(page, '/counter', { account: 'manager' });
  await openTill(page);
  await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
  if (await page.locator('#member-lookup').isVisible().catch(() => false)) {
    await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle(page);
    await page.locator('[data-member-lookup-result]').first().click(); await settle(page);
  }
  await page.locator('[data-catalogue-item="genetics"][data-search="Gominola de prueba"]').first().click();
  await settle(page);
  await page.click('[data-add-line]'); await settle(page);
  await page.click('button[wire\\:click="quickCash"]'); await settle(page);
  await page.click('[data-commit-action]');
  await page.waitForURL(/\/counter\/?$/, { timeout: 6000 }).catch(() => {});
  await settle(page);
  const boxes = page.locator('[data-hub-last-sale] [data-last-sale-boxes]');
  check(`H ${w}×${h}: the hub's last-sale line says which box`, (await boxes.count()) === 1 && /edibles box/.test(await boxes.innerText()), (await boxes.innerText().catch(() => '')).trim());
  await page.screenshot({ path: `${OUT}/hub-box-line-${w}x${h}.png` });

  await page.click('[data-hub-last-sale] [data-last-sale-options]'); await page.waitForTimeout(300);
  await page.click('[data-hub-last-sale] [data-receipt-open]');
  await frameCheck(page, `R hub ${w}×${h}`, h);
  await page.screenshot({ path: `${OUT}/receipt-hub-${w}x${h}.png` });
  await page.keyboard.press('Escape');

  await page.goto(`${BASE}/counter/hoy`, { waitUntil: 'networkidle' });
  await page.locator('[data-sheet-row="dispensary"]:not([data-sheet-voided]) button').last().click();
  await frameCheck(page, `R today ${w}×${h}`, h);
  await page.screenshot({ path: `${OUT}/receipt-today-${w}x${h}.png` });
  await ctx.close();
}

// --- T. The Today totals as manager and staff --------------------------------------------------------------------------------
for (const account of part('T') ? ['manager', 'staff'] : []) {
  const ctx = await browser.newContext({ viewport: { width: 1180, height: 820 }, hasTouch: true });
  const page = await ctx.newPage();
  await signInToCounter(page, '/counter/hoy', { account });
  await page.goto(`${BASE}/counter/hoy`, { waitUntil: 'networkidle' });
  const totals = page.locator('[data-sheet-totals]');
  await totals.scrollIntoViewIfNeeded();
  const text = await totals.innerText();
  if (account === 'manager') {
    check('T manager: Contributions and Bar & shop apart', /Contributions/.test(text) && /Bar & shop\n/.test(text) && !/Amount/.test(text));
    check('T manager: the cash by pot', /Cash by pot/.test(text) && /In the till/.test(text), text.split('\n').filter((l) => /pot|till/i.test(l)).join(' | '));
  } else {
    check('T staff: no money', !/€/.test(text) && !/Contributions/.test(text));
  }
  await totals.screenshot({ path: `${OUT}/today-totals-${account}.png` });
  await ctx.close();
}

// --- C. Locations → Tills ----------------------------------------------------------------------------------------------------
for (const [w, h] of part('C') ? [[1440, 900], [393, 852]] : []) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h } });
  const page = await ctx.newPage();
  await signIn(page, { account: 'owner' });
  await page.goto(`${BASE}/locations/${SEDE}/edit`, { waitUntil: 'networkidle' });
  const fieldset = page.locator('fieldset:has([data-cash-summary])');
  await fieldset.scrollIntoViewIfNeeded();
  if (w === 1440) {
    // One row per kind: the label, its two choices and «Count every night» on one line.
    const rows = await page.evaluate(() => [...document.querySelectorAll('fieldset [data-cash-summary]')].length);
    const height = (await fieldset.boundingBox())?.height ?? 0;
    check(`C ${w}: the cash rows are one line each (fieldset ${Math.round(height)} px)`, rows === 1 && height < 480);
  }
  check(`C ${w}: no sideways scroll`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
  await fieldset.screenshot({ path: `${OUT}/cash-boxes-${w}.png` });
  await ctx.close();
}

await browser.close();
for (const [label, pass, detail] of results) console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  — ${detail}` : ''}`);
const failed = results.filter(([, pass]) => !pass).length;
console.log(`${results.length - failed}/${results.length} passed`);
process.exit(failed === 0 ? 0 : 1);
