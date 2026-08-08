// Prompt 179 — read the MRZ off an ID photo IN THE BROWSER, and post only the text.
//
// The privacy argument in one line: the image never leaves the applicant's device in order to be read. It
// is still uploaded, because prompt 178 needs it as the compliance artefact — but the READING is local, on
// their own phone or the club's tablet, on their own document. No processor, no transfer, no RAT entry
// beyond what 178 already records, and no server binary that can vanish on a rebuild.
//
// What crosses the wire is a short MRZ string, never a photograph. The PARSING stays on the server, on
// `MrzParser`, which already validates every ICAO 9303 check digit — a JavaScript reimplementation would
// drift from the PHP one, and the half that drifted would be the half validating an identity document.
//
// Everything here is a progressive enhancement. If the engine cannot be fetched, cannot run, or reads
// nothing, the applicant fills the form exactly as they do today: no warning, no red state, no suggestion
// they did something wrong. That is the common case for a while and it is a normal outcome.

const ASSETS = {
    workerPath: '/ocr/worker.min.js',
    corePath: '/ocr/tesseract-core-lstm.wasm.js',
    langPath: '/ocr',
    gzip: true,
};

// An MRZ is fixed-width OCR-B: TD3 is two lines of 44, TD1 three of 30. Filler is '<'.
const MRZ_LINE = /^[A-Z0-9<]{28,46}$/;

function extractMrzLines(text) {
    const lines = text
        .toUpperCase()
        .split(/\r?\n/)
        .map((l) => l.replace(/\s+/g, ''))
        .filter((l) => MRZ_LINE.test(l));

    // Take the LAST run of same-length lines: an ID's MRZ sits at the foot of the image, and anything above
    // it that happens to match is not the zone we want.
    for (const size of [3, 2]) {
        const tail = lines.slice(-size);
        if (tail.length === size && tail.every((l) => l.length === tail[0].length)) {
            return tail.join('\n');
        }
    }

    return null;
}

/**
 * OCR a File and return the raw MRZ text, or null. Loads the engine ON DEMAND — a WASM bundle is megabytes
 * and an applicant who never scans, or who is on a slow connection, must not pay for it.
 */
export async function readMrz(file) {
    let Tesseract;

    try {
        Tesseract = await import('tesseract.js');
    } catch {
        return null; // engine unavailable — indistinguishable from an unsupported browser, by design
    }

    let worker;

    try {
        worker = await Tesseract.createWorker('eng', 1, ASSETS);
        // The MRZ alphabet only. Constraining it is worth more than any model choice here.
        await worker.setParameters({ tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<' });

        const { data } = await worker.recognize(file);

        return extractMrzLines(data?.text ?? '');
    } catch {
        return null;
    } finally {
        try {
            await worker?.terminate();
        } catch {
            /* nothing to do — the read already succeeded or failed on its own terms */
        }
    }
}

/**
 * Wire the scan control on the application form.
 *
 * Posts the TEXT to `application.read`, which parses it server-side and redirects back with the fields
 * marked unconfirmed. No image is posted here — a test pins that.
 */
export function mountMrzScan(root = document) {
    const trigger = root.querySelector('[data-mrz-scan]');
    const fileInput = root.querySelector('#document_scan');
    const form = root.querySelector('[data-mrz-form]');
    const status = root.querySelector('[data-mrz-status]');

    if (!trigger || !fileInput || !form) {
        return;
    }

    trigger.hidden = false;

    trigger.addEventListener('click', async () => {
        const file = fileInput.files?.[0];

        if (!file) {
            if (status) status.textContent = trigger.dataset.needsFile || '';
            return;
        }

        trigger.disabled = true;
        if (status) status.textContent = trigger.dataset.reading || '';

        const mrz = await readMrz(file);

        trigger.disabled = false;

        if (!mrz) {
            // A failed read is ordinary. Say nothing about it beyond clearing the "reading…" line.
            if (status) status.textContent = '';
            return;
        }

        form.querySelector('[data-mrz-input]').value = mrz;
        form.submit();
    });
}

/**
 * Prompt 215 — the same reader, on the counter's staff sign-up form.
 *
 * 179 built `readMrz()` as a reusable read and wired it to one consumer: the applicant's public form, which
 * POSTs the raw zone to a tokenised route. The staff form has no application yet — the token is minted at
 * submit — so the read goes straight to the Livewire component, which parses it with the SAME `MrzParser`
 * and the same ICAO check-digit rule. One reader, one parser, two callers.
 *
 * Mounted on every Livewire update as well as on load, because the form appears behind a disclosure and
 * Livewire replaces its markup. Idempotent: the trigger is re-found each time and the listener re-bound to
 * the new element.
 */
export function mountStaffMrzScan(root = document) {
    const trigger = root.querySelector('[data-alta-mrz-scan]');
    const fileInput = root.querySelector('[data-alta-scan]');
    const status = root.querySelector('[data-alta-mrz-status]');

    if (!trigger || !fileInput || trigger.dataset.mounted === '1') {
        return;
    }

    trigger.dataset.mounted = '1';
    trigger.hidden = false;

    trigger.addEventListener('click', async () => {
        const file = fileInput.files?.[0];

        if (!file) {
            if (status) status.textContent = trigger.dataset.needsFile || '';
            return;
        }

        trigger.disabled = true;
        if (status) status.textContent = trigger.dataset.reading || '';

        const mrz = await readMrz(file);

        trigger.disabled = false;
        if (status) status.textContent = '';

        // A failed read is ordinary and says nothing — the operator types the four fields, as they would
        // have anyway. The component decides whether a successful read is TRUSTWORTHY (the check digit).
        if (mrz) {
            window.Livewire?.find(trigger.closest('[wire\\:id]')?.getAttribute('wire:id'))?.call('applyMrz', mrz);
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    mountMrzScan();
    mountStaffMrzScan();
});

document.addEventListener('livewire:navigated', () => mountStaffMrzScan());
document.addEventListener('livewire:update', () => mountStaffMrzScan());
