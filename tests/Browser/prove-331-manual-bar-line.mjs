// Prompt 331 — the Dispensario's Barra tab adds a manual bar line, with the Bar screen's modal. Throwaway database (DBFILE,
// read with sqlite3), the demo club, at 1180×820:
//   1. a member, 1 g of flower;
//   2. Barra → «Línea manual» → €1.50 «Mechero», reason «Sin código» → in the cart as «Mechero · 1,50 €» (the club’s money format), tagged manual;
//   3. commit: the order carries the manual line (no article_id); its ticket and the till's Z report show €1.50;
//   4. the Bar screen's own manual line opens the same modal, still headed «Importe manual».
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/331';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };

await signInToCounter(page, '/counter/till');
if (await page.locator('input[wire\\:model="floatInput"]').count()) {
    await page.fill('input[wire\\:model="floatInput"]', '50');
    await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle();
}
await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });

// 1. A member and 1 g.
await page.fill('#member-lookup', 'M-00001'); await page.press('#member-lookup', 'Enter'); await settle();
await page.click('[data-member-lookup-result]'); await settle();
const fee = await page.$('form[wire\\:submit="collectMemberFee"] button[type="submit"]');
if (fee) { await fee.click(); await settle(); }
await page.locator('[data-catalogue-item="genetics"]:not([disabled]):visible').first().click(); await settle();
await page.locator('[data-weight-pad] button', { hasText: /^1$/ }).click();
await page.click('[data-add-line]'); await settle();
check('1 g is in the basket', (await page.locator('[wire\\:click^="removeLine"]').count()) === 1);

// 2. Barra → Línea manual.
check('no manual-line button on the Dispensario tab', ! (await page.locator('[data-misc-open]').isVisible()));
await page.click('[data-source-option="bar"]'); await page.waitForTimeout(200);
const trigger = page.locator('[data-misc-open]');
check('the Barra tab shows «Línea manual»', await trigger.isVisible() && /Línea manual/.test(await trigger.innerText()));
await trigger.click(); await page.waitForTimeout(300);
const dialog = page.locator('[data-manual-line-modal] [role="dialog"]');
check('the shared modal opens, headed «Línea manual de barra», focus in the description',
    await dialog.isVisible() && (await dialog.getAttribute('aria-label')) === 'Línea manual de barra' && await page.evaluate(() => document.activeElement?.id) === 'misc-desc');
await page.fill('#misc-desc', 'Mechero');
await page.fill('#misc-amount', '1,50');
await page.fill('#misc-ref', 'Sin código');
await page.screenshot({ path: `${OUT}/1-modal-1180.png` });
await dialog.locator('button[wire\\:click="addMiscLine"]').click(); await settle();
const line = page.locator('[data-bar-line-manual]');
check('the modal closed and the line is in the cart as «Mechero · 1,50 €» (the club’s money format), tagged manual',
    ! (await dialog.isVisible()) && /Mechero · 1[.,]50\s€/.test((await line.innerText()).replace(/ /g, ' ')) && /manual/i.test(await line.innerText()), (await line.innerText()).replace(/\s+/g, ' '));
await page.screenshot({ path: `${OUT}/2-cart-1180.png` });

// 3. Commit.
await page.locator('[wire\\:click^="quickCash"]').first().click(); await settle();
const pad = page.locator('[data-signature-canvas]');
if (await pad.count()) {
    const box = await pad.boundingBox();
    await page.mouse.move(box.x + 20, box.y + 30); await page.mouse.down();
    await page.mouse.move(box.x + 120, box.y + 80, { steps: 8 }); await page.mouse.up();
    await page.click('[data-signature-save]'); await settle();
}
await page.click('[data-commit-action]'); await settle();
await page.screenshot({ path: `${OUT}/3-committed-1180.png` });
const orderId = sql("select id from orders order by created_at desc limit 1");
const items = JSON.parse(sql(`select items from orders where id = '${orderId}'`) || '[]');
const manual = items.find((i) => i.article_id == null);
check('the order carries the manual line: no article_id, 150 cents, the reason', !! manual && manual.name === 'Mechero' && manual.line_total_cents === 150 && manual.reference === 'Sin código', JSON.stringify(manual));
check('and a dispensation was written with it', Number(sql("select count(*) from dispensations where created_at >= datetime('now', '-5 minutes')")) >= 1);

const location = sql(`select location_id from orders where id = '${orderId}'`);
const org = sql(`select organisation_id from orders where id = '${orderId}'`);
sql(`insert or ignore into settings (id, organisation_id, location_id, key, value, type, created_at, updated_at) values ('01proof331receipt00000000', '${org}', '${location}', 'bar_receipt_enabled', '1', 'BOOL', datetime('now'), datetime('now'))`);
await page.goto(`${BASE}/counter/bar/receipt/${orderId}`, { waitUntil: 'networkidle' });
const receipt = (await page.locator('body').innerText()).replace(/ /g, ' ');
check('the ticket shows «Mechero» and 1,50 €', /Mechero/.test(receipt) && /1[.,]50 €/.test(receipt));
await page.screenshot({ path: `${OUT}/4-receipt-1180.png`, fullPage: true });

const tillId = sql(`select till_session_id from orders where id = '${orderId}'`);
await page.goto(`${BASE}/till-sessions/${tillId}`, { waitUntil: 'networkidle' });
const z = (await page.locator('main').innerText()).replace(/ /g, ' ');
const barLine = (z.match(/Barra y tienda en efectivo\s*\n?\s*([0-9.,]+ €)/) ?? [])[1];
check('the Z report counts it in «Barra y tienda en efectivo»', barLine !== undefined && barLine.endsWith('€') && Number(sql(`select sum(cash_cents) from orders where till_session_id = '${tillId}' and status = 'COMPLETED'`)) >= 150, barLine);
await page.screenshot({ path: `${OUT}/5-z-report-1180.png`, fullPage: true });

// 4. The Bar screen's own line: the same modal, its own heading.
await page.goto(`${BASE}/counter/bar`, { waitUntil: 'networkidle' });
await page.click('[data-misc-open]'); await page.waitForTimeout(300);
check('the Bar screen opens the same modal, headed «Importe manual»', (await page.locator('[data-manual-line-modal] [role="dialog"]').getAttribute('aria-label')) === 'Importe manual' && await page.locator('[data-manual-line-modal] [role="dialog"]').isVisible());
await page.screenshot({ path: `${OUT}/6-bar-screen-modal-1180.png` });

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
