// Prompt 223 — the counter's entry point, and where prompt 179's MRZ reader is loaded from.
//
// It used to be `@vite`d from inside `alta-staff-form.blade.php`, which is markup Livewire MORPHS in and
// out: the module therefore arrived inside the very update that inserted the trigger it was supposed to
// mount, so every hook it registered was registered too late, and the button stayed hidden for ever. There
// is no full-page view to move it to either — `membership-counter` IS the Livewire component, so its whole
// template is inside the morph target. The layout and this entry are the only homes outside it, and the
// layout already loads this file on every counter screen.
//
// The module itself is ~4KB and the OCR engine is NOT in it: `readMrz()` dynamically imports tesseract.js
// on the first click, so loading this early costs a few kilobytes and no engine.
import './mrz-reader.js';
import { catalogueShows } from './catalogue-search.js';
import { createCardWedge } from './card-wedge.js';
import './photo-buttons.js';
import './applications-chime.js';

// Counter camera QR scanner (prompt 35) — a progressive enhancement registered on Alpine
// (which Livewire ships). Uses the native BarcodeDetector where available (Chrome/Edge/
// Android counter tablets); where it is not, `supported` stays false and the trigger hides
// itself, so the wedge scanner + name search remain the working path — the camera never
// gates identification. A decoded QR is handed to the SAME server lookup the wedge uses
// (`$wire.submitCameraScan` → ResolveMemberByToken). Translated copy is passed in from Blade.
// A pure-JS fallback (jsQR) for Safari/Firefox is a documented follow-up (needs a browser to
// add + verify). Camera access requires a secure context (HTTPS or localhost).
// Prompt 272 — the Android back gesture closes a counter overlay before it leaves the page (CLAUDE.md: pushState
// on open, popstate closes). The receipt and document sheets did this; the camera and photo overlays did not, so
// Back left the screen with the camera still live. `overlayHistory` is the one shared pair of moves.
const overlayHistory = {
    push(name) {
        history.pushState({ counterOverlay: name }, '');
    },
    // Take back our own entry when the overlay closes by any route OTHER than Back. With the root guard below, that
    // lands on the guard entry — never the root — so it can never trip the root handler.
    pop(name) {
        if (history.state?.counterOverlay === name) history.back();
    },
};

// Prompt 313 — in the INSTALLED app (display-mode standalone/fullscreen, 290), Back at the start of the history means
// "leave the app"; with Android app pinning on, the app is refused leave and restarts on its launch screen — in a loop,
// with the "to unpin" toast flashing. So the start is guarded: the page's own entry is marked as the root and a guard
// entry sits above it; Back onto the root pushes the guard again and does nothing else (no navigation, no reload).
// Overlays keep their own entries ABOVE the guard, so Back still closes them first. NEVER in a normal browser tab:
// trapping Back there would be hostile — leaving the counter is the top bar's job (tabs, Administración, sign out).
const rootBackGuard = {
    standalone() {
        return ['standalone', 'fullscreen'].some((mode) => window.matchMedia?.(`(display-mode: ${mode})`)?.matches);
    },
    install() {
        if (! this.standalone() || history.state?.cscGuard) return;
        history.replaceState({ ...(history.state ?? {}), cscRoot: true }, '');
        history.pushState({ cscGuard: true }, '');
        window.addEventListener('popstate', (event) => {
            if (! event.state?.cscRoot) return;
            history.pushState({ cscGuard: true }, '');
            this.hint();
        });
    },
    // Once a session, a quiet line so a swallowed Back is not a mystery. Never a dialog (that would need Back to close).
    hint() {
        try {
            if (sessionStorage.getItem('cscBackHinted')) return;
            sessionStorage.setItem('cscBackHinted', '1');
        } catch {
            return;
        }
        window.dispatchEvent(new CustomEvent('csc-back-swallowed'));
    },
};
rootBackGuard.install();

