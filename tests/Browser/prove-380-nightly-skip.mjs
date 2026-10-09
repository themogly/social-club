// Prompt 380 — proof on the running app (a throwaway csc:seed-staging DB in English; Central Branch with edibles in their own
// box counted every night, the bar in its own box counted only when emptied, no till open):
//   S. Locations → Tills at 1440 and 393: the two help lines say where the close starts, never "must";
//   C. the close screen (820×1180): skipping the every-night edibles box shows the note; the till still closes.
//   BASE_URL=http://127.0.0.1:8360 SEDE=<central id> PART=S|C node tests/Browser/prove-380-nightly-skip.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/380';
mkdirSync(OUT, { recursive: true });
const part = (p) => !process.env.PART || process.env.PART.includes(p);
const results = [];
const check = (label, pass, detail = '') => results.push([label, !!pass, detail]);
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };
const browser = await chromium.launch();

if (part('S')) {
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  await signIn(page, { account: 'owner' });
  for (const [w, h] of [[1440, 900], [393, 852]]) {
    await page.setViewportSize({ width: w, height: h });
    await page.goto(`${BASE}/locations/${process.env.SEDE}/edit`, { waitUntil: 'networkidle' });
    const fieldset = page.locator('fieldset:has([data-cash-summary])');
    await fieldset.scrollIntoViewIfNeeded();
    const text = await fieldset.innerText();
    check(`S ${w}: every night says where the close starts and that a skip is noted`, /At close it starts on “Count now”\. If it isn't counted one day, that's noted\./.test(text));
    check(`S ${w}: only when emptied says where the close starts`, /At close it starts on “Not counted today”; what's in it carries to the next day\./.test(text));
    check(`S ${w}: no "must"`, !/must be counted/i.test(text));
    await fieldset.screenshot({ path: `${OUT}/cajas-${w}.png` });
  }
}

if (part('C')) {
  const page = await (await browser.newContext({ viewport: { width: 820, height: 1180 }, hasTouch: true })).newPage();
  await signInToCounter(page, '/counter', { account: 'manager' });
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  const float = page.locator('input[wire\\:model="floatInput"]');
  if (await float.count()) { await float.fill('100'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page); }
  await page.click('[data-close-till]');
  await settle(page);
  if (await page.locator('[data-reweigh-batch]').count()) {
    for (let i = 0; i < await page.locator('[data-reweigh-not-counted-toggle]').count(); i++) { await page.locator('[data-reweigh-not-counted-toggle]').nth(i).click(); await page.waitForTimeout(200); }
    await page.click('form[wire\\:submit="submitReweigh"] button[type="submit"]');
    await settle(page);
  }
  check('C: the every-night box starts on Count now, without the note', (await page.locator('[data-pot-nightly-note="EDIBLES"]').count()) === 0);
  await page.locator('[data-pot-count="EDIBLES"] [data-pot-count-skip]').click();
  await settle(page);
  const note = (await page.locator('[data-pot-nightly-note="EDIBLES"]').innerText().catch(() => '')).trim();
  check('C: skipping it shows the note', /This location counts it every night: it will be noted that it wasn't counted today\./.test(note), note);
  check('C: the only-when-emptied bar box shows no note', (await page.locator('[data-pot-nightly-note="BAR"]').count()) === 0);
  await page.locator('[data-pot-count="EDIBLES"]').scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${OUT}/close-note-820.png`, fullPage: true });
  await page.fill('#count', '100');
  await page.click('form[wire\\:submit="submitCount"] button[type="submit"]');
  await settle(page);
  check('C: the till still closes', /Till closed/i.test(await page.locator('body').innerText()));
}

await browser.close();
for (const [label, pass, detail] of results) console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  — ${detail}` : ''}`);
const failed = results.filter(([, pass]) => !pass).length;
console.log(`${results.length - failed}/${results.length} passed`);
process.exit(failed === 0 ? 0 : 1);
