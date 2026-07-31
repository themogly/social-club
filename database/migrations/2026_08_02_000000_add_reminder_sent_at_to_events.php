<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 56 — a per-event marker so the nightly `events:remind` sweep dispatches the (previously
 * unwired) EventReminderNotification exactly once per event, never re-sending on a retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->timestamp('reminder_sent_at')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
