// Prompt 354 — every panel list screen on an iPhone (Playwright devices['iPhone 15'], 393×852), as the owner, light and
// dark. Freshly seeded demo DB (DBFILE), plus one row in every list the demo leaves empty, so all 26 are measured.
// For each list, on its first row:
//   · the pinned (sticky) cell is no wider than one ⋮ trigger plus padding (≤ 72 px);
//   · the first data column is fully visible — the pinned cell starts right of it;
//   · a table with no row-actions cell pins nothing (no data column is ever pinned).
// Run with SWEEP_ONLY=1 to measure and print without screenshots (the before-state on old code).
import { chromium, devices } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/354';
const DB = process.env.DBFILE;
const MAX_PIN = 72;
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { env: { ...process.env, DB_DATABASE: DB } }).toString().trim().split('\n').pop();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };

// One row in each list the demo seed leaves empty — through each model's factory, scoped to the demo club.
console.log('seeded:', tinker(`
  $org = App\\Models\\Organisation::query()->orderBy('created_at')->value('id');
  $loc = App\\Models\\Location::query()->withoutGlobalScopes()->where('name', 'Central Branch')->value('id');
  app(App\\Support\\ActiveScope::class)->setOrganisation($org); app(App\\Support\\ActiveScope::class)->setLocation($loc);
  $member = App\\Models\\Member::query()->withoutGlobalScopes()->value('id');
  $made = [];
  foreach ([App\\Models\\Announcement::class, App\\Models\\BreachLog::class, App\\Models\\Convocatoria::class, App\\Models\\DataRequest::class,
            App\\Models\\DocumentTemplate::class, App\\Models\\Event::class, App\\Models\\MemberDocument::class, App\\Models\\MessageThread::class,
            App\\Models\\Minute::class, App\\Models\\Purchase::class, App\\Models\\Supplier::class, App\\Models\\MemberApplication::class] as $m) {
      if (! in_array($m, [App\\Models\\Supplier::class, App\\Models\\MemberApplication::class], true) && $m::query()->withoutGlobalScopes()->exists()) continue;
      $fill = (new $m)->getFillable();
      $attrs = array_intersect_key(['organisation_id' => $org, 'location_id' => $loc, 'member_id' => $member], array_flip($fill));
      try { $m::factory()->create($attrs); $made[] = class_basename($m); } catch (Throwable $e) { $made[] = class_basename($m).'!'.substr($e->getMessage(), 0, 60); }
  }
  echo implode(',', $made);`));

const SLUGS = 'announcements articles audit-logs batches breach-logs convocatorias data-requests discounts dispensations document-templates events expense-categories expenses genetics locations member-applications member-documents members membership-tiers message-threads minutes orders purchases suppliers till-sessions users'.split(' ');

const browser = await chromium.launch();
const rows = [];
for (const scheme of process.env.SWEEP_ONLY ? ['dark'] : ['dark', 'light']) {
    const context = await browser.newContext({ ...devices['iPhone 15'], colorScheme: scheme });
    const page = await context.newPage();
    await signIn(page, { account: 'owner' });
    for (const slug of SLUGS) {
        await page.goto(`${BASE}/${slug}`, { waitUntil: 'networkidle' });
        const m = await page.evaluate(() => {
            const table = document.querySelector('.fi-ta-table');
            const row = table?.querySelector(':scope > tbody > tr');
            if (!row) return { empty: true };
            const cells = [...row.children];
            const sticky = cells.filter((c) => getComputedStyle(c).position === 'sticky');
            const actionsCell = cells.find((c) => c.querySelector(':scope > .fi-ta-actions'));
            const dataCells = cells.filter((c) => c !== actionsCell && !c.classList.contains('fi-ta-selection-cell'));
            // What the first column SHOWS — the right edge of its text, not its padded box (Batches' «Lote» box runs
            // under the ⋮ by its own padding while every word stays clear of it).
            const textRight = (cell) => {
                if (!cell) return null;
                const rects = [];
                const walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT);
                for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                    if (!node.textContent.trim()) continue;
                    const range = document.createRange();
                    range.selectNodeContents(node);
                    rects.push(...[...range.getClientRects()].filter((r) => r.width > 0));
                }
                return rects.length ? { right: Math.max(...rects.map((r) => r.right)) } : null;
            };
            const first = textRight(dataCells[0]);
            const pin = sticky[0]?.getBoundingClientRect();
            return {
                pinned: sticky.length,
                pinWidth: pin ? Math.round(pin.width) : 0,
                pinsData: sticky.some((c) => c !== actionsCell),
                firstVisible: !pin || !first || first.right <= pin.left + 1,
                actions: actionsCell ? actionsCell.querySelector(':scope > .fi-ta-actions').children.length : 0,
            };
        });
        rows.push({ scheme, slug, ...m });
        const ok = m.empty ? false : (m.pinWidth <= MAX_PIN && m.firstVisible && !m.pinsData);
        check(`${scheme} /${slug}`, ok, m.empty ? 'NO ROWS' : `pinned ${m.pinWidth}px · actions ${m.actions}${m.pinsData ? ' · PINS A DATA COLUMN' : ''}${m.firstVisible ? '' : ' · FIRST COLUMN COVERED'}`);
        if (!process.env.SWEEP_ONLY && ['member-applications', 'members', 'dispensations'].includes(slug)) {
            await page.screenshot({ path: `${OUT}/${slug}-393-${scheme}.png` });
        }
    }
    await context.close();
}
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
