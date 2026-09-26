// Prompt 265 — proof on the REAL app at iPad portrait (820×1180): (1) the closed arqueo itemises two till expenses;
// (2) the roles page warns that "Cerrar caja" needs the recount; (3) the limit-breach panel as STAFF speaks plainly
// and offers "Autorizar con PIN".
//
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DevAdminSeeder
//   (then: grant STAFF till.close on Roles y permisos; give M-00001 a 1 g daily limit)
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/shoot-cash-up-and-guidance.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, SEDE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/265';
mkdirSync(OUT, { recursive: true });
const VP = { width: 820, height: 1180 };
const results = [];
const browser = await chromium.launch();

const ONLY = process.env.ONLY ?? ''; // e.g. ONLY=3 to re-shoot one section without three more sign-ins

// --- 1. Two expenses, then the blind close → the arqueo itemises them -----------------------------------------
if (ONLY === '' || ONLY === '1') {
  const ctx = await browser.newContext({ viewport: VP, hasTouch: true });
  const page = await ctx.newPage();
  if (! await signInToCounter(page, '/counter/till', { sede: SEDE })) {
    results.push(['till: sign in', false]);
  } else {
    await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
    const float = await page.$('input[data-till-float]');
    if (float) { await float.fill('100'); await page.click('[data-till-open-action]'); await page.waitForTimeout(1200); }
    await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });

    for (const [amount, note] of [['12,50', 'Leche y café'], ['7,20', 'Bolsas de basura']]) {
      await page.selectOption('select[wire\\:model="expenseCategoryId"]', { index: 1 }).catch(() => {});
      await page.fill('input[wire\\:model="expenseAmount"]', amount);
      await page.fill('input[wire\\:model="expenseNote"]', note);
      await page.click('form[wire\\:submit="recordExpense"] button[type="submit"]');
      await page.waitForTimeout(900);
    }
    await page.screenshot({ path: `${OUT}/1a-till-shift-itemised.png`, fullPage: true });
    results.push(['till: petty cash itemised during the shift', (await page.content()).includes('data-petty-cash-items')]);

    await page.click('[wire\\:click="startClose"]');
    await page.waitForTimeout(800);
    // The end-of-day flower recount, when required: count every jar as its current figure.
    for (const input of await page.$$('input[wire\\:model^="reweighCounts"]')) { await input.fill('10'); }
    if (await page.$('form[wire\\:submit="submitReweigh"]')) {
      await page.click('form[wire\\:submit="submitReweigh"] button[type="submit"]');
      await page.waitForTimeout(900);
    }
    await page.fill('input[wire\\:model="countInput"]', '80,30');
    await page.click('form[wire\\:submit="submitCount"] button[type="submit"]');
    await page.waitForTimeout(900);
    // A variance beyond tolerance asks for a note: give one and resubmit.
    const note = await page.$('textarea[wire\\:model="closeNote"], input[wire\\:model="closeNote"]');
    if (note) { await note.fill('Recuento de prueba'); await page.click('form[wire\\:submit="submitCount"] button[type="submit"]'); await page.waitForTimeout(900); }

    const html = await page.content();
    await page.screenshot({ path: `${OUT}/1b-closed-arqueo-itemised.png`, fullPage: true });
    results.push(['arqueo: closed, itemising both expenses', html.includes('data-arqueo-petty-cash') && html.includes('Leche y café') && html.includes('Bolsas de basura')]);
  }
  await ctx.close();
}

// --- 2. The roles page warning ---------------------------------------------------------------------------------
if (ONLY === '' || ONLY === '2') {
  const ctx = await browser.newContext({ viewport: VP });
  const page = await ctx.newPage();
  if (await signIn(page)) {
    await page.goto(`${BASE}/roles-y-permisos`, { waitUntil: 'networkidle' });
    const warning = await page.$('[data-dependency-warning$=":till.close"]');
    await page.screenshot({ path: `${OUT}/2-roles-dependency-warning.png` });
    results.push(['roles: "Cerrar caja" without the recount is warned', !! warning]);
  } else {
    results.push(['roles: sign in', false]);
  }
  await ctx.close();
}

// --- 3. The breach panel as STAFF -------------------------------------------------------------------------------
if (ONLY === '' || ONLY === '3') {
  const ctx = await browser.newContext({ viewport: VP, hasTouch: true });
  const page = await ctx.newPage();
  const staffEmail = 'staff@club.test';
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
  await page.fill('input[type="email"]', staffEmail);
  await page.fill('input[type="password"]', 'password');
  await page.press('input[type="password"]', 'Enter');
  await page.waitForURL((u) => ! u.pathname.startsWith('/login'), { timeout: 20000 }).catch(() => {});
  // Filament throttles sign-ins (a few a minute): if a re-run tripped it, wait the window out once and retry.
  if (new URL(page.url()).pathname.startsWith('/login')) {
    await page.waitForTimeout(65000);
    await page.fill('input[type="email"]', staffEmail);
    await page.fill('input[type="password"]', 'password');
    await page.press('input[type="password"]', 'Enter');
    await page.waitForURL((u) => ! u.pathname.startsWith('/login'), { timeout: 20000 }).catch(() => {});
  }
  await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
  // Staff PIN (dev seed: 3456) on the pad, if it is up.
  if (await page.$('[data-counter-surface-unlock]')) {
    for (const d of '3456') { await page.click(`[data-counter-surface] button:has-text("${d}")`).catch(() => {}); }
    await page.click('[data-counter-surface-unlock]');
    await page.waitForTimeout(900);
  }
  // Section 1 closed the day's till — open a fresh one (staff hold till.open), then serve.
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  const float = await page.$('input[data-till-float]');
  if (float) { await float.fill('100'); await page.click('[data-till-open-action]'); await page.waitForTimeout(1000); }
  await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
  await page.fill('input[data-member-lookup]', 'M-00001', { timeout: 8000 }).catch(() => {});
  await page.waitForSelector('[data-member-lookup-result]', { timeout: 8000 }).catch(() => {});
  await page.click('[data-member-lookup-result]').catch(() => {});
  await page.waitForTimeout(700);
  await page.click('[wire\\:click^="chooseGenetic"]').catch(() => {});
  await page.waitForTimeout(500);
  await page.click(`button[wire\\:click="pad('3')"]`).catch(() => {}); // the weight keypad: 3 g
  await page.waitForTimeout(400);
  await page.click('button[wire\\:click="addLine"]').catch(() => {});
  await page.waitForTimeout(700);
  await page.click('[data-commit-action]').catch(() => {});
  await page.waitForTimeout(900);
  const panel = await page.$('[data-authorise-with-pin]');
  if (panel) { await panel.scrollIntoViewIfNeeded(); }
  const html = await page.content();
  await page.screenshot({ path: `${OUT}/3-breach-panel-as-staff.png` });
  results.push(['breach panel as staff: plain words + "Autorizar con PIN", no permission key',
    !! panel && ! html.includes('limits.override') && html.includes('Autorizar con PIN')]);
  await ctx.close();
}

await browser.close();
let ok = true;
for (const [text, pass] of results) { console.log(`${pass ? 'PASS' : 'FAIL'}  ${text}`); ok = ok && pass; }
process.exitCode = ok ? 0 : 1;
