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
// It also asserts its own SAMPLE COUNT. A selector that matched three elements would otherwise print ALL PASS
// over a page with twenty-two, which is the same defect prompt 205 found in measure-topbar.mjs.

import { chromium, devices } from 'playwright';
import { access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const STATES = [
  { key: 'initial', file: 'storage/app/applicant-217-initial.html', minControls: 20 },
  { key: 'scanned', file: 'storage/app/applicant-217-scanned.html', minControls: 24 },
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
      };
    }, FLOOR);

    // A measurement that sampled almost nothing must never report PASS.
    if (r.sampled < state.minControls) {
      fail(`${state.key}/${theme}: SAMPLED ONLY ${r.sampled} controls (expected ≥${state.minControls}) — the selector is stale, nothing was audited`);
    }
    if (r.under.length) fail(`${state.key}/${theme}: ${r.under.length} under ${FLOOR}px — ${r.under.join('; ')}`);
    if (r.hScroll) fail(`${state.key}/${theme}: horizontal page scroll at 390px`);

    console.log(`=== ${state.key} / ${theme} === ${r.under.length === 0 ? 'PASS' : 'FAIL'} (${r.sampled} controls sampled, ${r.under.length} under ${FLOOR}px) hScroll=${r.hScroll}`);
    await page.close();
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : '\nRESULT: ALL PASS');
process.exit(failed ? 1 : 0);
