// Prompt 185 — the member menu's three availability states at phone width, both locales, both themes.
//
//   npm run build
//   php artisan test tests/Browser/MenuAvailabilityHarnessTest.php
//   node tests/Browser/shoot-menu-availability.mjs   → storage/app/screenshots/185/

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';

const OUT = 'storage/app/screenshots/185';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
let failed = false;

for (const locale of ['es', 'en']) {
  for (const theme of ['light', 'dark']) {
    const page = await browser.newPage({
      viewport: { width: 390, height: 844 },
      colorScheme: theme,
      reducedMotion: 'reduce',
    });
    await page.goto(pathToFileURL(resolve(`storage/app/menu-${locale}.html`)).href);
    await page.addStyleTag({ content: '[x-show]{display:none !important}' });
    await page.waitForTimeout(150);
    await page.screenshot({ path: `${OUT}/menu-${locale}-${theme}-390.png`, fullPage: true });

    const m = await page.evaluate(() => ({
      states: [...document.querySelectorAll('[data-availability]')].map((n) => `${n.dataset.availability}:${n.innerText.trim()}`),
      hScroll: document.documentElement.scrollWidth > window.innerWidth + 1,
    }));

    if (m.states.length !== 3) { console.error(`FAIL ${locale}: ${m.states.length} states, expected 3`); failed = true; }
    if (m.hScroll) { console.error(`FAIL ${locale}: the menu scrolls sideways at 390px`); failed = true; }
    if (theme === 'light') console.log(`${locale}: ${m.states.join('  |  ')}`);

    await page.close();
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS — captures in ${OUT}`);
process.exit(failed ? 1 : 0);
