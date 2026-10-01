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
 *
 * Prompt 346 — `signal` cancels a read in progress (a newer photo was chosen): the worker is terminated and the read
 * resolves null, so a stale photo can never fill the form.
 */
export async function readMrz(file, signal = null) {
    let Tesseract;

    try {
        Tesseract = await import('tesseract.js');
    } catch {
        return null; // engine unavailable — indistinguishable from an unsupported browser, by design
    }

    let worker;
    const stop = () => worker?.terminate().catch(() => {});

    try {
        if (signal?.aborted) return null;
        worker = await Tesseract.createWorker('eng', 1, ASSETS);
        signal?.addEventListener('abort', stop, { once: true });
        // The MRZ alphabet only. Constraining it is worth more than any model choice here.
        await worker.setParameters({ tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<' });

        const { data } = await worker.recognize(file);

        return signal?.aborted ? null : extractMrzLines(data?.text ?? '');
    } catch {
        return null;
    } finally {
        signal?.removeEventListener('abort', stop);
        try {
            await worker?.terminate();
        } catch {
            /* nothing to do — the read already succeeded or failed on its own terms */
        }
    }
}

const isPdf = (file) => file.type === 'application/pdf' || /\.pdf$/i.test(file.name ?? '');
const isImage = (file) => (file.type ?? '').startsWith('image/');

/**
 * Prompt 346 — the read starts BY ITSELF when a photo is chosen or taken in the document field (its `change`), on both
 * forms; the button is a retry with the same photo. A PDF is not read (the engine cannot) and says so. A newer photo
 * cancels a read in progress. `onZone(mrz, signal)` hands a zone to the form and resolves true when it was a valid one;
 * anything else ends in the one helpful line — and the photo is never touched.
 */
function autoRead({ fileInput, trigger, status, spinner, onZone }) {
    let running = null;

    const say = (text, busy = false) => {
        if (status) status.textContent = text || '';
        if (spinner) spinner.hidden = ! busy;
    };

    const start = async () => {
        running?.abort();
        const file = fileInput.files?.[0];
        trigger.hidden = ! (file && isImage(file));
        if (! file) return say('');
        if (isPdf(file) || ! isImage(file)) return say(isPdf(file) ? trigger.dataset.pdf : '');

        const controller = new AbortController();
        running = controller;
        trigger.disabled = true;
        say(trigger.dataset.reading, true);

        const mrz = await readMrz(file, controller.signal);
        if (controller.signal.aborted) return; // a newer photo took over; its read reports for itself

        const ok = mrz ? await onZone(mrz, controller.signal).catch(() => false) : false;
        if (controller.signal.aborted) return;

        running = null;
        trigger.disabled = false;
        say(ok ? '' : trigger.dataset.failed);
    };

    return { start, refresh: () => { trigger.hidden = ! (fileInput.files?.[0] && isImage(fileInput.files[0])); } };
}

const FIELDS = ['first_name', 'last_name', 'date_of_birth', 'document_number', 'document_type'];

const shown = (value, input) => {
    if (input?.tagName === 'SELECT') return [...input.options].find((o) => o.value === value)?.textContent?.trim() ?? value;
    const iso = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    return iso ? `${iso[3]}/${iso[2]}/${iso[1]}` : value;
};

/**
 * Wire the reader on the applicant's form.
 *
 * Prompt 346 — the zone goes to `application.read` in the BACKGROUND (JSON, never a page submit): the old
 * page submit reloaded the page, and a file input never survives a reload, so the ID photo the applicant had just
 * taken was silently dropped. The server still parses (`MrzParser`) and keeps the provisional fields (`MrzPrefill`);
 * this page fills only EMPTY fields (or ones an earlier read filled and nobody changed), shows the «Es correcto»
 * confirmation under each, and offers the document's value under a field the applicant had typed differently. Only the
 * TEXT is posted — no image (a test pins it).
 */
export function mountMrzScan(root = document) {
    const trigger = root.querySelector('[data-mrz-scan]');
    const fileInput = root.querySelector('#document_scan');
    const form = root.querySelector('[data-mrz-form]');

    if (! trigger || ! fileInput || ! form) {
        return;
    }

    const field = (name) => root.getElementById?.(name) ?? document.getElementById(name);
    const confirmOf = (name) => root.querySelector(`[data-mrz-prefilled="${name}"]`);
    const offerOf = (name) => root.querySelector(`[data-mrz-offer="${name}"]`);
    let lastZone = null;

    // A select always has a value; it counts as the applicant's only once they have changed it.
    field('document_type')?.addEventListener('change', (e) => { e.target.dataset.touched = '1'; });

    const typedByThePerson = (name) => {
        const input = field(name);
        if (! input) return false;
        if (input.tagName === 'SELECT') return input.dataset.touched === '1' && input.value !== input.dataset.mrzValue;
        const value = input.value.trim();

        return value !== '' && value !== (input.dataset.mrzValue ?? null);
    };

    const ask = async (mrz, keep, signal) => {
        form.querySelector('[data-mrz-input]').value = mrz;
        const response = await fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            signal,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': form.querySelector('input[name="_token"]')?.value ?? '',
            },
            body: JSON.stringify({ mrz, keep }),
        });

        return response.ok ? response.json() : { ok: false };
    };

    const apply = (answer, keep) => {
        for (const name of FIELDS) {
            const input = field(name);
            const value = answer.fields?.[name];
            const provisional = (answer.provisional ?? []).includes(name);
            const confirm = confirmOf(name);
            const offer = offerOf(name);

            if (provisional && input && value !== undefined) {
                input.value = value;
                input.dataset.mrzValue = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (confirm) {
                confirm.hidden = ! provisional;
                const box = confirm.querySelector('[data-mrz-confirm]');
                if (box) box.checked = false;
            }
            if (offer) {
                const differs = keep.includes(name) && value !== undefined && input
                    && input.value.trim().toUpperCase() !== String(value).toUpperCase();
                offer.hidden = ! differs;
                if (differs) offer.querySelector('[data-mrz-offer-value]').textContent = shown(value, input);
                offer.dataset.value = differs ? value : '';
            }
        }
    };

    const reader = autoRead({
        fileInput,
        trigger,
        status: root.querySelector('[data-mrz-status]'),
        spinner: root.querySelector('[data-mrz-spinner]'),
        onZone: async (mrz, signal) => {
            const keep = FIELDS.filter(typedByThePerson);
            const answer = await ask(mrz, keep, signal);
            if (signal.aborted || ! answer.ok) return false;
            lastZone = { mrz, keep };
            apply(answer, keep);

            return true;
        },
    });

    // «Usar»: the document's value replaces the typed one, as provisional as the rest — the server is told by asking
    // again with that field no longer kept, so the confirmation gate covers it.
    root.querySelectorAll('[data-mrz-use]').forEach((button) => button.addEventListener('click', async () => {
        if (! lastZone) return;
        const name = button.dataset.mrzUse;
        const keep = lastZone.keep.filter((k) => k !== name);
        const answer = await ask(lastZone.mrz, keep).catch(() => ({ ok: false }));
        if (! answer.ok) return;
        lastZone = { ...lastZone, keep };
        apply(answer, keep);
    }));

    fileInput.addEventListener('change', reader.start);
    trigger.addEventListener('click', reader.start);
    reader.refresh();
}

