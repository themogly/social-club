// Prompt 366 — proof on the running app (a throwaway csc:seed-staging DB, accounts in English, staff granted till.close +
// stock.take as the guides' "about" says):
//   C. the close at 1180×820 (light + dark) and 390: a flower count that is off → «Continue without a reason»; the cash
//      count offers an optional note with no amount; a count far from expected, no note → «Till closed.»;
//   R. Reports → Tills on «Only with a difference» (1440 light + dark, 390): the «Unexplained» cell, newest first, a row
//      opening the till page, which leads with «Difference at close»;
//   D. the Dashboard's «Closes with an unexplained difference: N this week», linking to the filtered report.
//   BASE_URL=http://127.0.0.1:8360 node tests/Browser/prove-366.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/366';
mkdirSync(OUT, { recursive: true });
const results = [];
const check = (label, pass, detail = '') => results.push([label, !!pass, detail]);
const browser = await chromium.launch();
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };

async function openTill(page) {
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  const float = page.locator('input[wire\\:model="floatInput"]');
  if (await float.count()) {
    await float.fill('100'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
    await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' }); // opening lands on the counter home
  }
}

// --- C. The close never blocks ------------------------------------------------------------------------------------------
for (const [theme, w, h] of [['light', 1180, 820], ['dark', 1180, 820], ['light', 390, 844]]) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, hasTouch: true, colorScheme: theme });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => check(`page error: ${e.message}`, false));
  await signInToCounter(page, '/counter', { account: 'staff' });
  await openTill(page);
  await page.click('[data-close-till]', { timeout: 8000 }).catch(async (e) => { await page.screenshot({ path: `${OUT}/FAIL-${theme}-${w}.png`, fullPage: true }); throw e; });
  await settle(page);
  const tag = `${theme}-${w}`;

  if (await page.locator('[data-reweigh-batch]').count()) {
    const jars = page.locator('[data-reweigh-batch]');
    await jars.nth(0).locator('input[inputmode="decimal"]').fill('1');
    for (let i = 1; i < await jars.count(); i++) { await page.locator('[data-reweigh-not-counted-toggle]').nth(i).click(); await page.waitForTimeout(250); }
    await page.click('form[wire\\:submit="submitReweigh"] button[type="submit"]');
    await settle(page);
    const skip = page.locator('[data-reweigh-no-reason]');
    check(`C ${tag}: «Continue without a reason» offered`, (await skip.count()) === 1 && (await skip.innerText()).includes('Continue without a reason'));
    await page.locator('[data-reweigh-reason-box]').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/C1-reason-box-${tag}.png` });
    await skip.click();
    await settle(page);
    check(`C ${tag}: the count went on without a reason`, (await page.locator('#count').count()) === 1);
  } else {
    check(`C ${tag}: (no flower recount at this close)`, true);
  }

  const note = page.locator('[data-close-note]');
  check(`C ${tag}: optional note offered`, (await note.count()) === 1 && (await note.innerText()).includes('Want to leave a note? (optional)'));
  check(`C ${tag}: still blind — no expected figure`, (await page.locator('[data-till-expected]').count()) === 0);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: `${OUT}/C2-count-${tag}.png`, fullPage: w < 600 });

  await page.fill('#count', '1');
  await page.click('form[wire\\:submit="submitCount"] button[type="submit"]');
  await settle(page);
  const body = await page.locator('body').innerText();
  check(`C ${tag}: closed with a big difference and no note`, body.includes('Till closed.'), body.match(/Till closed\.|A note is needed[^\n]*|system error[^\n]*/)?.[0] ?? '');
  check(`C ${tag}: never "a note is needed"`, !/note is (needed|required)/i.test(body));
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: `${OUT}/C3-closed-${tag}.png`, fullPage: w < 600 });
  await ctx.close();
}

// --- R. Reports → Tills, «Only with a difference» — ONE signed-in owner page (the login rate limit), resized and re-themed.
const owner = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await owner.newPage();
page.on('pageerror', (e) => check(`page error: ${e.message}`, false));
await signIn(page, { account: 'owner' });
const theme = async (scheme) => {
  await page.emulateMedia({ colorScheme: scheme });
  await page.evaluate((dark) => document.documentElement.classList.toggle('dark', dark), scheme === 'dark');
};

for (const [scheme, w, h] of [['light', 1440, 900], ['dark', 1440, 900], ['light', 390, 844], ['light', 1024, 768]]) {
  await page.setViewportSize({ width: w, height: h });
  await page.goto(`${BASE}/informes/cajas?diferencia=1&period=week`, { waitUntil: 'networkidle' });
  await settle(page);
  await theme(scheme);
  const tag = `${scheme}-${w}`;
  check(`R ${tag}: the filter is ticked`, await page.locator('[data-only-variance]').isChecked());
  const unexplained = await page.locator('td.csc-cell-warning').count();
  check(`R ${tag}: «Unexplained» cells`, unexplained >= 1, `${unexplained}`);
  check(`R ${tag}: no horizontal page scroll`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: `${OUT}/R-report-${tag}.png`, fullPage: true });
}

// Untick → every close of the week again; the first row then opens its till page, which leads with the difference.
await page.setViewportSize({ width: 1280, height: 900 });
await page.goto(`${BASE}/informes/cajas?diferencia=1&period=week`, { waitUntil: 'networkidle' });
await theme('light');
const filtered = await page.locator('.csc-rep-table').first().locator('tbody tr').count();
await page.locator('[data-only-variance]').uncheck();
await settle(page);
const all = await page.locator('.csc-rep-table').first().locator('tbody tr').count();
check('R: unticking shows every close (≥ the filtered list)', all >= filtered, `${filtered} → ${all}`);
await page.goto(`${BASE}/informes/cajas?diferencia=1&period=week`, { waitUntil: 'networkidle' });
await page.locator('.csc-rep-table').first().locator('tbody tr').first().locator('a.csc-rep-link').first().click();
await settle(page);
const text = await page.locator('main').innerText();
check('R: the row opens the till page', /till-sessions\/|cajas\//.test(page.url()), page.url());
check('R: «Difference at close» leads the page', text.indexOf('Difference at close') > -1 && text.indexOf('Difference at close') < text.indexOf('Session'));
check('R: «No note» and «Unexplained» on it', text.includes('No note') && text.includes('Unexplained'));
// The close whose flower count went on «without a reason» shows «No reason» (the oldest of this run's closes: the evening
// count appears once per club per day).
const links = await page.goto(`${BASE}/informes/cajas?diferencia=1&period=week`, { waitUntil: 'networkidle' })
  .then(() => page.locator('.csc-rep-table').first().locator('tbody tr a.csc-rep-link').evaluateAll((as) => as.map((a) => a.href)));
let flower = null;
for (const href of links) {
  await page.goto(href, { waitUntil: 'networkidle' });
  if ((await page.locator('main').innerText()).includes('No reason')) { flower = href; break; }
}
check('R: the flower count without a reason reads «No reason» on its till page', flower !== null, flower ?? '');
await theme('light');
await page.screenshot({ path: `${OUT}/T-till-page-1280.png`, fullPage: true });
await theme('dark');
await page.screenshot({ path: `${OUT}/T-till-page-1280-dark.png`, fullPage: true });

// --- D. The dashboard line -----------------------------------------------------------------------------------------------
await page.setViewportSize({ width: 1440, height: 900 });
await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
await settle(page);
await theme('light');
const line = page.locator('a:has-text("Closes with an unexplained difference")');
check('D: the dashboard line is there', (await line.count()) >= 1, (await line.first().innerText().catch(() => '')).trim());
const href = await line.first().getAttribute('href').catch(() => null);
check('D: it links to the filtered report, this week', !!href && href.includes('informes/cajas') && href.includes('diferencia=1') && href.includes('period=week'), href ?? '');
await line.first().scrollIntoViewIfNeeded().catch(() => {});
await page.screenshot({ path: `${OUT}/D-dashboard-1440.png` });
await owner.close();

await browser.close();
for (const [label, pass, detail] of results) console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  — ${detail}` : ''}`);
const failed = results.filter(([, pass]) => !pass).length;
console.log(`${results.length - failed}/${results.length} passed`);
process.exit(failed === 0 ? 0 : 1);
