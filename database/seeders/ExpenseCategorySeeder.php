<?php

namespace Database\Seeders;

use App\Enums\ExpenseKind;
use App\Models\ExpenseCategory;
use App\Support\ActiveScope;
use Illuminate\Database\Seeder;

/**
 * The default expense categories every org starts with. Idempotent — keyed on
 * organisation + name via firstOrCreate, so re-running (or a second demo build)
 * never duplicates. Kinds reflect how each is normally paid: the petty-cash
 * category is drawer cash (TILL); everything else is an overhead paid outside the
 * till. Names are locale-aware (prompt 70) — an English club gets English category
 * names — but the KIND is the stable identity (the petty-cash category is looked up
 * by `default_kind = TILL`, never by its localised name).
 */
class ExpenseCategorySeeder extends Seeder
{
    /**
     * Canonical category → its kind and per-locale name. The array key is a stable
     * internal id; the displayed name comes from the active locale.
     *
     * @var array<string, array{kind: ExpenseKind, es: string, en: string}>
     */
    private const DEFAULTS = [
        'stock' => ['kind' => ExpenseKind::OVERHEAD, 'es' => 'Stock', 'en' => 'Stock'],
        'consumables' => ['kind' => ExpenseKind::TILL, 'es' => 'Consumibles', 'en' => 'Consumables'],
        'staff_pay' => ['kind' => ExpenseKind::OVERHEAD, 'es' => 'Pago de personal', 'en' => 'Staff pay'],
        'repairs' => ['kind' => ExpenseKind::OVERHEAD, 'es' => 'Reparaciones y mantenimiento', 'en' => 'Repairs & maintenance'],
        'rent' => ['kind' => ExpenseKind::OVERHEAD, 'es' => 'Alquiler', 'en' => 'Rent'],
        'utilities' => ['kind' => ExpenseKind::OVERHEAD, 'es' => 'Suministros', 'en' => 'Utilities'],
        'other' => ['kind' => ExpenseKind::OVERHEAD, 'es' => 'Otros', 'en' => 'Other'],
    ];

    public function run(): void
    {
        $organisationId = app(ActiveScope::class)->organisationId();

        if ($organisationId !== null) {
            self::seedFor($organisationId);
        }
    }

    /** Seed (idempotently) the default categories for one organisation, in the given (or active) locale. */
    public static function seedFor(string $organisationId, ?string $locale = null): void
    {
        $locale = ($locale ?? app()->getLocale()) === 'es' ? 'es' : 'en';

        foreach (self::DEFAULTS as $def) {
            ExpenseCategory::query()->firstOrCreate(
                ['organisation_id' => $organisationId, 'name' => $def[$locale]],
                ['default_kind' => $def['kind'], 'active' => true],
            );
        }
    }
}
