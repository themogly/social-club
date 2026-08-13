// Prompt 232 — can an applicant on the emailed route actually SIGN and SUBMIT?
//
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/measure-applicant-signature.mjs [after|before]
//
// Prompt 220's harnesses measured that the pad was PRESENT and BIG ENOUGH. Neither is function, and both
// passed while the route was unusable: `measure-applicant-form.mjs` requires the canvas to be ≥100px tall,
// and the broken un-initialised canvas is 335px — **the defect passed the size check because of itself**.
// `shoot-signature-pad.mjs` renders static file:// pages its own header documents as carrying no JavaScript.
//
// So this one does what a person does, on the running app: draw, look for ink, press Guardar firma, submit
// the form, and check the application came out the other side with a signature on it.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signInToCounter } from './counter-session.mjs';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/232';
mkdirSync(OUT, { recursive: true });

const SIZES = [
  { name: '1440x900', width: 1440, height: 900, phone: false },
  { name: '390x844', width: 390, height: 844, phone: true },
];

let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };
const ok = (m) => console.log(`  ok   ${m}`);

const browser = await chromium.launch();

// ONE CONTEXT PER SIZE, and both themes emulated inside it. Two reasons, both learned here:
//
//  · a handover mutates SERVER session state, so a shared `storageState` (which shares one Laravel session)
//    puts every later context into the first one's handover — contexts 2–4 could not reach the counter at all;
//  · signing in once per size×theme is five logins, which trips Filament's throttle on about the fifth and
//    lands the rest back on /login. `shoot-till-panels` recorded that failure in prompt 200.
//
// Three logins, four measurements, no shared session.

/** Hand the tablet over from the counter — the real way to reach an applicant form at a real token. */
async function applicantForm(ctx) {
  const page = await ctx.newPage();

  if (! await signInToCounter(page, '/counter/members')) return null;
  if (! await page.$('[data-alta-toggle]')) return null;

  await page.click('[data-alta-toggle]');
  await page.waitForSelector('[data-alta-modal]');
  await page.click('[data-alta-handover]');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(900);

  return page.url().includes('/socio/solicitud/') ? page : null;
}

