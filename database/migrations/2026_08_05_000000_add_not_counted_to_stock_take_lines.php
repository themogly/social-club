<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 91 — an EOD flower reweigh could not be closed if a single jar could not be weighed (mislaid, in
 * someone's hand, off the shelf). Add an explicit "not counted" state per line: the count still closes, that
 * batch's stock is left UNTOUCHED (no adjustment, no merma), and the omission is recorded with its reason so
 * a manager can follow it up. Distinct from a real count of zero — a 0 g line is still `counted_cg = 0`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_take_lines', function (Blueprint $table): void {
            $table->boolean('not_counted')->default(false)->after('variance_units');
            $table->string('not_counted_reason')->nullable()->after('not_counted');
        });
    }

    public function down(): void
    {
        Schema::table('stock_take_lines', function (Blueprint $table): void {
            $table->dropColumn(['not_counted', 'not_counted_reason']);
        });
    }
};
