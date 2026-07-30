<?php

namespace Tests\Feature\Members;

use App\Actions\Members\IssueDocumentUrl;
use App\Enums\Role;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MemberDocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    private MemberDocument $document;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed(RolePermissionSeeder::class);

        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $member = Member::factory()->create(['organisation_id' => $org->id]);

        Storage::disk('documents')->put('members/'.$member->id.'/dni.pdf', 'PDFDATA');
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

    public function test_staff_is_denied_and_the_attempt_is_logged(): void
    {
        $staff = $this->userWithRole(Role::STAFF);

        try {
            (new IssueDocumentUrl)->handle($this->document, $staff);
            $this->fail('Staff should not obtain a document URL.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertDatabaseHas('document_access_logs', [
            'actor_id' => $staff->id, 'member_document_id' => $this->document->id,
        ]);
    }

    public function test_owner_gets_a_signed_url_that_streams_and_then_expires(): void
    {
        $owner = $this->userWithRole(Role::OWNER);
        $this->actingAs($owner);

        $url = (new IssueDocumentUrl)->handle($this->document, $owner);

        $this->assertDatabaseHas('document_access_logs', [
            'actor_id' => $owner->id, 'member_document_id' => $this->document->id,
        ]);

        $this->get($url)->assertOk();

        // Past the TTL the signed URL no longer validates.
        $this->travel(3600)->seconds();
        $this->get($url)->assertForbidden();
    }
}
