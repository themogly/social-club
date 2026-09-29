// Prompt 327 — a staging site (APP_ENV=staging, a throwaway database seeded by `csc:seed-staging --fresh`):
//   1. the owner signs in and sees the teal «ENTORNO DE PRUEBAS» strip, and «[Pruebas]» in the tab title;
//   2. at the counter, the strip sits above the top bar; a visit is served with the staff PIN 3456.
import { chromium } from 'playwright';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/327';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(700); };

const panel = await browser.newPage({ viewport: { width: 1440, height: 900 } });
check('owner signs in', await signIn(panel, { account: 'owner' }));
await panel.goto(`${BASE}/`, { waitUntil: 'networkidle' });
const strip = panel.locator('[data-staging-strip]');
check('the panel shows the staging strip', await strip.isVisible() && /ENTORNO DE PRUEBAS/.test(await strip.innerText()));
check('it is teal', (await strip.evaluate((el) => getComputedStyle(el).backgroundColor)) === 'rgb(15, 118, 110)');
check('the panel tab reads [Pruebas]', (await panel.title()).startsWith('[Pruebas]'), await panel.title());
await panel.screenshot({ path: `${OUT}/panel-1440.png` });

const counter = await browser.newPage({ viewport: { width: 1180, height: 820 } });
await signInToCounter(counter, '/counter/till', { account: 'staff' });
const box = await counter.locator('[data-staging-strip]').boundingBox();
const bar = await counter.locator('[data-counter-topbar]').boundingBox().catch(() => null);
check('the counter shows the strip above its top bar', box !== null && (bar === null || box.y + box.height <= bar.y + 1), JSON.stringify({ box, bar }));
check('the counter tab reads [Pruebas]', (await counter.title()).startsWith('[Pruebas]'), await counter.title());
if (await counter.locator('input[wire\\:model="floatInput"]').count()) {
    await counter.fill('input[wire\\:model="floatInput"]', '50');
    await counter.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(counter);
}
await counter.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
await counter.fill('#member-lookup', 'M-00001'); await counter.press('#member-lookup', 'Enter'); await settle(counter);
await counter.click('[data-member-lookup-result]').catch(() => {}); await settle(counter);
await counter.locator('[data-catalogue-item="genetics"]:visible').first().click(); await settle(counter);
const chip = counter.locator('[data-batch-chip]').first();
if (await chip.count()) { await chip.click(); await settle(counter); }
await counter.keyboard.type('1'); await counter.waitForTimeout(300);
await counter.click('[data-add-line]'); await settle(counter);
await counter.locator('[wire\\:click^="quickCash"]').first().click(); await settle(counter);
const pad = counter.locator('[data-signature-canvas]');
if (await pad.count()) {
    const b = await pad.boundingBox();
    await counter.mouse.move(b.x + 20, b.y + 30); await counter.mouse.down();
    await counter.mouse.move(b.x + 120, b.y + 80, { steps: 8 }); await counter.mouse.up();
    await counter.click('[data-signature-save]'); await settle(counter);
}
await counter.click('[data-commit-action]'); await settle(counter);
check('a visit is served by the staff PIN', /Dispensación registrada|Visita liquidada/.test(await counter.locator('main').innerText()));
await counter.screenshot({ path: `${OUT}/counter-1180.png` });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
