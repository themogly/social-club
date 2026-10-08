// Prompt 349 — three cash pots; the float and the cash-up are the dispensary's. Freshly seeded demo DB (DBFILE), owner,
// 1180×820, Central Branch with *Botes de efectivo separados* on:
//   1. open a till with a €100 float;  2. a visit (flower + a drink, cash) and a fee in cash;
//   3. the headline expected is the float plus the flower cash only, the bar and fees on their own lines;
//   4. close counting only the dispensary: no difference; bar and fees carry forward;
//   5. the next till shows the carried balances and «sin contar desde»;  6. count the bar: its difference.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/349';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { env: { ...process.env, DB_DATABASE: DB } }).toString().trim().split('\n').pop();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(600); };
const euros = (s) => Number((s.match(/(-?[\d.,]+)\s*€/) ?? [])[1]?.replace(/\.(?=\d{3})/g, '').replace(',', '.') ?? NaN);

const central = sql("select id from locations where name = 'Central Branch'");
const org = sql('select id from organisations limit 1');
tinker(`app(App\\Support\\ActiveScope::class)->setOrganisation('${org}'); App\\Support\\Settings::set('cash_box_bar', 'own', App\\Enums\\SettingType::STRING, '${central}'); App\\Support\\Settings::set('cash_box_fees', 'own', App\\Enums\\SettingType::STRING, '${central}'); App\\Support\\Settings::set('after_recording', 'stay', App\\Enums\\SettingType::STRING, '${central}');`);
// Close whatever the demo left open at Central, so the till opens fresh with our float.
tinker(`App\\Models\\TillSession::withoutGlobalScopes()->where('location_id','${central}')->where('status','OPEN')->update(['status' => 'CLOSED', 'closed_at' => now()]);`);

const page = await (await browser.newContext({ viewport: { width: 1180, height: 820 } })).newPage();
page.on('pageerror', (e) => errors.push(e.message));
await signInToCounter(page, '/counter/till', { account: 'owner', sede: 'Central Branch' });

// 1. Open with €100.
await page.fill('input[wire\\:model="floatInput"]', '100');
check('the open form says the float is the dispensary pot\'s', await page.locator('[data-till-carried]').isVisible());
await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
const session = sql(`select id from till_sessions where location_id = '${central}' and status = 'OPEN' order by opened_at desc limit 1`);
check('a till is open with a €100 float and keeps pots', sql(`select float_cents || '|' || separate_pots from till_sessions where id = '${session}'`) === '10000|1');

// 2. A visit: flower + a drink, paid in cash. Then a fee in cash.
await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle(page);
await page.click('[data-member-lookup-result]'); await settle(page);
await page.locator('[data-catalogue-item="genetics"]:is([data-type="FLOWER"],[data-type="HASH"]):not([disabled]):visible').first().click(); await settle(page);
await page.locator('[data-weight-pad] button', { hasText: /^1$/ }).click();
await page.click('[data-add-line]'); await settle(page);
await page.locator('[data-source-option="bar"]').click(); await page.waitForTimeout(300);
await page.locator('[data-catalogue-item]:not([data-catalogue-item="genetics"]):not([disabled]):visible').first().click(); await settle(page);
await page.getByRole('button', { name: /^(Justo|Exact)$/ }).click().catch(() => {}); await settle(page);
const sig = page.locator('[data-signature-canvas]');
if (await sig.count()) { const b = await sig.boundingBox(); await page.mouse.move(b.x + 20, b.y + 30); await page.mouse.down(); await page.mouse.move(b.x + 120, b.y + 80, { steps: 8 }); await page.mouse.up(); await page.click('[data-signature-save]'); await settle(page); }
await page.click('[data-commit-action]'); await settle(page);
const flowerCash = Number(sql(`select coalesce(sum(cash_cents),0) from dispensations where till_session_id = '${session}' and status = 'COMPLETED'`));
const barCash = Number(sql(`select coalesce(sum(cash_cents),0) from orders where till_session_id = '${session}' and status = 'COMPLETED'`));
const said = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
check('the visit is recorded in two parts, and the bar\'s share is named', flowerCash > 0 && (barCash === 0 || /bote de la barra/.test(said)), `flower ${flowerCash} · bar ${barCash}`);
await page.screenshot({ path: `${OUT}/1-visit-1180.png` });
const membership = sql("select ms.id from memberships ms join members m on m.id = ms.member_id where m.member_no = 'M-00001' and ms.covered_by_id is null limit 1");
tinker(`app(App\\Support\\ActiveScope::class)->setOrganisation('${org}'); (new App\\Actions\\Memberships\\RecordFeePayment)->handle(App\\Models\\Membership::withoutGlobalScopes()->find('${membership}'), 1000, App\\Enums\\FeePaymentMethod::CASH, ['till_session_id' => '${session}']);`);

