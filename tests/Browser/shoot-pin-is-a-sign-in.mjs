// Prompt 267 — the tester's sequence on the REAL app: an OWNER-logged tablet, a STAFF PIN at the counter →
// no Administración button, and /users sends the session back to the counter (it used to open as the owner).
// Then a MANAGER PIN → the manager's panel (Roles y permisos refused).
//
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DevAdminSeeder
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/shoot-pin-is-a-sign-in.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, SEDE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/267';
mkdirSync(OUT, { recursive: true });
const results = [];
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1180, height: 820 }, hasTouch: true });
const page = await ctx.newPage();
page.setDefaultTimeout(8000);

// The dev seed's PINs: owner 1234, manager 2345, staff 3456.
async function pin(digits) {
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  if (! await page.isVisible('[data-counter-surface-unlock]')) {
    // Someone is identified — the top bar's "Cambiar de persona" chip opens the pad for the next one.
    await page.click('[data-counter-switch-operator]').catch(() => {});
    await page.waitForSelector('[data-counter-surface-unlock]', { state: 'visible', timeout: 8000 }).catch(() => {});
  }
  for (const d of digits) { await page.click(`[data-counter-surface] button:has-text("${d}")`).catch(() => {}); }
  await page.click('[data-counter-surface-unlock]').catch(() => {});
  await page.waitForTimeout(1200);
  await page.screenshot({ path: `${OUT}/pin-${digits}.png` });
}

if (! await signInToCounter(page, '/counter/till', { sede: SEDE })) { // the OWNER's login + PIN (1234)
  results.push(['sign in', false]);
} else {
  await pin('3456'); // STAFF
  await page.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
  const adminLink = await page.$('[data-counter-admin-link]');
  await page.screenshot({ path: `${OUT}/1-counter-after-staff-pin.png` });
  results.push(['staff PIN: no Administración button', ! adminLink]);

  await page.goto(`${BASE}/users`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: `${OUT}/2-users-as-staff.png` });
  results.push([`staff PIN: /users → ${new URL(page.url()).pathname} (not the owner's page)`, new URL(page.url()).pathname.startsWith('/counter')]);

  await pin('2345'); // MANAGER
  const roles = await page.goto(`${BASE}/roles-y-permisos`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: `${OUT}/3-roles-as-manager.png` });
  results.push([`manager PIN: Roles y permisos → ${roles?.status()}`, roles?.status() === 403]);
  const home = await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  results.push([`manager PIN: the panel opens as the manager (${home?.status()})`, home?.status() === 200]);
}

await browser.close();
let ok = true;
for (const [text, pass] of results) { console.log(`${pass ? 'PASS' : 'FAIL'}  ${text}`); ok = ok && pass; }
process.exitCode = ok ? 0 : 1;
