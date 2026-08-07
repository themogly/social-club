// Prompt 208 — the hub's hero tile, before and after.
//
//   npm run build
//   php artisan test tests/Browser/CounterHomeHarnessTest.php   # → storage/app/counter-home.html
//   node tests/Browser/shoot-hero-tile.mjs after                # (or `before`, from a stashed tree)
//
// Asserts what a picture cannot: exactly ONE hero, every reachable destination rendered exactly once, the
// hero substantially larger than the rest, nothing under 44×44 and no horizontal page scroll.

import { chromium } from 'playwright';
import { mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/208';
const SIZES = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];

await mkdir(OUT, { recursive: true });
const file = resolve('storage/app/counter-home.html');
try { await access(file); } catch { console.error('FAIL: run CounterHomeHarnessTest first'); process.exit(1); }

const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

for (const size of SIZES) {
  for (const theme of ['light', 'dark']) {
    const page = await browser.newPage({
      viewport: { width: size.width, height: size.height },
      colorScheme: theme,
      reducedMotion: 'reduce',
    });
    await page.goto(pathToFileURL(file).href);
    await page.evaluate(() => {
      document.querySelectorAll('[x-show],[x-cloak],[data-counter-surface]').forEach((n) => { n.style.display = 'none'; });
    });

    await page.screenshot({ path: `${OUT}/${STAGE}-${size.name}-${theme}.png`, fullPage: true });

    const r = await page.evaluate(() => {
      const tiles = Array.from(document.querySelectorAll('[data-counter-home-tile]'));
      const hero = document.querySelector('[data-counter-home-hero]');
      const routes = tiles.map((t) => t.getAttribute('data-counter-home-tile'));
      const box = (el) => { const b = el.getBoundingClientRect(); return { w: b.width, h: b.height }; };
      return {
        heroes: document.querySelectorAll('[data-counter-home-hero]').length,
        heroRoute: hero?.getAttribute('data-counter-home-tile') ?? null,
        heroArea: hero ? box(hero).w * box(hero).h : 0,
        maxOtherArea: Math.max(0, ...tiles.filter((t) => t !== hero).map((t) => box(t).w * box(t).h)),
        duplicated: routes.filter((v, i) => routes.indexOf(v) !== i),
        tiles: tiles.length,
        tooSmall: tiles.map(box).filter((b) => b.h < 44 || b.w < 44).length,
        clipped: tiles.some((t) => t.scrollWidth > t.clientWidth + 1),
        hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      };
    });

    if (r.heroes !== 1) fail(`${size.name}/${theme}: ${r.heroes} heroes`);
    if (r.duplicated.length) fail(`${size.name}/${theme}: rendered twice — ${r.duplicated.join(', ')}`);
    if (r.heroArea <= r.maxOtherArea) fail(`${size.name}/${theme}: the hero is not larger than the other tiles`);
    if (r.tooSmall) fail(`${size.name}/${theme}: ${r.tooSmall} tile(s) under 44×44`);
    if (r.clipped) fail(`${size.name}/${theme}: a tile is clipped`);
    if (r.hScroll) fail(`${size.name}/${theme}: horizontal page scroll`);

    console.log(`${STAGE} ${size.name} ${theme}: hero=${r.heroRoute} (${Math.round(r.heroArea / r.maxOtherArea * 10) / 10}× the largest other), ${r.tiles} tiles, hScroll=${r.hScroll}`);
    await page.close();
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS (${STAGE})`);
process.exit(failed ? 1 : 0);
