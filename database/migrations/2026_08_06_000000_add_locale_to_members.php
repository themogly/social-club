<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 96 — a member had nowhere to store a UI-language preference, so the PWA rendered in the
 * organisation default (shipped `en`) for every Spanish member with no way to change it. Give members the
 * same nullable `locale` the users table already has; null = fall through to the org default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->string('locale')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
