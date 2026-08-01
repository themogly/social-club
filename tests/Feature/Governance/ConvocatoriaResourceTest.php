<?php

namespace Tests\Feature\Governance;

use App\Enums\ConvocatoriaType;
use App\Enums\Role;
use App\Filament\Resources\Convocatorias\ConvocatoriaResource;
use App\Filament\Resources\Convocatorias\Pages\ListConvocatorias;
use App\Mail\ConvocatoriaMail;
use App\Models\Convocatoria;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 88 — the Filament surface that finally makes convening an assembly possible. Gated by
 * ConvocatoriaPolicy on `minutes.manage`; issuing runs through IssueConvocatoria from the table action.
 */
class ConvocatoriaResourceTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    public function test_the_index_is_forbidden_to_a_role_without_minutes_manage(): void
    {
        $staff = $this->user(Role::STAFF);
        $this->assertFalse($staff->can('minutes.manage'));

        $this->actingAs($staff)
            ->get(ConvocatoriaResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_the_index_loads_for_a_governance_user(): void
    {
        $this->actingAs($this->user(Role::OWNER))
            ->get(ConvocatoriaResource::getUrl('index'))
            ->assertOk();
    }

    public function test_issuing_from_the_table_action_freezes_the_roll_and_queues_the_notice(): void
    {
        Mail::fake();
        Member::factory()->create(['organisation_id' => $this->org->id, 'joined_at' => '2026-01-01', 'email' => 'a@club.example']);
        $convocatoria = Convocatoria::factory()->create([
            'organisation_id' => $this->org->id,
            'type' => ConvocatoriaType::ORDINARY,
            'held_at' => now()->addDays(30),
        ]);

        $this->actingAs($this->user(Role::OWNER));
        Livewire::test(ListConvocatorias::class)
            ->callTableAction('issue', $convocatoria);

        $this->assertNotNull($convocatoria->fresh()?->issued_at);
        $this->assertSame(1, $convocatoria->fresh()?->recipients()->count());
        Mail::assertQueued(ConvocatoriaMail::class, 1);
    }
}
