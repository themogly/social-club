// Prompt 187 — screenshots the counter terminal either side of the sede step, at the two tablet
// orientations, in both themes, and with motion reduced and allowed. Also asserts the two things a
// screenshot alone cannot: that the operator surface is DOWN while the chain is on the sede step (with the
// sede switcher reachable), and UP once the sede is chosen.
//
// Playwright is intentionally NOT a CI dependency (it needs a ~100MB browser). Run it by hand:
//   npm install --no-save playwright alpinejs && node_modules/.bin/playwright install chromium-headless-shell
//   npm run build
//   php artisan test tests/Browser/SurfaceChainHarnessTest.php   # writes storage/app/surface-chain-*.html
//   node tests/Browser/shoot-surface-chain.mjs
//
// Writes to storage/app/screenshots/187/. Exits non-zero if the surface is up on the sede step, if the top
// bar is missing there, or if the surface fails to raise once the sede is chosen.

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';

const STATES = [
  { name: 'no-sede', surfaceUp: false },   // fresh terminal: sede blocker + reachable switcher
  { name: 'with-sede', surfaceUp: true },  // sede chosen: the operator surface owns its own step
];
const VIEWPORTS = [
  { name: '1180x820', width: 1180, height: 820 }, // iPad landscape — the counter's working orientation
  { name: '820x1180', width: 820, height: 1180 }, // and portrait
];
const THEMES = ['light', 'dark'];
const MOTION = ['reduce', 'no-preference'];

// Prompt 175's script hid every `x-show` element with CSS, because its captures had no Alpine running and
// would otherwise render them all visible. That will not do here: this branch is ABOUT what the surface
// decides, its content sits inside `<template x-if>` (which no CSS can materialise), and approximating the
// decision we are trying to prove would be a picture of our own assumption. So the real Alpine runs and
// makes the real call from the server-rendered `data-surface-mode`. Alpine ships inside Livewire's bundle,
// which will not boot without a Livewire endpoint, so the standalone build is injected instead.
const ALPINE = 'node_modules/alpinejs/dist/cdn.min.js';

const OUT = 'storage/app/screenshots/187';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
let failed = false;

for (const state of STATES) {
  const url = pathToFileURL(resolve(`storage/app/surface-chain-${state.name}.html`)).href;

  for (const vp of VIEWPORTS) {
    for (const theme of THEMES) {
      for (const motion of MOTION) {
        const page = await browser.newPage({
          colorScheme: theme,
          reducedMotion: motion,
          viewport: { width: vp.width, height: vp.height },
        });
        await page.goto(url);
        await page.addScriptTag({ path: resolve(ALPINE) });
        await page.waitForTimeout(400);   // alpine:init registers the counter store, then x-if renders

        const shot = `${OUT}/${state.name}-${vp.name}-${theme}-motion-${motion === 'reduce' ? 'reduced' : 'allowed'}.png`;
        await page.screenshot({ path: shot, fullPage: false });

        // --- the assertions a picture cannot make ---
        const mode = await page.$eval('[data-counter-surface]', (n) => n.getAttribute('data-surface-mode'));
        const surfaceVisible = await page.$eval('[data-counter-surface]', (n) => getComputedStyle(n).display !== 'none');

        if (surfaceVisible !== state.surfaceUp) {
          console.error(`FAIL ${state.name} @ ${vp.name}/${theme}: surface visible=${surfaceVisible} (mode=${mode}), expected ${state.surfaceUp}`);
          failed = true;
        }

        if (state.surfaceUp) {
          // The PIN pad is the surface's whole purpose; it lives in an x-if template, so its presence also
          // proves Alpine really ran rather than the capture being a styled shell.
          const pad = await page.$('[data-counter-surface-unlock]');
          if (pad === null) {
            console.error(`FAIL ${state.name} @ ${vp.name}/${theme}: the surface raised without its PIN pad`);
            failed = true;
          }
        } else {
          // The deadlock was that the surface covered the top bar — the only route to a sede.
          const topbar = await page.$('[data-counter-topbar]');
          if (topbar === null) {
            console.error(`FAIL ${state.name} @ ${vp.name}/${theme}: no top bar, so no way to choose a sede`);
            failed = true;
          } else {
            const box = await topbar.boundingBox();
            if (box === null || box.height === 0) {
              console.error(`FAIL ${state.name} @ ${vp.name}/${theme}: the top bar is present but not visible`);
              failed = true;
            }
          }

          const blockers = await page.$$eval('[data-counter-blocker]', (n) => n.length);
          if (blockers !== 1) {
            console.error(`FAIL ${state.name} @ ${vp.name}/${theme}: ${blockers} blocking states, expected exactly 1`);
            failed = true;
          }
        }

        await page.close();
      }
    }
  }
}

await browser.close();
console.log(failed ? 'FAILED' : `OK — ${STATES.length * VIEWPORTS.length * THEMES.length * MOTION.length} captures in ${OUT}`);
process.exit(failed ? 1 : 0);
