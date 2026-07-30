<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-channel push opt-outs the member controls (prompt 15). A JSON list of the
 * channel keys the socio has switched OFF — empty/null means opted IN to all. A
 * small JSON column rather than a new table (Member::PUSH_CHANNELS is the catalogue).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->json('push_opt_outs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('push_opt_outs');
        });
    }
};
