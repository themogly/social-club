// Prompt 228 — the photo nag, and the capture component's OTHER consumer.
//
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/measure-photo-nag.mjs
//
// The nag's own geometry is measured in the static harness (`measure-pos-column.mjs`, the `no-photo` state).
// This one drives the REAL app for the half a static page cannot answer: the door screen, where the same
// `<x-counter.photo-capture>` renders in a different layout and had the same under-floor controls. Fixing a
// shared component and re-measuring only the screen that reported it is how the second consumer breaks.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/228';
mkdirSync(OUT, { recursive: true });

const FLOOR = 44;
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };
const ok = (m) => console.log(`  ok   ${m}`);

const browser = await chromium.launch();

for (const size of [{ name: '1180x820', width: 1180, height: 820 }, { name: '820x1180', width: 820, height: 1180 }]) {
  const ctx = await browser.newContext({ viewport: { width: size.width, height: size.height }, hasTouch: true, reducedMotion: 'reduce' });
  const page = await ctx.newPage();

  if (! await signInToCounter(page, '/counter/checkin')) {
    fail('could not reach the door — is the dev seed loaded and the server running?');
    await ctx.close();
    continue;
  }

  // Identify a socio, so the door renders its member card — where the capture component lives.
  const lookup = await page.$('#member-lookup');
  if (! lookup) { fail(`${size.name}: no lookup on the door`); await ctx.close(); continue; }
  await lookup.fill('M-');
  await lookup.press('Enter');
  await page.waitForTimeout(1200);
  const row = await page.$('[data-member-lookup-result]');
  if (row) { await row.click(); await page.waitForTimeout(1400); }

  const r = await page.evaluate((FLOOR) => {
    const capture = document.querySelector('[x-data^="photoCapture"]');
    if (! capture) return { present: false };

    const controls = Array.from(capture.querySelectorAll('button, label'))
      .filter((n) => ! n.closest('.fixed'))
      .map((n) => {
        const b = n.getBoundingClientRect();

        return { what: n.textContent.trim().slice(0, 14), w: Math.round(b.width), h: Math.round(b.height), visible: b.width > 1 && b.height > 1 };
      });

    const box = capture.getBoundingClientRect();

    return {
      present: true,
      box: `${Math.round(box.width)}×${Math.round(box.height)}`,
      controls,
      under: controls.filter((c) => c.visible && (c.h < FLOOR || c.w < FLOOR)),
      hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
    };
  }, FLOOR);

  await page.screenshot({ path: `${OUT}/checkin-${size.name}-light.png` });

  if (! r.present) {
    // A seeded socio may already have a photo — say so rather than passing silently on nothing.
    console.log(`  ${size.name}: no capture component on screen (this socio already has a photo) — nothing measured`);
  } else {
    if (r.under.length) fail(`${size.name}: ${r.under.map((c) => `"${c.what}" ${c.w}×${c.h}`).join('; ')} under ${FLOOR}px on the door`);
    else ok(`the door's capture controls clear the floor — ${r.controls.filter((c) => c.visible).map((c) => `${c.w}×${c.h}`).join(' ')}`);

    if (r.hScroll) fail(`${size.name}: the door scrolls sideways with the taller controls`);
    console.log(`  ${size.name}: capture ${r.box}`);
  }

  await ctx.close();
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS → ${OUT}`);
process.exit(failed ? 1 : 0);
