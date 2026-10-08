// Prompt 368 — proof on the running app (a throwaway csc:seed-staging DB with "Polen de casa": 2.80 g in the jar, 30.00 g
// sealed; staff granted stock.take as 364 made the default):
//   A. the dispensary refuses 4 g of a 2.80 g jar INSIDE the weight panel, offers «Rellenar» and «Añadir 2.80 g», and the
//      fix adds exactly 2.80 g (393×852 and 1180×820);
//   B. Existencias at 393×852: no action label overflows its button; no reserve → no «Rellenar»; «Corregir peso» shows one
//      confirm button labelled with the result, and confirming writes it;
//   C. the panel's Ajuste: «Ahora», Nuevo total / Añadir / Quitar, and the live preview.
//   BASE_URL=http://127.0.0.1:8360 LOCALE=en THEME=light node tests/Browser/prove-368-stock.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/368';
mkdirSync(OUT, { recursive: true });
const LOCALE = process.env.LOCALE ?? 'en';
const ONLY = process.env.ONLY ?? ''; // 'B' for the Existencias checks alone (the login rate limit)
// LARGE=1 — one step up of iOS text size: the root font at 118 % (the counter's sizes are rem).
const LARGE = process.env.LARGE === '1';
const SIZE = LARGE ? '-large' : '';
const THEME = process.env.THEME ?? 'light';
const T = {
  en: { only: 'Only 2.80 g in the jar.', add: 'Add 2.80 g', topUp: 'Top up', correct: 'Correct to 2.50 g (−0.30 g)', now: 'Now: jar', preview: '→ 6.80 g' },
  es: { only: 'Solo hay 2.80 g en el bote.', add: 'Añadir 2.80 g', topUp: 'Rellenar', correct: 'Corregir a 2.50 g (−0.30 g)', now: 'Ahora: bote', preview: '→ 6.80 g' },
}[LOCALE];
const results = [];
const check = (label, pass, detail = '') => results.push([`${LOCALE}/${THEME} ${label}`, !!pass, detail]);
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };
const browser = await chromium.launch();

// The counter screens need an open till (prompt 236); opening one lands on the counter home.
async function openTill(page) {
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  const float = page.locator('input[wire\\:model="floatInput"]');
  if (await float.count()) { await float.fill('100'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page); }
}

