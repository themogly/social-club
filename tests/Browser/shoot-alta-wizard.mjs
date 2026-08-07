// Prompt 174 — the counter's alta states at both tablet orientations and both themes. Portrait matters
// most: that is how a tablet gets handed to somebody.
//
//   npm run build
//   php artisan test tests/Browser/AltaWizardHarnessTest.php
//   node tests/Browser/shoot-alta-wizard.mjs     → storage/app/screenshots/174/

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';

const OUT = 'storage/app/screenshots/174';
mkdirSync(OUT, { recursive: true });
const HIDE = '[x-show]{display:none !important}[data-counter-surface]{display:none !important}';

const browser = await chromium.launch();
let failed = false;

for (const state of ['entry', 'review', 'duplicate']) {
  for (const vp of [{ n: '820x1180', w: 820, h: 1180 }, { n: '1180x820', w: 1180, h: 820 }]) {
    for (const theme of ['light', 'dark']) {
      const page = await browser.newPage({
        viewport: { width: vp.w, height: vp.h }, colorScheme: theme, reducedMotion: 'reduce',
      });
      await page.goto(pathToFileURL(resolve(`storage/app/alta-${state}.html`)).href);
      await page.addStyleTag({ content: HIDE });
      await page.waitForTimeout(150);
      await page.screenshot({ path: `${OUT}/alta-${state}-${vp.n}-${theme}.png`, fullPage: true });

      if (theme === 'light') {
        const m = await page.evaluate(() => ({
          small: [...document.querySelectorAll('button:not([disabled]), a[href], select, input')]
            .filter((n) => {
              const r = n.getBoundingClientRect(); const s = getComputedStyle(n);
              if (r.width === 0 || r.height === 0) return false;
              if (s.display === 'none' || s.visibility === 'hidden') return false;
              return r.width < 44 || r.height < 44;
            })
            .map((n) => `${n.tagName.toLowerCase()}"${(n.innerText || '').trim().slice(0, 20)}" ${Math.round(n.getBoundingClientRect().width)}x${Math.round(n.getBoundingClientRect().height)}`),
          hScroll: document.documentElement.scrollWidth > window.innerWidth + 1,
          panel: !!document.querySelector('[data-alta-panel]'),
        }));

        if (!m.panel) { console.error(`FAIL ${state} @ ${vp.n}: no alta panel`); failed = true; }
        if (m.hScroll) { console.error(`FAIL ${state} @ ${vp.n}: scrolls sideways`); failed = true; }
        if (m.small.length) { console.error(`FAIL ${state} @ ${vp.n}: under 44x44 → ${m.small.join(' | ')}`); failed = true; }
        console.log(`${state} @ ${vp.n}: panel present, ${m.small.length} under 44px`);
      }
      await page.close();
    }
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS — captures in ${OUT}`);
process.exit(failed ? 1 : 0);