for (const size of SIZES) {
  {
    const theme = 'light';
    const ctx = await browser.newContext({
      viewport: { width: size.width, height: size.height },
      colorScheme: theme,
      hasTouch: size.phone,
      isMobile: size.phone,
      reducedMotion: 'reduce',
    });

    const page = await applicantForm(ctx);
    if (! page) { fail(`${size.name}: could not reach an applicant form`); await ctx.close(); continue; }

    const label = `${size.name}`;

    // --- 1. the runtime is on the page ---------------------------------------------------------
    const runtime = await page.evaluate(() => typeof window.Alpine);
    if (runtime !== 'object') fail(`${label}: window.Alpine is ${runtime} — every directive on this page is dead markup`);
    else ok(`${label}: Alpine is on the page`);

    // --- 2. the canvas is its own size, not a stretched default ---------------------------------
    const pad = page.locator('[data-signature-canvas]').first();
    if (await pad.count() === 0) { fail(`${label}: no signature pad`); await ctx.close(); continue; }

    await pad.scrollIntoViewIfNeeded();
    await page.waitForTimeout(300);

    const geom = await pad.evaluate((c) => {
      const b = c.getBoundingClientRect();

      return { attrW: c.width, attrH: c.height, boxW: Math.round(b.width), boxH: Math.round(b.height) };
    });

    // The un-init tell: a canvas nobody sized keeps its 300×150 DEFAULT attributes while CSS stretches the
    // box, so every drawn coordinate lands somewhere else entirely.
    if (Math.abs(geom.attrW - geom.boxW) > 2 || Math.abs(geom.attrH - geom.boxH) > 4) {
      fail(`${label}: the canvas bitmap is ${geom.attrW}×${geom.attrH} inside a ${geom.boxW}×${geom.boxH} box — it never initialised`);
    } else {
      ok(`${label}: bitmap ${geom.attrW}×${geom.attrH} matches its box`);
    }

    await page.screenshot({ path: `${OUT}/${STAGE}-pad-empty-${size.name}-${theme}.png` });

    // --- 3. a stroke leaves ink ------------------------------------------------------------------
    const box = await pad.boundingBox();
    await page.mouse.move(box.x + box.width * 0.15, box.y + box.height * 0.6);
    await page.mouse.down();
    for (const [dx, dy] of [[0.3, 0.3], [0.45, 0.7], [0.6, 0.3], [0.75, 0.65]]) {
      await page.mouse.move(box.x + box.width * dx, box.y + box.height * dy);
    }
    await page.mouse.up();

    const drawn = await pad.evaluate((c) => {
      const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
      let ink = 0;
      for (let i = 3; i < d.length; i += 4) if (d[i] > 0) ink++;

      return { ink, drawn: c.dataset.drawn ?? null };
    });

    if (drawn.ink <= 0) fail(`${label}: a drawn stroke left ${drawn.ink} ink pixels — the pad does not draw`);
    else ok(`${label}: the stroke inked ${drawn.ink} pixels`);
    if (drawn.drawn !== '1') fail(`${label}: the pad did not mark itself drawn (prompt 222's close guard reads this)`);

    await page.screenshot({ path: `${OUT}/${STAGE}-pad-drawn-${size.name}-${theme}.png` });

    // --- 4. Guardar firma fills the field the server reads ---------------------------------------
    await page.click('[data-signature-save]');
    await page.waitForTimeout(400);

    const saved = await page.evaluate(() => ({
      value: document.querySelector('[data-signature-field]')?.value ?? '',
      confirmed: !! document.querySelector('[data-signature-form-ok]'),
    }));

    if (! saved.value.startsWith('data:image/png;base64,')) fail(`${label}: the hidden field holds "${saved.value.slice(0, 20)}" — the server has nothing to store`);
    else ok(`${label}: the field holds a ${saved.value.length}-char PNG data URL`);
    if (! saved.confirmed) fail(`${label}: the applicant is not told the signature was captured`);

    await page.screenshot({ path: `${OUT}/${STAGE}-pad-captured-${size.name}-${theme}.png` });

    // The dark frames, from the SAME session — the pad's behaviour has nothing to do with the theme, and a
    // second login per theme is what trips the throttle.
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${OUT}/${STAGE}-pad-captured-${size.name}-dark.png` });
    await page.emulateMedia({ colorScheme: 'light' });

    // --- 5. …and the whole form submits ----------------------------------------------------------
    if (size.name === '1440x900' && theme === 'light') {
      const url = page.url();

      await page.fill('#first_name', 'Marta');
      await page.fill('#last_name', 'Sanz');
      await page.fill('#email', `marta.${Date.now()}@example.es`);
      await page.fill('#date_of_birth', '1990-05-14');
      await page.selectOption('#document_type', 'DNI');
      await page.fill('#document_number', '12345678Z');
      // The consent rows are label-wrapped checkboxes (217) with no id — the label IS the target, which is
      // the whole point of that construction, so they are ticked the way a finger does.
      await page.check('input[name="consent_data"]');
      await page.check('input[name="consent_statutes"]');

      // Past the spam guard's floor (`ApplicationSpamGuard::MIN_SECONDS`). A faster submit is DISCARDED
      // SILENTLY behind an identical thank-you page — which is a good design and a trap for a harness: the
      // first version of this check filled the form in two seconds, saw the thank-you and reported success
      // while nothing had been written. Measure the write, not the page.
      await page.waitForTimeout(3500);

      await page.click('form[enctype] button[type="submit"]');
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(700);

      const stillOnForm = !! await page.$('[data-signature-canvas]');
      const body = await page.evaluate(() => document.body.innerText);

      if (stillOnForm) {
        fail(`the submit was refused — the applicant still cannot complete the route (${body.slice(0, 90).replace(/\s+/g, ' ')})`);
      } else {
        // The thank-you page is NOT proof: the spam guard shows the SAME page for a submission it silently
        // discarded. The proof is the payload — `show()` re-renders the form from whatever was stored, so a
        // recorded application comes back with its own name in it and a discarded one comes back empty.
        // (A submitted-but-pending invite still opens the form, so "is the form gone" is not the test either;
        // that was the second wrong discriminator.)
        await page.goto(url, { waitUntil: 'networkidle' });
        await page.waitForTimeout(500);

        const stored = await page.inputValue('#first_name').catch(() => '');

        if (stored !== 'Marta') fail(`nothing was recorded — the token re-opens with first_name="${stored}", so only a thank-you was shown`);
        else ok('the application was recorded with its signature (the token re-opens carrying the stored payload)');
      }

      await page.screenshot({ path: `${OUT}/${STAGE}-after-submit-1440x900-light.png` });
    }

    await ctx.close();
  }
}

// --- 6. the counter's own pads are untouched, and nothing double-starts Alpine --------------------
{
  const ctx = await browser.newContext({ viewport: { width: 1180, height: 820 } });
  const page = await ctx.newPage();
  const noise = [];
  page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') noise.push(m.text()); });

  if (await signInToCounter(page, '/counter/pos')) {
    await page.waitForTimeout(1200);
    const alpines = await page.evaluate(() => (window.Alpine ? 1 : 0));
    if (alpines !== 1) fail('the counter lost its Alpine');
    else ok('the counter still has exactly one Alpine (Livewire\'s)');

    const doubled = noise.filter((n) => /Alpine/i.test(n) && /multiple|already|detected/i.test(n));
    if (doubled.length) fail(`a counter route warns about Alpine: ${doubled[0]}`);
    else ok('no Alpine warning on a counter route');
  } else {
    fail('could not reach a counter route to check for a double start');
  }

  await ctx.close();
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS → ${OUT}`);
process.exit(failed ? 1 : 0);