/**
 * Prompt 215 — the same reader, on the counter's staff sign-up form.
 *
 * 179 built `readMrz()` as a reusable read and wired it to one consumer: the applicant's public form. The staff form
 * has no application yet — the token is minted at submit — so the read goes straight to the Livewire component, which
 * parses it with the SAME `MrzParser` and the same ICAO check-digit rule. One reader, one parser, two callers.
 *
 * Prompt 346 — the read starts by itself on choosing the scan; the component fills only empty fields and offers the
 * rest. Mounted on every Livewire morph as well as on load, because the form appears behind a disclosure and Livewire
 * replaces its markup: a WeakSet remembers which elements are wired (a data-* flag would be morphed away).
 */
const wired = new WeakSet();

export function mountStaffMrzScan(root = document) {
    const trigger = root.querySelector('[data-alta-mrz-scan]');
    const fileInput = root.querySelector('[data-alta-scan]');

    if (! trigger || ! fileInput) {
        return;
    }

    const reader = autoRead({
        fileInput,
        trigger,
        status: root.querySelector('[data-alta-mrz-status]'),
        spinner: root.querySelector('[data-alta-mrz-region] [data-mrz-spinner]'),
        onZone: async (mrz) => {
            const component = window.Livewire?.find(trigger.closest('[wire\\:id]')?.getAttribute('wire:id'));

            return component ? Boolean(await component.call('applyMrz', mrz)) : false;
        },
    });

    // After a morph the trigger comes back `hidden` from the server: show it again if a photo is attached.
    reader.refresh();

    if (! wired.has(fileInput)) {
        wired.add(fileInput);
        fileInput.addEventListener('change', () => reader.start());
    }
    if (! wired.has(trigger)) {
        wired.add(trigger);
        trigger.addEventListener('click', () => reader.start());
    }
}

// WHEN to mount (prompt 223). The applicant's form is on the page at load; the counter's is inserted by a
// Livewire update when the modal opens and re-inserted on every wizard step, so it needs a hook that fires
// after a morph.
//
// `livewire:update` — which this file listened for — **is not a Livewire event**. The dist dispatches only
// `livewire:init`, `livewire:initialized`, `livewire:initializing`, `livewire:navigate[d|ing]`. It had never
// fired once. `morphed` is the real hook and is triggered after each component morph; `mountStaffMrzScan` is
// idempotent (`data-mounted`, trigger re-found each time), so being called on every morph is free.
const mountAll = () => {
    mountMrzScan();
    mountStaffMrzScan();
};

// Registered whichever way round the two bundles execute: if Livewire has already started, hook now;
// otherwise wait for the event that says it is about to.
const hookMorphs = () => window.Livewire?.hook('morphed', () => mountStaffMrzScan());

if (window.Livewire) {
    hookMorphs();
} else {
    document.addEventListener('livewire:init', hookMorphs);
}

// `readyState` guard: loaded from an entry bundle this module may execute after DOMContentLoaded has
// already fired, and an event that has been and gone never comes back.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountAll);
} else {
    mountAll();
}

// Staff-only on navigate, exactly as before: `mountMrzScan` has no idempotence guard of its own, and the
// applicant's page is plain Blade with no Livewire on it — so calling it again here could only ever
// double-bind a listener, never fix one.
document.addEventListener('livewire:navigated', () => mountStaffMrzScan());