// --- A. The dispensary ---------------------------------------------------------------------------------------------------
for (const [w, h] of ONLY === 'B' ? [] : [[393, 852], [1180, 820]]) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, hasTouch: true, colorScheme: THEME });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => check(`page error: ${e.message}`, false));
  await signInToCounter(page, '/counter', { account: 'staff' });
  await openTill(page);
  await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
  if (await page.locator('#member-lookup').isVisible().catch(() => false)) {
    await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle(page);
    await page.locator('[data-member-lookup-result]').first().click(); await settle(page);
  }
  // A clean basket for the proof.
  const before = await page.locator('[data-edit-line]').count();
  await page.locator('[data-catalogue-item="genetics"][data-search="Polen de casa"]').first().click({ timeout: 10000 })
    .catch(async (e) => { await page.screenshot({ path: `${OUT}/FAIL-A-${LOCALE}-${THEME}-${w}.png`, fullPage: true }); throw e; });
  await settle(page);
  await page.locator('[data-weight-pad] button:text-is("4")').click();
  await page.click('[data-add-line]');
  await settle(page);
  const panel = page.locator('[data-weight-entry]');
  const short = panel.locator('[data-stock-short]');
  check(`A ${w}: 4 g of 2.80 g not added`, (await page.locator('[data-edit-line]').count()) === before);
  check(`A ${w}: the message is inside the weight panel`, (await short.count()) === 1 && (await short.innerText()).includes(T.only), (await short.innerText().catch(() => '')).replace(/\n/g, ' | '));
  check(`A ${w}: «${T.topUp}» to Existencias`, ((await panel.locator('[data-stock-short-top-up]').getAttribute('href')) ?? '').includes('/counter/existencias?lote='));
  check(`A ${w}: the typed 4 is kept`, (await panel.locator('[data-weight-display]').innerText()).trim().startsWith('4'));
  await short.scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${OUT}/A-over-stock-${LOCALE}-${THEME}-${w}.png` });
  await panel.locator('[data-stock-short-fix]').click();
  await settle(page);
  check(`A ${w}: «${T.add}» adds one line`, (await page.locator('[data-edit-line]').count()) === before + 1);
  const lineText = await page.locator('[data-edit-line]').last().innerText();
  check(`A ${w}: …of exactly 2.80 g`, lineText.includes('2.80 g'), lineText.replace(/\n/g, ' | '));
  // Leave the basket as it was (× on the line we added).
  const remove = page.locator('button[wire\\:click^="removeLine"]').last();
  if (await remove.count()) { await remove.click(); await settle(page); }
  await ctx.close();
}

// --- B. Existencias at 393×852 ---------------------------------------------------------------------------------------------
{
  const ctx = await browser.newContext({ viewport: { width: 393, height: 852 }, hasTouch: true, isMobile: true, colorScheme: THEME });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => check(`page error: ${e.message}`, false));
  await signInToCounter(page, '/counter', { account: 'staff' });
  if (LARGE) await ctx.addInitScript(() => document.addEventListener('DOMContentLoaded', () => { document.documentElement.style.fontSize = '118%'; }));

  const overflowFree = async (tag) => {
    const bad = await page.evaluate(() => {
      const out = [];
      for (const b of document.querySelectorAll('[data-stock-panel] button, [data-stock-panel] a')) {
        const r = b.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) continue; // hidden (x-show)
        if (b.scrollHeight > b.clientHeight + 1 || b.scrollWidth > b.clientWidth + 1) out.push(`${b.innerText.trim()} (scroll)`);
        for (const child of b.querySelectorAll('span')) {
          const c = child.getBoundingClientRect();
          if (c.width && (c.top < r.top - 1 || c.bottom > r.bottom + 1 || c.left < r.left - 1 || c.right > r.right + 1)) out.push(`${b.innerText.trim()} (spills)`);
        }
        if (r.height < 43.5) out.push(`${b.innerText.trim()} (${Math.round(r.height)} px tall)`);
      }
      if (document.documentElement.scrollWidth > window.innerWidth + 1) out.push('page scrolls sideways');
      return out;
    });
    check(`B ${tag}${SIZE}: nothing overflows, every control ≥ 44 px`, bad.length === 0, bad.join('; '));
  };

  await page.goto(`${BASE}/counter/existencias`, { waitUntil: 'networkidle' });
  await page.locator('[data-stock-row]:has-text("Polen de casa")').first().click();
  await settle(page);
  await overflowFree('action list');
  check('B: with a reserve, «Rellenar» is offered', (await page.locator('[data-action-top-up]').count()) === 1);
  await page.screenshot({ path: `${OUT}/B-actions-${LOCALE}-${THEME}-393${SIZE}.png` });

  // A batch with no reserve: no «Rellenar».
  await page.goto(`${BASE}/counter/existencias`, { waitUntil: 'networkidle' });
  const rows = page.locator('[data-stock-row]');
  let noReserveChecked = false;
  for (let i = 0; i < await rows.count() && !noReserveChecked; i++) {
    await rows.nth(i).click(); await settle(page);
    if (await page.locator('[data-no-reserve]').count()) {
      check('B: no reserve → «Rellenar» not offered', (await page.locator('[data-action-top-up]').count()) === 0);
      await overflowFree('no-reserve list');
      noReserveChecked = true;
    } else {
      await page.goto(`${BASE}/counter/existencias`, { waitUntil: 'networkidle' });
    }
  }
  check('B: found a batch without a reserve', noReserveChecked);

  // «Corregir peso» on Polen de casa: 2.50 g.
  await page.goto(`${BASE}/counter/existencias`, { waitUntil: 'networkidle' });
  await page.locator('[data-stock-row]:has-text("Polen de casa")').first().click();
  await settle(page);
  await page.click('[data-action-choose="weigh"]');
  for (const key of ['2', '.', '5']) await page.locator(`[data-stock-pad] button:text-is("${key}")`).first().click();
  const confirm = page.locator('[data-action-confirm="weigh"]');
  check('B: one confirm button for the action', (await page.locator('[data-action-confirm]:visible').count()) === 1);
  await page.click('[data-weigh-reason="WEIGHING_ERROR"]');
  await page.waitForTimeout(300);
  check('B: the reason enables the confirm button', !(await confirm.isDisabled()));
  const label = (await confirm.innerText()).trim();
  check('B: its label is the result', label === T.correct, label);
  await overflowFree('chosen action');
  await page.screenshot({ path: `${OUT}/B-chosen-${LOCALE}-${THEME}-393${SIZE}.png`, fullPage: true });
  await confirm.click();
  await settle(page);
  check('B: confirming wrote 2.50 g', (await page.locator('[data-stock-panel-figures]').innerText()).includes('2.50 g'));
  check('B: back to the action list', await page.locator('[data-stock-actions]').isVisible());
  await ctx.close();
}

// --- C. The panel's Ajuste ---------------------------------------------------------------------------------------------------
if (ONLY !== 'B') {
  const ctx = await browser.newContext({ viewport: { width: 393, height: 852 }, hasTouch: true, isMobile: true, colorScheme: THEME });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => check(`page error: ${e.message}`, false));
  await signIn(page, { account: 'manager' });
  await page.goto(`${BASE}/batches?search=${encodeURIComponent('Polen de casa')}`, { waitUntil: 'networkidle' });
  if (THEME === 'dark') await page.evaluate(() => document.documentElement.classList.add('dark'));
  await page.locator('.fi-ta-row .fi-ta-actions .fi-dropdown-trigger button').first().click();
  await page.waitForTimeout(400);
  await page.locator('.fi-dropdown-panel .fi-dropdown-list-item').filter({ hasText: LOCALE === 'en' ? 'Adjustment' : 'Ajuste' }).first().click();
  await page.waitForSelector('.fi-modal-window', { state: 'visible' });
  await page.waitForTimeout(500);
  const modal = page.locator('.fi-modal-window').last();
  check('C: «Ahora» shown', (await modal.innerText()).includes(T.now), (await modal.innerText()).split('\n').slice(0, 6).join(' | '));
  const amount = modal.locator('input[inputmode="decimal"]').first();
  check('C: the amount asks for the decimal keypad', (await amount.getAttribute('inputmode')) === 'decimal');
  await amount.fill('6.80');
  await page.waitForTimeout(1200);
  await settle(page);
  check('C: live preview', (await modal.innerText()).includes(T.preview), (await modal.innerText()).match(/\d+\.\d{2} g → [^\n]+/)?.[0] ?? '');
  check('C: no horizontal page scroll', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
  await page.screenshot({ path: `${OUT}/C-ajuste-${LOCALE}-${THEME}-393.png` });
  await ctx.close();
}

await browser.close();
for (const [label, pass, detail] of results) console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  — ${detail}` : ''}`);
const failed = results.filter(([, pass]) => !pass).length;
console.log(`${results.length - failed}/${results.length} passed`);
process.exit(failed === 0 ? 0 : 1);
