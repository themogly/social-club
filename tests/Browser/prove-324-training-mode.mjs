// Prompt 324 — *Modo formación* on the real counter. Throwaway database (DBFILE = its path, read with sqlite3), as the owner
// at Central Branch with the REAL till open:
//   1. the top bar's *Modo formación* asks first, then the striped banner is on every screen (1180×820 and 820×1180);
//   2. a full practice visit (member, 1 g, cash, signature): the success reads «(práctica)», the commit button carried the
//      suffix, the receipt is watermarked;
//   3. *Salir del modo formación*: the database is exactly as before, bar the two audit entries.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/324';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const TABLES = ['dispensations', 'dispensation_lines', 'orders', 'check_ins', 'cash_movements', 'stock_movements', 'till_sessions', 'wallet_transactions', 'members', 'staff_clock_events'];
const fingerprint = () => Object.fromEntries(TABLES.map((t) => [t, sql(`select count(*) from ${t}`)]).concat([['batches', sql('select sum(coalesce(remaining_cg,0)) from batches')]]));
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(700); };

const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
await signInToCounter(page, '/counter/till', { account: 'owner', sede: 'Central Branch' });
if (await page.locator('input[wire\\:model="floatInput"]').count()) { // the REAL till, opened before practice
    await page.fill('input[wire\\:model="floatInput"]', '50');
    await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
}
await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
const before = fingerprint();
const auditsBefore = Number(sql('select count(*) from audit_logs'));

// 1. Enter.
await page.click('[data-counter-training]'); await page.waitForTimeout(400);
await page.screenshot({ path: `${OUT}/1-confirm-1180.png` });
check('entering asks first', /Nada de lo que hagas se guardará/.test(await page.locator('[data-counter-sheet="training"]').innerText()));
await page.click('[data-training-start]'); await settle(page);
check('the banner is on', await page.locator('[data-training-banner]').isVisible());
const bannerBox = await page.locator('[data-training-banner]').boundingBox();
check('it sits at the top and spans the width', bannerBox && bannerBox.y <= 1 && bannerBox.x <= 0.5 && bannerBox.width >= 1179, JSON.stringify(bannerBox));
check('and causes no sideways scroll', await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth));
check('nothing dismisses it but leaving', await page.locator('[data-training-banner] button').count() === 1);

// 2. A practice visit.
await page.fill('#member-lookup', 'M-00027'); await page.press('#member-lookup', 'Enter'); await settle(page);
await page.click('[data-member-lookup-result]').catch(() => {}); await settle(page);
await page.locator('[data-catalogue-item="genetics"]:visible').first().click(); await settle(page);
const chip = page.locator('[data-batch-chip]').first();
if (await chip.count()) { await chip.click(); await settle(page); }
await page.keyboard.type('1'); await page.waitForTimeout(300);
await page.click('[data-add-line]'); await settle(page);
await page.locator('[wire\\:click^="quickCash"]').first().click(); await settle(page);
const pad = page.locator('[data-signature-canvas]');
if (await pad.count()) {
    const box = await pad.boundingBox();
    await page.mouse.move(box.x + 20, box.y + 30); await page.mouse.down();
    await page.mouse.move(box.x + 120, box.y + 80, { steps: 8 }); await page.mouse.up();
    await page.click('[data-signature-save]'); await settle(page);
}
const suffix = await page.locator('[data-commit-action]').evaluate((el) => getComputedStyle(el, '::after').content);
check('the commit button reads «(práctica)»', /práctica/.test(suffix), suffix);
await page.screenshot({ path: `${OUT}/2-basket-1180.png` });
await page.click('[data-commit-action]'); await settle(page);
const feedback = (await page.locator('[data-commit-feedback], [data-last-sale]').allInnerTexts()).join(' ');
check('the success says it was practice', /\(práctica\)/.test(feedback), feedback.replace(/\s+/g, ' ').slice(0, 120));
await page.screenshot({ path: `${OUT}/3-practice-sale-1180.png` });
await page.locator('[data-last-sale-options]').click().catch(() => {});
check('no «Enviar por email» in training', await page.locator('[data-last-sale-email]').count() === 0);
await page.locator('[data-receipt-open]').click(); await page.waitForTimeout(1500);
const frame = page.frameLocator('iframe').first();
check('the practice receipt is watermarked', await frame.locator('[data-practice-receipt]').count() === 1);
await page.screenshot({ path: `${OUT}/4-receipt-1180.png` });
await page.keyboard.press('Escape'); await settle(page);

// The banner on every screen, both orientations.
for (const [w, h] of [[1180, 820], [820, 1180]]) {
    await page.setViewportSize({ width: w, height: h });
    for (const screen of ['/counter', '/counter/till', '/counter/checkin', '/counter/members', '/counter/bar']) {
        await page.goto(`${BASE}${screen}`, { waitUntil: 'networkidle' });
        const on = await page.locator('[data-training-banner]').isVisible();
        if (! on) check(`banner on ${screen} at ${w}x${h}`, false);
    }
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' }); await settle(page);
    await page.screenshot({ path: `${OUT}/5-pos-${w}x${h}.png` });
}
check('the banner is on every counter screen at both orientations', true);
await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' }); await settle(page);
check('the till screen explains the till is never really opened or closed', await page.locator('[data-training-till-note]').isVisible());
await page.screenshot({ path: `${OUT}/6-till-820x1180.png`, fullPage: true });

// 3. Leave.
await page.click('[data-training-leave]'); await settle(page);
check('leaving removes the banner', await page.locator('[data-training-banner]').count() === 0);
const after = fingerprint();
check('nothing the practice did was kept', JSON.stringify(before) === JSON.stringify(after), JSON.stringify({ before, after }));
const newAudits = sql(`select action from audit_logs order by rowid desc limit ${Number(sql('select count(*) from audit_logs')) - auditsBefore}`).split('\n').filter(Boolean);
check('only the two training entries were written', JSON.stringify(newAudits.sort()) === JSON.stringify(['counter.training.ended', 'counter.training.started']), JSON.stringify(newAudits));

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
