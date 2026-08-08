<?php

namespace Tests\Feature\Counter;

use App\Actions\Members\IssueApplicationInvite;
use App\Actions\Till\OpenTill;
use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ApplicationShape;
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 221 — the sign-up becomes a modal wizard, and fee collection gets the screen back.
 *
 * The owner designed this in Claude Design and asked for it built. His brief to the design tool: the sign-up
 * panel *"is too small and still has the collect on the side… make it more user friendly."* Both halves are
 * one move — the sign-up leaves the page for a modal, and fee collection stops sharing a screen with it.
 *
 * **This branch is presentation and flow.** Nothing below asserts a new behaviour, because there is not
 * supposed to be one: the three routes reach the same three mechanisms, `SubmitApplication` is still the one
 * writer, 215's field parity and 220's signature rules are untouched and tested where they live. What IS
 * asserted here is the flow: one entrance, three ways out of the chooser, a stepper that keeps what was
 * typed, and a close guard that fires only when something would be lost.
 */
class SignupWizardTest extends TestCase
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

    private function staff(Role $role = Role::STAFF): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        if (! TillSession::query()->withoutGlobalScopes()->exists()) {
            (new OpenTill)->handle($this->location, 'POS-1', 10000);
        }

        return $user;
    }

    // --- The closed state ------------------------------------------------------------------------

    /**
     * **Fee collection owns the screen, and there is exactly ONE way into a sign-up.**
     *
     * Fails against `main`, where the alta panel and its three options render inline beside the fee card —
     * `data-alta-staff-form` is in the closed page's HTML there and is not here.
     */
    public function test_the_closed_screen_is_fee_collection_with_one_entrance(): void
    {
        $this->staff();

        $html = Livewire::test(MembershipCounter::class)->html();

        $this->assertStringContainsString('data-alta-toggle', $html, 'the sign-up has no entrance');
        // Count the CONTROLS, not the string: an attribute bag renders a bare `x` as `x="x"`, so a naive
        // substring count reads one button as two.
        $this->assertSame(1, preg_match_all('/data-alta-toggle=/', $html), 'more than one way into the sign-up');

        // Nothing of the sign-up itself is on the page until it is opened.
        foreach (['data-alta-modal', 'data-alta-panel', 'data-alta-staff-form', 'data-alta-handover', 'data-alta-invite'] as $hook) {
            $this->assertStringNotContainsString($hook, $html, "{$hook} still renders inline on the closed screen");
        }

        // …and the job the screen is now for is the thing on it.
        $this->assertStringContainsString('data-member-lookup', $html);
        $this->assertStringContainsString(e(__('Cobro de cuota')), $html);
    }

    /** 194's rule survives the rearrangement: the fee search is the screen's ONE member lookup. */
    public function test_the_wizard_adds_no_second_lookup(): void
    {
        $this->staff();

        $html = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->html();

        $this->assertSame(1, preg_match_all('/data-member-lookup(?![-\w])/', $html), 'the wizard added a second lookup');
    }

    // --- The chooser's three routes --------------------------------------------------------------

    /** Opening the sign-up lands on the chooser, with all three ways on it and no wizard yet. */
    public function test_the_entrance_opens_the_chooser(): void
    {
        $this->staff();

        $html = Livewire::test(MembershipCounter::class)->call('toggleAlta')->html();

        $this->assertStringContainsString('data-alta-modal', $html);
        $this->assertStringContainsString('data-alta-staff-form', $html);
        $this->assertStringContainsString('data-alta-handover', $html);
        $this->assertStringContainsString('data-alta-invite', $html);
        $this->assertStringNotContainsString('data-alta-stepper', $html, 'the chooser opened straight into the wizard');
    }

    /**
     * Card 2 is the REAL handover, not a mode inside this wizard.
     *
     * The design invented a handover banner and a wizard-in-handover-mode because it could not see the real
     * one; the owner's instruction was not to build it. So this asserts the actual mechanism: 173's session
     * handover begins and the tablet is redirected to the applicant's own tokenised form.
     */
    public function test_the_handover_card_starts_the_real_handover(): void
    {
        $this->staff();

        $before = MemberApplication::query()->withoutGlobalScopes()->count();

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('handOverForAlta')
            ->assertRedirect();

        $application = MemberApplication::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame($before + 1, MemberApplication::query()->withoutGlobalScopes()->count());
        $this->assertTrue(CounterHandover::active(), '173\'s handover did not begin');
        $this->assertSame($application->inviteUrl(), CounterHandover::returnUrl(), 'the tablet was not sent to the real form');
    }

    /** …and no lookalike was built beside it: nothing in the wizard pretends to be the applicant's form. */
    public function test_no_second_handover_was_built(): void
    {
        $modal = (string) file_get_contents(resource_path('views/livewire/counter/partials/alta-modal.blade.php'));

        $this->assertStringContainsString('wire:click="handOverForAlta"', $modal, 'the handover card no longer calls the real handover');
        $this->assertStringNotContainsString('handoverMode', $modal, 'a handover mode was rebuilt inside the wizard');
    }

    /** Card 3 sends the real invitation and the chooser says so where the operator is looking. */
    public function test_the_invite_card_sends_the_real_invitation_and_confirms_it(): void
    {
        $this->staff();

        $component = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->set('altaInviteEmail', 'nueva@example.es')
            ->call('sendAltaInvitation');

        $application = MemberApplication::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame('nueva@example.es', $application->applicant_email);
        $this->assertNotNull($application->invite_token);

        $component->assertSet('altaInviteSent', true)
            ->assertSee('data-alta-invite-sent', false);
    }

    /** A bad email is refused without pretending an invitation went out. */
    public function test_an_invalid_email_does_not_claim_an_invitation_was_sent(): void
    {
        $this->staff();

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->set('altaInviteEmail', 'no-es-un-email')
            ->call('sendAltaInvitation')
            ->assertSet('altaInviteSent', false);

        $this->assertSame(0, MemberApplication::query()->withoutGlobalScopes()->count());
    }

    // --- The stepper -----------------------------------------------------------------------------

    /**
     * **Every field of the shared application shape is asked on exactly one step.**
     *
     * This is 215's defect in a new costume: a field added to `ApplicationShape` reaches the public form for
     * free and would silently miss a four-step wizard that lists its fields by hand. The map is the one
     * declaration, `altaNext()` validates from it, and this compares it against the shape.
     */
    public function test_the_step_map_covers_the_shared_shape_exactly(): void
    {
        $declared = array_merge(array_keys(ApplicationShape::facts()), array_keys(ApplicationShape::files()));
        sort($declared);

        $mapped = array_merge(...array_values(MembershipCounter::WIZARD_STEPS));
        sort($mapped);

        $this->assertSame($declared, $mapped, 'the wizard asks a different field set from the one the application declares');
        $this->assertSame(count($mapped), count(array_unique($mapped)), 'a field is asked on two steps');
        $this->assertSame(array_keys(MembershipCounter::WIZARD_STEPS), [1, 2, 3, 4]);
    }

    /** What was typed on step 1 is still there after going forward and back. */
    public function test_partial_data_survives_back_and_next(): void
    {
        $this->staff();

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->assertSet('altaStep', 1)
            ->set('altaForm.first_name', 'Lucía')
            ->set('altaForm.last_name', 'García')
            ->set('altaForm.date_of_birth', now()->subYears(30)->format('Y-m-d'))
            ->set('altaForm.document_type', 'DNI')
            ->set('altaForm.document_number', '12345678Z')
            ->call('altaNext')
            ->assertSet('altaStep', 2)
            ->set('altaForm.email', 'lucia@example.es')
            ->call('altaBack')
            ->assertSet('altaStep', 1)
            ->assertSet('altaForm.first_name', 'Lucía')
            ->assertSet('altaForm.document_number', '12345678Z')
            ->call('altaNext')
            ->assertSet('altaForm.email', 'lucia@example.es');
    }

    /** A step validates with the route's OWN rules — no second validator, no way past a required fact. */
    public function test_a_step_refuses_to_advance_on_its_own_invalid_fields(): void
    {
        $this->staff();

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->call('altaNext')
            ->assertHasErrors(['altaForm.first_name', 'altaForm.last_name'])
            ->assertSet('altaStep', 1, 'the wizard advanced past an empty identity step');
    }

    /** The stepper walks BACK to a step already filled in, and never forward past its rules. */
    public function test_the_stepper_jumps_back_but_never_forward(): void
    {
        $this->staff();

        $component = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaForm.first_name', 'Lucía')
            ->set('altaForm.last_name', 'García')
            ->set('altaForm.date_of_birth', now()->subYears(30)->format('Y-m-d'))
            ->set('altaForm.document_type', 'DNI')
            ->set('altaForm.document_number', '12345678Z')
            ->call('altaNext')
            // Email is a REQUIRED fact of the shared shape, so the contact step has a rule of its own.
            ->set('altaForm.email', 'lucia@example.es')
            ->call('altaNext')
            ->assertSet('altaStep', 3);

        $component->call('goToAltaStep', 4)->assertSet('altaStep', 3, 'the stepper jumped forward past a step\'s rules');
        $component->call('goToAltaStep', 1)->assertSet('altaStep', 1);
    }

    /** Back from the first step is the way out of the wizard, not out of the sign-up. */
    public function test_back_from_the_first_step_returns_to_the_chooser(): void
    {
        $this->staff();

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->call('altaBack')
            ->assertSet('altaStaffFormOpen', false)
            ->assertSet('altaOpen', true, 'going back to the methods closed the whole sign-up');
    }

    // --- The close guard -------------------------------------------------------------------------

    /**
     * The confirm is rendered only when something would actually be lost (206's lesson: a guard that fires
     * over nothing teaches the operator to dismiss guards).
     */
    public function test_the_close_confirm_appears_only_once_data_was_entered(): void
    {
        $this->staff();

        $component = Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('toggleStaffAltaForm');

        $this->assertFalse($component->instance()->altaHasEnteredData());
        $this->assertStringNotContainsString('wire:confirm', $component->html(), 'an empty form asked to confirm');

        $component->set('altaForm.first_name', 'Lucía');

        $this->assertTrue($component->instance()->altaHasEnteredData());
        $this->assertStringContainsString('wire:confirm', $component->html(), 'a half-typed alta closed without a guard');
    }

    /** Closing forgets everything, so reopening is a clean chooser rather than somebody else's half-form. */
    public function test_closing_and_reopening_lands_on_a_clean_chooser(): void
    {
        $this->staff();

        $component = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaForm.first_name', 'Lucía')
            ->set('altaSignaturePath', 'data:image/png;base64,'.base64_encode('sig'))
            ->call('altaNext')
            ->call('closeAlta')
            ->assertSet('altaOpen', false)
            ->call('toggleAlta');

        $component->assertSet('altaStaffFormOpen', false)
            ->assertSet('altaStep', 1)
            ->assertSet('altaSignaturePath', null)
            ->assertSet('altaForm.first_name', '');

        $this->assertFalse($component->instance()->altaHasEnteredData());
    }

    // --- What did not change ---------------------------------------------------------------------

    /** 220's rule, from inside the wizard: with the setting on there is no saving without a signature. */
    public function test_the_wizard_still_cannot_submit_without_a_signature(): void
    {
        $this->staff();

        $this->wizardThrough()
            ->call('submitStaffAlta')
            ->assertHasErrors('altaSignaturePath');

        $this->assertSame(0, MemberApplication::query()->withoutGlobalScopes()->whereNotNull('submitted_at')->count());
    }

    /** …and with a signature it produces the ordinary application, through the ordinary writer. */
    public function test_the_wizard_produces_the_same_application_the_route_always_did(): void
    {
        $this->staff();

        $this->wizardThrough()
            ->set('altaSignaturePath', 'data:image/png;base64,'.base64_encode('sig'))
            ->call('submitStaffAlta')
            ->assertHasNoErrors()
            ->assertSet('altaStaffFormOpen', false);

        $application = MemberApplication::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertNotNull($application->submitted_at);
        $this->assertSame(ApplicationStatus::PENDING, $application->status);
        $this->assertSame('Lucía', $application->payload['first_name']);
        $this->assertSame($this->location->id, $application->location_id);
    }

    /** Prompt 210's route is unchanged with signatures off: the paper attestation is still the way through. */
    public function test_with_signatures_off_the_paper_attestation_still_carries_the_wizard(): void
    {
        Settings::set('signature_on_application', false, SettingType::BOOL);
        $staff = $this->staff();

        $this->wizardThrough()
            ->set('altaConsentHeld', true)
            ->call('submitStaffAlta')
            ->assertHasNoErrors();

        $payload = MemberApplication::query()->withoutGlobalScopes()->latest('id')->firstOrFail()->payload;

        $this->assertSame('PAPER', $payload['consent_channel']);
        $this->assertSame($staff->id, $payload['consent_attested_by']);
    }

    /** An application that came back is reviewed on the same surface, and approving closes it. */
    public function test_a_returned_application_is_reviewed_in_the_modal(): void
    {
        $staff = $this->staff();
        $application = (new IssueApplicationInvite)->handle($staff, $this->location->id, 'vuelta@example.es', null);

        $html = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('reviewAltaApplication', $application->id)
            ->html();

        $this->assertStringContainsString('data-alta-review', $html);
        $this->assertStringContainsString('data-alta-approve', $html);
        $this->assertStringNotContainsString('data-alta-stepper', $html, 'the review opened the wizard');
    }

    /** 207's alert still opens the sign-up on the applications waiting to be reviewed. */
    public function test_the_pending_applications_alert_opens_the_signup(): void
    {
        $staff = $this->staff();
        $application = (new IssueApplicationInvite)->handle($staff, $this->location->id, 'pendiente@example.es', null);
        $application->forceFill(['submitted_at' => now(), 'payload' => ['first_name' => 'Pendiente', 'last_name' => 'Persona']])->save();

        $html = Livewire::test(MembershipCounter::class, ['alert' => 'pending_applications'])->html();

        $this->assertStringContainsString('data-alta-modal', $html, 'the alert did not open the sign-up');
        $this->assertStringContainsString('data-alta-pending', $html, 'the applications waiting to be reviewed are not on it');
    }

    /** Type a valid identity + contact and stop on the signature step. */
    private function wizardThrough(): Testable
    {
        return Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaForm.first_name', 'Lucía')
            ->set('altaForm.last_name', 'García')
            ->set('altaForm.email', 'lucia@example.es')
            ->set('altaForm.date_of_birth', now()->subYears(30)->format('Y-m-d'))
            ->set('altaForm.document_type', 'DNI')
            ->set('altaForm.document_number', '12345678Z')
            ->call('altaNext')
            ->call('altaNext')
            ->call('altaNext');
    }
}
