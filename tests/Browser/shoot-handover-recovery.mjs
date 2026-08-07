// Prompt 209 — step 4 of the report: the counter recovered from a handover, with and without its bar.
//
//   npm run build
//   php artisan test tests/Browser/HandoverRecoveryHarnessTest.php   # → storage/app/handover-209-*.html
//   node tests/Browser/shoot-handover-recovery.mjs
//
// The two frames differ by exactly one thing — whether a Livewire response was able to bring the chrome back
// with the component. The script asserts that difference rather than trusting the pictures: `before` must
// have no terminal strip and `after` must have all of it, and both must show the recovered counter.

import { chromium } from 'playwright';
import { mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const OUT = 'storage/app/screenshots/209';
const SIZES = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];
const CHROME_HOOKS = [
  'data-counter-topbar', 'data-counter-home-link', 'data-counter-sede-region',
  'data-counter-lock', 'data-counter-admin-link', 'data-counter-logout', 'data-counter-panic',
];

await mkdir(OUT, { recursive: true });
const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

for (const stage of ['before', 'after']) {
  const file = resolve(`storage/app/handover-209-${stage}.html`);
  try { await access(file); } catch { fail(`missing ${stage} frame`); continue; }

  for (const size of SIZES) {
    for (const theme of ['light', 'dark']) {
      const page = await browser.newPage({
        viewport: { width: size.width, height: size.height },
        colorScheme: theme,
        reducedMotion: 'reduce',
      });
      await page.goto(pathToFileURL(file).href);
      await page.evaluate(() => {
        document.querySelectorAll('[x-show],[x-cloak],[data-counter-surface]').forEach((n) => { n.style.display = 'none'; });
      });

      await page.screenshot({ path: `${OUT}/${stage}-${size.name}-${theme}.png`, fullPage: true });

      const r = await page.evaluate((hooks) => ({
        chrome: hooks.filter((h) => document.querySelector(`[${h}]`)).length,
        recovered: document.body.innerText.includes('Trabajando:'),
        hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      }), CHROME_HOOKS);

      if (!r.recovered) fail(`${stage}/${size.name}/${theme}: the counter is not showing as recovered`);
      if (stage === 'before' && r.chrome !== 0) fail(`before still has ${r.chrome} chrome control(s) — the frame is not the bug`);
      if (stage === 'after' && r.chrome !== CHROME_HOOKS.length) fail(`after has ${r.chrome}/${CHROME_HOOKS.length} chrome controls`);
      if (r.hScroll) fail(`${stage}/${size.name}/${theme}: horizontal page scroll`);

      console.log(`${stage} ${size.name} ${theme}: ${r.chrome}/${CHROME_HOOKS.length} chrome controls, recovered=${r.recovered}`);
      await page.close();
    }
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : '\nRESULT: ALL PASS');
process.exit(failed ? 1 : 0);
