// Prompt 132 — bounding-box check for the counter top bar. Proves NO two interactive controls in the bar
// intersect, and none is under 44×44, at 768 / 800 / 1024 / 1280 (1024 landscape is the one that matters and is
// NOT the narrowest — a fixed-width check catches what the extremes miss). This is what prompt 130 lacked.
//
// **Prompt 206 added the two TABLET orientations** — 1180×820 and 820×1180 — because that branch changed what
// the row CONTAINS (the club's name went back into the home link, the admin control gained a longer word and a
// divider), and a widened row is exactly the change a width-only sweep at a fixed 900px height under-measures.
//
// **Updated by prompt 205, not deleted.** The five-destination row it was written for is gone — the hub is the
// menu — but "no two controls overlap, none under 44px, at four widths" is as valuable on a short row, and a
// short row is exactly where somebody would stop checking. The selector list had to move with it: left
// unchanged it matched ONE element and reported ALL PASS, which is the same defect as an axe sweep that
// audits a redirect. It now asserts a MINIMUM control count, so an empty measurement can never pass again.
//
// Playwright is intentionally NOT a CI dependency (it needs a ~100MB browser). Run it by hand:
//   npm install --no-save playwright && node_modules/.bin/playwright install chromium-headless-shell
//   npm run build
//   php artisan test tests/Browser/TopbarHarnessTest.php   # writes storage/app/topbar-harness.html
//   node tests/Browser/measure-topbar.mjs
//
// Exits non-zero on any overlap, any sub-44px control, or any horizontal page scroll.

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const harness = pathToFileURL(resolve('storage/app/topbar-harness.html')).href;
// width × height, because 206's two required checks are ORIENTATIONS, not widths.
const VIEWPORTS = [
  { width: 768, height: 900 }, { width: 800, height: 900 },
  { width: 1024, height: 900 }, { width: 1280, height: 900 },
  { width: 1180, height: 820 },   // tablet landscape (prompt 206)
  { width: 820, height: 1180 },   // tablet portrait  (prompt 206)
];
// Every interactive control the bar carries after prompt 205. Keyed by attribute so a renamed hook fails
// loudly (the count check below) rather than quietly shrinking what is measured.
const CONTROLS = [
  ['data-counter-home-link', 'home'],
  ['data-counter-sede-current', 'sede'],
  ['data-counter-sede-state', 'sede'],
  ['data-operator-name-chip', 'operator'],
  ['data-counter-lock', 'lock'],
  ['data-counter-admin-link', 'admin'],
  ['data-counter-logout', 'logout'],
  ['data-counter-panic', 'panic'],
];
const SELECTOR = CONTROLS.map(([attr]) => `[data-counter-topbar] [${attr}]`).join(', ');
// All 7 the harness's OWNER fixture renders. Was 6 ("panic depends on the role") — but the fixture is always
// an owner, and the loose floor let a stale measurement through again during prompt 206: a renamed hook
// dropped a control and 6 still read as PASS. A floor that cannot catch the thing it exists for is decoration.
const MIN_CONTROLS = 7;

const browser = await chromium.launch();
let failed = false;

for (const { width, height } of VIEWPORTS) {
  const page = await browser.newPage();
  await page.setViewportSize({ width, height });
  await page.goto(harness);

  const els = await page.$$eval(SELECTOR, (nodes) =>
    nodes
      .map((n) => {
        const r = n.getBoundingClientRect();
        const hooks = [
          ['data-counter-home-link', 'home'], ['data-counter-sede-current', 'sede'],
          ['data-counter-sede-state', 'sede'], ['data-operator-name-chip', 'operator'],
          ['data-counter-lock', 'lock'], ['data-counter-admin-link', 'admin'],
          ['data-counter-logout', 'logout'], ['data-counter-panic', 'panic'],
        ];
        const label = (hooks.find(([attr]) => n.hasAttribute(attr)) ?? [null, '?'])[1];
        return { label, x: r.x, right: r.right, y: r.y, bottom: r.bottom, w: r.width, h: r.height };
      })
      .filter((e) => e.w > 0 && e.h > 0),
  );

  const pageScroll = await page.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
  );

  const overlaps = [];
  for (let i = 0; i < els.length; i++) {
    for (let j = i + 1; j < els.length; j++) {
      const a = els[i], b = els[j];
      const ox = Math.min(a.right, b.right) - Math.max(a.x, b.x);
      const oy = Math.min(a.bottom, b.bottom) - Math.max(a.y, b.y);
      if (ox > 0.5 && oy > 0.5) overlaps.push(`${a.label}∩${b.label}=${Math.round(ox)}px`);
    }
  }
  const under44 = els.filter((e) => e.h < 44 || e.w < 44).map((e) => `${e.label}(${Math.round(e.w)}x${Math.round(e.h)})`);

  const ok = overlaps.length === 0 && under44.length === 0 && !pageScroll;
  // A measurement that found nothing must never report PASS — that is how the selector list going stale
  // reads as "no violations" (prompt 205; the same defect the axe sweep had).
  if (els.length < MIN_CONTROLS) {
    console.log(`  MEASURED ONLY ${els.length} CONTROLS — the selector list is stale, nothing was audited`);
    failed = true;
  }

  if (!ok) failed = true;
  console.log(`=== ${width}×${height} === ${ok ? 'PASS' : 'FAIL'} (${els.length} controls) hScroll=${pageScroll}`);
  if (overlaps.length) console.log('  overlaps:', overlaps.join(', '));
  if (under44.length) console.log('  under44:', under44.join(', '));
  await page.close();
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : '\nRESULT: ALL PASS');
process.exit(failed ? 1 : 0);
