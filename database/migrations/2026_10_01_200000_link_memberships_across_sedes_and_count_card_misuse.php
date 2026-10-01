<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 348 — a member enrolled at every club: the memberships at the other sedes point at the "home" one (where the
 * fee was charged) and carry no fee of their own; and a count of the times staff said a scanned card was being used by
 * someone else. Additive: a nullable link and a zero counter; no existing row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->foreignUlid('covered_by_id')->nullable()->constrained('memberships')->nullOnDelete();
        });

        Schema::table('members', function (Blueprint $table): void {
            $table->unsignedInteger('card_misuse_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('card_misuse_count');
        });

        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('covered_by_id');
        });
    }
};
