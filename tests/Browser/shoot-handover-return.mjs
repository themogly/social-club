// Prompt 249 — proof on the REAL app that the handed-over tablet has a way back: hand over → the applicant
// fills and submits → the thank-you card carries "Devolver la tablet al personal" → the surface says
// "Solicitud recibida" with the pad open → the PIN lands on the application's review. Then the same flow again,
// typing `/` instead of the button, to prove a stray still lands on the pad and never on the finished form.
//
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DevAdminSeeder
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/shoot-handover-return.mjs
//
// One context per (viewport × theme) — a shared browser session would carry one run's handover into the next
// (false-green #24). The tablet's CSS viewport in both orientations: 1280×800 and 800×1280.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, PIN, SEDE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/249';
mkdirSync(OUT, { recursive: true });

const VIEWPORTS = [
  { w: 1280, h: 800, tag: 'land' },
  { w: 800, h: 1280, tag: 'port' },
];
const results = [];
const browser = await chromium.launch();

// Fill and submit the tokenised applicant form the tablet was handed. The spam guard wants ≥ 3 s on the page,
// so the dwell is deliberate.
async function fillAndSubmitApplication(page) {
  await page.waitForSelector('#first_name', { timeout: 15000 });
  await page.fill('#first_name', 'María');
  await page.fill('#last_name', 'García');
  await page.fill('#email', 'maria.demo@example.es');
  await page.fill('#date_of_birth', '1990-03-04');
  await page.selectOption('#document_type', { index: 0 }).catch(() => {});
  await page.fill('#document_number', '12345678Z');
  // The two consent ticks + the signature pad (drawn) — both required to submit.
  for (const box of ['consent_data', 'consent_statutes']) {
    await page.check(`input[name="${box}"]`).catch(() => {});
  }
  // The signature pad, scrolled into view first: it sits below the fold, and a bounding box off-screen made
  // the stroke miss the canvas entirely ("Falta la firma"). A multi-step stroke, as measure-applicant-signature
  // draws it, so the pad registers a real line rather than a single point.
  const pad = page.locator('[data-signature-canvas]').first();
  await pad.scrollIntoViewIfNeeded();
  const b = await pad.boundingBox();
  if (b) {
    await page.mouse.move(b.x + b.width * 0.15, b.y + b.height * 0.6);
    await page.mouse.down();
    for (const [dx, dy] of [[0.3, 0.3], [0.45, 0.7], [0.6, 0.35], [0.8, 0.6]]) {
      await page.mouse.move(b.x + b.width * dx, b.y + b.height * dy, { steps: 6 });
    }
    await page.mouse.up();
  }
  // "Guardar firma" is what copies the drawing into the hidden field the server reads (prompt 220) — a stroke
  // alone leaves the field empty and the server answers "Falta la firma".
  await page.click('[data-signature-save]');
  await page.waitForTimeout(400);
  await page.waitForTimeout(3500); // clear the spam guard's minimum dwell
  // The APPLICATION form's submit, scoped to its form. A bare `button[type="submit"]` matched the locale
  // switcher's ES button first in the DOM (prompt 167's header), so the harness posted a language change and
  // reloaded an empty form — STOP 1 failed on every viewport for a harness reason, not an app one (prompt 254).
  await page.click('form[enctype="multipart/form-data"] button[type="submit"]');
  await page.waitForLoadState('networkidle');
}

// Drive one run: sign in, open a till, hand over, submit, then either tap the button or type `/`.
async function runFlow(theme, vp, { viaButton }) {
  const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, colorScheme: theme });
  const page = await ctx.newPage();
  const label = `${theme} ${vp.tag} ${viaButton ? 'button' : 'typed-/'}`;

  if (! await signInToCounter(page, '/counter/members', { sede: SEDE })) {
    results.push([`${label}: sign in`, false]); await ctx.close(); return;
  }

  // Till-first (236): a sign-up needs an open drawer. Open one if the guard sent us to the till screen.
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  const floatInput = await page.$('[data-till-float] input, input[wire\\:model="floatInput"]');
  if (floatInput) {
    await floatInput.fill('100');
    await page.click('[data-till-open-action]').catch(() => {});
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(600);
  }

  // Hand the tablet over — the alta modal's card 2 calls the real handOverForAlta().
  await page.goto(`${BASE}/counter/members`, { waitUntil: 'networkidle' });
  await page.click('[data-alta-toggle]');
  await page.waitForSelector('[data-alta-modal]');
  await page.click('[data-alta-handover]');
  await page.waitForLoadState('networkidle');

  await fillAndSubmitApplication(page);

  // STOP 1 — the thank-you card with the way back.
  const returnBtn = await page.$('[data-handover-return]');
  if (viaButton) {
    await page.screenshot({ path: `${OUT}/1-thankyou-${theme}-${vp.tag}.png` });
    results.push([`${label}: thank-you shows return`, !!returnBtn]);
  }

  // Reach the surface — the button, or a stray `/` typed in the address bar.
  if (viaButton) {
    if (returnBtn) { await returnBtn.click(); await page.waitForLoadState('networkidle'); }
  } else {
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  }

  // STOP 2 — the surface: "Solicitud recibida", pad open, and NOT the finished form.
  const onCounter = new URL(page.url()).pathname.startsWith('/counter');
  const padOpen = await page.$('[data-counter-surface-unlock]');
  const received = (await page.content()).includes('Solicitud recibida') || (await page.content()).includes('Application received');
  await page.screenshot({ path: `${OUT}/2-surface-${theme}-${vp.tag}-${viaButton ? 'button' : 'typed'}.png` });
  results.push([`${label}: surface pad on ${new URL(page.url()).pathname}`, onCounter && !!padOpen && received]);

  // The PIN → the review with the applicant's name.
  if (padOpen) {
    for (const digit of PIN.split('')) {
      await page.click(`[data-counter-surface] button:has-text("${digit}")`).catch(() => {});
    }
    await padOpen.click();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000);
  }

  // STOP 3 — the review, reached from the PIN.
  const onReview = await page.$('[data-alta-review]');
  const namesApplicant = (await page.content()).includes('María');
  await page.screenshot({ path: `${OUT}/3-review-${theme}-${vp.tag}-${viaButton ? 'button' : 'typed'}.png` });
  results.push([`${label}: review names applicant`, !!onReview && namesApplicant]);

  await ctx.close();
}

for (const vp of VIEWPORTS) {
  for (const theme of ['light', 'dark']) {
    await runFlow(theme, vp, { viaButton: true });
    await runFlow(theme, vp, { viaButton: false });
  }
}

await browser.close();
let ok = true;
for (const [labelText, pass] of results) { console.log(`${pass ? 'PASS' : 'FAIL'}  ${labelText}`); ok = ok && pass; }
process.exitCode = ok ? 0 : 1;
