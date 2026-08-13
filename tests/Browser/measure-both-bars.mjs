// Prompt 230 — the two bars, measured against each other.
//
//   npm run build
//   php artisan test tests/Browser/BothBarsHarnessTest.php
//   node tests/Browser/measure-both-bars.mjs [after|before]
//
// The owner's complaint was a COMPARISON, so this measures both screens the same way and prints them side by
// side: row height, tile height, whether the stock count is on the card, whether a sold-out article exists at
// all. On `main` the two rows disagree by 8px, the tiles by ~100, and only one of them has either fact.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { assertRealFont } from './font-ready.mjs';
import { resolve } from 'node:path';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/230';
mkdirSync(OUT, { recursive: true });

const STATES = ['bar-grid', 'bar-list', 'pos-grid', 'pos-list', 'bar-basket'];
const SIZES = [{ name: '1180x820', width: 1180, height: 820 }, { name: '820x1180', width: 820, height: 1180 }];
const FLOOR = 44;

const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };
const seen = {};

for (const state of STATES) {
  const file = resolve(`storage/app/bars-230-${state}.html`);
  try { await access(file); } catch { fail(`missing ${state} — run BothBarsHarnessTest first`); continue; }

  for (const size of SIZES) {
    for (const theme of ['light', 'dark']) {
      const page = await browser.newPage({ viewport: size, colorScheme: theme, hasTouch: true, reducedMotion: 'reduce' });
      await page.goto(pathToFileURL(file).href);
      await page.evaluate(() => {
        document.querySelectorAll('[data-counter-surface],[x-cloak]').forEach((n) => { n.style.display = 'none'; });
      });

      // Prompt 233 — prove what this is measuring IN before believing any number it produces.
      if (! await assertRealFont(page, `${state}/${size.name}/${theme}`, fail)) { await page.close(); continue; }

      await page.screenshot({ path: `${OUT}/${STAGE}-${state}-${size.name}-${theme}.png` });

      const r = await page.evaluate((FLOOR) => {
        const cards = Array.from(document.querySelectorAll('[data-product]'));
        const heights = cards.map((c) => Math.round(c.getBoundingClientRect().height)).sort((a, b) => a - b);
        const text = cards.map((c) => c.innerText).join(' | ');

        const targets = Array.from(document.querySelectorAll('a[href], button, input, select, textarea, label'))
          .filter((n) => n.tagName !== 'LABEL' || n.querySelector('input, select, textarea, button'))
          .filter((n) => ! n.disabled)
          .map((n) => ({ b: n.getBoundingClientRect(), what: `${n.tagName.toLowerCase()} ${(n.getAttribute('data-article-card') ? 'card' : (n.getAttribute('name') || n.id || n.textContent.trim().slice(0, 14)))}` }))
          .filter((t) => t.b.width > 1 && t.b.height > 1);

        return {
          cards: cards.length,
          median: heights.length ? heights[Math.floor(heights.length / 2)] : null,
          min: heights[0] ?? null,
          max: heights[heights.length - 1] ?? null,
          statesStock: /Stock:|Quedan pocas|Agotado/.test(text),
          soldOutVisible: cards.some((c) => /Agotado/.test(c.innerText)),
          placeholder: /🛒/.test(document.body.innerText),
          commit: document.querySelector('[data-commit-action]')?.innerText.trim().replace(/\s+/g, ' ') ?? null,
          under: targets.filter((t) => t.b.height < FLOOR || t.b.width < FLOOR).map((t) => `${Math.round(t.b.width)}×${Math.round(t.b.height)} ${t.what}`),
          hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        };
      }, FLOOR);

      const label = `${state}/${size.name}/${theme}`;
      seen[`${state}|${size.name}|${theme}`] = r;

      if (STAGE === 'after') {
        if (state !== 'bar-basket') {
          if (! r.cards) fail(`${label}: no article cards`);
          if (! r.statesStock) fail(`${label}: the card does not state stock`);
          if (! r.soldOutVisible) fail(`${label}: the sold-out article is not on the catalogue`);
        }
        if (r.placeholder) fail(`${label}: the placeholder glyph is back`);
        if (r.under.length) fail(`${label}: ${r.under.length} under ${FLOOR}px — ${r.under.slice(0, 3).join('; ')}`);
        if (r.hScroll) fail(`${label}: horizontal page scroll`);
      }

      console.log(`${label.padEnd(30)} cards=${r.cards} h=${r.median}px (${r.min}-${r.max}) stock=${r.statesStock} soldOut=${r.soldOutVisible} glyph=${r.placeholder} commit="${(r.commit ?? '').slice(0, 26)}"`);
      await page.close();
    }
  }
}

// The comparison the owner actually made: the same layout, the two screens, side by side.
if (STAGE === 'after') {
  for (const layout of ['grid', 'list']) {
    for (const size of SIZES) {
      const bar = seen[`bar-${layout}|${size.name}|light`];
      const pos = seen[`pos-${layout}|${size.name}|light`];
      if (! bar || ! pos) continue;

      const gap = Math.abs((bar.median ?? 0) - (pos.median ?? 0));
      if (gap > 4) fail(`${layout}/${size.name}: the two bars still disagree — Bar ${bar.median}px vs POS ${pos.median}px`);
      else console.log(`  ${layout}/${size.name}: both bars ${bar.median}px / ${pos.median}px — one card`);
    }
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS → ${OUT}`);
process.exit(failed ? 1 : 0);
