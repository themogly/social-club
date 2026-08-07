// Prompt 206 — the terminal strip, before and after, in English and Spanish.
//
//   npm run build
//   php artisan test tests/Browser/TopBar206HarnessTest.php   # → storage/app/topbar-206-{es,en}.html
//   node tests/Browser/shoot-topbar-destinations.mjs after    # (or `before`, from a stashed tree)
//
// The English pair is the bug report: `lang/en.json` renders "Panel" as **Dashboard**, so before this branch
// the row read *Home* and *Dashboard* side by side — two synonyms — with the house glyph on the one that
// went to the admin panel rather than the one that went home.
//
// Also asserts what a picture cannot: no control under 44×44 and no horizontal page scroll, at both tablet
// orientations, in light and dark.

import { chromium } from 'playwright';
import { mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/206';
const SIZES = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
  // Above the `xl` label threshold, so the WORDING is legible — which is what this branch changed.
  { name: '1440x900', width: 1440, height: 900 },
];
const CONTROLS = [
  'data-counter-home-link', 'data-counter-sede-current', 'data-counter-sede-state',
  'data-operator-name-chip', 'data-counter-lock', 'data-counter-dashboard',
  'data-counter-admin-link', 'data-counter-logout', 'data-counter-panic',
];
const SELECTOR = CONTROLS.map((a) => `[data-counter-topbar] [${a}]`).join(', ');

await mkdir(OUT, { recursive: true });

const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

for (const locale of ['en', 'es']) {
  const file = resolve(`storage/app/topbar-206-${locale}.html`);
  try { await access(file); } catch { fail(`missing harness for ${locale} — run TopBar206HarnessTest first`); continue; }

  for (const size of SIZES) {
    for (const theme of ['light', 'dark']) {
      const page = await browser.newPage({
        viewport: { width: size.width, height: size.height },
        colorScheme: theme,
        reducedMotion: 'reduce',
      });
      await page.goto(pathToFileURL(file).href);

      // Static capture: no Alpine runs, so anything x-show/x-cloak would render visible. Hide it, as the
      // other shoot scripts in this folder do, or the sede menu and the lock surface cover the bar.
      await page.evaluate(() => {
        document.querySelectorAll('[x-show],[x-cloak],[data-counter-surface]').forEach((n) => { n.style.display = 'none'; });
      });

      const header = await page.$('[data-counter-topbar]');
      if (! header) { fail(`${locale}/${size.name}/${theme}: no header`); await page.close(); continue; }

      await header.screenshot({ path: `${OUT}/${STAGE}-${locale}-${size.name}-${theme}.png` });

      const boxes = await page.$$eval(SELECTOR, (nodes) => nodes
        .map((n) => { const r = n.getBoundingClientRect(); return { w: r.width, h: r.height, hook: n.outerHTML.slice(0, 40) }; })
        .filter((e) => e.w > 0 && e.h > 0));

      if (boxes.length < 5) fail(`${locale}/${size.name}/${theme}: measured only ${boxes.length} controls`);
      boxes.filter((b) => b.w < 44 || b.h < 44)
        .forEach((b) => fail(`${locale}/${size.name}/${theme}: ${Math.round(b.w)}×${Math.round(b.h)} — ${b.hook}`));

      const hScroll = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
      if (hScroll) fail(`${locale}/${size.name}/${theme}: horizontal page scroll`);

      console.log(`${STAGE} ${locale} ${size.name} ${theme}: ${boxes.length} controls, hScroll=${hScroll}`);
      await page.close();
    }
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS (${STAGE})`);
process.exit(failed ? 1 : 0);
