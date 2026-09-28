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
    // Take back our own entry when the overlay closes by any route OTHER than Back.
    pop(name) {
        if (history.state?.counterOverlay === name) history.back();
    },
};

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
    formatGrams(cg, decimal) {
        const whole = Math.floor(cg / 100);
        return `${whole}${decimal}${String(cg % 100).padStart(2, '0')} g`;
    },
};

window.dispensaryPad = (config = {}) => ({
    value: config.value ?? '',
    calc: !! config.calc && !! config.calcEnabled,
    calcEnabled: !! config.calcEnabled,
    weight: !! config.weight,
    rate: config.rateCents ?? null,
    dailyRemaining: config.dailyRemainingCg ?? null,
    decimal: config.decimal ?? ',',
    adding: false,
    push(key) {
        if (key === ',') {
            if (! this.value.includes(',')) this.value = (this.value === '' ? '0' : this.value) + ',';
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
    grams(cg) { return window.dispensaryPadMath.formatGrams(Math.max(0, cg), this.decimal); },
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
        if (/^[0-9]$/.test(e.key)) { e.preventDefault(); this.push(e.key); return; }
        if (e.key === ',' || e.key === '.') { e.preventDefault(); this.push(','); return; }
        if (e.key === 'Backspace') { e.preventDefault(); this.back(); }
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
