// Prompt 221 — the sign-up modal, measured and shot at both orientations, light and dark.
//
//   npm run build
//   php artisan test tests/Browser/SignupWizardHarnessTest.php
//   node tests/Browser/measure-signup-wizard.mjs [after|before]
//
// Two sizes, because the modal's own constraint (`max-h: min(780px, 92vh)`) only bites on one of them:
// 1180×820 is the counter tablet lying down, 820×1180 is the same device stood up. A sticky footer that is
// only ever checked in landscape is a footer nobody has checked.
//
// What it asserts, per state × size × theme:
//   · the panel is INSIDE the viewport — top and bottom both, not just "it fits on paper"
//   · the footer (Atrás / Siguiente / Guardar) is reachable without the panel growing off screen
//   · nothing interactive under 44×44 — including the stepper circles, which are the new controls
//   · no horizontal page scroll
//   · `overflow-hidden` on <body> actually stops the page behind scrolling (the CSS half of the body lock;
//     the Alpine half that applies it is asserted in the feature tests, since these pages have no JS)

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const STAGE = process.argv[2] ?? 'after';
const OUT = `storage/app/screenshots/221`;
mkdirSync(OUT, { recursive: true });

// `before` shoots the SAME screen on `main` — the inline panel this branch replaces — so the pair can be
// looked at side by side. Its files come from main's own harness (`SociosLayoutHarnessTest`), rendered in a
// throwaway worktree and copied in; only the shots and the size checks apply to it, because the after-only
// assertions ("no modal on the closed screen") describe a screen that does not exist there.
const STATES = STAGE === 'before'
  ? ['before-closed', 'before-panel']
  : ['closed', 'fee', 'chooser', 'step1', 'step2', 'step3', 'step4', 'invite-sent'];
const SIZES = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];
const FLOOR = 44;

const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

for (const state of STATES) {
  const file = resolve(`storage/app/signup-221-${state}.html`);
  try { await access(file); } catch { fail(`missing ${state} — run SignupWizardHarnessTest first`); continue; }

  for (const size of SIZES) {
    for (const theme of ['light', 'dark']) {
      const page = await browser.newPage({
        viewport: { width: size.width, height: size.height },
        colorScheme: theme,
        hasTouch: true,
        reducedMotion: 'reduce',
      });
      await page.goto(pathToFileURL(file).href);

      // These pages carry no JavaScript, so Alpine never runs: hide what Alpine would have hidden (the
      // counter's own lock/handover surface) rather than shooting a screen nobody would ever see.
      await page.evaluate(() => {
        document.querySelectorAll('[data-counter-surface],[x-cloak]').forEach((n) => { n.style.display = 'none'; });
      });

      await page.screenshot({ path: `${OUT}/${STAGE}-${state}-${size.name}-${theme}.png` });

      const r = await page.evaluate((FLOOR) => {
        const box = (el) => (el ? el.getBoundingClientRect() : null);
        const panel = box(document.querySelector('[data-alta-panel]'));
        const footer = box(document.querySelector('[data-alta-next],[data-alta-staff-submit],[data-alta-approve]'));

        const targets = Array.from(document.querySelectorAll('a[href], button, input, select, textarea, summary'))
          .map((n) => ((n.type === 'checkbox' || n.type === 'radio') ? (n.closest('label') ?? n) : n))
          .map((n) => ({ b: n.getBoundingClientRect(), what: `${n.tagName.toLowerCase()} ${(n.getAttribute('data-alta-step') ? 'step' + n.getAttribute('data-alta-step') : (n.getAttribute('name') || n.id || n.textContent.trim().slice(0, 18)))}` }))
          .filter((t) => t.b.width > 1 && t.b.height > 1);

        // Does `overflow-hidden` on <body> actually hold the page behind still?
        document.body.classList.add('overflow-hidden');
        const lockedScrollHeight = document.body.scrollHeight;
        const lockedOverflow = getComputedStyle(document.body).overflowY;
        document.body.classList.remove('overflow-hidden');

        return {
          panel: panel ? { top: Math.round(panel.top), bottom: Math.round(panel.bottom), w: Math.round(panel.width), h: Math.round(panel.height) } : null,
          footer: footer ? { bottom: Math.round(footer.bottom), h: Math.round(footer.height) } : null,
          sampled: targets.length,
          under: targets.filter((t) => t.b.height < FLOOR || t.b.width < FLOOR).map((t) => `${Math.round(t.b.width)}×${Math.round(t.b.height)} ${t.what}`),
          hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
          lockedOverflow,
          lockedScrollHeight,
        };
      }, FLOOR);

      const label = `${state}/${size.name}/${theme}`;

      if (STAGE === 'before') {
        // Nothing to assert about a layout this branch is replacing — it is shot to be looked at.
      } else if (state === 'closed' || state === 'fee') {
        if (r.panel) fail(`${label}: the sign-up modal is on the closed screen`);
      } else {
        if (! r.panel) { fail(`${label}: no modal panel`); await page.close(); continue; }
        if (r.panel.top < 0) fail(`${label}: the panel starts above the viewport (top ${r.panel.top})`);
        if (r.panel.bottom > size.height + 1) fail(`${label}: the panel runs past the bottom (${r.panel.bottom} > ${size.height})`);
        if (r.footer && r.footer.bottom > size.height + 1) fail(`${label}: the footer is off screen (${r.footer.bottom})`);
      }

      if (r.under.length && STAGE !== 'before') fail(`${label}: ${r.under.length} under ${FLOOR}px — ${r.under.join('; ')}`);
      if (r.hScroll) fail(`${label}: horizontal page scroll`);
      if (r.lockedOverflow !== 'hidden' && STAGE !== 'before') fail(`${label}: overflow-hidden on <body> does not lock the page behind (${r.lockedOverflow})`);
      if (r.sampled < 3 && STAGE !== 'before') fail(`${label}: SAMPLED ONLY ${r.sampled} controls — the selector is stale, nothing was audited`);

      console.log(`${label.padEnd(34)} panel=${r.panel ? `${r.panel.w}×${r.panel.h}@${r.panel.top}..${r.panel.bottom}` : '—'} footer_bottom=${r.footer?.bottom ?? '—'} controls=${r.sampled} under=${r.under.length} hScroll=${r.hScroll}`);
      await page.close();
    }
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS → ${OUT}`);
process.exit(failed ? 1 : 0);
