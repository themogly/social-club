// Prompt 364 — «Existencias», on the running app (throwaway csc:seed-staging DB; Critical Kush's whole jar moved into its
// reserve with tinker so its dispensary card is the empty-jar link). Touch, 820×1180 and 1180×820.
//   R. The round trip: hold a member, add a line, tap the empty-jar card → Existencias opens on that lote; top up →
//      inline confirmation; «Volver al dispensario» → the same member and basket, and the strain is sellable again.
//   S. Screenshots: the list + summary, «Con reserva», a staff panel (reserve actions + weigh), a manager panel (+ Añadir).
//   BASE_URL=http://127.0.0.1:8360 node tests/Browser/prove-364-existencias.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/364';
mkdirSync(OUT, { recursive: true });
const results = [];
const check = (label, pass, detail = '') => results.push([label, !!pass, detail]);
const browser = await chromium.launch();
const settle = (page, ms = 600) => page.waitForLoadState('networkidle').catch(() => {}).then(() => page.waitForTimeout(ms));
const pos = (page) => page.evaluate(() => {
  const c = window.Livewire.all().find((x) => x.name === 'counter.dispensary-pos');
  return c ? { member: c.$wire.memberId, lines: (c.$wire.basket ?? []).length } : null;
});

async function openTill(page) {
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  const float = page.locator('input[wire\\:model="floatInput"]');
  if (await float.count()) { await float.fill('100'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page); }
}

// --- R. The round trip --------------------------------------------------------------------------------------------------
{
  const ctx = await browser.newContext({ viewport: { width: 820, height: 1180 }, hasTouch: true, isMobile: true });
  const page = await ctx.newPage();
  page.on('console', async (m) => { if (m.type() === 'warning') { const args = await Promise.all(m.args().map((a) => a.evaluate((v) => v instanceof Element ? v.outerHTML.slice(0, 300) : String(v)).catch(() => '?'))); console.log('CONSOLE', args.join(' || ').replace(/\n/g, ' ¶ ').slice(0, 1500)); } });
  page.on('pageerror', (e) => check(`page error: ${e.message}`, false, `${page.url()} ${(e.stack || '').split('\n').slice(0, 2).join(' / ')}`));
  await signInToCounter(page, '/counter', { account: 'staff' });
  await openTill(page);
  await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
  if (await page.locator('#member-lookup').isVisible().catch(() => false)) {
    await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle(page);
    await page.locator('[data-member-lookup-result]').first().tap(); await settle(page);
  }
  await page.locator('[data-catalogue-item="genetics"][data-search="Amnesia Haze"]').tap(); await settle(page);
  await page.locator('[data-weight-pad] button:text-is("1")').tap();
  await page.locator('[data-add-line]').tap(); await settle(page);
  const before = await pos(page);
  check('R: a member held and a line in the basket', before?.member && before.lines === 1, JSON.stringify(before));
  await page.screenshot({ path: `${OUT}/R1-dispensary-empty-jar-820.png` });

  const link = page.locator('[data-jar-top-up-link][data-search="Critical Kush"]');
  check('R: the empty jar is a link to Existencias', await link.count() === 1);
  await link.tap(); await settle(page);
  check('R: Existencias opens on that lote', page.url().includes('/counter/existencias?lote=') && await page.isVisible('[data-stock-panel]'), page.url());
  for (const d of ['5']) await page.locator(`[data-stock-pad] button:text-is("${d}")`).tap();
  await page.locator('[data-action-top-up-grams]').tap(); await settle(page);
  const confirmation = (await page.locator('[data-stock-confirmation]').innerText().catch(() => '')).trim();
  check('R: inline confirmation by the button', /\+5\.00 g/.test(confirmation), confirmation);
  await page.screenshot({ path: `${OUT}/R2-topped-up-820.png` });

  await page.locator('[data-back-to-pos]').tap(); await settle(page, 1000);
  const after = await pos(page);
  check('R: back — the same member and the same basket', after?.member === before?.member && after?.lines === 1, JSON.stringify(after));
  check('R: Critical Kush is no longer an empty jar', await page.locator('[data-jar-top-up-link][data-search="Critical Kush"]').count() === 0);
  check('R: the weight panel has no reserve controls', (await page.content()).includes('data-reserve-panel') === false);
  await page.screenshot({ path: `${OUT}/R3-back-in-the-dispensary-820.png` });
  await ctx.close();
}

// --- S. Screenshots ------------------------------------------------------------------------------------------------------
for (const [account, label] of [['staff', 'staff'], ['manager', 'manager']]) {
  for (const [w, h] of [[820, 1180], [1180, 820]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, hasTouch: true, isMobile: w < 1000 });
    const page = await ctx.newPage();
    await signInToCounter(page, '/counter', { account });
    await page.goto(`${BASE}/counter/existencias`, { waitUntil: 'networkidle' });
    const summary = (await page.locator('[data-stock-summary]').innerText().catch(() => '')).trim();
    if (account === 'staff') {
      check(`S ${w}: the summary line`, /\d+\.\d{2} g/.test(summary), summary);
      await page.screenshot({ path: `${OUT}/S1-list-${w}.png` });
      await page.locator('[data-stock-filter="reserve"]').tap(); await settle(page);
      check(`S ${w}: «Con reserva» lists only reserve rows`, (await page.locator('[data-stock-row]').count()) === (await page.locator('[data-stock-row] [data-stock-reserve]').count()));
      await page.screenshot({ path: `${OUT}/S2-con-reserva-${w}.png` });
    }
    await page.locator('[data-stock-row]:has-text("Amnesia Haze")').first().tap(); await settle(page);
    const panel = {
      topUp: await page.locator('[data-action-top-up]').count(), weigh: await page.locator('[data-action-weigh]').count(),
      add: await page.locator('[data-action-add-reserve]').count(),
    };
    check(`S ${w} ${label}: panel actions`, account === 'manager' ? panel.topUp && panel.weigh && panel.add : panel.topUp && panel.weigh && !panel.add, JSON.stringify(panel));
    await page.screenshot({ path: `${OUT}/S3-panel-${label}-${w}.png` });
    const small = await page.evaluate(() => [...document.querySelectorAll('[data-stock-panel] button, [data-stock-row], [data-stock-filter]')]
      .filter((b) => b.offsetParent !== null).map((b) => b.getBoundingClientRect()).filter((r) => r.height < 44 || r.width < 44).length);
    check(`S ${w} ${label}: every control ≥ 44×44`, small === 0, `${small} small`);
    await ctx.close();
  }
}

await browser.close();
let ok = true;
for (const [label, pass, detail] of results) { console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  (${detail})` : ''}`); ok &&= pass; }
console.log(`${results.filter((r) => r[1]).length}/${results.length}`);
process.exit(ok ? 0 : 1);
