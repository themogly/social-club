<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            // Idempotency marker for the expiry-reminder sweep: the period key (the
            // expiry date) a reminder was last sent for. Send only if it differs.
            $table->string('reminder_sent_for')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_for');
        });
    }
};
