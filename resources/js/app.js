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
});
