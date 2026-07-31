<?php

namespace Tests\Feature\Cleanup;

use App\Actions\Members\ApproveApplication;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Filament\Resources\Batches\Tables\BatchesTable;
use App\Filament\Resources\Members\Pages\CreateMember;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\RelationManagers\WalletTransactionsRelationManager;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\ActiveScope;
use App\Support\MemberEnrolment;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Prompt 37 — structural cleanup. Proves the refactors are behaviour-preserving and the
 * removed inert controls were genuinely inert:
 *  - both member-enrolment paths now fill ONE shared set of lifecycle defaults (no drift);
 *  - the money/stock-mutating actions carry a confirmation and still commit through it;
 *  - the deleted bulk-delete / force-delete actions were never authorised anyway.
 */
class StructuralCleanupTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);

        return $user;
    }

    public function test_both_enrolment_paths_apply_the_same_lifecycle_defaults(): void
    {
        // One shared source (MemberEnrolment::defaults) means the same carencia rule, active
        // status and generated member_no on BOTH the direct-create form and application approval.
        $this->travelTo(now()->startOfSecond()); // whole-second freeze — datetime columns drop µs
        Settings::set('carencia_days', 20, SettingType::INT); // non-default → proves it's read live

        // Path A — the approval action.
        $application = MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'payload' => [
                'first_name' => 'Ana', 'last_name' => 'García',
                'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
                'consents' => ['membership'],
            ],
        ]);
        $approved = (new ApproveApplication)->handle($application, $this->owner()->id);

        // Path B — the direct-create form.
        $avalador = Member::factory()->create(['organisation_id' => $this->org->id]);
        $this->actingAs($this->owner());
        Livewire::test(CreateMember::class)
            ->fillForm([
                'first_name' => 'Bruno', 'last_name' => 'López',
                'email' => 'bruno@example.test', 'phone' => '600111222',
                'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
                'avalador_member_id' => $avalador->id,   // policy default is "sponsor required"
                'document_scan_path' => UploadedFile::fake()->create('dni.pdf', 40, 'application/pdf'),
                'consent_given' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $created = Member::query()->withoutGlobalScopes()->where('first_name', 'Bruno')->firstOrFail();

        // Identical lifecycle defaults from the same fixture (carencia_days = 20).
        foreach ([$approved, $created] as $member) {
            $this->assertSame(MemberStatus::ACTIVE, $member->status);
            $this->assertEquals(now(), $member->joined_at);
            $this->assertEquals(now()->addDays(20), $member->carencia_ends_at);
            $this->assertNotNull($member->member_no);
        }
        // Sequential member numbers — the same generator, one after the other.
        $this->assertNotSame($approved->member_no, $created->member_no);

        // And the helper itself resolves the SAME configured window (the single source of truth).
        $this->assertEquals(now()->addDays(20), MemberEnrolment::defaults($this->org->id)['carencia_ends_at']);
    }

    public function test_batch_merma_requires_a_confirmation_step(): void
    {
        // A loss mutates compliance-relevant stock with no undo prompt before prompt 37.
        $merma = (new ReflectionMethod(BatchesTable::class, 'mermaAction'))->invoke(null);
        $this->assertTrue($merma->isConfirmationRequired());
    }

    public function test_wallet_adjust_requires_confirmation_and_still_commits_through_it(): void
    {
        // Proves the confirmation and the form schema coexist: the action still writes the
        // ledger row (callAction auto-confirms, exercising the confirmed path).
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $this->actingAs($this->owner());

        Livewire::test(WalletTransactionsRelationManager::class, [
            'ownerRecord' => $member,
            'pageClass' => EditMember::class,
        ])->callAction('adjust', [
            'location_id' => $this->location->id,
            'amount_eur' => '10.00',
            'reason' => 'Corrección de prueba',
        ]);

        $txn = WalletTransaction::query()->withoutGlobalScopes()
            ->where('member_id', $member->id)
            ->where('type', WalletTransactionType::ADJUSTMENT)
            ->firstOrFail();
        $this->assertSame(1000, $txn->amount_cents->cents); // €10.00 → 1000 cents, integer edge
    }

    public function test_member_applications_grant_no_delete_so_the_removed_bulk_action_was_inert(): void
    {
        $application = MemberApplication::factory()->create(['organisation_id' => $this->org->id]);

        foreach ([Role::OWNER, Role::MANAGER, Role::STAFF] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role->value);
            $this->assertFalse(
                Gate::forUser($user)->allows('delete', $application),
                "{$role->value} must not be able to delete an application — the bulk action was inert.",
            );
        }
    }

    public function test_no_role_can_force_delete_a_member_so_the_removed_action_was_inert(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        // MemberPolicy defines no forceDelete → the Gate denies it for every role, including OWNER.
        $this->assertFalse(Gate::forUser($this->owner())->allows('forceDelete', $member));
    }
}
