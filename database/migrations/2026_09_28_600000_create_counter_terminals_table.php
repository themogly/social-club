<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 289 — a tablet registered as a counter. Only the token's SHA-256 is stored, never the token; the tablet holds
 * id + token in an encrypted, HttpOnly, Secure cookie. No IP address — operational data about a club device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counter_terminals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained();
            $table->foreignUlid('location_id')->constrained();
            $table->string('name', 40);
            $table->string('token_hash', 64);
            $table->foreignUlid('registered_by')->constrained('users');
            $table->timestamp('registered_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUlid('revoked_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['organisation_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counter_terminals');
    }
};
