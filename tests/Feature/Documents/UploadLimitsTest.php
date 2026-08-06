<?php

namespace Tests\Feature\Documents;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Members\Pages\CreateMember;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use App\Support\DocumentUpload;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Prompt 164 — three limits applied to the same upload and none of them agreed. nginx's
 * `client_max_body_size` was the smallest and the one that actually fired, rejecting a 3.86 MB phone
 * photo of a DNI BEFORE PHP ran: nothing in the Laravel log, and the member saw only Livewire's generic
 * "failed to upload". The application itself declared nothing at all — no `maxSize()` on any document
 * upload — so it could not refuse anything, or say why.
 *
 * These pin the application having its own stated opinion, in ONE place.
 */
class UploadLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    // --- The shared definition -----------------------------------------------------------------

    public function test_the_limit_is_one_definition_below_the_server_ceiling(): void
    {
        $this->assertSame(12288, DocumentUpload::maxKilobytes());       // 12 MB
        $this->assertSame('max:12288', DocumentUpload::maxRule());
        $this->assertSame('12 MB', DocumentUpload::limitLabel());

        // It has to sit below the smallest server limit or it never fires. nginx is being set to 20 MB.
        $this->assertLessThan(20 * 1024, DocumentUpload::maxKilobytes());

        // …and it must not exceed Livewire's own temporary-upload rule, or Livewire would refuse
        // generically for a file this application had just told the member was acceptable.
        $this->assertLessThanOrEqual(12288, DocumentUpload::maxKilobytes());
    }

    public function test_a_missing_or_broken_config_degrades_to_the_fallback_rather_than_throwing(): void
    {
        config(['documents.max_upload_kb' => null]);
        $this->assertSame(12288, DocumentUpload::maxKilobytes());

        config(['documents.max_upload_kb' => 0]);
        $this->assertSame(12288, DocumentUpload::maxKilobytes());
    }

    public function test_the_label_never_overstates_the_real_ceiling(): void
    {
        // A stated limit larger than the real one sends someone off to pick a file that is then
        // refused — the exact failure this branch exists to remove. So it rounds DOWN.
        config(['documents.max_upload_kb' => 6000]);          // 5.86 MB
        $this->assertSame('5 MB', DocumentUpload::limitLabel());
    }

    // --- Every documents-disk upload carries it --------------------------------------------------

    public function test_every_file_upload_on_the_documents_disk_declares_the_shared_limit(): void
    {
        $checked = [];

        foreach ($this->resourceClasses() as $resource) {
            foreach ($this->fileUploads($resource::form(Schema::make($this->harness()))) as $upload) {
                if ($upload->getDiskName() !== 'documents') {
                    continue;
                }

                $name = class_basename($resource).'::'.$upload->getName();
                $checked[] = $name;

                $this->assertSame(
                    DocumentUpload::maxKilobytes(),
                    $upload->getMaxSize(),
                    "{$name} uploads to the private documents disk but does not declare the shared size limit. ".
                    'Add ->maxSize(DocumentUpload::maxKilobytes()).'
                );
            }
        }

        sort($checked);
        $this->assertSame([
            'BatchResource::lab_report_path',
            'ExpenseResource::receipt_path',
            'MemberResource::document_scan_path',
            'MemberResource::medical_cert_path',
            'MemberResource::photo_path',
            'PurchaseResource::invoice_path',
        ], $checked, 'The set of documents-disk uploads changed — re-check this branch covers the new one.');
    }

    public function test_no_documents_disk_upload_anywhere_in_the_app_is_missing_the_limit(): void
    {
        // The schema walk above cannot reach uploads declared on Filament Pages or inside action
        // forms, so this catches one added there too: any FileUpload chain that targets the
        // documents disk must also declare a maxSize.
        $offenders = [];

        foreach ($this->phpFilesIn(app_path()) as $file) {
            $source = (string) file_get_contents($file);

            foreach ($this->fileUploadChains($source) as $chain) {
                if (! str_contains($chain, "disk('documents')")) {
                    continue;
                }
                // Both halves matter: the ceiling itself, and telling the person about it BEFORE they
                // choose a file and wait for an upload that was never going to succeed.
                if (! str_contains($chain, 'maxSize(') || ! str_contains($chain, 'DocumentUpload::helperText(')) {
                    $offenders[] = Str::after($file, app_path().'/');
                }
            }
        }

        $this->assertSame([], array_unique($offenders),
            'FileUpload(s) on the private documents disk missing ->maxSize(DocumentUpload::maxKilobytes()) '.
            'or DocumentUpload::helperText(): '.implode(', ', array_unique($offenders)));
    }

    public function test_the_stated_limit_reaches_the_screen_before_a_file_is_chosen(): void
    {
        Storage::fake('documents');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);
        $this->actingAs($user);

        Livewire::test(CreateMember::class)->assertSee(DocumentUpload::limitLabel());
    }

    public function test_the_existing_type_restrictions_are_untouched(): void
    {
        $types = [];

        foreach ($this->fileUploads(MemberResource::form(Schema::make($this->harness()))) as $upload) {
            $types[$upload->getName()] = $upload->getAcceptedFileTypes();
        }

        $this->assertSame(['application/pdf', 'image/*'], $types['document_scan_path']);
        $this->assertSame(['application/pdf', 'image/*'], $types['medical_cert_path']);
    }

    // --- End to end: the applicant form, which is where the failure reached a real person ---------

    public function test_an_oversize_photo_is_refused_by_the_application_naming_the_limit(): void
    {
        Storage::fake('documents');
        $this->invite('t');

        $response = $this->from(route('socio.application', ['token' => 't']))
            ->post(route('socio.application.store', ['token' => 't']), $this->formData([
                'photo' => UploadedFile::fake()->create('dni.jpg', 13_000, 'image/jpeg'),   // 12.7 MB
            ]));

        $response->assertSessionHasErrors('photo');

        // Refused BY THE APP, with a sentence that names the limit — not a silent server rejection,
        // and not a raw `validation.max.file` key.
        $message = (string) session('errors')->first('photo');
        $this->assertStringContainsString(DocumentUpload::limitLabel(), $message);
        $this->assertStringNotContainsString('validation.', $message);

        Storage::disk('documents')->assertDirectoryEmpty('member-photos');
    }

    public function test_a_photo_under_the_limit_still_uploads_and_is_stored_encrypted(): void
    {
        Storage::fake('documents');
        $this->invite('t');

        $this->post(route('socio.application.store', ['token' => 't']), $this->formData([
            'photo' => UploadedFile::fake()->image('dni.jpg')->size(3_860),                  // the reported 3.86 MB
        ]))->assertSessionHasNoErrors();

        $application = MemberApplication::query()->withoutGlobalScopes()->sole();
        $path = data_get($application->payload, 'photo_path');

        $this->assertNotNull($path, 'An ordinary phone photo must still upload.');
        Storage::disk('documents')->assertExists($path);

        // Still encrypted at rest — this branch changed the ceiling, never the vault.
        $this->assertStringNotContainsString('JFIF', Storage::disk('documents')->get($path));
    }

    public function test_the_applicant_form_states_the_limit_before_a_file_is_chosen(): void
    {
        $this->invite('t');

        $this->get(route('socio.application', ['token' => 't']))
            ->assertOk()
            ->assertSee(DocumentUpload::limitLabel());
    }

    // --- Fixtures ---------------------------------------------------------------------------------

    private function invite(string $rawToken): MemberApplication
    {
        return MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'invite_token_hash' => hash('sha256', $rawToken),
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
        ]);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function formData(array $overrides = []): array
    {
        $this->travelTo(now()->subSeconds(ApplicationSpamGuard::MIN_SECONDS + 2));
        $token = ApplicationSpamGuard::issueToken();
        $this->travelBack();

        return array_merge([
            'first_name' => 'María', 'last_name' => 'García', 'email' => 'maria@example.es',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'document_type' => 'DNI', 'document_number' => '12345678Z',
            'declared_monthly_g' => '30', 'consent_data' => '1', 'consent_statutes' => '1',
            ApplicationSpamGuard::HONEYPOT => '',
            ApplicationSpamGuard::TIMESTAMP => $token,
        ], $overrides);
    }

    private function harness(): LivewireComponent&HasSchemas
    {
        return new class extends LivewireComponent implements HasSchemas
        {
            use InteractsWithSchemas;

            public function render(): string
            {
                return '<div></div>';
            }
        };
    }

    /** @return list<BaseFileUpload> */
    private function fileUploads(Schema $schema): array
    {
        $uploads = [];

        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component instanceof BaseFileUpload) {
                $uploads[] = $component;
            }
            if ($component instanceof SchemaComponent) {
                foreach ($component->getChildSchemas() as $child) {
                    $uploads = array_merge($uploads, $this->fileUploads($child));
                }
            }
        }

        return $uploads;
    }

    /** @return list<class-string<resource>> */
    private function resourceClasses(): array
    {
        $classes = [];

        foreach (glob(app_path('Filament/Resources/*/*Resource.php')) ?: [] as $file) {
            $class = 'App\\'.Str::of($file)->after(app_path().'/')->beforeLast('.php')->replace('/', '\\');
            if (class_exists($class) && is_subclass_of($class, Resource::class) && is_subclass_of($class::getModel(), Model::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** @return list<string> */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Every `FileUpload::make(...)` fluent chain in a source file, sliced from the `make(` to the
     * statement that closes it — enough to see whether that ONE field declares both a disk and a size.
     *
     * @return list<string>
     */
    private function fileUploadChains(string $source): array
    {
        $chains = [];
        $offset = 0;

        while (($start = strpos($source, 'FileUpload::make(', $offset)) !== false) {
            $next = strpos($source, 'FileUpload::make(', $start + 1);
            $chains[] = substr($source, $start, ($next === false ? strlen($source) : $next) - $start);
            $offset = $start + 1;
        }

        return $chains;
    }
}
