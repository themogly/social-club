// Prompt 321 — a row's ⋮ menu is not hidden under the next row in panel tables. Throwaway database, as the owner.
// Below 1280 px the row-actions cell is pinned (sticky, opaque, z-index 1: prompt 170/272), which made every row's last
// cell a stacking context at the same level, so the NEXT row's cell painted over an open menu.
//   1–2. at 1180×820 and 1024×768, the ⋮ menu of the FIRST and the LAST row on Lotes, Genéticas and Socios (Productos has
//        no ⋮ — its actions are inline): every item fully on screen, and the element at each item's centre IS that item;
//   3.   at 1440 (the rule off) the same holds (a pin);
//   4.   at 820 with the table scrolled fully left, the action column is still pinned and visible (272's pin);
//   plus light and dark screenshots of an open menu, and the wider check (bulk, filters, column toggles).
import { chromium } from 'playwright';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/321';
const PAGES = [['lotes', '/batches'], ['geneticas', '/genetics'], ['socios', '/members']];
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(500); };

/** Every item of the open dropdown fully on screen and the topmost element at its centre. */
async function menuIsOnTop(page) {
    return page.evaluate(() => {
        // A floating panel is position: fixed/absolute, so offsetParent can be null: open = displayed and with a size.
        const panel = [...document.querySelectorAll('.fi-dropdown-panel')].find((p) => getComputedStyle(p).display !== 'none' && p.getBoundingClientRect().height > 0);
        if (! panel) return { ok: false, why: 'no open panel' };
        const items = [...panel.querySelectorAll('.fi-dropdown-list-item')];
        if (items.length === 0) return { ok: false, why: 'no items' };
        for (const item of items) {
            const r = item.getBoundingClientRect();
            if (r.top < 0 || r.left < 0 || r.bottom > innerHeight || r.right > innerWidth) return { ok: false, why: `off screen: ${item.textContent.trim()}` };
            const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            if (! hit || ! item.contains(hit) && hit !== item) {
                const cell = hit?.closest('td, th');
                return { ok: false, why: `"${item.textContent.trim()}" is covered by ${cell ? cell.tagName + ' of another row' : hit?.className}` };
            }
        }
        return { ok: true, why: `${items.length} items` };
    });
}

async function openRowMenu(page, row) {
    await page.evaluate(() => { const s = document.querySelector('.fi-ta-content'); if (s) s.scrollLeft = 0; });
    const trigger = row.locator('td:last-child .fi-dropdown-trigger button').first();
    await trigger.scrollIntoViewIfNeeded();
    await trigger.click(); await page.waitForTimeout(400);
}

// ONE sign-in for the whole run (the login is throttled; a sign-in per width tripped it), then every width and scheme.
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
check('sign in', await signIn(page, { account: 'owner' }));
for (const scheme of ['light', 'dark']) {
    for (const [w, h] of [[1180, 820], [1024, 768], [1440, 900]]) {
        if (scheme === 'dark' && w !== 1180) continue; // dark: one width is the look; the geometry is the same
        await page.setViewportSize({ width: w, height: h });
        await page.emulateMedia({ colorScheme: scheme });
        await page.evaluate((t) => localStorage.setItem('theme', t), scheme);
        for (const [name, path] of PAGES) {
            await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' }); await settle(page);
            const rows = page.locator('.fi-ta-table > tbody > tr:has(td:last-child .fi-dropdown-trigger)');
            await rows.first().waitFor({ timeout: 10000 }).catch(() => {}); // the first load after sign-in can be slow
            const count = await rows.count();
            if (count < 2) { check(`${w}x${h} ${scheme} ${name}: at least two rows`, false, `${count} at ${page.url()}`); await page.screenshot({ path: `${OUT}/no-rows-${name}-${w}x${h}.png` }); continue; }
            for (const [which, row] of [['first', rows.first()], ['last', rows.last()]]) {
                if (which === 'last') {
                    // Near the table's bottom edge: the row at the bottom of the viewport, so the menu has to flip up.
                    await row.evaluate((el) => el.scrollIntoView({ block: 'end' })); await page.waitForTimeout(300);
                }
                await openRowMenu(page, row);
                const result = await menuIsOnTop(page);
                check(`${w}x${h} ${scheme} ${name}: the ${which} row's menu is fully visible and on top`, result.ok, result.why);
                if (which === 'first') await page.screenshot({ path: `${OUT}/${name}-${w}x${h}-${scheme}-first.png` });
                await page.keyboard.press('Escape'); await page.waitForTimeout(300);
                await page.mouse.click(5, h - 5); await page.waitForTimeout(200);
            }
        }
    }
}

