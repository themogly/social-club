<?php

namespace Tests\Browser;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Category;
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
 * Prompt 212 — the dispensary with **forty** articles at the sede, on each source.
 *
 * Seeded that way deliberately: five looked fine and forty is the same code. The `before` frame is the cart
 * column carrying forty chips, which is the picture the branch exists to replace.
 */
class CatalogueSourceHarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    public function test_it_writes_the_dispensary_on_each_source(): void
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

        foreach (['Amnesia Haze', 'Critical Kush', 'Moby Dick', 'Northern Lights'] as $name) {
            $genetic = Genetic::factory()->create(['organisation_id' => $org->id, 'name' => $name, 'active' => true]);
            GeneticPrice::factory()->create([
                'organisation_id' => $org->id, 'genetic_id' => $genetic->id, 'location_id' => $location->id,
                'tier_id' => null, 'price_per_gram_cents' => 800, 'active' => true,
            ]);
            Batch::factory()->create([
                'organisation_id' => $org->id, 'genetic_id' => $genetic->id, 'location_id' => $location->id,
                'initial_cg' => 500000, 'remaining_cg' => 500000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
            ]);
        }

        // FORTY articles — the figure the complaint is really about.
        $drinks = Category::factory()->create(['organisation_id' => $org->id, 'name' => 'Refrescos']);
        $merch = Category::factory()->create(['organisation_id' => $org->id, 'name' => 'Merchandising']);
        foreach (range(1, 40) as $i) {
            Article::factory()->create([
                'organisation_id' => $org->id, 'location_id' => $location->id,
                'name' => ($i % 2 === 0 ? 'Refresco ' : 'Camiseta ').sprintf('%02d', $i),
                'category_id' => $i % 2 === 0 ? $drinks->id : $merch->id,
                'price_cents' => 200 + ($i * 15), 'stock' => $i % 7 === 0 ? 2 : 20,
                'low_stock_threshold' => 5, 'active' => true,
            ]);
        }

        $page = (string) $this->get(route('counter.pos'))->assertOk()->getContent();
        $open = (int) strpos($page, '<main');
        $close = (int) strrpos($page, '</main>');
        $head = substr($page, 0, (int) strpos($page, '>', $open) + 1);
        $tail = substr($page, $close);

        foreach (['genetics', 'bar'] as $source) {
            // A line on the basket, because prompt 91's progressive disclosure keeps the whole payment
            // apparatus — and, on `main`, the bar chip list inside it — hidden until the transaction has
            // taken shape. Without one the `before` frame would not show the thing it is a frame of.
            $component = Livewire::test(DispensaryPos::class)
                ->call('selectMember', $member->id)
                ->call('chooseGenetic', Genetic::query()->withoutGlobalScopes()->firstOrFail()->id)
                ->set('weightInput', '2,00')
                ->call('addLine');

            // Guarded so the SAME harness runs against `main`, which has no source toggle — the `before`
            // frames are then two copies of the one pane it had, with the chip list in the cart column,
            // which is exactly the picture this branch replaces.
            if (method_exists(DispensaryPos::class, 'setCatalogueSource')) {
                $component->call('setCatalogueSource', $source);
            }

            $held = $component->html();

            file_put_contents(
                storage_path('app/catalogue-212-'.$source.'.html'),
                $this->inlineBuiltCss($head.$held.$tail),
            );
        }

        $this->assertFileExists(storage_path('app/catalogue-212-genetics.html'));
        $this->assertFileExists(storage_path('app/catalogue-212-bar.html'));
    }
}
