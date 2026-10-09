<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 380 — which of the session's own boxes the sede counts «Cada noche», snapshotted at opening beside `own_boxes`, so a
 * box left uncounted is noted against the choice in force that night and a later settings change never rewrites the past.
 * Null for a session from before 380: nothing to note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('till_sessions', function (Blueprint $table): void {
            $table->json('nightly_boxes')->nullable()->after('own_boxes');
        });
    }

    public function down(): void
    {
        Schema::table('till_sessions', function (Blueprint $table): void {
            $table->dropColumn('nightly_boxes');
        });
    }
};
