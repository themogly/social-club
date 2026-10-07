<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 355 — the weight a member PAYS for is rounded to the half gram; what left the jar is unchanged. Each weight line
 * stores the grams it was CHARGED for, and the dispensation whether rounding was on, so a later change of the setting
 * never rewrites a past sale. Existing rows: charged = weighed (null reads as "not rounded"), rounding off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispensation_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('charged_cg')->nullable()->after('grams_cg');
        });
        Schema::table('dispensations', function (Blueprint $table): void {
            $table->boolean('charge_rounding')->default(false)->after('rounding_cents');
        });
    }

    public function down(): void
    {
        Schema::table('dispensation_lines', function (Blueprint $table): void {
            $table->dropColumn('charged_cg');
        });
        Schema::table('dispensations', function (Blueprint $table): void {
            $table->dropColumn('charge_rounding');
        });
    }
};
