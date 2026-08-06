// Prompt 180 — photographs the Salud del sistema page, light and dark, showing the backup section replaced.
//
//   npm run build
//   php artisan test tests/Browser/BackupSlotHarnessTest.php
//   node tests/Browser/shoot-backup-slot.mjs      → storage/app/screenshots/180/

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';

const OUT = 'storage/app/screenshots/180';
mkdirSync(OUT, { recursive: true });

const RETIRED = ['Sin configurar', 'Pendiente de conectar', 'Última copia', 'Última restauración'];

const browser = await chromium.launch();
let failed = false;

for (const theme of ['light', 'dark']) {
  const page = await browser.newPage({
    viewport: { width: 1440, height: 1000 },
    colorScheme: theme,
    reducedMotion: 'reduce',
  });
  await page.goto(pathToFileURL(resolve('storage/app/system-health.html')).href);
  // A Filament panel page is server-rendered but its shell is Alpine-driven, and Alpine is not running in a
  // static capture — so the main region stays cloaked and the page photographs as an empty topbar. Reveal it.
  // (The counter harnesses do the OPPOSITE, hiding `x-show` elements: there, Alpine would hide them on boot.
  // The rule is the same either way — reproduce what the operator actually sees.)
  await page.addStyleTag({
    content: `[x-cloak]{display:revert !important}
              .fi-main,.fi-page,.fi-main-ctn,main{display:block !important;visibility:visible !important;opacity:1 !important}
              .fi-sidebar{display:none !important}
              .fi-dropdown-panel,[role="menu"]{display:none !important}`,
  });
  await page.waitForTimeout(250);

  const section = await page.evaluateHandle(() =>
    [...document.querySelectorAll('section, div')].find((el) =>
      el.textContent?.includes('Copias de seguridad') && el.textContent.length < 400)
  );
  const el = section.asElement();
  if (el) await el.scrollIntoViewIfNeeded();
  await page.waitForTimeout(120);
  await page.screenshot({ path: `${OUT}/system-health-${theme}-1440.png`, fullPage: true });

  const text = await page.evaluate(() => document.body.innerText);
  for (const phrase of RETIRED) {
    if (text.includes(phrase)) {
      console.error(`FAIL ${theme}: the page still says "${phrase}"`);
      failed = true;
    }
  }
  if (!text.includes('Copias de seguridad')) {
    console.error(`FAIL ${theme}: the section heading is missing entirely`);
    failed = true;
  }
  console.log(`${theme}: heading present, none of the retired claims rendered`);
  await page.close();
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS — captures in ${OUT}`);
process.exit(failed ? 1 : 0);
