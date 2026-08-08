// Prompt 222 — the sign-up's close guard, in a real browser, because that is the only place it is visible.
//
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DevAdminSeeder
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/measure-close-guard.mjs
//
// **Why a real server and not the static harness.** The defect is that a SERVER-rendered `wire:confirm` over
// deferred `wire:model` fields is always one action behind the typing. A server-side test cannot see it —
// it renders exactly the state the server believes in, which is the thing that was wrong. The static
// file:// harnesses cannot see it either: they carry no JavaScript, and this guard IS JavaScript. So the
// harness drives the actual app, types into the actual field, and listens for the actual dialog.
//
// The confirm is a native browser dialog, which is browser chrome: it cannot appear in a screenshot. The
// evidence is therefore the dialog EVENT and its message text, printed per case, plus before/after frames
// showing the typing intact once the confirm is dismissed.

import { chromium } from 'playwright';
import { mkdirSync, readFileSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8123';
const EMAIL = 'owner@club.test';
const PASSWORD = 'password';
const PIN = ['1', '2', '3', '4'];
const OUT = 'storage/app/screenshots/222';
// The key IS the Spanish source string (prompt 19), and the app runs in whichever locale the operator has.
// Both are accepted rather than pinning one, because which language the counter is in is not what this
// harness is about — and hard-coding one made it fail on an English install with a working guard.
const KEY = '¿Descartar esta alta? Se perderá lo que has escrito.';
const CONFIRMS = [KEY, JSON.parse(readFileSync('lang/en.json', 'utf8'))[KEY]].filter(Boolean);

mkdirSync(OUT, { recursive: true });

let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };
const ok = (m) => console.log(`  ok   ${m}`);

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1180, height: 820 }, reducedMotion: 'reduce', hasTouch: true });
const page = await ctx.newPage();

// --- sign in and clear the counter chain (sede → PIN) -------------------------------------------
await page.goto(`${BASE}/login`);
await page.fill('input[type="email"]', EMAIL);
await page.fill('input[type="password"]', PASSWORD);
await page.press('input[type="password"]', 'Enter');
await page.waitForLoadState('networkidle');

await page.goto(`${BASE}/counter/members`, { waitUntil: 'networkidle' });
const sede = await page.$('[data-counter-sede-menu] form button');
if (sede) { await sede.click(); await page.waitForLoadState('networkidle'); }
const pad = await page.$('[data-counter-surface-unlock]');
if (pad) {
  for (const d of PIN) await page.click(`[data-counter-surface] button:has-text("${d}")`).catch(() => {});
  await pad.click();
  await page.waitForTimeout(1200);
}

if (! await page.$('[data-alta-toggle]')) {
  fail('the counter did not reach the Socios screen — is the dev seed loaded and the server running?');
  await browser.close();
  process.exit(1);
}

// --- dialog plumbing ---------------------------------------------------------------------------
// Every case declares what it expects; the handler records what actually happened and always DISMISSES,
// because "dismiss keeps everything" is half of what is being asserted.
let seen = null;
page.on('dialog', async (dialog) => { seen = { type: dialog.type(), message: dialog.message() }; await dialog.dismiss(); });

// Between cases the screen is RELOADED rather than closed through Livewire's JS API: whether the modal is
// open is component state, so a fresh page is a clean modal, and the reset then depends on nothing this
// branch is testing.
const reset = async () => {
  await page.goto(`${BASE}/counter/members`, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-alta-toggle]');
};

const openWizard = async () => {
  await page.click('[data-alta-toggle]');
  await page.waitForSelector('[data-alta-modal]');
  await page.click('[data-alta-staff-form]');
  await page.waitForSelector('[data-alta-stepper]');
};

/** Run one case: set the modal up, trigger a close route, and report what the guard did. */
const check = async ({ name, setup, close, expectConfirm, expectStillOpen }) => {
  seen = null;
  await setup();
  await close();
  await page.waitForTimeout(600);

  const open = !! await page.$('[data-alta-modal]');

  // Each case tracks its OWN result, so a run where the first fails still reports what every later one did
  // — and never prints "ok" over a case that failed.
  let bad = false;
  const no = (m) => { fail(`${name}: ${m}`); bad = true; };

  if (expectConfirm && ! seen) no('NO confirm — unsaved work would have been lost silently');
  if (! expectConfirm && seen) no(`a confirm fired with nothing to lose (${seen.message})`);
  if (seen && ! CONFIRMS.includes(seen.message)) no(`unexpected confirm text — ${seen.message}`);
  if (open !== expectStillOpen) no(`modal ${open ? 'stayed open' : 'closed'}, expected the opposite`);

  if (! bad) ok(`${name} — confirm=${seen ? 'yes' : 'no'}, modal ${open ? 'open' : 'closed'}`);

  return open;
};

const typeName = async () => {
  await page.fill('#alta-first-name', 'María');
  await page.click('[data-alta-modal] h2');   // blur, the way a finger moving on would
  await page.waitForTimeout(2500);            // and wait, in case anything syncs on its own — it does not
};

console.log('\n— the defect: typing on the CURRENT step, closed three ways —');

