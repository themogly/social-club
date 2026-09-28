{{-- Prompt 295 — Hacer foto / Elegir archivo above a panel upload (App\Filament\Forms\CameraOrFile). Both feed the field's
     own FilePond instance, found through the field wrapper; the camera button hides itself where there is no camera. --}}
<div
    data-camera-or-file
    x-data="{
        camera: true,
        init() {
            navigator.mediaDevices?.enumerateDevices?.()
                .then((devices) => { this.camera = devices.some((d) => d.kind === 'videoinput') })
                .catch(() => {});
        },
        pond() {
            const upload = this.$el.closest('.fi-fo-field')?.querySelector('.fi-fo-file-upload');
            return upload ? window.Alpine.$data(upload).pond : null;
        },
        take(event) {
            [...event.target.files].forEach((file) => this.pond()?.addFile(file));
            event.target.value = '';
        },
    }"
    class="flex flex-wrap gap-2"
>
    <x-filament::button tag="label" color="gray" size="sm" icon="heroicon-o-camera" x-show="camera" class="min-h-11 cursor-pointer">
        <input type="file" accept="{{ $accept }}" capture="{{ $camera }}" class="sr-only" x-on:change="take($event)">
        {{ __('Hacer foto') }}
    </x-filament::button>
    <x-filament::button color="gray" size="sm" icon="heroicon-o-folder-open" x-on:click="pond()?.browse()" class="min-h-11">
        {{ __('Elegir archivo') }}
    </x-filament::button>
</div>
