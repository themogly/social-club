<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('till_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->string('terminal')->nullable();
            $table->foreignUlid('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->bigInteger('float_cents')->default(0);
            $table->foreignUlid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('counted_cents')->nullable();       // blind arqueo count
            $table->bigInteger('expected_cents')->nullable();
            $table->bigInteger('variance_cents')->nullable();
            $table->string('status')->default('OPEN');             // OPEN|CLOSED
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('till_session_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_cents');                    // signed
            $table->string('type');                                // IN|OUT|BANKED|PETTY_CASH
            $table->string('reason')->nullable();
            $table->foreignUlid('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('till_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('till_sessions');
    }
};
