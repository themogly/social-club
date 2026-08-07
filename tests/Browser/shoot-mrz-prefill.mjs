// Prompt 179 — the scan step, a successful prefill with fields marked unconfirmed, and a correction in
// progress. Phone width (where an emailed invite is opened) and tablet (the counter's handover), both
// locales. The "plain" state doubles as the unsupported-browser state: an ordinary form.
//
//   npm run build
//   php artisan test tests/Browser/MrzPrefillHarnessTest.php
//   node tests/Browser/shoot-mrz-prefill.mjs     → storage/app/screenshots/179/

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';

const OUT = 'storage/app/screenshots/179';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
let failed = false;

for (const locale of ['es', 'en']) {
  for (const state of ['plain', 'prefilled', 'correcting']) {
    for (const vp of [{ n: '390', w: 390, h: 844 }, { n: '820x1180', w: 820, h: 1180 }]) {
      const page = await browser.newPage({ viewport: { width: vp.w, height: vp.h }, reducedMotion: 'reduce' });
      await page.goto(pathToFileURL(resolve(`storage/app/mrz-${state}-${locale}.html`)).href);
      // The scan trigger is `hidden` until the module mounts. Reveal it for every state EXCEPT `plain`,
      // which is deliberately photographed as a browser that cannot run the reader would show it.
      if (state !== 'plain') {
        await page.evaluate(() => { const b = document.querySelector('[data-mrz-scan]'); if (b) b.hidden = false; });
      }
      await page.waitForTimeout(150);
      await page.screenshot({ path: `${OUT}/mrz-${state}-${locale}-${vp.n}.png`, fullPage: true });

      const m = await page.evaluate(() => ({
        marked: document.querySelectorAll('[data-mrz-prefilled]').length,
        confirms: [...document.querySelectorAll('[data-mrz-confirm]')].map((n) => {
          const r = n.getBoundingClientRect();
          return { w: Math.round(r.width), h: Math.round(r.height), label: Math.round(n.closest('label')?.getBoundingClientRect().height ?? 0) };
        }),
        hScroll: document.documentElement.scrollWidth > window.innerWidth + 1,
      }));

      if (m.hScroll) { console.error(`FAIL ${state}/${locale} @ ${vp.n}: scrolls sideways`); failed = true; }
      if (state === 'plain' && m.marked !== 0) { console.error(`FAIL plain/${locale}: fields marked with nothing read`); failed = true; }
      if (state !== 'plain' && m.marked === 0) { console.error(`FAIL ${state}/${locale}: nothing marked unconfirmed`); failed = true; }
      // 44x44 on the confirmation affordance — a phone, a tablet, non-staff.
      for (const c of m.confirms) {
        if (c.label < 44) { console.error(`FAIL ${state}/${locale} @ ${vp.n}: confirm target ${c.label}px tall`); failed = true; }
      }
      if (vp.n === '390') console.log(`${state}/${locale}: ${m.marked} marked, ${m.confirms.length} confirms`);
      await page.close();
    }
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS — captures in ${OUT}`);
process.exit(failed ? 1 : 0);
