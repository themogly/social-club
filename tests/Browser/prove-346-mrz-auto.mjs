// Prompt 346 — the ID reader starts by itself after the photo, keeps the photo, and says when it couldn't read.
// Freshly seeded demo DB (DBFILE). Synthetic documents only: the zones are BUILT here (valid ICAO check digits) and
// drawn onto a canvas — no real document image anywhere.
//   Public form (390×844, an emailed link):
//     1. choosing the TD1 photo reads it with no tap; the empty fields fill as provisional;
//     2. the photo is still attached (no reload) and submitting stores the scan in the vault;
//     3. a surname typed first is kept, with «En el documento: … · Usar»; Usar applies it as provisional;
//     4. a photo with no zone says «No hemos podido leer…» and keeps the photo; a PDF says the PDF line, no read;
//     5. a second photo chosen during a read cancels the first: only the second fills.
//   Staff form (counter, 1180×820): 6. the same automatic read, and the scan is still attached.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { BASE, signInToCounter, openStaffWizard } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/346';
const DB = process.env.DBFILE;
const sql = (q) => execFileSync('sqlite3', [DB, q]).toString().trim();
const php = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { env: { ...process.env, DB_DATABASE: DB } }).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const errors = [];
const settle = async (p) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(500); };

// --- synthetic zones ------------------------------------------------------------------------------------------------
const cd = (s) => String([...s].reduce((t, ch, i) => t + (ch === '<' ? 0 : /\d/.test(ch) ? +ch : ch.charCodeAt(0) - 55) * [7, 3, 1][i % 3], 0) % 10);
const pad = (s, n) => (s + '<'.repeat(n)).slice(0, n);
function td1({ doc, dob, exp, surname, given, nat = 'ESP' }) {
    const l1 = pad(`IDESP${doc}${cd(doc)}99999999R`, 30);
    const head = `${dob}${cd(dob)}F${exp}${cd(exp)}${nat}`;
    const l2body = pad(head, 29);
    const composite = cd(l1.slice(5, 30) + l2body.slice(0, 7) + l2body.slice(8, 15) + l2body.slice(18, 29));
    return [l1, l2body + composite, pad(`${surname}<<${given}`, 30)];
}
// Document numbers without 0/O — a generic font (not OCR-B) blurs those two, which says nothing about the page.
const DNI_BACK = td1({ doc: 'BAF123456', dob: '900101', exp: '300101', surname: 'ESPANOLA', given: 'CARMEN' });
// The cancel check uses a second ID card: a TD3's long «<<<» tail blurs in a generic font (the TD3 path itself is
// pinned server-side in MrzReadsByItselfTest / MrzPrefillTest with the ICAO specimen).
const DNI_OTHER = td1({ doc: 'CDE123456', dob: '850515', exp: '310101', surname: 'RUIZ', given: 'PABLO' });

async function image(page, lines, name) {
    const dataUrl = await page.evaluate((ls) => {
        const c = document.createElement('canvas');
        c.width = 1700; c.height = 140 + ls.length * 80;
        const g = c.getContext('2d');
        g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.fillStyle = '#000';
        // Menlo with a little tracking: the engine keeps «<<<» runs as filler (Courier's turned into K and L).
        g.font = '40px Menlo, "DejaVu Sans Mono", monospace';
        g.letterSpacing = '4px';
        ls.forEach((l, i) => g.fillText(l, 40, 100 + i * 80));
        return c.toDataURL('image/png');
    }, lines);
    return { name, mimeType: 'image/png', buffer: Buffer.from(dataUrl.split(',')[1], 'base64') };
}
const PDF = { name: 'dni.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n') };

const invite = (label) => php(`$o = App\\Models\\User::where('email','like','owner@%')->first(); $l = App\\Models\\Location::withoutGlobalScopes()->where('kind','!=','ALMACEN')->first(); app(App\\Support\\ActiveScope::class)->setOrganisation($l->organisation_id); $a = (new App\\Actions\\Members\\IssueApplicationInvite)->handle($o, $l->id, null, '${label}'); echo route('socio.application', ['token' => $a->invite_token], false);`).split('\n').pop().replace(/^/, BASE);
const value = (p, sel) => p.locator(sel).inputValue();
const visible = (p, sel) => p.locator(sel).isVisible();
const filesIn = (p, sel) => p.locator(sel).evaluate((i) => i.files?.length ?? 0);
const waitFilled = (p, sel, ms = 90000) => p.waitForFunction((s) => (document.querySelector(s)?.value ?? '') !== '', sel, { timeout: ms }).then(() => true).catch(() => false);

