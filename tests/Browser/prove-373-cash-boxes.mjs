// Prompt 373 — proof on the running app (a throwaway csc:seed-staging DB in English; Central Branch migrated from 349's pots
// to bar + fees boxes; its last close left €45.00 uncounted in the bar box):
//   S. Sedes → Cajas as the owner (1440 and 393, light and dark): each preset sets the rows, the sentence follows, the bar
//      row switched into the till warns that its €45.00 will be merged; «Everything apart» is saved;
//   C. the close screen at 820×1180 with the three boxes;
//   M. the counter's message after an edible paid in cash.
//   BASE_URL=http://127.0.0.1:8360 SEDE=<central id> node tests/Browser/prove-373-cash-boxes.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/373';
mkdirSync(OUT, { recursive: true });
const SEDE = process.env.SEDE;
const results = [];
const check = (label, pass, detail = '') => results.push([label, !!pass, detail]);
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(600); };
const browser = await chromium.launch();

const SENTENCES = {
  fees_apart: 'At close the till is counted (dispensary, edibles and bar) and the fees box.',
  all_apart: 'At close the till is counted (dispensary) and the edibles, bar and fees boxes.',
  all_till: 'At close the till is counted (dispensary, edibles, bar and fees).',
};

// --- S. The settings -----------------------------------------------------------------------------------------------------
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => check(`S page error: ${e.message}`, false));
  await signIn(page, { account: 'owner' });
  const section = () => page.locator('fieldset:has-text("Where does the cash go?")');

  for (const [w, h, theme] of [[1440, 900, 'light'], [393, 852, 'dark'], [1440, 900, 'dark'], [393, 852, 'light']]) {
    await page.setViewportSize({ width: w, height: h });
    await page.goto(`${BASE}/locations/${SEDE}/edit`, { waitUntil: 'networkidle' });
    await page.emulateMedia({ colorScheme: theme });
    await page.evaluate((dark) => document.documentElement.classList.toggle('dark', dark), theme === 'dark');
    const tag = `${w}-${theme}`;
    for (const preset of ['fees_apart', 'all_till', 'all_apart']) {
      await page.locator(`[data-cash-preset="${preset}"]`).click();
      await settle(page);
      const summary = (await page.locator('[data-cash-summary]').innerText()).trim();
      check(`S ${tag} ${preset}: the sentence`, summary === SENTENCES[preset], summary);
      if (preset !== 'all_apart') {
        const warning = page.locator('[data-cash-merge-warning="BAR"]');
        check(`S ${tag} ${preset}: the bar box's €45.00 merge warning`, (await warning.count()) === 1 && (await warning.innerText()).includes('45.00'), (await warning.innerText().catch(() => '')).trim());
      }
      check(`S ${tag}: no sideways scroll`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
      await section().scrollIntoViewIfNeeded();
      await page.screenshot({ path: `${OUT}/settings-${preset}-${tag}.png` });
    }
  }
  // Save «Everything apart» for the counter checks below.
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${BASE}/locations/${SEDE}/edit`, { waitUntil: 'networkidle' });
  await page.locator('[data-cash-preset="all_apart"]').click();
  await settle(page);
  await page.locator('button[type="submit"]:has-text("Save changes")').first().click();
  await settle(page);
  check('S: saved', (await page.locator('body').innerText()).includes('Saved'));
  await ctx.close();
}

async function openTill(page) {
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  const float = page.locator('input[wire\\:model="floatInput"]');
  if (await float.count()) {
    await page.screenshot({ path: `${OUT}/open-till.png` });
    await float.fill('100'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
  }
}

// --- M. The counter's message after an edible paid in cash ---------------------------------------------------------------
{
  const ctx = await browser.newContext({ viewport: { width: 1180, height: 820 }, hasTouch: true });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => check(`M page error: ${e.message}`, false));
  await signInToCounter(page, '/counter', { account: 'manager' });
  await openTill(page);
  await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
  if (await page.locator('#member-lookup').isVisible().catch(() => false)) {
    await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle(page);
    await page.locator('[data-member-lookup-result]').first().click(); await settle(page);
  }
  await page.locator('[data-catalogue-item="genetics"][data-search="Gominola de prueba"]').first().click();
  await settle(page);
  await page.click('[data-add-line]');
  await settle(page);
  await page.click('button[wire\\:click="quickCash"]');
  await settle(page);
  await page.click('[data-commit-action]');
  await settle(page);
  const flash = (await page.locator('body').innerText());
  check('M: the message names the edibles box', /Put €\d+\.\d{2} in the edibles box\./.test(flash), flash.match(/Contribution recorded[^\n]*/)?.[0] ?? flash.match(/Put [^\n]*/)?.[0] ?? '');
  await page.screenshot({ path: `${OUT}/counter-edibles-message-1180.png` });
  await ctx.close();
}

// --- C. The close screen with the three boxes ----------------------------------------------------------------------------
{
  const ctx = await browser.newContext({ viewport: { width: 820, height: 1180 }, hasTouch: true });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => check(`C page error: ${e.message}`, false));
  await signInToCounter(page, '/counter', { account: 'manager' });
  await openTill(page);
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  await page.click('[data-close-till]');
  await settle(page);
  if (await page.locator('[data-reweigh-batch]').count()) {
    for (let i = 0; i < await page.locator('[data-reweigh-not-counted-toggle]').count(); i++) { await page.locator('[data-reweigh-not-counted-toggle]').nth(i).click(); await page.waitForTimeout(200); }
    await page.click('form[wire\\:submit="submitReweigh"] button[type="submit"]');
    await settle(page);
  }
  for (const pot of ['BAR', 'FEES', 'EDIBLES']) check(`C: the close asks for the ${pot} box`, (await page.locator(`[data-pot-count="${pot}"]`).count()) === 1);
  check('C: the till label', (await page.locator('label[for="count"]').innerText()).includes('Till counted'));
  await page.locator('#count').scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${OUT}/close-three-boxes-820.png`, fullPage: true });
  await ctx.close();
}

await browser.close();
for (const [label, pass, detail] of results) console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  — ${detail}` : ''}`);
const failed = results.filter(([, pass]) => !pass).length;
console.log(`${results.length - failed}/${results.length} passed`);
process.exit(failed === 0 ? 0 : 1);