// 3. The headline is the dispensary pot.
await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
const headline = euros(await page.locator('[data-till-expected]').innerText()) * 100;
const barLine = euros(await page.locator('[data-till-pot="BAR"]').innerText()) * 100;
const feesLine = euros(await page.locator('[data-till-pot="FEES"]').innerText()) * 100;
check('the headline «Efectivo esperado en el cajón» = float + flower cash only', Math.round(headline) === 10000 + flowerCash, `${headline} vs ${10000 + flowerCash}`);
check('…the bar and the fees on their own lines, not added in', Math.round(barLine) === barCash && Math.round(feesLine) === 1000, `bar ${barLine} · fees ${feesLine}`);
await page.screenshot({ path: `${OUT}/2-headline-1180.png` });

// 4. Close counting only the dispensary.
await page.locator('button[wire\\:click="startClose"]').click(); await settle(page);
// The flower re-weigh comes first (flower was sold): weigh it as not counted, with a reason, to reach the cash count.
if (await page.locator('form[wire\\:submit="submitReweigh"]').count()) {
    for (const toggle of await page.locator('[data-reweigh-not-counted-toggle]').all()) { await toggle.click(); await settle(page); }
    for (const reason of await page.locator('[data-reweigh-reason]').all()) await reason.fill('Prueba');
    await page.locator('form[wire\\:submit="submitReweigh"] button[type="submit"]').click(); await settle(page);
}
check('the close asks for each pot: Contar ahora / No se cuenta hoy, the bar and fees starting on «no»', await page.locator('[data-pot-count="BAR"]').isVisible() && await page.locator('[data-pot-count="FEES"]').isVisible());
await page.screenshot({ path: `${OUT}/3-close-pots-1180.png` });
await page.fill('#count', String((10000 + flowerCash) / 100)); await page.click('form[wire\\:submit="submitCount"] button[type="submit"]'); await settle(page);
check('closed counting the dispensary only: no difference; the bar and the fees not counted, carried', sql(`select variance_cents || '|' || coalesce(bar_counted_cents, 'null') || '|' || bar_expected_cents || '|' || coalesce(fees_counted_cents,'null') from till_sessions where id = '${session}'`) === `0|null|${barCash}|null`);
await page.screenshot({ path: `${OUT}/4-closed-1180.png` });

// 5. The next till: carried balances, «sin contar desde».
await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
const carriedText = await page.locator('[data-till-carried]').innerText();
check('the next opening shows what the bar and fees carry', /1[.,]00|10[.,]00/.test(carriedText), carriedText);
await page.fill('input[wire\\:model="floatInput"]', '100'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
const next = sql(`select id from till_sessions where location_id = '${central}' and status = 'OPEN' order by opened_at desc limit 1`);
check('…opening with them', sql(`select bar_opening_cents || '|' || fees_opening_cents from till_sessions where id = '${next}'`) === `${barCash}|1000`);
await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' }); // opening lands on the counter home
check('…and «sin contar desde» beside the bar', /sin contar desde|not counted since/.test(await page.locator('[data-till-pot="BAR"]').innerText()));
await page.screenshot({ path: `${OUT}/5-next-day-1180.png` });

// 6. Count the bar: its difference against everything accumulated.
await page.locator('button[wire\\:click="startClose"]').click(); await settle(page);
await page.locator('[data-pot-count="BAR"] [data-pot-count-now]').click(); await settle(page);
await page.fill('#pot-count-BAR', String((barCash - 50) / 100));
await page.fill('#count', '100');
await page.click('form[wire\\:submit="submitCount"] button[type="submit"]'); await settle(page);
if (await page.locator('#note').count()) { await page.fill('#note', 'Prueba'); await page.click('form[wire\\:submit="submitCount"] button[type="submit"]'); await settle(page); }
check('counting the bar later: the difference is against everything carried (−0,50)', sql(`select bar_variance_cents from till_sessions where id = '${next}'`) === '-50'
    && /Barra|Bar/.test(await page.locator('[data-arqueo-pots]').innerText()));
await page.screenshot({ path: `${OUT}/6-bar-counted-1180.png` });

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
