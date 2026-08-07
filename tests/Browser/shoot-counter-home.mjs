// Prompt 189 — screenshots the counter home at both tablet orientations, light and dark, and asserts what a
// picture cannot: every tile is a genuinely large target (far above the 44px floor, which is the reason a hub
// beats a menu bar on a tablet), no control is under 44x44, and the page never scrolls sideways.
//
//   npm install --no-save playwright && node_modules/.bin/playwright install chromium-headless-shell
//   npm run build
//   php artisan test tests/Browser/CounterHomeHarnessTest.php   # writes storage/app/counter-home.html
//   node tests/Browser/shoot-counter-home.mjs

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';

const url = pathToFileURL(resolve('storage/app/counter-home.html')).href;
const VIEWPORTS = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];
const THEMES = ['light', 'dark'];
const OUT = 'storage/app/screenshots/189';
mkdirSync(OUT, { recursive: true });

// No Alpine here: the home screen is server-rendered markup. The 173 surface is the only x-show element and
// it is down (an operator is identified), so hiding x-show elements reproduces what the operator sees.
const HIDE_ALPINE_SHOWN = '[x-show]{display:none !important}[data-counter-surface]{display:none !important}';

const browser = await chromium.launch();
let failed = false;

for (const vp of VIEWPORTS) {
  for (const theme of THEMES) {
    const page = await browser.newPage({
      colorScheme: theme,
      viewport: { width: vp.width, height: vp.height },
    });
    await page.goto(url);
    await page.addStyleTag({ content: HIDE_ALPINE_SHOWN });
    await page.waitForTimeout(120);
    await page.screenshot({ path: `${OUT}/home-${vp.name}-${theme}.png`, fullPage: false });

    const tiles = await page.$$eval('[data-counter-home-tile]', (n) =>
      n.map((t) => ({ r: t.getAttribute('data-counter-home-tile'), w: t.getBoundingClientRect().width, h: t.getBoundingClientRect().height })));

    if (tiles.length === 0) {
      console.error(`FAIL ${vp.name}/${theme}: no tiles rendered`);
      failed = true;
    }
    for (const t of tiles) {
      // The whole argument for a hub on a tablet: these are finger targets, not menu items.
      if (t.h < 96 || t.w < 140) {
        console.error(`FAIL ${vp.name}/${theme}: tile ${t.r} is ${Math.round(t.w)}x${Math.round(t.h)} — too small for a finger`);
        failed = true;
      }
    }

    const under44 = await page.$$eval('a, button', (n) =>
      n.filter((e) => {
        const b = e.getBoundingClientRect();
        return b.width > 0 && (b.width < 44 || b.height < 44);
      }).length);
    if (under44 > 0) {
      console.error(`FAIL ${vp.name}/${theme}: ${under44} control(s) under 44x44`);
      failed = true;
    }

    if (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)) {
      console.error(`FAIL ${vp.name}/${theme}: horizontal page scroll`);
      failed = true;
    }

    console.log(`${vp.name}/${theme}: ${tiles.length} tiles, largest ${Math.round(Math.max(...tiles.map((t) => t.h)))}px tall`);
    await page.close();
  }
}

await browser.close();
console.log(failed ? 'FAILED' : `OK — ${VIEWPORTS.length * THEMES.length} captures in ${OUT}`);
process.exit(failed ? 1 : 0);
