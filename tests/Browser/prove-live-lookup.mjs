// Prompt 204 — the member lookup searches as you type, and behaves like a combobox.
//
// Four things here are invisible to a PHP test:
//
//   1. **Results appear from typing alone.** `Livewire::test()->set()` proves the SERVER would render them;
//      only a browser proves `wire:model.live` actually fires on keystrokes.
//   2. **The keyboard reaches the rows.** ↑/↓ move an active option, `aria-activedescendant` follows it, and
//      Enter takes it instead of submitting the form. None of that exists server-side.
//   3. **A wedge scanner still works.** This is the risk the branch introduces: with `wire:model.live
//      .debounce`, a scanner types 48 characters and presses Return before the debounce has flushed. If the
//      submit request carried a TRUNCATED `lookup`, every card scan would silently become a name search.
//      Asserted on the raw request payload, which is the only place the answer actually is.
//   4. **The placeholder fits.** 194 dropped the member-number example because the string truncated in the
//      bar's narrow socio column — measured WITH "pulsa Enter" on the end. Re-measured without it.
//
// Needs a RUNNING SERVER:
//   npm run build
//   php artisan serve --port=8123
//   node tests/Browser/prove-live-lookup.mjs
//
// Exits non-zero on any of the four.

import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';

// The sign-in preamble is `counter-session.mjs` (prompts 223/226) — one copy, not ten. Everything below is
// this harness's own: its viewport, its listeners, its measurements.
import { BASE, signInToCounter } from './counter-session.mjs';

const TERM = process.env.MEMBER_QUERY ?? 'ell';   // 2+ chars: the search floor is deliberate
const OUT = 'storage/app/screenshots/204';

await mkdir(OUT, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

const posted = [];
page.on('request', (r) => {
  if (r.url().endsWith('/update') && r.method() === 'POST') posted.push(r.postData() ?? '');
});

await signInToCounter(page, '/counter/checkin');

if (!(await page.$('#member-lookup'))) {
  fail('no lookup on Recepción — the counter chain is still blocking; nothing was measured');
  await browser.close();
  process.exit(1);
}

// --- 1. typing alone produces results ---------------------------------------------------------------
await page.click('#member-lookup');
await page.type('#member-lookup', TERM, { delay: 90 });
await page.waitForTimeout(900);

const rows = await page.$$eval('[data-member-lookup-result]', (n) => n.length);
const expanded = await page.$eval('#member-lookup', (n) => n.getAttribute('aria-expanded'));
console.log(`typed "${TERM}" · rows ${rows} · aria-expanded ${expanded}`);

if (expanded !== 'true') fail('the combobox did not report itself open after typing');
if (rows === 0) fail(`typing "${TERM}" produced no rows — set MEMBER_QUERY to something the demo data matches`);
await page.screenshot({ path: `${OUT}/live-results.png` });

// --- 2. the keyboard reaches the rows ---------------------------------------------------------------
await page.press('#member-lookup', 'ArrowDown');
await page.waitForTimeout(200);
const active1 = await page.$eval('#member-lookup', (n) => n.getAttribute('aria-activedescendant'));
const selected1 = await page.$$eval('[data-member-lookup-result][aria-selected="true"]', (n) => n.length);

await page.press('#member-lookup', 'ArrowDown');
await page.waitForTimeout(200);
const active2 = await page.$eval('#member-lookup', (n) => n.getAttribute('aria-activedescendant'));

console.log(`ArrowDown ×1 → ${active1} (${selected1} selected) · ×2 → ${active2}`);
if (active1 !== 'member-lookup-option-0') fail(`first ArrowDown did not activate option 0 (got ${active1})`);
if (selected1 !== 1) fail(`${selected1} options marked aria-selected after one ArrowDown, expected exactly 1`);
if (rows > 1 && active2 === active1) fail('the second ArrowDown did not move');
await page.screenshot({ path: `${OUT}/keyboard-active.png` });

// Escape closes and clears.
await page.press('#member-lookup', 'Escape');
await page.waitForTimeout(800);
const afterEscape = await page.$eval('#member-lookup', (n) => n.getAttribute('aria-expanded'));
if (afterEscape !== 'false') fail('Escape did not close the list');

// Enter on an active option identifies that member rather than submitting a token search.
await page.click('#member-lookup');
await page.type('#member-lookup', TERM, { delay: 90 });
await page.waitForTimeout(900);
await page.press('#member-lookup', 'ArrowDown');
await page.waitForTimeout(150);
posted.length = 0;
await page.press('#member-lookup', 'Enter');
await page.waitForTimeout(1500);

const enterCalls = posted.join(' ');
if (!enterCalls.includes('selectMember')) fail('Enter on an active option did not call selectMember');
if (enterCalls.includes('submitLookup')) fail('Enter on an active option ALSO submitted the form — the token path ran on a chosen row');
console.log(`Enter on the active option → selectMember ${enterCalls.includes('selectMember')} · submitLookup ${enterCalls.includes('submitLookup')}`);
await page.screenshot({ path: `${OUT}/chosen-by-keyboard.png` });

// --- 3. a wedge scan is not truncated by the debounce -------------------------------------------------
await page.goto(`${BASE}/counter/checkin`);
await page.waitForLoadState('networkidle');

const TOKEN = 'z'.repeat(48);                       // shape of a real token; it need not resolve
await page.click('#member-lookup');
posted.length = 0;
await page.type('#member-lookup', TOKEN, { delay: 4 });   // a wedge reader types ~4ms/char
await page.press('#member-lookup', 'Enter');
await page.waitForTimeout(1800);

const submit = posted.find((b) => b.includes('submitLookup')) ?? '';
const carriedFullToken = submit.includes(TOKEN);
console.log(`wedge scan · submit request found ${submit !== ''} · carried all 48 chars ${carriedFullToken}`);
if (submit === '') fail('the scanner\'s Return never reached submitLookup');
if (!carriedFullToken) fail('the submit request carried a TRUNCATED token — the debounce ate the scan');

// --- 4. the placeholder fits the narrowest field it appears in ----------------------------------------
for (const [route, label] of [['/counter/bar', 'bar socio column'], ['/counter/checkin', 'recepción']]) {
  await page.goto(BASE + route);
  await page.waitForLoadState('networkidle');
  if (!(await page.$('#member-lookup'))) { console.log(`${label}: no lookup on screen — not measured`); continue; }

  const fit = await page.$eval('#member-lookup', (el) => {
    const cs = getComputedStyle(el);
    const ctx = document.createElement('canvas').getContext('2d');
    ctx.font = `${cs.fontStyle} ${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
    const inner = el.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    return { text: el.placeholder, needed: ctx.measureText(el.placeholder).width, available: inner };
  });
  const ok = fit.needed <= fit.available;
  console.log(`${label}: "${fit.text}" needs ${fit.needed.toFixed(0)}px of ${fit.available.toFixed(0)}px — ${ok ? 'fits' : 'TRUNCATES'}`);
  if (!ok) fail(`the placeholder truncates in the ${label}`);
  await page.screenshot({ path: `${OUT}/placeholder-${label.split(' ')[0]}.png` });
}

await browser.close();
console.log(failed ? 'RESULT: FAIL' : 'RESULT: PASS');
process.exit(failed ? 1 : 0);