// --- the public form ------------------------------------------------------------------------------------------------
const phone = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
phone.on('pageerror', (e) => errors.push(e.message));
await phone.goto(invite('PRUEBA-346-A'), { waitUntil: 'networkidle' });
await phone.evaluate(() => { window.__sameDocument = true; });
await phone.fill('#last_name', 'GARCIA LOPEZ'); // typed before the read
check('the tip is above the photo buttons, always visible', await visible(phone, '[data-mrz-tip]'));
await phone.locator('#document_scan').setInputFiles(await image(phone, DNI_BACK, 'dni-back.png'));
await phone.waitForTimeout(300);
const reading = await phone.locator('[data-mrz-status]').innerText();
check('1. choosing the photo starts reading by itself («Leyendo el documento…»)', /Leyendo/.test(reading), reading);
const filled = await waitFilled(phone, '#first_name');
await phone.screenshot({ path: `${OUT}/1-read-by-itself-390.png`, fullPage: true });
check('1. …and the empty fields fill: name, date of birth, number, type', filled
    && await value(phone, '#first_name') === 'CARMEN' && await value(phone, '#date_of_birth') === '1990-01-01'
    && await value(phone, '#document_number') === 'BAF123456' && await value(phone, '#document_type') === 'DNI',
    JSON.stringify({ first: await value(phone, '#first_name'), dob: await value(phone, '#date_of_birth'), doc: await value(phone, '#document_number'), type: await value(phone, '#document_type') }));
check('1. …each marked provisional («Es correcto» to tick)', await visible(phone, '[data-mrz-prefilled="first_name"]') && await visible(phone, '[data-mrz-prefilled="document_number"]') && await visible(phone, '[data-mrz-prefilled="document_type"]'));
check('2. the photo is still attached, and the page never reloaded', await filesIn(phone, '#document_scan') === 1 && await phone.evaluate(() => window.__sameDocument === true));
check('3. the surname typed first is kept', await value(phone, '#last_name') === 'GARCIA LOPEZ' && ! await visible(phone, '[data-mrz-prefilled="last_name"]'));
const offer = (await phone.locator('[data-mrz-offer="last_name"]').innerText().catch(() => '')).replace(/\s+/g, ' ');
check('3. …with «En el documento: ESPANOLA · Usar» under it', /ESPANOLA/.test(offer) && /Usar/.test(offer), offer);
await phone.locator('[data-mrz-use="last_name"]').click(); await phone.waitForTimeout(1200);
check('3. «Usar» applies it as provisional', await value(phone, '#last_name') === 'ESPANOLA' && await visible(phone, '[data-mrz-prefilled="last_name"]') && ! await visible(phone, '[data-mrz-offer="last_name"]'));
await phone.screenshot({ path: `${OUT}/2-used-390.png`, fullPage: true });

// Submit: the scan travels with the application and lands in the vault.
for (const f of ['first_name', 'last_name', 'date_of_birth', 'document_number', 'document_type']) {
    const box = phone.locator(`[data-mrz-confirm="${f}"]`);
    if (await box.isVisible()) await box.check();
}
await phone.fill('#email', `carmen.${Date.now()}@example.es`);
await phone.check('input[name="consent_data"]');
await phone.check('input[name="consent_statutes"]');
const sig = phone.locator('[data-signature-canvas]').first();
await sig.scrollIntoViewIfNeeded();
const sb = await sig.boundingBox();
await phone.mouse.move(sb.x + sb.width * 0.15, sb.y + sb.height * 0.6); await phone.mouse.down();
for (const [dx, dy] of [[0.3, 0.3], [0.45, 0.7], [0.6, 0.3], [0.75, 0.65]]) await phone.mouse.move(sb.x + sb.width * dx, sb.y + sb.height * dy);
await phone.mouse.up();
await phone.click('[data-signature-save]').catch(() => {});
await phone.waitForTimeout(3500); // past the spam guard's floor
await phone.click('form[enctype] button[type="submit"]'); await settle(phone);
const scanPath = sql("select json_extract(payload, '$.document_scan_path') from member_applications where json_extract(payload, '$.first_name') = 'CARMEN' order by updated_at desc limit 1");
const onDisk = scanPath ? php(`echo Illuminate\\Support\\Facades\\Storage::disk('documents')->exists('${scanPath}') ? 'yes' : 'no';`).split('\n').pop() : 'no path';
check('2. submitting uploads the scan: the vaulted document exists', !! scanPath && onDisk === 'yes', `${scanPath} ${onDisk}`);
await phone.screenshot({ path: `${OUT}/3-submitted-390.png` });

