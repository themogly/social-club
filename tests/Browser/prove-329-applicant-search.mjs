// Prompt 329 — an applicant is found from the counter search. Throwaway database; INVITE = the invitation link (issued
// through IssueApplicationInvite), DBFILE read with sqlite3. At 1180×820:
//   1. the applicant fills in the invitation in their own browser (signature, details, consents);
//   2. at the counter as staff, Dispensario's search finds them under «Solicitudes pendientes»; a tap lands on Socios in
//      the review;
//   3. approve on a tier, then collect the fee;
//   4. back on Dispensario, the search finds them as a member (served once their carencia ends).
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/329';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(700); };

// 1. The applicant, on their phone.
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
await phone.fill('#document_number', 'PRUEBA-329');
await phone.check('input[name="consent_data"]');
await phone.check('input[name="consent_statutes"]');
await phone.waitForTimeout(3500); // past the spam guard's floor
await phone.click('form[enctype] button[type="submit"]'); await settle(phone);
check('the application was submitted', sql("select count(*) from member_applications where status = 'PENDING' and submitted_at is not null and payload like '%Powney%'") === '1');

// 2. The counter.
const pos = await browser.newPage({ viewport: { width: 1180, height: 820 } });
await signInToCounter(pos, '/counter/till', { account: 'staff', sede: 'Central Branch' });
if (await pos.locator('input[wire\\:model="floatInput"]').count()) {
    await pos.fill('input[wire\\:model="floatInput"]', '50');
    await pos.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(pos);
}
await pos.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
await pos.fill('#member-lookup', 'Thom'); await pos.waitForTimeout(1200); await settle(pos);
const row = pos.locator('[data-lookup-applicant]', { hasText: 'Thomas Powney' });
check('Dispensario finds the applicant under «Solicitudes pendientes»', await row.count() === 1 && await pos.locator('[data-lookup-applicants-heading]').isVisible());
check('with the badge and no document', /Pendiente de aprobar/.test(await row.innerText()) && ! /PRUEBA-329/.test(await pos.locator('#member-lookup-results').innerText()));
await pos.screenshot({ path: `${OUT}/1-dispensary-search-1180.png` });
await row.click(); await settle(pos);
check('the tap lands on Socios in the review', /\/counter\/members\?solicitud=/.test(pos.url()) && await pos.locator('[data-alta-review]').count() > 0, pos.url());
await pos.screenshot({ path: `${OUT}/2-socios-review-1180.png` });

// 3. Approve, then the fee.
await pos.selectOption('#alta-tier', { index: 1 }); await settle(pos);
await pos.click('[data-alta-approve]'); await settle(pos);
const memberId = sql("select resulting_member_id from member_applications where payload like '%Powney%'");
check('approved: a member was created', memberId !== '' && sql(`select first_name || ' ' || last_name from members where id = '${memberId}'`) === 'Thomas Powney');
await pos.screenshot({ path: `${OUT}/3-approved-fee-panel-1180.png` });
const feeInput = pos.locator('input[wire\\:model="feeAmount"], input[wire\\:model\\.live="feeAmount"]').first();
if (await feeInput.count() && (await feeInput.inputValue()) === '') {
    const owed = sql(`select ms.fee_cents from memberships ms where ms.member_id = '${memberId}'`);
    await feeInput.fill((Number(owed) / 100).toFixed(2));
}
await pos.locator('button[wire\\:click="collectFee"], form[wire\\:submit="collectFee"] button[type="submit"]').first().click(); await settle(pos);
check('the fee was collected', Number(sql(`select coalesce(sum(p.amount_cents), 0) from membership_fee_payments p join memberships ms on ms.id = p.membership_id where ms.member_id = '${memberId}'`)) > 0);
await pos.screenshot({ path: `${OUT}/4-fee-collected-1180.png` });

// 4. Back to Dispensario: a member now.
await pos.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
await pos.fill('#member-lookup', 'Thom'); await pos.waitForTimeout(1200); await settle(pos);
const member = pos.locator('[data-member-lookup-result]:not([data-lookup-applicant])', { hasText: 'Thomas Powney' });
check('Dispensario now finds them as a member', await member.count() === 1 && await pos.locator('[data-lookup-applicant]', { hasText: 'Thomas Powney' }).count() === 0);
await member.click(); await settle(pos);
// A brand-new member is in their carencia (the club's waiting period): selected at the counter, and told why they can't
// be served yet — the compliance rule, not this prompt. Served once it ends (or it is waived, with its permission).
const main = (await pos.locator('main').innerText()).replace(/\s+/g, ' ');
check('selected at the counter, blocked only by the carencia', /Thomas Powney/.test(main) && /carencia/i.test(main), (main.match(/En carencia[^.]*\./) ?? [''])[0]);
await pos.screenshot({ path: `${OUT}/5-member-ready-1180.png` });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