for (const [route, close] of [
  ['Esc', async () => page.keyboard.press('Escape')],
  ['✕', async () => page.click('[data-alta-close]')],
  ['backdrop', async () => page.click('[data-alta-backdrop]', { position: { x: 10, y: 10 } })],
]) {
  await reset();
  const stillOpen = await check({
    name: `typed on step 1, closed with ${route}`,
    setup: async () => { await openWizard(); await typeName(); },
    close,
    expectConfirm: true,
    expectStillOpen: true,
  });

  // Dismissing kept everything — including where in the wizard the operator was.
  if (stillOpen) {
    const value = await page.inputValue('#alta-first-name').catch(() => null);
    const step = await page.getAttribute('[data-alta-step="1"]', 'aria-current');
    if (value !== 'María') fail(`${route}: dismissing the confirm lost the typing (${value})`);
    if (step !== 'step') fail(`${route}: dismissing the confirm moved the wizard off step 1`);
  }
}

console.log('\n— the cases that must NOT nag —');

await reset();
await check({
  name: 'empty chooser, Esc',
  setup: async () => { await page.click('[data-alta-toggle]'); await page.waitForSelector('[data-alta-modal]'); },
  close: async () => page.keyboard.press('Escape'),
  expectConfirm: false,
  expectStillOpen: false,
});

await reset();
await check({
  name: 'untouched wizard, Esc',
  setup: openWizard,
  close: async () => page.keyboard.press('Escape'),
  expectConfirm: false,
  expectStillOpen: false,
});

console.log('\n— the case that already worked, still working —');

await reset();
await check({
  name: 'typed then advanced a step, Esc',
  setup: async () => {
    await openWizard();
    await page.fill('#alta-first-name', 'María');
    await page.fill('#alta-last-name', 'García');
    await page.fill('#alta-dob', '1991-04-12');
    await page.selectOption('#alta-doc-type', 'DNI');
    await page.fill('#alta-doc-number', '12345678Z');
    await page.click('[data-alta-next]');
    await page.waitForTimeout(800);
  },
  close: async () => page.keyboard.press('Escape'),
  expectConfirm: true,
  expectStillOpen: true,
});

console.log('\n— a drawn-but-unsaved signature —');

// Isolated on the MECHANISM, deliberately. By the time the wizard reaches step 4 the server already knows the
// identity fields, so a confirm on Escape there would prove nothing about the drawing. Instead: blank every
// input in the DOM (so the client half says "nothing"), then draw one stroke and ask again. The stroke is the
// only thing that changed, and it is the one thing on this form the member themselves did.
await reset();
await openWizard();
await page.fill('#alta-first-name', 'María');
await page.fill('#alta-last-name', 'García');
await page.fill('#alta-dob', '1991-04-12');
await page.selectOption('#alta-doc-type', 'DNI');
await page.fill('#alta-doc-number', '12345678Z');
await page.click('[data-alta-next]');
await page.waitForTimeout(700);
await page.fill('#alta-email-staff', 'maria@example.es');
await page.click('[data-alta-next]');
await page.waitForTimeout(700);
await page.click('[data-alta-next]');
await page.waitForSelector('[data-signature-canvas]');

const domDirty = () => page.evaluate(() => window.Alpine.$data(document.querySelector('[data-alta-modal]')).domSaysDirty());

await page.evaluate(() => {
  document.querySelectorAll('[data-alta-panel] input, [data-alta-panel] select, [data-alta-panel] textarea')
    .forEach((f) => { if (f.type === 'checkbox' || f.type === 'radio') { f.checked = false; } else { f.value = ''; } });
});

const beforeStroke = await domDirty();
if (beforeStroke) fail('signature: the client guard already reported unsaved work with a blank pad and blank fields');

const box = await page.locator('[data-signature-canvas]').boundingBox();
await page.mouse.move(box.x + box.width * 0.25, box.y + box.height * 0.6);
await page.mouse.down();
await page.mouse.move(box.x + box.width * 0.5, box.y + box.height * 0.35);
await page.mouse.move(box.x + box.width * 0.75, box.y + box.height * 0.6);
await page.mouse.up();

const afterStroke = await domDirty();
if (! afterStroke) fail('signature: a drawn-but-unsaved signature does not count as unsaved work');
if (beforeStroke === false && afterStroke === true) ok('one stroke on the pad, with everything else blank, is unsaved work');

// …and a Borrar takes it back, so a cleared pad does not nag for ever.
await page.click('[data-signature-clear]');
if (await domDirty()) fail('signature: clearing the pad still reports unsaved work');
else ok('clearing the pad takes it back');

// --- the capture: type on step 1, press Escape, the confirm appears -----------------------------
console.log('\n— capture —');
await reset();
await openWizard();
await page.screenshot({ path: `${OUT}/1-wizard-empty.png` });
await page.fill('#alta-first-name', 'María');
await page.fill('#alta-last-name', 'García');
await page.screenshot({ path: `${OUT}/2-typed-on-step-1.png` });
seen = null;
await page.keyboard.press('Escape');
await page.waitForTimeout(600);
await page.screenshot({ path: `${OUT}/3-after-dismissing-the-confirm.png` });
console.log(`  dialog: ${seen ? `${seen.type} — "${seen.message}"` : 'NONE'}`);
console.log(`  typing after dismiss: "${await page.inputValue('#alta-first-name')}"`);
if (! seen) fail('capture: the confirm did not fire');

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS → ${OUT}`);
process.exit(failed ? 1 : 0);
