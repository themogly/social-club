// Prompt 217 — the applicant's form at 390×844, both states, before and after.
//
//   npm run build
//   php artisan test tests/Browser/ApplicantFormHarnessTest.php   # → storage/app/applicant-217-*.html
//   node tests/Browser/shoot-applicant-form.mjs after             # (or `before`, from a stashed tree)
//
// Full page, at the size the page is for. The measurement lives in measure-applicant-form.mjs; this records
// the count alongside each frame so a picture is never the only evidence.

import { chromium, devices } from 'playwright';
import { mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/217';
const STATES = [
  { key: 'initial', file: 'storage/app/applicant-217-initial.html' },
  { key: 'scanned', file: 'storage/app/applicant-217-scanned.html' },
];

await mkdir(OUT, { recursive: true });
const browser = await chromium.launch();
let failed = false;

for (const state of STATES) {
  const file = resolve(state.file);
  try { await access(file); } catch { console.error(`FAIL: missing ${state.key}`); failed = true; continue; }

  for (const theme of ['light', 'dark']) {
    const page = await browser.newPage({
      ...devices['iPhone 14 Pro'],
      viewport: { width: 390, height: 844 },
      colorScheme: theme,
      reducedMotion: 'reduce',
    });
    await page.goto(pathToFileURL(file).href);
    await page.screenshot({ path: `${OUT}/${STAGE}-${state.key}-390x844-${theme}.png`, fullPage: true });

    const under = await page.evaluate(() => Array.from(document.querySelectorAll('a[href], button, input, select, textarea, summary'))
      .map((n) => ((n.type === 'checkbox' || n.type === 'radio') ? (n.closest('label') ?? n) : n))
      .map((n) => n.getBoundingClientRect())
      .filter((b) => b.width > 1 && b.height > 1)
      .filter((b) => b.height < 44 || b.width < 44).length);

    console.log(`${STAGE} ${state.key} ${theme}: ${under} control(s) under 44px`);
    await page.close();
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS (${STAGE})`);
process.exit(failed ? 1 : 0);
