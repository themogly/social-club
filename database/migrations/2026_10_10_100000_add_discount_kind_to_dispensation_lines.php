<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 375 — which discount a line was priced with (LOCAL, CONCESSION, THERAPEUTIC, STAFF, CUSTOM, TIER), written by
 * CommitDispensation from now on so the reports can split member discounts by kind. No backfill: an older line stays null
 * («Sin clasificar») — the member's discount today may not be the one they had then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispensation_lines', function (Blueprint $table): void {
            $table->string('discount_kind', 20)->nullable()->after('discount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('dispensation_lines', function (Blueprint $table): void {
            $table->dropColumn('discount_kind');
        });
    }
};
