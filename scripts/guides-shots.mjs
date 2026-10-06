// Prompt 353 — `npm run guides:shots`: regenerate the guides' screenshots from `resources/guides/shots.json`.
//
// Run it against a site seeded with `php artisan csc:seed-staging` (the fictional demo club, prompt 327) — never live:
//   BASE_URL=http://127.0.0.1:8000 npm run guides:shots              (every recipe)
//   BASE_URL=… npm run guides:shots -- counter-quick-start/05         (only the files whose name starts with that)
//   GUIDES_SHOTS_OUT=/some/dir …                                       (write there instead, to compare before replacing)
// Sign-ins go through the browser harness (tests/Browser/counter-session.mjs — the one place its accounts live). Each image
// is written as a JPEG at 1600 px wide, the size the guides use. An image with no recipe is left alone (DECISIONS.md lists
// them).
import { chromium } from 'playwright';
import { readFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { BASE, signIn, signInToCounter } from '../tests/Browser/counter-session.mjs';

const manifest = JSON.parse(readFileSync('resources/guides/shots.json', 'utf8'));
const only = process.argv[2] ?? '';
const WIDTH = 1600;
const OUT = process.env.GUIDES_SHOTS_OUT ?? 'resources/guides/img';
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };

async function openTill(page) {
    await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
    const float = page.locator('input[wire\\:model="floatInput"]');
    if (await float.count()) {
        await float.fill('100');
        await page.click('form[wire\\:submit="open"] button[type="submit"]');
        await settle(page);
    }
}

// The dispensary remembers the member between visits, so «choose M-00001» only types when the search is showing.
async function chooseMember(page, memberNo) {
    if (!await page.locator('#member-lookup').isVisible().catch(() => false)) return;
    await page.fill('#member-lookup', memberNo);
    await page.press('#member-lookup', 'Enter');
    await settle(page);
    await page.locator('[data-member-lookup-result]').first().click();
    await settle(page);
}

async function run(page, step) {
    if (step.goto) await page.goto(`${BASE}${step.goto}`, { waitUntil: 'networkidle' });
    if (step.click) await page.locator(step.click).first().click();
    // Prompt 360 — every match, one at a time (e.g. «Can't count it» on each jar of the evening count).
    if (step.clickAll) {
        for (let i = 0; i < await page.locator(step.clickAll).count(); i++) {
            await page.locator(step.clickAll).nth(i).click();
            await settle(page);
        }
    }
    if (step.fill) await page.fill(step.fill[0], step.fill[1]);
    if (step.press) await page.press(step.press[0], step.press[1]);
    if (step.wait) await page.waitForTimeout(step.wait);
    await settle(page);
}

const browser = await chromium.launch();
// One sign-in per account (and per locked/unlocked), reused by every recipe: signing in again for each shot runs into the
// login rate limit, and the next page is then the login screen.
const sessions = new Map();
async function contextFor(recipe, options) {
    const key = `${recipe.account}|${recipe.locked ? 'locked' : recipe.panel ? 'panel' : recipe.sede ?? 'Central Branch'}`;
    if (!sessions.has(key)) {
        const context = await browser.newContext(options);
        const page = await context.newPage();
        if (recipe.locked || recipe.panel) await signIn(page, { account: recipe.account });
        else await signInToCounter(page, '/counter', { account: recipe.account, sede: recipe.sede ?? 'Central Branch' });
        sessions.set(key, await context.storageState());
        await context.close();
    }
    return browser.newContext({ ...options, storageState: sessions.get(key) });
}

let written = 0;
let failed = 0;
for (const [file, recipe] of Object.entries(manifest.shots)) {
    if (!file.startsWith(only)) continue;
    const [w, h] = recipe.viewport ?? [1180, 820];
    const context = await contextFor(recipe, { viewport: { width: w, height: h }, deviceScaleFactor: WIDTH / w, colorScheme: recipe.dark ? 'dark' : 'light' });
    const page = await context.newPage();
    try {
        if (recipe.openTill && !recipe.locked && !recipe.panel) await openTill(page);
        await page.goto(`${BASE}${recipe.url}`, { waitUntil: 'networkidle' });
        await settle(page);
        const lang = await page.evaluate(() => document.documentElement.lang);
        if (manifest.locale && !lang.startsWith(manifest.locale)) {
            throw new Error(`the page is in "${lang}", the guides show "${manifest.locale}" — set the accounts' language first (see "about")`);
        }
        if (recipe.member) await chooseMember(page, recipe.member);
        for (const step of recipe.steps ?? []) await run(page, step);

        const out = `${OUT}/${file}`;
        mkdirSync(dirname(out), { recursive: true });
        const target = recipe.crop ? page.locator(recipe.crop).first() : page;
        await target.screenshot({ path: out, type: 'jpeg', quality: 82 });
        written++;
        console.log(`wrote ${out}`);
    } catch (e) {
        failed++;
        console.error(`FAILED ${file}: ${e.message.split('\n')[0]}`);
    }
    await context.close();
}
await browser.close();
console.log(`${written} written, ${failed} failed`);
process.exit(failed === 0 && written > 0 ? 0 : 1);
