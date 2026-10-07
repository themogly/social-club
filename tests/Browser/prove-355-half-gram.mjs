// Prompt 355 — the charged weight rounded to the half gram, with a staff switch. Freshly seeded demo DB (DBFILE) plus a
// test strains at €10/g, one with no eighth and one with a €32 eighth, at Central Branch; staff; at 820×1180 and 1180×820:
//   switch ON:  1.10 g → «· se cobra 1.00 g», €10; 3.40 g → one eighth (1/8), €32; total €42;
//   switch OFF: the same basket → €11 + €34 = €45, «(cambiado)» beside the switch;
//   the commit button stays above the fold.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/355';
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
  foreach (['Aaa gramos' => null, 'Aab octavo' => 3200] as $name => $eighth) {
      $g = App\\Models\\Genetic::factory()->create(['organisation_id' => '${org}', 'name' => $name, 'product_type' => App\\Enums\\ProductType::FLOWER]);
      App\\Models\\Batch::factory()->create(['organisation_id' => '${org}', 'genetic_id' => $g->id, 'location_id' => '${central}', 'remaining_cg' => 10000,
          'status' => App\\Enums\\BatchStatus::OPEN, 'price_per_gram_cents' => 1000, 'price_per_eighth_cents' => $eighth, 'expires_on' => now()->addYear()]);
  }
  App\\Support\\Settings::set('dispensary_sort', 'alpha', App\\Enums\\SettingType::STRING, '${central}');`);

async function line(page, name, grams) {
    const card = page.locator(`[data-catalogue-item="genetics"][data-search="${name}"]`);
    await card.evaluate((el) => el.scrollIntoView({ block: 'center' }));
    await card.click(); await settle(page);
    for (const key of grams) {
        const pattern = key === '.' ? /^\.$/ : new RegExp(`^${key}$`);
        await page.locator('[data-weight-pad] button', { hasText: pattern }).first().click();
    }
    await page.click('[data-add-line]'); await settle(page);
}

for (const [w, h] of [[820, 1180], [1180, 820]]) {
    const page = await (await browser.newContext({ viewport: { width: w, height: h } })).newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    await signInToCounter(page, '/counter/pos', { account: 'staff', sede: 'Central Branch' });
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    if (await page.locator('input[wire\\:model="floatInput"]').count()) {
        await page.fill('input[wire\\:model="floatInput"]', '50'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
        await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    }
    await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle(page);
    await page.click('[data-member-lookup-result]'); await settle(page);
    if (await page.locator('[data-charge-rounding="off"]').count()) { await page.click('[data-charge-rounding]'); await settle(page); }

    await line(page, 'Aaa gramos', ['1', '.', '1']);
    await line(page, 'Aab octavo', ['3', '.', '4']);
    const text = (await page.locator('[data-cart-column]').innerText()).replace(/\s+/g, ' ');
    const totalOn = cents(await page.locator('[data-visit-total]').innerText());
    const button = await page.locator('[data-commit-action]').boundingBox();
    check(`${w}: ON — «1.10 g · se cobra 1.00 g», the 3.40 g line takes the eighth, total €42`,
        /1\.10 g · se cobra 1\.00 g/.test(text) && /1\/8/.test(text) && totalOn === 4200, `${totalOn} ${text.slice(0, 160)}`);
    check(`${w}: the commit button is above the fold`, button !== null && button.y + button.height <= h, JSON.stringify(button));
    await page.locator('[data-charge-rounding]').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/1-on-${w}.png` });

    await page.click('[data-charge-rounding]'); await settle(page);
    const totalOff = cents(await page.locator('[data-visit-total]').innerText());
    check(`${w}: OFF — the same basket is €11 + €34 = €45, and the switch says «(cambiado)»`,
        totalOff === 4500 && await page.locator('[data-charge-rounding-changed]').isVisible(), String(totalOff));
    await page.screenshot({ path: `${OUT}/2-off-${w}.png` });
    await page.click('[data-charge-rounding]'); await settle(page); // back on for the next run
    await page.locator('button:has-text("Vaciar")').first().click().catch(() => {}); await settle(page);
    await page.context().close();
}

check('each flip is audited', Number(sql("select count(*) from audit_logs where action = 'counter.rounding.toggled'")) >= 4);
check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