// Prompt 286 — every PIN entry behaves the same while its answer is on the way. The owner: "if the PIN is correct, say
// so straight away and don't let them keep retrying". The pad used to empty its dots and look idle for the whole
// round trip, so people retyped — each retype that landed wrong cost an attempt against the lockout. Now:
//   checking — one request in flight at most (a second submit is impossible, not ignored); keys, Borrar, backspace,
//              the confirm and the keyboard do nothing; the dots stay filled; the confirm reads "Comprobando…".
//   holding  — a correct PIN shows "Hola, Marta" (~600 ms) before the counter continues; nothing can be typed.
//   shaking  — a wrong PIN shakes once and clears; the server's line says how many attempts are left.
// `call` is the `$wire` action; its promise carries the server's outcome ({ok, name}). The state always clears in
// `finally`, so a network error can never leave a pad stuck. `feedback: false` (the supervisor and till-handover PIN
// fields) keeps only the checking state: their outcome is the act itself, reported by the screen.
// Prompt 290 — "Instalar como app". Chrome offers installation by firing `beforeinstallprompt`; keep it, and tell the top
// bar's button it may show. A browser that never fires it never shows the button (no dead control); once running as the
// installed app (`display-mode: standalone`) it hides. Installing grants nothing — the PIN still gates everything.
window.cscInstallPrompt = null;
window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    window.cscInstallPrompt = event;
    window.dispatchEvent(new CustomEvent('csc-installable'));
});
window.addEventListener('appinstalled', () => {
    window.cscInstallPrompt = null;
    window.dispatchEvent(new CustomEvent('csc-installed'));
});

// Prompt 292 — the dispensary keypad runs in the BROWSER. Every key used to be a full server round trip that re-rendered
// the whole dispensary (~170 KB), and Livewire queues one request per component, so taps waited in line — the "disabled
// pad" the owner felt. Now keys, the comma rule, backspace, presets and the Gramos/€ switch are local; the value goes to
// the server only with "Añadir a la cesta" (`addLine(value, mode)`), which validates it exactly as before.
//
// The preview mirrors the PHP rules with integer arithmetic — never a float product that can land on 199.9999:
//   grams: Weight::canonicalGrams (digits, optional , or . and 1–2 decimals) → centigrams
//   euros: Money::parseTyped (1–9 digits, optional 1–2 decimals) → cents; grams = floor(cents × 100 / rate)
// One PHP resolver (DispensaryPos::resolveGramsCg), this one mirror, and a parity harness that proves they agree.
window.dispensaryPadMath = {
    gramsToCg(value) {
        const m = String(value ?? '').trim().match(/^(\d+)(?:[.,](\d{1,2}))?$/);
        return m ? parseInt(m[1], 10) * 100 + parseInt((m[2] ?? '0').padEnd(2, '0'), 10) : null;
    },
    eurosToCents(value) {
        const m = String(value ?? '').trim().match(/^(\d{1,9})(?:[.,](\d{1,2}))?$/);
        return m ? parseInt(m[1], 10) * 100 + parseInt((m[2] ?? '0').padEnd(2, '0'), 10) : null;
    },
    backSolveCg(cents, rateCents) {
        if (cents === null || cents <= 0 || ! rateCents || rateCents <= 0) return null;
        const scaled = cents * 100;
        return (scaled - (scaled % rateCents)) / rateCents; // floor, in integers
    },
    // The one display rule (prompt 316): a decimal point in every language — NumberFormat on the server.
    formatGrams(cg) {
        const whole = Math.floor(cg / 100);
        return `${whole}.${String(cg % 100).padStart(2, '0')} g`;
    },
};

