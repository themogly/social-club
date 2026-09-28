// Prompt 295 (Shane) — *Hacer foto* beside *Elegir archivo* on every photo or scan (`x-counter.file-field`).
//
// The camera button is its own `<input capture>` with no name; when it takes a picture, the file is handed to the
// field's real input (the one *Elegir archivo* opens), and that input's `change` fires — so `wire:model`, a form post,
// the MRZ reader and the close guard all see one field, exactly as if the file had been chosen.
document.addEventListener('change', (event) => {
    const camera = event.target instanceof HTMLInputElement && event.target.matches('input[data-camera-for]') ? event.target : null;
    const field = camera ? document.getElementById(camera.dataset.cameraFor) : null;
    if (! camera || ! field || ! camera.files?.length) return;

    const transfer = new DataTransfer();
    [...camera.files].forEach((file) => transfer.items.add(file));
    field.files = transfer.files;
    camera.value = '';
    field.dispatchEvent(new Event('change', { bubbles: true }));
});

// A device with no camera (the owner's desktop) shows only *Elegir archivo*. If the check itself is unavailable or
// fails, both stay: the camera button then simply opens the file picker.
navigator.mediaDevices?.enumerateDevices?.()
    .then((devices) => {
        document.documentElement.toggleAttribute('data-no-camera', ! devices.some((device) => device.kind === 'videoinput'));
    })
    .catch(() => {});
