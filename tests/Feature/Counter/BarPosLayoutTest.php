<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Models\Article;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Batch 2·1 — the two confirmed Bar POS layout defects, guarded at the render level (no browser here).
 *   1) At 1440 a long article name clipped the price because the name flex-cell had no min-w-0, so it
 *      overran the card's overflow-hidden edge — the price now wraps/clamps via min-w-0 + line-clamp-2.
 *   2) At 1024 the basket + Charge dropped below the tall articles column, behind a void under the
 *      short socio panel — the RIGHT column is now placed in grid column 2 spanning both rows.
 * These assert the specific fix classes are present so a regression that removes them fails.
 */
class BarPosLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function bootOperatorWithArticle(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id]);

        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($location->id);
        CounterOperator::set($user);

        // Prompt 175: without an open till the screen IS the till blocking state, so there is no layout
        // to assert. These two defects live on the usable screen — put the screen in that state.
        (new OpenTill)->handle($location, 'POS-1', 10000);

        Article::factory()->create([
            'organisation_id' => $org->id, 'location_id' => $location->id,
            'name' => 'Mechero recargable de larga duración', // deliberately long — the 1440 clip case
            'price_cents' => 100, 'stock' => 10, 'active' => true,
        ]);
    }

    public function test_article_name_wraps_or_clamps_so_the_price_never_clips(): void
    {
        $this->bootOperatorWithArticle();
        $html = Livewire::test(BarPos::class)->html();

        // The name cell can shrink (min-w-0) and clamps to two lines rather than overrunning the price.
        $this->assertStringContainsString('min-w-0 font-semibold leading-tight line-clamp-2', $html);
    }

    /**
     * Re-pointed by prompt 176, not weakened. Prompt 91 pinned the basket to a dedicated grid column so it
     * could never drop below the articles into dead space; 176 replaced that grid with two panes, which
     * defends the same thing more strongly — the cart is a sibling of the selection pane, so it cannot be
     * pushed anywhere by the article list, and the commit is inside it rather than at the end of a page.
     */
    public function test_the_basket_column_is_pinned_beside_the_grid_at_the_tablet_width(): void
    {
        $this->bootOperatorWithArticle();
        $html = Livewire::test(BarPos::class)->html();

        // Two panes, the cart beside the selection pane rather than after it…
        $this->assertStringContainsString('data-selection-pane', $html);
        $this->assertStringContainsString('data-cart-column', $html);
        $this->assertStringContainsString('md:flex-row', $html);

        // …and the commit lives INSIDE the cart column, which is what stops it leaving the viewport.
        $cart = substr($html, strpos($html, 'data-cart-column'));
        $this->assertStringContainsString('data-commit-action', $cart);
    }
}
