<?php

namespace Tests\Feature\Till;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Exceptions\TillAlreadyOpenException;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\TillSession as TillSessionScreen;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use App\Support\TerminalName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 84 — the till terminal is a picker over configured terminals, normalised so a typo can't open a
 * phantom till the POS then can't find. "POS 1", "POS-1" and "pos-1" are ONE terminal. The picker is the
 * MULTI-till path (prompt 102), so this sede enables it; single-till behaviour is covered in SingleTillTest.
 */
class TillTerminalPickerTest extends TestCase
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
        Settings::set('multiple_tills_enabled', true, SettingType::BOOL, $this->location->id);
    }

    private function operator(Role $role = Role::MANAGER): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        return $user;
    }

    public function test_terminal_name_normalisation_collapses_spelling_variants(): void
    {
        $key = TerminalName::key('POS-1');
        $this->assertSame($key, TerminalName::key('POS 1'));
        $this->assertSame($key, TerminalName::key('pos-1'));
        $this->assertSame($key, TerminalName::key('  Pos  1 '));
        $this->assertNotSame($key, TerminalName::key('POS-2'));
        $this->assertSame('POS 1', TerminalName::clean('  POS   1 ')); // display form: trimmed, collapsed
    }

    public function test_opening_a_till_normalises_and_registers_the_terminal(): void
    {
        (new OpenTill)->handle($this->location, '  POS  1 ', 10000);

        $session = TillSession::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('POS 1', $session->terminal); // stored cleaned
        $this->assertSame(['POS 1'], $this->location->refresh()->terminalNames()); // configured on the location
    }

    public function test_a_spelling_variant_cannot_open_a_second_till(): void
    {
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $this->expectException(TillAlreadyOpenException::class);
        (new OpenTill)->handle($this->location, 'pos 1', 10000); // same terminal, different spelling
    }

    public function test_the_dispensary_pos_resolves_a_session_opened_as_a_variant(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        // The POS looking for "pos 1" must still find the "POS-1" session — raw-string comparison used to miss.
        Livewire::test(DispensaryPos::class)
            ->set('terminal', 'pos 1')
            ->assertViewHas('openTill', fn (?TillSession $t): bool => $t !== null && $t->terminal === 'POS-1');
    }

    public function test_a_single_open_session_is_auto_adopted_and_several_are_not(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        Livewire::test(TillSessionScreen::class)->assertSet('terminal', 'POS-1'); // adopted

        (new OpenTill)->handle($this->location, 'POS-2', 10000);
        Livewire::test(TillSessionScreen::class)->assertSet('terminal', ''); // ambiguous → operator picks
    }

    public function test_the_terminal_picker_lists_the_configured_terminals(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        Livewire::test(TillSessionScreen::class)->assertViewHas('terminals', ['POS-1']);
    }

    public function test_terminals_are_per_location(): void
    {
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $other = Location::factory()->create(['organisation_id' => $this->org->id]);

        $this->assertSame(['POS-1'], $this->location->refresh()->terminalNames());
        $this->assertSame([], $other->refresh()->terminalNames()); // A's terminal is not visible at B
    }

    public function test_the_not_found_message_lists_the_open_terminals(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000); // open on POS-1

        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        $batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'remaining_cg' => 100000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        Livewire::test(DispensaryPos::class)
            ->set('terminal', 'POS-9') // a terminal with NO open till
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $genetic->id)->set('weightInput', '1')->call('addLine')
            ->call('commitDispensation')
            ->assertSet('flashType', 'error')
            ->assertSee(__('No hay caja abierta en este terminal. Con caja abierta: :terminals', ['terminals' => 'POS-1']));
    }
}
