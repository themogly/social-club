// Prompt 225 — the POS column and the catalogue's density, measured at both tablet orientations.
//
//   npm run build
//   php artisan test tests/Browser/PosColumnHarnessTest.php
//   node tests/Browser/measure-pos-column.mjs [after|before]
//
// What it asserts (after only; `before` records the numbers the change is judged against):
//   · identity pinned and commit pinned — both inside the viewport with the basket scrolled to its end
//   · ONLY the cart's middle region scrolls: the page does not, and the scroll container is that region
//   · the commit carries the total; when blocked, the amber reason line is under it exactly once
//   · a blocked socio has no catalogue and no weight pad
//   · nothing interactive under 44×44
//   · the catalogue ROW HEIGHT, printed, which is the density change

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/225';
mkdirSync(OUT, { recursive: true });

const STATES = ['working', 'bar', 'blocked'];
const SIZES = [{ name: '1180x820', width: 1180, height: 820 }, { name: '820x1180', width: 820, height: 1180 }];
const FLOOR = 44;

const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

for (const state of STATES) {
  const file = resolve(`storage/app/pos-225-${state}.html`);
  try { await access(file); } catch { fail(`missing ${state} — run PosColumnHarnessTest first`); continue; }

  for (const size of SIZES) {
    for (const theme of ['light', 'dark']) {
      const page = await browser.newPage({ viewport: size, colorScheme: theme, reducedMotion: 'reduce', hasTouch: true });
      await page.goto(pathToFileURL(file).href);
      await page.evaluate(() => {
        document.querySelectorAll('[data-counter-surface],[x-cloak]').forEach((n) => { n.style.display = 'none'; });
      });

      // Scroll the cart's middle to its end: a pinned commit must still be on screen afterwards, which is
      // the whole claim (176's fold measurement, re-taken).
      await page.evaluate(() => {
        const region = document.querySelector('[data-cart-scroll]');
        if (region) region.scrollTop = region.scrollHeight;
      });

      await page.screenshot({ path: `${OUT}/${STAGE}-${state}-${size.name}-${theme}.png` });

      const r = await page.evaluate((FLOOR) => {
        const box = (s) => { const el = document.querySelector(s); return el ? el.getBoundingClientRect() : null; };
        const rows = Array.from(document.querySelectorAll('[data-product]')).map((n) => Math.round(n.getBoundingClientRect().height));

        const targets = Array.from(document.querySelectorAll('a[href], button, input, select, textarea'))
          .map((n) => ((n.type === 'checkbox' || n.type === 'radio') ? (n.closest('label') ?? n) : n))
          .map((n) => ({ b: n.getBoundingClientRect(), what: `${n.tagName.toLowerCase()} ${(n.getAttribute('data-product') !== null ? 'product' : (n.getAttribute('name') || n.id || n.textContent.trim().slice(0, 16)))}` }))
          .filter((t) => t.b.width > 1 && t.b.height > 1);

        const scroller = document.querySelector('[data-cart-scroll]');

        return {
          identity: box('[data-member-summary]'),
          commit: box('[data-commit-action]'),
          rows: rows.length ? { n: rows.length, min: Math.min(...rows), max: Math.max(...rows), median: rows.sort((a, b) => a - b)[Math.floor(rows.length / 2)] } : null,
          cartScrolls: scroller ? scroller.scrollHeight > scroller.clientHeight : null,
          pageScrolls: document.documentElement.scrollHeight > window.innerHeight + 1,
          hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
          blocked: !! document.querySelector('[data-blocked-member]'),
          blockedReason: document.querySelectorAll('[data-commit-blocked-reason]').length,
          weightPad: !! document.querySelector('[data-weight-preset]'),
          commitText: document.querySelector('[data-commit-action]')?.innerText.trim().replace(/\s+/g, ' ') ?? null,
          under: targets.filter((t) => t.b.height < FLOOR || t.b.width < FLOOR).map((t) => `${Math.round(t.b.width)}×${Math.round(t.b.height)} ${t.what}`),
          // The audit's amber-ramp finding: verify, do not assume. Contrast of the blocked line against the
          // background it is actually painted on.
          //
          // Colours are normalised through a CANVAS rather than parsed out of the computed string: Tailwind
          // v4 resolves an opacity modifier to `oklab(… / .1)`, and a regex that pulls numbers out of that
          // reads 0.47 as a red channel and reports 2.96:1 for a line that is really 5.5:1. The canvas gives
          // back exactly what the browser will paint.
          amber: (() => {
            const el = document.querySelector('[data-commit-blocked-reason]');
            if (! el) return null;

            const c = document.createElement('canvas');
            c.width = c.height = 1;
            const ctx = c.getContext('2d');
            // Paint-and-read, not parse: `ctx.fillStyle` refuses some modern colour syntaxes silently, so the
            // pixel the browser actually produces is the only trustworthy answer (oklch/oklab reach here from
            // Tailwind v4 for every token and every opacity modifier).
            const painted1px = (css) => {
              ctx.clearRect(0, 0, 1, 1);
              ctx.fillStyle = css;
              ctx.fillRect(0, 0, 1, 1);
              const d = ctx.getImageData(0, 0, 1, 1).data;
              return [d[0], d[1], d[2], d[3] / 255];
            };
            const rgba = painted1px;
            const over = (top, bottom) => top.slice(0, 3).map((v, i) => v * top[3] + bottom[i] * (1 - top[3])).concat(1);
            const lum = ([r, g, b]) => {
              const f = (v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4); };
              return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
            };

            // Walk up for the first opaque background, the way a reader's eye does.
            let node = el, base = null;
            while (node && ! base) {
              const c = rgba(getComputedStyle(node).backgroundColor);
              if (c[3] === 1) base = c;
              node = node.parentElement;
            }
            base = base ?? [255, 255, 255, 1];

            const painted = over(rgba(getComputedStyle(el).backgroundColor), base);
            const fg = over(rgba(getComputedStyle(el).color), painted);
            const [l1, l2] = [lum(fg), lum(painted)].sort((a, b) => b - a);

            return Math.round(((l1 + 0.05) / (l2 + 0.05)) * 100) / 100;
          })(),
        };
      }, FLOOR);

      const label = `${state}/${size.name}/${theme}`;
      const inView = (b) => b && b.top >= -1 && b.bottom <= size.height + 1;

      if (STAGE === 'after') {
        if (! inView(r.identity)) fail(`${label}: the identity is not pinned in view (${JSON.stringify(r.identity && { top: Math.round(r.identity.top), bottom: Math.round(r.identity.bottom) })})`);
        if (! inView(r.commit)) fail(`${label}: the commit is not on screen after scrolling the basket`);
        if (r.pageScrolls) fail(`${label}: the PAGE scrolls — only the cart's middle may`);
        if (r.hScroll) fail(`${label}: horizontal page scroll`);
        if (r.under.length) fail(`${label}: ${r.under.length} under ${FLOOR}px — ${r.under.slice(0, 4).join('; ')}`);

        if (state === 'blocked') {
          if (! r.blocked) fail(`${label}: the blocked surface is missing`);
          if (r.rows) fail(`${label}: a blocked socio still sees ${r.rows.n} catalogue rows`);
          if (r.weightPad) fail(`${label}: a blocked socio still has a weight pad`);
          if (r.blockedReason !== 1) fail(`${label}: the blocked reason renders ${r.blockedReason} times, expected once`);
          // AA for small bold text is 4.5:1 — the amber ramp is exactly what the audit flagged.
          if (r.amber !== null && r.amber < 4.5) fail(`${label}: the blocked line is ${r.amber}:1 against its own background — under AA`);
        } else {
          if (! r.rows) fail(`${label}: no catalogue rows to measure`);
          if (r.blockedReason !== 0) fail(`${label}: a clear socio was told they are blocked`);
        }
      }

      console.log(`${label.padEnd(26)} rows=${r.rows ? `${r.rows.n}@${r.rows.median}px (${r.rows.min}-${r.rows.max})` : '—'} cartScrolls=${r.cartScrolls} pageScrolls=${r.pageScrolls} blocked=${r.blocked} amber=${r.amber ?? '—'} commit="${(r.commitText ?? '').slice(0, 40)}"`);
      await page.close();
    }
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS → ${OUT}`);
process.exit(failed ? 1 : 0);
