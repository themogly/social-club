// Prompt 235 — the PIN-lockout admin, shot live: the Seguridad section (clear and locked), the sede form
// field, and the counter overlay's hint line.
//
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/shoot-pin-lockouts.mjs

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/235';
mkdirSync(OUT, { recursive: true });

let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };
const ok = (m) => console.log(`  ok   ${m}`);

const browser = await chromium.launch();

for (const theme of ['light', 'dark']) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, colorScheme: theme });
  const page = await ctx.newPage();

  if (! await signIn(page)) { fail('could not sign in'); await ctx.close(); continue; }

  // 1. Seguridad, clear state.
  // No font assertion here: 233's helper is for GEOMETRY harnesses, and it checks for the app's
  // self-hosted Inter — which the FILAMENT panel does not load (it ships its own theme and font stack).
  // These are screenshots of live pages over HTTP; nothing here asserts a text-driven number.
  await page.goto(`${BASE}/seguridad`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(700);

  if (! await page.$('[data-pin-lockouts]')) fail(`${theme}: no PIN-lockout section on Seguridad`);
  else ok(`${theme}: the section is on Seguridad`);
  await page.screenshot({ path: `${OUT}/seguridad-clear-${theme}.png` });

  // 2. A sede locked out-of-band (see the shell command that seeds redis before this run — driving the PIN
  //    pad through Alpine across Livewire morphs is unreliable, and the FEATURE is pinned by
  //    PinLockoutAdminTest; these are the visual record). The overlay shows the countdown and the hint; the
  //    Seguridad section shows the lockout and clears it.
  if (theme === 'light') {
    const counter = await ctx.newPage();
    await counter.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
    const sede = await counter.$('[data-counter-sede-menu] form button');
    if (sede) { await sede.click(); await counter.waitForLoadState('networkidle'); }
    await counter.waitForTimeout(600);

    const hint = await counter.$('[data-lockout-hint]');
    if (! hint) fail('the overlay does not say where the key is');
    else ok('the overlay names Administración › Seguridad');
    await counter.screenshot({ path: `${OUT}/overlay-hint-light.png` });

    await page.goto(`${BASE}/seguridad`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    if (! await page.$('[data-pin-locked]')) fail('the locked sede does not read as locked on Seguridad');
    else ok('Seguridad shows the lockout');
    await page.screenshot({ path: `${OUT}/seguridad-locked-light.png` });

    await page.click('[data-pin-clear]');
    await page.waitForTimeout(900);
    await page.screenshot({ path: `${OUT}/seguridad-cleared-light.png` });

    // 3. The sede form's field.
    await page.goto(`${BASE}/locations`, { waitUntil: 'networkidle' });
    const row = await page.$('table a');
    if (row) {
      await row.click();
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(700);
      const field = await page.$('input[id$="counter_pin_max_attempts"], [wire\\:model*="counter_pin_max_attempts"]');
      if (! field) fail('no attempts field on the sede form');
      else { await field.scrollIntoViewIfNeeded(); ok('the sede form carries the attempts field'); }
      await page.screenshot({ path: `${OUT}/sede-form-field-light.png` });
    }
  }

  await ctx.close();
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL SHOT → ${OUT}`);
process.exit(failed ? 1 : 0);
