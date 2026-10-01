<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 347 — a staff account linked to that person's own member record (one record per account), so the club's staff
 * discount can apply by itself and the counter can tell when someone is serving themselves; and the flag on the
 * dispensation that records it. Additive: nullable link, a false-by-default flag; no existing row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUlid('member_id')->nullable()->unique()->constrained('members')->nullOnDelete();
        });

        Schema::table('dispensations', function (Blueprint $table): void {
            $table->boolean('self_dispensed')->default(false);
        });
    }

    public function down(): void
    {
        // The unique index first: SQLite rebuilds the table on a column drop and refuses an index on a missing column.
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['member_id']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('member_id');
        });

        Schema::table('dispensations', function (Blueprint $table): void {
            $table->dropColumn('self_dispensed');
        });
    }
};
