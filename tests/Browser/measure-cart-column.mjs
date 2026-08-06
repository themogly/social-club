// Prompt 176 — measures the two SELLING screens in their resolved states (a socio identified, a basket
// empty and full) at both tablet orientations, and screenshots them in both themes.
//
// The measurement that justifies the branch: WHERE IS THE COMMIT BUTTON. On `main` it was below the fold
// on Barra in both orientations and on Dispensario in portrait, so an operator could not complete a sale
// without hunting for it.
//
// Playwright is intentionally NOT a CI dependency (it needs a ~100MB browser). Run it by hand:
//   npm install --no-save playwright && node_modules/.bin/playwright install chromium-headless-shell
//   npm run build                                              # the harness inlines the BUILT css
//   php artisan test tests/Browser/CartColumnHarnessTest.php    # writes storage/app/cart-*.html
//   node tests/Browser/measure-cart-column.mjs
//
// Writes to storage/app/screenshots/176/ and prints a table. Exits non-zero if a commit action is outside
// the viewport, if no product is visible without scrolling, if the page scrolls sideways, or if a control
// on a selling screen is under 44x44.

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';

const SCREENS = ['dispensary-empty', 'dispensary-full', 'bar-empty', 'bar-full'];
const VIEWPORTS = [
  { name: '1180x820', width: 1180, height: 820 }, // iPad landscape — the counter's working orientation
  { name: '820x1180', width: 820, height: 1180 }, // and portrait, which is how it is handed over
];
const WIDTHS_NO_HSCROLL = [820, 1024, 1180, 1440];
const THEMES = ['light', 'dark'];

// Static captures with no Alpine running, so every `x-show` element renders VISIBLE — the offline banner,
// the overflow menu, the 173 handover surface. In the real app Alpine hides them on boot; hiding them here
// reproduces what an operator actually sees. Same convention as shoot-blocking-states.mjs.
const HIDE_ALPINE_SHOWN = '[x-show]{display:none !important}[data-counter-surface]{display:none !important}';

// The commit action: `data-commit-action` after this branch, the raw wire:click before it, so the SAME
// script measures both sides of the comparison.
const COMMIT = '[data-commit-action], button[wire\\:click="commit"], button[wire\\:click^="commit"]';

const OUT = 'storage/app/screenshots/176';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
let failed = false;
const rows = [];

