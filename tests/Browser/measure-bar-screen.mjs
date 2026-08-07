// Prompt 193 — the numbers behind "the list view is not a list".
//
// Counts article rows per viewport, measures the first row, checks no name wraps, and measures how much of
// the cart column sits below its own fold. Run before and after; the row count is the argument.
//
//   npm run build && php artisan test tests/Browser/BarScreenHarnessTest.php
//   node tests/Browser/measure-bar-screen.mjs

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';

const LAYOUT = process.argv[2] ?? 'list';
const url = pathToFileURL(resolve(`storage/app/bar-screen-${LAYOUT}.html`)).href;
const VIEWPORTS = [
  { name: '1180x820  (tablet landscape)', width: 1180, height: 820 },
  { name: '820x1180  (tablet portrait) ', width: 820, height: 1180 },
  { name: '1440x900  (desktop)         ', width: 1440, height: 900 },
  { name: '1280x720  (short laptop)    ', width: 1280, height: 720 },
];

const OUT = 'storage/app/screenshots/193';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();

console.log(`--- ${LAYOUT} layout ---`);
for (const vp of VIEWPORTS) {
  const page = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });
  await page.goto(url);
  // No Alpine in a static capture, so every x-show element would render VISIBLE — including the offline
  // banner, which is 54px and shifts everything below it. Prompt 175's script hides them for the same
  // reason; measuring without this reports a commit button below the fold that is not.
  await page.addStyleTag({ content: '[x-show]{display:none !important}[data-counter-surface]{display:none !important}' });
  await page.waitForTimeout(150);

  const shot = `${OUT}/bar-${LAYOUT}-${vp.width}x${vp.height}.png`;
  await page.screenshot({ path: shot, fullPage: false });

  const m = await page.evaluate(() => {
    const rows = [...document.querySelectorAll('[data-product]')];
    const vh = window.innerHeight;
    const visible = rows.filter((r) => { const b = r.getBoundingClientRect(); return b.top < vh && b.bottom > 0; });
    const first = rows[0]?.getBoundingClientRect();

    // A name wraps if its rendered height exceeds a single line-height.
    let wrapped = 0;
    for (const r of rows) {
      const n = r.querySelector('[data-product-name]') ?? r.querySelector('span');
      if (!n) continue;
      const lh = parseFloat(getComputedStyle(n).lineHeight) || 20;
      if (n.getBoundingClientRect().height > lh * 1.4) wrapped++;
    }

    const cart = document.querySelector('[data-cart-column]');
    const scroller = cart?.querySelector('.overflow-y-auto');
    const commit = document.querySelector('[data-commit-action]');
    const cb = commit?.getBoundingClientRect();

    // How far is the outcome from the control that produces it?
    const flash = document.querySelector('[data-commit-feedback]') ?? document.querySelector('[wire\\:key="flash"]');
    const fb = flash?.getBoundingClientRect();

    return {
      total: rows.length,
      visible: visible.length,
      firstRow: first ? `${Math.round(first.width)}x${Math.round(first.height)}` : null,
      wrapped,
      cartHidden: scroller ? Math.round(scroller.scrollHeight - scroller.clientHeight) : null,
      commitTop: cb ? Math.round(cb.top) : null,
      commitVisible: cb ? cb.bottom <= vh + 1 : null,
      flashDistance: (fb && cb) ? Math.round(Math.abs(cb.top - fb.top)) : null,
      under44: [...document.querySelectorAll('[data-product]')].filter((e) => e.getBoundingClientRect().height < 44).length,
    };
  });

  console.log(`${vp.name}: ${m.visible}/${m.total} rows on screen · first ${m.firstRow} · names wrapped ${m.wrapped} · ` +
    `cart hides ${m.cartHidden}px · commit top ${m.commitTop} (visible ${m.commitVisible}) · ` +
    `outcome ${m.flashDistance === null ? 'n/a' : m.flashDistance + 'px'} from it · under-44 rows ${m.under44}`);

  await page.close();
}
await browser.close();
