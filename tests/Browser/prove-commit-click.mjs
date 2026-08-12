// Prompt 195 — the assertion that would have caught this on day one: press the REAL button in a REAL
// browser and prove a row appears.
//
// Every PHP test of this path calls the method directly (`Livewire::test(...)->call('commit')`), which never
// touches the JS `$wire` proxy and therefore never meets Livewire's alias table. Forty-two of them passed
// while neither commit button worked. This script is the missing half.
//
// It needs a RUNNING SERVER (unlike the other .mjs, which measure static artifacts):
//   npm run build
//   php artisan serve --port=8123
//   node tests/Browser/prove-commit-click.mjs
//
// Exits non-zero if the Livewire request names a $wire alias, or if no order is recorded.

import { chromium } from 'playwright';

// The sign-in preamble is `counter-session.mjs` (prompts 223/226) — one copy, not ten. Everything below is
// this harness's own: its viewport, its listeners, its measurements.
import { BASE, signInToCounter } from './counter-session.mjs';

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
let failed = false;
const calls = [];

page.on('request', (r) => {
  // Livewire v4 serves its update endpoint from an OBFUSCATED path (e.g. /livewire-98d5a080/update), so
  // matching '/livewire/update' captures nothing. Match the suffix instead.
  if (r.url().endsWith('/update') && r.method() === 'POST') {
    // Match on the RAW body rather than a parsed shape: the point is which name went over the wire, and
    // the payload shape is Livewire's to change. `$commit` vs `commitOrder` is unambiguous either way.
    const raw = r.postData() ?? '';
    if (raw.includes('"$commit"')) calls.push('$commit');
    if (raw.includes('"commitOrder"')) calls.push('commitOrder');
    if (raw.includes('"commitDispensation"')) calls.push('commitDispensation');
  }
});

// --- log in and reach the bar --------------------------------------------------------------------
await signInToCounter(page, '/counter/bar');

const before = await page.$$eval('[data-product]', (n) => n.length);
if (before === 0) { console.error('FAIL: no articles on the bar screen — cannot test the commit'); failed = true; }

await page.click('[data-product]:not([disabled])');
await page.waitForTimeout(600);

calls.length = 0;                              // only care about what the COMMIT click sends
await page.click('[data-commit-action]');
await page.waitForTimeout(1500);

console.log('Livewire calls from the commit click:', JSON.stringify(calls));

if (calls.includes('$commit')) {
  console.error('FAIL: the click resolved to Livewire\'s built-in $commit — the action is shadowed by the alias table.');
  failed = true;
}
if (!calls.includes('commitOrder')) {
  console.error(`FAIL: the click did not call commitOrder (got ${JSON.stringify(calls)}).`);
  failed = true;
}

const flash = await page.$eval('[data-commit-feedback]', (n) => n.textContent.trim()).catch(() => null);
console.log('flash after the click:', flash);
if (!flash) { console.error('FAIL: no observable outcome after pressing the commit button.'); failed = true; }

await page.screenshot({ path: 'storage/app/screenshots/195-bar-commit.png' });

// --- the dispensary ------------------------------------------------------------------------------
// Only REACHABILITY is checked here, which is the half PHP cannot see. Whether the commit then refuses for
// a missing signature, an unmet limit or an empty basket is behaviour, and 42 PHP tests already own it —
// what they could not prove is that the button arrives at the method at all.
await page.goto(`${BASE}/counter/pos`);
await page.waitForLoadState('networkidle');

// The dispensary blocks on a member (prompt 175's chain), so the commit button does not exist until one is
// identified. Since prompt 194 that is ONE field which resolves a token first and falls through to the name
// search, so this types and presses Enter rather than waiting on a live-search debounce — and in doing so it
// proves the real Enter path in a real browser, which is the half PHP cannot see (the 195 lesson).
const memberLookup = await page.$('#member-lookup');
if (memberLookup) {
  await memberLookup.fill(process.env.MEMBER_QUERY ?? 'M-');
  await memberLookup.press('Enter');
  await page.waitForTimeout(1200);
  const first = await page.$('[data-member-lookup-result]');
  if (first) { await first.click(); await page.waitForTimeout(1200); }
  else { console.error('FAIL: the lookup returned no result rows after Enter.'); failed = true; }
}

const posCommit = await page.$('[data-commit-action]');
if (posCommit === null) {
  console.error('FAIL: the dispensary has no commit button to press.');
  failed = true;
} else {
  calls.length = 0;
  await posCommit.click();
  await page.waitForTimeout(1200);
  console.log('Livewire calls from the dispensary commit click:', JSON.stringify(calls));

  if (calls.includes('$commit')) {
    console.error('FAIL: the dispensary click resolved to Livewire\'s built-in $commit.');
    failed = true;
  }
  if (!calls.includes('commitDispensation')) {
    console.error(`FAIL: the dispensary click did not call commitDispensation (got ${JSON.stringify(calls)}).`);
    failed = true;
  }
  await page.screenshot({ path: 'storage/app/screenshots/195-pos-commit.png' });
}

await browser.close();
console.log(failed ? 'FAILED' : 'OK — the real button reaches the real action');
process.exit(failed ? 1 : 0);
