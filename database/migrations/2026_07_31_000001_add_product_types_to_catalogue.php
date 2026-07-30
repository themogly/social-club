<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 21 — cannabis product types (concentrates, edibles, prerolls beyond flower).
 * Purely ADDITIVE to the prompt-01 catalogue: every existing FLOWER genetic, batch,
 * price and dispensation line keeps behaving exactly as before. `product_type`
 * defaults to FLOWER and `unit_type` to WEIGHT, so existing rows are backfilled to
 * the flower/weight shape by the column default. Each column pair (price_per_gram/
 * unit, initial_cg/units, remaining_cg/units) is one-of-two, enforced at the model
 * layer — so the cg/price_per_gram columns are relaxed to nullable to let a UNIT row
 * leave them empty. No existing value is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('genetics', function (Blueprint $table) {
            // product_type drives the derived, stored unit_type (set by GeneticObserver).
            $table->string('product_type')->default('FLOWER');   // FLOWER|CONCENTRATE|PREROLL|EDIBLE
            $table->string('unit_type')->default('WEIGHT');       // WEIGHT|UNIT (derived, never user-entered)
            $table->string('concentrate_subtype')->nullable();    // CONCENTRATE only (descriptive)
            $table->unsignedInteger('grams_per_unit_cg')->nullable(); // UNIT only: gram content of one unit
            $table->unsignedInteger('thc_mg_per_unit')->nullable();   // EDIBLE only
            $table->index('product_type');
        });

        Schema::table('genetic_prices', function (Blueprint $table) {
            $table->bigInteger('price_per_unit_cents')->nullable(); // UNIT genetics price per unit
        });
        // Relax the per-gram rate to nullable so a UNIT price row can leave it empty (one-of-two).
        Schema::table('genetic_prices', function (Blueprint $table) {
            $table->bigInteger('price_per_gram_cents')->nullable()->change();
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->integer('initial_units')->nullable();
            $table->integer('remaining_units')->nullable();
        });
        // Relax the cg columns to nullable (and drop the 0 default) so a UNIT batch
        // holds units, not centigrams (one-of-two).
        Schema::table('batches', function (Blueprint $table) {
            $table->unsignedBigInteger('initial_cg')->nullable()->default(null)->change();
            $table->bigInteger('remaining_cg')->nullable()->default(null)->change();
        });

        Schema::table('dispensation_lines', function (Blueprint $table) {
            $table->integer('units_dispensed')->nullable();          // UNIT lines
            $table->bigInteger('price_per_unit_cents')->nullable();  // frozen per-unit rate (UNIT lines)
            // grams_cg stays NOT NULL and populated on EVERY line (computed for UNIT).
        });
        // Relax the per-gram rate to nullable so a UNIT line can leave it empty (one-of-two);
        // every WEIGHT line still populates it.
        Schema::table('dispensation_lines', function (Blueprint $table) {
            $table->bigInteger('price_per_gram_cents')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('dispensation_lines', function (Blueprint $table) {
            $table->dropColumn(['units_dispensed', 'price_per_unit_cents']);
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn(['initial_units', 'remaining_units']);
        });

        Schema::table('genetic_prices', function (Blueprint $table) {
            $table->dropColumn('price_per_unit_cents');
        });

        Schema::table('genetics', function (Blueprint $table) {
            $table->dropIndex(['product_type']);
            $table->dropColumn(['product_type', 'unit_type', 'concentrate_subtype', 'grams_per_unit_cg', 'thc_mg_per_unit']);
        });
    }
};
