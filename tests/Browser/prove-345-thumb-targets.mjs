// Prompt 345 — the panel's last small controls at a thumb's size on a phone. Freshly seeded demo DB (DBFILE), the owner.
//   1. 390×844, touch (pointer: coarse): ES|EN, a filter chip's ✕ (Socios, Lotes), Lotes' «Ver todos», the profile's
//      2FA «Configurar» — each tap area ≥ 44×44;
//   2. 1280×800, mouse (pointer: fine): the same controls keep their desktop size (a pin);
//   3. the sweep: every panel page at 390×844 with touch — no visible interactive element under 44 px, bar the skip link,
//      anything inside a horizontally scrolling table, and a link inside a sentence (WCAG 2.5.5's inline exception).
// The TAP AREA is the element's box grown by an absolutely positioned ::before/::after (how the enlargement is done
// where the visible look must stay), measured the same way on both runs.
import { chromium, devices } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/345';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];

// The tap area of one element: its own box, or a positioned pseudo-element's, whichever is larger.
const TAP = `(el) => {
    const r = el.getBoundingClientRect();
    let w = r.width, h = r.height;
    for (const which of ['::before', '::after']) {
        const s = getComputedStyle(el, which);
        if (s.content && s.content !== 'none' && s.position === 'absolute') {
            w = Math.max(w, parseFloat(s.width) || 0); h = Math.max(h, parseFloat(s.height) || 0);
        }
    }
    return { w: Math.round(w), h: Math.round(h) };
}`;
const tap = (loc) => loc.evaluate(new Function(`return (${TAP})`)());

const CONTROLS = {
    'ES|EN segment': { url: '/', sel: '[data-locale-switch] button' },
    'filter chip ✕ (Socios)': { url: '/members?filters[status][value]=ACTIVE', sel: '.fi-ta-filter-indicators .fi-badge-delete-btn' },
    'filter chip ✕ (Lotes)': { url: '/batches', sel: '.fi-ta-filter-indicators .fi-badge-delete-btn' },
    '«Ver todos» (Lotes)': { url: '/batches', sel: '[data-show-empty-batches]' },
    '«Configurar» (2FA)': { url: '/profile', sel: '[data-mfa-set-up], .fi-sc-actions button:has-text("Configurar"), button:has-text("Configurar")' },
};

const contexts = {
    phone: { ...devices['iPhone 14'], defaultBrowserType: undefined },
    desktop: { viewport: { width: 1280, height: 800 } },
};
let state = null;
const measured = {};
for (const [kind, options] of Object.entries(contexts)) {
    const context = await browser.newContext({ ...options, storageState: state ?? undefined });
    const page = await context.newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    if (! state) { await signIn(page, { account: 'owner' }); state = await context.storageState(); }
    const coarse = await page.evaluate(() => matchMedia('(pointer: coarse)').matches);
    check(`${kind}: the pointer is ${kind === 'phone' ? 'coarse' : 'fine'}`, coarse === (kind === 'phone'));

    for (const [name, { url, sel }] of Object.entries(CONTROLS)) {
        await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
        const loc = page.locator(sel).filter({ visible: true }).first();
        if (! await loc.count()) { check(`${kind}: ${name} is on the page`, false, url); continue; }
        const size = await tap(loc);
        measured[`${kind}:${name}`] = size;
        if (kind === 'phone') check(`${kind}: ${name} — tap area ≥ 44×44`, size.w >= 44 && size.h >= 44, JSON.stringify(size));
        else console.log(`     desktop ${name}: ${JSON.stringify(size)}`);
    }
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await page.screenshot({ path: `${OUT}/1-topbar-${kind}.png` });
    if (kind === 'phone') {
        const tops = await page.locator('.fi-topbar').evaluate((bar) => [...bar.querySelectorAll('button, a, select')].filter((e) => e.offsetParent !== null).map((e) => Math.round(e.getBoundingClientRect().top + e.getBoundingClientRect().height / 2)));
        check('phone: the top bar still fits on one row', Math.max(...tops) - Math.min(...tops) < 20, JSON.stringify(tops));
        await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
        await page.screenshot({ path: `${OUT}/2-batches-phone.png`, fullPage: false });
        await page.goto(`${BASE}/profile`, { waitUntil: 'networkidle' });
        await page.locator(CONTROLS['«Configurar» (2FA)'].sel).first().scrollIntoViewIfNeeded().catch(() => {});
        await page.screenshot({ path: `${OUT}/3-profile-phone.png` });
    }
    await context.close();
}

