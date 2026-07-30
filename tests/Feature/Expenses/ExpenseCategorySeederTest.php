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

        $byName = $categories->keyBy('name');
        // Petty cash (drawer) vs overhead (paid outside the till).
        $this->assertSame(ExpenseKind::TILL, $byName['Consumibles']->default_kind);
        $this->assertSame(ExpenseKind::OVERHEAD, $byName['Stock']->default_kind);
        $this->assertSame(ExpenseKind::OVERHEAD, $byName['Alquiler']->default_kind);
        $this->assertSame(ExpenseKind::OVERHEAD, $byName['Pago de personal']->default_kind);
    }
}
