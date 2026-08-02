// Prompt 132 — bounding-box check for the counter top bar. Proves NO two interactive controls in the bar
// intersect, and none is under 44×44, at 768 / 800 / 1024 / 1280 (1024 landscape is the one that matters and is
// NOT the narrowest — a fixed-width check catches what the extremes miss). This is what prompt 130 lacked.
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
const WIDTHS = [768, 800, 1024, 1280];
const SELECTOR = [
  '[data-counter-topbar] [data-counter-screen]',
  '[data-counter-topbar] [data-counter-sede-current]',
  '[data-counter-topbar] [data-counter-lock-now]',
  '[data-counter-topbar] [data-counter-overflow-trigger]',
].join(', ');

const browser = await chromium.launch();
let failed = false;

for (const width of WIDTHS) {
  const page = await browser.newPage();
  await page.setViewportSize({ width, height: 900 });
  await page.goto(harness);

  const els = await page.$$eval(SELECTOR, (nodes) =>
    nodes
      .map((n) => {
        const r = n.getBoundingClientRect();
        const label =
          n.getAttribute('data-counter-screen') ||
          (n.hasAttribute('data-counter-overflow-trigger') ? 'overflow' : 'sede');
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
  if (!ok) failed = true;
  console.log(`=== ${width}px === ${ok ? 'PASS' : 'FAIL'} (${els.length} controls) hScroll=${pageScroll}`);
  if (overlaps.length) console.log('  overlaps:', overlaps.join(', '));
  if (under44.length) console.log('  under44:', under44.join(', '));
  await page.close();
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : '\nRESULT: ALL PASS');
process.exit(failed ? 1 : 0);
