<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 65 — partial + cash refunds. A refund NEVER mutates the original dispensation (that stays
 * COMPLETED, append-only); it is a fresh, linked record. Many refunds may point at one dispensation
 * (partial, repeated) but their cumulative amount/weight can never exceed the original — enforced
 * server-side under a lock in RefundDispensation, not here. Money is cents, weight is centigrams; a
 * cash refund carries the till session it was paid out of so the arqueo reconciles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('dispensation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('member_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_cents')->default(0);        // money returned to the member
            $table->bigInteger('grams_cg')->default(0);            // weight of product returned (0 = money-only)
            $table->string('destination');                         // STOCK | MERMA (RefundDestination)
            $table->string('method');                              // WALLET | CASH (RefundMethod)
            $table->foreignUlid('batch_id')->nullable()->constrained()->nullOnDelete();      // where grams were returned
            $table->foreignUlid('till_session_id')->nullable()->constrained()->nullOnDelete(); // cash refunds only
            $table->text('reason');
            $table->foreignUlid('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['dispensation_id']);
            $table->index(['location_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
