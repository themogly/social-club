<?php

namespace Tests\Feature\Members;

use App\Actions\Members\AnonymiseMember;
use App\Actions\Members\ApproveApplication;
use App\Actions\Members\ExportMemberData;
use App\Actions\Members\IssueApplicationInvite;
use App\Enums\ApplicationStatus;
use App\Enums\ConsentChannel;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\MembershipCounter;
use App\Models\ConsentRecord;
use App\Models\DocumentAccessLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use App\Support\CounterOperator;
use App\Support\DocumentVault;
use App\Support\Settings;
use App\Support\VaultUrl;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 220 — the sign-up is signed, by whoever is signing up, on all three routes.
 *
 * Prompt 210 gave staff a way to type the form in and — correctly — refused to let that produce the
 * applicant's own artefact: the consent row said `PAPER` and named the operator. Prompt 218 recorded the
 * owner's decision that paper was enough and put a standing instruction against tightening it.
 *
 * **The owner has now asked for the signature**, which lifts that instruction rather than contradicting it:
 * a signature drawn by the person signing up IS their own act, so the staff route stops being an attestation
 * the moment one exists. `signature_on_application` is on by default and enforced SERVER-SIDE on every route,
 * because a disabled button is not a rule — the club's evidence cannot depend on which screen was used.
 *
 * The three routes are the emailed link, the tablet handed over, and staff typing it in. Every one of them
 * ends in the same place: one PNG, encrypted, on the private disk, pointed at by the consent record that
 * makes it mean something.
 */
class SignedSignUpTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
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

        return $user;
    }

    /** A drawn signature, in the shape the pad produces. */
    private function drawnSignature(): string
    {
        return 'data:image/png;base64,'.base64_encode('drawn-by-a-finger');
    }

    private function latestApplication(): MemberApplication
    {
        return MemberApplication::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    // --- The three routes --------------------------------------------------------------------

    /** Route 1: an invitation emailed to the applicant, filled in on their own phone. */
    private function emailedLink(User $staff): MemberApplication
    {
        return (new IssueApplicationInvite)->handle($staff, $this->location->id, 'nueva@example.es', null);
    }

    /** Route 2: the tablet handed over at the counter — the same public form, a different way in. */
    private function handedOver(): MemberApplication
    {
        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');

        return $this->latestApplication();
    }

    /** @param array<string, mixed> $overrides */
    private function submitPublicForm(MemberApplication $application, array $overrides = []): TestResponse
    {
        $this->travelTo(now()->subSeconds(ApplicationSpamGuard::MIN_SECONDS + 2));
        $token = ApplicationSpamGuard::issueToken();
        $this->travelBack();

        return $this->post(route('socio.application.store', ['token' => $application->invite_token]), array_merge([
            'first_name' => 'Lucía',
            'last_name' => 'García',
            'email' => 'lucia@example.es',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'document_type' => 'DNI',
            'document_number' => '12345678Z',
            'consent_data' => '1',
            'consent_statutes' => '1',
            'signature' => $this->drawnSignature(),
            ApplicationSpamGuard::HONEYPOT => '',
            ApplicationSpamGuard::TIMESTAMP => $token,
        ], $overrides));
    }

    /** Route 3: staff typing it in with the person in front of them, who signs. */
    private function submitStaffForm(?string $signature = null): void
    {
        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaForm', [
                'first_name' => 'Lucía',
                'last_name' => 'García',
                'email' => 'lucia@example.es',
                'phone' => '600111222',
                'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
                'address' => 'Calle Mayor 1',
                'document_type' => 'DNI',
                'document_number' => '12345678Z',
                'is_therapeutic' => false,
                'avalador_ref' => '',
            ])
            ->set('altaConsentHeld', true)
            ->set('altaSignaturePath', $signature ?? $this->drawnSignature())
            ->call('submitStaffAlta');
    }

    /** All three land in the same place: a vault-stored PNG and the consent version the person saw. */
    public function test_every_route_stores_the_signature_in_the_vault_with_the_consent_version(): void
    {
        $staff = $this->staff();

        $applications = [];

        $this->submitPublicForm($this->emailedLink($staff));
        $applications['emailed link'] = $this->latestApplication();

        $this->submitPublicForm($this->handedOver());
        $applications['handover'] = $this->latestApplication();

        CounterOperator::set($staff);
        $this->submitStaffForm();
        $applications['staff typed'] = $this->latestApplication();

        foreach ($applications as $route => $application) {
            $payload = $application->payload;

            $this->assertArrayHasKey('signature_path', $payload, "the {$route} route stored no signature");
            $this->assertStringStartsWith('signatures/', $payload['signature_path']);
            $this->assertTrue(
                Storage::disk('documents')->exists($payload['signature_path']),
                "the {$route} route's signature is not on the private disk",
            );
            $this->assertSame('drawn-by-a-finger', DocumentVault::get($payload['signature_path']));
            $this->assertNotEmpty($payload['consent_version'], "the {$route} route stamped no consent version");
        }

        $this->assertCount(3, array_unique(array_map(fn ($a) => $a->payload['signature_path'], $applications)), 'two routes wrote the same file');
    }

    /** The bytes on the disk are ciphertext — the same rule as every other Article-9 artefact. */
    public function test_the_signature_is_encrypted_at_rest(): void
    {
        $this->submitPublicForm($this->emailedLink($this->staff()));

        $path = $this->latestApplication()->payload['signature_path'];
        $onDisk = (string) Storage::disk('documents')->get($path);

        $this->assertStringNotContainsString('drawn-by-a-finger', $onDisk, 'the signature is on the disk in plaintext');
        $this->assertSame('drawn-by-a-finger', DocumentVault::get($path));
    }

    // --- Required means required -------------------------------------------------------------

    /** With the setting on, a signature-less submit is refused on the public form — server-side. */
    public function test_the_public_form_refuses_a_signature_less_submit(): void
    {
        $application = $this->emailedLink($this->staff());

        $this->submitPublicForm($application, ['signature' => ''])
            ->assertSessionHasErrors('signature');

        $this->assertNull($application->fresh()->submitted_at, 'a signature-less application entered the queue');
    }

    /** …and on the handover, which is the same form reached from the counter. */
    public function test_the_handover_refuses_a_signature_less_submit(): void
    {
        $this->staff();
        $application = $this->handedOver();

        $this->submitPublicForm($application, ['signature' => ''])
            ->assertSessionHasErrors('signature');

        $this->assertNull($application->fresh()->submitted_at);
    }

    /** …and on the staff route, where the operator cannot sign on somebody's behalf. */
    public function test_the_staff_route_refuses_a_signature_less_submit(): void
    {
        $this->staff();

        $this->submitStaffForm(signature: '');

        $this->assertSame(0, MemberApplication::query()->withoutGlobalScopes()->whereNotNull('submitted_at')->count());
    }

    /** A drawn signature is the member's OWN act, so the staff route stops being an attestation. */
    public function test_a_signed_staff_application_is_not_a_paper_attestation(): void
    {
        $staff = $this->staff();

        $this->submitStaffForm();
        $payload = $this->latestApplication()->payload;

        $this->assertSame(ConsentChannel::SIGNED->value, $payload['consent_channel'], 'a signed alta was still recorded as paper');
        $this->assertNull($payload['consent_attested_by'], 'nobody attests a consent the member signed themselves');
        $this->assertTrue(ConsentChannel::SIGNED->isApplicantsOwnAct());
        $this->assertNotSame($staff->id, $payload['consent_attested_by']);
    }

    /** Switched off, prompt 210's paper attestation is exactly what a club gets back. */
    public function test_with_the_setting_off_the_paper_route_is_unchanged(): void
    {
        Settings::set('signature_on_application', false, SettingType::BOOL);
        $staff = $this->staff();

        $this->submitStaffForm(signature: '');
        $payload = $this->latestApplication()->payload;

        $this->assertArrayNotHasKey('signature_path', $payload);
        $this->assertSame(ConsentChannel::PAPER->value, $payload['consent_channel']);
        $this->assertSame($staff->id, $payload['consent_attested_by']);
    }

    /** Switched off, the public form submits without one too — no half-enforced rule. */
    public function test_with_the_setting_off_the_public_form_submits_without_a_signature(): void
    {
        Settings::set('signature_on_application', false, SettingType::BOOL);
        $application = $this->emailedLink($this->staff());

        $this->submitPublicForm($application, ['signature' => ''])->assertSessionHasNoErrors();

        $this->assertNotNull($application->fresh()->submitted_at);
    }

    /** An invitation that is never filled in leaves nothing behind — no orphan file, no half-signature. */
    public function test_an_abandoned_handover_leaves_no_orphan_signature(): void
    {
        $this->staff();
        $this->handedOver();

        $this->assertSame([], Storage::disk('documents')->allFiles(), 'a handover that was never submitted wrote a file');
        $this->assertNull($this->latestApplication()->submitted_at);
    }

    // --- What it is worth once they are a member ---------------------------------------------

    private function approveTheApplication(User $staff): Member
    {
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => 2500]);

        return (new ApproveApplication)->handle($this->latestApplication(), $staff->id, $tier->id);
    }

    /** The consent record points at the signature — the evidence sits with the act it evidences. */
    public function test_approval_carries_the_signature_onto_every_consent_record(): void
    {
        $staff = $this->staff();
        $this->submitPublicForm($this->emailedLink($staff));
        $path = $this->latestApplication()->payload['signature_path'];

        $member = $this->approveTheApplication($staff);
        $consents = $member->consents()->get();

        $this->assertGreaterThan(0, $consents->count());
        foreach ($consents as $consent) {
            $this->assertSame($path, $consent->signature_path, 'a consent record cannot produce the signature it claims');
            $this->assertTrue($consent->isSigned());
        }
    }

    /** …and it is REACHABLE: a signed, user-bound URL streams the decrypted image. */
    public function test_the_signature_can_be_produced_through_a_signed_url(): void
    {
        $staff = $this->staff(Role::MANAGER);
        $this->submitPublicForm($this->emailedLink($staff));
        $member = $this->approveTheApplication($staff);

        $consent = $member->consents()->firstOrFail();
        $url = VaultUrl::consentSignature($consent, $staff);

        $this->assertIsString($url);
        $response = $this->actingAs($staff)->get($url);
        $response->assertOk();
        $this->assertSame('drawn-by-a-finger', $response->getContent());
    }

    /**
     * The denial test: an authenticated user without the right to read the member cannot fetch what they
     * signed — and the refusal is not recorded as a view.
     *
     * Minted FOR the outsider, deliberately. A URL signed for somebody else is refused by the `u` binding
     * before authorisation is consulted at all, so testing that would prove the binding and say nothing about
     * the policy. This one gets past the binding and has to be stopped by the gate.
     *
     * The cross-ORGANISATION half of `viewConsentSignature` is not asserted here and could not honestly be:
     * `ActiveScope::organisationId()` resolves to the only organisation in a single-org install, so a second
     * org's manager reads as in-scope in a test the same way they would in production — there is one club.
     * The org clause is there for the multi-org keying the schema already carries; it is exercised the day a
     * second organisation is real, not faked into passing today.
     */
    public function test_an_actor_without_members_view_cannot_fetch_the_signature(): void
    {
        $staff = $this->staff(Role::MANAGER);
        $this->submitPublicForm($this->emailedLink($staff));
        $member = $this->approveTheApplication($staff);
        $consent = $member->consents()->firstOrFail();

        // Authenticated, at this club, and holding no right to read a member.
        $outsider = User::factory()->create();
        $outsider->locations()->sync([$this->location->id]);

        $url = VaultUrl::consentSignature($consent, $outsider);

        $this->actingAs($outsider)->get((string) $url)->assertForbidden();
        $this->assertSame(0, DocumentAccessLog::query()->where('actor_id', $outsider->id)->count(), 'a refused view was logged as a view');
    }

    /** …and a URL issued to somebody else cannot be replayed, even by a manager who could mint their own. */
    public function test_a_signature_url_cannot_be_replayed_by_another_session(): void
    {
        $staff = $this->staff(Role::MANAGER);
        $this->submitPublicForm($this->emailedLink($staff));
        $member = $this->approveTheApplication($staff);
        $consent = $member->consents()->firstOrFail();

        $url = (string) VaultUrl::consentSignature($consent, $staff);

        $other = User::factory()->create();
        $other->assignRole(Role::MANAGER->value);
        $other->locations()->sync([$this->location->id]);

        $this->actingAs($other)->get($url)->assertForbidden();
    }

    // --- Retention, erasure, portability -----------------------------------------------------

    /** Erasure destroys the signature and leaves nothing pointing at it — from either direction. */
    public function test_erasure_deletes_the_signature_and_both_pointers(): void
    {
        $staff = $this->staff();
        $this->submitPublicForm($this->emailedLink($staff));
        $path = $this->latestApplication()->payload['signature_path'];
        $member = $this->approveTheApplication($staff);

        (new AnonymiseMember)->handle($member);

        $this->assertFalse(Storage::disk('documents')->exists($path), 'the signature survived erasure');
        $this->assertNull(ConsentRecord::query()->whereNotNull('signature_path')->first(), 'a consent still points at a deleted signature');
        $this->assertArrayNotHasKey('signature_path', $this->latestApplication()->fresh()->payload ?? [], 'the source application still points at a deleted signature');
    }

    /** A rejected application past retention takes its signature with it. */
    public function test_the_retention_sweep_deletes_a_rejected_signature(): void
    {
        $staff = $this->staff();
        $this->submitPublicForm($this->emailedLink($staff));
        $application = $this->latestApplication();
        $path = $application->payload['signature_path'];

        $application->forceFill(['status' => ApplicationStatus::REJECTED->value])->save();
        $application->forceFill(['updated_at' => now()->subDays((int) Settings::get('application_retention_days', 180) + 1)])->saveQuietly();

        $this->artisan('applications:prune-retention')->assertExitCode(0);

        $this->assertFalse(Storage::disk('documents')->exists($path), 'a rejected application kept its signature');
        $this->assertNull($application->fresh()->payload);
    }

    /** The portability pack says they signed, without handing out an internal vault path. */
    public function test_the_export_reports_the_signature_without_leaking_its_path(): void
    {
        $staff = $this->staff();
        $this->submitPublicForm($this->emailedLink($staff));
        $path = $this->latestApplication()->payload['signature_path'];
        $member = $this->approveTheApplication($staff);

        $export = (new ExportMemberData)->handle($member);

        $this->assertNotEmpty($export['consents']);
        foreach ($export['consents'] as $consent) {
            $this->assertTrue($consent['signed'], 'the pack does not say the member signed');
            $this->assertArrayNotHasKey('signature_path', $consent);
        }
        $this->assertStringNotContainsString($path, json_encode($export) ?: '');
    }
}
