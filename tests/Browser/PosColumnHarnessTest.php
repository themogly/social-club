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
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Browser\Concerns\InlinesBuiltCss;
use Tests\TestCase;

/**
 * Prompt 225 — the POS right column and the catalogue's density, for measuring and shooting.
 *
 *   npm run build
 *   php artisan test tests/Browser/PosColumnHarnessTest.php   # → storage/app/pos-225-*.html
 *   node tests/Browser/measure-pos-column.mjs [after|before]
 */
class PosColumnHarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private string $page = '';

    public function test_it_writes_the_pos_states(): void
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

        foreach (['Amnesia Haze', 'Critical Kush', 'Lemon Haze', 'White Widow', 'Blue Dream', 'Gorilla Glue'] as $i => $name) {
            $this->genetic($name, 700 + ($i * 50));
        }
        foreach (['Cerveza', 'Agua', 'Café', 'Refresco'] as $name) {
            Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'name' => $name, 'price_cents' => 250, 'stock' => 100, 'active' => true]);
        }

        $this->page = (string) $this->get(route('counter.pos'))->assertOk()->getContent();

        $clear = $this->member(0);
        $genetic = Genetic::query()->where('name', 'Amnesia Haze')->firstOrFail();

        // 1. The working screen, a socio held and a basket started — the density and column states.
        $this->write('working', Livewire::test(DispensaryPos::class)
            ->call('selectMember', $clear->id)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '2')
            ->call('addLine'));

        // 2. The bar source, which defaults to a grid.
        $this->write('bar', Livewire::test(DispensaryPos::class)
            ->call('selectMember', $clear->id)
            ->call('setCatalogueSource', 'bar'));

        // 3. A blocked socio: the selling surface replaced by its resolution.
        $this->write('blocked', Livewire::test(DispensaryPos::class)->call('selectMember', $this->member(2500)->id));

        // 4. A socio with NO PHOTO, which is the state prompt 225's three snapshots never covered — every one
        //    of them carried a photo, so the nag row 225 shipped was never on a page the 44-floor sweep or the
        //    geometry checks ran against. The assertions were right; the page they ran on did not show the
        //    thing (prompt 228).
        $this->write('no-photo', Livewire::test(DispensaryPos::class)->call('selectMember', $clear->id));

        // 5. A flash in the pinned foot, on a photo-less socio — the two things that grow the pinned regions
        //    at once. Prompt 234: neither may take the basket below its floor.
        $this->write('flash', Livewire::test(DispensaryPos::class)
            ->call('selectMember', $clear->id)
            ->call('commitDispensation'));

        $this->assertStringContainsString('data-blocked-member', (string) file_get_contents(storage_path('app/pos-225-blocked.html')));
        $this->assertStringContainsString('data-commit-feedback', (string) file_get_contents(storage_path('app/pos-225-flash.html')));
    }

    private function genetic(string $name, int $priceCents): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => $name]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => $priceCents, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);

        return $genetic;
    }

    private function member(int $feeCents): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'first_name' => 'Ana', 'last_name' => 'Ruiz',
            'status' => MemberStatus::ACTIVE, 'date_of_birth' => now()->subYears(34),
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => $feeCents,
        ]);

        return $member;
    }

    private function write(string $state, Testable $component): void
    {
        $open = (int) strpos($this->page, '<main');
        $close = (int) strrpos($this->page, '</main>');
        $html = substr($this->page, 0, (int) strpos($this->page, '>', $open) + 1).$component->html().substr($this->page, $close);

        file_put_contents(storage_path('app/pos-225-'.$state.'.html'), $this->inlineBuiltCss($html));
    }
}
