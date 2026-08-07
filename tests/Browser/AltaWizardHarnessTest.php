<?php

namespace Tests\Browser;

use App\Actions\Till\OpenTill;
use App\Enums\MemberStatus;
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
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 174 — writes the counter's alta states to storage/app/alta-*.html for the screenshot pass
 * (`node tests/Browser/shoot-alta-wizard.mjs`). Counter screen, so app-*.css only and the layout params
 * the real page passes (see the README).
 */
class AltaWizardHarnessTest extends TestCase
{
    use RefreshDatabase;

    private function write(string $name, string $componentHtml): void
    {
        $html = view('components.layouts.counter', [
            'slot' => new HtmlString($componentHtml),
            'title' => 'Socios',
        ])->render();

        $css = '';
        foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
            $css .= (string) file_get_contents($file);
        }

        if ($css !== '') {
            $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
            $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        }

        file_put_contents(storage_path('app/alta-'.$name.'.html'), $html);
    }

    public function test_it_writes_the_alta_states(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro']);

        $staff = User::factory()->create(['name' => 'Marta Ruiz']);
        $staff->assignRole(Role::STAFF->value);
        $staff->locations()->sync([$location->id]);
        $this->actingAs($staff);
        app(ActiveScope::class)->setLocation($location->id);
        CounterOperator::set($staff);
        (new OpenTill)->handle($location, 'POS-1', 10000);
        MembershipTier::factory()->create(['organisation_id' => $org->id, 'name' => 'General', 'default_fee_cents' => 2500]);

        // 1) the entry inside Socios
        $this->write('entry', Livewire::test(MembershipCounter::class)->call('toggleAlta')->html());

        // 2) an application come back, at the tier-and-approve step
        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');
        $application = MemberApplication::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->post(route('socio.application.store', ['token' => $application->invite_token]), [
            'first_name' => 'Lucía', 'last_name' => 'García', 'email' => 'lucia@example.es',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'document_type' => 'DNI', 'document_number' => '12345678Z',
            'consent_data' => '1', 'consent_statutes' => '1',
            ApplicationSpamGuard::HONEYPOT => '',
            ApplicationSpamGuard::TIMESTAMP => $this->agedToken(),
        ]);
        CounterOperator::set($staff);
        CounterHandover::end();

        $this->write('review', Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')->call('reviewAltaApplication', $application->id)->html());

        // 3) the duplicate decision
        Member::factory()->create([
            'organisation_id' => $org->id, 'first_name' => 'Lucía', 'last_name' => 'García',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'), 'status' => MemberStatus::ACTIVE,
        ]);
        $tier = MembershipTier::query()->withoutGlobalScopes()->firstOrFail();
        $this->write('duplicate', Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')->call('reviewAltaApplication', $application->id)
            ->set('altaTierId', $tier->id)->call('approveAlta')->html());

        foreach (['entry', 'review', 'duplicate'] as $state) {
            $this->assertStringContainsString('data-alta-panel', (string) file_get_contents(storage_path('app/alta-'.$state.'.html')));
        }
    }

    private function agedToken(): string
    {
        $this->travelTo(now()->subSeconds(ApplicationSpamGuard::MIN_SECONDS + 2));
        $token = ApplicationSpamGuard::issueToken();
        $this->travelBack();

        return $token;
    }
}
