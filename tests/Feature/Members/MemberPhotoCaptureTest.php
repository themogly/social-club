<?php

namespace Tests\Feature\Members;

use App\Actions\Members\ApproveApplication;
use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\DocumentAccessLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use App\Support\DocumentVault;
use App\Support\VaultUrl;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Prompt 157 — the counter needs a member photo to compare against the person, but only the admin form ever
 * captured one, so invitation-flow members arrived with an empty square. These pin the capture path: encrypted
 * to the private disk, served through the existing signed + access-logged URL, authorised object-level, with
 * the upload fallback always present so a camera-less tablet still works. Storage on the documents disk is
 * faked so nothing touches a real disk.
 */
class MemberPhotoCaptureTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('documents');
        Storage::fake('public');
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function operator(Role $role = Role::STAFF): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value); // STAFF holds pos.use + checkin.manage
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    private function postPhoto(User $actor, Member $member, array $data)
    {
        return $this->actingAs($actor)
            ->withSession(['scope.organisation_id' => $this->org->id])
            ->post(route('counter.members.photo', ['member' => $member->id]), $data);
    }

    // --- Capture + storage ----------------------------------------------------------

    public function test_a_photo_captured_at_the_counter_is_encrypted_on_the_private_disk_and_not_public(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'photo_path' => null]);

        $this->postPhoto($this->operator(), $member, ['photo' => UploadedFile::fake()->image('face.jpg', 400, 500)])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $path = $member->fresh()->photo_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('member-photos/', $path);

        // On the private documents disk, as ciphertext — the raw bytes are not the image, but the vault
        // round-trips them. Never on the public disk.
        $this->assertTrue(Storage::disk('documents')->exists($path));
        $this->assertNotSame(DocumentVault::get($path), (string) Storage::disk('documents')->get($path));
        $this->assertFalse(Storage::disk('public')->exists($path));

        // Every capture is audited.
        $this->assertSame(1, AuditLog::query()->where('action', 'member.photo.captured')->count());
    }

    public function test_replacing_a_photo_deletes_the_previous_file(): void
    {
        DocumentVault::put('member-photos/old.jpg', 'OLDBYTES');
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'photo_path' => 'member-photos/old.jpg']);

        $this->postPhoto($this->operator(), $member, ['photo' => UploadedFile::fake()->image('new.jpg')])->assertOk();

        $this->assertFalse(Storage::disk('documents')->exists('member-photos/old.jpg'));
        $this->assertTrue(Storage::disk('documents')->exists($member->fresh()->photo_path));
    }

    // --- Signed, access-logged display (the existing prompt-113 path) ----------------

    public function test_the_captured_photo_serves_through_a_signed_access_logged_url(): void
    {
        $operator = $this->operator();
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'photo_path' => null]);

        $this->postPhoto($operator, $member, ['photo' => UploadedFile::fake()->image('face.jpg')])->assertOk();

        $viewer = $this->operator(); // holds members.view
        $this->actingAs($viewer)
            ->withSession(['scope.organisation_id' => $this->org->id])
            ->get(VaultUrl::photo($member->fresh(), $viewer))
            ->assertOk();

        $this->assertSame(1, DocumentAccessLog::query()
            ->where('subject_type', $member->getMorphClass())->where('subject_id', $member->id)->count());
    }

    // --- Authorisation (write the denial tests) -------------------------------------

    public function test_capture_is_denied_for_an_actor_without_a_counter_permission(): void
    {
        $noAccess = User::factory()->create();
        $noAccess->givePermissionTo('till.open'); // neither pos.use nor checkin.manage
        $noAccess->locations()->sync([$this->location->id]);
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'photo_path' => null]);

        $this->postPhoto($noAccess, $member, ['photo' => UploadedFile::fake()->image('face.jpg')])->assertForbidden();

        $this->assertNull($member->fresh()->photo_path);
    }

    public function test_capture_cannot_reach_a_member_of_another_organisation(): void
    {
        $otherOrg = Organisation::factory()->create();
        $foreign = Member::factory()->create(['organisation_id' => $otherOrg->id, 'photo_path' => null]);

        // Operator scoped to THIS org; the member belongs to another — the org global scope 404s the binding.
        $this->postPhoto($this->operator(), $foreign, ['photo' => UploadedFile::fake()->image('face.jpg')])->assertNotFound();

        $this->assertNull($foreign->fresh()->photo_path);
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'photo_path' => null]);

        $this->postPhoto($this->operator(), $member, ['photo' => UploadedFile::fake()->create('note.pdf', 20, 'application/pdf')])
            ->assertSessionHasErrors('photo');

        $this->assertNull($member->fresh()->photo_path);
    }

    // --- Progressive enhancement: the upload fallback is ALWAYS present --------------

    public function test_the_capture_component_always_offers_the_upload_fallback(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        $html = Blade::render('<x-counter.photo-capture :member="$member" source="door" />', ['member' => $member]);

        // A file input with no dependency on getUserMedia — so a camera-less tablet or an unsupported browser
        // still captures a photo. The live-camera trigger is gated behind Alpine `supported`.
        $this->assertStringContainsString('type="file"', $html);
        $this->assertStringContainsString('x-show="supported"', $html);
    }

    // --- Optional photo on the public application form -------------------------------

    public function test_the_application_form_still_submits_without_a_photo(): void
    {
        $application = $this->invite('t');

        $this->post(route('socio.application.store', ['token' => 't']), $this->applicationData())
            ->assertRedirect();

        $payload = $application->fresh()->payload;
        $this->assertNotNull($payload['first_name'] ?? null);
        $this->assertArrayNotHasKey('photo_path', $payload);
    }

    public function test_an_uploaded_application_photo_is_stored_encrypted_and_applied_on_approval(): void
    {
        $application = $this->invite('t');

        $this->post(
            route('socio.application.store', ['token' => 't']),
            $this->applicationData(['photo' => UploadedFile::fake()->image('me.jpg', 300, 400)]),
        )->assertRedirect();

        $path = $application->fresh()->payload['photo_path'] ?? null;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('member-photos/', $path);
        $this->assertTrue(Storage::disk('documents')->exists($path));
        $this->assertFalse(Storage::disk('public')->exists($path));

        $member = (new ApproveApplication)->handle($application->fresh());

        $this->assertSame($path, $member->photo_path);
    }

    // --- Application helpers (mirror PublicApplicationFormTest) ----------------------

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
    private function applicationData(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'María', 'last_name' => 'García', 'email' => 'maria@example.es',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'document_type' => 'DNI', 'document_number' => '12345678Z',
            'declared_monthly_g' => '30', 'consent_data' => '1', 'consent_statutes' => '1',
            'signature' => 'data:image/png;base64,'.base64_encode('sig'),
            ApplicationSpamGuard::HONEYPOT => '',
            ApplicationSpamGuard::TIMESTAMP => $this->agedToken(ApplicationSpamGuard::MIN_SECONDS + 2),
        ], $overrides);
    }

    private function agedToken(int $ageSeconds): string
    {
        $this->travelTo(now()->subSeconds($ageSeconds));
        $token = ApplicationSpamGuard::issueToken();
        $this->travelBack();

        return $token;
    }
}
