// Prompt 207 — the rail with alerts on it, and the screen each one lands on with the subject visible.
//
//   npm run build
//   php artisan test tests/Browser/AlertsHarnessTest.php   # → storage/app/alerts-207-*.html
//   node tests/Browser/shoot-alerts-land.mjs
//
// Also asserts what a picture cannot: that the hub's rail items are real links with real hrefs (not click
// handlers on a <p>), that every worklist row clears the 44×44 floor, and no horizontal page scroll.

import { chromium } from 'playwright';
import { mkdir, access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const OUT = 'storage/app/screenshots/207';
const PAGES = ['hub', 'expiring', 'applications'];
const SIZES = [
  { name: '1180x820', width: 1180, height: 820 },
  { name: '820x1180', width: 820, height: 1180 },
];

await mkdir(OUT, { recursive: true });

const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

for (const name of PAGES) {
  const file = resolve(`storage/app/alerts-207-${name}.html`);
  try { await access(file); } catch { fail(`missing harness page ${name}`); continue; }

  for (const size of SIZES) {
    for (const theme of ['light', 'dark']) {
      const page = await browser.newPage({
        viewport: { width: size.width, height: size.height },
        colorScheme: theme,
        reducedMotion: 'reduce',
      });
      await page.goto(pathToFileURL(file).href);

      // Static capture: no Alpine, so x-show/x-cloak elements would all render. Hide them, as the other
      // shoot scripts in this folder do, or the lock surface covers the page.
      await page.evaluate(() => {
        document.querySelectorAll('[x-show],[x-cloak],[data-counter-surface]').forEach((n) => { n.style.display = 'none'; });
      });

      await page.screenshot({ path: `${OUT}/${name}-${size.name}-${theme}.png`, fullPage: true });

      const report = await page.evaluate(() => {
        const alerts = Array.from(document.querySelectorAll('[data-alert]'));
        const rows = Array.from(document.querySelectorAll('[data-worklist-member], [data-alta-pending] button'));
        return {
          alerts: alerts.length,
          alertsThatAreLinks: alerts.filter((a) => a.tagName === 'A' && a.getAttribute('href')).length,
          rows: rows.length,
          tooSmall: rows
            .map((r) => r.getBoundingClientRect())
            .filter((b) => b.width > 0 && (b.height < 44 || b.width < 44)).length,
          hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        };
      });

      if (report.hScroll) fail(`${name}/${size.name}/${theme}: horizontal page scroll`);
      if (report.tooSmall > 0) fail(`${name}/${size.name}/${theme}: ${report.tooSmall} row(s) under 44×44`);
      if (name === 'hub' && report.alerts !== report.alertsThatAreLinks) {
        fail(`${name}/${size.name}/${theme}: ${report.alerts - report.alertsThatAreLinks} alert(s) are not real links`);
      }
      if (name !== 'hub' && report.rows === 0) fail(`${name}/${size.name}/${theme}: the arrival showed no subjects`);

      console.log(`${name} ${size.name} ${theme}: ${report.alerts} alerts (${report.alertsThatAreLinks} linked), ${report.rows} subjects, hScroll=${report.hScroll}`);
      await page.close();
    }
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : '\nRESULT: ALL PASS');
process.exit(failed ? 1 : 0);
