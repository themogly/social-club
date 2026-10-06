<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 359 — sealed top-up bags are a reserve inside the batch, off the counter: `reserve_cg` beside `remaining_cg`
 * (the jar). Each stock movement says which figure it touched (`on_reserve`), and a stock-take line can be the reserve's
 * own («Reserva sellada», the full inventory) and can record a forgotten «Rellenar» the close count absorbed.
 * Existing batches: no reserve (0), every past movement the jar's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table): void {
            $table->unsignedBigInteger('reserve_cg')->default(0)->after('remaining_cg');
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->boolean('on_reserve')->default(false)->after('qty_units');
        });
        Schema::table('stock_take_lines', function (Blueprint $table): void {
            $table->boolean('reserve')->default(false)->after('countable_id');
            $table->unsignedBigInteger('unrecorded_topup_cg')->nullable()->after('variance_units');
        });
    }

    public function down(): void
    {
        Schema::table('stock_take_lines', function (Blueprint $table): void {
            $table->dropColumn(['reserve', 'unrecorded_topup_cg']);
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropColumn('on_reserve');
        });
        Schema::table('batches', function (Blueprint $table): void {
            $table->dropColumn('reserve_cg');
        });
    }
};
