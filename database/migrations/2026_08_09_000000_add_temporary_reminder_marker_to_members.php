<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency marker for the temporary-member expiry reminder (prompt 111). A temporary member is
 * reminded once, `temporary_reminder_lead_days` before their window closes; this timestamp stops the
 * daily sweep re-sending on every subsequent run (the same shape as memberships.reminder_sent_for).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->timestamp('temporary_reminder_sent_at')->nullable()->after('temporary_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('temporary_reminder_sent_at');
        });
    }
};
