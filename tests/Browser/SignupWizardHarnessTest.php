<?php

namespace Tests\Browser;

use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Browser\Concerns\InlinesBuiltCss;
use Tests\TestCase;

/**
 * Prompt 221 — every state of the sign-up, plus the screen it left behind.
 *
 *   npm run build
 *   php artisan test tests/Browser/SignupWizardHarnessTest.php   # → storage/app/signup-221-*.html
 *   node tests/Browser/measure-signup-wizard.mjs
 *
 * Both sizes matter and both are shot: `1180×820` is the counter tablet in landscape, and `820×1180` is the
 * same device stood up — which is where `max-h-[min(780px,92vh)]` bites and where a footer that is not sticky
 * walks off the bottom of the screen.
 */
class SignupWizardHarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    private Location $location;

    private User $user;

    private string $page = '';

    public function test_it_writes_every_signup_state(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create(['name' => 'Club Verde']);
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->location = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->user = User::factory()->create(['name' => 'Lucía Márquez']);
        $this->user->assignRole(Role::OWNER->value);
        $this->user->locations()->sync([$this->location->id]);
        $this->actingAs($this->user);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($this->user);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $this->page = (string) $this->get(route('counter.members'))->assertOk()->getContent();

        // A socio who OWES, so prompt 219's collect + waive controls are on the closed screen — the half of
        // this branch that is "fee collection gets the screen back" is only shown by the state that fills it.
        $member = Member::factory()->create([
            'organisation_id' => $org->id, 'first_name' => 'Ana', 'last_name' => 'Ruiz',
            'date_of_birth' => now()->subYears(34), 'joined_at' => now()->subYear(),
        ]);
        Membership::factory()->create([
            'organisation_id' => $org->id, 'location_id' => $this->location->id, 'member_id' => $member->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $org->id])->id,
            // Owed is DERIVED (fee minus payments), never a flag — so a fee with no payment against it is
            // what puts the collect + waive controls on screen.
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 2500,
            'starts_at' => now()->subMonths(6), 'expires_at' => now()->addMonths(6),
        ]);

        // 1. Closed — the empty state the design specifies, and the same screen with a socio held.
        $this->write('closed', Livewire::test(MembershipCounter::class));
        $this->write('fee', Livewire::test(MembershipCounter::class)->call('selectMember', $member->id));

        // 2. The chooser.
        $this->write('chooser', Livewire::test(MembershipCounter::class)->call('toggleAlta'));

        // 3. Every step of the wizard.
        foreach ([1, 2, 3, 4] as $step) {
            $this->write('step'.$step, $this->wizardAt($step));
        }

        // 4. The invitation-sent confirmation row.
        $this->write('invite-sent', Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->set('altaInviteEmail', 'nueva@example.es')
            ->call('sendAltaInvitation'));

        $this->assertStringNotContainsString('data-alta-modal', (string) file_get_contents(storage_path('app/signup-221-closed.html')));
        $this->assertStringContainsString('data-alta-stepper', (string) file_get_contents(storage_path('app/signup-221-step4.html')));
        $this->assertStringContainsString('data-signature-pad', (string) file_get_contents(storage_path('app/signup-221-step4.html')));
        $this->assertStringContainsString('data-alta-invite-sent', (string) file_get_contents(storage_path('app/signup-221-invite-sent.html')));
    }

    /** The wizard, typed far enough to be standing on $step. */
    private function wizardAt(int $step): Testable
    {
        $component = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaForm.first_name', 'Ana')
            ->set('altaForm.last_name', 'Ruiz Delgado')
            ->set('altaForm.email', 'ana@example.es')
            ->set('altaForm.date_of_birth', '1991-04-12')
            ->set('altaForm.document_type', 'DNI')
            ->set('altaForm.document_number', '12345678Z');

        for ($i = 1; $i < $step; $i++) {
            $component->call('altaNext');
        }

        return $component;
    }

    /** The real page with the component's real output spliced into its `<main>` (prompt 209's idiom). */
    private function write(string $state, Testable $component): void
    {
        $open = (int) strpos($this->page, '<main');
        $close = (int) strrpos($this->page, '</main>');
        $html = substr($this->page, 0, (int) strpos($this->page, '>', $open) + 1).$component->html().substr($this->page, $close);

        file_put_contents(storage_path('app/signup-221-'.$state.'.html'), $this->inlineBuiltCss($html));
    }
}
