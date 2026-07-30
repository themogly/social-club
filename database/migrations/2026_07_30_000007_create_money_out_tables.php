<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('category_id')->constrained('expense_categories')->cascadeOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('paid_from')->default('TILL_CASH');    // TILL_CASH|BANK|CARD|OTHER
            $table->string('kind')->default('OVERHEAD');          // TILL|OVERHEAD
            $table->foreignUlid('till_session_id')->nullable()->constrained()->nullOnDelete();
            $table->json('recurrence')->nullable();               // template when set
            $table->string('receipt_path')->nullable();
            $table->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->date('incurred_on')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organisation_id', 'incurred_on']);
            $table->index(['location_id', 'kind']);
        });

        // Idempotency marker for the recurring-expense scheduler.
        Schema::create('recurring_expense_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('expense_template_id')->constrained('expenses')->cascadeOnDelete();
            $table->string('period_key');                         // e.g. 2026-07
            $table->foreignUlid('created_expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->timestamps();
            $table->unique(['expense_template_id', 'period_key']);
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('supplier_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_cents');
            $table->bigInteger('paid_cents')->default(0);         // owing = amount - paid
            $table->json('items')->nullable();
            $table->string('invoice_path')->nullable();
            $table->date('purchased_on')->nullable();
            $table->foreignUlid('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organisation_id', 'purchased_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('recurring_expense_runs');
        Schema::dropIfExists('expenses');
    }
};
