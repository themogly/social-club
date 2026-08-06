// Prompt 178 — photographs the public application form's optional ID upload at PHONE width, in both
// locales. Phone width because that is where an applicant actually opens an emailed invite link.
//
//   npm run build
//   php artisan test tests/Browser/ApplicationFormHarnessTest.php
//   node tests/Browser/shoot-application-form.mjs        → storage/app/screenshots/178/

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';

const OUT = 'storage/app/screenshots/178';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
let failed = false;

for (const locale of ['es', 'en']) {
  for (const theme of ['light', 'dark']) {
    const page = await browser.newPage({
      viewport: { width: 390, height: 844 }, // an ordinary phone, which is where the invite link is opened
      colorScheme: theme,
      reducedMotion: 'reduce',
    });
    await page.goto(pathToFileURL(resolve(`storage/app/application-form-${locale}.html`)).href);
    await page.addStyleTag({ content: '[x-show]{display:none !important}' });
    await page.waitForTimeout(150);

    const field = await page.$('#document_scan');
    if (!field) {
      console.error(`FAIL ${locale}: no #document_scan on the form`);
      failed = true;
      await page.close();
      continue;
    }

    await field.scrollIntoViewIfNeeded();
    await page.waitForTimeout(100);
    await page.screenshot({ path: `${OUT}/application-upload-${locale}-${theme}-390.png` });

    const m = await page.evaluate(() => {
      const el = document.querySelector('#document_scan');
      const r = el.getBoundingClientRect();
      const help = el.parentElement.querySelector('p');
      return {
        w: Math.round(r.width), h: Math.round(r.height),
        required: el.hasAttribute('required'),
        help: (help?.textContent || '').trim().slice(0, 70),
        hScroll: document.documentElement.scrollWidth > window.innerWidth + 1,
      };
    });

    if (m.required) { console.error(`FAIL ${locale}: the upload is marked required`); failed = true; }
    if (m.hScroll) { console.error(`FAIL ${locale}: the form scrolls sideways at 390px`); failed = true; }
    console.log(`${locale}/${theme}: ${m.w}x${m.h}, required=${m.required} — "${m.help}…"`);

    await page.close();
  }
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS — captures in ${OUT}`);
process.exit(failed ? 1 : 0);
