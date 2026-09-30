// Prompt 330 — a new sign-up pops up on the counter within one poll. Throwaway database; INVITE = the invitation link
// (issued through IssueApplicationInvite), DBFILE read with sqlite3. At 1180×820:
//   1. the counter is open on Dispensario as staff, the search field focused, no bell;
//   2. the applicant fills in the invitation in their own browser;
//   3. within 15 s (+ margin) the banner reads "Nueva solicitud: Thomas P." with the bell's count; focus has not moved,
//      and the banner is clear of the commit area; light, dark and 390 screenshots;
//   4. *Revisar y aprobar* lands on Socios in that review; approve, collect the fee; the bell is gone.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/330';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(700); };

// 1. The counter.
const pos = await browser.newPage({ viewport: { width: 1180, height: 820 } });
await signInToCounter(pos, '/counter/till', { account: 'staff', sede: 'Central Branch' });
if (await pos.locator('input[wire\\:model="floatInput"]').count()) {
    await pos.fill('input[wire\\:model="floatInput"]', '50');
    await pos.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(pos);
}
await pos.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
check('no bell while nothing is pending', await pos.locator('[data-bell-count]').count() === 0);
await pos.focus('#member-lookup');

// 2. The applicant, on their phone.
const phone = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
await phone.goto(process.env.INVITE, { waitUntil: 'networkidle' });
const pad = phone.locator('[data-signature-canvas]');
if (await pad.count()) {
    await pad.scrollIntoViewIfNeeded();
    const b = await pad.boundingBox();
    await phone.mouse.move(b.x + 20, b.y + 40); await phone.mouse.down();
    await phone.mouse.move(b.x + 160, b.y + 90, { steps: 10 }); await phone.mouse.up();
    await phone.click('[data-signature-save]'); await phone.waitForTimeout(300);
}
await phone.fill('#first_name', 'Thomas');
await phone.fill('#last_name', 'Powney');
await phone.fill('#email', `thomas.${Date.now()}@example.test`);
await phone.fill('#date_of_birth', '1991-03-02');
await phone.selectOption('#document_type', 'DNI');
await phone.fill('#document_number', 'PRUEBA-330');
await phone.check('input[name="consent_data"]');
await phone.check('input[name="consent_statutes"]');
await phone.waitForTimeout(3500); // past the spam guard's floor
await phone.click('form[enctype] button[type="submit"]'); await settle(phone);
check('the application was submitted', sql("select count(*) from member_applications where status = 'PENDING' and submitted_at is not null and payload like '%Powney%'") === '1');
const submittedAt = Date.now();

// 3. The banner, within one poll.
const banner = pos.locator('[data-applications-banner]');
await banner.waitFor({ state: 'visible', timeout: 20000 }).catch(() => {});
const took = Math.round((Date.now() - submittedAt) / 1000);
const text = (await banner.innerText().catch(() => '')).replace(/\s+/g, ' ');
check('the banner arrived within a poll', await banner.isVisible(), `${took}s`);
check('it reads "Nueva solicitud: Thomas P." and nothing more about them', /Nueva solicitud: Thomas P\./.test(text) && ! /Powney|PRUEBA-330|example\.test/.test(await pos.content()), text);
check('the bell counts one', (await pos.locator('[data-bell-count]').innerText()).trim() === '1');
check('focus stayed in the search', await pos.evaluate(() => document.activeElement?.id) === 'member-lookup');
const bb = await banner.boundingBox();
const commit = pos.locator('[data-commit-action]');
const cb = await commit.count() ? await commit.first().boundingBox() : null;
check('clear of the commit area', ! cb || bb.y + bb.height <= cb.y || cb.y + cb.height <= bb.y, JSON.stringify({ banner: bb, commit: cb }));
await pos.screenshot({ path: `${OUT}/1-banner-light-1180.png` });
await pos.emulateMedia({ colorScheme: 'dark' });
await pos.screenshot({ path: `${OUT}/2-banner-dark-1180.png` });
await pos.emulateMedia({ colorScheme: 'light' });
await pos.setViewportSize({ width: 390, height: 844 });
await pos.waitForTimeout(300);
check('no sideways scroll at 390', await pos.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
await pos.screenshot({ path: `${OUT}/3-banner-390.png` });
await pos.setViewportSize({ width: 1180, height: 820 });

// 4. Review, approve, the fee.
await pos.click('[data-bell-review]'); await settle(pos);
check('Revisar y aprobar lands on Socios in the review', /\/counter\/members\?solicitud=/.test(pos.url()) && await pos.locator('[data-alta-review]').count() > 0, pos.url());
await pos.selectOption('#alta-tier', { index: 1 }); await settle(pos);
await pos.click('[data-alta-approve]'); await settle(pos);
const memberId = sql("select resulting_member_id from member_applications where payload like '%Powney%'");
check('approved: a member was created', memberId !== '');
const feeInput = pos.locator('input[wire\\:model="feeAmount"], input[wire\\:model\\.live="feeAmount"]').first();
if (await feeInput.count() && (await feeInput.inputValue()) === '') {
    await feeInput.fill((Number(sql(`select fee_cents from memberships where member_id = '${memberId}'`)) / 100).toFixed(2));
}
await pos.locator('button[wire\\:click="collectFee"], form[wire\\:submit="collectFee"] button[type="submit"]').first().click(); await settle(pos);
check('the fee was collected', Number(sql(`select coalesce(sum(p.amount_cents), 0) from membership_fee_payments p join memberships ms on ms.id = p.membership_id where ms.member_id = '${memberId}'`)) > 0);
await pos.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
check('nothing pending: no bell, no banner', await pos.locator('[data-bell-count]').count() === 0 && await banner.count() === 0);
await pos.screenshot({ path: `${OUT}/4-after-approval-1180.png` });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
