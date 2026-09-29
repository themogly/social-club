<?php

use App\Support\EdibleEquivalence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Prompt 326 — edibles count by their THC from now on. Every edible strain WITH a THC figure has its `grams_per_unit_cg`
 * worked out from it at the default equivalence (150 mg per gram); each change is printed (name, old → new). Edibles
 * WITHOUT a figure keep their stored grams and are listed on Salud del sistema until someone gives them one (their edit
 * form requires it). Dispensations already recorded are untouched: each line stored its own grams.
 */
return new class extends Migration
{
    public function up(): void
    {
        $edibles = DB::table('genetics')->where('product_type', 'EDIBLE')->where('thc_mg_per_unit', '>', 0)
            ->get(['id', 'name', 'thc_mg_per_unit', 'grams_per_unit_cg']);

        foreach ($edibles as $edible) {
            $new = EdibleEquivalence::gramsCg((int) $edible->thc_mg_per_unit, EdibleEquivalence::DEFAULT_MG_PER_GRAM);
            if ((int) $edible->grams_per_unit_cg === $new) {
                continue;
            }
            DB::table('genetics')->where('id', $edible->id)->update(['grams_per_unit_cg' => $new]);
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                echo sprintf("  edible %s: %s cg → %d cg (%d mg THC)\n", $edible->name, $edible->grams_per_unit_cg ?? '—', $new, $edible->thc_mg_per_unit);
            }
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            $missing = DB::table('genetics')->where('product_type', 'EDIBLE')->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('thc_mg_per_unit')->orWhere('thc_mg_per_unit', '<=', 0))->pluck('name');
            foreach ($missing as $name) {
                echo "  edible {$name}: no THC figure — grams kept, listed on Salud del sistema\n";
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo safely: the previous grams were typed by hand and are not kept.
    }
};
