<?php

use App\Support\BatchPriceBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 278 (Ben's 271) — the sale price and the photos live on the BATCH: two harvests of one strain differ in quality,
 * cost and look, and the club prices and shows the batch it actually has.
 *
 *   · batches: price_per_gram_cents (weight) / price_per_unit_cents (unit) / price_per_eighth_cents (the 3.5 g price,
 *     weight only — prompt 83's break carries on per batch), and images (json list of public-disk paths).
 *   · membership_tiers.discount_bp — a tier's price becomes a percentage discount on any batch (owner decision 1a).
 *   · Existing batches get their genetic's base sede price; the unpriced are logged (BatchPriceBackfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->unsignedInteger('price_per_gram_cents')->nullable()->after('cost_per_gram_cents');
            $table->unsignedInteger('price_per_unit_cents')->nullable()->after('price_per_gram_cents');
            $table->unsignedInteger('price_per_eighth_cents')->nullable()->after('price_per_unit_cents');
            $table->json('images')->nullable()->after('lab_report_path');
        });

        Schema::table('membership_tiers', function (Blueprint $table) {
            $table->unsignedInteger('discount_bp')->default(0)->after('monthly_limit_cg');
        });

        BatchPriceBackfill::run();
    }

    public function down(): void
    {
        Schema::table('membership_tiers', function (Blueprint $table) {
            $table->dropColumn('discount_bp');
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn(['price_per_gram_cents', 'price_per_unit_cents', 'price_per_eighth_cents', 'images']);
        });
    }
};
