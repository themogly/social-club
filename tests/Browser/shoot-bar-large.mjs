// Prompt 248 — proof on the REAL standalone Bar that the third layout works: the ≡ / ▦ / ⬜ toggle switches
// to large, the pane goes category-first (big tiles) then large article tiles, commit stays reachable, and the
// toggle itself clears 44×44 with its label. Records tile heights at both tablet orientations, light and dark.
//
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DevAdminSeeder
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/shoot-bar-large.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, SEDE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/248';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const results = [];

for (const vp of [{ w: 1280, h: 800, tag: 'land' }, { w: 800, h: 1280, tag: 'port' }]) {
  for (const theme of ['light', 'dark']) {
    const context = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, colorScheme: theme });
    const page = await context.newPage();
    const label = `${theme} ${vp.tag}`;

    if (! await signInToCounter(page, '/counter/bar', { sede: SEDE })) {
      results.push([`${label}: sign in`, false]); await context.close(); continue;
    }
    await page.evaluate(() => document.fonts?.ready).catch(() => {}); // 233 — measure once the real font is in

    // Compact grid (the default) — for the before/after pair.
    await page.click('[data-layout-option="grid"]').catch(() => {});
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/bar-grid-${theme}-${vp.tag}.png` });

    // The toggle itself: ≥ 44×44 with a label.
    const toggle = await page.$('[data-layout-option="large"]');
    const box = toggle ? await toggle.boundingBox() : null;
    const labelled = toggle ? await toggle.getAttribute('aria-label') : null;
    results.push([`${label}: large toggle ≥44×44 + label`, !!box && box.width >= 44 && box.height >= 44 && !!labelled]);

    // Switch to large — category-first tiles then large article tiles.
    await toggle?.click();
    await page.waitForTimeout(400);
    const catTiles = await page.$('[data-category-tiles]');
    const tile = await page.$('[data-article-card]');
    const tileBox = tile ? await tile.boundingBox() : null;
    results.push([`${label}: category tiles present`, !!catTiles]);
    results.push([`${label}: article tile ≥120px tall`, !!tileBox && tileBox.height >= 120]);

    // Commit stays reachable without scrolling the column (176's geometry rule).
    const commit = await page.$('[data-commit-action]');
    const commitBox = commit ? await commit.boundingBox() : null;
    results.push([`${label}: commit reachable`, !!commitBox && commitBox.top < vp.h]);

    await page.screenshot({ path: `${OUT}/bar-large-${theme}-${vp.tag}.png` });
    await context.close();
  }
}

await browser.close();
let ok = true;
for (const [text, pass] of results) { console.log(`${pass ? 'PASS' : 'FAIL'}  ${text}`); ok = ok && pass; }
process.exitCode = ok ? 0 : 1;
