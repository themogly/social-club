<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 64 — a permissioned, reasoned price override at the dispensary counter (comping mouldy product,
 * a €0 give-away). `total_cents` stays the CHARGED (overridden) amount so the tender invariant still holds;
 * `original_total_cents` records the RESOLVED price it would otherwise have been (null = no override), so
 * every override is fully reconstructable, attributed and reportable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispensations', function (Blueprint $table): void {
            $table->unsignedBigInteger('original_total_cents')->nullable()->after('total_cents');
            $table->text('price_override_reason')->nullable()->after('original_total_cents');
            $table->foreignUlid('price_override_by')->nullable()->after('price_override_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dispensations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('price_override_by');
            $table->dropColumn(['original_total_cents', 'price_override_reason']);
        });
    }
};
