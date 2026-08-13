// Prompt 231 — does the sign-up wizard's first step actually FIT?
//
//   npm run build
//   php artisan test tests/Browser/SignupWizardHarnessTest.php
//   node tests/Browser/measure-wizard-fit.mjs [after|before]
//
// The owner: *"the first part of the wizard scrolls in the middle — should be fixed."* Measured on
// `2306824`, step 1 in ES at 1180×820 was 506px of content in a 506px region — a fit of ZERO pixels. His
// EN window (longer helper strings) tipped it over and clipped the MRZ block mid-sentence.
//
// **An exact fit is treated as a failure here.** A fit is a number, and a number that happens to be 0 is
// luck, not a layout. The bar is ≥40px of margin, in both locales and both orientations — plus a check that
// when a genuinely short window DOES overflow, the body says so instead of clipping silently.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/231';
mkdirSync(OUT, { recursive: true });

const MARGIN = 40;
const LOCALES = ['es', 'en'];
const STEPS = [1, 2, 3, 4];
const SIZES = [
  { name: '1180x820', width: 1180, height: 820, fit: true },
  { name: '820x1180', width: 820, height: 1180, fit: true },
  // The owner's window, and two shorter ones: these are allowed to overflow, but must SAY so.
  { name: '1180x760', width: 1180, height: 760, fit: true },
  { name: '1180x650', width: 1180, height: 650, fit: false },
];

const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

for (const locale of LOCALES) {
  for (const step of STEPS) {
    const file = resolve(`storage/app/signup-221-step${step}-${locale}.html`);
    try { await access(file); } catch { fail(`missing step${step}-${locale} — run SignupWizardHarnessTest first`); continue; }

    for (const size of SIZES) {
      for (const theme of ['light', 'dark']) {
        const page = await browser.newPage({ viewport: size, colorScheme: theme, reducedMotion: 'reduce', hasTouch: true });
        await page.goto(pathToFileURL(file).href);
        await page.evaluate(() => {
          document.querySelectorAll('[data-counter-surface],[x-cloak]').forEach((n) => { n.style.display = 'none'; });

          // Prompt 223's MRZ trigger ships `hidden` and is revealed by its module — which these file:// pages
          // do not run. Measuring with it hidden measures a step no counter tablet is ever on, and it is the
          // ~44px that decides this fit. (Prompt 228 learned the same lesson on the photo nag: a harness that
          // hides what it is supposed to measure reports a page nobody has.)
          document.querySelectorAll('[data-alta-mrz-scan][hidden]').forEach((n) => n.removeAttribute('hidden'));

          // …and the signature pad sizes its own bitmap at init (`c.width = c.offsetWidth; c.height = 150`).
          // With no JS the canvas keeps its intrinsic 300×150 ratio stretched to the panel's width — about
          // 160px taller than the real thing — so step 4 measures as overflowing a window it fits fine in.
          // The component's own init line, applied by hand, for the same reason prompt 220's shoot script
          // draws through the 2D context: measure the page the operator has.
          document.querySelectorAll('[data-signature-canvas]').forEach((c) => { c.style.height = '150px'; });
        });

        if (step === 1 && size.name === '1180x820') {
          await page.screenshot({ path: `${OUT}/${STAGE}-step1-${locale}-${size.name}-${theme}.png` });
        }

        const r = await page.evaluate(() => {
          // The body is the wizard's scroll region: the element between the stepper and the footer.
          const body = document.querySelector('[data-alta-stepper]')?.nextElementSibling ?? null;
          const panel = document.querySelector('[data-alta-panel]');
          if (! body || ! panel) return null;

          const style = getComputedStyle(body);

          // `spare` inside the body is always 0 when there is room, because the body is `flex-1` and
          // shrinks to its content — so the meaningful number is the PANEL's headroom against the viewport:
          // how many pixels are left before the cap starts squeezing the body and clipping it. That is what
          // "506px of content in a 506px region" actually described.
          const panelBox = panel.getBoundingClientRect();

          return {
            content: body.scrollHeight,
            region: body.clientHeight,
            panelH: Math.round(panelBox.height),
            headroom: Math.round(window.innerHeight - panelBox.height),
            overflow: Math.max(0, body.scrollHeight - body.clientHeight),
            overflows: body.scrollHeight > body.clientHeight + 1,
            saysSo: body.className.includes('counter-scroll-region'),
            gutter: style.scrollbarGutter,
            panelBottom: Math.round(panel.getBoundingClientRect().bottom),
            mrzVisible: (() => {
              const t = document.querySelector('[data-alta-mrz-region]');
              if (! t) return null;
              const b = t.getBoundingClientRect(), r2 = body.getBoundingClientRect();
              return b.bottom <= r2.bottom + 1;
            })(),
          };
        });

        if (! r) { fail(`step${step}/${locale}/${size.name}: no wizard body found`); await page.close(); continue; }

        const label = `step${step}/${locale}/${size.name}/${theme}`;

        if (STAGE === 'after') {
          if (r.panelBottom > size.height + 1) fail(`${label}: the panel runs past the viewport (${r.panelBottom} > ${size.height})`);

          if (size.fit) {
            if (r.overflows) fail(`${label}: the body overflows by ${r.overflow}px — the step does not fit`);
            else if (r.headroom < MARGIN) fail(`${label}: the panel is ${r.panelH}px in a ${size.height}px window — ${r.headroom}px headroom, under the ${MARGIN}px bar`);
            if (step === 1 && r.mrzVisible === false) fail(`${label}: the MRZ reader is below the fold on first paint`);
          } else if (r.overflows && ! r.saysSo) {
            fail(`${label}: the body overflows with no scroll affordance — a silent cliff`);
          }
        }

        if (theme === 'light') {
          console.log(`${label.padEnd(34)} content=${r.content} region=${r.region} panel=${r.panelH} headroom=${r.headroom} overflow=${r.overflow} saysSo=${r.saysSo} mrzInView=${r.mrzVisible ?? '—'}`);
        }

        await page.close();
      }
    }
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS → ${OUT}`);
process.exit(failed ? 1 : 0);
