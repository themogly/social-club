// Prompt 279 (Ben's 275) — "Record movement not working in counter."
//
// It WAS working: the row was written and the till flashed "Movimiento registrado: 10,00 €." — in the shared
// slot at the TOP of the page. At iPad landscape (1180×820) the operator has scrolled down to the form, so the
// confirmation landed ~500px above the viewport and the only visible change was the amount field emptying. It
// looked like nothing happened, and the second tap recorded it twice.
//
// This drives the REAL till: scroll the form into view the way the operator does, tap, and measure where the
// confirmation actually is. The assertion is geometric — its bounding box must be inside the viewport — because
// every PHP test of this path passed while the answer was off-screen.
//
//   npm run build && php artisan serve --port=8126
//   BASE_URL=http://127.0.0.1:8126 node tests/Browser/shoot-till-forms-inline.mjs [before|after]
//
// `before` only records (the unmodified code is expected to fail); `after` exits non-zero on any miss.
// Screenshots of both forms after the tap, landscape, land in storage/app/screenshots/279/.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

// The sign-in preamble is `counter-session.mjs` (prompts 223/226) — one copy, not ten.
import { BASE, SEDE, signInToCounter } from './counter-session.mjs';

const PHASE = process.argv[2] ?? 'after';
const OUT = 'storage/app/screenshots/279';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();

// One login, reused — logging in per context trips Filament's throttle (prompt 200).
const auth = await browser.newContext({ viewport: { width: 1180, height: 820 } });
const login = await auth.newPage();
if (! await signInToCounter(login, '/counter/till', { sede: SEDE })) {
  console.error('FAIL: could not reach the counter — is the dev seed loaded and the server running?');
  process.exit(1);
}

// The till must be open for the forms to exist. Open it once if the seed left it closed.
await login.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
if (await login.$('[data-till-open-screen]')) {
  await login.fill('[data-till-float]', '100,00');
  await login.click('[data-till-open-action]');
  await login.waitForLoadState('networkidle');
  await login.waitForTimeout(800);
}

const storageState = await auth.storageState();
await auth.close();

const FORMS = [
  { key: 'movement', submit: 'form[wire\\:submit="recordMovement"]', amount: '#movementAmount', figure: /10[,.]00/ },
  { key: 'expense', submit: 'form[wire\\:submit="recordExpense"]', amount: '#expenseAmount', figure: /2[,.]50/ },
];

const rows = [];
let failed = false;

