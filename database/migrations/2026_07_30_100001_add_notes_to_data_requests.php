<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Free-text handling notes on a subject-rights request (what was done, by whom, any
     * caveat) — evidence the club can point to when showing it answered in time. Additive.
     */
    public function up(): void
    {
        Schema::table('data_requests', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('data_requests', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
