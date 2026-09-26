<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 259 — a member may run a tab only up to what the owner approved.
 *
 * ONE figure per member, in cents, checked against the member's TOTAL debt across every sede (the wallet ledger
 * stays per-location; only the limit check reads the sum). Null — every existing member, and the default for
 * every new one — means not approved for any tab. Not mass-assignable: only `SetMemberDebtLimit` writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->unsignedInteger('debt_limit_cents')->nullable()->after('monthly_limit_cg');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('debt_limit_cents');
        });
    }
};
