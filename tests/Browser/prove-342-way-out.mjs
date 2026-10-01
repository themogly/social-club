// Prompt 342 — a way out of the membership application. Freshly seeded demo DB (DBFILE), owner (PIN 1234), at 1180×820;
// INVITE = an emailed invitation link for the phone step.
//   1. Socios → Nuevo socio → hand over the tablet → the form;
//   2. "close the browser, reopen, log in" (same cookies): you land on the counter or the panel, never the form;
//   3. hand over again, tap «Personal», enter a PIN: back on Socios;
//   4. a phone (390×844): the emailed link → «Salir sin enviar» → confirm → nothing sent; the link opens the form again.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/342';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(700); };
const onForm = (p) => /\/socio\/solicitud\//.test(p.url());

const context = await browser.newContext({ viewport: { width: 1180, height: 820 } });
const page = await context.newPage();
page.on('pageerror', (e) => errors.push(e.message));
await signInToCounter(page, '/counter/till', { account: 'owner', sede: 'Central Branch' });
if (await page.locator('input[wire\\:model="floatInput"]').count()) {
    await page.fill('input[wire\\:model="floatInput"]', '50'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
}
const handOver = async () => {
    await page.goto(`${BASE}/counter/members`, { waitUntil: 'networkidle' });
    await page.click('[data-alta-toggle]'); await settle(page);
    await page.click('[data-alta-handover]'); await settle(page);
};

// 1. Hand over.
await handOver();
check('handing over lands on the form, with a discreet «Personal»', onForm(page) && await page.locator('[data-handover-staff-exit]').isVisible(), page.url());
await page.screenshot({ path: `${OUT}/1-form-handed-over-1180.png` });

// 2. Close mid-form, reopen, log in again. Ben's state: the sign-in had gone but the session — and its handover — had not.
// Reproduced by removing the sign-in from the stored session (database sessions), leaving the handover in it.
await page.fill('#first_name', 'Ana').catch(() => {});
execFileSync('php', ['artisan', 'tinker', '--execute', `
    $row = DB::table('sessions')->orderByDesc('last_activity')->first();
    $data = json_decode(base64_decode($row->payload), true);
    foreach (array_keys($data) as $k) { if (str_starts_with($k, 'login_web_') || str_starts_with($k, 'password_hash_web')) unset($data[$k]); }
    DB::table('sessions')->where('id', $row->id)->update(['payload' => base64_encode(json_encode($data)), 'user_id' => null]);
    echo isset($data['counter']['handover']) ? 'handover kept' : 'no handover';`], { env: { ...process.env, DB_DATABASE: DB } });
await page.context().clearCookies({ name: /remember_web/ }).catch(() => {});
// The shared harness's password login (counter-session.mjs) — the login page must ANSWER during the handover now.
const loginShown = await signIn(page, { account: 'owner' });
await settle(page);
check('reopened and logged in: the login page answers and you land on the counter or panel, not the form', loginShown && ! onForm(page), page.url());
await page.goto(`${BASE}/counter/members`, { waitUntil: 'networkidle' });
check('…and the handover is over (the counter answers, not the form)', ! onForm(page), page.url());

// 3. Hand over again, then «Personal» + PIN.
if (await page.locator('[data-counter-surface-unlock]').isVisible().catch(() => false)) {
    for (const d of '1234') await page.locator('[data-counter-surface] button').filter({ hasText: new RegExp(`^${d}$`) }).first().click();
    await page.click('[data-counter-surface-unlock]'); await settle(page);
    if (await page.locator('[data-clock-skip]').isVisible().catch(() => false)) { await page.click('[data-clock-skip]'); await settle(page); }
}
await handOver();
await page.click('[data-handover-staff-exit]'); await page.waitForTimeout(300);
await page.screenshot({ path: `${OUT}/2-staff-pin-1180.png` });
await page.fill('#staff-pin', '1234');
await page.click('[data-handover-staff-submit]'); await settle(page);
check('«Personal» + a staff PIN: back on Socios', new URL(page.url()).pathname === '/counter/members' && ! onForm(page), page.url());
check('audited, naming the operator', sql("select count(*) from audit_logs where action = 'counter.handover.cancelled'") >= '1');
await context.close();

// 4. The applicant's own phone.
const phone = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
await phone.goto(process.env.INVITE, { waitUntil: 'networkidle' });
const dob = await phone.locator('#date_of_birth').inputValue();
check('the phone form: the date of birth is empty, with the dd/mm/aaaa hint; no «Personal»', dob === '' && await phone.getByText('dd/mm/aaaa').isVisible() && await phone.locator('[data-handover-staff-exit]').count() === 0);
await phone.locator('[data-application-leave]').scrollIntoViewIfNeeded();
await phone.click('[data-application-leave]'); await phone.waitForTimeout(300);
const ask = (await phone.locator('form[action$="/salir"]').innerText()).replace(/\s+/g, ' ');
check('«Salir sin enviar» asks first, naming the date the link lasts until', /No se enviará nada\. Puedes volver con el mismo enlace hasta el \d\d\/\d\d\/\d{4}\./.test(ask), ask.slice(0, 120));
await phone.screenshot({ path: `${OUT}/3-leave-ask-390.png` });
await phone.click('[data-application-leave-confirm]'); await settle(phone);
check('confirmed: «No se ha enviado nada.», nothing else to tap', /No se ha enviado nada\./.test(await phone.locator('body').innerText()) && await phone.locator('main a').count() === 0);
await phone.screenshot({ path: `${OUT}/4-left-390.png` });
await phone.goto(process.env.INVITE, { waitUntil: 'networkidle' });
check('the same link opens the form again', await phone.locator('#first_name').count() === 1);

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
