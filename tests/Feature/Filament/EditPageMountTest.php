<?php

namespace Tests\Feature\Filament;

use App\Enums\DiscountMode;
use App\Enums\Role;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Discounts\Pages\EditDiscount;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\MembershipTiers\Pages\EditMembershipTier;
use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Models\Article;
use App\Models\Discount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every resource whose form edits a Money/Weight value via a virtual euro/gram field
 * must NOT leave the raw cast object in the form state — Livewire cannot hold a value
 * object ("Property type not supported: [{cents:…}]"). Mounting each Edit page with a
 * real record exercises mutateFormDataBeforeFill and fails loudly if a cast leaks.
 */
class EditPageMountTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        Filament::setCurrentPanel('admin');
    }

    public function test_money_backed_edit_pages_mount_without_leaking_a_cast_object(): void
    {
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => 2000, 'daily_limit_cg' => 350]);
        $article = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'price_cents' => 500]);
        $discount = Discount::factory()->create(['organisation_id' => $this->org->id, 'mode' => DiscountMode::FIXED, 'value_cents' => 300, 'value_bp' => null]);
        $category = ExpenseCategory::factory()->create(['organisation_id' => $this->org->id]);
        $expense = Expense::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'category_id' => $category->id, 'amount_cents' => 1000]);
        $supplier = Supplier::factory()->create(['organisation_id' => $this->org->id]);
        $purchase = Purchase::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'supplier_id' => $supplier->id, 'amount_cents' => 50000, 'paid_cents' => 30000]);

        $pages = [
            [EditMembershipTier::class, $tier],
            [EditArticle::class, $article],
            [EditDiscount::class, $discount],
            [EditExpense::class, $expense],
            [EditPurchase::class, $purchase],
        ];

        foreach ($pages as [$page, $record]) {
            Livewire::actingAs($this->owner)
                ->test($page, ['record' => $record->getRouteKey()])
                ->assertOk();
        }
    }
}
