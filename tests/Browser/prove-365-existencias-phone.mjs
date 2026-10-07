// Prompt 365 — «Existencias» on a phone: the search box has its own full-width row and is never clipped; the empties are
// counted and shown grouped at the bottom; an empty reserve says why. iPhone 15 (393×852) and 820×1180, es and en, and
// one larger text size (root font +12.5%, what a larger iOS text size does to rem-based type).
//   DB_DATABASE=<the server's sqlite> BASE_URL=http://127.0.0.1:8360 node tests/Browser/prove-365-existencias-phone.mjs
import { chromium, devices } from 'playwright';
import { mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/365';
mkdirSync(OUT, { recursive: true });
const results = [];
const check = (label, pass, detail = '') => results.push([label, !!pass, detail]);
const browser = await chromium.launch();
const settle = (page, ms = 500) => page.waitForLoadState('networkidle').catch(() => {}).then(() => page.waitForTimeout(ms));

async function measure(page, label) {
  const m = await page.evaluate(() => {
    const input = document.querySelector('[data-stock-search]');
    const card = document.querySelector('[data-stock-list]');
    if (!input || !card) return null;
    const cs = getComputedStyle(card);
    const inner = card.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    // Is the placeholder clipped? Measure its text against the input's content box.
    const probe = document.createElement('span');
    const ics = getComputedStyle(input);
    probe.style.cssText = `position:absolute;visibility:hidden;white-space:nowrap;font:${ics.font};letter-spacing:${ics.letterSpacing}`;
    probe.textContent = input.placeholder;
    document.body.appendChild(probe);
    const textW = probe.getBoundingClientRect().width;
    probe.remove();
    const contentW = input.clientWidth - parseFloat(ics.paddingLeft) - parseFloat(ics.paddingRight);
    return { input: Math.round(input.getBoundingClientRect().width), inner: Math.round(inner), textW: Math.round(textW), contentW: Math.round(contentW) };
  });
  const phone = label.includes('iphone');
  check(phone ? `${label}: search ≥ 90% of the card's inner width` : `${label}: search keeps ≥ 224px (one row on a tablet)`,
    m && (phone ? m.input >= 0.9 * m.inner : m.input >= 224), m ? `${m.input}px of ${m.inner}px` : 'no search box');
  check(`${label}: placeholder not clipped`, m && m.textW <= m.contentW, m ? `text ${m.textW}px in ${m.contentW}px` : '');
  return m;
}

// The staff account's language, set the way the topbar switch persists it (users.locale), before each pass.
const setLocale = (locale) => execFileSync('php', ['artisan', 'tinker', '--execute', `App\\Models\\User::where('email', 'staff@club.test')->update(['locale' => '${locale}']);`], { env: process.env });

for (const locale of ['es', 'en']) {
  setLocale(locale);
  // ONE sign-in per language, reused by every variant (eight sign-ins in a row run into the login rate limit).
  const login = await browser.newContext();
  await signInToCounter(await login.newPage(), '/counter/existencias', { account: 'staff' });
  const state = await login.storageState();
  await login.close();
  for (const [name, opts] of [['iphone', { ...devices['iPhone 15'] }], ['820', { viewport: { width: 820, height: 1180 }, hasTouch: true }]]) {
    for (const big of [false, true]) {
      const ctx = await browser.newContext({ ...opts, colorScheme: 'light', storageState: state });
      const page = await ctx.newPage();
      await page.goto(`${BASE}/counter/existencias`, { waitUntil: 'networkidle' });
      check(`${locale} ${name}${big ? ' +text' : ''}: page in ${locale}`, (await page.locator('html').getAttribute('lang') || '').startsWith(locale));
      if (big) await page.addStyleTag({ content: 'html { font-size: 112.5% !important; }' });
      await settle(page);
      await measure(page, `${locale} ${name}${big ? ' +text' : ''}`);
      if (!big) await page.screenshot({ path: `${OUT}/header-${locale}-${name}.png` });
      await ctx.close();
    }
  }
}

await browser.close();
let ok = true;
for (const [label, pass, detail] of results) { console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  (${detail})` : ''}`); ok &&= pass; }
console.log(`${results.filter((r) => r[1]).length}/${results.length}`);

// --- Screenshots (en): the header, the empties shown at the bottom, and — with no reserve at the sede — the message. ---
if (process.env.SHOTS) {
  setLocale('en');
  const shots = await chromium.launch();
  const login = await shots.newContext();
  await signInToCounter(await login.newPage(), '/counter/existencias', { account: 'staff' });
  const state = await login.storageState();
  await login.close();
  for (const [name, opts] of [['393-light', { ...devices['iPhone 15'], colorScheme: 'light' }], ['393-dark', { ...devices['iPhone 15'], colorScheme: 'dark' }], ['820', { viewport: { width: 820, height: 1180 }, hasTouch: true, colorScheme: 'light' }]]) {
    const ctx = await shots.newContext({ ...opts, storageState: state });
    const page = await ctx.newPage();
    await page.goto(`${BASE}/counter/existencias`, { waitUntil: 'networkidle' });
    await page.screenshot({ path: `${OUT}/S1-header-${name}.png` });
    if (process.env.SHOTS === 'empties') {
      await page.locator('[data-stock-empty-toggle]').tap();
      await settle(page);
      await page.locator('[data-stock-empty-heading]').scrollIntoViewIfNeeded();
      await page.screenshot({ path: `${OUT}/S2-empties-shown-${name}.png` });
    }
    if (process.env.SHOTS === 'no-reserve') {
      await page.locator('[data-stock-filter="reserve"]').tap();
      await settle(page);
      await page.screenshot({ path: `${OUT}/S3-no-reserve-${name}.png` });
    }
    await ctx.close();
  }
  await shots.close();
}
process.exit(ok ? 0 : 1);
