<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 87 — an acta recorded THAT it was signed (signed_at) but not by WHOM. Record the signatory.
 * Nullable and never backfilled: actas signed before this column existed have no attributable signatory
 * and must say so honestly ("no consta") rather than have one invented. nullOnDelete so removing a user
 * account never deletes or rewrites the legal record — it just loses the live link, which the acta then
 * reports as unrecorded, consistent with the historical rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minutes', function (Blueprint $table): void {
            $table->foreignUlid('signed_by')->nullable()->after('signed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('minutes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('signed_by');
        });
    }
};
