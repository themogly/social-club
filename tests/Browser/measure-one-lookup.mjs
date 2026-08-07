// Prompt 194 — measures THE member lookup on every counter screen that identifies a socio, at both tablet
// orientations, with its results on screen.
//
// The question this answers: after collapsing two stacked inputs into one, are the field AND the first result
// row reachable without scrolling? A lookup whose results fall below the fold is the same defect as a commit
// button below the fold — the operator has a socio in front of them and has to hunt.
//
// Playwright is intentionally NOT a CI dependency (it needs a ~100MB browser). Run it by hand:
//   npm install --no-save playwright && node_modules/.bin/playwright install chromium-headless-shell
//   npm run build                                            # the harness inlines the BUILT css
//   php artisan test tests/Browser/OneLookupHarnessTest.php   # writes storage/app/lookup-*.html
//   node tests/Browser/measure-one-lookup.mjs
//
// Writes to storage/app/screenshots/194/ and prints a table. Exits non-zero if the input or the first result
// row is outside the viewport, if a row is under 44x44, or if the page scrolls sideways.

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';

// Two criteria, because the screens are two different kinds of page and one rule would be dishonest on both.
//
//   FOLD     — the input and the first result row are inside the viewport at scroll 0. This is the bar for a
//              screen whose reason to exist is identifying somebody: the door, the dispensary (blocked and
//              resolved), Socios, and the bar's socio panel.
//   TOGETHER — the input and the first row fit within ONE viewport height of each other. The caja is a long
//              stacked page and `Cobrar cuota` is its FOURTH section (measured: the section opens at y=1413
//              of a 2016px page, and the input 115px into it — exactly where the old feeSearch box sat, so
//              194 did not move it). Demanding scroll-0 visibility there would mean re-laying-out the whole
//              till screen, which is not this branch. What 194 IS responsible for is that the results it now
//              renders below the field do not fall out of view once the operator is at the panel.
const SCREENS = [
  { name: 'checkin', rule: 'FOLD' },
  { name: 'dispensary-blocker', rule: 'FOLD' },
  { name: 'dispensary-pane', rule: 'FOLD' },
  { name: 'socios', rule: 'FOLD' },
  { name: 'till', rule: 'TOGETHER' },
  { name: 'bar', rule: 'FOLD' },
];
const VIEWPORTS = [
  { name: '1180x820', width: 1180, height: 820 }, // iPad landscape — the counter's working orientation
  { name: '820x1180', width: 820, height: 1180 }, // and portrait, which is how it is handed over
];
const WIDTHS_NO_HSCROLL = [820, 1024, 1180, 1440];
const THEMES = ['light', 'dark'];

// Static captures with no Alpine running, so every `x-show` element renders VISIBLE — the offline banner,
// the overflow menu, the 173 handover surface. In the real app Alpine hides them on boot; leaving them
// visible here shifts everything below them down and the fold measurement becomes a measurement of a page
// no operator ever sees. Same convention as measure-cart-column.mjs.
const HIDE_ALPINE_SHOWN = '[x-show]{display:none !important}[data-counter-surface]{display:none !important}';

const OUT = 'storage/app/screenshots/194';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
let failed = false;
const rows = [];

for (const { name: screen, rule } of SCREENS) {
  const url = pathToFileURL(resolve(`storage/app/lookup-${screen}.html`)).href;

  for (const vp of VIEWPORTS) {
    const page = await browser.newPage({
      colorScheme: 'light',
      reducedMotion: 'reduce',
      viewport: { width: vp.width, height: vp.height },
    });
    await page.goto(url);
    await page.addStyleTag({ content: HIDE_ALPINE_SHOWN });
    await page.waitForTimeout(150);

    const m = await page.evaluate(() => {
      const box = (el) => {
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return { top: Math.round(r.top), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) };
      };

      const input = document.querySelector('#member-lookup');
      const results = [...document.querySelectorAll('[data-member-lookup-result]')];

      return {
        input: box(input),
        firstRow: box(results[0]),
        rowCount: results.length,
        smallRows: results
          .map((r) => r.getBoundingClientRect())
          .filter((r) => r.width < 44 || r.height < 44)
          .map((r) => `${Math.round(r.width)}x${Math.round(r.height)}`),
        viewportH: window.innerHeight,
        pageHeight: Math.round(document.documentElement.scrollHeight),
      };
    });

    const inside = (b) => b !== null && b.top >= 0 && b.bottom <= m.viewportH;
    const present = m.input !== null && m.firstRow !== null;
    // Both criteria are computed for every screen; only the screen's own one can fail it.
    const foldOk = inside(m.input) && inside(m.firstRow);
    const togetherOk = present && m.firstRow.bottom - m.input.top <= m.viewportH;
    const ok = rule === 'FOLD' ? foldOk : togetherOk;

    rows.push({
      screen,
      viewport: vp.name,
      rule,
      page: m.pageHeight,
      input: m.input ? `${m.input.top}–${m.input.bottom}` : 'NOT FOUND',
      firstRow: m.firstRow ? `${m.firstRow.top}–${m.firstRow.bottom}` : 'NOT FOUND',
      rows: m.rowCount,
      span: present ? `${m.firstRow.bottom - m.input.top}px` : '—',
      aboveFold: foldOk ? 'YES' : 'no',
      pass: ok ? 'PASS' : 'FAIL',
    });

    if (m.input === null) {
      console.error(`FAIL ${screen} @ ${vp.name}: no #member-lookup on the page`);
      failed = true;
    } else if (m.firstRow === null) {
      console.error(`FAIL ${screen} @ ${vp.name}: no result row rendered`);
      failed = true;
    } else if (!ok && rule === 'FOLD') {
      console.error(
        `FAIL ${screen} @ ${vp.name}: input y=${m.input.top}–${m.input.bottom}, first row y=${m.firstRow.top}–${m.firstRow.bottom}, viewport ${m.viewportH} — not both above the fold`
      );
      failed = true;
    } else if (!ok) {
      console.error(
        `FAIL ${screen} @ ${vp.name}: the field and its first result span ${m.firstRow.bottom - m.input.top}px, taller than the ${m.viewportH}px viewport`
      );
      failed = true;
    }

    if (m.smallRows.length) {
      console.error(`FAIL ${screen} @ ${vp.name}: ${m.smallRows.length} result row(s) under 44x44 → ${m.smallRows.join(' | ')}`);
      failed = true;
    }

    for (const theme of THEMES) {
      const shotPage = await browser.newPage({
        colorScheme: theme,
        reducedMotion: 'reduce',
        viewport: { width: vp.width, height: vp.height },
      });
      await shotPage.goto(url);
      await shotPage.addStyleTag({ content: HIDE_ALPINE_SHOWN });
      await shotPage.waitForTimeout(150);
      await shotPage.screenshot({ path: `${OUT}/${screen}-${vp.name}-${theme}.png`, fullPage: false });
      await shotPage.close();
    }

    await page.close();
  }

  for (const width of WIDTHS_NO_HSCROLL) {
    const page = await browser.newPage({ viewport: { width, height: 820 }, reducedMotion: 'reduce' });
    await page.goto(url);
    await page.addStyleTag({ content: HIDE_ALPINE_SHOWN });
    await page.waitForTimeout(120);

    if (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)) {
      console.error(`FAIL ${screen} @ ${width}px: page scrolls horizontally`);
      failed = true;
    }

    await page.close();
  }
}

await browser.close();

console.table(rows);
console.log(failed ? '\nRESULT: FAIL' : '\nRESULT: ALL PASS');
process.exit(failed ? 1 : 0);
