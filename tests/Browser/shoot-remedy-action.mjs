// Prompt 211 — the blocked member on the dispensary, before and after.
//
//   npm run build
//   php artisan test tests/Browser/BlockedMemberHarnessTest.php   # → storage/app/blocked-member-211.html
//   node tests/Browser/shoot-remedy-action.mjs after              # (or `before`, from a stashed tree)
//
// Asserts what the picture cannot: `before` offers NO control on the verdict, `after` offers exactly one —
// 203's own enrol button, rendered in place — and it clears the 44px floor. That is the entire report:
// "there should be an action button".

import { chromium } from 'playwright';
import { mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/211';
const SIZES = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];

await mkdir(OUT, { recursive: true });
const file = resolve('storage/app/blocked-member-211.html');
try { await access(file); } catch { console.error('FAIL: run BlockedMemberHarnessTest first'); process.exit(1); }

const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

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

    await page.screenshot({ path: `${OUT}/${STAGE}-${size.name}-${theme}.png`, fullPage: true });

    const r = await page.evaluate(() => {
      // 203's own fix panel, rendered on the screen that shows the problem it fixes.
      const actions = Array.from(document.querySelectorAll('[data-membership-enrol], [data-membership-renew]'));
      return {
        actions: actions.length,
        tooSmall: actions.map((a) => a.getBoundingClientRect()).filter((b) => b.height < 44).length,
        blocked: document.body.innerText.includes('Sin membresía activa en esta sede'),
        hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      };
    });

    if (!r.blocked) fail(`${size.name}/${theme}: the member is not showing as blocked`);
    if (STAGE === 'before' && r.actions !== 0) fail(`before has ${r.actions} action(s) — the frame is not the bug`);
    if (STAGE === 'after' && r.actions !== 1) fail(`after has ${r.actions} action(s), expected 1`);
    if (r.tooSmall) fail(`${size.name}/${theme}: the action is under 44px tall`);
    if (r.hScroll) fail(`${size.name}/${theme}: horizontal page scroll`);

    console.log(`${STAGE} ${size.name} ${theme}: ${r.actions} verdict action(s), blocked=${r.blocked}, hScroll=${r.hScroll}`);
    await page.close();
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS (${STAGE})`);
process.exit(failed ? 1 : 0);
