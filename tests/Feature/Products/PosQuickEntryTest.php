<?php

namespace Tests\Feature\Products;

use App\Enums\BatchStatus;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 133 — one-tap weight presets and "their usual". Both are INPUTS: a preset fills the same weight the
 * operator would type, so every eligibility/limit check applies identically; "their usual" only offers what is
 * sellable at the sede, sourced in one query on identification.
 */
class PosQuickEntryTest extends TestCase
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

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        return $user;
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    private function genetic(string $name, int $perGram, ?int $perEighth = null): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => $name]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => $perGram, 'price_per_eighth_cents' => $perEighth, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'remaining_cg' => 100000, 'status' => BatchStatus::OPEN,
        ]);

        return $genetic;
    }

    private function history(Member $member, Genetic $genetic, string $at): void
    {
        $dispensation = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'member_id' => $member->id,
            'status' => DispensationStatus::COMPLETED, 'dispensed_at' => $at,
        ]);
        DispensationLine::factory()->create([
            'dispensation_id' => $dispensation->id, 'genetic_id' => $genetic->id, 'grams_cg' => 350,
        ]);
    }

    public function test_tapping_3_5g_is_the_same_grams_and_triggers_the_eighth_break(): void
    {
        $this->operator();
        $genetic = $this->genetic('Amnesia', 1000, 3000); // €10/g, eighth €30 (cheaper than 3.5×10=35)
        $member = $this->member();

        $component = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $genetic->id)
            ->call('applyWeightPreset', 350);

        // Same grams as typing 3,50 (the preset only fills the input).
        $this->assertSame('3,5', $component->get('weightInput'));
        $this->assertSame(350, $component->instance()->activeEntryGramsCg());

        // The 3.5 g preset shows the eighth price, not 3.5 × rate.
        $preset = collect($component->instance()->quickEntryPresets())->firstWhere('grams_cg', 350);
        $this->assertTrue($preset['eighth_applied']);
        $this->assertSame(3000, $preset['price_cents']); // the €30 eighth, not €35
    }

    public function test_a_preset_over_the_remaining_allowance_is_unavailable(): void
    {
        $this->operator();
        $genetic = $this->genetic('Kush', 1000);
        // Daily limit 200cg (2 g): the 3.5 g and 5 g presets must be unavailable, 1 g and 2 g available.
        $member = $this->member();
        $member->update(['daily_limit_cg' => 200]);

        $presets = collect(Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $genetic->id)
            ->instance()->quickEntryPresets())->keyBy('grams_cg');

        $this->assertTrue($presets[100]['available']);   // 1 g
        $this->assertTrue($presets[200]['available']);   // 2 g
        $this->assertFalse($presets[350]['available']);  // 3.5 g > 2 g remaining
        $this->assertFalse($presets[500]['available']);  // 5 g
    }

    public function test_two_sedes_see_their_own_preset_set(): void
    {
        $this->operator();
        $other = Location::factory()->create(['organisation_id' => $this->org->id]);
        Settings::set('pos_weight_presets_g', [1, 2], SettingType::JSON, $this->location->id);
        Settings::set('pos_weight_presets_g', [10, 20], SettingType::JSON, $other->id);
        $genetic = $this->genetic('Haze', 1000);
        $member = $this->member();

        $labels = collect(Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)->call('chooseGenetic', $genetic->id)
            ->instance()->quickEntryPresets())->pluck('label')->all();

        $this->assertSame(['1', '2'], $labels); // this sede's set, not the other's
    }

    public function test_their_usual_shows_only_sellable_genetics(): void
    {
        $this->operator();
        $sellable = $this->genetic('Sellable', 1000);
        $unpriced = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Unpriced']); // no price → not sellable
        $member = $this->member();
        $this->history($member, $sellable, '2026-01-10 10:00:00');
        $this->history($member, $unpriced, '2026-01-11 10:00:00'); // more recent, but not sellable

        $usual = collect(Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->instance()->theirUsual());

        $this->assertSame(['Sellable'], $usual->pluck('name')->all()); // the unpriced one is filtered out
    }

    public function test_a_member_with_no_history_sees_no_suggestions(): void
    {
        $this->operator();
        $this->genetic('Anything', 1000);
        $member = $this->member();

        $this->assertSame([], Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->instance()->theirUsual());
    }

    public function test_their_usual_does_not_add_a_query_per_suggestion(): void
    {
        $this->operator();
        $one = $this->genetic('One', 1000);
        [$two, $three] = [$this->genetic('Two', 1000), $this->genetic('Three', 1000)];
        $member = $this->member();

        // One historical genetic.
        $this->history($member, $one, '2026-01-01 10:00:00');
        DB::enableQueryLog();
        Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);
        $withOne = count(DB::getQueryLog());
        DB::flushQueryLog();

        // Three historical genetics — the count must not scale with the number of suggestions.
        $this->history($member, $two, '2026-01-02 10:00:00');
        $this->history($member, $three, '2026-01-03 10:00:00');
        Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);
        $withThree = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($withOne + 2, $withThree);
    }
}
