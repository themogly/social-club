// Accessibility sweep — runs axe-core over every significant page of the product, authenticated, in both
// themes, at a desktop and a tablet width.
//
// Automated results are a STARTING CHECKLIST, not the audit: axe catches roughly a third of real WCAG
// problems and produces some findings that are wrong in context. Every violation this prints is verified by
// hand before it reaches the report.
//
// It needs a RUNNING SERVER and the dev seed:
//   php artisan migrate --seed          # DevAdminSeeder + DemoDataSeeder
//   npm run build && php artisan serve --port=8123
//   npm install --no-save @axe-core/playwright
//   node tests/Browser/axe-sweep.mjs [--json out.json]
//
// Exits non-zero if any serious/critical violation is found.

import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import { writeFileSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8123';
const EMAIL = process.env.AUDIT_EMAIL ?? 'owner@club.test';
const PASSWORD = process.env.AUDIT_PASSWORD ?? 'password';
const PIN = process.env.AUDIT_PIN ?? '1234';
const jsonAt = process.argv.includes('--json') ? process.argv[process.argv.indexOf('--json') + 1] : null;

// Every page type, not every record: one of each SHAPE. Filament resources share their form/table
// components, so an index + a create + an edit of one resource covers the rest — the ones listed
// separately are the pages with hand-written Blade of their own.
const PAGES = [
  // --- unauthenticated ---
  { url: '/login', name: 'login', auth: false },
  { url: '/socio/login', name: 'socio-login', auth: false },

  // --- the counter (tablet-first, hand-written Blade + Livewire) ---
  { url: '/counter', name: 'counter-home' },
  { url: '/counter/checkin', name: 'counter-checkin' },
  { url: '/counter/pos', name: 'counter-pos' },
  { url: '/counter/bar', name: 'counter-bar' },
  { url: '/counter/till', name: 'counter-till' },
  { url: '/counter/members', name: 'counter-members' },

  // The counter screens above are audited as an operator first meets them — which, by prompt 175's chain, is
  // a blocking state. The WORKING screens only exist after an interaction, and they are where the real
  // density is (member card, gauge, basket, tender), so they are driven and audited too.
  { url: '/counter/checkin', name: 'counter-checkin-resolved', drive: 'identify' },
  { url: '/counter/pos', name: 'counter-pos-resolved', drive: 'identify' },
  { url: '/counter/members', name: 'counter-members-resolved', drive: 'identify' },
  { url: '/counter/bar', name: 'counter-bar-basket', drive: 'basket' },

  // --- the admin panel (Filament) ---
  { url: '/', name: 'dashboard' },
  { url: '/members', name: 'members-index' },
  { url: '/members/create', name: 'members-create' },
  { url: '/genetics', name: 'genetics-index' },
  { url: '/batches', name: 'batches-index' },
  { url: '/dispensations', name: 'dispensations-index' },
  { url: '/till-sessions', name: 'till-sessions-index' },
  { url: '/expenses', name: 'expenses-index' },
  { url: '/announcements/create', name: 'announcements-create' },
  { url: '/audit-logs', name: 'audit-logs-index' },
  { url: '/manage-settings', name: 'settings' },
  { url: '/informes/financiero', name: 'report-financial' },
  { url: '/informes/consumo', name: 'report-consumption' },
  { url: '/asamblea', name: 'asamblea' },
  { url: '/ayuda/manual', name: 'help-manual' },
  { url: '/ayuda/glosario', name: 'help-glossary' },
];

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1024', width: 1024, height: 768 }, // the counter's tablet width
];
const THEMES = ['light', 'dark'];

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();

// --- sign in once; the context keeps the session cookie for every page below --------------------
await page.goto(`${BASE}/login`);
await page.fill('input[type="email"]', EMAIL);
await page.fill('input[type="password"]', PASSWORD);
await page.press('input[type="password"]', 'Enter');
await page.waitForLoadState('networkidle');

if (page.url().includes('/login')) {
  console.error(`FATAL: could not sign in as ${EMAIL}. Is the dev seed loaded?`);
  process.exit(2);
}

// The counter screens sit behind prompt 175's chain: choose a sede → identify with a PIN → open a till.
// Clear all three ONCE here, or every counter page is audited as a blocking state and the real screens —
// which is where the density actually is — are never seen at all. (The first run of this script did exactly
// that: all six counter pages reported 18 controls, which was the sede chooser six times.)
const SEDE = process.env.AUDIT_SEDE ?? 'Central Branch'; // the seeded sede that has an open caja
await page.goto(`${BASE}/counter`);
await page.waitForLoadState('networkidle');

// The switcher opens ITSELF when a sede must be chosen, so click straight into the open menu.
const sede = await page.$(`[data-counter-sede-menu] form button:has-text("${SEDE}")`);
if (sede) {
  await sede.click();
  await page.waitForLoadState('networkidle');
}

const pinPad = await page.$('[data-counter-surface] [x-ref="pinPad"], [data-counter-surface-unlock]');
if (pinPad) {
  for (const digit of PIN) {
    await page.click(`[data-counter-surface] button:has-text("${digit}")`).catch(() => {});
  }
  await pinPad.click().catch(() => {});
  await page.waitForTimeout(1200);
}

