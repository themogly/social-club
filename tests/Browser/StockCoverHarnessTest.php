<?php

namespace Tests\Browser;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Browser\Concerns\InlinesBuiltCss;
use Tests\TestCase;

/**
 * Prompt 216 — a fast mover and a slow mover with IDENTICAL stock, side by side on the POS grid.
 *
 * That is the whole picture: on `main` a flat threshold makes them look the same, because a flat threshold
 * cannot tell them apart. On the branch one carries its cover figure.
 */
class StockCoverHarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    public function test_it_writes_the_grid_with_a_fast_and_a_slow_mover(): void
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
            'starts_at' => now()->subYear(), 'expires_at' => now()->addYear(), 'fee_cents' => 0,
        ]);

        // IDENTICAL on hand — 20 g each — and wildly different demand. That is the point of the picture.
        foreach ([['Critical Kush', 1500], ['Amnesia Haze', 50]] as [$name, $perDayCg]) {
            $genetic = Genetic::factory()->create(['organisation_id' => $org->id, 'name' => $name, 'active' => true]);
            GeneticPrice::factory()->create([
                'organisation_id' => $org->id, 'genetic_id' => $genetic->id, 'location_id' => $location->id,
                'tier_id' => null, 'price_per_gram_cents' => 800, 'active' => true, 'low_stock_threshold_cg' => null,
            ]);
            Batch::factory()->create([
                'organisation_id' => $org->id, 'genetic_id' => $genetic->id, 'location_id' => $location->id,
                'initial_cg' => 2000, 'remaining_cg' => 2000,
                'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
            ]);

            // An anchor sale before the window (so neither is "thin"), then a fortnight of real trading.
            foreach (array_merge([60], range(1, 14)) as $daysAgo) {
                $dispensation = Dispensation::factory()->create([
                    'organisation_id' => $org->id, 'location_id' => $location->id, 'member_id' => $member->id,
                    'status' => DispensationStatus::COMPLETED, 'dispensed_at' => now()->subDays($daysAgo),
                ]);
                DispensationLine::factory()->create([
                    'dispensation_id' => $dispensation->id, 'genetic_id' => $genetic->id,
                    'grams_cg' => $daysAgo === 60 ? 1 : $perDayCg,
                ]);
            }
        }

        $page = (string) $this->get(route('counter.pos'))->assertOk()->getContent();
        $held = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->html();

        $open = (int) strpos($page, '<main');
        $close = (int) strrpos($page, '</main>');
        $page = substr($page, 0, (int) strpos($page, '>', $open) + 1).$held.substr($page, $close);

        file_put_contents(storage_path('app/cover-216.html'), $this->inlineBuiltCss($page));

        $this->assertStringContainsString('Critical Kush', $page);
        $this->assertStringContainsString('Amnesia Haze', $page);
    }
}
