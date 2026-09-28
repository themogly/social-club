<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 281 (Ben's 280) — Registro de jornada. ONE append-only event log of clock-ins and clock-outs, confirmed by the
 * person's own PIN. Nothing is ever updated or deleted: a correction is a new row pointing at what it corrects. Periods
 * are derived (App\Support\WorkedHours), never stored. `user_id` RESTRICTS deletion — hours outlive the person leaving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_clock_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->string('type', 8);            // IN | OUT | ANNUL
            $table->timestamp('occurred_at');     // when it happened (UTC)
            $table->timestamp('recorded_at');     // when the row was written — later than occurred_at for a correction
            $table->date('business_date');        // BusinessDay::date($location, $occurred_at) — never inline
            $table->string('source', 24);         // PIN | TILL_CLOSE | SELF_DECLARED | MANAGER_CORRECTION
            $table->foreignUlid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('corrects_event_id')->nullable()->constrained('staff_clock_events')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'user_id', 'occurred_at']);
            $table->index(['location_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_clock_events');
    }
};
