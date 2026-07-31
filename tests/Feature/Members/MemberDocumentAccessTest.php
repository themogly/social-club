<?php

namespace Tests\Feature\Members;

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

class MemberDocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private MemberDocument $document;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        DocumentVault::put('members/'.$member->id.'/dni.pdf', 'PDFDATA');   // encrypted at rest
        $this->document = MemberDocument::factory()->create([
            'member_id' => $member->id, 'path' => 'members/'.$member->id.'/dni.pdf',
        ]);
    }

    private function userWithRole(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function stream(User $user, string $url)
    {
        return $this->actingAs($user)
            ->withSession(['scope.organisation_id' => $this->org->id])
            ->get($url);
    }

    public function test_staff_is_denied_a_url_and_nothing_is_logged(): void
    {
        $staff = $this->userWithRole(Role::STAFF);

        try {
            (new IssueDocumentUrl)->handle($this->document, $staff);
            $this->fail('Staff should not obtain a document URL.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Access is logged on the VIEW (audit S2), not issuance — a denied issuance never views.
        $this->assertSame(0, DocumentAccessLog::query()->count());
    }

    public function test_owner_gets_a_signed_url_that_streams_is_logged_and_then_expires(): void
    {
        $owner = $this->userWithRole(Role::OWNER);

        $url = (new IssueDocumentUrl)->handle($this->document, $owner);

        $this->stream($owner, $url)->assertOk();

        // The VIEW itself is logged (prompt 32 / audit S2).
        $this->assertDatabaseHas('document_access_logs', [
            'actor_id' => $owner->id, 'member_document_id' => $this->document->id,
        ]);

        // Past the TTL the signed URL no longer validates.
        $this->travel(3600)->seconds();
        $this->stream($owner, $url)->assertForbidden();
    }
}