for (const screen of SCREENS) {
  const url = pathToFileURL(resolve(`storage/app/cart-${screen}.html`)).href;

  for (const vp of VIEWPORTS) {
    const page = await browser.newPage({
      colorScheme: 'light',
      reducedMotion: 'reduce',
      viewport: { width: vp.width, height: vp.height },
    });
    await page.goto(url);
    await page.addStyleTag({ content: HIDE_ALPINE_SHOWN });
    await page.waitForTimeout(150);

    const m = await page.evaluate((commitSel) => {
      const el = document.querySelector(commitSel);
      const box = el ? el.getBoundingClientRect() : null;
      const doc = document.documentElement;

      // "A product is visible without scrolling": any tile/row in the selection pane whose box is fully
      // inside the viewport at scroll 0.
      const products = [...document.querySelectorAll('[data-product]')];
      const visibleProducts = products.filter((p) => {
        const r = p.getBoundingClientRect();
        return r.top >= 0 && r.bottom <= window.innerHeight && r.height > 0;
      }).length;

      return {
        found: !!el,
        commitTop: box ? Math.round(box.top) : null,
        commitBottom: box ? Math.round(box.bottom) : null,
        commitW: box ? Math.round(box.width) : null,
        commitH: box ? Math.round(box.height) : null,
        pageHeight: Math.round(doc.scrollHeight),
        viewportH: window.innerHeight,
        pageScrolls: doc.scrollHeight > window.innerHeight + 1,
        products: products.length,
        visibleProducts,
      };
    }, COMMIT);

    // Does the cart column stay put when the selection pane scrolls to its end?
    const stayed = await page.evaluate((commitSel) => {
      const el = document.querySelector(commitSel);
      if (!el) return null;
      const before = el.getBoundingClientRect().top;

      const pane = document.querySelector('[data-selection-pane]');
      if (pane && pane.scrollHeight > pane.clientHeight) {
        pane.scrollTop = pane.scrollHeight; // scroll the pane itself
      } else {
        window.scrollTo(0, document.documentElement.scrollHeight); // pre-176: the whole page scrolls
      }

      return { before: Math.round(before), after: Math.round(el.getBoundingClientRect().top) };
    }, COMMIT);
    await page.evaluate(() => window.scrollTo(0, 0));

    const inViewport =
      m.found && m.commitBottom !== null && m.commitBottom <= m.viewportH && m.commitTop >= 0;

    rows.push({
      screen,
      viewport: vp.name,
      page: m.pageHeight,
      commit: m.found ? `${m.commitTop}–${m.commitBottom}` : 'NOT FOUND',
      size: m.found ? `${m.commitW}x${m.commitH}` : '—',
      inViewport: m.found ? (inViewport ? 'YES' : `NO (+${m.commitBottom - m.viewportH}px)`) : 'n/a',
      products: `${m.visibleProducts}/${m.products}`,
      cartMoved: stayed ? (stayed.before === stayed.after ? 'no' : `${stayed.before}→${stayed.after}`) : 'n/a',
    });

    if (!m.found) {
      console.error(`FAIL ${screen} @ ${vp.name}: no commit action found`);
      failed = true;
    } else if (!inViewport) {
      console.error(
        `FAIL ${screen} @ ${vp.name}: commit action at y=${m.commitTop}–${m.commitBottom}, viewport ${m.viewportH} — ${m.commitBottom - m.viewportH}px below the fold`
      );
      failed = true;
    }

    if (m.found && (m.commitW < 44 || m.commitH < 44)) {
      console.error(`FAIL ${screen} @ ${vp.name}: commit action ${m.commitW}x${m.commitH}, under 44x44`);
      failed = true;
    }

    if (m.products > 0 && m.visibleProducts === 0) {
      console.error(`FAIL ${screen} @ ${vp.name}: no product visible without scrolling (${m.products} on screen)`);
      failed = true;
    }

    if (stayed && stayed.before !== stayed.after) {
      console.error(
        `FAIL ${screen} @ ${vp.name}: cart moved when the selection pane scrolled (${stayed.before} → ${stayed.after})`
      );
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

  // --- touch floor + no horizontal scroll, across the four widths ---
  for (const width of WIDTHS_NO_HSCROLL) {
    const page = await browser.newPage({ viewport: { width, height: 820 }, reducedMotion: 'reduce' });
    await page.goto(url);
    await page.addStyleTag({ content: HIDE_ALPINE_SHOWN });
    await page.waitForTimeout(120);

    const hScroll = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    if (hScroll) {
      console.error(`FAIL ${screen} @ ${width}px: page scrolls horizontally`);
      failed = true;
    }

    const small = await page.$$eval(
      'button:not([disabled]), a[href], [role="button"], select, input[type="checkbox"], input[type="radio"]',
      (nodes) =>
        nodes
          .filter((n) => {
            const r = n.getBoundingClientRect();
            const s = getComputedStyle(n);
            if (r.width === 0 || r.height === 0) return false;
            if (s.display === 'none' || s.visibility === 'hidden') return false;
            return r.width < 44 || r.height < 44;
          })
          .map((n) => `${n.tagName.toLowerCase()}"${(n.innerText || n.getAttribute('aria-label') || '').trim().slice(0, 28)}" ${Math.round(n.getBoundingClientRect().width)}x${Math.round(n.getBoundingClientRect().height)}`)
    );

    if (small.length) {
      console.error(`FAIL ${screen} @ ${width}px: ${small.length} control(s) under 44x44 → ${small.join(' | ')}`);
      failed = true;
    }

    await page.close();
  }
}

await browser.close();

console.table(rows);
console.log(failed ? '\nRESULT: FAIL' : '\nRESULT: ALL PASS');
process.exit(failed ? 1 : 0);
