// Prompt 299 — a keyboard-wedge card reader with the member search hidden.
//
// Once a socio is chosen the dispensary no longer shows the search field, so a USB / Bluetooth card reader — which
// "types" the card's token and presses Enter — has nowhere to type. This catches it at window level: a burst of
// characters arriving faster than a person can type (a gap of at most `gapMs` between keys), ended by Enter, is a
// card. It is sent to the same token resolution as the search.
//
// People are never caught: slow typing passes straight through, and nothing is taken while focus is in a field or a
// dialog is open. The weight pad listens to the whole window too, so keys are SWALLOWED once a burst is certain — from
// the `leak`-th fast key on (three gaps under 50 ms in a row is not a person) — and the burst's Enter never reaches the
// pad (it would add a line). The few keys that did reach it are undone by the pad from `startedAt` (see app.js).

export const WEDGE_GAP_MS = 50;
export const WEDGE_MIN_LENGTH = 8;
export const WEDGE_LEAK = 3;

/** Focus in a field or a contenteditable: typing there is the person's, never a card. */
export function isTypingTarget(target) {
    return Boolean(target?.closest?.('input, textarea, select, [contenteditable="true"]'));
}

/**
 * @param {{ onScan: (value: string, startedAt: number) => void, gapMs?: number, minLength?: number, leak?: number,
 *           ignore?: (target: any) => boolean, dialogOpen?: () => boolean }} options
 */
export function createCardWedge({ onScan, gapMs = WEDGE_GAP_MS, minLength = WEDGE_MIN_LENGTH, leak = WEDGE_LEAK, ignore = isTypingTarget, dialogOpen = () => false }) {
    let buffer = '';
    let last = null;
    let startedAt = null;
    let fastRun = 0;

    const reset = () => { buffer = ''; last = null; startedAt = null; fastRun = 0; };

    return {
        reset,
        /** @returns {'pass'|'swallow'|'scan'} what the caller must do with the event */
        handle(event) {
            if (event.ctrlKey || event.metaKey || event.altKey || ignore(event.target) || dialogOpen()) {
                reset();

                return 'pass';
            }

            const fast = last !== null && event.timeStamp - last <= gapMs;

            if (event.key === 'Enter') {
                const value = buffer;
                const began = startedAt;
                const isCard = fast && fastRun >= leak && value.length >= minLength;
                reset();
                if (! isCard) return 'pass';
                onScan(value, began);

                return 'scan';
            }

            if (typeof event.key !== 'string' || event.key.length !== 1) return 'pass'; // Shift and friends: part of a burst

            if (fast) {
                buffer += event.key;
                last = event.timeStamp;
                fastRun++;

                return fastRun >= leak ? 'swallow' : 'pass';
            }

            buffer = event.key;
            last = event.timeStamp;
            startedAt = event.timeStamp;
            fastRun = 0;

            return 'pass';
        },
    };
}
