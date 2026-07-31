<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 31 — temporary / short-stay members. A member-kind distinction and an
 * auto-expiry date. Additive: every existing member backfills to STANDARD with a null
 * expiry, unaffected. Temporary status changes list visibility + retention timing ONLY;
 * it never loosens any compliance check (age, avalador, carencia, gram limits).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->string('kind')->default('STANDARD')->after('status');   // STANDARD | TEMPORARY
            $table->timestamp('temporary_expires_at')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'temporary_expires_at']);
        });
    }
};
