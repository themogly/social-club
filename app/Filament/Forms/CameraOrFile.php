<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\FileUpload;

/**
 * Prompt 295 (Shane's note 4) — a panel photo or scan field offers *Hacer foto* and *Elegir archivo*, side by side.
 *
 * FilePond's drop zone only ever browsed (or, where a field set `capture`, only ever opened the camera — "it says browse
 * but opens the camera"). This adds the two buttons above the drop zone and hands their file to the SAME FilePond instance
 * through its own API (`addFile`, `browse`), so validation, size limits, the private disk, encryption and the preview
 * are exactly the field's. `camera`: `user` (front) for a member's face, `environment` (back) for everything else. A
 * device with no camera shows only *Elegir archivo*.
 */
final class CameraOrFile
{
    public static function field(FileUpload $upload, string $camera, string $accept = 'image/*'): FileUpload
    {
        // ABOVE the drop zone: `helperText()` is Filament's below-content slot, and the size limit and the encryption notice
        // live there — putting the buttons below would replace them.
        return $upload->aboveContent(fn () => view('filament.forms.camera-or-file', ['camera' => $camera, 'accept' => $accept]));
    }
}
