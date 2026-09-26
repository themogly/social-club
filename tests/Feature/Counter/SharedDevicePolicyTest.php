<?php

namespace Tests\Feature\Counter;

use App\Actions\Members\IssueApplicationInvite;
use App\Actions\Till\OpenTill;
use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
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
 * Prompt 246 — the counter is a shared device with a fixed home: the device is the club's, the sede is the
 * manager's, the PIN is the person's.
 */
class SharedDevicePolicyTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private User $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);

        // The DEVICE is signed in as the owner (both sedes), once — the runbook's "log the tablet in once".
        $this->device = User::factory()->create();
        $this->device->assignRole(Role::OWNER->value);
        $this->device->locations()->sync([$this->centro->id, $this->norte->id]);
        $this->actingAs($this->device);
        app(ActiveScope::class)->setLocation($this->centro->id);
        session(['counter.location_id' => $this->centro->id]);
        (new OpenTill)->handle($this->centro, 'POS-1', 10000);
    }

    private function operatorPin(Role $role): User
    {
        $operator = User::factory()->create();
        $operator->assignRole($role->value);
        $operator->locations()->sync([$this->centro->id, $this->norte->id]);
        CounterOperator::set($operator); // the PIN-identified person, distinct from the device account

        return $operator;
    }

    private function sessionKeys(): array
    {
        return ['counter.location_id' => $this->centro->id, 'counter.operator_id' => CounterOperator::id()];
    }

    // --- 1. Switching sede is a manager's act -----------------------------------

    /** A STAFF operator on the owner-logged tablet cannot switch the terminal's sede — the POST is refused. */
    public function test_a_staff_operator_cannot_switch_sede(): void
    {
        $this->operatorPin(Role::STAFF);

        $this->withSession($this->sessionKeys())
            ->post(route('counter.location'), ['location_id' => $this->norte->id])
            ->assertSessionHas('counterLocationError');

        // The terminal did NOT move (fails against main, which validated against the device account's sedes).
        $this->assertSame($this->centro->id, session('counter.location_id'));
    }

    public function test_a_manager_operator_can_switch_sede(): void
    {
        $this->operatorPin(Role::MANAGER);
        // 89's separate rule blocks a switch while THIS sede's till is open; close it so the permission gate is
        // what the test measures.
        TillSession::query()->withoutGlobalScopes()->update(['status' => 'CLOSED', 'closed_at' => now()]);

        $this->withSession($this->sessionKeys())
            ->post(route('counter.location'), ['location_id' => $this->norte->id])
            ->assertSessionMissing('counterLocationError');

        $this->assertSame($this->norte->id, session('counter.location_id'));
    }

    public function test_the_switcher_is_a_static_badge_for_a_staff_operator(): void
    {
        $this->operatorPin(Role::STAFF);

        $html = (string) $this->withSession($this->sessionKeys())->get(route('counter.checkin'))->getContent();

        // The sede is still ON SCREEN (89) — as a badge, not a dropdown a non-manager could open.
        $this->assertStringContainsString('data-counter-sede-locked', $html);
        $this->assertStringContainsString('Sede Centro', $html);
        $this->assertStringNotContainsString('data-counter-sede-menu', $html, 'staff saw a way to change the sede');
    }

    public function test_the_switcher_is_a_dropdown_for_a_manager_operator(): void
    {
        $this->operatorPin(Role::MANAGER);

        $html = (string) $this->withSession($this->sessionKeys())->get(route('counter.checkin'))->getContent();

        $this->assertStringContainsString('data-counter-sede-menu', $html, 'a manager must be able to switch');
        $this->assertStringNotContainsString('data-counter-sede-locked', $html);
    }

    // --- 2. The admin's way back is a labelled word -----------------------------

    public function test_the_panel_control_is_labelled_at_every_width(): void
    {
        $this->operatorPin(Role::OWNER);

        $html = (string) $this->withSession($this->sessionKeys())->get(route('counter.checkin'))->getContent();

        // The label is not hidden below xl any more — an unlabelled briefcase was what the tester could not find.
        $at = strpos($html, 'data-counter-admin-link');
        $this->assertNotFalse($at);
        $window = substr($html, $at, 700);
        $this->assertStringContainsString('Administración', $window);
        $this->assertStringNotContainsString('hidden xl:inline', $window, 'the panel label is still hidden in portrait');
    }

    // --- 3. Staff-made sign-ups are approved on submit --------------------------

    private function wizardTo(Testable $component, ?string $tierId): Testable
    {
        return $component
            ->call('toggleAlta')->call('toggleStaffAltaForm')
            ->set('altaForm.first_name', 'Nuevo')->set('altaForm.last_name', 'Socio')
            ->set('altaForm.date_of_birth', now()->subYears(30)->format('Y-m-d'))
            ->set('altaForm.document_type', 'DNI')->set('altaForm.document_number', '12345678Z')
            ->set('altaForm.email', 'nuevo@example.es')
            ->call('altaNext')->call('altaNext')->set('altaTierId', $tierId)->call('altaNext')
            ->set('altaSignaturePath', 'data:image/png;base64,'.base64_encode('sig'));
    }

    public function test_a_wizard_submit_with_a_tier_approves_on_submit(): void
    {
        $this->operatorPin(Role::STAFF); // STAFF holds applications.review, so the sign-up is theirs to approve
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);

        $this->wizardTo(Livewire::test(MembershipCounter::class), $tier->id)
            ->call('submitStaffAlta')
            ->assertHasNoErrors()
            ->assertSet('flashType', 'success'); // "Socio dado de alta" — lands on the member's card

        $member = Member::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(MemberStatus::ACTIVE, $member->status);

        // The application is APPROVED, with the audit row naming the operator (ApproveApplication).
        $application = MemberApplication::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(ApplicationStatus::APPROVED, $application->status);
    }

    public function test_an_emailed_invitation_stays_pending(): void
    {
        $operator = $this->operatorPin(Role::STAFF);

        // The applicant's own emailed route — the person who fills it is not staff, so it stays PENDING.
        $application = (new IssueApplicationInvite)->handle($operator, $this->centro->id, 'invitado@example.es', null);

        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->status);
    }
}