for (const vp of [{ name: '1180x820', width: 1180, height: 820 }, { name: '820x1180', width: 820, height: 1180 }]) {
  const c = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, colorScheme: 'light', reducedMotion: 'reduce', storageState });
  const p = await c.newPage();

  // Two scroll positions: the form card just on screen, and the page scrolled to its foot (where the
  // report's ≈ −512 came from — the operator had scrolled past the forms toward the close-out).
  for (const form of FORMS.flatMap((f) => [{ ...f, scroll: 'card' }, { ...f, scroll: 'foot' }])) {
    await p.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
    await p.waitForTimeout(500);

    if (! await p.$(form.submit)) {
      console.error(`FAIL ${vp.name} ${form.key}/${form.scroll}: the form is not on the till screen`);
      failed = true;
      continue;
    }

    if (form.key === 'expense') {
      const first = await p.$eval('#expenseCategory', (s) => [...s.options].find((o) => o.value !== '')?.value ?? '');
      await p.selectOption('#expenseCategory', first);
    }

    // The operator scrolls until the form card is on screen, then taps its button.
    if (form.scroll === 'card') {
      await p.$eval(form.submit, (f) => f.closest('section').scrollIntoView({ block: 'end' }));
    } else {
      await p.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    }
    await p.waitForTimeout(150);
    await p.fill(form.amount, form.key === 'movement' ? '10,00' : '2,50');
    await p.click(`${form.submit} button[type="submit"]`);
    await p.waitForLoadState('networkidle');
    await p.waitForTimeout(600);

    const m = await p.evaluate(({ figure }) => {
      const re = new RegExp(figure);
      const regions = [...document.querySelectorAll('[role="status"], [role="alert"]')]
        .filter((n) => re.test(n.textContent ?? ''));
      const n = regions[0];
      if (! n) return { found: false, count: 0 };
      const r = n.getBoundingClientRect();
      return {
        found: true,
        count: regions.length,
        text: n.textContent.replace(/\s+/g, ' ').trim().slice(0, 70),
        top: Math.round(r.top),
        bottom: Math.round(r.bottom),
        inView: r.top >= 0 && r.bottom <= window.innerHeight,
        insideForm: !! n.closest('section')?.querySelector('form'),
      };
    }, { figure: form.figure.source });

    if (vp.name === '1180x820' && form.scroll === 'card') {
      await p.screenshot({ path: `${OUT}/${PHASE}-${form.key}-${vp.name}-light.png` });
    }

    rows.push({ phase: PHASE, viewport: vp.name, form: form.key, scroll: form.scroll, top: m.top ?? '—', bottom: m.bottom ?? '—', inView: m.inView ? 'yes' : 'NO', inCard: m.insideForm ? 'yes' : 'no', count: m.count, text: m.text ?? '(none)' });

    if (! m.found) { console.error(`FAIL ${vp.name} ${form.key}/${form.scroll}: no confirmation rendered at all`); failed = true; }
    else if (! m.inView) { console.error(`FAIL ${vp.name} ${form.key}/${form.scroll}: confirmation outside the viewport (top ${m.top}, bottom ${m.bottom}, viewport ${vp.height})`); failed = true; }
    if (m.found && m.count !== 1) { console.error(`FAIL ${vp.name} ${form.key}/${form.scroll}: ${m.count} confirmations — there must be exactly one`); failed = true; }
  }

  // The sweep: the other till actions whose buttons sit low on the page — a refusal from each (nothing is
  // written: an unreadable handover count, an empty flower recount / cash count), tapped with the page at its foot.
  const refusal = async (key) => {
    await p.waitForLoadState('networkidle');
    await p.waitForTimeout(600);
    const m = await p.evaluate(() => {
      const n = [...document.querySelectorAll('[role="alert"], [role="status"]')].find((x) => x.offsetParent !== null);
      if (! n) return { found: false };
      const r = n.getBoundingClientRect();
      return { found: true, top: Math.round(r.top), bottom: Math.round(r.bottom), inView: r.top >= 0 && r.bottom <= window.innerHeight, text: n.textContent.replace(/\s+/g, ' ').trim().slice(0, 70) };
    });
    rows.push({ phase: PHASE, viewport: vp.name, form: key, scroll: 'foot', top: m.top ?? '—', bottom: m.bottom ?? '—', inView: m.inView ? 'yes' : 'NO', inCard: '', count: m.found ? 1 : 0, text: m.text ?? '(none)' });
    if (! m.inView) { console.error(`FAIL ${vp.name} ${key}: refusal not in the viewport (${m.top}..${m.bottom})`); failed = true; }
    if (vp.name === '1180x820') await p.screenshot({ path: `${OUT}/${PHASE}-${key}-${vp.name}-light.png` });
  };

  await p.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  if (await p.$('[data-handover-toggle]')) {
    await p.click('[data-handover-toggle]');
    await p.waitForSelector('[data-handover-counted]');
    await p.fill('[data-handover-counted]', 'nope');
    await p.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await p.click('[data-handover-confirm]');
    await refusal('handover');
  }

  await p.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
  if (await p.$('[data-close-till]')) {
    await p.click('[data-close-till]');
    await p.waitForLoadState('networkidle');
    await p.waitForTimeout(400);
    const recount = await p.$('form[wire\\:submit="submitReweigh"]');
    await p.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await p.click(recount ? 'form[wire\\:submit="submitReweigh"] button[type="submit"]' : 'form[wire\\:submit="submitCount"] button[type="submit"]');
    await refusal(recount ? 'reweigh' : 'count');
    await p.click('button[wire\\:click="cancelClose"]');
    await p.waitForLoadState('networkidle');
  }

  // Dark captures of the landscape result, for the LOOK pass.
  if (vp.name === '1180x820' && PHASE === 'after') {
    const d = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, colorScheme: 'dark', reducedMotion: 'reduce', storageState });
    const dp = await d.newPage();
    await dp.goto(`${BASE}/counter/till`, { waitUntil: 'networkidle' });
    await dp.$eval(FORMS[0].submit, (f) => f.closest('section').scrollIntoView({ block: 'end' }));
    await dp.fill('#movementAmount', '10,00');
    await dp.selectOption('#movementType', 'OUT');
    await dp.click(`${FORMS[0].submit} button[type="submit"]`);
    await dp.waitForLoadState('networkidle');
    await dp.waitForTimeout(600);
    await dp.screenshot({ path: `${OUT}/${PHASE}-movement-${vp.name}-dark.png` });
    // A refusal, inline: an unreadable amount.
    await dp.fill('#expenseAmount', '1.000');
    await dp.click(`${FORMS[1].submit} button[type="submit"]`);
    await dp.waitForLoadState('networkidle');
    await dp.waitForTimeout(600);
    await dp.screenshot({ path: `${OUT}/${PHASE}-expense-refusal-${vp.name}-dark.png` });
    await d.close();
  }

  await c.close();
}

await browser.close();
console.table(rows);

if (PHASE === 'before') {
  console.log('\nBEFORE recorded (misses above are the defect being measured, not a harness failure).');
  process.exit(0);
}
console.log(failed ? '\nRESULT: FAIL' : `\nRESULT: ALL PASS — captures in ${OUT}`);
process.exit(failed ? 1 : 0);
