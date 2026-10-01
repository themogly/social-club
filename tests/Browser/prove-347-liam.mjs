// Prompt 347 — Liam's feedback. Freshly seeded demo DB (DBFILE).
//   Panel (1440×900, owner): a new strain must have its type chosen; «vape» with Flor shows the hint; a bar product named
//     «THC vape 1 g» shows the warning and the accessory confirmation; Genéticas with one sede chosen starts filtered on it.
//   Counter (1180×820): a sale lands back on the hub with the «Última: … · Opciones» line (Anular in it); the staff test
//     user linked to a member, the club's staff discount set: serving that member as that user shows the discount and the
//     «Te estás atendiendo a ti mismo» notice.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/347';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(600); };

// --- the panel ----------------------------------------------------------------------------------------------------------
const panel = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
panel.on('pageerror', (e) => errors.push(e.message));
await signIn(panel, { account: 'owner' });
await panel.goto(`${BASE}/genetics/create`, { waitUntil: 'networkidle' });
const anyChecked = await panel.locator('[data-product-type-choices] input[type=radio]:checked').count();
check('a new strain starts with no type chosen (six choices, each with its line)', anyChecked === 0 && await panel.locator('[data-product-type-choices] input[type=radio]').count() === 6);
const nameField = () => panel.getByRole('textbox', { name: /^(Nombre|Name)/ }).first();
await nameField().fill('Lemon vape 1 ml');
await nameField().blur(); await settle(panel);
await panel.getByRole('button', { name: /^(Crear|Create)$/ }).first().click(); await settle(panel);
check('saving without a type is refused', /\/genetics\/create/.test(panel.url()) && await panel.locator('[data-product-type-choices]').locator('..').locator('..').getByText(/obligatorio|required/i).count() > 0);
await panel.locator('[data-product-type-choices] input[value="FLOWER"]').check(); await settle(panel);
check('«vape» in the name with Flor chosen: the amber hint', await panel.locator('[data-vape-hint]').isVisible());
await panel.screenshot({ path: `${OUT}/1-strain-type-1440.png`, fullPage: true });

await panel.goto(`${BASE}/articles/create`, { waitUntil: 'networkidle' });
await nameField().fill('THC vape 1 g'); await nameField().blur(); await settle(panel);
check('a bar product named «THC vape 1 g»: the warning, with the accessory confirmation', await panel.locator('[data-vape-bar-warning]').isVisible() && await panel.getByText(/Es un accesorio, no contiene cannabis|accessory, it contains no cannabis/).isVisible());
await panel.screenshot({ path: `${OUT}/2-bar-vape-warning-1440.png`, fullPage: true });

// One sede chosen in the top bar → Genéticas starts filtered on it.
const central = sql("select id from locations where name = 'Central Branch'");
await panel.goto(`${BASE}/`, { waitUntil: 'networkidle' });
await panel.locator('.fi-topbar select').first().selectOption(central).catch(() => {}); await settle(panel);
await panel.goto(`${BASE}/genetics`, { waitUntil: 'networkidle' });
const chips = (await panel.locator('.fi-ta-filter-indicators').innerText().catch(() => '')).replace(/\s+/g, ' ');
check('Genéticas with Central Branch chosen: the «Con existencias en…» chip, removable', /Central Branch/.test(chips) && await panel.locator('.fi-ta-filter-indicators .fi-badge-delete-btn').count() > 0, chips);
check('…and the «Sedes» column', await panel.getByRole('columnheader', { name: /^(Sedes|Locations)/ }).count() > 0);
await panel.screenshot({ path: `${OUT}/3-genetics-by-sede-1440.png` });
await panel.context().close();

