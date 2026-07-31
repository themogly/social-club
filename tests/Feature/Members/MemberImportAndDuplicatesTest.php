<?php

namespace Tests\Feature\Members;

use App\Actions\Members\ApproveApplication;
use App\Actions\Members\ImportMembers;
use App\Enums\Role;
use App\Exceptions\DuplicateMemberException;
use App\Filament\Resources\Members\Pages\CreateMember;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 50 — ImportMembers had zero callers and FindDuplicateMembers ran only inside it, so neither
 * real creation path checked for duplicates. This proves: the CSV import runs and skips duplicates; the
 * direct-create form blocks a duplicate (overridable); application approval blocks a duplicate
 * (overridable); and the import action is gated on members.import.
 */
class MemberImportAndDuplicatesTest extends TestCase
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

    private function actor(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    public function test_csv_import_creates_valid_rows_skips_duplicates_and_reports_errors(): void
    {
        Member::factory()->create([
            'organisation_id' => $this->org->id, 'first_name' => 'Ana', 'last_name' => 'Existing',
            'email' => 'ana@test.es', 'date_of_birth' => '1990-01-01',
        ]);

        $csv = "first_name,last_name,email,phone,date_of_birth,document_type,document_number,declared_monthly_g\n"
            ."Bruno,Nuevo,bruno@test.es,600111222,1985-05-05,DNI,12345678A,30\n"
            ."Ana,Existing,ana@test.es,,1990-01-01,,,\n"   // duplicate (email) → skipped
            ."Nino,Menor,nino@test.es,,2015-01-01,,,\n";   // underage → error

        $path = (string) tempnam(sys_get_temp_dir(), 'members_csv_');
        file_put_contents($path, $csv);
        $result = (new ImportMembers)->import($path);
        @unlink($path);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertCount(1, $result['errors']);
        $this->assertDatabaseHas('members', ['email' => 'bruno@test.es']);
        $this->assertDatabaseMissing('members', ['email' => 'nino@test.es']); // underage never created
    }

    public function test_create_member_blocks_a_duplicate_then_creates_with_acknowledgement(): void
    {
        Member::factory()->create([
            'organisation_id' => $this->org->id, 'email' => 'carlos@dup.test',
            'first_name' => 'Carlos', 'last_name' => 'Duplicado', 'date_of_birth' => '1990-01-01',
        ]);
        $avalador = Member::factory()->create(['organisation_id' => $this->org->id]);
        $this->actingAs($this->actor(Role::OWNER));

        $form = [
            'first_name' => 'Otro', 'last_name' => 'Solicitante',
            'email' => 'carlos@dup.test', // same email → duplicate
            'phone' => '600999888',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'avalador_member_id' => $avalador->id,
            'document_scan_path' => UploadedFile::fake()->create('dni.pdf', 40, 'application/pdf'),
            'consent_given' => true,
        ];

        // Blocked by beforeCreate (a halt, not a validation error) — the new member is not created.
        Livewire::test(CreateMember::class)->fillForm($form)->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseMissing('members', ['first_name' => 'Otro']);

        // Acknowledged — proceeds.
        Livewire::test(CreateMember::class)
            ->fillForm($form + ['acknowledge_duplicate' => true])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertDatabaseHas('members', ['first_name' => 'Otro']);
    }

    public function test_approving_a_duplicate_application_is_blocked_then_allowed_with_override(): void
    {
        Member::factory()->create([
            'organisation_id' => $this->org->id, 'email' => 'dup@test.es',
            'first_name' => 'Dup', 'last_name' => 'Licado', 'date_of_birth' => '1990-01-01',
        ]);
        $application = MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'payload' => [
                'first_name' => 'Otro', 'last_name' => 'Nombre',
                'email' => 'dup@test.es', // same email → duplicate
                'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
                'consents' => ['membership'],
            ],
        ]);

        try {
            (new ApproveApplication)->handle($application);
            $this->fail('Expected DuplicateMemberException');
        } catch (DuplicateMemberException) {
            // expected
        }

        $member = (new ApproveApplication)->handle($application, allowDuplicate: true);
        $this->assertNotNull($member->id);
        $this->assertSame('Otro', $member->first_name);
    }

    public function test_the_csv_import_action_is_gated_on_members_import(): void
    {
        $this->actingAs($this->actor(Role::MANAGER));
        Livewire::test(ListMembers::class)->assertActionVisible('import');

        $this->actingAs($this->actor(Role::STAFF));
        Livewire::test(ListMembers::class)->assertActionHidden('import');
    }
}
