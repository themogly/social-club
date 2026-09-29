<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 318 — *Inventario*, the full stock count, on the EXISTING stock_takes / stock_take_lines (no second model):
 * a `kind` tells a full count from the till's closing recount (every existing row is the till's), a count can be
 * cancelled, and each line keeps who counted it and when — the moment its expected quantity was snapshotted — plus the
 * reason for a difference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_takes', function (Blueprint $table) {
            $table->string('kind', 20)->default('till_recount')->after('location_id');
            $table->foreignUlid('cancelled_by')->nullable()->after('committed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->index(['location_id', 'kind', 'status']);
        });

        Schema::table('stock_take_lines', function (Blueprint $table) {
            $table->foreignUlid('counted_by')->nullable()->after('variance_units')->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable()->after('counted_by');
            $table->string('adjustment_reason', 30)->nullable()->after('not_counted_reason');
            $table->text('adjustment_note')->nullable()->after('adjustment_reason');
        });
    }

    public function down(): void
    {
        Schema::table('stock_take_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('counted_by');
            $table->dropColumn(['counted_at', 'adjustment_reason', 'adjustment_note']);
        });
        Schema::table('stock_takes', function (Blueprint $table) {
            $table->dropIndex(['location_id', 'kind', 'status']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['kind', 'cancelled_at']);
        });
    }
};