// --- the counter: back to the hub after a sale -------------------------------------------------------------------------------
async function counter(account) {
    const page = await (await browser.newContext({ viewport: { width: 1180, height: 820 } })).newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    await signInToCounter(page, '/counter/till', { account, sede: 'Central Branch' });
    if (await page.locator('input[wire\\:model="floatInput"]').count()) {
        await page.fill('input[wire\\:model="floatInput"]', '50'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
    }
    return page;
}
async function serve(page, memberNo) {
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    await page.fill('#member-lookup', memberNo); await page.press('#member-lookup', 'Enter'); await settle(page);
    await page.click('[data-member-lookup-result]'); await settle(page);
}
const page = await counter('owner');
await serve(page, 'M-00001');
await page.locator('[data-catalogue-item="genetics"]:is([data-type="FLOWER"],[data-type="HASH"]):not([disabled]):visible').first().click(); await settle(page);
await page.locator('[data-weight-pad] button', { hasText: /^1$/ }).click();
await page.click('[data-add-line]'); await settle(page);
await page.getByRole('button', { name: /^(Justo|Exact)$/ }).click().catch(() => {}); await settle(page);
const sig = page.locator('[data-signature-canvas]');
if (await sig.count()) {
    const box = await sig.boundingBox();
    await page.mouse.move(box.x + 20, box.y + 30); await page.mouse.down(); await page.mouse.move(box.x + 120, box.y + 80, { steps: 8 }); await page.mouse.up();
    await page.click('[data-signature-save]'); await settle(page);
}
const before = Number(sql('select count(*) from dispensations'));
await page.click('[data-commit-action]');
await page.waitForTimeout(400);
await page.screenshot({ path: `${OUT}/4-recorded-1180.png` });
await page.waitForURL((u) => new URL(u).pathname === '/counter', { timeout: 6000 }).catch(() => {});
await settle(page);
check('a sale is recorded and the counter is back on the hub within ~2 s', Number(sql('select count(*) from dispensations')) === before + 1 && new URL(page.url()).pathname === '/counter', page.url());
check('…with the «Última: … · Opciones» line', await page.locator('[data-hub-last-sale] [data-last-sale-summary]').isVisible());
await page.click('[data-hub-last-sale] [data-last-sale-options]'); await page.waitForTimeout(300);
check('…whose Opciones hold Anular', await page.locator('[data-hub-last-sale] [data-last-sale-void]').isVisible());
await page.screenshot({ path: `${OUT}/5-hub-last-sale-1180.png` });
await page.keyboard.press('Escape');
await page.context().close();

// --- the staff discount and serving yourself ------------------------------------------------------------------------------------
const member = sql("select id from members where member_no = 'M-00001'");
sql(`update users set member_id = '${member}' where email = 'staff@club.test'`);
const staffDiscount = sql("select id from discounts where kind = 'STAFF' limit 1");
const org = sql('select id from organisations limit 1');
execFileSync('php', ['artisan', 'tinker', '--execute', `app(App\\Support\\ActiveScope::class)->setOrganisation('${org}'); App\\Support\\Settings::set('staff_discount_id', '${staffDiscount}', App\\Enums\\SettingType::STRING);`], { env: { ...process.env, DB_DATABASE: DB } });
const staff = await counter('staff');
await serve(staff, 'M-00001');
check('the staff user serving their own member record: «Te estás atendiendo a ti mismo»', await staff.locator('[data-self-serving]').isVisible());
await staff.screenshot({ path: `${OUT}/6a-staff-self-1180.png` });
await staff.locator('[data-catalogue-item="genetics"]:is([data-type="FLOWER"],[data-type="HASH"]):not([disabled]):visible').first().click(); await settle(staff);
await staff.locator('[data-weight-pad] button', { hasText: /^1$/ }).click();
await staff.click('[data-add-line]'); await settle(staff);
const basket = (await staff.locator('[data-cart-column]').innerText()).replace(/\s+/g, ' ');
check('…and the club\'s staff discount applies by itself (10 % off the line)', /× [0-9.,]+ €\/g · −[0-9.,]+ €/.test(basket), basket.slice(0, 240));
await staff.screenshot({ path: `${OUT}/6-staff-self-1180.png` });

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
