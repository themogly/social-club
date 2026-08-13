// Prompt 229 — the waiver's radios, in a real browser.
//
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/measure-waiver-reasons.mjs
//
// The half no server-side test can see. Radios are mutually exclusive only within a `name` group; without one
// each is a group of ONE, so clicking a second checks it WITHOUT unchecking the first — both lit until
// Livewire's round trip morphs the attribute back. That window is ~100ms on localhost and invisible, which is
// exactly why it shipped; on a counter tablet on wifi it is long, and a dropped update makes it permanent.
//
// So the check is deliberately made BEFORE any round trip can help: click, then read the DOM immediately.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { signInToCounter } from './counter-session.mjs';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/229';
mkdirSync(OUT, { recursive: true });

let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };
const ok = (m) => console.log(`  ok   ${m}`);

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1180, height: 820 }, hasTouch: true, reducedMotion: 'reduce' });
const page = await ctx.newPage();

if (! await signInToCounter(page, '/counter/checkin')) {
  fail('could not reach the door — is the dev seed loaded and the server running?');
  await browser.close();
  process.exit(1);
}

// Find a socio who owes a fee, so the waiver is on screen at all.
const lookup = await page.$('#member-lookup');
if (! lookup) { fail('no lookup on the door'); await browser.close(); process.exit(1); }

let opened = false;
for (const term of ['M-', 'a', 'e']) {
  await lookup.fill(term);
  await lookup.press('Enter');
  await page.waitForTimeout(1200);

  const rows = await page.$$('[data-member-lookup-result]');
  for (const row of rows.slice(0, 8)) {
    await row.click();
    await page.waitForTimeout(1400);

    if (await page.$('[data-fee-waive-toggle]')) {
      await page.click('[data-fee-waive-toggle]');
      await page.waitForTimeout(900);
      if (await page.$('[data-waive-reason]')) { opened = true; break; }
    }

    await page.goto(page.url(), { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    const back = await page.$('#member-lookup');
    if (back) { await back.fill(term); await back.press('Enter'); await page.waitForTimeout(1000); }
  }
  if (opened) break;
}

if (! opened) {
  fail('no socio on this seed owes a fee with the waiver available — nothing was measured');
  await browser.close();
  process.exit(1);
}

const state = () => page.evaluate(() => {
  const radios = Array.from(document.querySelectorAll('[data-waive-reason]'));

  return {
    values: radios.map((r) => r.getAttribute('data-waive-reason')),
    names: [...new Set(radios.map((r) => r.getAttribute('name')))],
    checked: radios.filter((r) => r.checked).map((r) => r.getAttribute('data-waive-reason')),
    otherText: !! document.querySelector('[data-waive-reason-text]'),
  };
});

const before = await state();
console.log(`  reasons on a fresh open: ${before.values.join(', ')} · checked: ${before.checked.join(', ') || 'none'}`);

if (before.names.length !== 1 || ! before.names[0]) fail(`the radios are in ${before.names.length} groups (${before.names.join('|')}) — they cannot be exclusive`);
else ok(`one group: ${before.names[0]}`);

if (before.values.length < 2) {
  fail(`only ${before.values.length} reason(s) rendered — cannot test exclusivity`);
} else {
  // Click A, then B, reading the DOM IMMEDIATELY after each — no waiting, no round trip.
  const [a, b] = before.values;

  await page.click(`[data-waive-reason="${a}"]`);
  const afterA = await state();
  if (afterA.checked.length !== 1 || afterA.checked[0] !== a) fail(`after clicking ${a}: checked = [${afterA.checked.join(', ')}]`);
  else ok(`clicking ${a} leaves exactly one lit, immediately`);

  await page.click(`[data-waive-reason="${b}"]`);
  const afterB = await state();
  if (afterB.checked.length !== 1) fail(`after clicking ${b}: ${afterB.checked.length} radios lit at once — [${afterB.checked.join(', ')}] (this is the reported defect)`);
  else if (afterB.checked[0] !== b) fail(`after clicking ${b}: ${afterB.checked[0]} is lit instead`);
  else ok(`clicking ${b} deselects ${a} with no round trip — exactly one lit`);

  await page.screenshot({ path: `${OUT}/${STAGE}-waiver-after-switching-1180x820-light.png` });

  // …and the OTHER free-text box follows the selection, both ways.
  await page.waitForTimeout(900);
  const otherValue = before.values[before.values.length - 1];
  await page.click(`[data-waive-reason="${otherValue}"]`);
  await page.waitForTimeout(900);
  if (! (await state()).otherText) fail('choosing "Otro motivo" did not reveal its text box');
  else ok('the free-text box appears with its reason');

  await page.click(`[data-waive-reason="${a}"]`);
  await page.waitForTimeout(900);
  if ((await state()).otherText) fail('the free-text box survived switching away from "Otro motivo"');
  else ok('…and goes away with it');
}

await page.screenshot({ path: `${OUT}/${STAGE}-waiver-open-1180x820-light.png` });
await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS → ${OUT}`);
process.exit(failed ? 1 : 0);
