// Prompt 219 — the waive control, and the door notice it removes.
//
//   npm run build
//   php artisan test tests/Browser/FeeWaiverHarnessTest.php   # → storage/app/waiver-219-*.html
//   node tests/Browser/shoot-fee-waiver.mjs
//
// Asserts what the pictures cannot: the waiver control clears the 44px floor, the reason radios are real
// targets, and the door frame that should nag does while the one that should not does not.

import { chromium } from 'playwright';
import { mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const OUT = 'storage/app/screenshots/219';
const SIZES = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];
const FRAMES = [
  { key: 'panel', file: 'storage/app/waiver-219-panel.html', waiver: true, nags: false },
  { key: 'door-owing', file: 'storage/app/waiver-219-door-owing.html', waiver: false, nags: true },
  { key: 'door-clear', file: 'storage/app/waiver-219-door-clear.html', waiver: false, nags: false },
];

await mkdir(OUT, { recursive: true });
const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

for (const frame of FRAMES) {
  const file = resolve(frame.file);
  try { await access(file); } catch { fail(`missing ${frame.key}`); continue; }

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

      await page.screenshot({ path: `${OUT}/${frame.key}-${size.name}-${theme}.png`, fullPage: true });

      const r = await page.evaluate(() => {
        const targets = Array.from(document.querySelectorAll('[data-fee-waiver] button, [data-fee-waiver] input, [data-fee-waiver] label'))
          .map((n) => (n.type === 'radio' ? (n.closest('label') ?? n) : n))
          .map((n) => n.getBoundingClientRect())
          .filter((b) => b.width > 1 && b.height > 1);
        return {
          waiverForm: document.querySelector('[data-fee-waive-form]') !== null,
          nags: document.body.innerText.includes('Cobrar cuota pendiente') || document.body.innerText.includes('Collect outstanding fee'),
          tooSmall: targets.filter((b) => b.height < 44).length,
          hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        };
      });

      if (r.hScroll) fail(`${frame.key}/${size.name}/${theme}: horizontal page scroll`);
      if (r.tooSmall) fail(`${frame.key}/${size.name}/${theme}: ${r.tooSmall} waiver control(s) under 44px`);
      if (r.waiverForm !== frame.waiver) fail(`${frame.key}: waiver form ${r.waiverForm ? 'present' : 'absent'}, expected the opposite`);
      if (r.nags !== frame.nags) fail(`${frame.key}: fee notice ${r.nags ? 'present' : 'absent'}, expected the opposite`);

      console.log(`${frame.key} ${size.name} ${theme}: waiver=${r.waiverForm} nags=${r.nags} under44=${r.tooSmall}`);
      await page.close();
    }
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : '\nRESULT: ALL PASS');
process.exit(failed ? 1 : 0);
