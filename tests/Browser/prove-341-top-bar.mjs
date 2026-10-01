// Prompt 341 — the counter top bar: one row at tablet widths, the chip says who is working and their clock state, and the
// rest is labelled in ⋯ Más. Freshly seeded demo DB (DBFILE), as the owner (PIN 1234), at 820×1180 and 1180×820:
//   1. every top-bar control on one row; each ≥ 44×44; no icon-only control without an accessible name;
//   2. signed in with a PIN, unclocked: the chip «Sin fichar» (amber) with «Fichar entrada» beside it;
//   3. «Fichar entrada» → own PIN → the chip «Fichado HH:MM» (green), no reload;
//   4. ⋯ Más lists Modo formación, Este dispositivo, Administración, Salir; the padlock and shield stay out;
//   5. lock and unlock;  6. at 390, the chip, the padlock and ⋯ on the first row.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/341';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(700); };
const owner = sql("select id from users where email like 'owner@%'");
const pin = async (p, digits) => { for (const d of digits) await p.locator('[data-counter-surface] button').filter({ hasText: new RegExp(`^${d}$`) }).first().click(); await p.click('[data-counter-surface-unlock]'); await settle(p); await p.waitForTimeout(900); };

const CONTROLS = '[data-counter-home-link], [data-counter-sede-region] > *:not([data-counter-sede-switch-confirm]):not([data-counter-sede-error]), [data-operator-name-chip], [data-counter-clock-in-quick], [data-counter-lock], [data-counter-panic], [data-counter-more]';
const rowOf = (p) => p.locator('[data-counter-topbar]').evaluate((bar, sel) => [...bar.querySelectorAll(sel)].filter((e) => e.offsetParent !== null).map((e) => {
    const r = e.getBoundingClientRect(); return { el: e.getAttribute('data-counter-more') !== null ? 'more' : (e.dataset.counterLock !== undefined ? 'lock' : [...e.attributes].map((a) => a.name).find((n) => n.startsWith('data-')) ?? e.tagName), top: Math.round(r.top), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) };
}), CONTROLS);

for (const [w, h] of [[1180, 820], [820, 1180]]) { // 1180 first: the 820 run clocks the owner in
    const tag = `${w}x${h}`;
    const page = await (await browser.newContext({ viewport: { width: w, height: h } })).newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    await signInToCounter(page, '/counter/till', { account: 'owner', sede: 'Central Branch' });
    if (await page.locator('[data-clock-skip]').isVisible().catch(() => false)) { await page.click('[data-clock-skip]'); await settle(page); }
    if (await page.locator('input[wire\\:model="floatInput"]').count()) {
        await page.fill('input[wire\\:model="floatInput"]', '50'); await page.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(page);
    }
    await page.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
    // If the opener's till-open clock-in (338) fired, undo it so the chip starts unclocked.
    if (await page.locator('[data-clock-in-undo]').isVisible().catch(() => false)) { await page.click('[data-clock-in-undo]'); await settle(page); }

    const boxes = await rowOf(page);
    const centres = boxes.map((b) => (b.top + b.bottom) / 2);
    const oneRow = Math.max(...centres) - Math.min(...centres) < 24;
    check(`${tag}: every top-bar control on one row`, oneRow, JSON.stringify(boxes.map((b) => `${b.el}@${b.top}`)));
    check(`${tag}: every control at least 44×44`, boxes.every((b) => b.h >= 44 && b.w >= 44), JSON.stringify(boxes.filter((b) => b.h < 44 || b.w < 44)));
    const unnamed = await page.locator('[data-counter-topbar]').evaluate((bar) => [...bar.querySelectorAll('button, a')].filter((e) => e.offsetParent !== null)
        .filter((e) => ! (e.getAttribute('aria-label') || e.innerText.trim() || e.getAttribute('title'))).map((e) => e.outerHTML.slice(0, 80)));
    check(`${tag}: no icon-only control without an accessible name`, unnamed.length === 0, JSON.stringify(unnamed));

    const chip = page.locator('[data-operator-name-chip]');
    check(`${tag}: unclocked, the chip says «Sin fichar» in amber with «Fichar entrada» beside it`, (await chip.getAttribute('data-clock-state')) === 'out'
        && /Sin fichar/.test(await chip.innerText()) && await page.locator('[data-counter-clock-in-quick]').isVisible());
    await page.screenshot({ path: `${OUT}/1-unclocked-${tag}.png` });

    if (w === 820) {
        await page.click('[data-counter-clock-in-quick]'); await settle(page);
        await pin(page, '1234');
        const label = (await chip.innerText()).replace(/\s+/g, ' ');
        check(`${tag}: «Fichar entrada» + PIN → «Fichado HH:MM» (green), no reload`, (await chip.getAttribute('data-clock-state')) === 'in' && /Fichado \d\d:\d\d/.test(label)
            && ! await page.locator('[data-counter-clock-in-quick]').isVisible(), label);
        await page.screenshot({ path: `${OUT}/2-clocked-in-${tag}.png` });
    }

    await page.click('[data-counter-more]'); await page.waitForTimeout(300);
    const more = (await page.locator('[data-counter-more-menu]').innerText()).replace(/\s+/g, ' ');
    check(`${tag}: ⋯ Más lists Modo formación, Este dispositivo, Administración, Salir`, ['Modo formación', 'Este dispositivo', 'Administración', 'Salir'].every((l) => more.includes(l)), more);
    check(`${tag}: the padlock and the shield stay in the bar`, await page.locator('[data-counter-lock]').isVisible() && await page.locator('[data-counter-panic]').isVisible()
        && await page.locator('[data-counter-more-menu] [data-counter-lock], [data-counter-more-menu] [data-counter-panic]').count() === 0);
    await page.screenshot({ path: `${OUT}/3-more-menu-${tag}.png` });
    await page.keyboard.press('Escape');

    await page.click('[data-operator-name-chip]'); await page.waitForTimeout(300);
    const chipMenu = (await page.locator('[data-counter-chip-menu]').innerText()).replace(/\s+/g, ' ');
    check(`${tag}: the chip's menu — fichar, Mis horas, Cambiar de persona`, /Fichar (entrada|salida)/.test(chipMenu) && chipMenu.includes('Mis horas') && chipMenu.includes('Cambiar de persona'), chipMenu);
    await page.keyboard.press('Escape');

    await page.click('[data-counter-lock]'); await page.waitForTimeout(600);
    await pin(page, '1234');
    if (await page.locator('[data-clock-skip]').isVisible().catch(() => false)) { await page.click('[data-clock-skip]'); await settle(page); }
    check(`${tag}: lock and unlock`, await chip.isVisible());
    await page.context().close();
}

// 390: the chip, the padlock and ⋯ on the first row.
{
    const page = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
    await signInToCounter(page, '/counter', { account: 'owner', sede: 'Central Branch' });
    await page.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
    const top = async (sel) => Math.round((await page.locator(sel).first().boundingBox())?.y ?? -1);
    const [home, chipTop, lock, more] = [await top('[data-counter-home-link]'), await top('[data-operator-name-chip]'), await top('[data-counter-lock]'), await top('[data-counter-more]')];
    check('390: the chip, the padlock and ⋯ on the first row with home', Math.max(home, chipTop, lock, more) - Math.min(home, chipTop, lock, more) < 30, JSON.stringify({ home, chipTop, lock, more }));
    await page.screenshot({ path: `${OUT}/4-phone-390.png` });
    await page.context().close();
}

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