// Prompt 333 — a strain tap brings the weight entry into view: scrolled to the top of its scrolling pane (the selection
// pane at md+, the page below it) ONLY when it is not already fully visible, smoothly unless the operator asked for
// reduced motion, and focused so the physical keyboard (292) types into the pad at once. No request: the panel mounts
// with the render `chooseGenetic` already makes.
window.bringIntoView = (el) => {
    if (! el) return;
    const pane = el.closest('[data-selection-pane]');
    const box = el.getBoundingClientRect();
    const frame = pane && pane.scrollHeight > pane.clientHeight ? pane.getBoundingClientRect() : { top: 0, bottom: window.innerHeight };
    const top = Math.max(frame.top, 0);
    const bottom = Math.min(frame.bottom, window.innerHeight);
    if (box.top < top || box.bottom > bottom) {
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        el.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
    }
    el.focus({ preventScroll: true });
};

window.dispensaryPad = (config = {}) => ({
    value: config.value ?? '',
    calc: !! config.calc && !! config.calcEnabled,
    calcEnabled: !! config.calcEnabled,
    weight: !! config.weight,
    rate: config.rateCents ?? null,
    dailyRemaining: config.dailyRemainingCg ?? null,
    adding: false,
    trail: [], // prompt 299 — the value before each keyboard key, so a card-reader burst's leaked keys can be undone
    push(key) {
        // Prompt 316 — the decimal key is a POINT, as every figure is shown; a typed comma is read as the same key.
        if (key === '.' || key === ',') {
            if (! /[.,]/.test(this.value)) this.value = (this.value === '' ? '0' : this.value) + '.';
            return;
        }
        this.value += key; // no length cap, as the server pad had none; addLine validates the value
    },
    back() { this.value = this.value.slice(0, -1); },
    clear() { this.value = ''; },
    setMode(calc) {
        calc = calc && this.calcEnabled;
        if (calc !== this.calc) { this.calc = calc; this.value = ''; }
    },
    preset(cg, label) { this.calc = false; this.value = label; },
    get enteredCg() {
        const m = window.dispensaryPadMath;
        return this.calc ? m.backSolveCg(m.eurosToCents(this.value), this.rate) : m.gramsToCg(this.value);
    },
    get remainingAfter() {
        return this.dailyRemaining === null || this.enteredCg === null ? null : this.dailyRemaining - this.enteredCg;
    },
    grams(cg) { return window.dispensaryPadMath.formatGrams(Math.max(0, cg)); },
    // One request in flight: a double tap adds one line. The keys are never disabled by a request.
    add() {
        if (this.adding) return;
        this.adding = true;
        const call = this.weight ? this.$wire.addLine(this.value, this.calc ? 'calculator' : 'grams') : this.$wire.addLine();
        Promise.resolve(call).finally(() => {
            this.adding = false;
            this.value = this.$wire.weightInput ?? '';
        });
    },
    // A physical keyboard types into the same pad (digits, , or ., Backspace); Enter adds — except when focus is on one
    // of the pad's own buttons (those activate themselves) or in any other field, or an overlay is open (272's rule).
    onKey(e) {
        if (! this.weight || e.ctrlKey || e.metaKey || e.altKey) return;
        const t = e.target;
        if (t?.closest?.('input, textarea, select, [contenteditable="true"]')) return;
        if ([...document.querySelectorAll('[role="dialog"][aria-modal="true"]')].some((d) => d.offsetParent !== null)) return;
        if (e.key === 'Enter') {
            if (t?.closest?.('[data-weight-pad] button, [data-add-line]')) return;
            e.preventDefault(); this.add(); return;
        }
        if (/^[0-9]$/.test(e.key)) { e.preventDefault(); this.remember(e); this.push(e.key); return; }
        if (e.key === ',' || e.key === '.') { e.preventDefault(); this.remember(e); this.push('.'); return; }
        if (e.key === 'Backspace') { e.preventDefault(); this.remember(e); this.back(); }
    },
    remember(e) {
        this.trail.push({ t: e.timeStamp, value: this.value });
        if (this.trail.length > 12) this.trail.shift();
    },
    // Prompt 299 — a card reader's first keys reach the pad before the burst is recognised (card-wedge.js); put the
    // value back as it was before the burst began.
    undoSince(startedAt) {
        const first = this.trail.find((entry) => entry.t >= startedAt - 1);
        if (first) this.value = first.value;
        this.trail = [];
    },
});

