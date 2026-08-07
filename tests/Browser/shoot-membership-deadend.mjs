// Prompt 203 — the dead end, and the same member resolved.
//
// The BEFORE artifact reproduces the owner's screenshot: an ACTIVE member, "Sin membresía activa en esta
// sede", a verdict telling the operator to renew from a record they cannot reach, and nothing to press. The
// AFTER artifacts are the three cases the counter now handles.
//
//   npm run build
//   php artisan test tests/Browser/MembershipDeadEndHarnessTest.php     # → storage/app/deadend-{lapsed,none,elsewhere}.html
//   node tests/Browser/shoot-membership-deadend.mjs                     # → storage/app/screenshots/203/
//
// To regenerate the BEFORE (see the README recipe):
//   git show main:resources/views/livewire/counter/membership-counter.blade.php > resources/views/livewire/counter/membership-counter.blade.php
//   php artisan test tests/Browser/MembershipDeadEndHarnessTest.php     # fails; writes the artifacts anyway
//   cp storage/app/deadend-lapsed.html storage/app/deadend-before.html
//   git checkout -- resources/views/livewire/counter/membership-counter.blade.php
//
// Asserts what a picture cannot: the before has no control at all, every after has exactly one, and nothing
// is under 44x44 or clipped.

import { chromium } from 'playwright';
import { mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const OUT = 'storage/app/screenshots/203';
const STATES = ['before', 'lapsed', 'none', 'elsewhere'];
const SIZES = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];

await mkdir(OUT, { recursive: true });

const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };
const rows = [];

for (const state of STATES) {
  const file = resolve(`storage/app/deadend-${state}.html`);
  try { await access(file); } catch { fail(`missing artifact for "${state}" — run the harness first`); continue; }

  for (const size of SIZES) {
    for (const theme of ['light', 'dark']) {
      for (const motion of ['reduce', 'no-preference']) {
        const page = await browser.newPage({
          viewport: { width: size.width, height: size.height },
          colorScheme: theme,
          reducedMotion: motion,
        });
        await page.goto(pathToFileURL(file).href);

        // Static capture: no Alpine, so every x-show element would render visible. Hide them, as the
        // blocking-states shooter does, so this is what an operator actually sees.
        await page.evaluate(() => {
          document.querySelectorAll('[x-show], [x-cloak]').forEach((el) => { el.style.display = 'none' });
        });
        await page.waitForTimeout(120);

        if (theme === 'light' && motion === 'reduce') {
          const controls = await page.$$eval('[data-membership-fix] button, [data-membership-fix] select', (nodes) =>
            nodes.map((n) => { const r = n.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height) } }));
          const scrolls = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);

          rows.push({ state, size: size.name, controls: controls.length, hScroll: scrolls });

          if (state === 'before' && controls.length !== 0) fail(`"before" has ${controls.length} controls — that is not the dead end`);
          if (state !== 'before' && controls.length === 0) fail(`"${state}" offers nothing to press`);
          for (const c of controls) {
            if (c.h < 44) fail(`${state} @ ${size.name}: a control is ${c.w}x${c.h} — under the 44px floor`);
          }
          if (scrolls) fail(`${state} @ ${size.name}: the page scrolls horizontally`);
        }

        await page.screenshot({ path: `${OUT}/${state}-${size.name}-${theme}-${motion}.png`, fullPage: true });
        await page.close();
      }
    }
  }
}

console.table(rows);
await browser.close();
console.log(failed ? 'RESULT: FAIL' : 'RESULT: PASS');
process.exit(failed ? 1 : 0);
