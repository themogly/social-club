<?php

namespace Tests\Feature\Counter;

use App\Actions\Memberships\EnrolMembership;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 358 — staff at the club, via Ben: "There should be a way, when a customer gets a couple of things, to put the
 * price as a total instead of having to click their name again to add another gram … it should take them to the search
 * bar for strain." The same strain MERGES into one line, a line can be EDITED, and after «Añadir» the strain search is
 * back at the top, cleared (the browser half: tests/Browser/prove-358-basket.mjs).
 */
class BasketLinesMergeAndEditTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    private Member $member;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        Settings::set('enrol_all_sedes', false, SettingType::BOOL);
        Settings::set('after_recording', 'stay', SettingType::STRING, (string) $this->sede->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 1000000]);
        (new EnrolMembership)->handle($this->member, $this->sede, MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'discount_bp' => 0]), ['actor' => $this->owner, 'fee_cents' => 0]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        session(['counter.location_id' => $this->sede->id]);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);
        CounterOperator::set($this->owner);
    }

    /** €10/g, optionally with a €30 eighth. */
    private function batch(?int $eighth = null, string $acquired = '2026-09-01'): Batch
    {
        return Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->sede->id,
            'remaining_cg' => 10000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 1000, 'price_per_eighth_cents' => $eighth,
            'expires_on' => now()->addYear(), 'acquired_or_harvested_on' => $acquired]);
    }

    private function pos(): Testable
    {
        return Livewire::test(DispensaryPos::class)->call('selectMember', $this->member->id);
    }

    private function add(Testable $pos, string $grams): Testable
    {
        return $pos->call('chooseGenetic', $this->genetic->id)->call('addLine', $grams, 'grams');
    }

    // --- 1–4. The same strain merges --------------------------------------------------------------------------------------------

    public function test_adding_the_same_strain_again_makes_one_line_priced_on_the_total(): void
    {
        $this->batch();
        $pos = $this->add($this->add($this->pos(), '1'), '1');

        $this->assertCount(1, $pos->get('basket'));
        $this->assertSame(200, $pos->get('basket')[0]['grams_cg']);
        $pos->assertSeeHtml('data-merge-note')->assertSee('+1.00 g');
        $pos->assertDispatched('basket-line-added');
        $pos->call('quickCash')->call('commitDispensation');
        $this->assertSame([1, 2000], [Dispensation::query()->withoutGlobalScopes()->sole()->lines()->count(), Dispensation::query()->withoutGlobalScopes()->sole()->total_cents->cents]);
    }

    public function test_with_manual_lotes_only_the_same_lote_merges(): void
    {
        Settings::set('dispensary_batch_selection', 'manual', SettingType::STRING, (string) $this->sede->id);
        $first = $this->batch(acquired: '2026-08-01');
        $second = $this->batch(acquired: '2026-09-01');

        $pos = $this->pos();
        $pos->call('chooseGenetic', $this->genetic->id)->call('selectBatch', $first->id)->call('addLine', '1', 'grams');
        $pos->call('chooseGenetic', $this->genetic->id)->call('selectBatch', $second->id)->call('addLine', '1', 'grams');
        $this->assertCount(2, $pos->get('basket'), 'a different lote is its own line');

        $pos->call('chooseGenetic', $this->genetic->id)->call('selectBatch', $first->id)->call('addLine', '1', 'grams');
        $this->assertCount(2, $pos->get('basket'));
        $this->assertSame([200, 100], array_column($pos->get('basket'), 'grams_cg'), 'the same lote merged');
    }

    public function test_a_merge_past_the_daily_limit_is_stopped_at_commit_like_two_lines_would_be(): void
    {
        $this->member->update(['daily_limit_cg' => 300]);
        $this->batch();
        $pos = $this->add($this->add($this->pos(), '2'), '2');

        $this->assertSame(400, $pos->get('basket')[0]['grams_cg']);
        $pos->call('quickCash')->call('commitDispensation')->assertSet('requireOverride', true);
        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
    }

    public function test_two_plus_one_and_a_half_grams_merge_into_an_eighth(): void
    {
        $this->batch(eighth: 3000);
        $pos = $this->add($this->add($this->pos(), '2'), '1.5');

        $this->assertCount(1, $pos->get('basket'));
        $this->assertSame(350, $pos->get('basket')[0]['grams_cg']);
        $pos->assertSee(__('1/8'));
        $pos->call('quickCash')->call('commitDispensation');
        $this->assertSame(3000, Dispensation::query()->withoutGlobalScopes()->sole()->total_cents->cents);
    }

    // --- 5. A line can be edited ---------------------------------------------------------------------------------------------------

    public function test_tapping_a_line_opens_the_pad_with_its_amount_and_actualizar_and_zero_removes_it(): void
    {
        $this->batch();
        $pos = $this->add($this->pos(), '1');
        $pos->assertSeeHtml('data-edit-line="0"');

        $pos->call('editLine', 0)->assertSet('editingLine', 0)->assertSet('activeGeneticId', $this->genetic->id)
            ->assertSet('weightInput', '1')->assertSee(__('Actualizar'))->assertDontSee(__('Añadir a la cesta'));
        $pos->call('addLine', '2.5', 'grams');
        $this->assertSame([250], array_column($pos->get('basket'), 'grams_cg'));
        $pos->assertSet('editingLine', null)->assertNotDispatched('basket-line-added'); // an edit leaves the operator where they were
        $pos->assertSee(__('Registrar aportación · :total', ['total' => Money::fromCents(2500)->formatted()]));

        $pos->call('editLine', 0)->call('addLine', '0', 'grams');
        $this->assertSame([], $pos->get('basket'));
    }

    public function test_cancelling_tells_the_browser_to_put_the_list_back(): void
    {
        $this->batch();
        $this->pos()->call('chooseGenetic', $this->genetic->id)->call('cancelWeightEntry')->assertDispatched('weight-entry-cancelled');
    }

    // --- 8. The member stays held ----------------------------------------------------------------------------------------------------

    public function test_the_member_stays_held_through_adds_merges_edits_and_cancels(): void
    {
        $this->batch();
        $pos = $this->add($this->add($this->pos(), '1'), '1');
        $pos->call('editLine', 0)->call('addLine', '3', 'grams')->call('chooseGenetic', $this->genetic->id)->call('cancelWeightEntry');

        $pos->assertSet('memberId', $this->member->id);
    }
}