// Prompt 299 — the keyboard-wedge catcher (card-wedge.js), mounted on the dispensary only while a socio is chosen and
// only when the sede has card readers on. Capture phase, so a burst it recognises never reaches the weight pad.
window.cardWedge = () => ({
    wedge: null,
    handler: null,
    init() {
        this.wedge = createCardWedge({
            onScan: (value, startedAt) => {
                window.dispatchEvent(new CustomEvent('counter-card-scan', { detail: { startedAt } }));
                this.$wire.submitWedgeScan(value);
            },
            dialogOpen: () => [...document.querySelectorAll('[role="dialog"][aria-modal="true"]')].some((d) => d.offsetParent !== null),
        });
        this.handler = (e) => {
            if (this.wedge.handle(e) !== 'pass') { e.preventDefault(); e.stopImmediatePropagation(); }
        };
        window.addEventListener('keydown', this.handler, true);
    },
    destroy() {
        window.removeEventListener('keydown', this.handler, true);
    },
});

// Prompt 293 — the catalogue pane's view controls, in the browser. The tab (Dispensario / Barra), the filters, the search
// and the list/grid/large toggle only change what is VISIBLE over a catalogue that is already on the page in full, so
// they make no request at all: each used to re-render and re-send the whole screen (~170 KB at club size). A tap on a
// card is still a server action (`$wire.chooseGenetic`, `$wire.addBarItem`, `$wire.addArticle`) — price, limits and
// stock are decided there, never here. The layout choice is still remembered per device by its #[Session] property:
// `$wire.$set(prop, mode, false)` hands it over with the next real request instead of making one.
window.counterCatalogue = (config = {}) => ({
    source: config.source ?? 'genetics',
    layouts: { ...(config.layouts ?? {}) },
    layoutProps: config.layoutProps ?? {},
    search: { genetics: '', bar: '' },
    category: { genetics: null, bar: null },
    productType: null,
    strainType: null,
    filtersOpen: false,
    init() {
        this.$store.counterCatalogue.source = this.source;
    },
    setSource(source) {
        this.source = source;
        this.$store.counterCatalogue.source = source;
    },
    layoutOf(source) {
        return this.layouts[source ?? this.source];
    },
    setLayout(mode) {
        this.layouts[this.source] = mode;
        const prop = this.layoutProps[this.source];
        if (prop) this.$wire.$set(prop, mode, false);
    },
    filter(axis, value) {
        if (axis === 'category') this.category[this.source] = value;
        else this[axis] = value;
    },
    get activeFilters() {
        if (this.source === 'bar') return this.category.bar === null ? 0 : 1;
        return [this.category.genetics, this.productType, this.strainType].filter((v) => v !== null).length;
    },
    // A card lists its own facts as data attributes, so a card added by a later render is filtered like the rest.
    visible(el) {
        const d = el.dataset;
        return catalogueShows(
            { source: d.catalogueItem, category: d.category, type: d.type, strain: d.strain, search: (d.search ?? '').split('\n') },
            this,
        );
    },
    anyVisible(source) {
        // Read the filter state first so Alpine re-evaluates this when any of it changes.
        const deps = [this.search[source], this.category[source], this.productType, this.strainType];
        return deps && [...this.$root.querySelectorAll(`[data-catalogue-item="${source}"]`)].some((el) => this.visible(el));
    },
});

