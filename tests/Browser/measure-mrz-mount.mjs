// Prompt 223 — does prompt 179's MRZ reader actually mount where prompt 215 put it?
//
//   npm run build && php artisan serve --port=8123
//   node tests/Browser/measure-mrz-mount.mjs
//
// A real browser, because nothing else can answer it. The trigger is `hidden` until the module mounts it —
// which is the correct progressive-enhancement default, and is indistinguishable server-side from a browser
// that genuinely cannot run the reader. A Blade test sees identical markup either way.
//
// Run against `ad871fe` this reports the defect: on the first paint of Identidad the trigger is in the DOM,
// hidden, with `data-mounted` unset — and it stays that way across every step change.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { signInToCounter, openStaffWizard } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/223';
mkdirSync(OUT, { recursive: true });

let failed = false;
const fail = (m) => { console.error(`FAIL: ${m}`); failed = true; };
const ok = (m) => console.log(`  ok   ${m}`);

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1180, height: 820 }, reducedMotion: 'reduce', hasTouch: true });
const page = await ctx.newPage();

// Everything the page fetches, so "the OCR engine has not loaded" is a measurement rather than a belief.
const requests = [];
page.on('request', (r) => requests.push(r.url()));
const ocrRequests = () => requests.filter((u) => /tesseract|\/ocr\/|src-[A-Za-z0-9_-]+\.js/.test(u));

if (! await signInToCounter(page)) {
    fail('could not reach the counter — is the dev seed loaded and the server running?');
    await browser.close();
    process.exit(1);
}

/** What the trigger's state actually is, as opposed to what the markup implies. */
const readerState = () => page.evaluate(() => {
    const trigger = document.querySelector('[data-alta-mrz-scan]');

    if (! trigger) return { present: false };

    return {
        present: true,
        hidden: trigger.hidden,
        mounted: trigger.dataset.mounted === '1',
        fileInput: !! document.querySelector('[data-alta-scan]'),
        status: document.querySelector('[data-alta-mrz-status]')?.textContent ?? null,
    };
});

// --- 1. first paint of Identidad ---------------------------------------------------------------
console.log('\n— the staff wizard, step 1 —');

await openStaffWizard(page);
await page.waitForTimeout(900);

let s = await readerState();
if (! s.present) fail('first paint: the trigger is not in the DOM at all');
else if (s.hidden || ! s.mounted) fail(`first paint: the reader never mounted (hidden=${s.hidden}, mounted=${s.mounted}) — it is unreachable`);
else ok('first paint of Identidad — the trigger is visible and mounted');

if (! s.fileInput) fail('first paint: the document input the reader binds to is not on the same step');

// Pressing it with no file chosen proves the LISTENER is live, not merely that the button is visible.
// Guarded on the button being clickable at all: a run against a broken build must REPORT the defect, not
// crash on a 30-second timeout waiting for a hidden control — a harness that dies says less than one that
// says what it found.
const press = async () => {
    if (! (await readerState()).mounted) return null;
    await page.click('[data-alta-mrz-scan]');
    await page.waitForTimeout(400);

    return (await readerState()).status;
};

const needsFile = await press();
if (! needsFile) fail('the trigger is not bound — pressing it does nothing');
else ok(`the click listener answers: "${needsFile}"`);

await page.screenshot({ path: `${OUT}/1-step-1-reader-visible.png` });

// --- 2. across a step change -------------------------------------------------------------------
console.log('\n— across a step change —');

await page.fill('#alta-first-name', 'María');
await page.fill('#alta-last-name', 'García');
await page.fill('#alta-dob', '1991-04-12');
await page.selectOption('#alta-doc-type', 'DNI');
await page.fill('#alta-doc-number', '12345678Z');
await page.click('[data-alta-next]');
await page.waitForTimeout(800);
await page.click('[data-alta-back]');
await page.waitForTimeout(900);

s = await readerState();
if (! s.present || s.hidden || ! s.mounted) fail(`after step 2 → back: the reader is gone or unmounted (${JSON.stringify(s)})`);
else ok('back on Identidad — still visible and still mounted');

if (! await press()) fail('after a step change the click listener is dead');
else ok('and its listener is still bound');

// --- 3. the engine stays behind the click -------------------------------------------------------
console.log('\n— the OCR engine is lazy —');

if (ocrRequests().length) fail(`the OCR engine loaded before anybody asked to read anything: ${ocrRequests().join(', ')}`);
else ok(`nothing OCR-shaped fetched yet (${requests.length} requests so far)`);

// A file the reader will fail to read is fine: what is measured is that pressing the button is what FETCHES
// the engine, not what it manages to read from a one-pixel photo.
await page.setInputFiles('[data-alta-scan]', {
    name: 'dni.png',
    mimeType: 'image/png',
    buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'),
});
await page.waitForTimeout(1500);

if (ocrRequests().length) fail(`choosing a file alone fetched the engine: ${ocrRequests().join(', ')}`);
else ok('choosing a file does not fetch it either');

if ((await readerState()).mounted) {
    await page.click('[data-alta-mrz-scan]');
    await page.waitForTimeout(6000);
}

if (! ocrRequests().length) fail('pressing the trigger with a file fetched nothing — the reader is not wired to the engine');
else ok(`pressed: the engine is fetched on demand (${ocrRequests().length} request(s))`);

await page.screenshot({ path: `${OUT}/2-step-1-after-pressing-read.png` });

// --- 4. the applicant's own form is untouched ---------------------------------------------------
console.log("\n— the applicant's form, ordinary page load —");

// In its OWN context: reaching that form means handing the tablet over (173), which puts the session into
// handover mode. Doing it in the shared context would leave a state the next harness run inherits.
const applicantCtx = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
const applicantPage = await applicantCtx.newPage();

if (! await signInToCounter(applicantPage)) {
    fail('could not reach the counter to hand the tablet over');
} else {
    await applicantPage.click('[data-alta-toggle]');
    await applicantPage.waitForSelector('[data-alta-modal]');
    await applicantPage.click('[data-alta-handover]');
    await applicantPage.waitForLoadState('networkidle');
    await applicantPage.waitForTimeout(900);

    const applicant = await applicantPage.evaluate(() => {
        const trigger = document.querySelector('[data-mrz-scan]');

        // The applicant's mount does not stamp `data-mounted` — it has no need to be idempotent on a page
        // that loads once — so being VISIBLE is what says it ran.
        return trigger ? { present: true, hidden: trigger.hidden } : { present: false };
    });

    if (! applicant.present) fail('the applicant form has no MRZ trigger');
    else if (applicant.hidden) fail("the applicant form's reader stopped mounting");
    else ok('the applicant form still mounts on an ordinary page load');

    await applicantPage.screenshot({ path: `${OUT}/3-applicant-form-reader.png` });
}

await applicantCtx.close();
await browser.close();

console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS → ${OUT}`);
process.exit(failed ? 1 : 0);
