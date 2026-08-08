// Prompt 217 — the applicant's form measured at PHONE size, in both of its states.
//
//   npm run build
//   php artisan test tests/Browser/ApplicantFormHarnessTest.php   # → storage/app/applicant-217-*.html
//   node tests/Browser/measure-applicant-form.mjs
//
// **390×844 with touch**, because that is the size that matters: `socio/application.blade.php` is the one
// genuinely phone-first surface in the product, and every other harness in this folder measures desktop or
// tablet — which is exactly how this page was missed. It enumerates EVERY interactive element and exits
// non-zero on any under 44×44.
//
// Prompt 220 added the signature pad to this page, so it also checks the pad is here and big enough to sign
// on with a finger — a collapsed canvas passes every touch-target assertion while being unusable.
//
// It also asserts its own SAMPLE COUNT. A selector that matched three elements would otherwise print ALL PASS
// over a page with twenty-two, which is the same defect prompt 205 found in measure-topbar.mjs.

import { chromium, devices } from 'playwright';
import { access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

// Counts rose by two in prompt 220: the shared signature pad puts Borrar + Guardar firma on this page.
// Bumped deliberately — the point of asserting the sample count is that it moves when the page does.
const STATES = [
  { key: 'initial', file: 'storage/app/applicant-217-initial.html', minControls: 22 },
  { key: 'scanned', file: 'storage/app/applicant-217-scanned.html', minControls: 26 },
];
const FLOOR = 44;

const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

for (const state of STATES) {
  const file = resolve(state.file);
  try { await access(file); } catch { fail(`missing ${state.key} harness — run ApplicantFormHarnessTest first`); continue; }

  for (const theme of ['light', 'dark']) {
    const page = await browser.newPage({
      ...devices['iPhone 14 Pro'],   // 393×852, touch, mobile — the audience this page is for
      viewport: { width: 390, height: 844 },
      colorScheme: theme,
      reducedMotion: 'reduce',
    });
    await page.goto(pathToFileURL(file).href);

    const r = await page.evaluate((FLOOR) => {
      // A checkbox's touch target is its LABEL when there is one — that is what a finger hits, and it is
      // the whole construction prompt 217 gave all three checkboxes.
      const nodes = Array.from(document.querySelectorAll('a[href], button, input, select, textarea, summary'));
      const targets = nodes
        .map((n) => ((n.type === 'checkbox' || n.type === 'radio') ? (n.closest('label') ?? n) : n))
        .map((n) => ({
          box: n.getBoundingClientRect(),
          what: `${n.tagName.toLowerCase()}${n.type ? '[' + n.type + ']' : ''} ${n.getAttribute('name') || n.id || n.textContent.trim().slice(0, 18)}`,
        }))
        // `sr-only` collapses to 1×1 until focused — the skip link pattern, not a touch target.
        .filter((t) => t.box.width > 1 && t.box.height > 1);

      return {
        sampled: targets.length,
        under: targets
          .filter((t) => t.box.height < FLOOR || t.box.width < FLOOR)
          .map((t) => `${Math.round(t.box.width)}×${Math.round(t.box.height)} ${t.what}`),
        hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        // Prompt 220's pad. The canvas is not in the selector list above (it is a drawing surface, not a
        // control), so it is measured explicitly: a signature drawn with a finger on a 390px phone needs
        // room, and a pad that collapsed to nothing would still have passed every assertion above.
        pad: (() => {
          const pad = document.querySelector('[data-signature-pad]');
          if (! pad) return null;
          const canvas = pad.querySelector('[data-signature-canvas]');
          if (! canvas) return { canvas: null };
          const box = canvas.getBoundingClientRect();

          return { canvas: `${Math.round(box.width)}×${Math.round(box.height)}`, width: box.width, height: box.height };
        })(),
      };
    }, FLOOR);

    // A measurement that sampled almost nothing must never report PASS.
    if (r.sampled < state.minControls) {
      fail(`${state.key}/${theme}: SAMPLED ONLY ${r.sampled} controls (expected ≥${state.minControls}) — the selector is stale, nothing was audited`);
    }
    if (r.under.length) fail(`${state.key}/${theme}: ${r.under.length} under ${FLOOR}px — ${r.under.join('; ')}`);
    if (r.hScroll) fail(`${state.key}/${theme}: horizontal page scroll at 390px`);
    if (! r.pad) fail(`${state.key}/${theme}: the signature pad is not on the applicant's form`);
    else if (! r.pad.canvas) fail(`${state.key}/${theme}: the pad rendered without a canvas`);
    else if (r.pad.height < 100 || r.pad.width < 240) fail(`${state.key}/${theme}: the signature canvas is ${r.pad.canvas} — too small to sign with a finger`);

    console.log(`=== ${state.key} / ${theme} === ${r.under.length === 0 ? 'PASS' : 'FAIL'} (${r.sampled} controls sampled, ${r.under.length} under ${FLOOR}px) pad=${r.pad?.canvas ?? 'MISSING'} hScroll=${r.hScroll}`);
    await page.close();
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : '\nRESULT: ALL PASS');
process.exit(failed ? 1 : 0);