// 2. Desktop pin — today's sizes (measured on main before 345, recorded in DECISIONS.md).
const PIN = { // measured on main (f559e1c) at 1280×800 with a mouse, before 345
    'ES|EN segment': { w: 31, h: 24 },
    'filter chip ✕ (Socios)': { w: 20, h: 20 },
    'filter chip ✕ (Lotes)': { w: 20, h: 20 },
    '«Ver todos» (Lotes)': { w: 64, h: 22 },
    '«Configurar» (2FA)': { w: 98, h: 20 },
};
if (PIN) {
    for (const [name, size] of Object.entries(PIN)) {
        const now = measured[`desktop:${name}`];
        check(`desktop: ${name} keeps its size`, !! now && Math.abs(now.w - size.w) <= 1 && Math.abs(now.h - size.h) <= 1, `${JSON.stringify(now)} vs ${JSON.stringify(size)}`);
    }
}

// 3. The sweep.
const member = sql('select id from members where deleted_at is null order by created_at limit 1');
const batch = sql('select id from batches where deleted_at is null order by created_at limit 1');
const PAGES = (process.env.PAGES ?? '').split(',').filter(Boolean).concat([`/members/${member}/edit`, `/batches/${batch}/edit`]);
const context = await browser.newContext({ ...devices['iPhone 14'], storageState: state });
const page = await context.newPage();
const small = [];
for (const path of PAGES) {
    await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' }).catch(() => {});
    const found = await page.evaluate((tapSrc) => {
        const tapOf = new Function(`return (${tapSrc})`)();
        const scrollsSideways = (el) => { for (let p = el.parentElement; p; p = p.parentElement) { const s = getComputedStyle(p); if (/(auto|scroll)/.test(s.overflowX) && p.scrollWidth > p.clientWidth + 1) return true; } return false; };
        const label = (el) => (el.getAttribute('aria-label') || el.innerText || el.getAttribute('title') || el.name || el.className || el.tagName).toString().trim().replace(/\s+/g, ' ').slice(0, 50);
        return [...document.querySelectorAll('a[href], button, select, input:not([type=hidden]), textarea, [role=button], [role=tab], [role=switch], [role=checkbox], summary')]
            .filter((el) => el.offsetParent !== null && getComputedStyle(el).visibility !== 'hidden' && ! el.closest('[aria-hidden="true"], .sr-only, .fi-dropdown-panel, .fi-modal:not(.fi-modal-open)'))
            .filter((el) => ! el.matches('.fi-skip-link, [href="#main"], [href^="#"][class*=sr-only]'))
            .filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.bottom > 0 && r.right > 0 && r.left < innerWidth; })
            .filter((el) => ! scrollsSideways(el))
            // WCAG 2.5.5's «inline» exception: a link inside a sentence (its paragraph has more words than the link).
            // A 44 px tap area there would swallow the lines above and below it.
            .filter((el) => ! (el.tagName === 'A' && el.parentElement?.tagName === 'P' && el.parentElement.innerText.trim().length > el.innerText.trim().length + 10))
            // A checkbox or radio is tapped through its label row: measure what the thumb lands on.
            .map((el) => ({ el: label(el), tag: el.tagName.toLowerCase(), cls: (el.className?.baseVal ?? el.className ?? '').toString().split(/\s+/).filter((c) => c.startsWith('fi-')).slice(0, 3).join('.'), ...tapOf(el.matches('input[type=checkbox], input[type=radio]') ? (el.closest('label') ?? el) : el) }))
            .filter((t) => t.w < 44 || t.h < 44);
    }, TAP);
    for (const f of found) small.push({ path, ...f });
}
const byKind = {};
for (const s of small) { const k = `${s.tag}.${s.cls}`; (byKind[k] ??= []).push(`${s.path} «${s.el}» ${s.w}×${s.h}`); }
for (const [k, list] of Object.entries(byKind)) console.log(`  under 44: ${k} ×${list.length} — e.g. ${list.slice(0, 3).join(' | ')}`);
check(`the sweep: no visible interactive element under 44 px on ${PAGES.length} panel pages`, small.length === 0, `${small.length} found`);
await context.close();

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
