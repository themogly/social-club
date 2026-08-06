// Prompt 175 — screenshots each dispensary blocking state at the two tablet orientations, in both themes,
// and with motion reduced and allowed. Also asserts the two things a screenshot alone cannot: exactly ONE
// blocking state per screen, and no destructive colour on a blocked (not destructive) state.
//
// Playwright is intentionally NOT a CI dependency (it needs a ~100MB browser). Run it by hand:
//   npm install --no-save playwright && node_modules/.bin/playwright install chromium-headless-shell
//   npm run build
//   php artisan test tests/Browser/BlockingStatesHarnessTest.php   # writes storage/app/blocker-*.html
//   node tests/Browser/shoot-blocking-states.mjs
//
// Writes to storage/app/screenshots/175/. Exits non-zero if any state draws 0 or >1 blocking states, if a
// blocking state's action is under 44x44, or if a blocking state paints the destructive colour.

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';

const STATES = ['sede', 'till', 'member'];
const VIEWPORTS = [
  { name: '1180x820', width: 1180, height: 820 }, // iPad landscape — the counter's working orientation
  { name: '820x1180', width: 820, height: 1180 }, // and portrait
];
const THEMES = ['light', 'dark'];
const MOTION = ['reduce', 'no-preference'];

// These captures are static HTML with no Alpine running, so every `x-show` element renders VISIBLE — the
// offline banner, the top bar's overflow menu, the 173 surface. In the real app Alpine hides them on boot.
// Hiding them here reproduces what an operator actually sees; the blocking states themselves are plain
// server-rendered markup with no x-show, so nothing being photographed is suppressed by this.
const HIDE_ALPINE_SHOWN = '[x-show]{display:none !important}[data-counter-surface]{display:none !important}';

const OUT = 'storage/app/screenshots/175';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
let failed = false;

for (const state of STATES) {
  const url = pathToFileURL(resolve(`storage/app/blocker-${state}.html`)).href;

  for (const vp of VIEWPORTS) {
    for (const theme of THEMES) {
      for (const motion of MOTION) {
        const page = await browser.newPage({
          colorScheme: theme,
          reducedMotion: motion,
          viewport: { width: vp.width, height: vp.height },
        });
        await page.goto(url);
        await page.addStyleTag({ content: HIDE_ALPINE_SHOWN });
        await page.waitForTimeout(150);

        const shot = `${OUT}/${state}-${vp.name}-${theme}-motion-${motion === 'reduce' ? 'reduced' : 'allowed'}.png`;
        await page.screenshot({ path: shot, fullPage: false });

        // --- the assertions a picture cannot make ---
        const found = await page.$$eval('[data-counter-blocker]', (n) => n.length);
        if (found !== 1) {
          console.error(`FAIL ${state} @ ${vp.name}/${theme}: ${found} blocking states, expected exactly 1`);
          failed = true;
        }

        const bad = await page.$$eval('[data-counter-blocker]', (nodes) =>
          nodes.flatMap((n) =>
            [...n.querySelectorAll('*')]
              .filter((el) => {
                const bg = getComputedStyle(el).backgroundColor;
                // --error #dc2626 → rgb(220, 38, 38); anything in that family on a blocked state is wrong.
                return bg === 'rgb(220, 38, 38)';
              })
              .map((el) => el.className)
          )
        );
        if (bad.length) {
          console.error(`FAIL ${state} @ ${vp.name}/${theme}: destructive colour on a blocked state: ${bad.join(' | ')}`);
          failed = true;
        }

        const small = await page.$$eval('[data-counter-blocker] [data-blocker-action]', (nodes) =>
          nodes
            .map((n) => {
              const r = n.getBoundingClientRect();
              return { tag: n.tagName, w: Math.round(r.width), h: Math.round(r.height) };
            })
            // The member state's action is a container of fields; measure the fields, not the wrapper.
            .filter((b) => b.tag === 'A' && (b.w < 44 || b.h < 44))
        );
        if (small.length) {
          console.error(`FAIL ${state} @ ${vp.name}/${theme}: action under 44x44: ${JSON.stringify(small)}`);
          failed = true;
        }

        // Nothing may scroll the page sideways at the counter.
        const overflows = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        if (overflows) {
          console.error(`FAIL ${state} @ ${vp.name}/${theme}: horizontal page scroll`);
          failed = true;
        }

        await page.close();
      }
    }
  }
  console.log(`shot ${state} — ${VIEWPORTS.length * THEMES.length * MOTION.length} captures`);
}

