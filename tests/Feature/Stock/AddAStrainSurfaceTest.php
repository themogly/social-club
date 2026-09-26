<?php

namespace Tests\Feature\Stock;

use App\Enums\CategoryAppliesTo;
use App\Enums\Role;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Genetics\Pages\EditGenetic;
use App\Models\Category;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 247 — the two surfaces around the "add a strain" flow:
 *   §4  the home screen offers "Añadir variedad" and "Añadir stock", gated by the same policies their create
 *       pages are — STAFF, who cannot create either, see neither button.
 *   §3  the category is a menu GROUP, not a fact about the strain: it lives under Publicación and is HIDDEN
 *       entirely when the club has defined no genetic categories (an empty select is a question with no answer).
 */
class AddAStrainSurfaceTest extends TestCase
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
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(ActiveScope::class)->setLocation($this->location->id);
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->attach($this->location->id);

        return $user;
    }

    // --- §4  the dashboard shortcuts --------------------------------------------

    public function test_an_owner_sees_both_add_shortcuts_on_the_dashboard(): void
    {
        Livewire::actingAs($this->user(Role::OWNER))->test(Dashboard::class)
            ->assertActionVisible('addStrain')
            ->assertActionVisible('addStock');
    }

    public function test_a_staff_operator_sees_neither_add_shortcut(): void
    {
        // STAFF holds neither genetics.manage nor stock.manage — the buttons would 403 their create pages.
        Livewire::actingAs($this->user(Role::STAFF))->test(Dashboard::class)
            ->assertActionHidden('addStrain')
            ->assertActionHidden('addStock');
    }

    // --- §3  the category is a menu group, hidden when there are none ------------

    public function test_the_category_field_is_hidden_when_the_club_has_no_genetic_categories(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        Livewire::actingAs($this->user(Role::OWNER))
            ->test(EditGenetic::class, ['record' => $genetic->getRouteKey()])
            ->assertFormFieldIsHidden('category_id');
    }

    public function test_the_category_field_appears_once_a_genetic_category_exists(): void
    {
        Category::factory()->create([
            'organisation_id' => $this->org->id,
            'applies_to' => CategoryAppliesTo::GENETIC->value,
        ]);
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        Livewire::actingAs($this->user(Role::OWNER))
            ->test(EditGenetic::class, ['record' => $genetic->getRouteKey()])
            ->assertFormFieldIsVisible('category_id');
    }
}