// Failures and PDFs, on a second invitation.
await phone.goto(invite('PRUEBA-346-B'), { waitUntil: 'networkidle' });
await phone.locator('#document_scan').setInputFiles(await image(phone, ['HOLA, ESTO NO ES', 'LA PARTE DE ATRAS'], 'dni-front.png'));
const failed = await phone.waitForFunction(() => /No hemos podido leer/.test(document.querySelector('[data-mrz-status]')?.textContent ?? ''), null, { timeout: 90000 }).then(() => true).catch(() => false);
check('4. a photo with no zone: «No hemos podido leer el documento…», the photo kept', failed && await filesIn(phone, '#document_scan') === 1 && await value(phone, '#first_name') === '');
await phone.screenshot({ path: `${OUT}/4-could-not-read-390.png`, fullPage: true });
await phone.locator('#document_scan').setInputFiles(PDF); await phone.waitForTimeout(400);
const pdfLine = await phone.locator('[data-mrz-status]').innerText();
check('4. a PDF: the PDF line, and no read (no spinner, no retry button)', /usa una foto en lugar de un PDF/.test(pdfLine) && ! await visible(phone, '[data-mrz-spinner]') && ! await visible(phone, '[data-mrz-scan]'), pdfLine);

// A second photo during a read cancels the first.
await phone.goto(invite('PRUEBA-346-C'), { waitUntil: 'networkidle' });
await phone.locator('#document_scan').setInputFiles(await image(phone, DNI_BACK, 'first.png'));
await phone.waitForTimeout(400);
await phone.locator('#document_scan').setInputFiles(await image(phone, DNI_OTHER, 'second.png'));
await waitFilled(phone, '#first_name');
await phone.waitForTimeout(6000); // long enough for a stale first read to land, had it not been cancelled
check('5. a second photo cancels the first read: only the second fills', await value(phone, '#first_name') === 'PABLO' && await value(phone, '#last_name') === 'RUIZ' && await value(phone, '#date_of_birth') === '1985-05-15',
    JSON.stringify({ first: await value(phone, '#first_name'), last: await value(phone, '#last_name') }));
await phone.context().close();

// --- the staff form at the counter -----------------------------------------------------------------------------------
const tablet = await (await browser.newContext({ viewport: { width: 1180, height: 820 } })).newPage();
tablet.on('pageerror', (e) => errors.push(e.message));
await signInToCounter(tablet, '/counter/till', { account: 'owner', sede: 'Central Branch' });
if (await tablet.locator('input[wire\\:model="floatInput"]').count()) {
    await tablet.fill('input[wire\\:model="floatInput"]', '50'); await tablet.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(tablet);
}
await tablet.goto(`${BASE}/counter/members`, { waitUntil: 'networkidle' });
await openStaffWizard(tablet);
await tablet.locator('#alta-scan').setInputFiles(await image(tablet, DNI_BACK, 'dni-back.png'));
const staffFilled = await waitFilled(tablet, '#alta-first-name');
await tablet.waitForTimeout(800);
const attached = await tablet.evaluate(() => {
    const id = document.querySelector('[data-alta-mrz-region]')?.closest('[wire\\:id]')?.getAttribute('wire:id');
    return { files: document.querySelector('#alta-scan')?.files?.length ?? 0, server: !! window.Livewire?.find(id)?.get('altaDocumentScan') };
});
check('6. the staff form reads by itself and fills the fields', staffFilled && await value(tablet, '#alta-first-name') === 'CARMEN' && await value(tablet, '#alta-doc-type') === 'DNI');
check('6. …and the scan is still attached (the field and the component)', attached.files === 1 && attached.server, JSON.stringify(attached));
await tablet.screenshot({ path: `${OUT}/5-staff-form-1180.png` });

check('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
