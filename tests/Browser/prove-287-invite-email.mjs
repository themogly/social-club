// Prompt 287 — "Enviar invitación" at the counter actually sends the email. Run with MAIL_MAILER=log (the email lands in
// storage/logs/laravel.log). As staff at 820×1180: send one, confirm the message names the address and the log holds the
// email with a working link, open the link, then resend from the list and confirm a second email.
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/287';
const LOG = 'storage/logs/laravel.log';
const EMAIL = `lucia.${Date.now() % 100000}@example.es`;
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const logText = () => { try { return readFileSync(LOG, 'utf8'); } catch { return ''; } };
const inviteMailsTo = (text) => text.split(EMAIL).length - 1;

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 820, height: 1180 } });
check('staff sign in', await signInToCounter(page, '/counter/members'));
if (page.url().includes('/counter/till')) {
    await page.fill('input[wire\\:model="floatInput"]', '50');
    await page.click('form[wire\\:submit="open"] button[type="submit"]');
    await page.waitForTimeout(1500);
    await page.goto(`${BASE}/counter/members`, { waitUntil: 'networkidle' });
}

const before = inviteMailsTo(logText());
await page.click('[data-alta-toggle]');
await page.waitForSelector('#alta-email');
await page.fill('#alta-email', EMAIL);
await page.click('[data-alta-invite]');
await page.waitForSelector('[data-alta-invite-sent], [data-alta-invite-failed]', { timeout: 15000 });
const sent = await page.textContent('[data-alta-invite-sent]').catch(() => '');
check('the confirmation names the address', (sent ?? '').includes(EMAIL), (sent ?? '').trim());
await page.screenshot({ path: `${OUT}/820x1180-sent.png` });

await page.waitForTimeout(1500);
const log = logText();
check('the log holds the invitation email to that address', inviteMailsTo(log) > before);
const link = (log.slice(log.lastIndexOf(EMAIL)).match(/https?:\/\/[^\s"<>]+\/socio\/solicitud\/[A-Za-z0-9_\-.]+/) ?? [])[0];
check('the email carries the invitation link', !! link, link ?? '');

// Resend from the counter's list — it is on the same chooser, under the confirmation.
const row = page.locator('[data-alta-invites] li', { hasText: EMAIL });
check('the outstanding invitation is listed with Reenviar', await row.count() === 1);
await page.screenshot({ path: `${OUT}/820x1180-list.png` });
const beforeResend = inviteMailsTo(logText());
await row.locator('[data-alta-invite-resend]').click();
await page.waitForTimeout(2000);
check('Reenviar puts a second email in the log', inviteMailsTo(logText()) > beforeResend);

// The link reaches the application form (a fresh, signed-out browser: the applicant's phone).
if (link) {
    const phone = await browser.newPage({ viewport: { width: 390, height: 844 } });
    const res = await phone.goto(link.replace(/^https?:\/\/[^/]+/, BASE), { waitUntil: 'networkidle' });
    check('the link opens the application form', res?.status() === 200 && await phone.isVisible('form'), `${res?.status()}`);
    await phone.screenshot({ path: `${OUT}/390-application-form.png` });
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
