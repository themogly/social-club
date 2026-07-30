<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Basket header — a multi-line withdrawal is one document, one payment, one void.
        Schema::create('dispensations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('member_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('till_session_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('total_cents')->default(0);
            // The tender split — till reconciliation is derived from these, never inferred.
            $table->bigInteger('cash_cents')->default(0);
            $table->bigInteger('wallet_cents')->default(0);
            $table->string('status')->default('COMPLETED');        // COMPLETED|VOIDED|CORRECTED
            $table->foreignUlid('reversal_of_id')->nullable()->constrained('dispensations')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->foreignUlid('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('reference')->nullable();
            $table->timestamp('dispensed_at')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'status']);
            $table->index(['location_id', 'status', 'dispensed_at']);
        });

        Schema::create('dispensation_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('dispensation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('genetic_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('batch_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('grams_cg');
            $table->bigInteger('price_per_gram_cents');            // frozen
            $table->bigInteger('discount_cents')->default(0);      // frozen
            $table->bigInteger('line_total_cents');
            $table->string('genetic_name_snapshot')->nullable();
            $table->string('batch_no_snapshot')->nullable();
            $table->timestamps();
            $table->index('dispensation_id');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('till_session_id')->nullable()->constrained()->nullOnDelete();
            $table->json('items');                                 // snapshot
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('cash_cents')->default(0);
            $table->bigInteger('wallet_cents')->default(0);
            $table->string('status')->default('COMPLETED');        // COMPLETED|VOIDED
            $table->foreignUlid('reversal_of_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->foreignUlid('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('reference')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'status']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('member_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();  // per-location balances (checkpoint)
            $table->bigInteger('amount_cents');                    // signed
            $table->string('type');                                // TOPUP|CONTRIBUTION|FEE|REFUND|ADJUSTMENT|TRANSFER_IN|TRANSFER_OUT
            $table->bigInteger('balance_after_cents');
            $table->foreignUlid('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('till_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->nullableUlidMorphs('source');                  // Dispensation | Order | MembershipFeePayment | ...
            $table->foreignUlid('transfer_pair_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamps();
            $table->index(['member_id', 'location_id']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('dispensation_lines');
        Schema::dropIfExists('dispensations');
    }
};
