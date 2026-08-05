<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 161 — retire the `organisations.settings` JSON column, the organisation-level twin of
 * `locations.settings` (retired by prompt 59). It is a second, disconnected settings store sitting beside the
 * one mechanism `Settings::get()` actually reads (the `settings` TABLE): declared in the original migration,
 * read by nothing — no model accessor (its cast/relation were removed in the code-style audit), no
 * `Settings::get()` path, no query in `app/`.
 *
 * UNLIKE `locations.settings` — which held five documented booleans that prompt 59 moved into `Setting` rows
 * before dropping — `organisations.settings` has NO documented keys, so there is nothing to map. Therefore this
 * migration does the honest thing: it VERIFIES the dead-column expectation at run time and REFUSES to drop if
 * any row carries content, rather than inventing a `Setting`-key mapping or silently discarding an undocumented
 * blob. At the time of writing the column is empty on every row (confirmed — see DECISIONS). `down()` restores
 * it, nullable, like prompt 59's; every migration here is reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organisations', 'settings')) {
            return;
        }

        // The part people get wrong (prompt 59): check before dropping. There is no key mapping to migrate to,
        // so a non-empty blob is a FINDING, not a chore — fail loudly rather than lose organisation config.
        $carrying = DB::table('organisations')
            ->whereNotNull('settings')
            ->whereNotIn('settings', ['{}', '[]', ''])
            ->count();

        if ($carrying > 0) {
            throw new RuntimeException(
                "organisations.settings carries content in {$carrying} row(s) — investigate and map it "
                .'deliberately before retiring the column. Do NOT invent a Setting-key mapping in this migration.'
            );
        }

        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn('settings');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->json('settings')->nullable();
        });
    }
};
