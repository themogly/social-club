// Prompt 200 — the three lockdown surfaces, photographed and measured.
//
// Prompt 121 shipped with a stated verification gap: "no browser here". The mechanism was tested thoroughly
// server-side and none of that was what was owed — every one of these is about what a PERSON sees, once,
// under stress, having never used it before.
//
// The load-bearing one is the 503. The whole design intent is that a locked-down club does not announce to
// whoever is standing in the room that a lockdown was triggered, so this reads that page back as plain text
// and prints it: if it says or implies "lockdown", the feature is defeated.
//
// Playwright is intentionally NOT a CI dependency. Run it by hand:
//   npm install --no-save playwright && node_modules/.bin/playwright install chromium-headless-shell
//   npm run build                                          # the harness inlines the BUILT css
//   php artisan test tests/Browser/LockdownHarnessTest.php  # writes storage/app/lockdown-*.html
//   node tests/Browser/shoot-lockdown.mjs
//
// Writes to storage/app/screenshots/200/ and exits non-zero if the trigger is visible to a non-holder, if
// the 503 leaks, or if any control on these surfaces is under 44x44.

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';

const SURFACES = [
  { name: 'counter-holder', open: '[data-counter-overflow-trigger]' },
  { name: 'counter-non-holder', open: '[data-counter-overflow-trigger]' },
  // Seguridad is NOT a static artifact. A Filament panel page is laid out by its own JS at runtime, so a
  // captured HTML file renders as a topbar over an empty body no matter which stylesheet is inlined — the
  // text is in the DOM, nothing is visible. Every other harness in this repo is a COUNTER page, which is
  // plain server-rendered Blade, which is why this had no precedent. It is shot from the LIVE server
  // below, the way prove-commit-click.mjs already does for anything that needs the runtime.
  { name: 'unavailable', hideAlpine: false },
];
const VIEWPORTS = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];
const THEMES = ['light', 'dark'];

// Static captures with no Alpine running, so every x-show element would render VISIBLE. Same convention as
// the other shooters — except the overflow MENU, which we deliberately open by hand below.
const HIDE_ALPINE_SHOWN = '[x-show]:not([data-keep-open]){display:none !important}[data-counter-surface]{display:none !important}';

const OUT = 'storage/app/screenshots/200';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
let failed = false;
const rows = [];

for (const surface of SURFACES) {
  const url = pathToFileURL(resolve(`storage/app/lockdown-${surface.name}.html`)).href;

  for (const vp of VIEWPORTS) {
    for (const theme of THEMES) {
      const page = await browser.newPage({
        colorScheme: theme,
        reducedMotion: 'reduce',
        viewport: { width: vp.width, height: vp.height },
      });
      await page.goto(url);
      if (surface.hideAlpine !== false) await page.addStyleTag({ content: HIDE_ALPINE_SHOWN });
      await page.waitForTimeout(150);

      // The overflow menu is where the discreet trigger lives, so it has to be OPEN to be photographed.
      // Alpine is not running on a static capture, so reveal the panel directly.
      if (surface.open) {
        // Alpine is not running on a static capture, so the panel is closed by BOTH `x-show` (hidden by the
        // stylesheet above) and `x-cloak` (hidden by the app's own CSS). Strip both, or the trigger inside it
        // measures 0x0 and the measurement is of nothing.
        await page.evaluate(() => {
          const menu = document.querySelector('[data-counter-overflow] [x-show]');
          if (!menu) return;
          menu.removeAttribute('x-cloak');
          menu.setAttribute('data-keep-open', '');
          menu.style.setProperty('display', 'block', 'important');
        });
        await page.waitForTimeout(150);
      }

      // 44x44 is the COUNTER's touch floor; the Filament panel is desktop-first by CLAUDE.md and prompt 98
      // set 24x24 there. Applying the counter's number to a panel page would report the design as a defect.
      const floor = surface.floor ?? 44;

      const m = await page.evaluate((floor) => {
        const panic = document.querySelector('[data-counter-panic]');
        const box = panic ? panic.getBoundingClientRect() : null;

        return {
          text: document.body.innerText.replace(/\s+/g, ' ').trim(),
          panic: panic !== null,
          panicSize: box ? `${Math.round(box.width)}x${Math.round(box.height)}` : null,
          small: [...document.querySelectorAll('button:not([disabled]), a[href], [role="button"]')]
            .filter((n) => {
              const r = n.getBoundingClientRect();
              const s = getComputedStyle(n);
              if (r.width === 0 || r.height === 0 || s.display === 'none' || s.visibility === 'hidden') return false;
              // A skip link is 1x1 BY DESIGN until it is focused — that is what sr-only means, and the
              // accessibility audit deliberately made it collapse. Measuring it as a touch target measures
              // the wrong thing.
              if (n.className.toString().includes('sr-only')) return false;
              return r.width < floor || r.height < floor;
            })
            .slice(0, 3)
            .map((n) => `${(n.innerText || n.getAttribute('aria-label') || '?').trim().slice(0, 20)} ${Math.round(n.getBoundingClientRect().width)}x${Math.round(n.getBoundingClientRect().height)}`),
        };
      }, floor);

      await page.screenshot({ path: `${OUT}/${surface.name}-${vp.name}-${theme}.png`, fullPage: false });

      if (theme === 'light' && vp.name === '1180x820') {
        rows.push({
          surface: surface.name,
          panicTrigger: m.panic ? `present ${m.panicSize}` : 'absent',
          under44: m.small.join(' | ') || '—',
          reads: m.text.slice(0, 78),
        });

        // The guarantee, read back as text rather than eyeballed.
        if (surface.name === 'unavailable') {
          for (const word of ['lockdown', 'bloqueo', 'locked', 'cerrado', 'pánico', 'panic', 'simulacro', 'Asociación Ejemplo', 'Sede Centro']) {
            if (m.text.toLowerCase().includes(word.toLowerCase())) {
              console.error(`FAIL: the 503 page contains «${word}» — it must read as an ordinary outage`);
              failed = true;
            }
          }
        }

        if (surface.name === 'counter-non-holder' && m.panic) {
          console.error('FAIL: the lockdown trigger is present for a user without lockdown.initiate');
          failed = true;
        }

        if (surface.name === 'counter-holder' && !m.panic) {
          console.error('FAIL: the lockdown trigger is missing for a holder');
          failed = true;
        }

        if (m.small.length) {
          console.error(`FAIL ${surface.name}: ${m.small.length} control(s) under ${floor}x${floor} → ${m.small.join(' | ')}`);
          failed = true;
        }
      }

      await page.close();
    }
  }
  process.stdout.write('.');
}

