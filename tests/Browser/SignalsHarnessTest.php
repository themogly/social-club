<?php

namespace Tests\Browser;

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
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Browser\Concerns\InlinesBuiltCss;
use Tests\TestCase;

/**
 * Prompt 213 — the genetics pane and the cart, with holdings a club under a stock ceiling actually keeps.
 *
 * Seeded at the figures from the report (12.95 g – 32.66 g), which is the point: on `main` all of them badge
 * "Quedan pocas" at once because the fallback threshold was 50 g, and the cart shows the price override
 * sitting open above the commit.
 */
class SignalsHarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    public function test_it_writes_the_dispensary_with_a_clubs_real_holdings(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create(['name' => 'Club Verde']);
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro', 'capacity' => 50]);
        app(ActiveScope::class)->setLocation($location->id);

        $user = User::factory()->create(['name' => 'Lucía Márquez']);
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$location->id]);
        $this->actingAs($user);
        session(['counter.location_id' => $location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($location, 'POS-1', 10000);

        $tier = MembershipTier::factory()->create(['organisation_id' => $org->id, 'default_fee_cents' => 0]);
        $member = Member::factory()->create([
            'organisation_id' => $org->id, 'first_name' => 'Ana', 'last_name' => 'Ruiz',
            'status' => MemberStatus::ACTIVE, 'date_of_birth' => now()->subYears(34),
            'carencia_ends_at' => now()->subDay(), 'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
        Membership::factory()->create([
            'organisation_id' => $org->id, 'member_id' => $member->id, 'location_id' => $location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
            'starts_at' => now()->subMonth(), 'expires_at' => now()->addYear(), 'fee_cents' => 0,
        ]);

        // The exact holdings from the report — every one of them under the old 50 g fallback.
        $first = null;
        foreach ([
            ['Amnesia Haze', 2311], ['CBD Charlotte', 2336], ['Critical Kush', 1295],
            ['Moby Dick', 1455], ['Northern Lights', 1941], ['Purple Haze', 3266],
        ] as [$name, $cg]) {
            $genetic = Genetic::factory()->create(['organisation_id' => $org->id, 'name' => $name, 'active' => true]);
            GeneticPrice::factory()->create([
                'organisation_id' => $org->id, 'genetic_id' => $genetic->id, 'location_id' => $location->id,
                'tier_id' => null, 'price_per_gram_cents' => 800, 'active' => true, 'low_stock_threshold_cg' => null,
            ]);
            Batch::factory()->create([
                'organisation_id' => $org->id, 'genetic_id' => $genetic->id, 'location_id' => $location->id,
                'initial_cg' => $cg, 'remaining_cg' => $cg, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
            ]);
            $first ??= $genetic;
        }

        $page = (string) $this->get(route('counter.pos'))->assertOk()->getContent();
        $held = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $first->id)
            ->set('weightInput', '2,00')
            ->call('addLine')
            ->html();

        $open = (int) strpos($page, '<main');
        $close = (int) strrpos($page, '</main>');
        $page = substr($page, 0, (int) strpos($page, '>', $open) + 1).$held.substr($page, $close);

        file_put_contents(storage_path('app/signals-213.html'), $this->inlineBuiltCss($page));

        $this->assertStringContainsString('Amnesia Haze', $page);
        $this->assertGreaterThan(0, (int) Settings::get('daily_limit_cg', 300));
    }
}
