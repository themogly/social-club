<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Batch;
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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 301 — the genetic tile's stock no longer spills out in GRID view. The geometry is proven in the browser
 * (`tests/Browser/prove-301-tile-fit.mjs`, both orientations, es and en); this pins the markup that makes it so: in grid
 * the price and the stock are two left-aligned lines in a column that may shrink, the figure never shrinks, the status
 * word truncates with its full text in `title` — and the LIST view keeps 225's price-over-stock on the right.
 */
class GeneticTileFitTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_grid_stacks_price_and_stock_inside_the_tile_and_the_list_is_unchanged(): void
    {
        app()->setLocale('es'); // the figure is asserted as shown ("499.00 g" — a point in every language, 316)
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $sede = Location::factory()->create(['organisation_id' => $org->id]);
        app(ActiveScope::class)->setLocation($sede->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$sede->id]);
        $this->actingAs($owner);
        CounterOperator::set($owner);
        (new OpenTill)->handle($sede, 'POS-1', 10000);
        $genetic = Genetic::factory()->create(['organisation_id' => $org->id, 'name' => 'Storage weed storage house']);
        GeneticPrice::factory()->create(['organisation_id' => $org->id, 'genetic_id' => $genetic->id, 'location_id' => $sede->id, 'tier_id' => null, 'price_per_gram_cents' => 1500, 'active' => true]);
        Batch::factory()->create(['organisation_id' => $org->id, 'genetic_id' => $genetic->id, 'location_id' => $sede->id, 'initial_cg' => 49900, 'remaining_cg' => 49900, 'status' => BatchStatus::OPEN]);
        $member = Member::factory()->create(['organisation_id' => $org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth()]);
        Membership::factory()->create(['organisation_id' => $org->id, 'member_id' => $member->id, 'location_id' => $sede->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);

        $html = (string) Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->html();

        preg_match('/<span class="([^"]*)">\s*<span class="text-sm font-semibold text-brand tabular-nums[^"]*">[^<]*\/g<\/span>/', $html, $column);
        $this->assertNotEmpty($column, 'the price column was not found');
        foreach (['as-grid:flex-col', 'as-grid:items-start', 'as-grid:min-w-0', 'as-grid:w-full'] as $grid) {
            $this->assertStringContainsString($grid, $column[1], "grid view: {$grid} missing — price and stock would share one line again");
        }
        foreach (['as-list:sm:flex-col', 'as-list:sm:items-end'] as $list) {
            $this->assertStringContainsString($list, $column[1], "list view changed: {$list} missing");
        }
        $this->assertStringNotContainsString('as-grid:justify-between', $column[1]);

        preg_match('/<span data-genetic-stock class="([^"]*)">(.*?)<\/span>\s*<\/span>\s*<\/button>/s', $html, $stock);
        $this->assertNotEmpty($stock, 'the stock row was not found');
        $this->assertStringContainsString('min-w-0', $stock[1]);
        $this->assertMatchesRegularExpression('/<span class="shrink-0 tabular-nums">499\.00 g<\/span>/', $stock[2], 'the figure may shrink');
        $this->assertMatchesRegularExpression('/title="[^"]+"[^>]*><span class="h-2 w-2 shrink-0[^"]*"><\/span><span class="truncate">/', $stock[2], 'the status word neither truncates nor keeps its title');
    }
}
