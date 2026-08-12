// Prompt 224 — the bar-only visit on the dispensary, before and after.
//
//   npm run build
//   php artisan test tests/Browser/BarOnlyCartHarnessTest.php
//   node tests/Browser/shoot-bar-only-cart.mjs [after|before]
//
// The whole argument in one frame: a member attached, Barra selected, two beers tapped. It reports what the
// cart column actually contains, so "the money is nowhere" is a measurement rather than a look.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/224';
mkdirSync(OUT, { recursive: true });

const file = resolve('storage/app/bar-only-224.html');
try { await access(file); } catch { console.error('FAIL: run BarOnlyCartHarnessTest first'); process.exit(1); }

const browser = await chromium.launch();
let failed = false;

for (const size of [{ name: '1180x820', width: 1180, height: 820 }, { name: '820x1180', width: 820, height: 1180 }]) {
  for (const theme of ['light', 'dark']) {
    const page = await browser.newPage({ viewport: size, colorScheme: theme, reducedMotion: 'reduce', hasTouch: true });
    await page.goto(pathToFileURL(file).href);
    await page.evaluate(() => {
      document.querySelectorAll('[data-counter-surface],[x-cloak]').forEach((n) => { n.style.display = 'none'; });
    });

    await page.screenshot({ path: `${OUT}/${STAGE}-bar-only-${size.name}-${theme}.png` });

    const r = await page.evaluate(() => {
      const column = document.querySelector('[data-cart-column]');
      const text = column?.innerText ?? '';

      return {
        barSection: !! document.querySelector('[data-cart-bar-section]'),
        settle: !! document.querySelector('[data-settle-visit]'),
        tender: text.includes('Efectivo entregado') || text.includes('Cash handed over'),
        showsTheMoney: /5[.,]00/.test(text),
        emptyHint: !! document.querySelector('[data-empty-basket-hint]'),
      };
    });

    console.log(`${STAGE}/${size.name}/${theme}  bar=${r.barSection} settle=${r.settle} tender=${r.tender} money=${r.showsTheMoney} emptyHint=${r.emptyHint}`);

    if (STAGE === 'after' && (! r.barSection || ! r.settle || ! r.tender || ! r.showsTheMoney)) {
      console.error(`FAIL: ${size.name}/${theme} — the bar-only visit is still not fully rendered`);
      failed = true;
    }

    await page.close();
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: SHOT → ${OUT}`);
process.exit(failed ? 1 : 0);
