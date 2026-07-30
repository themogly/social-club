<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_tiers', function (Blueprint $table) {
            // Optional per-tier limit overrides (precedence: member → tier → location → org).
            $table->unsignedInteger('daily_limit_cg')->nullable()->after('default_period');
            $table->unsignedInteger('monthly_limit_cg')->nullable()->after('daily_limit_cg');
        });
    }

    public function down(): void
    {
        Schema::table('membership_tiers', function (Blueprint $table) {
            $table->dropColumn(['daily_limit_cg', 'monthly_limit_cg']);
        });
    }
};
