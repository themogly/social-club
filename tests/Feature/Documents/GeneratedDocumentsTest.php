<?php

namespace Tests\Feature\Documents;

use App\Enums\MemberDocumentType;
use App\Enums\Role;
use App\Filament\Resources\MemberDocuments\MemberDocumentResource;
use App\Filament\Resources\MemberDocuments\Pages\ListMemberDocuments;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Models\ConsentRecord;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class GeneratedDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        Location::factory()->create(['organisation_id' => $this->org->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    public function test_the_generate_document_action_creates_a_versioned_member_document(): void
    {
        Storage::fake('documents');
        $this->actingAs($this->user(Role::OWNER));

        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'first_name' => 'Ana', 'last_name' => 'García']);
        ConsentRecord::factory()->create(['member_id' => $member->id, 'consent_text_version' => '1.0', 'granted_at' => now()]);

        Livewire::test(ViewMember::class, ['record' => $member->getKey()])
            ->callAction('generateDocument', ['type' => MemberDocumentType::REGISTRATION_FORM->value])
            ->assertHasNoActionErrors();

        $document = MemberDocument::query()->where('member_id', $member->id)->firstOrFail();
        $this->assertSame(MemberDocumentType::REGISTRATION_FORM, $document->type);
        $this->assertSame(1, $document->version);
        Storage::disk('documents')->assertExists($document->path);
        // Routed through the audited domain action.
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.generated']);
    }

    public function test_viewing_a_document_issues_a_signed_url_and_logs_the_access(): void
    {
        $owner = $this->user(Role::OWNER);
        $this->actingAs($owner);

        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $document = MemberDocument::factory()->create(['member_id' => $member->id]);

        Livewire::test(ListMemberDocuments::class)
            ->callTableAction('view', $document);

        $this->assertDatabaseHas('document_access_logs', [
            'member_document_id' => $document->id,
            'actor_id' => $owner->id,
        ]);
    }

    public function test_the_generated_documents_vault_is_forbidden_to_a_manager(): void
    {
        // documents.generate (manager holds) is distinct from member.documents.view
        // (owner-only) — a manager may generate but not open the sensitive artifacts.
        $manager = $this->user(Role::MANAGER);
        $this->assertTrue($manager->can('documents.generate'));
        $this->assertFalse($manager->can('member.documents.view'));

        $this->actingAs($manager)
            ->get(MemberDocumentResource::getUrl('index'))
            ->assertForbidden();
    }
}
