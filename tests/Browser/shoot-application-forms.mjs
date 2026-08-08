// Prompt 215 — the applicant's form and the staff form, before and after.
//
//   npm run build
//   php artisan test tests/Browser/ApplicationFormsHarnessTest.php   # → storage/app/form-215-*.html
//   node tests/Browser/shoot-application-forms.mjs after             # (or `before`, from a stashed tree)
//
// Counts what the pictures are about: how many of the four missing pieces the STAFF form carries — the photo,
// the ID document, prompt 179's reader, and the declared monthly consumption. Before: 0 of 4.

import { chromium } from 'playwright';
import { mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/215';
const SIZES = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];
const FORMS = [
  { key: 'staff', file: 'storage/app/form-215-staff.html' },
  { key: 'public', file: 'storage/app/form-215-public.html' },
];

await mkdir(OUT, { recursive: true });
const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

for (const form of FORMS) {
  const file = resolve(form.file);
  try { await access(file); } catch { fail(`missing ${form.key} frame`); continue; }

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

      await page.screenshot({ path: `${OUT}/${STAGE}-${form.key}-${size.name}-${theme}.png`, fullPage: true });

      const r = await page.evaluate(() => {
        const has = (sel) => document.querySelector(sel) !== null;
        // A checkbox's touch target is its LABEL — tapping the words toggles it — so measure that where
        // there is one, which is what a finger actually hits.
        const controls = Array.from(document.querySelectorAll('button, a[href], input, select, textarea'))
          .map((n) => (n.type === 'checkbox' || n.type === 'radio' ? (n.closest('label') ?? n) : n))
          .map((n) => ({ b: n.getBoundingClientRect(), what: (n.id || n.getAttribute('name') || n.tagName) }))
          .filter((c) => c.b.width > 1 && c.b.height > 1);
        return {
          photo: has('[data-alta-photo], #photo'),
          scan: has('[data-alta-scan], #document_scan'),
          mrz: has('[data-alta-mrz-scan], [data-mrz-scan]'),
          declared: has('[data-alta-declared], #declared_monthly_g'),
          fields: document.querySelectorAll('input, select, textarea').length,
          tooSmall: controls.filter((c) => c.b.height < 44).map((c) => c.what),
          hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        };
      });

      const carried = ['photo', 'scan', 'mrz', 'declared'].filter((k) => r[k]).length;

      if (r.hScroll) fail(`${form.key}/${size.name}/${theme}: horizontal page scroll`);
      if (STAGE === 'after' && carried !== 4) fail(`after ${form.key} carries ${carried}/4 of the pieces`);
      // The touch floor is asserted on the STAFF form, which is what this branch built and which is used
      // standing at a counter with somebody waiting. The applicant's public form is a phone-first page that
      // predates this branch and has its own sub-44px controls (native file inputs and the consent
      // checkboxes); reported as a finding rather than failed here, because fixing it is not this branch's
      // scope and quietly widening the check would hide the number.
      if (STAGE === 'after' && r.tooSmall.length) {
        const what = `${r.tooSmall.length} under 44px: ${r.tooSmall.join(', ')}`;
        if (form.key === 'staff') fail(`after staff/${size.name}/${theme}: ${what}`);
        else console.log(`  · finding (pre-existing, public form): ${what}`);
      }

      console.log(`${STAGE} ${form.key} ${size.name} ${theme}: ${carried}/4 pieces (photo=${r.photo} scan=${r.scan} mrz=${r.mrz} declared=${r.declared}), ${r.fields} fields, hScroll=${r.hScroll}`);
      await page.close();
    }
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS (${STAGE})`);
process.exit(failed ? 1 : 0);
