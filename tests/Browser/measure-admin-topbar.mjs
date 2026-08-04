// Prompt 143 — bounding-box check for the admin topbar's top-right cluster (the "DGESEN" fix). Proves the three
// controls — language toggle, help, avatar — are SEPARATED (>= 12px between adjacent boxes, not touching), do not
// intersect, and are each >= 24x24, at 1280 / 1024 / 800, light and dark. Screenshots the topbar for the eye.
//
// Playwright is intentionally NOT a CI dependency (it needs a ~100MB browser). Run it by hand:
//   npm install --no-save playwright && node_modules/.bin/playwright install chromium-headless-shell
//   npm run build
//   php artisan test tests/Browser/AdminTopbarHarnessTest.php   # writes storage/app/admin-topbar-harness.html
//   node tests/Browser/measure-admin-topbar.mjs
//
// Exits non-zero on any overlap, any adjacent gap < 12px, or any sub-24px control.

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const harness = pathToFileURL(resolve('storage/app/admin-topbar-harness.html')).href;
const OUT = resolve('storage/app');
const WIDTHS = [1280, 1024, 800];
const CONTROLS = {
  language: '.fi-topbar [role="group"][aria-label]', // scope to the topbar (the dashboard has its own group)
  help: '.fi-topbar [data-screen-help]',
  avatar: '.fi-topbar .fi-user-menu-trigger',
};

const browser = await chromium.launch();
let failed = false;

for (const width of WIDTHS) {
  for (const dark of [false, true]) {
    const page = await browser.newPage();
    await page.setViewportSize({ width, height: 200 });
    await page.goto(harness);
    await page.evaluate((d) => {
      document.documentElement.classList.toggle('dark', d);
      try { localStorage.setItem('theme', d ? 'dark' : 'light'); } catch (e) {}
    }, dark);

    const boxes = {};
    for (const [name, sel] of Object.entries(CONTROLS)) {
      const el = await page.$(sel);
      boxes[name] = el ? await el.boundingBox() : null;
    }

    const missing = Object.entries(boxes).filter(([, b]) => !b || b.width < 1).map(([n]) => n);
    const tag = `${width}px ${dark ? 'dark' : 'light'}`;

    if (missing.length) {
      failed = true;
      console.log(`=== ${tag} === FAIL — control(s) not found/visible: ${missing.join(', ')}`);
      await page.close();
      continue;
    }

    // Order the three controls left→right and measure the gaps between adjacent boxes.
    const ordered = Object.entries(boxes).sort((a, b) => a[1].x - b[1].x);
    const gaps = [];
    for (let i = 0; i < ordered.length - 1; i++) {
      const a = ordered[i][1], b = ordered[i + 1][1];
      gaps.push({ pair: `${ordered[i][0]}|${ordered[i + 1][0]}`, gap: Math.round(b.x - (a.x + a.width)) });
    }
    const under24 = Object.entries(boxes).filter(([, b]) => b.height < 24 || b.width < 24).map(([n, b]) => `${n}(${Math.round(b.width)}x${Math.round(b.height)})`);
    const overlaps = gaps.filter((g) => g.gap < 0).map((g) => g.pair);
    const tooTight = gaps.filter((g) => g.gap >= 0 && g.gap < 12).map((g) => `${g.pair}=${g.gap}px`);

    const ok = overlaps.length === 0 && tooTight.length === 0 && under24.length === 0;
    if (!ok) failed = true;
    console.log(
      `=== ${tag} === ${ok ? 'PASS' : 'FAIL'} — order: ${ordered.map((o) => o[0]).join(' → ')}; gaps: ${gaps.map((g) => `${g.pair}=${g.gap}px`).join(', ')}`,
    );
    if (overlaps.length) console.log('  OVERLAP:', overlaps.join(', '));
    if (tooTight.length) console.log('  TOO TIGHT (<12px):', tooTight.join(', '));
    if (under24.length) console.log('  UNDER 24px:', under24.join(', '));

    // Screenshot the topbar strip for the eye.
    const topbar = await page.$('.fi-topbar');
    if (topbar) await topbar.screenshot({ path: `${OUT}/admin-topbar-${width}-${dark ? 'dark' : 'light'}.png` });
    await page.close();
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : '\nRESULT: ALL PASS');
process.exit(failed ? 1 : 0);
