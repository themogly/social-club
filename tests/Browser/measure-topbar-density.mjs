// Prompt 189 — "cramped" with a number.
//
// measure-topbar.mjs (prompt 132) asks whether anything OVERLAPS or falls under 44px. It passes, and passed
// before this branch — which is exactly why it could not see the problem the owner reported. Overlap is the
// failure state; cramped is the state just before it. This measures DENSITY instead:
//
//   used   = the width the row's controls actually occupy, including the gaps between them
//   slack  = how much of the bar is left over
//   scroll = whether the destination strip has overflowed into its own scroller (it is overflow-x-auto,
//            so it degrades silently rather than colliding — the row looks fine and starts hiding things)
//
// Run it against the harness before and after; the comparison is the argument.
//   npm run build && php artisan test tests/Browser/TopbarHarnessTest.php
//   node tests/Browser/measure-topbar-density.mjs

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const harness = pathToFileURL(resolve('storage/app/topbar-harness.html')).href;
const VIEWPORTS = [
  { name: '1180x820 (landscape)', width: 1180, height: 820 },
  { name: '820x1180 (portrait)', width: 820, height: 1180 },
];

const browser = await chromium.launch();

for (const vp of VIEWPORTS) {
  const page = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });
  await page.goto(harness);
  await page.waitForTimeout(120);

  const m = await page.evaluate(() => {
    const bar = document.querySelector('[data-counter-topbar]');
    const cs = getComputedStyle(bar);
    const inner = bar.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    const nav = bar.querySelector('nav');

    // The strip is flex-1: it absorbs whatever the fixed blocks leave, so a total always reads 100%. The
    // honest number is the SPLIT — how much of the row the fixed furniture claims, and how much is left for
    // the destinations, against the minimum they need (one 44px target each, plus the 4px gaps).
    const fixed = [...bar.children]
      .filter((c) => c !== nav && c.getBoundingClientRect().width > 0)
      .reduce((sum, c) => sum + c.getBoundingClientRect().width, 0);
    const stripHas = nav ? nav.clientWidth : 0;
    const items = nav ? nav.children.length : 0;
    const stripNeeds = items > 0 ? items * 44 + (items - 1) * 4 : 0;

    return {
      inner: Math.round(inner),
      fixed: Math.round(fixed),
      stripHas: Math.round(stripHas),
      stripNeeds,
      items,
      headroom: Math.round(stripHas - stripNeeds),
      labelled: nav ? [...nav.children].some((c) => c.querySelector('span:not([aria-hidden])')?.offsetWidth > 0) : false,
      pageScrolls: document.documentElement.scrollWidth > document.documentElement.clientWidth,
    };
  });

  const claimed = ((m.fixed / m.inner) * 100).toFixed(0);
  console.log(
    `${vp.name}: furniture ${m.fixed}px (${claimed}% of ${m.inner}) · ` +
    `strip gets ${m.stripHas}px for ${m.items} destinations, needs >=${m.stripNeeds}px · ` +
    `headroom ${m.headroom}px · labels shown: ${m.labelled} · page scrolls: ${m.pageScrolls}`
  );

  await page.close();
}

await browser.close();
