<?php

namespace Tests\Feature\Governance;

use App\Actions\Governance\IssueConvocatoria;
use App\Enums\Role;
use App\Filament\Pages\Asamblea;
use App\Models\AssemblyAttendance;
use App\Models\Convocatoria;
use App\Models\Member;
use App\Models\Minute;
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
 * Prompt 137 — the Asamblea page is the entry point that makes the governance actions reachable. Gated on
 * minutes.manage; every button routes through RecordAttendance / RecordResolution / DraftAssemblyMinute.
 */
class AsambleaPageTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private User $owner;

    private Convocatoria $convocatoria;

    /** @var array<int, Member> */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        Mail::fake();

        $this->members = Member::factory()->count(3)->create([
            'organisation_id' => $this->org->id, 'joined_at' => '2026-01-01', 'email' => null,
        ])->all();
        $draft = Convocatoria::factory()->create([
            'organisation_id' => $this->org->id, 'held_at' => now()->addDays(30),
            'agenda' => ['Aprobación de cuentas', 'Ruegos y preguntas'],
        ]);
        $this->convocatoria = (new IssueConvocatoria)->handle($draft, $this->owner);
    }

    public function test_a_staff_user_cannot_open_the_page(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);

        $this->actingAs($staff)->get(Asamblea::getUrl())->assertForbidden();
    }

    public function test_a_governance_user_can_open_the_page(): void
    {
        $this->actingAs($this->owner)->get(Asamblea::getUrl())->assertOk();
    }

    public function test_marking_present_from_the_page_records_attendance(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Asamblea::class)
            ->set('convocatoriaId', $this->convocatoria->id)
            ->call('markPresent', $this->members[0]->id);

        $this->assertSame(1, AssemblyAttendance::where('convocatoria_id', $this->convocatoria->id)->count());
    }

    public function test_saving_a_resolution_from_the_page_records_it(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Asamblea::class)
            ->set('convocatoriaId', $this->convocatoria->id)
            ->set('resResult.1', 'APPROVED')
            ->set('resFor.1', 3)
            ->call('saveResolution', 1, 'Aprobación de cuentas');

        $this->assertSame(1, $this->convocatoria->resolutions()->count());
        $this->assertSame(3, $this->convocatoria->resolutions()->first()?->votes_for);
    }

    public function test_drafting_the_acta_from_the_page_creates_the_minute(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Asamblea::class)
            ->set('convocatoriaId', $this->convocatoria->id)
            ->call('markPresent', $this->members[0]->id)
            ->call('markPresent', $this->members[1]->id)
            ->call('draftActa');

        $this->assertSame(1, Minute::where('convocatoria_id', $this->convocatoria->id)->count());
    }
}
