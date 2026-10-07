<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 360 — an *Inventario* row counts a weight batch's JAR and its SEALED RESERVE side by side (each may be left blank:
 * untouched), and a count may list batches the old reweigh zeroed (`optional`: an untouched one does not hold up «Aplicar
 * ajustes»). The end-of-day weigh's single reason, asked only when the count is off, is stored on the take.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_take_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('expected_reserve_cg')->nullable()->after('expected_cg');
            $table->unsignedBigInteger('counted_reserve_cg')->nullable()->after('counted_cg');
            $table->bigInteger('variance_reserve_cg')->nullable()->after('variance_cg');
            $table->boolean('optional')->default(false)->after('countable_id');
        });
        Schema::table('stock_takes', function (Blueprint $table): void {
            $table->string('reason', 255)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('stock_takes', function (Blueprint $table): void {
            $table->dropColumn('reason');
        });
        Schema::table('stock_take_lines', function (Blueprint $table): void {
            $table->dropColumn(['expected_reserve_cg', 'counted_reserve_cg', 'variance_reserve_cg', 'optional']);
        });
    }
};
