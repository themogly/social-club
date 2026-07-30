<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('member_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tier_id')->constrained('membership_tiers')->cascadeOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();          // null = lifetime
            $table->bigInteger('fee_cents')->default(0);
            $table->foreignUlid('fee_override_by')->nullable()->constrained('users')->nullOnDelete();
            // Derived + persisted by the nightly sweep so it is indexable/filterable.
            $table->string('status')->default('ACTIVE');          // ACTIVE|EXPIRING_SOON|LAPSED|CANCELLED
            $table->timestamps();
            $table->softDeletes();
            $table->index(['member_id', 'status']);
            $table->index(['location_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('membership_fee_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('membership_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('method');                             // CASH|WALLET|BANK|CARD
            $table->foreignUlid('till_session_id')->nullable();   // FK added with till_sessions (migration 000005)
            $table->timestamp('paid_at')->nullable();
            $table->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('instalment_of')->nullable()->constrained('membership_fee_payments')->nullOnDelete();
            $table->timestamps();
            $table->index('membership_id');
        });

        Schema::create('check_ins', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('member_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->timestamp('checked_in_at');
            $table->timestamp('checked_out_at')->nullable();
            $table->boolean('auto_checked_out')->default(false);
            $table->foreignUlid('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method')->default('MANUAL');          // QR|MANUAL
            $table->timestamps();
            $table->index(['location_id', 'checked_in_at']);
            $table->index(['member_id', 'checked_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');
        Schema::dropIfExists('membership_fee_payments');
        Schema::dropIfExists('memberships');
    }
};
