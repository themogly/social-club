// Prompt 177 — the member record at the counter, in its three states, at both tablet orientations and
// both themes. Asserts what a picture cannot: no control under 44x44, no horizontal scroll, and no
// document/DNI/scan anywhere on screen.
//
//   npm run build
//   php artisan test tests/Browser/MemberRecordHarnessTest.php
//   node tests/Browser/shoot-member-record.mjs      → storage/app/screenshots/177/

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';

const STATES = ['active', 'owing', 'bare'];
const VIEWPORTS = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];
const HIDE = '[x-show]{display:none !important}[data-counter-surface]{display:none !important}';

const OUT = 'storage/app/screenshots/177';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
let failed = false;

for (const state of STATES) {
  const url = pathToFileURL(resolve(`storage/app/record-${state}.html`)).href;

  for (const vp of VIEWPORTS) {
    for (const theme of ['light', 'dark']) {
      const page = await browser.newPage({
        viewport: { width: vp.width, height: vp.height },
        colorScheme: theme,
        reducedMotion: 'reduce',
      });
      await page.goto(url);
      await page.addStyleTag({ content: HIDE });
      await page.waitForTimeout(150);
      await page.screenshot({ path: `${OUT}/record-${state}-${vp.name}-${theme}.png` });

      if (theme === 'light') {
        const m = await page.evaluate(() => {
          const small = [...document.querySelectorAll('button:not([disabled]), a[href], select, input')]
            .filter((n) => {
              const r = n.getBoundingClientRect();
              const s = getComputedStyle(n);
              if (r.width === 0 || r.height === 0) return false;
              if (s.display === 'none' || s.visibility === 'hidden') return false;
              return r.width < 44 || r.height < 44;
            })
            .map((n) => `${n.tagName.toLowerCase()}"${(n.innerText || n.getAttribute('aria-label') || '').trim().slice(0, 24)}" ${Math.round(n.getBoundingClientRect().width)}x${Math.round(n.getBoundingClientRect().height)}`);

          return {
            small,
            hScroll: document.documentElement.scrollWidth > window.innerWidth + 1,
            hasRecord: !!document.querySelector('[data-member-record]'),
            text: document.body.innerText,
          };
        });

        if (!m.hasRecord) { console.error(`FAIL ${state} @ ${vp.name}: no member record panel`); failed = true; }
        if (m.hScroll) { console.error(`FAIL ${state} @ ${vp.name}: page scrolls sideways`); failed = true; }
        if (m.small.length) { console.error(`FAIL ${state} @ ${vp.name}: under 44x44 → ${m.small.join(' | ')}`); failed = true; }
        for (const forbidden of ['member-id-scans', 'document_scan', 'medical_cert']) {
          if (m.text.includes(forbidden)) { console.error(`FAIL ${state}: Article 9 material on screen (${forbidden})`); failed = true; }
        }
        console.log(`${state} @ ${vp.name}: record present, ${m.small.length} controls under 44px, hScroll=${m.hScroll}`);
      }

      await page.close();
    }
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS — captures in ${OUT}`);
process.exit(failed ? 1 : 0);
