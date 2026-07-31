<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 83 — eighth (3.5 g) quantity-break pricing. An optional per-strain, per-location eighth price
 * lives on GeneticPrice (a RATE, plain int cents, like price_per_gram_cents — NOT a MoneyCast amount).
 * Null = this strain has no eighth price and is always per-gram. The resolver groups basket lines by this
 * price and charges 3.5 g at it (even split across strains). dispensation_lines carries a `pricing_note`
 * so an eighth-priced line is EVIDENCED on the frozen snapshot and survives onto the receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('genetic_prices', function (Blueprint $table): void {
            $table->bigInteger('price_per_eighth_cents')->nullable()->after('price_per_gram_cents'); // WEIGHT genetics only
        });

        Schema::table('dispensation_lines', function (Blueprint $table): void {
            $table->string('pricing_note')->nullable()->after('line_total_cents'); // e.g. "1/8" when an eighth applied
        });
    }

    public function down(): void
    {
        Schema::table('genetic_prices', function (Blueprint $table): void {
            $table->dropColumn('price_per_eighth_cents');
        });

        Schema::table('dispensation_lines', function (Blueprint $table): void {
            $table->dropColumn('pricing_note');
        });
    }
};