const findings = [];
const landings = [];
let serious = 0;

for (const spec of PAGES) {
  for (const vp of VIEWPORTS) {
    for (const theme of THEMES) {
      const ctx = await browser.newContext({
        viewport: { width: vp.width, height: vp.height },
        colorScheme: theme,
        reducedMotion: 'reduce',
        // The signed-out pages get a CLEAN context. Reusing the signed-in session sent /login straight to
        // the dashboard, so the first sweep audited the dashboard twice and the login screen never once.
        ...(spec.auth === false ? {} : { storageState: await context.storageState() }),
      });
      const p = await ctx.newPage();

      try {
        await p.goto(`${BASE}${spec.url}`, { waitUntil: 'networkidle', timeout: 20000 });
      } catch {
        console.error(`SKIP ${spec.name} @ ${vp.name}/${theme}: navigation timed out`);
        await ctx.close();
        continue;
      }

      // Alpine/Livewire need a beat to boot, or x-cloak elements are still in their pre-boot state.
      await p.waitForTimeout(600);

      if (spec.drive === 'identify' || spec.drive === 'basket') {
        // The ONE member lookup (prompt 194): type, Enter, take the first row.
        const lookup = await p.$('#member-lookup');
        if (lookup) {
          await lookup.fill(process.env.AUDIT_MEMBER_QUERY ?? 'M-');
          await lookup.press('Enter');
          await p.waitForTimeout(1000);
          const row = await p.$('[data-member-lookup-result]');
          if (row) {
            await row.click();
            await p.waitForTimeout(1200);
          }
        }
      }

      if (spec.drive === 'basket') {
        // Put something in the bar basket so the tender side of the cart is on screen.
        const article = await p.$('[data-product], [data-article]');
        if (article) {
          await article.click();
          await p.waitForTimeout(900);
        }
      }

      // Prove the page is the page. A sweep that silently audited a redirect, a 403 or the PIN surface
      // would report "no violations" and mean nothing — the exact shape of green-certifies-nothing this
      // project keeps meeting. Recorded per render and printed at the end.
      const landed = await p.evaluate(() => ({
        url: location.pathname,
        title: document.title,
        h1: document.querySelector('h1, [data-surface-heading]')?.textContent?.trim().slice(0, 40) ?? '(none)',
        controls: document.querySelectorAll('a[href], button, input, select, textarea').length,
        surface: document.querySelector('[data-counter-surface]')?.getAttribute('data-surface-mode') ?? null,
        blocker: document.querySelector('[data-counter-blocker]')?.getAttribute('data-blocker') ?? null,
      }));
      landings.push({ page: spec.name, at: `${vp.name}/${theme}`, ...landed });

      const results = await new AxeBuilder({ page: p })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'])
        .analyze()
        .catch((e) => ({ violations: [], error: String(e) }));

      for (const v of results.violations ?? []) {
        if (v.impact === 'serious' || v.impact === 'critical') serious++;
        findings.push({
          page: spec.name,
          url: spec.url,
          viewport: vp.name,
          theme,
          id: v.id,
          impact: v.impact,
          help: v.help,
          nodes: v.nodes.length,
          sample: v.nodes.slice(0, 3).map((n) => ({
            target: n.target.join(' '),
            html: (n.html ?? '').slice(0, 220),
            summary: (n.failureSummary ?? '').replace(/\s+/g, ' ').slice(0, 300),
          })),
        });
      }

      await ctx.close();
    }
  }
  process.stdout.write('.');
}

await browser.close();
console.log('\n');

// --- what each page actually WAS when it was audited -------------------------------------------
const seen = new Map();
for (const l of landings) {
  if (!seen.has(l.page)) seen.set(l.page, l);
}
console.log('Pages as audited:');
console.table([...seen.values()].map((l) => ({ page: l.page, landedOn: l.url, h1: l.h1, controls: l.controls, surface: l.surface ?? '—', blocker: l.blocker ?? '—' })));

// --- collapse to one row per (page, rule) so the same finding across 4 renders reads as one -----
const byRule = new Map();
for (const f of findings) {
  const key = `${f.id}::${f.page}`;
  const existing = byRule.get(key);
  if (existing) {
    existing.renders.add(`${f.viewport}/${f.theme}`);
    existing.nodes = Math.max(existing.nodes, f.nodes);
  } else {
    byRule.set(key, { ...f, renders: new Set([`${f.viewport}/${f.theme}`]) });
  }
}

const rows = [...byRule.values()].sort(
  (a, b) => ['critical', 'serious', 'moderate', 'minor'].indexOf(a.impact) - ['critical', 'serious', 'moderate', 'minor'].indexOf(b.impact)
);

console.table(rows.map((r) => ({ impact: r.impact, rule: r.id, page: r.page, nodes: r.nodes, renders: r.renders.size, help: r.help.slice(0, 60) })));

if (jsonAt) {
  writeFileSync(jsonAt, JSON.stringify(rows.map((r) => ({ ...r, renders: [...r.renders] })), null, 2));
  console.log(`\nFull detail → ${jsonAt}`);
}

console.log(`\n${rows.length} distinct (rule × page) findings · ${serious} serious/critical occurrences`);
process.exit(serious > 0 ? 1 : 0);