// --- Seguridad, from the LIVE server (needs the Filament runtime) --------------------------------
const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8123';
try {
  // Sign in ONCE and reuse the session. Logging in per context tripped Filament's login throttle
  // ("Demasiados intentos") on the fifth attempt and every capture landed back on /login — the script
  // rate-limiting itself, which looks exactly like a broken page if you do not read the error.
  const auth = await browser.newContext({ viewport: { width: 1180, height: 820 } });
  const login = await auth.newPage();
  await login.goto(`${BASE}/login`, { timeout: 20000, waitUntil: 'networkidle' });
  await login.fill('input[type="email"]', process.env.AUDIT_EMAIL ?? 'owner@club.test');
  await login.fill('input[type="password"]', process.env.AUDIT_PASSWORD ?? 'password');
  await login.press('input[type="password"]', 'Enter');

  // Filament's login is a Livewire form: the redirect happens CLIENT-side after the response, so
  // `networkidle` can resolve while the URL is still /login. Wait for the URL itself, or a perfectly good
  // login reads as a failure — which is how this first reported "could not sign in".
  await login.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20000 })
    .catch(() => { throw new Error(`could not sign in (still at ${login.url()}) — dev seed loaded? throttled?`); });
  const storageState = await auth.storageState();
  await auth.close();

  for (const vp of VIEWPORTS) {
    for (const theme of THEMES) {
      const c = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, colorScheme: theme, reducedMotion: 'reduce', storageState });
      const p = await c.newPage();
      await p.goto(`${BASE}/seguridad`, { waitUntil: 'networkidle' });
      await p.waitForTimeout(900);

      const m = await p.evaluate(() => ({
        url: location.pathname,
        text: document.body.innerText.replace(/\s+/g, ' ').trim(),
        actions: [...document.querySelectorAll('button, a[href]')].map((n) => n.innerText.trim()).filter(Boolean),
        small: [...document.querySelectorAll('button:not([disabled]), a[href]')]
          .filter((n) => {
            const r = n.getBoundingClientRect();
            const st = getComputedStyle(n);
            if (r.width === 0 || r.height === 0 || st.display === 'none' || st.visibility === 'hidden') return false;
            if (n.className.toString().includes('sr-only')) return false;
            return r.width < 24 || r.height < 24;
          })
          .slice(0, 3)
          .map((n) => `${n.innerText.trim().slice(0, 18)} ${Math.round(n.getBoundingClientRect().width)}x${Math.round(n.getBoundingClientRect().height)}`),
      }));

      await p.screenshot({ path: `${OUT}/seguridad-${vp.name}-${theme}.png`, fullPage: false });

      if (theme === 'light' && vp.name === '1180x820') {
        const hasPanic = m.actions.some((a) => a.includes('Activar bloqueo') || a.includes('security lockdown'));
        const hasDrill = m.actions.some((a) => a.includes('Simulacro') || a.includes('Drill'));
        rows.push({ surface: 'seguridad (live)', panicTrigger: hasPanic ? 'present' : 'ABSENT', under44: m.small.join(' | ') || '—', reads: m.text.slice(0, 78) });

        if (m.url !== '/seguridad') { console.error(`FAIL: landed on ${m.url}, not the Seguridad page`); failed = true; }
        if (!hasPanic || !hasDrill) { console.error('FAIL: the Seguridad page must offer both the panic and the drill actions'); failed = true; }
        // Filament's own brand link is vendor chrome and is excluded — the panel is desktop-first (24px floor).
        const ours = m.small.filter((x) => !x.startsWith('CSC platform'));
        if (ours.length) { console.error(`FAIL seguridad: control(s) under 24x24 → ${ours.join(' | ')}`); failed = true; }
      }

      await c.close();
    }
  }
} catch (e) {
  console.error(`SKIP seguridad (live): ${e.message.slice(0, 120)}`);
  failed = true;
}

await browser.close();
console.log('\n');
console.table(rows);
console.log(failed ? '\nRESULT: FAIL' : '\nRESULT: ALL PASS');
process.exit(failed ? 1 : 0);
