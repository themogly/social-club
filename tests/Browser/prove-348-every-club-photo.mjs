// Prompt 348 — every club, the photo on every scan, card reissue, and no silent sign-up failure. Freshly seeded demo DB.
//   Counter (1180×820, owner): a scanned card shows the photo check (large photo, name, number); «No es esta persona»
//     selects nobody and says so; «Sí» selects. A member with no photo is blocked in the dispensary with «Hacer foto».
//     Socios: «Reemitir carné» → the old token no longer finds the member. The staff sign-up wizard says why on step 1
//     without a photo; an approved staff sign-up is enrolled at both sedes, never the store, one fee.
//   Public form (390×844): submitting without a photo says why and keeps what was typed.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter, openStaffWizard } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/348';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { env: { ...process.env, DB_DATABASE: DB } }).toString().trim().split('\n').pop();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(600); };

const memberId = sql("select id from members where member_no = 'M-00001'");
const token = tinker(`echo (new App\\Actions\\Members\\IssueMemberToken)->handle(App\\Models\\Member::withoutGlobalScopes()->find('${memberId}'));`);

const page = await (await browser.newContext({ viewport: { width: 1180, height: 820 } })).newPage();
page.on('pageerror', (e) => errors.push(e.message));
await signInToCounter(page, '/counter/till', { account: 'owner', sede: 'Central Branch' });
if (await page.locator('input[wire\\:model="floatInput"]').count()) {
    await page.fill('input[wire\\:model="floatInput"]', '50'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
}
const scan = async () => {
    await page.goto(`${BASE}/counter/members`, { waitUntil: 'networkidle' });
    await page.fill('#member-lookup', token); await page.press('#member-lookup', 'Enter'); await settle(page);
};

// 1. The photo check.
await scan();
const card = page.locator('[data-photo-check]');
const photoBox = await page.locator('[data-photo-check-photo]').boundingBox().catch(() => null);
check('a scanned card shows the photo check first: the photo (≥ 240 px), name and number', await card.isVisible() && !! photoBox && photoBox.width >= 240
    && /M-00001/.test(await card.innerText()), JSON.stringify(photoBox && { w: Math.round(photoBox.width) }));
await page.screenshot({ path: `${OUT}/1-photo-check-1180.png` });
await page.click('[data-photo-check-no]'); await settle(page);
check('«No es esta persona»: nobody selected, «No se ha atendido. Avisa a un responsable.»', ! await card.isVisible()
    && /No se ha atendido|Not served/.test(await page.locator('body').innerText()) && sql(`select card_misuse_count from members where id = '${memberId}'`) === '1');
await page.screenshot({ path: `${OUT}/2-not-this-person-1180.png` });
await scan();
await page.click('[data-photo-check-yes]'); await settle(page);
check('«Sí, es esta persona»: the member is selected', /M-00001/.test(await page.locator('[data-member-record], main').first().innerText()) && ! await card.isVisible());

// 2. Reissue the card.
await page.click('[data-reissue-card]'); await page.waitForTimeout(200);
await page.click('[data-reissue-card-confirm]'); await settle(page);
const stillFinds = tinker(`echo (new App\\Actions\\Members\\ResolveMemberByToken)->handle('${token}')?->id ?? 'none';`);
check('«Reemitir carné»: the old token no longer finds the member', stillFinds === 'none', stillFinds);
await page.screenshot({ path: `${OUT}/3-reissued-1180.png` });

// 3. No photo, no dispensing.
const bare = sql("select id from members where member_no = 'M-00003'");
sql(`update members set photo_path = null where id = '${bare}'`);
await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
await page.fill('#member-lookup', 'M-00003'); await page.press('#member-lookup', 'Enter'); await settle(page);
await page.click('[data-member-lookup-result]'); await settle(page);
check('a member with no photo: blocked, «Hazle una foto antes de dispensar» with «Hacer foto»', await page.locator('[data-blocked-resolution="photo"]').isVisible()
    && await page.locator('[data-blocked-resolution="photo"]').getByText(/Hacer foto|Elegir archivo|Take photo|Choose file/).first().isVisible());
await page.screenshot({ path: `${OUT}/4-no-photo-blocked-1180.png` });

// 4. The staff wizard says why; an approved sign-up is enrolled at both sedes.
await page.goto(`${BASE}/counter/members`, { waitUntil: 'networkidle' });
await openStaffWizard(page);
await page.fill('#alta-first-name', 'Prueba'); await page.fill('#alta-last-name', 'Fotografía');
await page.fill('#alta-dob', '1990-05-14'); await page.selectOption('#alta-doc-type', 'DNI'); await page.fill('#alta-doc-number', 'PRUEBA-77777');
await page.locator('[data-alta-next]').click(); await settle(page);
const step1 = (await page.locator('[data-alta-modal]').innerText()).replace(/\s+/g, ' ');
check('the staff wizard without a photo stays on step 1 and says why, keeping what was typed', /foto|photo/i.test(step1) && (await page.inputValue('#alta-first-name')) === 'Prueba', step1.slice(0, 160));
await page.screenshot({ path: `${OUT}/5-staff-no-photo-1180.png` });
await page.context().close();

// 5. The public form says why and keeps what was typed.
const url = BASE + tinker(`$o = App\\Models\\User::where('email','like','owner@%')->first(); $l = App\\Models\\Location::withoutGlobalScopes()->where('kind','SEDE')->first(); app(App\\Support\\ActiveScope::class)->setOrganisation($l->organisation_id); echo route('socio.application', ['token' => (new App\\Actions\\Members\\IssueApplicationInvite)->handle($o, $l->id, null, 'PRUEBA-348')->invite_token], false);`);
const phone = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
phone.on('pageerror', (e) => errors.push(e.message));
await phone.goto(url, { waitUntil: 'networkidle' });
await phone.fill('#first_name', 'Marta'); await phone.fill('#last_name', 'Sanz'); await phone.fill('#email', 'marta.348@example.es');
await phone.fill('#date_of_birth', '1990-05-14'); await phone.fill('#document_number', 'PRUEBA-34800');
await phone.check('input[name="consent_data"]'); await phone.check('input[name="consent_statutes"]');
const sig = phone.locator('[data-signature-canvas]').first(); await sig.scrollIntoViewIfNeeded(); const sb = await sig.boundingBox();
await phone.mouse.move(sb.x + 20, sb.y + 30); await phone.mouse.down(); await phone.mouse.move(sb.x + 140, sb.y + 70, { steps: 8 }); await phone.mouse.up();
await phone.click('[data-signature-save]').catch(() => {});
await phone.waitForTimeout(3500);
await phone.evaluate(() => document.querySelector('#photo')?.removeAttribute('required')); // past the browser's own check: the SERVER's message is what this proves
await phone.click('form[enctype] button[type="submit"]'); await settle(phone);
const body = (await phone.locator('body').innerText()).replace(/\s+/g, ' ');
check('the public form without a photo: «Añade una foto tuya…», and what was typed is kept', /Añade una foto tuya/.test(body) && (await phone.inputValue('#first_name')) === 'Marta' && (await phone.inputValue('#document_number')) === 'PRUEBA-34800');
await phone.screenshot({ path: `${OUT}/6-public-no-photo-390.png`, fullPage: true });

// 6. Every club: enrol a member at Central and look at the memberships.
const fresh = sql("select id from members m where not exists (select 1 from memberships s where s.member_id = m.id) and m.deleted_at is null limit 1");
tinker(`$m = App\\Models\\Member::withoutGlobalScopes()->find('${fresh}'); app(App\\Support\\ActiveScope::class)->setOrganisation($m->organisation_id); $l = App\\Models\\Location::withoutGlobalScopes()->where('name','Central Branch')->first(); $t = App\\Models\\MembershipTier::withoutGlobalScopes()->where('organisation_id', $m->organisation_id)->first(); (new App\\Actions\\Memberships\\EnrolMembership)->handle($m, $l, $t);`);
const rows = sql(`select l.name || ':' || (m.covered_by_id is not null) || ':' || m.fee_cents from memberships m join locations l on l.id = m.location_id where m.member_id = '${fresh}' and m.deleted_at is null order by l.name`);
check('enrolled at Central: memberships at Central (the fee) and North (covered, no fee), none at the store', /^Central Branch:0:\d+\nNorth Branch:1:0$/.test(rows), rows.replace(/\n/g, ' | '));

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
