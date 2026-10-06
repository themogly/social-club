// Prompt 356 — the price adjustment goes up as well as down; an optional reason is not shown. Freshly seeded demo DB
// (DBFILE), 820×1180:
//   1. a member of staff (given dispensation.price.override) opens «Ajustar precio»: the reason box is there;
//   2. a manager raises the total: «+… sobre el precio calculado», the batch-price link, NO reason box; committed at the
//      raised total with «Aprobado por responsable»;
//   3. a manager waives a fee in one tap (no reasons shown).
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/356';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { env: { ...process.env, DB_DATABASE: DB } }).toString().trim().split('\n').pop();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(500); };
const cents = (s) => Math.round(Number((s.match(/(\d+[.,]\d{2})/) ?? [])[1]?.replace(',', '.') ?? NaN) * 100);

const org = sql('select id from organisations limit 1');
const central = sql("select id from locations where name = 'Central Branch'");
tinker(`app(App\\Support\\ActiveScope::class)->setOrganisation('${org}');
  App\\Models\\User::query()->where('email', 'like', 'staff@%')->first()->givePermissionTo('dispensation.price.override');
  App\\Support\\Settings::set('after_recording', 'stay', App\\Enums\\SettingType::STRING, '${central}');
  App\\Models\\Membership::query()->withoutGlobalScopes()->whereHas('member', fn ($q) => $q->withoutGlobalScopes()->where('member_no', 'M-00003'))->where('location_id', '${central}')->update(['fee_cents' => 1500]);`);

async function basket(page, member = 'M-00001') {
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    if (await page.locator('input[wire\\:model="floatInput"]').count()) {
        await page.fill('input[wire\\:model="floatInput"]', '50'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
        await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    }
    await page.fill('#member-lookup', member); await page.press('#member-lookup', 'Enter'); await settle(page);
    await page.click('[data-member-lookup-result]'); await settle(page);
}
async function addStrain(page) {
    await page.locator('[data-catalogue-item="genetics"]:is([data-type="FLOWER"],[data-type="HASH"]):not([disabled]):visible').first().click(); await settle(page);
    await page.locator('[data-weight-pad] button', { hasText: /^2$/ }).click();
    await page.click('[data-add-line]'); await settle(page);
}

// 1. Staff: the reason box is there.
{
    const page = await (await browser.newContext({ viewport: { width: 820, height: 1180 } })).newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    await signInToCounter(page, '/counter/pos', { account: 'staff', sede: 'Central Branch' });
    await basket(page); await addStrain(page);
    await page.click('[data-price-override-toggle]'); await page.waitForTimeout(300);
    check('staff: «Ajustar precio» shows the required reason box', await page.locator('#price-override-reason').isVisible());
    await page.locator('[data-price-override]').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/1-staff-reason-box-820.png` });
    await page.context().close();
}

// 2. Manager: raise, the notice, the link, no reason box; committed at the raised total.
{
    const page = await (await browser.newContext({ viewport: { width: 820, height: 1180 } })).newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    await signInToCounter(page, '/counter/pos', { account: 'manager', sede: 'Central Branch' });
    await basket(page); await addStrain(page);
    const calculated = cents(await page.locator('[data-visit-total]').innerText());
    await page.click('[data-price-override-toggle]'); await page.waitForTimeout(300);
    const raised = calculated + 400;
    await page.fill('#price-override-amount', (raised / 100).toFixed(2)); await page.press('#price-override-amount', 'Enter'); await settle(page);
    const notice = await page.locator('[data-price-override-notice]').innerText().catch(() => '');
    check('manager: no reason box', ! await page.locator('#price-override-reason').count());
    check('manager: the notice says «+4.00 € …» above the calculated price', /^\+/.test(notice) && cents(notice) === 400, notice);
    check('manager: the batch-price link is offered', await page.locator('[data-batch-price-link]').isVisible());
    check('manager: the button charges the raised total', cents(await page.locator('[data-commit-action]').innerText()) === raised);
    await page.locator('[data-price-override]').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/2-manager-raised-820.png` });
    await page.getByRole('button', { name: /^(Justo|Exact)$/ }).click(); await settle(page);
    const sig = page.locator('[data-signature-canvas]');
    if (await sig.count()) { const b = await sig.boundingBox(); await page.mouse.move(b.x + 20, b.y + 30); await page.mouse.down(); await page.mouse.move(b.x + 120, b.y + 80, { steps: 8 }); await page.mouse.up(); await page.click('[data-signature-save]'); await settle(page); }
    await page.click('[data-commit-action]'); await settle(page);
    const row = sql('select total_cents || \'|\' || original_total_cents || \'|\' || price_override_reason from dispensations order by created_at desc limit 1').split('|');
    check('manager: committed at the raised total, the calculated one kept, «Aprobado por responsable»', Number(row[0]) === raised && Number(row[1]) === calculated && row[2] === 'Aprobado por responsable', row.join(' '));

    // 3. The fee waiver: one tap.
    await basket(page, 'M-00003');
    const toggle = page.locator('[data-fee-waive-toggle]').first();
    await toggle.click(); await settle(page);
    check('manager: the waiver shows no reasons, just «Condonar cuota»', ! await page.locator('[data-waive-reason]').count() && await page.locator('[data-fee-waive-submit]').isVisible());
    await page.locator('[data-fee-waive-form]').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/3-manager-waiver-one-tap-820.png` });
    await page.click('[data-fee-waive-submit]'); await settle(page);
    check('manager: the waiver is recorded with «Aprobado por responsable»', sql("select reason from membership_fee_payments where method = 'WAIVED' order by created_at desc limit 1") === 'Aprobado por responsable');
    await page.context().close();
}

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
