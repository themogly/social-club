// Prompt 322 — setting a PIN in the panel: no password field, typed twice, confirmation of where it works, Probar PIN.
// Throwaway database, as the owner, in Chromium (the local Playwright has no WebKit; the Mac Safari check is Ben's):
//   1. Club Staff → Editar → *Establecer un PIN nuevo*: the PIN input is type="text", autocomplete="off", visually masked;
//      letters typed into it are dropped; the eye reveals it; *Repite el PIN* appears;
//   2. a mismatched repeat is refused; a matching one saves and the notification names Central Branch;
//   3. *Probar PIN* → Coincide; a wrong PIN → No coincide;
//   4. the PIN opens the Central Branch counter as Club Staff.
import { chromium } from 'playwright';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/322';
const STAFF_ID = process.env.STAFF_ID;
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(600); };
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
check('sign in', await signIn(page, { account: 'owner' }));

await page.goto(`${BASE}/users/${STAFF_ID}/edit`, { waitUntil: 'networkidle' });
check('the PIN input is not rendered before asking to set one', await page.locator('input[id="form.pin"]').count() === 0);
await page.locator('.fi-fo-field:has(label:has-text("Establecer un PIN nuevo")) button[role="switch"]').click(); await settle(page);

const pin = page.locator('input[id="form.pin"]');
const attrs = await pin.evaluate((el) => ({ type: el.type, autocomplete: el.getAttribute('autocomplete'), inputmode: el.getAttribute('inputmode'),
    ignore: ['data-1p-ignore', 'data-lpignore', 'data-bwignore', 'data-form-type'].every((a) => el.hasAttribute(a)),
    masked: getComputedStyle(el).webkitTextSecurity }));
check('the PIN input is a text input, autocomplete off, numeric, with the ignore hints', attrs.type === 'text' && attrs.autocomplete === 'off' && attrs.inputmode === 'numeric' && attrs.ignore, JSON.stringify(attrs));
check('it is masked visually', attrs.masked === 'disc', attrs.masked);
await pin.click();
await page.keyboard.type('48ab2x6');
check('anything but digits is dropped as it is typed', await pin.inputValue() === '4826', await pin.inputValue());
await page.locator('.fi-fo-field:has(input[id="form.pin"]) .fi-input-wrp-suffix button').first().click(); await page.waitForTimeout(200);
check('the eye reveals it', await pin.evaluate((el) => getComputedStyle(el).webkitTextSecurity) === 'none');
await pin.blur(); await settle(page);
const repeat = page.locator('input[id="form.pin_confirmation"]');
check('«Repite el PIN» appears', await repeat.count() === 1);
await page.screenshot({ path: `${OUT}/edit-pin-revealed-1440.png`, fullPage: true });

await repeat.fill('4827');
await page.getByRole('button', { name: 'Guardar cambios' }).click(); await settle(page);
check('a repeat that does not match is refused', /Los PIN no coinciden/.test(await page.locator('main').innerText()));
await page.screenshot({ path: `${OUT}/mismatch-1440.png`, fullPage: true });
await repeat.fill('4826');
await page.getByRole('button', { name: 'Guardar cambios' }).click(); await settle(page);
const saved = (await page.locator('.fi-no-notification').allInnerTexts()).join(' ');
check('the notification says whose PIN and where to try it', /PIN guardado para Club Staff\. Pruébalo en el mostrador de Central Branch/.test(saved), saved.replace(/\s+/g, ' ').slice(0, 140));
await page.screenshot({ path: `${OUT}/saved-1440.png` });

// 3. Probar PIN.
for (const [typed, expect] of [['4826', /Coincide/], ['9999', /No coincide/]]) {
    await page.getByRole('button', { name: 'Probar PIN' }).click(); await settle(page);
    const modalPin = page.locator('.fi-modal-window:visible input[id$="pin"]').first();
    check(`the Probar PIN field is not a password field (${typed})`, await modalPin.getAttribute('type') === 'text');
    await modalPin.click(); await page.keyboard.type(typed);
    await page.locator('.fi-modal-window:visible .fi-modal-footer-actions button').filter({ hasText: /^\s*Probar\s*$/ }).click(); await settle(page);
    const answer = (await page.locator('.fi-no-notification').last().innerText()).trim();
    check(`Probar PIN with ${typed === '4826' ? 'this person\'s PIN' : 'a wrong PIN'} answers ${expect.source}`, expect.test(answer) && (typed !== '4826' || ! /No coincide/.test(answer)), answer);
    await page.screenshot({ path: `${OUT}/probar-${typed}-1440.png` });
}

// 4. The counter.
const counter = await browser.newPage({ viewport: { width: 1180, height: 820 } });
await signInToCounter(counter, '/counter', { account: 'owner', sede: 'Central Branch' });
await counter.locator('[data-counter-switch-operator]').first().click().catch(async () => { await counter.locator('[data-counter-lock]').first().click(); });
await settle(counter);
for (const digit of '4826') await counter.click(`[data-counter-surface] button:has-text("${digit}")`).catch(() => {});
await counter.locator('[data-counter-surface-unlock]').click().catch(() => {}); await settle(counter);
const skip = counter.locator('[data-clock-skip]');
if (await skip.isVisible().catch(() => false)) { await skip.click(); await settle(counter); }
check('the PIN opens the Central Branch counter as Club Staff', /Club Staff/.test(await counter.content()));
await counter.screenshot({ path: `${OUT}/counter-1180.png` });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
