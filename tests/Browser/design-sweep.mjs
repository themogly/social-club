// Design audit — viewport-sized captures of every page type across the full size range.
//
// Viewport-sized only, never fullPage: the audit brief is explicit, and a full-page capture hides exactly
// the defect it is looking for (what actually fits above the fold at a short laptop height).
//
// It needs a RUNNING SERVER and the dev seed:
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/design-sweep.mjs [--only counter|admin|socio]
//
// Writes to storage/app/screenshots/design/<page>/<viewport>-<theme>.png and prints a layout table:
// page height vs viewport, horizontal overflow, and any element wider than the viewport.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8123';
const EMAIL = 'owner@club.test';
const PASSWORD = 'password';
const PIN = '1234';
const SEDE = 'Central Branch';
const only = process.argv.includes('--only') ? process.argv[process.argv.indexOf('--only') + 1] : null;

// A RANGE, not two widths — the in-between sizes are where layouts overfitted to 1440/390 break, and the
// short laptop height is where "it fits" stops being true.
const VIEWPORTS = [
  { name: '1440x900', width: 1440, height: 900 },
  { name: '1280x800', width: 1280, height: 800 },
  { name: '1024x768', width: 1024, height: 768 },
  { name: '1440x560', width: 1440, height: 560 }, // short laptop
  { name: '390x844', width: 390, height: 844 },
];

const PAGES = [
  { group: 'counter', name: 'counter-home', url: '/counter' },
  { group: 'counter', name: 'counter-checkin', url: '/counter/checkin', drive: 'identify' },
  { group: 'counter', name: 'counter-pos', url: '/counter/pos', drive: 'identify' },
  { group: 'counter', name: 'counter-bar', url: '/counter/bar' },
  { group: 'counter', name: 'counter-till', url: '/counter/till' },
  { group: 'counter', name: 'counter-members', url: '/counter/members' },
  { group: 'admin', name: 'dashboard', url: '/' },
  { group: 'admin', name: 'members-index', url: '/members' },
  { group: 'admin', name: 'members-create', url: '/members/create' },
  { group: 'admin', name: 'genetics-index', url: '/genetics' },
  { group: 'admin', name: 'settings', url: '/manage-settings' },
  { group: 'admin', name: 'report-financial', url: '/informes/financiero' },
  { group: 'admin', name: 'breach-logs-empty', url: '/breach-logs' },
  { group: 'admin', name: 'help-manual', url: '/ayuda/manual' },
  { group: 'admin', name: 'asamblea', url: '/asamblea' },
  { group: 'auth', name: 'login', url: '/login', auth: false },
  { group: 'auth', name: 'socio-login', url: '/socio/login', auth: false },
];

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();

await page.goto(`${BASE}/login`);
await page.fill('input[type="email"]', EMAIL);
await page.fill('input[type="password"]', PASSWORD);
await page.press('input[type="password"]', 'Enter');
await page.waitForLoadState('networkidle');

// Clear the counter chain once (sede → PIN), or every counter capture is a blocking state.
await page.goto(`${BASE}/counter`);
await page.waitForLoadState('networkidle');
const sede = await page.$(`[data-counter-sede-menu] form button:has-text("${SEDE}")`);
if (sede) { await sede.click(); await page.waitForLoadState('networkidle'); }
const pad = await page.$('[data-counter-surface-unlock]');
if (pad) {
  for (const d of PIN) await page.click(`[data-counter-surface] button:has-text("${d}")`).catch(() => {});
  await pad.click();
  await page.waitForTimeout(1200);
}

const rows = [];

for (const spec of PAGES) {
  if (only && spec.group !== only) continue;
  const dir = `storage/app/screenshots/design/${spec.name}`;
  mkdirSync(dir, { recursive: true });

  for (const vp of VIEWPORTS) {
    for (const theme of ['light', 'dark']) {
      const c = await browser.newContext({
        viewport: { width: vp.width, height: vp.height },
        colorScheme: theme,
        reducedMotion: 'reduce',
        ...(spec.auth === false ? {} : { storageState: await ctx.storageState() }),
      });
      const p = await c.newPage();

      try {
        await p.goto(`${BASE}${spec.url}`, { waitUntil: 'networkidle', timeout: 20000 });
      } catch { await c.close(); continue; }
      await p.waitForTimeout(700);

      if (spec.drive === 'identify') {
        const lookup = await p.$('#member-lookup');
        if (lookup) {
          await lookup.fill('M-');
          await lookup.press('Enter');
          await p.waitForTimeout(900);
          const row = await p.$('[data-member-lookup-result]');
          if (row) { await row.click(); await p.waitForTimeout(1400); }
        }
      }

      const m = await p.evaluate(() => {
        const doc = document.documentElement;
        const wide = [...document.querySelectorAll('body *')]
          .filter((el) => {
            const r = el.getBoundingClientRect();
            return r.width > window.innerWidth + 2 && r.height > 0 && getComputedStyle(el).position !== 'fixed';
          })
          .slice(0, 3)
          .map((el) => `${el.tagName.toLowerCase()}.${(el.className || '').toString().split(' ').slice(0, 2).join('.')} ${Math.round(el.getBoundingClientRect().width)}px`);

        return {
          pageH: Math.round(doc.scrollHeight),
          viewportH: window.innerHeight,
          hScroll: doc.scrollWidth > window.innerWidth + 1,
          wide,
          // Anything below 44x44 that a finger or a pointer is meant to hit.
          small: [...document.querySelectorAll('button:not([disabled]), a[href], [role="button"]')]
            .filter((n) => {
              const r = n.getBoundingClientRect();
              const s = getComputedStyle(n);
              if (r.width === 0 || r.height === 0 || s.display === 'none' || s.visibility === 'hidden') return false;
              return r.width < 24 || r.height < 24;
            })
            .slice(0, 3)
            .map((n) => `${(n.innerText || n.getAttribute('aria-label') || '?').trim().slice(0, 18)} ${Math.round(n.getBoundingClientRect().width)}x${Math.round(n.getBoundingClientRect().height)}`),
        };
      });

      await p.screenshot({ path: `${dir}/${vp.name}-${theme}.png`, fullPage: false });

      if (theme === 'light') {
        rows.push({
          page: spec.name,
          viewport: vp.name,
          pageH: m.pageH,
          screens: (m.pageH / m.viewportH).toFixed(1) + '×',
          hScroll: m.hScroll ? 'YES' : '',
          overflowing: m.wide.join(' | '),
          under24: m.small.join(' | '),
        });
      }

      await c.close();
    }
  }
  process.stdout.write('.');
}

await browser.close();
console.log('\n');
console.table(rows);
const bad = rows.filter((r) => r.hScroll || r.overflowing);
console.log(bad.length ? `\n${bad.length} render(s) with horizontal overflow — see the table.` : '\nNo horizontal overflow anywhere.');
