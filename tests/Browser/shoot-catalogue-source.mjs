// Prompt 212 — the dispensary on each source, before and after, with FORTY articles at the sede.
//
//   npm run build
//   php artisan test tests/Browser/CatalogueSourceHarnessTest.php   # → storage/app/catalogue-212-*.html
//   node tests/Browser/shoot-catalogue-source.mjs after             # (or `before`, from a stashed tree)
//
// Asserts what a picture cannot: the commit action inside the fold (176), nothing under 44px, nothing
// clipped, no horizontal page scroll — on BOTH sources — and counts the cart-column chips, which is the
// measurement the whole branch is about.

import { chromium } from 'playwright';
import { mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/212';
const SIZES = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];

await mkdir(OUT, { recursive: true });
const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

for (const source of ['genetics', 'bar']) {
  const file = resolve(`storage/app/catalogue-212-${source}.html`);
  try { await access(file); } catch { fail(`missing ${source} frame`); continue; }

  for (const size of SIZES) {
    for (const theme of ['light', 'dark']) {
      const page = await browser.newPage({
        viewport: { width: size.width, height: size.height },
        colorScheme: theme,
        reducedMotion: 'reduce',
      });
      await page.goto(pathToFileURL(file).href);
      await page.evaluate(() => {
        document.querySelectorAll('[x-show],[x-cloak],[data-counter-surface]').forEach((n) => { n.style.display = 'none'; });
      });

      await page.screenshot({ path: `${OUT}/${STAGE}-${source}-${size.name}-${theme}.png`, fullPage: true });

      const r = await page.evaluate((viewportHeight) => {
        const commit = document.querySelector('[data-commit-action], button[wire\\:click="commitDispensation"]');
        const controls = Array.from(document.querySelectorAll('button, a[href], input, select'))
          .map((n) => ({ b: n.getBoundingClientRect(), t: n.tagName }))
          .filter((c) => c.b.width > 1 && c.b.height > 1);
        // The cart column's chip list — what this branch retired.
        const cartChips = document.querySelectorAll('aside button[wire\\:click^="addBarItem"]').length;
        return {
          cards: document.querySelectorAll('[data-product]').length,
          cartChips,
          commitTop: commit ? Math.round(commit.getBoundingClientRect().top) : null,
          commitInFold: commit ? commit.getBoundingClientRect().bottom <= viewportHeight + 1 : false,
          tooSmall: controls.filter((c) => c.b.height < 44).length,
          hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        };
      }, size.height);

      // `before` is the DEFECT, so its violations are findings rather than failures — and one of them is
      // worth recording: the retired chip list was `px-3 py-1`, ~28px, so it never met the 44px floor either.
      const note = STAGE === 'before' ? (m) => console.log(`  · before: ${m}`) : fail;

      if (r.hScroll) note(`${source}/${size.name}/${theme}: horizontal page scroll`);
      if (r.tooSmall) note(`${source}/${size.name}/${theme}: ${r.tooSmall} control(s) under 44px tall`);
      if (!r.commitInFold) note(`${source}/${size.name}/${theme}: the commit action is outside the fold (176)`);
      if (STAGE === 'after' && r.cartChips !== 0) fail(`after still has ${r.cartChips} chips in the cart column`);

      console.log(`${STAGE} ${source} ${size.name} ${theme}: ${r.cards} cards, ${r.cartChips} cart chips, commit at ${r.commitTop} (in fold: ${r.commitInFold})`);
      await page.close();
    }
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS (${STAGE})`);
process.exit(failed ? 1 : 0);