window.counterPinCheck = () => ({
    checking: false,
    holding: false,
    shaking: false,
    greeting: '',
    pinBusy() {
        return this.checking || this.holding;
    },
    async checkPin(call, { feedback = true } = {}) {
        if (this.checking || this.holding) return null;
        this.checking = true;
        let outcome = null;
        try {
            outcome = await call();
        } catch (e) {
            outcome = null;
        } finally {
            this.checking = false;
        }
        if (! feedback) return outcome;
        if (outcome && outcome.ok) {
            this.greeting = outcome.name || '';
            this.holding = true;
            await new Promise((resolve) => setTimeout(resolve, 600));
            this.holding = false;
            this.greeting = '';
        } else {
            this.shaking = true;
            setTimeout(() => { this.shaking = false; }, 400);
        }
        return outcome;
    },
});

document.addEventListener('alpine:init', () => {
    // Prompt 313 — a counter dialog rendered with @if (the terminal dialog, Mis horas): its history entry and its Back
    // listener live exactly as long as the dialog does. `onBack` runs when Back closes it; closed any other way, the
    // dialog leaves the page, `destroy()` removes the listener and takes back its own entry — so a later, unrelated
    // Back can never call its close again (the `{ once: true }` listeners it replaces stayed behind).
    window.Alpine.data('historyDialog', (name, onBack) => ({
        init() {
            this.poppedByBack = false;
            this.onPopState = () => {
                if (history.state?.counterOverlay === name) return;
                this.poppedByBack = true;
                window.removeEventListener('popstate', this.onPopState);
                onBack();
            };
            overlayHistory.push(name);
            window.addEventListener('popstate', this.onPopState);
        },
        destroy() {
            window.removeEventListener('popstate', this.onPopState);
            if (! this.poppedByBack) overlayHistory.pop(name);
        },
    }));

    // Which catalogue source the dispensary is browsing (prompt 293) — the cart reads it to show the bar section.
    window.Alpine.store('counterCatalogue', { source: 'genetics' });

    window.Alpine.data('cameraScan', (config = {}) => ({
        messages: config.messages || {},
        supported:
            typeof window !== 'undefined' &&
            'BarcodeDetector' in window &&
            !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
        active: false,
        error: null,
        stream: null,
        detector: null,
        timer: null,
        busy: false,

        init() {
            this.onPopState = () => {
                if (this.active) {
                    this.teardown();
                    this.active = false;
                }
            };
            window.addEventListener('popstate', this.onPopState);
        },

        async openScanner() {
            if (!this.supported) return;
            this.error = null;
            this.active = true;
            overlayHistory.push('camera');

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment' },
                    audio: false,
                });
            } catch (e) {
                this.error = this.messages.camera || 'Camera unavailable.';
                return;
            }

            const video = this.$refs.video;
            if (video) {
                video.srcObject = this.stream;
                try {
                    await video.play();
                } catch (e) {
                    /* autoplay rejection is non-fatal */
                }
            }

            try {
                this.detector = new BarcodeDetector({ formats: ['qr_code'] });
            } catch (e) {
                this.error = this.messages.unsupported || 'Camera scanning is not supported here.';
                return;
            }

            this.timer = setInterval(() => this.tick(), 250);
        },

        async tick() {
            const video = this.$refs.video;
            if (this.busy || !this.detector || !video || video.readyState < 2) return;

            this.busy = true;
            try {
                const codes = await this.detector.detect(video);
                const value = codes && codes.length ? String(codes[0].rawValue || '').trim() : '';
                if (value) {
                    this.onDecoded(value);
                }
            } catch (e) {
                /* transient decode error — keep scanning */
            } finally {
                this.busy = false;
            }
        },

        onDecoded(token) {
            this.teardown();
            this.active = false;
            overlayHistory.pop('camera');
            this.$wire.submitCameraScan(token);
        },

        closeScanner() {
            this.teardown();
            this.active = false;
            overlayHistory.pop('camera');
        },

        // Release the camera and stop the scan loop — always call before leaving the screen.
        teardown() {
            if (this.timer) {
                clearInterval(this.timer);
                this.timer = null;
            }
            if (this.stream) {
                this.stream.getTracks().forEach((track) => track.stop());
                this.stream = null;
            }
            this.detector = null;
            this.busy = false;
        },

        destroy() {
            this.teardown();
            window.removeEventListener('popstate', this.onPopState);
        },
    }));

    // Counter member-photo capture (prompt 157) — a progressive enhancement over the upload fallback. Where
    // getUserMedia exists it offers a live camera: a still frame is drawn to a canvas, previewed for
    // confirm/retake, then POSTed as a JPEG to the capture endpoint. Where the API is missing, `supported`
    // stays false, the camera trigger hides itself, and the plain file input remains — the counter is never
    // blocked. After a successful write the host Livewire component is refreshed so the new photo renders
    // through its short-lived signed URL. Secure context required (HTTPS or localhost), like the QR scanner.
    window.Alpine.data('photoCapture', (config = {}) => ({
        endpoint: config.endpoint || '',
        csrf: config.csrf || '',
        source: config.source || 'counter',
        messages: config.messages || {},
        supported:
            typeof navigator !== 'undefined' &&
            !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
        active: false,
        busy: false,
        error: null,
        preview: null, // dataURL of the captured still, awaiting confirm
        stream: null,

        init() {
            this.onPopState = () => {
                if (this.active) {
                    this.teardown();
                    this.active = false;
                }
            };
            window.addEventListener('popstate', this.onPopState);
        },

        async open() {
            if (!this.supported) return;
            this.error = null;
            this.preview = null;
            this.active = true;
            overlayHistory.push('photo');

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'user' },
                    audio: false,
                });
            } catch (e) {
                this.error = this.messages.camera || 'Camera unavailable.';
                return;
            }

            const video = this.$refs.video;
            if (video) {
                video.srcObject = this.stream;
                try {
                    await video.play();
                } catch (e) {
                    /* autoplay rejection is non-fatal */
                }
            }
        },

        capture() {
            const video = this.$refs.video;
            if (!video || video.readyState < 2) return;

            // Cap the long edge so a tablet's full-resolution frame is not a multi-MB upload.
            const maxEdge = 900;
            const scale = Math.min(1, maxEdge / Math.max(video.videoWidth, video.videoHeight));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(video.videoWidth * scale);
            canvas.height = Math.round(video.videoHeight * scale);
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            this.preview = canvas.toDataURL('image/jpeg', 0.85);
        },

        retake() {
            this.preview = null;
        },

        async confirm() {
            if (!this.preview || this.busy) return;
            this.busy = true;
            this.error = null;
            try {
                const blob = await (await fetch(this.preview)).blob();
                await this.send(blob, 'photo.jpg');
            } catch (e) {
                this.error = this.messages.failed || 'Upload failed.';
                this.busy = false;
            }
        },

        // Upload fallback — a file the operator chose from the device (broken camera, or a club that
        // photographs people another way).
        async fromFile(event) {
            const file = event.target && event.target.files && event.target.files[0];
            if (!file) return;
            this.busy = true;
            this.error = null;
            try {
                await this.send(file, file.name || 'photo.jpg');
            } catch (e) {
                this.error = this.messages.failed || 'Upload failed.';
                this.busy = false;
            }
        },

        async send(blob, filename) {
            const body = new FormData();
            body.append('photo', blob, filename);
            body.append('source', this.source);

            const res = await fetch(this.endpoint, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrf, Accept: 'application/json' },
                body,
            });

            if (!res.ok) throw new Error('bad status');

            this.teardown();
            this.active = false;
            this.busy = false;
            overlayHistory.pop('photo');
            if (this.$wire) {
                this.$wire.$refresh();
            } else {
                window.location.reload();
            }
        },

        close() {
            this.teardown();
            this.active = false;
            overlayHistory.pop('photo');
        },

        teardown() {
            if (this.stream) {
                this.stream.getTracks().forEach((track) => track.stop());
                this.stream = null;
            }
            this.preview = null;
        },

        destroy() {
            this.teardown();
            window.removeEventListener('popstate', this.onPopState);
        },
    }));
});
