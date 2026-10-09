<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 378 — the shop gets its own cash box, apart from the bar (Ben: "Bar and shop needs to be separate as well").
 *
 *  - articles.sold_at — 'BAR' | 'SHOP'; every existing product is the bar's until the owner marks the shop ones;
 *  - orders.shop_cash_cents — the cash that paid for the shop items, fixed at commit (cash to them first, up to their total);
 *    existing orders are 0, which is exactly «Con la barra»: their cash stays where the bar's went;
 *  - till_sessions.shop_box — the shop's choice snapshotted at opening ('with_bar' | 'till' | 'own'); null for a session from
 *    before 378, read as 'with_bar' (today's behaviour);
 *  - till_sessions.shop_* — the shop box, mirroring bar_*, fees_*, edibles_* (signed, prompt 370's rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->string('sold_at', 10)->default('BAR');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->bigInteger('shop_cash_cents')->default(0)->after('wallet_cents');
        });
        Schema::table('till_sessions', function (Blueprint $table): void {
            $table->string('shop_box', 10)->nullable()->after('own_boxes');
            $table->bigInteger('shop_opening_cents')->default(0);
            $table->bigInteger('shop_counted_cents')->nullable();
            $table->bigInteger('shop_expected_cents')->nullable();
            $table->bigInteger('shop_variance_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('till_sessions', function (Blueprint $table): void {
            $table->dropColumn(['shop_box', 'shop_opening_cents', 'shop_counted_cents', 'shop_expected_cents', 'shop_variance_cents']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('shop_cash_cents');
        });
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn('sold_at');
        });
    }
};
