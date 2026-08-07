// Prompt 205 — the bar and the hub, before and after, plus the measurement the branch owes.
//
//   npm run build
//   php artisan test tests/Browser/CounterHomeHarnessTest.php   # → storage/app/counter-home.html
//   node tests/Browser/shoot-counter-hub.mjs                    # → storage/app/screenshots/205/
//
// Asserts what a picture cannot: the tap count for Recepción → Dispensario (the loop a shift is made of),
// AA contrast on the hero tile and every rail figure, no control under 44×44, and no horizontal page scroll.

import { chromium } from 'playwright';
import { mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const OUT = 'storage/app/screenshots/205';
const SIZES = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];

await mkdir(OUT, { recursive: true });

const file = resolve('storage/app/counter-home.html');
try { await access(file); } catch { console.error('FAIL: run the harness first'); process.exit(1); }

const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };
const rows = [];

// --- contrast, with the colours RESOLVED BY THE BROWSER ------------------------------------------
//
// The first draft of this parsed `getComputedStyle().color` with a `/\d+/g` regex, which is wrong twice over
// on a Tailwind v4 page: colours come back as `oklch(0.968 0.007 247.896)` and `oklab(... / 0.8)`, so the
// regex read a lightness of 0.968 as a red channel of 0.968/255 and produced 1.10:1 for near-white on
// near-black (really ~15:1) and 4.06:1 for 80% white on brand blue. **Both numbers were the instrument, not
// the page** — one a false alarm, one a false reassurance.
//
// So the resolution happens in the browser, on a canvas, which converts any CSS colour to sRGB and composites
// alpha over the background for us. Nothing is parsed here.
const CONTRAST_IN_PAGE = `(fg, bg) => {
  const c = document.createElement('canvas'); c.width = c.height = 1;
  const ctx = c.getContext('2d', { willReadFrequently: true });
  const px = (colour, under) => {
    ctx.clearRect(0, 0, 1, 1);
    if (under) { ctx.fillStyle = under; ctx.fillRect(0, 0, 1, 1); }
    ctx.fillStyle = colour; ctx.fillRect(0, 0, 1, 1);
    return Array.from(ctx.getImageData(0, 0, 1, 1).data).slice(0, 3);
  };
  const lum = ([r, g, b]) => {
    const f = (v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
  };
  const bgPx = px(bg, 'white');
  const fgPx = px(fg, bg);            // composite any alpha over the real background first
  const [hi, lo] = [lum(fgPx), lum(bgPx)].sort((a, b) => b - a);
  return (hi + 0.05) / (lo + 0.05);
}`;

for (const size of SIZES) {
  for (const theme of ['light', 'dark']) {
    for (const motion of ['reduce', 'no-preference']) {
      const page = await browser.newPage({
        viewport: { width: size.width, height: size.height },
        colorScheme: theme,
        reducedMotion: motion,
      });
      await page.goto(pathToFileURL(file).href);

      // Static capture: no Alpine, so every x-show element would render visible. Hide them, as the other
      // shooters do, so this is what an operator actually sees.
      await page.evaluate(() => {
        document.querySelectorAll('[x-show], [x-cloak]').forEach((el) => { el.style.display = 'none' });
      });
      await page.waitForTimeout(120);

      if (motion === 'reduce') {
        const tiles = await page.$$eval('[data-counter-home-tile]', (n) =>
          n.map((el) => { const r = el.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height) } }));
        const barControls = await page.$$eval(
          '[data-counter-topbar] a, [data-counter-topbar] button',
          (n) => n.map((el) => { const r = el.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height) } })
            .filter((r) => r.w > 0 && r.h > 0));
        const hScroll = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);

        // Contrast: the hero (white on brand) and every rail figure against its card.
        const samples = await page.$$eval(
          '[data-counter-home-hero] span, [data-counter-home-rail] [data-figure], [data-counter-home-tile] span',
          (nodes, src) => {
            const ratio = eval(src);
            return nodes.filter((el) => el.textContent.trim() !== '' && el.children.length === 0).map((el) => {
              let bg = getComputedStyle(el).backgroundColor, p = el;
              while ((bg === 'rgba(0, 0, 0, 0)' || bg === 'transparent') && p.parentElement) {
                p = p.parentElement; bg = getComputedStyle(p).backgroundColor;
              }
              return { text: el.textContent.trim().slice(0, 24), ratio: ratio(getComputedStyle(el).color, bg) };
            });
          },
          CONTRAST_IN_PAGE,
        );

        const worst = samples.reduce((acc, s) => Math.min(acc, s.ratio), Infinity);
        if (process.env.DEBUG_CONTRAST) {
          for (const x of samples) console.log(`   ${size.name}/${theme}  ${x.ratio.toFixed(2)}:1  "${x.text}"`);
        }
        rows.push({ size: size.name, theme, tiles: tiles.length, bar: barControls.length, worstContrast: worst.toFixed(2), hScroll });

        if (tiles.length === 0) fail(`${size.name}/${theme}: no tiles — nothing was audited`);
        for (const t of tiles) if (t.h < 44 || t.w < 44) fail(`${size.name}/${theme}: a tile is ${t.w}x${t.h}`);
        for (const c of barControls) if (c.h < 44 || c.w < 44) fail(`${size.name}/${theme}: a bar control is ${c.w}x${c.h}`);
        if (hScroll) fail(`${size.name}/${theme}: the page scrolls horizontally`);
        // AA is 4.5:1 for body text; the hero's label is 24px bold, which is large text (3:1). Held to 4.5.
        if (worst < 4.5) fail(`${size.name}/${theme}: contrast ${worst.toFixed(2)}:1 — under AA`);
      }

      await page.screenshot({ path: `${OUT}/hub-${size.name}-${theme}-${motion}.png`, fullPage: true });
      await page.close();
    }
  }
}

// --- THE LOOP A SHIFT IS MADE OF ------------------------------------------------------------------
// Recepción → Dispensario, counted honestly. It is TWO taps now (Home, then the Dispensary tile) where the
// tab strip made it one. That is the trade the owner accepted, and it is written down rather than implied.
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
await page.goto(pathToFileURL(file).href);
const homeLink = await page.$('[data-counter-topbar] [data-counter-home-link]');
const dispensaryTile = await page.$('[data-counter-home-tile="counter.pos"]');
const taps = (homeLink ? 1 : 0) + (dispensaryTile ? 1 : 0);
console.log(`\nRecepción → Dispensario: ${taps} taps (Home in the bar, then the Dispensario tile on the hub)`);
if (taps !== 2) fail(`expected the loop to be 2 taps, measured ${taps}`);
await page.close();

console.table(rows);
await browser.close();
console.log(failed ? 'RESULT: FAIL' : 'RESULT: PASS');
process.exit(failed ? 1 : 0);
