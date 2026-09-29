// Prompt 315 — every person has a language saved. Throwaway database (after the backfill), as the owner:
//   1. *Usuarios → Crear*: *Idioma* shows the club default; set Español; create a staff member with a PIN;
//   2. that person's PIN at the counter → the counter is in Spanish (<html lang="es">);
//   3. the owner changes *Idioma* on their profile → the panel and the top-bar ES/EN switch follow.
import { chromium } from 'playwright';
import { BASE, signIn, signInToCounter, enterPin } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/315';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(700); };
const field = (page, id) => page.locator(`.fi-fo-field:has(label[for="form.${id}"])`);
const pick = async (page, id, text) => {
    const native = field(page, id).locator('select');
    if (await native.count()) { await native.selectOption({ label: text }); await settle(page); return; }
    await field(page, id).locator('.fi-select-input').first().click();
    await page.locator('[role="option"]:visible', { hasText: text }).first().click(); await settle(page);
};

const owner = await browser.newPage({ viewport: { width: 1440, height: 900 } });
check('owner signs in', await signIn(owner, { account: 'owner' }));
await owner.goto(`${BASE}/users/create`, { waitUntil: 'networkidle' });
const shown = ((await field(owner, 'locale').textContent()) ?? '').replace(/\s+/g, ' ');
check('a new person\'s Idioma shows the club default', /Español/.test(shown), shown.trim().slice(0, 80));
await owner.fill('input[id="form.name"]', 'Pepa Nueva');
await owner.fill('input[id="form.email"]', 'pepa@club.test');
await owner.locator('input[id="form.email"]').blur(); await settle(owner);
await owner.fill('input[id="form.pin"]', '7391');
if (await owner.locator('input[id="form.password"]').count()) await owner.fill('input[id="form.password"]', 'secreto-73910');
await pick(owner, 'roles', 'STAFF');
await owner.keyboard.press('Escape');
if (await field(owner, 'locations').count()) { await pick(owner, 'locations', 'Central Branch'); await owner.keyboard.press('Escape'); }
await owner.screenshot({ path: `${OUT}/create-user.png`, fullPage: true });
await owner.click('button[type="submit"]:has-text("Crear")'); await settle(owner);
check('the staff member is created', ! owner.url().endsWith('/create'), owner.url());

// Their PIN at the counter: the counter speaks Spanish.
const counter = await browser.newPage({ viewport: { width: 1180, height: 820 } });
await signInToCounter(counter, '/counter', { account: 'owner' });
await counter.locator('[data-counter-switch-operator]').first().click().catch(async () => { await counter.locator('[data-counter-lock]').first().click(); });
await settle(counter);
for (const digit of '7391') await counter.click(`[data-counter-surface] button:has-text("${digit}")`).catch(() => {});
await counter.locator('[data-counter-surface-unlock]').click().catch(() => {}); await settle(counter);
const skip = counter.locator('[data-clock-skip]');
if (await skip.isVisible().catch(() => false)) { await skip.click(); await settle(counter); }
const lang = await counter.getAttribute('html', 'lang');
check('the new staff member\'s counter is in Spanish', lang === 'es' && /Pepa/.test(await counter.content()), `lang=${lang}`);
await counter.screenshot({ path: `${OUT}/counter-pepa.png` });

// The owner's own profile: English → the top bar follows.
await owner.goto(`${BASE}/profile`, { waitUntil: 'networkidle' });
await pick(owner, 'locale', 'English');
await owner.getByRole('button', { name: 'Guardar cambios' }).click(); await settle(owner);
await owner.goto(`${BASE}/`, { waitUntil: 'networkidle' });
const panelLang = await owner.getAttribute('html', 'lang');
const activeEn = await owner.locator('button:has-text("EN")').first().getAttribute('class').catch(() => '');
check('after the profile change the panel is in English and the top bar shows EN', panelLang === 'en' && /bg-|brand|primary|active/.test(activeEn ?? ''), `lang=${panelLang}`);
await owner.screenshot({ path: `${OUT}/profile-en.png` });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
