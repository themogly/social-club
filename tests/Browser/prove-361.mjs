// Prompt 361 — proof on the running app, with TOUCH at 820×1180 (the club's tablet), of:
//   A. a signature drawn with a finger and never "Guardar" is sent with the form (the link route);
//   K. a refusal keeps the photo and the ID scan, shown as «✓ Foto guardada» with Cambiar, and the next submit stores
//      both without re-attaching;
//   H. the handover sent unsigned asks «¿Enviar sin firma?», and back at the counter the review says «Falta la firma»
//      with Firmar ahora / Seguir sin firma (staff account: the reason buttons; a manager would get one tap);
//   M. the invitation email in /dev/mail carries the plain link.
//
//   Throwaway DB, dev seed, two invites made with tinker (tokens proof361-link-*, proof361-keep-*), then
//   BASE_URL=http://127.0.0.1:8361 PHOTO=… SCAN=… node tests/Browser/prove-361.mjs
//
// Strokes are real touch events (CDP Input.dispatchTouchEvent), not mouse — the pad's touch path is what failed.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, SEDE, signInToCounter, enterPin } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/361';
mkdirSync(OUT, { recursive: true });
const PHOTO = process.env.PHOTO;
const SCAN = process.env.SCAN;
const results = [];
const check = (label, pass, detail = '') => results.push([label, !!pass, detail]);
const browser = await chromium.launch();
const newPage = async (theme) => {
  const ctx = await browser.newContext({ viewport: { width: 820, height: 1180 }, hasTouch: true, isMobile: true, colorScheme: theme });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => check(`page error: ${e.message}`, false));
  return { ctx, page };
};

async function touchDraw(page) {
  const pad = page.locator('[data-signature-canvas]').first();
  await pad.scrollIntoViewIfNeeded();
  const b = await pad.boundingBox();
  const cdp = await page.context().newCDPSession(page);
  const pts = [[0.15, 0.6], [0.3, 0.3], [0.45, 0.7], [0.6, 0.35], [0.8, 0.6]].map(([dx, dy]) => ({ x: b.x + b.width * dx, y: b.y + b.height * dy }));
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [pts[0]] });
  for (let i = 1; i < pts.length; i++) {
    for (let s = 1; s <= 6; s++) {
      const x = pts[i - 1].x + (pts[i].x - pts[i - 1].x) * s / 6;
      const y = pts[i - 1].y + (pts[i].y - pts[i - 1].y) * s / 6;
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y }] });
    }
  }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await page.waitForTimeout(200);
}

async function fill(page, { files = true } = {}) {
  await page.waitForSelector('#first_name', { timeout: 15000 });
  await page.fill('#first_name', 'María');
  await page.fill('#last_name', 'García');
  await page.fill('#email', 'maria.361@example.es');
  await page.fill('#date_of_birth', '1990-03-04');
  await page.fill('#document_number', '12345678Z');
  for (const box of ['consent_data', 'consent_statutes']) await page.check(`input[name="${box}"]`).catch(() => {});
  if (files) {
    await page.setInputFiles('input[type=file][name="photo"]', PHOTO);
    if (SCAN) await page.setInputFiles('input[type=file][name="document_scan"]', SCAN);
  }
}

const submit = async (page) => {
  await page.waitForTimeout(3500); // the spam guard's minimum dwell
  await page.click('form[enctype="multipart/form-data"] button[type="submit"]');
  await page.waitForLoadState('networkidle');
};
const field = (page) => page.evaluate(() => document.querySelector('[data-signature-field]')?.value.length ?? -1);

// --- A. Drawn, never Guardar, sent -----------------------------------------------------------------------------------
{
  const { ctx, page } = await newPage('light');
  await page.goto(`${BASE}/socio/solicitud/proof361-link-light`, { waitUntil: 'networkidle' });
  await fill(page);
  check('A: no «Guardar firma» on the form', (await page.$$('[data-signature-save]')).length === 0);
  check('A: the field is empty before any stroke', (await field(page)) === 0);
  await touchDraw(page);
  const len = await field(page);
  check('A: one touch stroke fills the field, no Guardar', len > 1000, `${len} chars`);
  check('A: «✓ Firma capturada» shows once inked', await page.isVisible('[data-signature-form-ok]'));
  await page.screenshot({ path: `${OUT}/A-signed-no-guardar-820.png` });
  await submit(page);
  check('A: the form went through (no form left)', ! await page.$('form[enctype="multipart/form-data"]'), page.url());
  await page.screenshot({ path: `${OUT}/A-received-820.png` });
  await ctx.close();
}

