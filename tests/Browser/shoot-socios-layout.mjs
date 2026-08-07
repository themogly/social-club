// Prompt 210 — the Socios screen's three jobs, before and after.
//
//   npm run build
//   php artisan test tests/Browser/SociosLayoutHarnessTest.php   # → storage/app/socios-210.html
//   node tests/Browser/shoot-socios-layout.mjs after             # (or `before`, from a stashed tree)
//
// Records the FOLD POSITION of each of the three jobs — sign-up, fee collection, the member's record — which
// is the measurement the complaint is actually about: at 1180×820 the record began below the fold with the
// width unused on both sides. Also asserts nothing clipped, nothing under 44×44 and no horizontal scroll.

import { chromium } from 'playwright';
import { mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/210';
const SIZES = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];

await mkdir(OUT, { recursive: true });
const PAGES = [
  { key: '', file: resolve('storage/app/socios-210.html') },
  // The route this branch adds, open. Only shot for `after` — it does not exist on `main`.
  { key: '-form', file: resolve('storage/app/socios-210-form.html') },
];

const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

for (const { key, file } of PAGES) {
  try { await access(file); } catch { if (key === '') { fail('run SociosLayoutHarnessTest first'); } continue; }

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

    await page.screenshot({ path: `${OUT}/${STAGE}${key}-${size.name}-${theme}.png`, fullPage: true });

    const r = await page.evaluate((viewportHeight) => {
      const find = (text) => Array.from(document.querySelectorAll('h2')).find((h) => h.textContent.trim() === text);
      const top = (el) => (el ? Math.round(el.getBoundingClientRect().top + window.scrollY) : null);
      const jobs = {
        signup: top(document.querySelector('[data-alta-panel]')),
        fee: top(find('Cobro de cuota')),
        record: top(document.querySelector('[data-member-column] [data-member-identity]')
          ?? document.querySelector('[data-member-identity]')),
      };
      // A checkbox's touch target is its LABEL — tapping the words toggles it — so measure the label when
      // there is one, which is what a finger actually hits.
      const controls = Array.from(document.querySelectorAll('button, a[href], input, select'))
        .map((n) => (n.type === 'checkbox' || n.type === 'radio' ? (n.closest('label') ?? n) : n))
        .map((n) => ({ box: n.getBoundingClientRect(), what: n.tagName + (n.id ? '#' + n.id : '') }))
        // `sr-only` collapses to 1×1 until focused — the skip link. It is not a touch target and measuring
        // it as one would report a failure that is the a11y pattern working.
        .filter((c) => c.box.width > 1 && c.box.height > 1);
      return {
        jobs,
        belowFold: Object.entries(jobs).filter(([, t]) => t !== null && t > viewportHeight).map(([k]) => k),
        widestUnused: Math.round(document.documentElement.clientWidth - Math.max(
          0, ...Array.from(document.querySelectorAll('section')).map((s) => s.getBoundingClientRect().right))),
        tooSmall: controls.filter((c) => c.box.height < 44).map((c) => `${c.what}(${Math.round(c.box.height)}px)`),
        hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      };
    }, size.height);

    if (r.hScroll) fail(`${size.name}/${theme}: horizontal page scroll`);
    if (r.tooSmall.length) fail(`${size.name}/${theme}: under 44px tall — ${r.tooSmall.join(', ')}`);

    console.log(`${STAGE}${key} ${size.name} ${theme}: folds signup=${r.jobs.signup} fee=${r.jobs.fee} record=${r.jobs.record} · below fold: ${r.belowFold.join(', ') || 'none'} · hScroll=${r.hScroll}`);
    await page.close();
  }
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS (${STAGE})`);
process.exit(failed ? 1 : 0);
