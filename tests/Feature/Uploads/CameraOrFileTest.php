<?php

namespace Tests\Feature\Uploads;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Prompt 295 (Shane's note 4) — every place a photo or a scan can be given offers two buttons side by side: *Hacer foto*
 * (the camera — the FRONT one for a member's face, the BACK one for everything else) and *Elegir archivo* (the file and
 * gallery picker, never forced to the camera). Some fields said "browse" and opened the camera; some only browsed.
 *
 * Every upload site in the app is listed here, and each one uses the shared pattern or is on the short file-only list
 * (a CSV import and the club logo are not photos). A NEW upload site fails until it is classified.
 */
class CameraOrFileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        view()->share('errors', new ViewErrorBag);
    }

    /** Panel `FileUpload` fields, by file and field → the camera they open (`null`: file-only, not a photo). */
    private const PANEL = [
        'app/Filament/Resources/Genetics/Pages/CreateGenetic.php' => ['lab_report_path' => 'environment', 'images' => 'environment'],
        'app/Filament/Resources/Genetics/Schemas/GeneticForm.php' => ['images' => 'environment'],
        'app/Filament/Resources/Batches/Schemas/BatchForm.php' => ['lab_report_path' => 'environment', 'images' => 'environment'],
        'app/Filament/Resources/Articles/Schemas/ArticleForm.php' => ['images' => 'environment'],
        'app/Filament/Resources/Members/Schemas/MemberForm.php' => ['document_scan_path' => 'environment', 'medical_cert_path' => 'environment', 'photo_path' => 'user'],
        'app/Filament/Resources/Expenses/Schemas/ExpenseForm.php' => ['receipt_path' => 'environment'],
        'app/Filament/Resources/Purchases/Schemas/PurchaseForm.php' => ['invoice_path' => 'environment'],
        'app/Filament/Resources/Members/Pages/ListMembers.php' => ['csv' => null],
        'app/Filament/Pages/ManageOrganisationIdentity.php' => ['logo_path' => null],
    ];

    /** Blade upload sites outside the panel: each is the shared counter field (or the counter's live camera). */
    private const BLADE = [
        'resources/views/livewire/counter/partials/alta-staff-form.blade.php' => ['alta-photo' => 'user', 'alta-scan' => 'environment', 'alta-medical-cert' => 'environment'],
        'resources/views/socio/application.blade.php' => ['photo' => 'user', 'document_scan' => 'environment'],
    ];

    public function test_every_panel_upload_is_classified_and_uses_the_shared_pattern(): void
    {
        $found = [];
        foreach ((new Finder)->files()->in(app_path('Filament'))->name('*.php') as $file) {
            preg_match_all("/FileUpload::make\('([a-z_]+)'\)/", $file->getContents(), $m);
            foreach ($m[1] as $field) {
                $found[Str::after($file->getPathname(), base_path().'/')][] = $field;
            }
        }
        ksort($found);
        $listed = array_map(fn (array $fields): array => array_keys($fields), self::PANEL);
        ksort($listed);
        $this->assertEquals($listed, $found, 'an upload site is not classified: add it to PANEL, as a photo (with its camera) or file-only');

        foreach (self::PANEL as $path => $fields) {
            $source = (string) file_get_contents(base_path($path));
            foreach ($fields as $field => $camera) {
                $call = $this->fieldCall($source, $field);
                if ($camera === null) {
                    $this->assertStringNotContainsString('CameraOrFile', $call, "{$path} {$field} is file-only");
                } else {
                    $this->assertStringContainsString("CameraOrFile::field(FileUpload::make('{$field}')", $call, "{$path} {$field} has no Hacer foto / Elegir archivo");
                    $this->assertStringContainsString("camera: '{$camera}'", $call, "{$path} {$field} opens the wrong camera");
                }
                $this->assertStringNotContainsString("'capture'", $call, "{$path} {$field} forces the camera on its file picker");
            }
        }
    }

    public function test_every_blade_upload_is_the_shared_field_with_the_right_camera(): void
    {
        foreach (self::BLADE as $path => $ids) {
            $source = (string) file_get_contents(base_path($path));
            $this->assertStringNotContainsString('type="file"', $source, "{$path} hand-rolls a file input");
            foreach ($ids as $id => $camera) {
                $this->assertMatchesRegularExpression('/<x-counter\.file-field id="'.preg_quote($id, '/').'"[^>]*camera="'.$camera.'"/', $source, "{$path} {$id}");
            }
        }
    }

    public function test_the_shared_field_renders_two_buttons_and_only_the_camera_one_captures(): void
    {
        $html = Blade::render('<x-counter.file-field id="alta-scan" :label="$label" camera="environment" name="document_scan" accept="image/*" />', ['label' => 'Documento']);

        $this->assertStringContainsString(e(__('Hacer foto')), $html);
        $this->assertStringContainsString(e(__('Elegir archivo')), $html);

        preg_match_all('/<input[^>]*type="file"[^>]*>/', $html, $inputs);
        $this->assertCount(2, $inputs[0]);
        [$camera] = array_values(array_filter($inputs[0], fn (string $i): bool => str_contains($i, 'data-camera-for')));
        [$file] = array_values(array_filter($inputs[0], fn (string $i): bool => str_contains($i, 'id="alta-scan"')));

        $this->assertStringContainsString('capture="environment"', $camera);
        $this->assertStringContainsString('data-camera-for="alta-scan"', $camera, 'the camera does not feed the field');
        $this->assertStringNotContainsString('name=', $camera, 'the camera input would post a second file');
        $this->assertStringNotContainsString('capture', $file, 'Elegir archivo still forces the camera');
        $this->assertStringContainsString('name="document_scan"', $file);

        // The member's own face uses the front camera.
        $this->assertStringContainsString('capture="user"', Blade::render('<x-counter.file-field id="p" label="Foto" camera="user" />'));

        // No camera at all: only Elegir archivo. The shared script hides the camera button where there is none.
        $plain = Blade::render('<x-counter.file-field id="f" label="Archivo" />');
        $this->assertStringNotContainsString(e(__('Hacer foto')), $plain);
        $js = (string) file_get_contents(resource_path('js/photo-buttons.js'));
        $this->assertStringContainsString('enumerateDevices', $js);
        $this->assertStringContainsString("kind === 'videoinput'", $js);
    }

    public function test_the_live_camera_keeps_elegir_archivo_beside_it_without_capture(): void
    {
        $source = (string) file_get_contents(resource_path('views/components/counter/photo-capture.blade.php'));

        $this->assertStringContainsString("__('Hacer foto')", $source);
        $this->assertStringContainsString("__('Elegir archivo')", $source);
        $this->assertStringNotContainsString('capture=', $source, 'its file picker forces the camera');
    }

    /** The source of one `FileUpload::make('field')` call, up to the next field or the end of the schema. */
    private function fieldCall(string $source, string $field): string
    {
        $at = strpos($source, "FileUpload::make('{$field}')");
        $this->assertNotFalse($at);
        $start = strrpos(substr($source, 0, $at), "\n");
        $next = preg_match('/\n\s*(?:\w+::make|\]\))/', $source, $m, PREG_OFFSET_CAPTURE, $at + 10) ? $m[0][1] : strlen($source);

        return substr($source, (int) $start, $next - (int) $start);
    }
}
