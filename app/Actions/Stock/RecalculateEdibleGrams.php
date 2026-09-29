<?php

namespace App\Actions\Stock;

use App\Enums\ProductType;
use App\Models\Genetic;
use App\Support\EdibleEquivalence;
use Illuminate\Support\Facades\DB;

/**
 * Prompt 326 — the club changed `edible_thc_mg_per_gram`: every edible strain with a THC figure counts again, at once,
 * in one transaction. Only what counts FROM NOW: each dispensation line stored its own `grams_cg` when it was recorded,
 * so the registro and past limits are never rewritten. Edibles with no THC figure keep their grams (see Salud del
 * sistema). Returns the strains changed, with their old and new centigrams.
 *
 * @phpstan-type Change array{name: string, old_cg: ?int, new_cg: int}
 */
class RecalculateEdibleGrams
{
    /** @return list<Change> */
    public function handle(?int $mgPerGram = null): array
    {
        return DB::transaction(function () use ($mgPerGram): array {
            $changes = [];
            $edibles = Genetic::query()->withoutGlobalScopes()->withTrashed()
                ->where('product_type', ProductType::EDIBLE->value)->where('thc_mg_per_unit', '>', 0)->lockForUpdate()->get();

            foreach ($edibles as $genetic) {
                $new = EdibleEquivalence::gramsCg((int) $genetic->thc_mg_per_unit, $mgPerGram);
                $old = $genetic->grams_per_unit_cg === null ? null : (int) $genetic->grams_per_unit_cg;
                if ($old !== $new) {
                    // A plain column write: the observer would recompute from the SAVED setting, which may be the old one.
                    Genetic::query()->withoutGlobalScopes()->withTrashed()->whereKey($genetic->id)->update(['grams_per_unit_cg' => $new]);
                    $changes[] = ['name' => (string) $genetic->name, 'old_cg' => $old, 'new_cg' => $new];
                }
            }

            return $changes;
        });
    }
}
