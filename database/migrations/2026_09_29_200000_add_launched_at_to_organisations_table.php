<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 304 — the one-way launch latch. Null while the club holds test data; set once by `csc:launch` on the day the first
 * real member is served, and from then on `csc:reset-for-launch` and `csc:install --force` refuse for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->timestamp('launched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn('launched_at');
        });
    }
};
