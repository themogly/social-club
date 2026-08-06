// Prompt 176 — the argument for the branch as one picture: Barra at 1180x820, before and after, with the
// charge button's position marked. Run AFTER measure-cart-column.mjs, and only once the "before"
// artifacts exist (see tests/Browser/README.md for how to capture them against an older commit).
//
//   node tests/Browser/compose-cart-before-after.mjs
//
// Writes storage/app/screenshots/176/coldstart-before-after-barra.png

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';

const OUT = 'storage/app/screenshots/176';
mkdirSync(OUT, { recursive: true });

const HIDE = '[x-show]{display:none !important}[data-counter-surface]{display:none !important}';
const COMMIT = '[data-commit-action], button[wire\\:click="commit"]';
const VP = { width: 1180, height: 820 };


const dataUri = (p) => `data:image/png;base64,${readFileSync(resolve(p)).toString('base64')}`;

const browser = await chromium.launch();

async function shoot(file, label, out) {
  if (!existsSync(file)) {
    console.error(`missing ${file} — see the README for capturing the "before" side`);
    return null;
  }
  const page = await browser.newPage({ viewport: VP, reducedMotion: 'reduce' });
  await page.goto(pathToFileURL(resolve(file)).href);
  await page.addStyleTag({ content: HIDE });
  await page.waitForTimeout(150);

  const m = await page.evaluate((sel) => {
    const el = document.querySelector(sel);
    const r = el?.getBoundingClientRect();
    return r ? { top: Math.round(r.top), bottom: Math.round(r.bottom), h: window.innerHeight } : null;
  }, COMMIT);

  // Mark the fold, and the action if it is beyond it — the whole claim in one frame.
  await page.addStyleTag({
    content: `body::after{content:'${label}';position:fixed;left:0;top:0;z-index:9999;background:#0f172a;color:#fff;font:600 13px Inter,sans-serif;padding:4px 10px}`,
  });
  await page.screenshot({ path: out, fullPage: false });
  await page.close();
  return m;
}

const before = await shoot('storage/app/before-bar-full.html', 'BEFORE — Cobrar is below the fold', `${OUT}/barra-before-1180x820.png`);
const after = await shoot('storage/app/cart-bar-full.html', 'AFTER — Cobrar is on screen', `${OUT}/barra-after-1180x820.png`);

if (before && after) {
  const page = await browser.newPage({ viewport: { width: 2400, height: 900 } });
  await page.setContent(`
    <div style="display:flex;gap:16px;padding:16px;background:#f8fafc;font:14px Inter,sans-serif">
      <figure style="margin:0"><img src="${dataUri(`${OUT}/barra-before-1180x820.png`)}" width="1160">
        <figcaption style="padding-top:8px;font-weight:600;color:#dc2626">BEFORE — Cobrar at y=${before.top}–${before.bottom}, fold at ${before.h} (${before.bottom - before.h}px below)</figcaption></figure>
      <figure style="margin:0"><img src="${dataUri(`${OUT}/barra-after-1180x820.png`)}" width="1160">
        <figcaption style="padding-top:8px;font-weight:600;color:#16a34a">AFTER — Cobrar at y=${after.top}–${after.bottom}, fold at ${after.h} (on screen)</figcaption></figure>
    </div>`);
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${OUT}/barra-before-after-1180x820.png`, fullPage: true });
  await page.close();
  console.log(`before: Cobrar ${before.top}-${before.bottom}, fold ${before.h}  →  ${before.bottom - before.h}px below`);
  console.log(`after : Cobrar ${after.top}-${after.bottom}, fold ${after.h}  →  on screen`);
  console.log(`composed ${OUT}/barra-before-after-1180x820.png`);
}

await browser.close();
