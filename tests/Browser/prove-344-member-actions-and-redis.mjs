// Prompt 344 — the member page's header, and sign-in with Redis down. Freshly seeded demo DB (DBFILE), as the owner.
//   MODE=header (default): Socios → Editar at 1400×900 and 390×844 — five everyday buttons then *Más acciones*; inside,
//     Datos · Estado del socio · Eliminar (red, last); on the phone the open list is on screen and no red button leads.
//   MODE=redis-down: the server runs with CACHE_STORE=redis pointed at a dead port (CACHE_LIMITER left to its default):
//     the panel login lands in the panel, and the counter PIN pad signs the operator in.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/344';
const DB = process.env.DBFILE;
const MODE = process.env.MODE ?? 'header';
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];

if (MODE === 'header') {
    const member = sql('select id from members where deleted_at is null order by created_at limit 1');
    let state = null;
    for (const [w, h] of [[1400, 900], [390, 844]]) {
        const page = await (await browser.newContext({ viewport: { width: w, height: h }, storageState: state ?? undefined })).newPage();
        page.on('pageerror', (e) => errors.push(e.message));
        if (! state) { await signIn(page, { account: 'owner' }); state = await page.context().storageState(); }
        await page.goto(`${BASE}/members/${member}/edit`, { waitUntil: 'networkidle' });

        const header = page.locator('.fi-header-actions-ctn');
        const buttons = (await header.locator('.fi-btn:visible').allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim());
        const expected = ['Socios', 'Cuenta del socio', 'Reenviar carné QR', 'Límites personalizados', 'Generar documento', 'Más acciones'];
        check(`${w}: the header — ← Socios, the four everyday actions, then Más acciones`, JSON.stringify(buttons) === JSON.stringify(expected), JSON.stringify(buttons));
        const firstRed = await header.locator('.fi-btn.fi-color-danger:visible, .fi-btn.fi-color-warning:visible').count();
        check(`${w}: no red or amber button in the visible header`, firstRed === 0);
        await page.screenshot({ path: `${OUT}/1-header-${w}.png` });

        await page.locator('[data-more-actions]').click(); await page.waitForTimeout(500);
        const panel = page.locator('.fi-dropdown-panel:visible').last();
        const text = (await panel.innerText()).replace(/\s+/g, ' ');
        const order = ['DATOS', 'ESTADO DEL SOCIO', 'ELIMINAR'].map((s) => text.toUpperCase().indexOf(s));
        check(`${w}: Más acciones — Datos, Estado del socio, Eliminar, in that order`, order.every((i) => i >= 0) && order[0] < order[1] && order[1] < order[2], text.slice(0, 200));
        const tail = text.slice(text.toUpperCase().indexOf('ELIMINAR'));
        check(`${w}: Eliminar holds Solicitar supresión (RGPD) and Borrar, last`, tail.includes('Solicitar supresión (RGPD)') && tail.includes('Borrar') && ! /Expulsar|Suspender/.test(tail), tail);
        const cut = await panel.locator('.fi-dropdown-list-item-label').evaluateAll((els) => els.filter((e) => e.scrollWidth > e.clientWidth + 1).map((e) => e.innerText));
        check(`${w}: every label in the list reads whole`, cut.length === 0, JSON.stringify(cut));
        const b = await panel.boundingBox();
        check(`${w}: the open list is inside the viewport`, !! b && b.x >= 0 && b.y >= 0 && b.x + b.width <= w + 0.5 && b.y + b.height <= h + 0.5, JSON.stringify(b && { x: Math.round(b.x), w: Math.round(b.width), bottom: Math.round(b.y + b.height) }));
        await page.screenshot({ path: `${OUT}/2-more-open-${w}.png` });

        if (w === 1400) {
            await panel.getByText('Suspender', { exact: true }).click(); await page.waitForTimeout(600);
            check(`${w}: Suspender, from the group, still opens its reason modal`, await page.locator('.fi-modal-window:visible textarea').count() > 0);
            await page.screenshot({ path: `${OUT}/3-suspend-modal-${w}.png` });
        }
        await page.context().close();
    }
} else {
    const page = await (await browser.newContext({ viewport: { width: 1180, height: 820 } })).newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    check('Redis down: the panel login lands in the panel, not back on the form', await signIn(page, { account: 'owner' }), page.url());
    await page.screenshot({ path: `${OUT}/4-redis-down-panel.png` });
    await page.context().close();

    const counter = await (await browser.newContext({ viewport: { width: 1180, height: 820 } })).newPage();
    const ok = await signInToCounter(counter, '/counter', { account: 'owner', sede: 'Central Branch' });
    const chip = await counter.locator('[data-operator-name-chip]').isVisible().catch(() => false);
    check('Redis down: the counter PIN pad signs the operator in', ok && chip, counter.url());
    check('…and the PIN tally is in the cache table', Number(sql("select count(*) from cache where key like '%counter-pin%'")) > 0);
    await counter.screenshot({ path: `${OUT}/5-redis-down-counter.png` });
}

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
