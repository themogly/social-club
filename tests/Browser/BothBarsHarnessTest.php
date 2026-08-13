<?php

namespace Tests\Browser;

use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
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
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Browser\Concerns\InlinesBuiltCss;
use Tests\TestCase;

/**
 * Prompt 230 — the two bars, same articles, for the side-by-side.
 *
 *   npm run build
 *   php artisan test tests/Browser/BothBarsHarnessTest.php   # → storage/app/bars-230-*.html
 *   node tests/Browser/measure-both-bars.mjs [after|before]
 *
 * Four states: each screen in each layout, on the SAME sede with the SAME articles — including one that is
 * sold out, which is the fact the two screens disagreed about most.
 */
class BothBarsHarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private string $barPage = '';

    private string $posPage = '';

    public function test_it_writes_both_bars(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create(['name' => 'Club Verde']);
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($this->location->id);

        $user = User::factory()->create(['name' => 'Lucía Márquez']);
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        foreach ([['Cerveza', 12, 250], ['Agua', 40, 150], ['Café', 0, 180], ['Refresco', 3, 200], ['Camiseta', 7, 1500]] as [$name, $stock, $price]) {
            Article::factory()->create([
                'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
                'name' => $name, 'price_cents' => $price, 'stock' => $stock, 'active' => true,
                'low_stock_threshold' => 5,
            ]);
        }

        $this->barPage = (string) $this->get(route('counter.bar'))->assertOk()->getContent();
        $this->posPage = (string) $this->get(route('counter.pos'))->assertOk()->getContent();

        $member = $this->member();

        foreach (['grid', 'list'] as $layout) {
            $this->write($this->barPage, 'bar-'.$layout, Livewire::test(BarPos::class)->call('setArticleLayout', $layout));

            $this->write($this->posPage, 'pos-'.$layout, Livewire::test(DispensaryPos::class)
                ->call('selectMember', $member->id)
                ->call('setCatalogueSource', 'bar')
                ->call('setGeneticLayout', $layout));
        }

        // …and the Bar with a basket, for the totalled commit.
        $beer = Article::query()->where('name', 'Cerveza')->firstOrFail();
        $this->write($this->barPage, 'bar-basket', Livewire::test(BarPos::class)->call('addArticle', $beer->id)->call('addArticle', $beer->id));

        $this->assertStringContainsString('data-article-card', (string) file_get_contents(storage_path('app/bars-230-bar-grid.html')));
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'first_name' => 'Ana', 'last_name' => 'Ruiz',
            'status' => MemberStatus::ACTIVE, 'date_of_birth' => now()->subYears(34),
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    private function write(string $page, string $state, Testable $component): void
    {
        $open = (int) strpos($page, '<main');
        $close = (int) strrpos($page, '</main>');
        $html = substr($page, 0, (int) strpos($page, '>', $open) + 1).$component->html().substr($page, $close);

        file_put_contents(storage_path('app/bars-230-'.$state.'.html'), $this->inlineBuiltCss($html));
    }
}
