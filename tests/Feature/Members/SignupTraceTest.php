<?php

namespace Tests\Feature\Members;

use App\Actions\Members\IssueApplicationInvite;
use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 348 — "Aaron signed up a user and it didn't save." First, the read-only trace that answers what happened, with no
 * personal data; then, every sign-up path says what went wrong and keeps what was typed.
 */
class SignupTraceTest extends TestCase
{
    use RefreshDatabase;

    private string $logs;

    private Location $sede;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->owner = User::factory()->create(['pin' => '1234']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);

        // The trace reads the configured channels' files: point both at a scratch folder for this test.
        $this->logs = storage_path('framework/testing/signup-trace-'.getmypid());
        File::ensureDirectoryExists($this->logs);
        File::cleanDirectory($this->logs);
        config(['logging.channels.signup.path' => $this->logs.'/signup.log', 'logging.channels.single.path' => $this->logs.'/laravel.log']);
        app('log')->forgetChannel('signup');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logs);
        parent::tearDown();
    }

    private function invite(): MemberApplication
    {
        return (new IssueApplicationInvite)->handle($this->owner, $this->sede->id, null, 'TRAZA');
    }

    private function fields(array $overrides = []): array
    {
        $this->travelTo(now()->subSeconds(ApplicationSpamGuard::MIN_SECONDS + 2));
        $spam = ApplicationSpamGuard::issueToken();
        $this->travelBack();

        return array_merge([
            'first_name' => 'Rocío', 'last_name' => 'Vidal', 'email' => 'rocio@example.es',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'), 'document_type' => 'DNI', 'document_number' => '12345678Z',
            'consent_data' => '1', 'consent_statutes' => '1', 'signature' => 'data:image/png;base64,'.base64_encode('sig'),
            'photo' => UploadedFile::fake()->image('foto.jpg'),
            ApplicationSpamGuard::HONEYPOT => '', ApplicationSpamGuard::TIMESTAMP => $spam,
        ], $overrides);
    }

    // --- 8. The trace ----------------------------------------------------------------------------------------------------------

    public function test_the_trace_lists_a_refused_submission_and_an_exception_with_no_personal_data(): void
    {
        $application = $this->invite();
        $token = (string) $application->invite_token;

        // A forced validation failure: no photo.
        $this->post(route('socio.application.store', ['token' => $token]), $this->fields(['photo' => null]))->assertSessionHasErrors('photo');
        // A forced exception in a sign-up class, as the framework logs it.
        File::append($this->logs.'/laravel.log', '['.now()->format('Y-m-d H:i:s').'] testing.ERROR: SQLSTATE fallo con Rocío Vidal {"exception":"[object] (Illuminate\\\\Database\\\\QueryException(code: 23000): SQLSTATE fallo at /app/app/Actions/Members/SubmitApplication.php:120)"}'."\n#0 /app/app/Actions/Members/SubmitApplication.php(120): ...\n");
        // And a successful one, for contrast.
        $this->post(route('socio.application.store', ['token' => $token]), $this->fields())->assertRedirect();

        $this->assertSame(0, Artisan::call('csc:signup-trace', ['--since' => now()->subHour()->format('Y-m-d H:i')]));
        $out = Artisan::output();
        foreach (['application.invalid', 'keys=photo', 'application.submitted', 'QueryException', 'SubmitApplication.php:120'] as $expected) {
            $this->assertStringContainsString($expected, $out);
        }
        foreach (['Rocío', 'Vidal', '12345678Z', 'rocio@example.es'] as $personal) {
            $this->assertStringNotContainsString($personal, $out, 'the trace showed personal data');
        }
    }

    // --- 9. Every path says what went wrong, and keeps what was typed ---------------------------------------------------------------

    public function test_the_public_form_says_why_and_keeps_the_typed_values(): void
    {
        $token = (string) $this->invite()->invite_token;
        $form = route('socio.application', ['token' => $token]);

        $this->from($form)->post(route('socio.application.store', ['token' => $token]), $this->fields(['photo' => null]))->assertRedirect($form);
        $html = (string) $this->get($form)->getContent();

        $this->assertStringContainsString(e(__('Añade una foto tuya: el personal la comprobará en cada visita.')), $html);
        $this->assertMatchesRegularExpression('/id="first_name"[^>]*value="Rocío"/u', $html);
        $this->assertMatchesRegularExpression('/id="document_number"[^>]*value="12345678Z"/', $html);
    }

    public function test_the_staff_form_says_why_on_the_step_with_the_photo_and_on_a_refused_approval(): void
    {
        session(['counter.location_id' => $this->sede->id]);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);
        CounterOperator::set($this->owner);

        $wizard = Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('toggleStaffAltaForm')
            ->set('altaForm.first_name', 'Rocío')->set('altaForm.last_name', 'Vidal')
            ->set('altaForm.date_of_birth', now()->subYears(30)->format('Y-m-d'))
            ->set('altaForm.document_type', 'DNI')->set('altaForm.document_number', '12345678Z')
            ->call('altaNext');
        $wizard->assertHasErrors('altaPhoto')->assertSet('altaStep', 1)
            ->assertSet('altaForm.first_name', 'Rocío'); // what was typed is kept

        // An underage applicant gets through the form (the age is the WRITER's rule) and is refused at «Aprobar», on screen.
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->sede->organisation_id]);
        $wizard->set('altaPhoto', UploadedFile::fake()->image('foto.jpg'))->set('altaForm.date_of_birth', now()->subYears(15)->format('Y-m-d'))
            ->call('altaNext')->set('altaForm.email', 'rocio@example.es')->call('altaNext')
            ->set('altaTierId', $tier->id)->call('altaNext')
            ->set('altaSignaturePath', 'data:image/png;base64,'.base64_encode('sig'))
            ->call('submitStaffAlta');
        $this->assertSame('error', $wizard->get('flashType'), 'the refusal was not said');
        $this->assertNotEmpty($wizard->get('flashMessage'));
        $this->assertSame(0, Member::query()->where('first_name', 'Rocío')->count(), 'an underage member was created');
    }
}
