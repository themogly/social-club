<?php

namespace Tests\Browser;

use App\Actions\Till\OpenTill;
use App\Enums\MemberStatus;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
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
 * Prompt 224 — the bar-only visit, for the before/after.
 *
 *   npm run build
 *   php artisan test tests/Browser/BarOnlyCartHarnessTest.php   # → storage/app/bar-only-224.html
 *   node tests/Browser/shoot-bar-only-cart.mjs [after|before]
 *
 * One state, because one state is the whole argument: a member attached, the Barra source selected, two
 * articles tapped. On `main` the cart shows an empty basket and the money is nowhere.
 */
class BarOnlyCartHarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    public function test_it_writes_the_bar_only_cart(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create(['name' => 'Club Verde']);
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($location->id);

        $user = User::factory()->create(['name' => 'Lucía Márquez']);
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$location->id]);
        $this->actingAs($user);
        session(['counter.location_id' => $location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($location, 'POS-1', 10000);

        $member = Member::factory()->create([
            'organisation_id' => $org->id, 'first_name' => 'Ana', 'last_name' => 'Ruiz',
            'status' => MemberStatus::ACTIVE, 'date_of_birth' => now()->subYears(34),
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
        Membership::factory()->create([
            'organisation_id' => $org->id, 'member_id' => $member->id, 'location_id' => $location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        $beer = Article::factory()->create(['organisation_id' => $org->id, 'location_id' => $location->id, 'name' => 'Cerveza', 'price_cents' => 250, 'stock' => 100, 'active' => true]);
        Article::factory()->create(['organisation_id' => $org->id, 'location_id' => $location->id, 'name' => 'Agua', 'price_cents' => 150, 'stock' => 100, 'active' => true]);

        $page = (string) $this->get(route('counter.pos'))->assertOk()->getContent();

        $held = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('setCatalogueSource', 'bar')
            ->call('addBarItem', $beer->id)
            ->call('addBarItem', $beer->id)
            ->html();

        $open = (int) strpos($page, '<main');
        $close = (int) strrpos($page, '</main>');
        $html = substr($page, 0, (int) strpos($page, '>', $open) + 1).$held.substr($page, $close);

        file_put_contents(storage_path('app/bar-only-224.html'), $this->inlineBuiltCss($html));

        $this->assertStringContainsString('Cerveza', $html);
    }
}
