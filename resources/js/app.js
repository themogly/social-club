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

        async openScanner() {
            if (!this.supported) return;
            this.error = null;
            this.active = true;

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
            this.$wire.submitCameraScan(token);
        },

        closeScanner() {
            this.teardown();
            this.active = false;
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

        async open() {
            if (!this.supported) return;
            this.error = null;
            this.preview = null;
            this.active = true;

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
            if (this.$wire) {
                this.$wire.$refresh();
            } else {
                window.location.reload();
            }
        },

        close() {
            this.teardown();
            this.active = false;
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
        },
    }));
});
