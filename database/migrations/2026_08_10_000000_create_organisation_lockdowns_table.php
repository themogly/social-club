<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 121 — the org-wide panic lockdown. An APPEND-ONLY history: one row per lockdown event, closed by
 * `reactivated_at`. The org is locked NOW iff it has a row with `reactivated_at` still null (the Action enforces
 * one open lockdown per org). Keeping the history — not a boolean on `organisations` — is what lets the club
 * evidence "convened correctly, locked at HH:MM by whom, reactivated by which path", which is the whole point of
 * a feature that a court might look at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_lockdowns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();

            $table->timestamp('locked_at');
            $table->foreignUlid('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_drill')->default(false);   // a rehearsal — exercises the flow, reversible on-site
            $table->string('reason')->nullable();          // free text; never sensitive detail

            $table->timestamp('reactivated_at')->nullable();
            $table->foreignUlid('reactivated_by')->nullable()->constrained('users')->nullOnDelete();
            // owner_link | auto_delay | break_glass | drill_ended — how the club got back in (audited).
            $table->string('reactivation_method')->nullable();

            $table->timestamps();

            // The hot query is "is THIS org locked right now" → open lockdowns for an org.
            $table->index(['organisation_id', 'reactivated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_lockdowns');
    }
};
