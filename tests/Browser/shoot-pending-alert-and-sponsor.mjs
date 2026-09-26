// Prompt 264 — proof on the REAL app: (1) tapping the hub's "1 solicitud pendiente" opens that application's
// review, not the sign-up chooser; (2) the admin member form finds a sponsor by SURNAME, labelled name · number.
//
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DevAdminSeeder
//   (plus exactly one submitted, pending application at the sede)
//   npm run build && php artisan serve --port=8123
//   SPONSOR_SURNAME=Adams node tests/Browser/shoot-pending-alert-and-sponsor.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, SEDE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/264';
mkdirSync(OUT, { recursive: true });
const SURNAME = process.env.SPONSOR_SURNAME ?? 'Adams';
const results = [];
const browser = await chromium.launch();

// --- 1. The hub alert, at iPad portrait ---------------------------------------------------------------------
{
  const ctx = await browser.newContext({ viewport: { width: 820, height: 1180 }, hasTouch: true });
  const page = await ctx.newPage();
  if (! await signInToCounter(page, '/counter', { sede: SEDE })) {
    results.push(['hub: sign in', false]);
  } else {
    // Till-first (236): the counter sends a till-less sede to "Abrir caja" — open one first.
    await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
    const float = await page.$('[data-till-float] input, input[wire\\:model="floatInput"]');
    if (float) {
      await float.fill('100');
      await page.click('[data-till-open-action]').catch(() => {});
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(600);
    }
    await page.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
    const alert = await page.$('a[href*="alert=pending_applications"]');
    results.push(['hub: the pending-application alert is shown', !! alert]);
    if (alert) {
      await alert.click();
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(600);
    }
    const reviewOpen = await page.isVisible('[data-alta-review]').catch(() => false);
    const chooserShown = await page.isVisible('[data-alta-staff-form]').catch(() => false);
    await page.screenshot({ path: `${OUT}/alert-opens-review-820x1180.png` });
    results.push(['hub alert → the application\'s review is open, not the sign-up chooser', reviewOpen && ! chooserShown]);
  }
  await ctx.close();
}

// --- 2. The sponsor, by surname, in the admin member form -----------------------------------------------------
{
  const ctx = await browser.newContext({ viewport: { width: 820, height: 1180 } });
  const page = await ctx.newPage();
  if (! await signIn(page)) {
    results.push(['admin: sign in', false]);
  } else {
    await page.goto(`${BASE}/members/create`, { waitUntil: 'networkidle' });
    // Filament's searchable select: open it, type the surname, read the options.
    const trigger = page.locator('[wire\\:key*="avalador_member_id"] button, [id*="avalador_member_id"]').first();
    await trigger.scrollIntoViewIfNeeded().catch(() => {});
    await trigger.click().catch(() => {});
    await page.keyboard.type(SURNAME, { delay: 40 });
    await page.waitForTimeout(1200);
    const options = await page.$$eval('[role="option"]', (els) => els.map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean));
    await page.screenshot({ path: `${OUT}/sponsor-search-${SURNAME.toLowerCase()}-820x1180.png` });
    results.push([`admin: searching "${SURNAME}" offers ${JSON.stringify(options.slice(0, 4))}`, options.some((o) => o.includes(SURNAME) && / · M-\d+/.test(o))]);
  }
  await ctx.close();
}

await browser.close();
let ok = true;
for (const [text, pass] of results) { console.log(`${pass ? 'PASS' : 'FAIL'}  ${text}`); ok = ok && pass; }
process.exitCode = ok ? 0 : 1;
