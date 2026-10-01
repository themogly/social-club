// Prompt 350 — the discounted dispensary total rounds to the euro (defaults: nearest, Local discount only). Freshly
// seeded demo DB (DBFILE), owner, 1180×820: a member with a 10 % Local discount, a strain whose total isn't whole — the
// basket, the button and «Justo» show the whole-euro total with a «Redondeo» line; the receipt shows the rounding; with
// «Sin redondeo» the exact cents come back.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/350';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { env: { ...process.env, DB_DATABASE: DB } }).toString().trim().split('\n').pop();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(600); };
const cents = (s) => Math.round(Number((s.match(/(-?\d+[.,]\d{2})\s*€/) ?? [])[1]?.replace(',', '.') ?? NaN) * 100);

const org = sql('select id from organisations limit 1');
const central = sql("select id from locations where name = 'Central Branch'");
const member = sql("select id from members where member_no = 'M-00001'");
const owner = sql("select id from users where email like 'owner@%'");
tinker(`app(App\\Support\\ActiveScope::class)->setOrganisation('${org}'); app(App\\Support\\ActiveScope::class)->setLocation('${central}');
  $d = App\\Models\\Discount::create(['organisation_id' => '${org}', 'name' => 'Local 10 %', 'kind' => App\\Enums\\DiscountKind::LOCAL, 'mode' => App\\Enums\\DiscountMode::PERCENT, 'value_bp' => 1000, 'applies_to' => App\\Enums\\DiscountAppliesTo::GENETIC, 'active' => true]);
  $d->locations()->sync(['${central}']);
  (new App\\Actions\\Members\\AssignMemberDiscount)->handle(App\\Models\\Member::withoutGlobalScopes()->find('${member}'), App\\Models\\User::find('${owner}'), ['discount_id' => $d->id, 'reason' => 'Prueba']);
  App\\Support\\Settings::set('after_recording', 'stay', App\\Enums\\SettingType::STRING, '${central}');`);

async function basket(page, grams = '2') {
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle(page);
    await page.click('[data-member-lookup-result]'); await settle(page);
    await page.locator('[data-catalogue-item="genetics"]:is([data-type="FLOWER"],[data-type="HASH"]):not([disabled]):visible').first().click(); await settle(page);
    for (const key of grams.split('')) await page.locator('[data-weight-pad] button', { hasText: new RegExp(`^${key}$`) }).click();
    await page.click('[data-add-line]'); await settle(page);
}

for (const [w, h] of [[1180, 820], [820, 1180]]) {
    const page = await (await browser.newContext({ viewport: { width: w, height: h } })).newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    await signInToCounter(page, '/counter/till', { account: 'owner', sede: 'Central Branch' });
    if (await page.locator('input[wire\\:model="floatInput"]').count()) { await page.fill('input[wire\\:model="floatInput"]', '50'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page); }
    await basket(page);

    const total = cents(await page.locator('[data-visit-total]').innerText());
    const rounding = await page.locator('[data-basket-rounding]').isVisible() ? cents(await page.locator('[data-basket-rounding]').innerText()) : null;
    const button = cents(await page.locator('[data-commit-action]').innerText());
    await page.getByRole('button', { name: /^(Justo|Exact)$/ }).click(); await settle(page);
    const justo = cents((await page.inputValue('#pos-cash-tendered')) + ' €');
    check(`${w}: the basket, the button and «Justo» all show the whole-euro total, with its «Redondeo» line`, total % 100 === 0 && rounding !== null && rounding !== 0 && button === total && justo === total,
        JSON.stringify({ total, rounding, button, justo }));
    await page.screenshot({ path: `${OUT}/1-basket-rounded-${w}.png` });

    if (w === 1180) {
        const sig = page.locator('[data-signature-canvas]');
        if (await sig.count()) { const b = await sig.boundingBox(); await page.mouse.move(b.x + 20, b.y + 30); await page.mouse.down(); await page.mouse.move(b.x + 120, b.y + 80, { steps: 8 }); await page.mouse.up(); await page.click('[data-signature-save]'); await settle(page); }
        await page.click('[data-commit-action]'); await settle(page);
        const d = sql("select id || '|' || total_cents || '|' || rounding_cents from dispensations order by created_at desc limit 1").split('|');
        check('committed at the rounded total, the rounding stored', Number(d[1]) === total && Number(d[2]) === rounding, d.join(' '));
        const receipt = await page.context().newPage();
        await receipt.goto(`${BASE}/counter/pos/receipt/${d[0]}`, { waitUntil: 'networkidle' });
        check('the receipt shows «Redondeo (incluido)»', await receipt.locator('[data-receipt-rounding]').isVisible());
        await receipt.screenshot({ path: `${OUT}/2-receipt-${w}.png` });
        await receipt.close();

        // «Sin redondeo»: the exact cents return.
        tinker(`app(App\\Support\\ActiveScope::class)->setOrganisation('${org}'); App\\Support\\Settings::set('discount_rounding', 'none', App\\Enums\\SettingType::STRING);`);
        await basket(page);
        const exact = cents(await page.locator('[data-visit-total]').innerText());
        check('«Sin redondeo»: the exact cents, no «Redondeo» line', exact % 100 !== 0 && ! await page.locator('[data-basket-rounding]').isVisible(), String(exact));
        await page.screenshot({ path: `${OUT}/3-no-rounding-${w}.png` });
        tinker(`app(App\\Support\\ActiveScope::class)->setOrganisation('${org}'); App\\Support\\Settings::set('discount_rounding', 'nearest', App\\Enums\\SettingType::STRING);`);
    }
    await page.context().close();
}

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
