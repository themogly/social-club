<?php

namespace Tests\Feature\Members;

use App\Actions\Documents\GenerateMemberDocument;
use App\Actions\Members\UpdateDeclaredForecast;
use App\Enums\MemberDocumentType;
use App\Enums\Role;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 72 — the declared forecast is a signed legal figure. It changes ONLY through
 * UpdateDeclaredForecast (audited under its own vocabulary), never inline on the member form; and when the
 * change post-dates a generated declaration, the drift is surfaced (derived, never a stored flag) so a
 * human regenerates and re-signs. Generated documents stay immutable. Weight is asserted in centigrams.
 */
class DeclaredForecastDriftTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value); // members.edit + documents.generate

        return $user;
    }

    private function member(int $declaredCg = 4000): Member
    {
        return Member::factory()->create(['organisation_id' => $this->org->id, 'declared_monthly_cg' => $declaredCg]);
    }

    private function generate(Member $member, MemberDocumentType $type): MemberDocument
    {
        Storage::fake('documents');

        return (new GenerateMemberDocument)->handle($member, $type, $this->owner());
    }

    public function test_updating_the_forecast_routes_through_the_action_and_audits_its_own_vocabulary(): void
    {
        $member = $this->member(4000);

        (new UpdateDeclaredForecast)->handle($member, 10000); // 40 g → 100 g

        $this->assertSame(10000, $member->fresh()->declared_monthly_cg); // real stored centigrams
        $audit = AuditLog::query()->where('action', 'member.forecast.updated')->latest()->firstOrFail();
        $this->assertSame(4000, $audit->before['declared_monthly_cg']);
        $this->assertSame(10000, $audit->after['declared_monthly_cg']);
    }

    public function test_the_member_form_no_longer_writes_the_declared_forecast_directly(): void
    {
        $member = $this->member();
        $this->actingAs($this->owner());

        // The regression guard: the drift vector (an inline, direct column write) is gone from the form.
        // Its only writer is now the record action.
        Livewire::test(EditMember::class, ['record' => $member->getKey()])
            ->assertFormFieldDoesNotExist('declared_monthly_cg');
    }

    public function test_drift_is_reported_when_a_generated_declaration_diverges(): void
    {
        $member = $this->member(4000);
        $this->generate($member, MemberDocumentType::DECLARATION); // snapshot freezes 4000

        (new UpdateDeclaredForecast)->handle($member, 10000);

        $member->refresh()->load('documents');
        $this->assertTrue($member->hasStaleDeclaration());
        $this->assertCount(1, $member->driftedDocuments());
    }

    public function test_drift_is_not_reported_without_a_declaration_or_when_it_still_matches(): void
    {
        // No document at all.
        $bare = $this->member(4000);
        $this->assertFalse($bare->load('documents')->hasStaleDeclaration());

        // A declaration that still matches the live figure (no edit).
        $matching = $this->member(4000);
        $this->generate($matching, MemberDocumentType::DECLARATION);
        $this->assertFalse($matching->refresh()->load('documents')->hasStaleDeclaration());
    }

    public function test_the_generated_declaration_is_unchanged_by_the_edit(): void
    {
        $member = $this->member(4000);
        $document = $this->generate($member, MemberDocumentType::DECLARATION);

        (new UpdateDeclaredForecast)->handle($member, 10000);

        // The frozen evidence is immutable — the edit flags drift, it never rewrites the snapshot.
        $this->assertSame(4000, $document->fresh()->snapshot['declared_monthly_cg']);
    }

    public function test_a_registration_form_also_flags_drift_when_identity_changes(): void
    {
        // Sibling-document answer: the SAME snapshot freezes name + document number, so a registration form
        // drifts too — the mechanism is general, not declaration-specific.
        $member = $this->member(4000);
        $member->update(['first_name' => 'Ana', 'last_name' => 'Ruiz']);
        $registration = $this->generate($member, MemberDocumentType::REGISTRATION_FORM);
        $declaration = $this->generate($member, MemberDocumentType::DECLARATION);

        $member->update(['last_name' => 'Ruiz Gómez']); // a name correction after generation

        $member->refresh()->load('documents');
        $drifted = $member->driftedDocuments()->pluck('id');
        $this->assertTrue($drifted->contains($registration->id), 'the registration form drifts on a name change');
        // A DECLARATION only tracks the forecast figure — a name change does NOT make it stale.
        $this->assertFalse($drifted->contains($declaration->id));
    }
}
