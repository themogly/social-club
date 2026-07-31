<?php

namespace Tests\Feature\Expenses;

use App\Enums\ExpenseKind;
use App\Models\ExpenseCategory;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseCategorySeederTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    public function test_it_seeds_the_default_categories_idempotently_with_the_right_kinds(): void
    {
        ExpenseCategorySeeder::seedFor($this->org->id);
        ExpenseCategorySeeder::seedFor($this->org->id); // re-run must not duplicate

        $categories = ExpenseCategory::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->org->id)->get();

        $this->assertCount(7, $categories);

        // Prompt 70: category NAMES are now locale-aware, so this asserts the stable SHAPE (kinds), not
        // Spanish literals. Exactly one petty-cash (TILL) category — the drawer's — and six overheads.
        $this->assertSame(1, $categories->where('default_kind', ExpenseKind::TILL)->count());
        $this->assertSame(6, $categories->where('default_kind', ExpenseKind::OVERHEAD)->count());
        $this->assertTrue($categories->every(fn (ExpenseCategory $c): bool => $c->active));
    }
}
