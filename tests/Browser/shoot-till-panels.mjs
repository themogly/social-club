// Prompt 201 — the Caja's remaining panels, before and after fee collection leaves it.
//
// The design audit (post-194) put the till's three "record something" panels side by side from lg, which
// took the page from 1811px to 1477px and lifted `Cobrar cuota` from y=1413 to y=1104. Removing one of the
// three changes that layout, so it is measured rather than eyeballed: with two panels the row must read as
// deliberate, not as something with a hole in it.
//
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/shoot-till-panels.mjs [before|after]

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

// The sign-in preamble is `counter-session.mjs` (prompts 223/226) — one copy, not ten. Everything below is
// this harness's own: its viewports, its contexts, its measurements.
import { BASE, SEDE, signInToCounter } from './counter-session.mjs';

const PHASE = process.argv[2] ?? 'after';
const OUT = `storage/app/screenshots/201`;
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();

// One login, reused — logging in per context trips Filament's throttle (learned in prompt 200).
const auth = await browser.newContext({ viewport: { width: 1180, height: 820 } });
const login = await auth.newPage();

// Signed in and through the counter chain once, on this session, so the till renders its working screen.
if (! await signInToCounter(login, '/counter', { sede: SEDE })) {
  console.error('FAIL: could not reach the counter — is the dev seed loaded and the server running?');
  process.exit(1);
}

const storageState = await auth.storageState();
await auth.close();

const rows = [];
for (const vp of [{ name: '1180x820', width: 1180, height: 820 }, { name: '820x1180', width: 820, height: 1180 }]) {
  for (const theme of ['light', 'dark']) {
    const c = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, colorScheme: theme, reducedMotion: 'reduce', storageState });
    const p = await c.newPage();
    await p.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
    await p.waitForTimeout(700);

    const m = await p.evaluate(() => {
      const y = (sel) => { const el = document.querySelector(sel); return el ? Math.round(el.getBoundingClientRect().top + window.scrollY) : null; };
      const headings = [...document.querySelectorAll('h2, h3')].map((h) => `${h.textContent.trim().slice(0, 22)}@${Math.round(h.getBoundingClientRect().top + window.scrollY)}`);
      return {
        pageH: document.documentElement.scrollHeight,
        lookups: document.querySelectorAll('#member-lookup').length,
        feeForm: document.querySelectorAll('[wire\\:submit="collectFee"]').length,
        closeOut: y('[wire\\:click="startClose"]'),
        headings: headings.slice(0, 8),
        hScroll: document.documentElement.scrollWidth > window.innerWidth + 1,
      };
    });

    await p.screenshot({ path: `${OUT}/${PHASE}-${vp.name}-${theme}.png`, fullPage: false });

    if (theme === 'light') {
      rows.push({ phase: PHASE, viewport: vp.name, pageH: m.pageH, screens: (m.pageH / vp.height).toFixed(1) + '×', memberLookups: m.lookups, feeForms: m.feeForm, hScroll: m.hScroll ? 'YES' : '' });
      console.log(`${PHASE} ${vp.name} headings:`, m.headings.join(' | '));
    }

    await c.close();
  }
}

await browser.close();
console.table(rows);
