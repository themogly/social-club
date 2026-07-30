<?php

namespace Tests\Feature\Till;

use App\Actions\Till\CloseTill;
use App\Actions\Till\OpenTill;
use App\Actions\Till\RecordCashMovement;
use App\Enums\CashMovementType;
use App\Enums\Role;
use App\Enums\TillSessionStatus;
use App\Livewire\Counter\TillSession;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Money;
use App\Support\TillSummary;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TillUiTest extends TestCase
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

    /** A manager holds till.close (and till.open) — the closer of the blind arqueo. */
    private function manager(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);

        return $user;
    }

    public function test_the_till_screen_renders_for_a_manager(): void
    {
        $this->actingAs($this->manager());
        app(ActiveScope::class)->setLocation($this->location->id);

        Livewire::test(TillSession::class)
            ->assertOk()
            ->assertSet('noLocation', false)
            ->assertSee(__('Abrir caja')); // no open session yet → the open form
    }

    /**
     * The critical test: a BLIND arqueo. Until the count is submitted the expected
     * figure is absent from BOTH the component's public state AND the rendered output;
     * only a successful close reveals it, read back from the immutable closed session.
     */
    public function test_the_close_flow_hides_the_expected_figure_until_the_count_is_submitted(): void
    {
        $this->actingAs($this->manager());
        app(ActiveScope::class)->setLocation($this->location->id);

        // Open with cash so the expected drawer figure is non-zero and distinctive.
        $session = (new OpenTill)->handle($this->location, 'POS-1', 20000); // €200 float
        (new RecordCashMovement)->handle($session, CashMovementType::IN, 5000); // +€50 → €250

        $expectedCents = TillSummary::expectedCents($session);
        $this->assertSame(25000, $expectedCents);
        $expectedFormatted = Money::fromCents($expectedCents)->formatted(); // e.g. "250,00 €"

        // The single open session is adopted on mount.
        $component = Livewire::test(TillSession::class)
            ->assertOk()
            ->assertSet('terminal', 'POS-1');

        // Enter the blind count — the expected figure must NOT be in state or output.
        $component->call('startClose')
            ->assertSet('closing', true)
            ->assertSet('countSubmitted', false)
            ->assertSet('expected', null)      // the payload, not just the pixels…
            ->assertSet('variance', null)
            ->assertDontSee($expectedFormatted); // …and not the pixels either.

        // Submit a count that differs from expected (but within tolerance, so no note):
        // seeing the expected figure now proves it was revealed, not merely echoed back.
        $component->set('countInput', '252') // €252 counted vs €250 expected → +€2
            ->call('submitCount')
            ->assertSet('countSubmitted', true)
            ->assertSet('counted', 25200)
            ->assertSet('expected', 25000)
            ->assertSet('variance', 200)
            ->assertSee($expectedFormatted);

        $this->assertDatabaseHas('till_sessions', [
            'id' => $session->id,
            'status' => TillSessionStatus::CLOSED->value,
            'counted_cents' => 25200,
            'expected_cents' => 25000,
            'variance_cents' => 200,
        ]);
    }

    public function test_the_till_resource_index_loads_for_a_manager(): void
    {
        $indexUrl = route('filament.admin.resources.till-sessions.index');

        $this->actingAs($this->manager())->get($indexUrl)->assertOk();
    }

    public function test_the_view_page_renders_the_z_report_for_a_closed_session(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);
        app(ActiveScope::class)->setLocation($this->location->id);

        $session = (new OpenTill)->handle($this->location, 'POS-9', 10000); // €100 float
        (new RecordCashMovement)->handle($session, CashMovementType::IN, 5000); // +€50 → €150 expected
        $closed = (new CloseTill)->handle($session, 15000, $manager); // counted €150 → variance €0

        $viewUrl = route('filament.admin.resources.till-sessions.view', ['record' => $closed->id]);

        $this->get($viewUrl)
            ->assertOk()
            ->assertSee('POS-9')
            ->assertSee(Money::fromCents(15000)->formatted()); // expected/counted in the Z-report
    }

    /**
     * STAFF actually hold till.open (so they CAN view). The genuine denial is a user
     * with NO till permission at all — here a fresh account with no role.
     */
    public function test_a_user_with_no_till_permission_is_forbidden_from_the_resource(): void
    {
        $indexUrl = route('filament.admin.resources.till-sessions.index');

        $user = User::factory()->create(); // no role → no till.open / till.close

        $this->actingAs($user)->get($indexUrl)->assertForbidden();
    }
}
