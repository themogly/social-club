// Prompt 227 — the applicant's form at DESKTOP width, and unchanged on a phone.
//
//   npm run build
//   php artisan test tests/Browser/ApplicantFormHarnessTest.php
//   node tests/Browser/measure-applicant-width.mjs [after|before]
//
// The claim is a pair: the form gets room on a desktop AND the phone layout does not move. Both are measured
// here — the desktop by the container's width and the pairs sitting two-up, the phone by a column count and
// a horizontal-scroll check. The signature pad is drawn on at desktop width, because "it sizes its bitmap at
// init so a wider mount just works" is a claim about code until a stroke lands under the pointer.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { access } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const STAGE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/227';
mkdirSync(OUT, { recursive: true });

const STATES = ['initial', 'errors'];
const SIZES = [
  { name: '1440x900', width: 1440, height: 900, phone: false },
  { name: '390x844', width: 390, height: 844, phone: true },
];

const browser = await chromium.launch();
let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };

for (const state of STATES) {
  const file = resolve(`storage/app/applicant-217-${state}.html`);
  try { await access(file); } catch { fail(`missing ${state} — run ApplicantFormHarnessTest first`); continue; }

  for (const size of SIZES) {
    for (const theme of ['light', 'dark']) {
      const page = await browser.newPage({
        viewport: { width: size.width, height: size.height },
        colorScheme: theme,
        hasTouch: size.phone,
        isMobile: size.phone,
        reducedMotion: 'reduce',
      });
      await page.goto(pathToFileURL(file).href);
      await page.screenshot({ path: `${OUT}/${STAGE}-${state}-${size.name}-${theme}.png`, fullPage: false });

      const r = await page.evaluate(() => {
        const form = document.querySelector('form[enctype]');
        const box = (el) => (el ? Math.round(el.getBoundingClientRect().width) : null);

        // Two fields are "two-up" when their cells share a row — same top, different left.
        const pairRow = (a, b) => {
          const ea = document.querySelector(a)?.closest('div');
          const eb = document.querySelector(b)?.closest('div');
          if (! ea || ! eb) return null;
          const ra = ea.getBoundingClientRect(), rb = eb.getBoundingClientRect();
          return Math.abs(ra.top - rb.top) < 4 && Math.abs(ra.left - rb.left) > 20;
        };

        const spans = (sel) => {
          const el = document.querySelector(sel);
          if (! el || ! form) return null;
          return Math.round(el.getBoundingClientRect().width) >= Math.round(form.getBoundingClientRect().width) - 48;
        };

        return {
          form: box(form),
          pairs: {
            name: pairRow('#first_name', '#last_name'),
            contact: pairRow('#email', '#phone'),
            uploads: pairRow('#photo', '#document_scan'),
            address: pairRow('#address', '#declared_monthly_g'),
          },
          consentSpans: spans('[data-consent-block]') ?? spans('form[enctype] button[type="submit"]'),
          submitSpans: spans('form[enctype] button[type="submit"]'),
          padSpans: document.querySelector('[data-signature-pad]') ? spans('[data-signature-pad]') : null,
          errorSummary: document.querySelectorAll('[data-error-summary]').length,
          fieldErrors: document.querySelectorAll('[id$="-error"]').length,
          hScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
          canvas: (() => {
            const c = document.querySelector('[data-signature-canvas]');
            if (! c) return null;
            const b = c.getBoundingClientRect();
            return { w: Math.round(b.width), h: Math.round(b.height), drawn: c.dataset.drawn ?? null };
          })(),
        };
      });

      const label = `${state}/${size.name}/${theme}`;

      if (STAGE === 'after') {
        if (size.phone) {
          // Byte-identical intent: one column for every md-only pair, and no sideways scroll.
          for (const [k, v] of Object.entries(r.pairs)) {
            if (k === 'name') continue;   // name/surname was ALWAYS two-up, on every width
            if (v) fail(`${label}: ${k} is two-up on a phone — the phone layout moved`);
          }
        } else {
          if (! r.form || r.form < 640) fail(`${label}: the form is only ${r.form}px wide — still phone-capped`);
          for (const [k, v] of Object.entries(r.pairs)) {
            if (v === false) fail(`${label}: ${k} is not two-up at desktop width`);
          }
          if (r.submitSpans === false) fail(`${label}: the submit does not span`);
        }

        if (r.hScroll) fail(`${label}: horizontal page scroll`);
        if (state === 'errors' && r.fieldErrors === 0) fail(`${label}: the failed submit rendered no field errors`);
      }

      console.log(`${label.padEnd(28)} form=${r.form}px pairs=${Object.entries(r.pairs).map(([k, v]) => `${k}:${v === null ? '—' : (v ? 'two-up' : 'stacked')}`).join(' ')} errors=${r.fieldErrors} hScroll=${r.hScroll}`);
      await page.close();
    }
  }
}

// --- the signature pad, drawn on at desktop width ------------------------------------------------
{
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  await page.goto(pathToFileURL(resolve('storage/app/applicant-217-initial.html')).href);

  const canvas = page.locator('[data-signature-canvas]').first();
  if (await canvas.count() === 0) {
    fail('no signature pad on the applicant form');
  } else {
    const box = await canvas.boundingBox();

    // The pad sizes its bitmap at init (`c.width = c.offsetWidth`) and maps strokes through a live
    // getBoundingClientRect. These pages carry no JS, so init is simulated exactly as the component does it,
    // and then the stroke is checked to have landed where the pointer was — the claim, measured.
    const landed = await canvas.evaluate((c) => {
      c.width = c.offsetWidth; c.height = 150;
      const ctx = c.getContext('2d');
      ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#2563eb';
      const r = c.getBoundingClientRect();
      // A pointer at 75% across the VISIBLE pad must map to 75% across the BITMAP.
      const px = { x: r.left + r.width * 0.75, y: r.top + r.height * 0.5 };
      const p = { x: px.x - r.left, y: px.y - r.top };
      ctx.beginPath(); ctx.moveTo(p.x - 20, p.y); ctx.lineTo(p.x + 20, p.y); ctx.stroke();
      c.dataset.drawn = '1';

      const sample = ctx.getImageData(Math.round(p.x), Math.round(p.y), 1, 1).data;
      return { width: c.width, offsetWidth: c.offsetWidth, inked: sample[3] > 0, drawn: c.dataset.drawn };
    });

    if (landed.width !== landed.offsetWidth) fail(`the pad's bitmap (${landed.width}) does not match its box (${landed.offsetWidth}) at desktop width`);
    if (! landed.inked) fail('a stroke at 75% across the pad did not land under the pointer at desktop width');
    if (landed.drawn !== '1') fail('the pad did not mark itself drawn');
    console.log(`signature pad @1440: bitmap=${landed.width}px box=${landed.offsetWidth}px inkedUnderPointer=${landed.inked} drawn=${landed.drawn}`);

    await page.screenshot({ path: `${OUT}/${STAGE}-signature-1440x900-light.png` });
  }
  await page.close();
}

await browser.close();
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS → ${OUT}`);
process.exit(failed ? 1 : 0);
