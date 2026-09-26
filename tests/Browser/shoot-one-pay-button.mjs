// Prompt 263 — proof on the REAL dispensary that a visit with a flower line AND bar items has ONE pay button,
// ONE total, and that the button is on screen without scrolling the cart column. iPad portrait + landscape.
//
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DevAdminSeeder
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/shoot-one-pay-button.mjs
//
// The harness opens a till if the counter asks for one, holds a dispensable socio, adds a weight preset of the
// first genetic, switches to Barra and adds two articles.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, SEDE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/263';
mkdirSync(OUT, { recursive: true });

const VIEWPORTS = [
  { w: 820, h: 1180, tag: 'portrait' },
  { w: 1180, h: 820, tag: 'landscape' },
];
const results = [];
const browser = await chromium.launch();

async function openTillIfAsked(page) {
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  const float = await page.$('[data-till-float] input, input[wire\\:model="floatInput"]');
  if (float) {
    await float.fill('100');
    await page.click('[data-till-open-action]').catch(() => {});
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);
  }
}

// Hold a socio who can take a flower line (MEMBER_NO, default the dev seed's first — dispensable at the sede), add
// a weight preset of the first genetic, and report whether the basket took it.
const MEMBER_NO = process.env.MEMBER_NO ?? 'M-00001';
async function holdDispensableMember(page) {
  await page.fill('input[data-member-lookup]', MEMBER_NO);
  await page.waitForSelector('[data-member-lookup-result]', { timeout: 8000 }).catch(() => {});
  await page.click('[data-member-lookup-result]').catch(() => {});
  await page.waitForTimeout(800);
  await page.click('[wire\\:click^="chooseGenetic"]').catch(() => {});
  await page.waitForTimeout(600);
  const preset = await page.$('[data-weight-preset]');
  if (preset) { await preset.click(); } else { await page.fill('input[wire\\:model*="weightInput"]', '1').catch(() => {}); }
  await page.click('button[wire\\:click="addLine"]').catch(() => {});
  await page.waitForTimeout(800);

  return (await page.content()).includes('data-visit-total');
}

for (const vp of VIEWPORTS) {
  for (const theme of ['light', 'dark']) {
    const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, colorScheme: theme, hasTouch: true });
    const page = await ctx.newPage();
    const label = `${theme} ${vp.tag}`;

    if (! await signInToCounter(page, '/counter/pos', { sede: SEDE })) { results.push([`${label}: sign in`, false]); await ctx.close(); continue; }
    await openTillIfAsked(page);
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });

    if (! await holdDispensableMember(page)) { results.push([`${label}: a flower line in the basket`, false]); await ctx.close(); continue; }

    await page.click('[data-source-option="bar"]');
    await page.waitForTimeout(600);
    for (let i = 0; i < 2; i++) {
      const cards = await page.$$('[wire\\:click^="addBarItem"]:not([disabled])');
      if (cards[i]) { await cards[i].click(); await page.waitForTimeout(500); }
    }

    const state = await page.evaluate(() => {
      const buttons = [...document.querySelectorAll('[data-commit-action]')];
      const visible = (el) => { const r = el.getBoundingClientRect(); return r.bottom <= window.innerHeight && r.top >= 0; };
      // Whitespace normalised on all three — the money formatter's non-breaking space included.
      const norm = (t) => (t ?? '').replace(/\s+/g, ' ').trim();
      const total = norm(document.querySelector('[data-visit-total]')?.textContent);
      const cash = norm(document.querySelector('[data-cash-due]')?.textContent);
      return {
        commitButtons: buttons.length,
        settleInBar: !! document.querySelector('[data-settle-visit]'),
        buttonText: buttons[0]?.textContent.replace(/\s+/g, ' ').trim() ?? '',
        buttonInView: buttons[0] ? visible(buttons[0]) : false,
        total, cash,
      };
    });

    await page.screenshot({ path: `${OUT}/cart-combined-${vp.tag}-${theme}.png` });
    results.push([`${label}: exactly one pay button (${state.commitButtons}), none in the bar block`, state.commitButtons === 1 && ! state.settleInBar]);
    results.push([`${label}: button, header and tender agree — "${state.buttonText}" / ${state.total} / ${state.cash}`,
      state.total !== '' && state.buttonText.includes(state.total) && state.cash === state.total]);
    results.push([`${label}: the pay button is in view without scrolling the column`, state.buttonInView]);

    await ctx.close();
  }
}

await browser.close();
let ok = true;
for (const [text, pass] of results) { console.log(`${pass ? 'PASS' : 'FAIL'}  ${text}`); ok = ok && pass; }
process.exitCode = ok ? 0 : 1;
