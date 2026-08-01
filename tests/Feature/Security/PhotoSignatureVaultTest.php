<?php

namespace Tests\Feature\Security;

use App\Actions\Members\AnonymiseMember;
use App\Enums\Role;
use App\Models\Dispensation;
use App\Models\DocumentAccessLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\DocumentVault;
use App\Support\VaultUrl;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Prompt 113 — the member photo and the POS signature are the two Article-9 files prompt 32 left plaintext
 * and served by a bare temporaryUrl. They now go through DocumentVault (encrypted) and the authorised,
 * access-logged streaming endpoint, exactly like a document.
 */
class PhotoSignatureVaultTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('documents');
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    private function memberWithPhoto(string $bytes = 'the-raw-photo-bytes'): Member
    {
        $path = 'member-photos/'.Str::ulid().'.png';
        DocumentVault::put($path, $bytes);

        return Member::factory()->create(['organisation_id' => $this->org->id, 'photo_path' => $path]);
    }

    private function stream(User $user, string $url)
    {
        return $this->actingAs($user)->withSession(['scope.organisation_id' => $this->org->id])->get($url);
    }

    // --- Encryption at rest ---------------------------------------------------------

    public function test_a_stored_photo_and_signature_are_ciphertext_on_disk(): void
    {
        DocumentVault::put('member-photos/p.png', 'PLAINPHOTOBYTES');
        DocumentVault::put('signatures/s.png', 'PLAINSIGNATUREBYTES');

        foreach (['member-photos/p.png' => 'PLAINPHOTOBYTES', 'signatures/s.png' => 'PLAINSIGNATUREBYTES'] as $path => $original) {
            $raw = (string) Storage::disk('documents')->get($path);
            $this->assertStringNotContainsString($original, $raw);       // plaintext not recoverable from disk
            $this->assertSame($original, DocumentVault::get($path));     // but round-trips through the vault
        }
    }

    // --- Authorised, logged streaming -----------------------------------------------

    public function test_a_member_photo_streams_and_logs_the_view_with_the_permission(): void
    {
        $staff = $this->user(Role::STAFF); // holds members.view
        $member = $this->memberWithPhoto('IMGBYTES');

        $this->stream($staff, VaultUrl::photo($member, $staff))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->assertSame(1, DocumentAccessLog::query()
            ->where('subject_type', $member->getMorphClass())->where('subject_id', $member->id)->count());
    }

    public function test_a_member_photo_is_refused_without_the_permission(): void
    {
        $noAccess = User::factory()->create();
        $noAccess->givePermissionTo('till.open'); // some permission, but NOT members.view
        $noAccess->locations()->sync([$this->location->id]);
        $member = $this->memberWithPhoto();

        // Mint as a staffer who may, then fetch as the user who may not — the Gate refuses.
        $staff = $this->user(Role::STAFF);
        $url = VaultUrl::photo($member, $noAccess);
        $this->stream($noAccess, $url)->assertForbidden();
        $this->assertSame(0, DocumentAccessLog::query()->count());
    }

    public function test_a_photo_url_bound_to_one_user_is_refused_for_another(): void
    {
        $a = $this->user(Role::STAFF);
        $b = $this->user(Role::STAFF);
        $member = $this->memberWithPhoto();

        $url = VaultUrl::photo($member, $a); // bound to a (u=…)
        $this->stream($b, $url)->assertForbidden();
        $this->assertSame(0, DocumentAccessLog::query()->count());
    }

    public function test_a_pos_signature_streams_and_logs_for_an_authorised_actor(): void
    {
        $manager = $this->user(Role::MANAGER); // reports.view + assigned to the location
        $path = 'signatures/'.Str::ulid().'.png';
        DocumentVault::put($path, 'SIGBYTES');
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $dispensation = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id,
            'location_id' => $this->location->id, 'signature_path' => $path,
        ]);

        $this->stream($manager, VaultUrl::signature($dispensation, $manager))->assertOk();
        $this->assertSame(1, DocumentAccessLog::query()
            ->where('subject_type', $dispensation->getMorphClass())->where('subject_id', $dispensation->id)->count());
    }

    // --- The one-off encrypt command ------------------------------------------------

    public function test_the_encrypt_command_encrypts_plaintext_and_is_safe_to_run_twice(): void
    {
        // A plaintext file that predates the change, written straight to the disk (not through the vault).
        Storage::disk('documents')->put('member-photos/legacy.png', 'LEGACYPLAINTEXT');

        $this->artisan('csc:encrypt-vault-media')->assertSuccessful();
        $raw = (string) Storage::disk('documents')->get('member-photos/legacy.png');
        $this->assertStringNotContainsString('LEGACYPLAINTEXT', $raw);
        $this->assertSame('LEGACYPLAINTEXT', Crypt::decryptString($raw));

        // Running again must NOT double-encrypt.
        $this->artisan('csc:encrypt-vault-media')->assertSuccessful();
        $this->assertSame('LEGACYPLAINTEXT', DocumentVault::get('member-photos/legacy.png'));
    }

    // --- Erasure --------------------------------------------------------------------

    public function test_anonymise_removes_the_photo_and_the_signature_files(): void
    {
        $member = $this->memberWithPhoto('IMG');
        $photoPath = $member->photo_path;
        $sigPath = 'signatures/'.Str::ulid().'.png';
        DocumentVault::put($sigPath, 'SIG');
        Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id,
            'location_id' => $this->location->id, 'signature_path' => $sigPath,
        ]);

        (new AnonymiseMember)->handle($member);

        $this->assertFalse(Storage::disk('documents')->exists($photoPath));
        $this->assertFalse(Storage::disk('documents')->exists($sigPath));
        $this->assertNull($member->fresh()->photo_path);
    }
}
