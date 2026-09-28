<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 277 (Ben's 270) — a central store for the grow, and batches moved (whole or part) between locations.
 *
 *   · locations.kind: SEDE (every existing location — the default) or ALMACEN (the grow / central store: no counter).
 *     The store is a LOCATION, not "no location", so batches.location_id stays NOT NULL and everything that filters
 *     by location (FEFO, ceilings, reports, the recount) keeps working unchanged.
 *   · batches.parent_batch_id: a part-transfer creates a child batch at the destination (same lote number) pointing at
 *     its source, so a dispensed gram can always be traced back to the original harvest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('kind', 16)->default('SEDE')->after('name');
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->foreignUlid('parent_batch_id')->nullable()->after('genetic_id')->constrained('batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_batch_id');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
