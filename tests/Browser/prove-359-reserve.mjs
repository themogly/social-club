// Prompt 359 — sealed top-up bags are a reserve inside the batch. Freshly seeded demo DB (DBFILE) plus two strains at
// Central Branch: «Aaa reserva» (jar 20 g, 30 g sealed, 10 g sold) and «Aab bote vacío» (empty jar, 30 g sealed). 820×1180.
//   1. staff at the dispensary: «Reserva: 30.00 g» on the strain; «Con reserva» lists only the two; the empty jar says
//      «Bote vacío — 30.00 g en reserva · Rellenar» and opens with Rellenar; «Toda la reserva» fills the jar;
//   2. the manager closes the till: the reweigh is unchanged (no bag questions); the «Aaa» jar counted 10 g OVER (a bag
//      opened without Rellenar) → «Rellenado sin registrar: 10.00 g», no stock created.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/359';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { env: { ...process.env, DB_DATABASE: DB } }).toString().trim().split('\n').pop();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(500); };

const org = sql('select id from organisations limit 1');
const central = sql("select id from locations where name = 'Central Branch'");
tinker(`app(App\\Support\\ActiveScope::class)->setOrganisation('${org}');
  foreach (['Aaa reserva' => [2000, 3000, 6000], 'Aab bote vacío' => [0, 3000, 3000]] as $name => [$jar, $reserve, $initial]) {
      $g = App\\Models\\Genetic::factory()->create(['organisation_id' => '${org}', 'name' => $name, 'product_type' => App\\Enums\\ProductType::FLOWER]);
      App\\Models\\Batch::factory()->create(['organisation_id' => '${org}', 'genetic_id' => $g->id, 'location_id' => '${central}', 'initial_cg' => $initial,
          'remaining_cg' => $jar, 'reserve_cg' => $reserve, 'status' => App\\Enums\\BatchStatus::OPEN, 'price_per_gram_cents' => 1000, 'expires_on' => now()->addYear()]);
  }
  App\\Support\\Settings::set('dispensary_sort', 'alpha', App\\Enums\\SettingType::STRING, '${central}');`);
const batchOf = (name) => sql(`select b.id from batches b join genetics g on g.id = b.genetic_id where g.name = '${name}'`);

// --- 1. The counter ----------------------------------------------------------------------------------------------------------
const page = await (await browser.newContext({ viewport: { width: 820, height: 1180 } })).newPage();
page.on('pageerror', (e) => errors.push(e.message));
await signInToCounter(page, '/counter/pos', { account: 'staff', sede: 'Central Branch' });
await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
if (await page.locator('input[wire\\:model="floatInput"]').count()) {
    await page.fill('input[wire\\:model="floatInput"]', '50'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
}
await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle(page);
await page.click('[data-member-lookup-result]'); await settle(page);

const aaa = page.locator('[data-catalogue-item="genetics"][data-search="Aaa reserva"]');
const aab = page.locator('[data-catalogue-item="genetics"][data-search="Aab bote vacío"]');
check('a strain with sealed top-ups shows «Reserva: 30.00 g»', /Reserva: 30\.00 g/.test(await aaa.innerText()), (await aaa.innerText()).replace(/\s+/g, ' '));
await aaa.screenshot({ path: `${OUT}/1-strain-reserve-820.png` });

await page.click('[data-reserve-filter]'); await page.waitForTimeout(300);
const shown = await page.locator('[data-catalogue-item="genetics"]:visible').evaluateAll((els) => els.map((e) => e.dataset.search));
check('«Con reserva» lists only the strains with spare top-ups', JSON.stringify(shown) === JSON.stringify(['Aaa reserva', 'Aab bote vacío']), JSON.stringify(shown));
await page.screenshot({ path: `${OUT}/2-con-reserva-820.png` });

const aabText = (await aab.innerText()).replace(/\s+/g, ' ');
check('an empty jar with a reserve says «Bote vacío — 30.00 g en reserva · Rellenar», and can be tapped', /Bote vacío — 30\.00 g en reserva · Rellenar/.test(aabText) && !(await aab.isDisabled()), aabText);
await aab.screenshot({ path: `${OUT}/3-empty-jar-820.png` });
await aab.click(); await settle(page);
check('its pad offers «Rellenar» and «Toda la reserva»', await page.locator('[data-top-up]').isVisible() && await page.locator('[data-top-up-all]').isVisible());
await page.locator('[data-reserve-panel]').scrollIntoViewIfNeeded();
await page.screenshot({ path: `${OUT}/4-rellenar-panel-820.png` });
await page.click('[data-top-up-all]'); await settle(page);
check('«Toda la reserva» moves the bags into the jar', sql(`select remaining_cg || '|' || reserve_cg from batches where id = '${batchOf('Aab bote vacío')}'`) === '3000|0');
await page.context().close();

// --- 2. The manager closes: the reweigh, unchanged; a forgotten Rellenar absorbed -------------------------------------------
const mgr = await (await browser.newContext({ viewport: { width: 820, height: 1180 } })).newPage();
mgr.on('pageerror', (e) => errors.push(e.message));
await signInToCounter(mgr, '/counter/till', { account: 'manager', sede: 'Central Branch' });
await mgr.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
await mgr.locator('button[wire\\:click="startClose"]').click(); await settle(mgr);
const form = mgr.locator('form[wire\\:submit="submitReweigh"]');
const formText = (await form.innerText()).toLowerCase();
check('the reweigh asks nothing about bags', await form.isVisible() && !/reserva:|en reserva|bolsa|sellad/.test(formText)); // (a strain here is NAMED «Aaa reserva»)
await mgr.screenshot({ path: `${OUT}/5-reweigh-unchanged-820.png` });
const aaaId = batchOf('Aaa reserva');
for (const row of await mgr.locator('[data-reweigh-batch]').all()) {
    const id = await row.getAttribute('data-reweigh-batch');
    if (id === aaaId) {
        await row.locator('input[wire\\:model^="reweighCounts"]').fill('30'); // 20 g expected + a 10 g bag opened, no Rellenar
    } else {
        await row.locator('[data-reweigh-not-counted-toggle]').click(); await settle(mgr);
        await row.locator('[data-reweigh-reason]').fill('Prueba');
    }
}
await form.locator('button[type="submit"]').click(); await settle(mgr);
const note = await mgr.locator('[data-unrecorded-topup]').innerText().catch(() => '');
check('the manager sees «Rellenado sin registrar: 10.00 g» and no stock was created', /10\.00 g/.test(note)
    && sql(`select remaining_cg || '|' || reserve_cg from batches where id = '${aaaId}'`) === '3000|2000'
    && sql(`select count(*) from stock_movements where stockable_id = '${aaaId}' and type = 'ADJUSTMENT'`) === '0', note);
await mgr.locator('[data-unrecorded-topup]').scrollIntoViewIfNeeded().catch(() => {});
await mgr.screenshot({ path: `${OUT}/6-unrecorded-topup-820.png` });

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
