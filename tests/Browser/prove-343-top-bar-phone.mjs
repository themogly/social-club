// Prompt 343 — the counter top bar on a phone, as Ben saw it on an iPhone (Safari, English, the bell showing, clocked in):
// ⋯ wrapped to a row of its own and its menu opened off the left of the screen; the chip cut the time to "08:…".
// Freshly seeded demo DB (DBFILE), the owner in English, a pending application, clocked in; Chromium AND WebKit:
//   390×844 and 360×780 — ⋯ on the chip's row; ⋯ opens a sheet with every item on screen and working, closing on the
//   backdrop, Esc and Back; the chip's menu too; the chip shows the whole clock-in time;
//   820×1180 and 1180×820 — 341's one row still holds, and the dropdown sits inside the viewport.
import { chromium, webkit } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/343';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(700); };
sql("update users set locale = 'en' where email like 'owner@%'");
const inView = async (p, loc) => { const b = await loc.boundingBox(); const v = p.viewportSize(); return !! b && b.x >= 0 && b.y >= 0 && b.x + b.width <= v.width + 0.5 && b.y + b.height <= v.height + 0.5; };

for (const [engineName, engine] of [['chromium', chromium], ['webkit', webkit]]) {
    const browser = await engine.launch();
    let clockedIn = false;
    let signedIn = null; // one sign-in per engine, its cookies reused — the login page allows five attempts a minute
    for (const [w, h] of [[390, 844], [360, 780], [820, 1180], [1180, 820]]) {
        const tag = `${engineName} ${w}x${h}`;
        const page = await (await browser.newContext({ viewport: { width: w, height: h }, hasTouch: w < 640, storageState: signedIn ?? undefined })).newPage();
        if (signedIn) await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
        else { await signInToCounter(page, '/counter/till', { account: 'owner', sede: 'Central Branch' }); signedIn = await page.context().storageState(); }
        if (await page.locator('[data-clock-in]').isVisible().catch(() => false)) { await page.click('[data-clock-in]'); await settle(page); clockedIn = true; }
        else if (await page.locator('[data-clock-skip]').isVisible().catch(() => false)) { await page.click('[data-clock-skip]'); await settle(page); }
        if (await page.locator('input[wire\\:model="floatInput"]').count()) {
            await page.fill('input[wire\\:model="floatInput"]', '50'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
        }
        await page.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
        const chip = page.locator('[data-operator-name-chip]');
        const more = page.locator('[data-counter-more]');
        if (! await chip.waitFor({ timeout: 8000 }).then(() => true).catch(() => false)) {
            await page.screenshot({ path: `${OUT}/0-no-chip-${engineName}-${w}.png` });
            check(`${tag}: the counter loaded with the chip`, false, page.url()); await page.context().close(); continue;
        }

        if (w < 640) {
            const c = await chip.boundingBox(); const m = await more.boundingBox();
            check(`${tag}: ⋯ is on the chip's row`, !! c && !! m && m.y < c.y + c.height && c.y < m.y + m.height, JSON.stringify({ chip: c && Math.round(c.y), more: m && Math.round(m.y) }));
            const time = sql("select occurred_at from staff_clock_events where type = 'IN' order by recorded_at desc limit 1");
            const shown = (await chip.innerText()).replace(/\s+/g, ' ');
            const clipped = await chip.locator('[data-clock-short]').evaluate((e) => e.scrollWidth > e.clientWidth + 1).catch(() => true);
            check(`${tag}: the chip shows the whole clock-in time`, /\d\d:\d\d/.test(shown) && ! /…|\.\.\./.test(shown.split('·').pop() ?? '') && ! clipped, shown);
            await page.screenshot({ path: `${OUT}/1-bar-${engineName}-${w}.png` });

            await more.click(); await page.waitForTimeout(500);
            const items = page.locator('[data-counter-more-menu] [role="menuitem"]:visible');
            const n = await items.count();
            let allIn = n > 0;
            for (let i = 0; i < n; i++) if (! await inView(page, items.nth(i))) allIn = false;
            const hooks = await Promise.all(['data-counter-training', 'data-counter-terminal', 'data-counter-admin-link', 'data-counter-logout'].map((h) => page.locator(`[data-counter-more-menu] [${h}]`).isVisible()));
            check(`${tag}: ⋯ opens a sheet with every item on screen (training, device, administration, sign out)`, allIn && hooks.every(Boolean), `${n} items ${JSON.stringify(hooks)}`);
            await page.screenshot({ path: `${OUT}/2-more-sheet-${engineName}-${w}.png` });
            await page.locator('[data-counter-more-menu] [data-counter-training]').click(); await page.waitForTimeout(600);
            check(`${tag}: choosing an item works (the training sheet opens) and the menu closes`, await page.getByRole('button', { name: /Enter training mode|Entrar en modo formación/ }).isVisible() && ! await items.first().isVisible());
            await page.keyboard.press('Escape'); await page.waitForTimeout(400);
            await page.mouse.click(5, 300).catch(() => {}); await page.waitForTimeout(300);

            // Closing it: the backdrop, Esc, and Android Back (the sheet is an overlay — Back closes it, the page stays).
            const moreOpen = () => page.locator('[data-counter-more-menu]').isVisible();
            await more.click(); await page.waitForTimeout(300); await page.mouse.click(w / 2, 150); await page.waitForTimeout(300);
            const byBackdrop = ! await moreOpen();
            await more.click(); await page.waitForTimeout(300); await page.keyboard.press('Escape'); await page.waitForTimeout(300);
            const byEsc = ! await moreOpen();
            await more.click(); await page.waitForTimeout(300); await page.evaluate(() => history.back()); await page.waitForTimeout(600);
            const byBack = ! await moreOpen() && new URL(page.url()).pathname === '/counter';
            check(`${tag}: the sheet closes on the backdrop, Esc and Back (the page stays)`, byBackdrop && byEsc && byBack, JSON.stringify({ byBackdrop, byEsc, byBack }));

            await chip.click(); await page.waitForTimeout(500);
            const chipItems = page.locator('[data-counter-chip-menu] [role="menuitem"]:visible');
            let chipIn = await chipItems.count() === 3;
            for (let i = 0; i < await chipItems.count(); i++) if (! await inView(page, chipItems.nth(i))) chipIn = false;
            check(`${tag}: the chip's menu is a sheet on screen too (3 items)`, chipIn, `${await chipItems.count()} items`);
            await page.screenshot({ path: `${OUT}/3-chip-sheet-${engineName}-${w}.png` });
        } else {
            const ids = ['[data-counter-home-link]', '[data-operator-name-chip]', '[data-counter-lock]', '[data-counter-panic]', '[data-counter-more]'];
            const tops = await Promise.all(ids.map(async (s) => { const b = await page.locator(s).first().boundingBox(); return b ? b.y + b.height / 2 : -999; }));
            check(`${tag}: 341's one row still holds`, Math.max(...tops) - Math.min(...tops) < 24, JSON.stringify(tops.map(Math.round)));
            await more.click(); await page.waitForTimeout(400);
            check(`${tag}: the ⋯ dropdown sits inside the viewport`, await inView(page, page.locator('[data-counter-more-menu]')));
            await page.screenshot({ path: `${OUT}/4-dropdown-${engineName}-${w}.png` });
        }
        await page.context().close();
    }
    await browser.close();
}
sql("update users set locale = null where email like 'owner@%'");
process.exit(results.every(Boolean) ? 0 : 1);