// --- K. Kept uploads across a refusal ------------------------------------------------------------------------------
for (const theme of ['light', 'dark']) {
  const { ctx, page } = await newPage(theme);
  await page.goto(`${BASE}/socio/solicitud/proof361-keep-${theme}`, { waitUntil: 'networkidle' });
  await fill(page);
  await submit(page); // unsigned on the link: the server refuses «Falta la firma»
  const kept = await page.$$eval('[data-kept-upload]', (els) => els.map((e) => e.dataset.keptUpload));
  check(`K ${theme}: refused, both files shown kept`, kept.includes('photo') && kept.includes('document_scan'), kept.join(','));
  const thumb = await page.$eval('[data-kept-upload="photo"] img', (img) => img.naturalWidth).catch(() => 0);
  check(`K ${theme}: the kept photo's thumbnail loads`, thumb > 0, `${thumb}px`);
  await page.locator('[data-kept-upload="photo"]').scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${OUT}/K-kept-${theme}-820.png` });
  await page.click('[data-kept-upload="document_scan"] [data-kept-change]');
  // The shared file field keeps its real <input type=file> hidden behind «Hacer foto» / «Elegir archivo» (prompt 295);
  // the photo is still kept here, so the only visible «Elegir archivo» is the document's.
  await page.waitForTimeout(250);
  const keptHidden = ! await page.isVisible('[data-kept-upload="document_scan"]');
  const pickers = await page.locator('form[enctype="multipart/form-data"] button:visible, form[enctype="multipart/form-data"] label:visible').filter({ hasText: /Elegir archivo|Choose file/ }).count();
  check(`K ${theme}: Cambiar brings the picker back`, keptHidden && pickers === 1, `keptHidden=${keptHidden} pickers=${pickers}`);
  await page.screenshot({ path: `${OUT}/K-cambiar-${theme}-820.png` });
  await touchDraw(page);
  await submit(page);
  check(`K ${theme}: resubmitted without re-attaching, accepted`, ! await page.$('form[enctype="multipart/form-data"]'), page.url());
  await ctx.close();
}

// --- H. Handover unsigned → «Falta la firma» --------------------------------------------------------------------------
for (const theme of ['light', 'dark']) {
  const { ctx, page } = await newPage(theme);
  if (! await signInToCounter(page, '/counter/members', { sede: SEDE, account: 'staff' })) { check(`H ${theme}: sign in`, false); await ctx.close(); continue; }
  await page.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  const floatInput = await page.$('[data-till-float] input, input[wire\\:model="floatInput"]');
  if (floatInput) { await floatInput.fill('100'); await page.click('[data-till-open-action]').catch(() => {}); await page.waitForLoadState('networkidle'); await page.waitForTimeout(600); }
  await page.goto(`${BASE}/counter/members`, { waitUntil: 'networkidle' });
  await page.click('[data-alta-toggle]');
  await page.waitForSelector('[data-alta-modal]');
  await page.click('[data-alta-handover]');
  await page.waitForLoadState('networkidle');

  await fill(page, { files: true });
  await page.waitForTimeout(3500);
  await page.click('form[enctype="multipart/form-data"] button[type="submit"]');
  await page.waitForTimeout(500);
  check(`H ${theme}: the unsigned submit asks first`, await page.isVisible('[data-unsigned-confirm]'));
  await page.screenshot({ path: `${OUT}/H1-enviar-sin-firma-${theme}-820.png` });
  await page.click('[data-unsigned-send]');
  await page.waitForLoadState('networkidle');
  const back = await page.$('[data-handover-return]');
  check(`H ${theme}: sent, the thank-you has the way back`, !! back);
  if (back) { await back.click(); await page.waitForLoadState('networkidle'); }
  await enterPin(page, { account: 'staff' });
  await page.waitForTimeout(800);
  check(`H ${theme}: the review says «Falta la firma»`, await page.isVisible('[data-missing-signature]'));
  await page.locator('[data-missing-signature]').scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${OUT}/H2-falta-la-firma-${theme}-820.png` });
  await page.click('[data-sign-now]');
  await page.waitForTimeout(300);
  check(`H ${theme}: Firmar ahora shows the pad`, await page.isVisible('[data-missing-signature] [data-signature-canvas]'));
  await page.screenshot({ path: `${OUT}/H3-firmar-ahora-${theme}-820.png` });
  await page.click('[data-missing-signature] button:has-text("Back"), [data-missing-signature] button:has-text("Volver")');
  await page.click('[data-sign-waive]');
  await page.waitForTimeout(300);
  check(`H ${theme}: Seguir sin firma offers the reasons`, await page.isVisible('[data-sign-waive-reason="TABLET"]'));
  await page.screenshot({ path: `${OUT}/H4-seguir-sin-firma-${theme}-820.png` });
  await page.click('[data-sign-waive-reason="TABLET"]');
  await page.waitForTimeout(1200);
  check(`H ${theme}: waived, the review names who`, await page.isVisible('[data-signature-waived]'));
  await page.screenshot({ path: `${OUT}/H5-waived-${theme}-820.png` });
  await ctx.close();
}

// --- M. The invitation email with the plain link -----------------------------------------------------------------------
for (const width of [820, 390]) {
  const ctx = await browser.newContext({ viewport: { width, height: 1180 }, hasTouch: true, isMobile: true });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/dev/mail/application-invite`, { waitUntil: 'networkidle' });
  const link = await page.$eval('[data-plain-link]', (el) => ({ text: el.textContent.trim(), w: el.scrollWidth, cw: document.documentElement.clientWidth })).catch(() => null);
  check(`M ${width}: the plain link is there as text`, link && link.text.startsWith('https://'), link?.text);
  check(`M ${width}: it wraps (no sideways scroll)`, link && link.w <= link.cw, link ? `${link.w}/${link.cw}` : '');
  await page.screenshot({ path: `${OUT}/M-invite-${width}.png`, fullPage: true });
  await ctx.close();
}

await browser.close();
let ok = true;
for (const [label, pass, detail] of results) { console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  (${detail})` : ''}`); ok &&= pass; }
console.log(`${results.filter((r) => r[1]).length}/${results.length}`);
process.exit(ok ? 0 : 1);
