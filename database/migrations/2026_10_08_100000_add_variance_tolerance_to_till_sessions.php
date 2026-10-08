<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 366 — a close is never refused for a difference any more, so whether it was BEYOND the tolerance is what the
 * owner's report reads. The tolerance in force at the close is kept on the session: changing the setting later must not
 * turn last month's closes into unexplained ones (or back). Older closes have none and fall back to the current setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('till_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('variance_tolerance_cents')->nullable()->after('variance_cents');
        });
    }

    public function down(): void
    {
        Schema::table('till_sessions', function (Blueprint $table): void {
            $table->dropColumn('variance_tolerance_cents');
        });
    }
};
