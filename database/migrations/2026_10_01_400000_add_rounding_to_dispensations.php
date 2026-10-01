<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 350 — the rounding applied to a dispensation's discounted total (signed cents: −24 gave 24 cents away, +50
 * took 50 more), so the receipt can show it and the reports can total it. Every existing row is 0: none was rounded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispensations', function (Blueprint $table): void {
            $table->bigInteger('rounding_cents')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('dispensations', function (Blueprint $table): void {
            $table->dropColumn('rounding_cents');
        });
    }
};
