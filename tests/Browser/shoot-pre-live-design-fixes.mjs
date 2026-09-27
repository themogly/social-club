// Prompt 272 — the pre-live design + accessibility fixes, LOOKED AT.
//
// Shoots the counter top bar (on the hub), the POS (member held, a line, the tender after a quick-cash tap), the
// till, the hub and the admin dashboard at 1440 / 1280 / 1024 / 390 plus the two iPad orientations, in light and
// dark, with motion reduced. Also proves two behaviours in a real browser: the PIN pad no longer submits a
// partial PIN when Enter activates a focused key, and the top bar never scrolls sideways.
//
// Needs the dev seed, a built app and a server:
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DevAdminSeeder
//   npm run build && php artisan serve --port=8124
//   BASE_URL=http://127.0.0.1:8124 node tests/Browser/shoot-pre-live-design-fixes.mjs
//
// Signs in ONCE (Filament's login is throttled) and reuses the storage state. Screenshots land in
// storage/app/screenshots/272/.

import { chromium } from 'playwright';
import fs from 'node:fs';
import { BASE, PIN, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/272';
const STATE = 'storage/app/pw-owner-272.json';
fs.mkdirSync(OUT, { recursive: true });

const VIEWPORTS = [
    ['1440', 1440, 900], ['1280', 1280, 800], ['short', 1280, 700], ['1024', 1024, 768],
    ['land', 1180, 820], ['port', 820, 1180], ['390', 390, 844],
];
const SCHEMES = ['light', 'dark'];

const browser = await chromium.launch();
const findings = [];

// Always a fresh sign-in (ONE per run): the harness's own PIN step regenerates the session (a PIN is a
// sign-in, prompt 267), so a state file saved by a previous run is stale.
{
    const ctx = await browser.newContext({ viewport: { width: 1180, height: 820 } });
    const page = await ctx.newPage();
    if (! await signInToCounter(page, '/counter')) {
        console.error('Could not sign in to the counter.');
        process.exit(1);
    }
    await ctx.storageState({ path: STATE });
    await ctx.close();
}

async function context(scheme, width, height) {
    const ctx = await browser.newContext({ viewport: { width, height }, colorScheme: scheme, reducedMotion: 'reduce', storageState: STATE });

    return [ctx, await ctx.newPage()];
}

async function noSidewaysScroll(page, label) {
    const sw = await page.evaluate(() => document.documentElement.scrollWidth);
    const vw = page.viewportSize().width;
    if (sw > vw) findings.push(`${label}: page scrolls sideways (${sw} > ${vw})`);
}

async function topBarIsWhole(page, label) {
    const r = await page.evaluate(() => {
        const box = (s) => document.querySelector(s)?.getBoundingClientRect();
        const home = box('[data-counter-home-link]');
        const panic = box('[data-counter-panic]');

        return { home: home?.width ?? 0, panicRight: panic ? panic.right : 0, vw: innerWidth };
    });
    if (r.home < 120) findings.push(`${label}: home link crushed to ${Math.round(r.home)}px`);
    if (r.panicRight > r.vw) findings.push(`${label}: panic control off screen (${Math.round(r.panicRight)} > ${r.vw})`);
}

async function holdMemberWithLine(page) {
    // The lookup is debounced and live; read the results fresh on every try, never a stale handle.
    for (let attempt = 0; attempt < 5; attempt++) {
        await page.fill('[data-member-lookup]', 'M-0');
        await page.waitForTimeout(1500);
        const result = (await page.$$('[data-member-lookup-result]'))[attempt];
        if (! result) break;
        await result.click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(500);
        if (! await page.$('[data-blocked-member]')) break;
        await page.click('[data-member-summary] button[wire\\:click="clearMember"]').catch(() => {});
        await page.waitForLoadState('networkidle');
    }
    const product = await page.$('[data-product]:not([disabled])');
    if (! product) return false;
    await product.click();
    await page.waitForLoadState('networkidle');
    const preset = await page.$('[data-weight-preset]:not([disabled])');
    if (preset) await preset.click(); else await page.click('button[wire\\:click="pad(\'1\')"]');
    await page.waitForLoadState('networkidle');
    await page.click('button:has-text("Añadir a la cesta"), button:has-text("Add to basket")').catch(() => {});
    await page.waitForLoadState('networkidle');
    await page.click('button[wire\\:click="quickCash(2000)"]').catch(() => {});
    await page.waitForLoadState('networkidle');

    return true;
}

for (const scheme of SCHEMES) {
    for (const [name, width, height] of VIEWPORTS) {
        const [ctx, page] = await context(scheme, width, height);
        const tag = `${name}-${scheme}`;

        await page.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
        await noSidewaysScroll(page, `hub ${tag}`);
        await topBarIsWhole(page, `hub ${tag}`);
        await page.screenshot({ path: `${OUT}/hub-${tag}.png` });
        await page.screenshot({ path: `${OUT}/topbar-${tag}.png`, clip: { x: 0, y: 0, width, height: Math.min(200, height) } });

        await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
        await noSidewaysScroll(page, `till ${tag}`);
        await page.screenshot({ path: `${OUT}/till-${tag}.png` });

        // Recepción with a member held: the door's commit must be on screen (design audit, Phase 2).
        await page.goto(`${BASE}/counter/checkin`, { waitUntil: 'networkidle' });
        await page.fill('[data-member-lookup]', 'M-0');
        await page.waitForTimeout(1500);
        const door = (await page.$$('[data-member-lookup-result]'))[0];
        if (door) {
            await door.click();
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(500);
            await page.screenshot({ path: `${OUT}/checkin-held-${tag}.png` });
            if (width >= 1024 && height >= 800) {
                const bottom = await page.evaluate(() => [...document.querySelectorAll('button')].find((b) => /Registrar entrada|Register entry|Check in/i.test(b.innerText))?.getBoundingClientRect().bottom ?? 0);
                if (bottom > height) findings.push(`checkin ${tag}: Registrar entrada below the fold (${Math.round(bottom)} > ${height})`);
            }
            await page.click('button[wire\\:click="clearMember"]').catch(() => {});
            await page.waitForLoadState('networkidle');
        }

        await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
        await page.screenshot({ path: `${OUT}/pos-empty-${tag}.png` });
        if (await holdMemberWithLine(page)) {
            await noSidewaysScroll(page, `pos ${tag}`);
            await page.screenshot({ path: `${OUT}/pos-tender-${tag}.png` });
            await page.$eval('[data-tender-summary]', (el) => el.scrollIntoView({ block: 'center' })).catch(() => {});
            await page.screenshot({ path: `${OUT}/pos-tender-summary-${tag}.png` });
            // Leave the basket empty for the next viewport.
            await page.click('[data-member-summary] button[wire\\:click="clearMember"]').catch(() => {});
            await page.waitForLoadState('networkidle');
            await page.click('[data-confirm-discard-yes]').catch(() => {});
            await page.waitForLoadState('networkidle');
        } else {
            await page.screenshot({ path: `${OUT}/pos-failed-${tag}.png` });
            findings.push(`pos ${tag}: could not hold a member with a line`);
        }

        if (width >= 1024) {
            await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
            await page.screenshot({ path: `${OUT}/admin-dash-${tag}.png` });
            const two = await page.$('.csc-two');
            if (two) {
                await two.scrollIntoViewIfNeeded();
                await page.screenshot({ path: `${OUT}/admin-dash-tables-${tag}.png` });
                const clipped = await page.$$eval('.csc-table-wrap', (els) => els.filter((e) => e.scrollWidth > e.clientWidth + 1).map((e) => e.getAttribute('aria-label')));
                if (clipped.length) findings.push(`admin dashboard ${tag}: tables scroll/clip: ${clipped.join(', ')}`);
            }
        }

        await ctx.close();
    }
}

// The PIN pad, worked from the keyboard the standard way: Tab to a key, Enter to press it.
{
    const [ctx, page] = await context('dark', 1180, 820);
    await page.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
    await page.click('[data-counter-lock]');
    await page.waitForTimeout(800);
    await page.screenshot({ path: `${OUT}/pinpad-open-dark.png` });
    const focusedIsSurface = await page.evaluate(() => document.activeElement?.hasAttribute('data-counter-surface'));
    if (! focusedIsSurface) findings.push('pin pad: focus did not move into the surface on open');

    for (const digit of PIN.slice(0, 3).split('')) {
        await page.focus(`[data-counter-surface] button:has-text("${digit}")`);
        await page.keyboard.press('Enter');
    }
    await page.waitForTimeout(600);
    const dots = await page.$$eval('[data-counter-surface] .rounded-full.bg-ink', (els) => els.filter((e) => e.offsetParent !== null).length);
    const feedback = await page.$('[data-counter-surface-feedback]');
    if (feedback) findings.push('pin pad: Enter on a focused key submitted a partial PIN');
    if (dots !== 3) findings.push(`pin pad: expected 3 digits after three Enter presses, saw ${dots}`);
    // The last digit from the physical keyboard, then Enter with focus on a pad key activates only that key —
    // so submit with the confirm.
    await page.keyboard.press(PIN.slice(3));
    await page.screenshot({ path: `${OUT}/pinpad-four-digits-dark.png` });
    await page.click('[data-counter-surface-unlock]');
    await page.waitForTimeout(1500);
    const stillOpen = await page.$eval('[data-counter-surface]', (el) => getComputedStyle(el).display !== 'none');
    if (stillOpen) findings.push('pin pad: a correct PIN typed by keyboard did not unlock');
    await ctx.close();
}

await browser.close();

console.log(findings.length ? `FINDINGS:\n- ${findings.join('\n- ')}` : 'No findings: every capture rendered without sideways scroll, crushed home link, off-screen panic or clipped dashboard table; the PIN pad works from the keyboard.');
