// Prompt 213 — the genetics pane and the cart, before and after.
//
//   npm run build
//   php artisan test tests/Browser/SignalsHarnessTest.php   # → storage/app/signals-213.html
//   node tests/Browser/shoot-signals.mjs after              # (or `before`, from a stashed tree)
//
// Counts what the pictures are about: how many of the six genetics badge "low" (all of them on `main`, none
// after), and whether the price-override FIELDS are in the DOM at all before anybody opens them.

import { chromium } from 'playwright';
import { mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/213';
const SIZES = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];

await mkdir(OUT, { recursive: true });
const file = resolve('storage/app/signals-213.html');
try { await access(file); } catch { console.error('FAIL: run SignalsHarnessTest first'); process.exit(1); }

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
      // The counter card's own wording (`Stock bajo` / `Low stock`), not the member menu's.
      const lowWords = ['Stock bajo', 'Low stock'];
      return {
        cards: cards.length,
        badgedLow: cards.filter((c) => lowWords.some((w) => c.innerText.includes(w))).length,
        // The override's FIELDS, not its toggle — the question is whether they exist unasked-for.
        overrideFields: document.querySelectorAll('input[wire\\:model\\.blur="priceOverrideEuros"]').length,
        overrideToggle: document.querySelectorAll('[data-price-override-toggle]').length,
        hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      };
    });

    if (r.hScroll) fail(`${size.name}/${theme}: horizontal page scroll`);
    if (STAGE === 'after') {
      if (r.badgedLow !== 0) fail(`after still badges ${r.badgedLow}/${r.cards} genetics low`);
      if (r.overrideFields !== 0) fail(`after still renders the override fields unasked (${r.overrideFields})`);
      if (r.overrideToggle !== 1) fail(`after has ${r.overrideToggle} override toggles, expected 1`);
    }

    console.log(`${STAGE} ${size.name} ${theme}: ${r.badgedLow}/${r.cards} badged low, override fields in DOM: ${r.overrideFields}, toggle: ${r.overrideToggle}`);
    await page.close();
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS (${STAGE})`);
process.exit(failed ? 1 : 0);
