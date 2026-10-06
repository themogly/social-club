// Prompt 362 — on a phone, the pinned ⋮ must not sit over the FIRST data column's text (the one read at the left edge,
// 354's sweep), on every panel list screen. Columns further right scroll under the pin by design (the table scrolls).
//   BASE_URL=http://127.0.0.1:8360 node tests/Browser/measure-362-pin.mjs [path …]
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/362';
mkdirSync(OUT, { recursive: true });
// 354's 26 list screens (prove-354-phone-tables.mjs), or the paths given.
const SLUGS = 'announcements articles audit-logs batches breach-logs convocatorias data-requests discounts dispensations document-templates events expense-categories expenses genetics locations member-applications member-documents members membership-tiers message-threads minutes orders purchases suppliers till-sessions users'.split(' ');
const PATHS = process.argv.slice(2).length ? process.argv.slice(2) : SLUGS.map((s) => `/${s}`);
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 393, height: 852 }, hasTouch: true, isMobile: true, deviceScaleFactor: 3 });
const page = await ctx.newPage();
await signIn(page, { account: 'owner' });
let bad = 0;
for (const path of PATHS) {
  await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' });
  const r = await page.evaluate(() => {
    const out = { rows: 0, overlaps: [] };
    for (const tr of document.querySelectorAll('.fi-ta-table > tbody > tr')) {
      const pinned = tr.querySelector(':scope > td:last-child');
      if (!pinned || getComputedStyle(pinned).position !== 'sticky') continue;
      out.rows++;
      const left = pinned.getBoundingClientRect().left;
      const first = [...tr.querySelectorAll(':scope > td:not(:last-child)')].find((td) => !td.classList.contains('fi-ta-selection-cell'));
      for (const td of first ? [first] : []) {
        const walker = document.createTreeWalker(td, NodeFilter.SHOW_TEXT);
        let n;
        while ((n = walker.nextNode())) {
          if (!n.textContent.trim()) continue;
          const range = document.createRange(); range.selectNodeContents(n);
          for (const rect of range.getClientRects()) {
            if (rect.width > 0 && rect.right > left + 0.5 && rect.left < left) out.overlaps.push(`${n.textContent.trim().slice(0, 30)} ends ${Math.round(rect.right)} > ⋮ ${Math.round(left)}`);
          }
        }
      }
    }
    return out;
  });
  bad += r.overlaps.length;
  console.log(`${r.overlaps.length ? 'FAIL' : 'PASS'} ${path}: ${r.rows} pinned rows${r.overlaps.length ? ' — ' + r.overlaps.slice(0, 3).join('; ') : ''}`);
  if (path === '/batches') await page.screenshot({ path: `${OUT}/batches-393.png` });
}
await browser.close();
process.exit(bad ? 1 : 0);
