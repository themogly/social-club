// Prompt 186 — a handover in progress, and a drawer that has passed through two shifts.
//
//   npm run build
//   php artisan test tests/Browser/TillHandoverHarnessTest.php
//   node tests/Browser/shoot-till-handover.mjs   → storage/app/screenshots/186/

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';

const OUT = 'storage/app/screenshots/186';
mkdirSync(OUT, { recursive: true });
const HIDE = '[x-show]{display:none !important}[data-counter-surface]{display:none !important}';

const browser = await chromium.launch();
let failed = false;

for (const state of ['inprogress', 'twoshifts']) {
  for (const vp of [{ n: '1180x820', w: 1180, h: 820 }, { n: '820x1180', w: 820, h: 1180 }]) {
    for (const theme of ['light', 'dark']) {
      const page = await browser.newPage({ viewport: { width: vp.w, height: vp.h }, colorScheme: theme, reducedMotion: 'reduce' });
      await page.goto(pathToFileURL(resolve(`storage/app/handover-${state}.html`)).href);
      await page.addStyleTag({ content: HIDE });
      await page.waitForTimeout(150);
      await page.screenshot({ path: `${OUT}/handover-${state}-${vp.n}-${theme}.png`, fullPage: true });

      if (theme === 'light') {
        const m = await page.evaluate(() => {
          const panel = document.querySelector('[data-handover]');
          const text = panel?.innerText ?? '';
          return {
            panel: !!panel,
            // A blind count: no euro figure other than the operator's own empty input may appear here.
            revealsFigure: /€\s?\d/.test(text),
            small: [...(panel?.querySelectorAll('button, input') ?? [])].filter((n) => {
              const r = n.getBoundingClientRect();
              return r.width > 0 && r.height > 0 && (r.width < 44 || r.height < 44);
            }).length,
            hScroll: document.documentElement.scrollWidth > window.innerWidth + 1,
          };
        });

        if (!m.panel) { console.error(`FAIL ${state} @ ${vp.n}: no handover panel`); failed = true; }
        if (m.revealsFigure) { console.error(`FAIL ${state} @ ${vp.n}: the handover panel shows a cash figure — the count must be blind`); failed = true; }
        if (m.small) { console.error(`FAIL ${state} @ ${vp.n}: ${m.small} control(s) under 44px`); failed = true; }
        if (m.hScroll) { console.error(`FAIL ${state} @ ${vp.n}: scrolls sideways`); failed = true; }
        console.log(`${state} @ ${vp.n}: panel ok, blind=${!m.revealsFigure}, ${m.small} under 44px`);
      }
      await page.close();
    }
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS — captures in ${OUT}`);
process.exit(failed ? 1 : 0);
