// Prompt 330 — the optional tone when a new sign-up reaches the counter. The bell (App\Livewire\Counter\
// PendingApplicationsBell) dispatches `applications-arrived` with `chime` true only where the sede switched
// *Sonido al recibir solicitudes* on (off by default). Two short notes from WebAudio: no file to fetch, nothing
// cached. A browser that has not had a tap on the page yet may refuse to play; the banner is the notice either way.
let audio = null;

window.addEventListener('applications-arrived', (event) => {
    if (! event.detail?.chime || ! window.AudioContext) return;

    try {
        audio ??= new window.AudioContext();
        audio.resume?.();
        [[880, 0], [1320, 0.18]].forEach(([frequency, at]) => {
            const tone = audio.createOscillator();
            const gain = audio.createGain();
            const start = audio.currentTime + at;
            tone.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(0.2, start + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.25);
            tone.connect(gain).connect(audio.destination);
            tone.start(start);
            tone.stop(start + 0.3);
        });
    } catch {
        // No sound is not an error: the banner and the badge carry the notice.
    }
});