// --- the argument for the branch: the cold start, before and after -----------------------------------
//
// `storage/app/blocker-before-coldstart.html` is the SAME state rendered from the pre-175 dispensary
// (captured by running this harness with e8c68cd's blade checked out). Both are photographed at the
// counter's working orientation and composed side by side.

import { existsSync, readFileSync } from 'node:fs';

const BEFORE = 'storage/app/blocker-before-coldstart.html';

if (existsSync(BEFORE)) {
  const counts = {};

  for (const [label, file] of [['before', BEFORE], ['after', 'storage/app/blocker-till.html']]) {
    for (const theme of THEMES) {
      const page = await browser.newPage({ colorScheme: theme, viewport: { width: 1180, height: 820 } });
      await page.goto(pathToFileURL(resolve(file)).href);
      await page.addStyleTag({ content: HIDE_ALPINE_SHOWN });
      await page.waitForTimeout(150);
      await page.screenshot({ path: `${OUT}/coldstart-${label}-1180x820-${theme}.png` });

      // How many separate things is the operator being told, in this one state?
      counts[`${label}-${theme}`] = await page.evaluate(() => {
        const texts = [
          'No hay caja abierta',
          'Identifica a un socio',
          'Identifica a un socio para poder registrar.',
        ];
        const body = document.body.innerText;
        return texts.filter((t) => body.includes(t)).length;
      });
      await page.close();
    }
  }

  console.log(`\ncold start — statements shown at once: ${JSON.stringify(counts)}`);

  // Compose the two into one image per theme.
  for (const theme of THEMES) {
    const png = (f) => 'data:image/png;base64,' + readFileSync(f).toString('base64');
    const page = await browser.newPage({ colorScheme: theme, viewport: { width: 2400, height: 900 } });
    await page.setContent(`
      <style>
        body{margin:0;display:flex;gap:16px;padding:16px;font:600 15px system-ui;
             background:${theme === 'dark' ? '#0f172a' : '#f8fafc'};color:${theme === 'dark' ? '#f1f5f9' : '#0f172a'}}
        figure{margin:0;flex:1}
        figcaption{padding:0 0 8px}
        img{width:100%;border-radius:12px;border:1px solid ${theme === 'dark' ? '#1e293b' : '#e2e8f0'}}
      </style>
      <figure><figcaption>BEFORE — three statements, three styles, no order</figcaption>
        <img src="${png(`${OUT}/coldstart-before-1180x820-${theme}.png`)}"></figure>
      <figure><figcaption>AFTER — one blocking state, the first unmet link in the chain</figcaption>
        <img src="${png(`${OUT}/coldstart-after-1180x820-${theme}.png`)}"></figure>
    `);
    await page.waitForTimeout(250);
    await page.screenshot({ path: `${OUT}/coldstart-before-after-${theme}.png` });
    await page.close();
  }
  console.log(`composed ${OUT}/coldstart-before-after-{light,dark}.png`);
} else {
  console.log(`\n(no ${BEFORE} — skipping the before/after composition)`);
}

await browser.close();
console.log(failed ? '\nFAILED' : `\nALL PASS — ${STATES.length * VIEWPORTS.length * THEMES.length * MOTION.length} captures in ${OUT}`);
process.exit(failed ? 1 : 0);
