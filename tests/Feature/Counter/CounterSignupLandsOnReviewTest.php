<?php

namespace Tests\Feature\Counter;

use App\Actions\Members\IssueApplicationInvite;
use App\Actions\Till\OpenTill;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 243 — the counter sign-up refuses at the door, lands on the review, and carries the tier.
 *
 * The tester filled four steps, watched the modal reset to the method chooser, and concluded it had failed —
 * the flow was complete and its outcome invisible (234's rule, unheeded). And the authorisation he asked for
 * ("a way to authorise a new member immediately") existed a screen away, behind a pending list he never
 * opened, demanding the cuota he thought the Membresía step had already taken.
 *
 * This branch: the refusal (no till) is shown at *Nuevo socio*, not after the form; a successful staff submit
 * lands straight on THAT application's review with *Aprobar* there and the tier from step 3 carried; the
 * pending list stays the second path.
 */
class CounterSignupLandsOnReviewTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function operator(Role $role = Role::STAFF, bool $withTill = true): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        if ($withTill && ! TillSession::query()->withoutGlobalScopes()->exists()) {
            (new OpenTill)->handle($this->location, 'POS-1', 10000);
        }

        return $user;
    }

    private function tier(): MembershipTier
    {
        return MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Estándar']);
    }

    /** Fill the four steps, choosing $tierId in the Membresía step, and sign — leaving it ready to submit. */
    private function wizardTo(MembershipCounter|Testable $component, ?string $tierId): Testable
    {
        return $component
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaForm.first_name', 'Lucía')
            ->set('altaForm.last_name', 'García')
            ->set('altaForm.date_of_birth', now()->subYears(30)->format('Y-m-d'))
            ->set('altaForm.document_type', 'DNI')
            ->set('altaForm.document_number', '12345678Z')
            ->set('altaForm.email', 'lucia@example.es')
            ->call('altaNext')   // → step 2
            ->call('altaNext')   // → step 3 (Membresía)
            ->set('altaTierId', $tierId) // the tier, chosen in the wizard
            ->call('altaNext')   // → step 4 (Firma)
            ->set('altaSignaturePath', 'data:image/png;base64,'.base64_encode('a-drawn-signature'));
    }

    // --- Refuse at the door ------------------------------------------------------

    public function test_new_socio_is_refused_at_the_door_without_a_till(): void
    {
        $this->operator(withTill: false); // sede + PIN, but NO till open

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->assertSet('flashType', 'error')
            ->assertSet('altaOpen', false)              // the wizard never opens…
            ->assertDontSee('data-alta-staff-form', false); // …so its four steps are never rendered
    }

    // --- Land on the review, tier carried ---------------------------------------

    /**
     * The landing assertion, written to FAIL against `main`: there, a successful submit resets to the chooser
     * (`altaApplicationId` null, the wizard/chooser shown), so `data-alta-review` is absent and the operator is
     * dropped back to a menu. Here a submit with NO tier lands on the review of the created application.
     *
     * (Prompt 246: a staff submit WITH a tier chosen in step 3 auto-approves — because starting a counter
     * sign-up already requires `applications.review`, so the tier + that permission always approve on the spot.
     * The review landing is therefore the no-tier case, where the operator confirms the cuota on the review.)
     */
    public function test_a_staff_submit_without_a_tier_lands_on_the_review(): void
    {
        $this->operator();

        $component = $this->wizardTo(Livewire::test(MembershipCounter::class), null) // no tier → no auto-approve
            ->call('submitStaffAlta')
            ->assertHasNoErrors();

        $application = MemberApplication::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

        // Landed on THAT application's review, not the chooser.
        $component->assertSet('altaApplicationId', $application->id)
            ->assertSet('altaStaffFormOpen', false);

        $html = $component->html();
        $this->assertStringContainsString('data-alta-review', $html);
        $this->assertStringContainsString('data-alta-approve', $html);
        $this->assertStringNotContainsString('data-alta-stepper', $html, 'the submit reopened the wizard');
        $this->assertSame(0, Member::query()->withoutGlobalScopes()->count(), 'no member without a chosen tier');
    }

    public function test_aprobar_from_the_landing_creates_the_active_member(): void
    {
        $this->operator();
        $tier = $this->tier();

        // No tier in the wizard → lands on the review; pick the cuota there and approve.
        $this->wizardTo(Livewire::test(MembershipCounter::class), null)
            ->call('submitStaffAlta')
            ->assertHasNoErrors()
            ->set('altaTierId', $tier->id)
            ->call('approveAlta')
            ->assertSet('flashType', 'success');

        $member = Member::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(MemberStatus::ACTIVE, $member->status);

        $membership = Membership::query()->withoutGlobalScopes()->where('member_id', $member->id)->firstOrFail();
        $this->assertSame($tier->id, $membership->tier_id);
        $this->assertSame($this->location->id, $membership->location_id);

        $application = MemberApplication::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertArrayHasKey('signature_path', $application->payload);
    }

    /** With no tier chosen in the wizard, the review is where it is asked — the block is honest, not doubled. */
    public function test_without_a_tier_the_review_asks_once_at_approve(): void
    {
        $this->operator();

        $this->wizardTo(Livewire::test(MembershipCounter::class), null) // skipped the tier in step 3
            ->call('submitStaffAlta')
            ->assertHasNoErrors()
            ->assertSet('altaTierId', null)
            ->call('approveAlta')
            ->assertSet('flashType', 'error')
            ->assertSee(__('Elige una cuota antes de aprobar.'));

        $this->assertSame(0, Member::query()->withoutGlobalScopes()->count());
    }

    // --- The second path is unchanged -------------------------------------------

    public function test_the_pending_list_still_opens_an_application_to_the_review(): void
    {
        $staff = $this->operator();
        $application = (new IssueApplicationInvite)->handle($staff, $this->location->id, 'vuelta@example.es', null);

        $html = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('reviewAltaApplication', $application->id)
            ->assertSet('altaApplicationId', $application->id)
            ->html();

        $this->assertStringContainsString('data-alta-review', $html);
        $this->assertStringContainsString('data-alta-approve', $html);
    }
}
