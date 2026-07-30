<?php

namespace Database\Seeders;

use App\Enums\ExpenseKind;
use App\Models\ExpenseCategory;
use App\Support\ActiveScope;
use Illuminate\Database\Seeder;

/**
 * The default expense categories every org starts with. Idempotent — keyed on
 * organisation + name via firstOrCreate, so re-running (or a second demo build)
 * never duplicates. Kinds reflect how each is normally paid: Consumibles is petty
 * cash from the drawer (TILL); everything else is an overhead paid outside the till.
 */
class ExpenseCategorySeeder extends Seeder
{
    /** @var array<string, ExpenseKind> Default category name → its default kind. */
    private const DEFAULTS = [
        'Stock' => ExpenseKind::OVERHEAD,
        'Consumibles' => ExpenseKind::TILL,
        'Pago de personal' => ExpenseKind::OVERHEAD,
        'Reparaciones y mantenimiento' => ExpenseKind::OVERHEAD,
        'Alquiler' => ExpenseKind::OVERHEAD,
        'Suministros' => ExpenseKind::OVERHEAD,
        'Otros' => ExpenseKind::OVERHEAD,
    ];

    public function run(): void
    {
        $organisationId = app(ActiveScope::class)->organisationId();

        if ($organisationId !== null) {
            self::seedFor($organisationId);
        }
    }

    /** Seed (idempotently) the default categories for one organisation. */
    public static function seedFor(string $organisationId): void
    {
        foreach (self::DEFAULTS as $name => $kind) {
            ExpenseCategory::query()->firstOrCreate(
                ['organisation_id' => $organisationId, 'name' => $name],
                ['default_kind' => $kind, 'active' => true],
            );
        }
    }
}
