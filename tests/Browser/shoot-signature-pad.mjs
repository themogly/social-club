// Prompt 220 — the signature pad, on every route that takes one, light and dark.
//
//   npm run build
//   php artisan test tests/Browser/SignedSignUpHarnessTest.php
//   node tests/Browser/shoot-signature-pad.mjs
//
// Three routes, two templates: the applicant's own form IS both the emailed link (their phone, 390) and the
// tablet handed over the counter (1024), so it is shot at both sizes rather than once and assumed. The staff
// form is counter chrome and only ever tablet.
//
// It draws on the canvas before the second shot of each: an empty pad and a signed pad are different states,
// and the signed one is the one nobody looks at until it is wrong.

import { chromium } from 'playwright';
import { mkdirSync, readFileSync } from 'node:fs';
import { access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

// These are file:// pages with the built CSS inlined and NO JavaScript — Alpine ships inside Livewire's
// bundle, which cannot load off a file URL. So the ink is put on the canvas through its own 2D context with
// the component's own settings (2px, round cap, brand blue): real pixels at the real geometry, which is what
// a screenshot is for. The one state that needs Alpine — the `x-show` confirmation line on the PUBLIC form —
// is instead shot server-rendered on the staff form, where a stored signature renders the same panel from PHP.

const OUT = 'storage/app/screenshots/220-signature';
mkdirSync(OUT, { recursive: true });

const SHOTS = [
  { key: 'emailed-link', file: 'storage/app/signature-220-applicant.html', width: 390, height: 844, touch: true },
  { key: 'handover-tablet', file: 'storage/app/signature-220-applicant.html', width: 1024, height: 768, touch: true },
  { key: 'staff-typed', file: 'storage/app/signature-220-staff.html', width: 1024, height: 768, touch: true },
  // The captured state, server-rendered: what the operator sees once the person has signed.
  { key: 'staff-captured', file: 'storage/app/signature-220-staff-captured.html', width: 1024, height: 768, touch: true },
];

const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

for (const shot of SHOTS) {
  const file = resolve(shot.file);
  try { await access(file); } catch { fail(`missing ${shot.key} harness — run SignedSignUpHarnessTest first`); continue; }

  for (const theme of ['light', 'dark']) {
    const page = await browser.newPage({
      viewport: { width: shot.width, height: shot.height },
      colorScheme: theme,
      hasTouch: shot.touch,
      isMobile: shot.width < 500,
      reducedMotion: 'reduce',
    });
    await page.goto(pathToFileURL(file).href);

    const pad = page.locator('[data-signature-pad]').first();
    if (await pad.count() === 0) { fail(`${shot.key}/${theme}: no signature pad on the page`); await page.close(); continue; }

    await pad.scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/${shot.key}-${theme}-empty.png` });
    await pad.screenshot({ path: `${OUT}/${shot.key}-${theme}-pad.png` });

    // Sign it, if there is a canvas to sign — the captured state renders no canvas at all.
    const canvas = pad.locator('[data-signature-canvas]').first();
    if (await canvas.count() > 0) {
      const box = await canvas.boundingBox();
      if (! box) { fail(`${shot.key}/${theme}: the canvas has no box`); await page.close(); continue; }
      if (box.height < 100) fail(`${shot.key}/${theme}: the canvas is only ${Math.round(box.height)}px tall`);

      await canvas.evaluate((c) => {
        c.width = c.offsetWidth; c.height = 150;           // the component's own init
        const ctx = c.getContext('2d');
        ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#2563eb';
        const pts = [[0.18, 0.66], [0.26, 0.3], [0.34, 0.7], [0.44, 0.35], [0.5, 0.6], [0.62, 0.28], [0.7, 0.62], [0.82, 0.4]];
        ctx.beginPath();
        ctx.moveTo(c.width * pts[0][0], c.height * pts[0][1]);
        pts.slice(1).forEach(([x, y]) => ctx.lineTo(c.width * x, c.height * y));
        ctx.stroke();
      });
      await pad.screenshot({ path: `${OUT}/${shot.key}-${theme}-signed.png` });
      console.log(`shot ${shot.key} / ${theme} — pad ${Math.round(box.width)}×${Math.round(box.height)}`);
    } else {
      console.log(`shot ${shot.key} / ${theme} — captured state (no canvas)`);
    }

    await page.close();
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL SHOT → ${OUT}`);
process.exit(failed ? 1 : 0);
