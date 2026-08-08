// Prompt 216 — the POS grid with a fast mover and a slow mover holding IDENTICAL stock.
//
//   npm run build
//   php artisan test tests/Browser/StockCoverHarnessTest.php   # → storage/app/cover-216.html
//   node tests/Browser/shoot-stock-cover.mjs after             # (or `before`, from a stashed tree)
//
// Counts what the picture is about: how many of the two badge, and whether the badge is a FIGURE or the
// word. On `main` a flat threshold cannot tell them apart, so they behave identically — which is the finding.

import { chromium } from 'playwright';
import { mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/216';
const SIZES = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];

await mkdir(OUT, { recursive: true });
const file = resolve('storage/app/cover-216.html');
try { await access(file); } catch { console.error('FAIL: run StockCoverHarnessTest first'); process.exit(1); }

const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

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

    await page.screenshot({ path: `${OUT}/${STAGE}-${size.name}-${theme}.png`, fullPage: true });

    const r = await page.evaluate(() => {
      const cards = Array.from(document.querySelectorAll('[data-product]'));
      const lowWords = ['Stock bajo', 'Low stock'];
      const badged = cards.filter((c) => lowWords.some((w) => c.innerText.includes(w)) || c.innerText.includes('≈'));
      return {
        cards: cards.length,
        badged: badged.length,
        figures: cards.filter((c) => c.innerText.includes('≈')).length,
        words: cards.filter((c) => lowWords.some((w) => c.innerText.includes(w))).length,
        hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      };
    });

    if (r.hScroll) fail(`${size.name}/${theme}: horizontal page scroll`);
    if (STAGE === 'after') {
      if (r.cards !== 2) fail(`after: ${r.cards} cards, expected the fast and the slow mover`);
      if (r.badged !== 1) fail(`after: ${r.badged}/2 badged — identical stock must NOT badge identically`);
      if (r.figures !== 1) fail(`after: ${r.figures} card(s) carry a cover figure, expected 1`);
      if (r.words !== 0) fail(`after: ${r.words} card(s) still say the word instead of the figure`);
    }

    console.log(`${STAGE} ${size.name} ${theme}: ${r.badged}/${r.cards} badged (${r.figures} as a figure, ${r.words} as the word), hScroll=${r.hScroll}`);
    await page.close();
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS (${STAGE})`);
process.exit(failed ? 1 : 0);