// 4. 272's pin: at 820 the action column is pinned and visible with the table scrolled fully left.
const narrow = page;
await narrow.setViewportSize({ width: 820, height: 1180 });
await narrow.evaluate(() => localStorage.setItem('theme', 'light'));
for (const [name, path] of PAGES) {
    await narrow.goto(`${BASE}${path}`, { waitUntil: 'networkidle' }); await settle(narrow);
    const box = await narrow.evaluate(() => {
        const s = document.querySelector('.fi-ta-content'); if (s) s.scrollLeft = 0;
        const cell = document.querySelector('.fi-ta-table > tbody > tr > td:last-child');
        const r = cell?.getBoundingClientRect();
        return r ? { right: r.right, left: r.left, pos: getComputedStyle(cell).position, vw: innerWidth } : null;
    });
    check(`820 ${name}: the action column is pinned and on screen, scrolled fully left`, box !== null && box.pos === 'sticky' && box.right <= box.vw && box.left >= 0, JSON.stringify(box));
}

// The wider check: every other dropdown that opens over a table with pinned cells, at 1024×768.
async function panelOnTop(p) {
    return p.evaluate(() => {
        const panel = [...document.querySelectorAll('.fi-dropdown-panel')].find((el) => getComputedStyle(el).display !== 'none' && el.getBoundingClientRect().height > 0);
        if (! panel) return { ok: false, why: 'no open panel' };
        const full = panel.getBoundingClientRect();
        if (full.left < 0 || full.right > innerWidth) return { ok: false, why: 'panel off the side of the screen' };
        // A long panel (the column list) may run below the fold and scroll with the page, as Filament's always has;
        // what matters here is that nothing paints OVER it, so sample the part that is on screen.
        const r = { left: full.left, width: full.width, top: Math.max(full.top, 0), height: Math.min(full.bottom, innerHeight) - Math.max(full.top, 0) };
        for (const [fx, fy] of [[0.5, 0.5], [0.1, 0.1], [0.9, 0.1], [0.1, 0.9], [0.9, 0.9]]) {
            const hit = document.elementFromPoint(r.left + r.width * fx, r.top + r.height * fy);
            if (! hit || ! panel.contains(hit)) return { ok: false, why: `covered at ${fx},${fy} by ${hit?.closest('td, th')?.tagName ?? hit?.className}` };
        }
        return { ok: true, why: `${Math.round(r.width)}×${Math.round(r.height)}` };
    });
}
await page.setViewportSize({ width: 1024, height: 768 });
for (const [name, path, trigger, before] of [
    ['filters', '/batches', '.fi-ta-filters-dropdown .fi-dropdown-trigger button', null],
    ['column toggles', '/genetics', '.fi-ta-col-manager-dropdown .fi-dropdown-trigger button', null],
    ['bulk actions', '/members', '.fi-ta-header-toolbar .fi-dropdown-trigger button:has-text("Abrir acciones"), .fi-ta-header-toolbar .fi-dropdown-trigger button:has-text("acciones")', '.fi-ta-table > tbody > tr input[type="checkbox"]'],
]) {
    await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' }); await settle(page);
    if (before) { await page.locator(before).first().check(); await settle(page); }
    const t = page.locator(trigger).first();
    if (! await t.count()) { check(`1024 wider: ${name} dropdown present`, false, trigger); continue; }
    await t.click(); await page.waitForTimeout(500);
    const result = await panelOnTop(page);
    check(`1024 wider: the ${name} dropdown is fully visible and on top of the pinned cells`, result.ok, result.why);
    await page.screenshot({ path: `${OUT}/wider-${name.replace(' ', '-')}-1024.png` });
    await page.keyboard.press('Escape'); await page.waitForTimeout(300);
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
