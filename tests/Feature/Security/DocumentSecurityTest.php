<?php

namespace Tests\Feature\Security;

use App\Actions\Members\IssueDocumentUrl;
use App\Enums\Role;
use App\Models\DocumentAccessLog;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\DocumentVault;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Prompt 32 — audit S1 (encrypt Article-9 documents at rest) + S2 (authorise, own,
 * bind and per-view-log the streaming endpoint).
 */
class DocumentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('documents');
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function document(?Organisation $org = null): MemberDocument
    {
        $member = Member::factory()->create(['organisation_id' => ($org ?? $this->org)->id]);
        $document = MemberDocument::factory()->create(['member_id' => $member->id]);
        DocumentVault::put($document->path, '%PDF-1.4 secret contents');

        return $document;
    }

    /** GET a signed URL with the actor's active-org session scope set (so the ownership policy can read it). */
    private function stream(User $user, string $url)
    {
        return $this->actingAs($user)
            ->withSession(['scope.organisation_id' => $this->org->id])
            ->get($url);
    }

    // --- S1: encryption at rest -----------------------------------------------------

    public function test_a_written_document_is_ciphertext_on_disk_not_the_original(): void
    {
        $document = $this->document();

        $raw = (string) Storage::disk('documents')->get($document->path);
        $this->assertStringNotContainsString('%PDF', $raw);              // not identifiable as a PDF
        $this->assertStringNotContainsString('secret contents', $raw);   // plaintext not recoverable from disk
        $this->assertSame('%PDF-1.4 secret contents', DocumentVault::get($document->path)); // decrypts back
    }

    public function test_the_encrypt_decrypt_round_trip_is_byte_identical(): void
    {
        $bytes = random_bytes(4096);
        DocumentVault::put('generated/roundtrip.bin', $bytes);

        $this->assertSame($bytes, DocumentVault::get('generated/roundtrip.bin'));
    }

    // --- S2: authorisation, logging, binding ----------------------------------------

    public function test_a_stream_writes_exactly_one_access_log_per_view(): void
    {
        $owner = $this->user(Role::OWNER);
        $document = $this->document();

        $this->stream($owner, (new IssueDocumentUrl)->handle($document, $owner))->assertOk();
        $this->assertSame(1, DocumentAccessLog::query()->where('member_document_id', $document->id)->count());

        // A second view writes a second row — every view, not just the first / issuance.
        $this->stream($owner, (new IssueDocumentUrl)->handle($document, $owner))->assertOk();
        $this->assertSame(2, DocumentAccessLog::query()->where('member_document_id', $document->id)->count());
    }

    public function test_a_url_bound_to_one_user_is_refused_for_another_session(): void
    {
        $owner = $this->user(Role::OWNER);
        $other = $this->user(Role::OWNER);
        $document = $this->document();

        $url = (new IssueDocumentUrl)->handle($document, $owner);   // bound to $owner (u=…)

        $this->stream($other, $url)->assertForbidden();             // leaked/replayed by a different session
        $this->assertSame(0, DocumentAccessLog::query()->count());  // and not logged as a view
    }

    public function test_a_document_from_another_org_403s_even_with_a_valid_signed_url(): void
    {
        $owner = $this->user(Role::OWNER);
        $document = $this->document(Organisation::factory()->create());   // member in a DIFFERENT org

        $url = (new IssueDocumentUrl)->handle($document, $owner);
        $this->stream($owner, $url)->assertForbidden();              // object-ownership gate
    }

    public function test_a_tampered_signed_url_is_refused(): void
    {
        $owner = $this->user(Role::OWNER);
        $document = $this->document();

        $url = (new IssueDocumentUrl)->handle($document, $owner);
        $this->stream($owner, $url.'&x=1')->assertForbidden();       // broken signature
    }

    public function test_issuance_refuses_a_user_without_the_permission(): void
    {
        $staff = $this->user(Role::STAFF);   // holds no member.documents.view
        $document = $this->document();

        $this->expectException(HttpException::class);
        (new IssueDocumentUrl)->handle($document, $staff);
    }
}
