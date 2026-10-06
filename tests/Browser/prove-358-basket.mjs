// Prompt 358 — "it should take them to the search bar for strain". Freshly seeded demo DB (DBFILE) plus 30 extra strains
// with stock at Central Branch (a long list, as at the club), staff, at 820×1180 and 1180×820, in a TOUCH context and a
// MOUSE context:
//   1. scroll to the 26th strain, tap it, enter 1 g, tap Añadir → the strain search is at the top of the pane, empty;
//      focused with a mouse, NOT focused on touch (no on-screen keyboard);
//   2. the same strain again → ONE line of 2.00 g (+1.00 g);
//   3. tap the line → the pad with its amount and «Actualizar»;
//   4. scroll to the 26th strain, tap it, Cancelar → the list is back where it was.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/358';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { env: { ...process.env, DB_DATABASE: DB } }).toString().trim().split('\n').pop();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(600); };

const org = sql('select id from organisations limit 1');
const central = sql("select id from locations where name = 'Central Branch'");
console.log('strains added:', tinker(`app(App\\Support\\ActiveScope::class)->setOrganisation('${org}');
  $n = 0;
  for ($i = 1; $i <= 30; $i++) {
      $g = App\\Models\\Genetic::factory()->create(['organisation_id' => '${org}', 'name' => sprintf('Prueba %02d', $i), 'product_type' => App\\Enums\\ProductType::FLOWER]);
      App\\Models\\Batch::factory()->create(['organisation_id' => '${org}', 'genetic_id' => $g->id, 'location_id' => '${central}', 'remaining_cg' => 5000,
          'status' => App\\Enums\\BatchStatus::OPEN, 'price_per_gram_cents' => 900, 'expires_on' => now()->addYear()]);
      $n++;
  }
  App\\Support\\Settings::set('after_recording', 'stay', App\\Enums\\SettingType::STRING, '${central}');
  App\\Support\\Settings::set('dispensary_sort', 'alpha', App\\Enums\\SettingType::STRING, '${central}');
  echo $n;`));

const pane = (p) => p.locator('[data-selection-pane]');
const scrollTop = (p) => pane(p).evaluate((el) => Math.round(el.scrollTop));
async function toTwentySixth(page) {
    const card = page.locator('[data-catalogue-item="genetics"]:not([disabled]):visible').nth(25);
    await card.evaluate((el) => el.scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(300);
    return card;
}

let first = true;
for (const [w, h] of [[820, 1180], [1180, 820]]) {
    for (const touch of [true, false]) {
        const label = `${w}×${h} ${touch ? 'touch' : 'mouse'}`;
        const context = await browser.newContext({ viewport: { width: w, height: h }, hasTouch: touch, isMobile: touch });
        const page = await context.newPage();
        page.on('pageerror', (e) => errors.push(e.message));
        await signInToCounter(page, '/counter/pos', { account: 'staff', sede: 'Central Branch' });
        await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
        if (await page.locator('input[wire\\:model="floatInput"]').count()) {
            await page.fill('input[wire\\:model="floatInput"]', '50'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
            await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
        }
        if (await page.locator('[data-basket-clear], button:has-text("Vaciar")').count()) await page.locator('button:has-text("Vaciar")').first().click().catch(() => {});
        await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle(page);
        await page.click('[data-member-lookup-result]'); await settle(page);
        const media = await page.evaluate(() => ({ coarse: matchMedia('(pointer: coarse)').matches, anyFine: matchMedia('(any-pointer: fine)').matches }));

        // 1. After Añadir.
        const card = await toTwentySixth(page);
        const before = await scrollTop(page);
        const name = await card.getAttribute('data-search');
        await (touch ? card.tap() : card.click()); await settle(page);
        await page.locator('[data-weight-pad] button', { hasText: /^1$/ }).click();
        await page.click('[data-add-line]'); await settle(page);
        const search = page.locator('[data-genetic-search]');
        const box = await search.boundingBox();
        const paneBox = await pane(page).boundingBox();
        const state = {
            before, after: await scrollTop(page), value: await search.inputValue(),
            visible: box !== null && box.y >= paneBox.y - 1 && box.y + box.height <= paneBox.y + paneBox.height,
            focused: await search.evaluate((el) => document.activeElement === el),
        };
        check(`${label}: after Añadir the strain search is at the top, empty, ${touch ? 'NOT focused (no keyboard)' : 'focused'}`,
            state.before > 600 && state.after === 0 && state.value === '' && state.visible && state.focused === !touch,
            JSON.stringify({ ...state, media }));
        if (w === 820) await page.screenshot({ path: `${OUT}/1-after-add-${touch ? 'touch' : 'mouse'}-820.png` });

        // 2. The same strain again: one line, 2.00 g.
        const again = page.locator(`[data-catalogue-item="genetics"][data-search="${name}"]`);
        await again.evaluate((el) => el.scrollIntoView({ block: 'center' }));
        await (touch ? again.tap() : again.click()); await settle(page);
        await page.locator('[data-weight-pad] button', { hasText: /^1$/ }).click();
        await page.click('[data-add-line]'); await settle(page);
        const lines = await page.locator('[data-edit-line]').count();
        const lineText = (await page.locator('[data-edit-line]').first().innerText()).replace(/\s+/g, ' ');
        check(`${label}: the same strain again is ONE line of 2.00 g, with +1.00 g`, lines === 1 && /2\.00 g/.test(lineText) && /\+1\.00 g/.test(lineText), lineText);
        if (w === 820 && touch) await page.locator('[data-edit-line]').first().screenshot({ path: `${OUT}/2-merged-line-820.png` });

        // 3. Tap the line: the pad with its amount and «Actualizar».
        await page.locator('[data-edit-line]').first().click(); await settle(page);
        const button = (await page.locator('[data-add-line]').innerText()).trim();
        const shown = (await page.locator('[data-weight-display]').innerText()).trim();
        check(`${label}: tapping the line opens the pad with 2 g and «Actualizar»`, button === 'Actualizar' && /^2\s*g$/.test(shown), `${button} · ${shown}`);
        if (w === 820 && touch) await page.screenshot({ path: `${OUT}/3-edit-pad-820.png` });
        await page.locator('[data-weight-entry] button', { hasText: 'Cancelar' }).click(); await settle(page);

        // 4. Cancel puts the list back.
        const card2 = await toTwentySixth(page);
        const at = await scrollTop(page);
        await (touch ? card2.tap() : card2.click()); await settle(page);
        const opened = await scrollTop(page);
        await page.locator('[data-weight-entry] button', { hasText: 'Cancelar' }).click(); await settle(page);
        const back = await scrollTop(page);
        check(`${label}: Cancelar puts the list back where it was`, Math.abs(back - at) <= 4 && opened < at, JSON.stringify({ at, opened, back }));

        check(`${label}: the member is still held`, (await page.locator('[data-counter-member], body').innerText()).includes('M-00001'));
        await page.locator('button:has-text("Vaciar")').first().click().catch(() => {}); await settle(page);
        await context.close();
        first = false;
    }
}

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
