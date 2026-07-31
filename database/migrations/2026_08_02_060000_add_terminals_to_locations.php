<?php

use App\Support\TerminalName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 84 — configured till terminals per location. The terminal is still the key linking a till session
 * to its POS screens; this makes CHOOSING it a pick, not free text, so a typo can't open a phantom till.
 * Backfills each location's list from the distinct terminals already used in till_sessions, so historical
 * sessions still resolve and staff immediately see their real tills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->json('terminals')->nullable()->after('accent');
        });

        // Seed each location's list from history — distinct terminal strings already in till_sessions.
        foreach (DB::table('locations')->pluck('id') as $locationId) {
            $used = DB::table('till_sessions')
                ->where('location_id', $locationId)
                ->whereNotNull('terminal')
                ->distinct()->pluck('terminal');

            $list = [];
            foreach ($used as $terminal) {
                $list = TerminalName::register($list, (string) $terminal);
            }

            DB::table('locations')->where('id', $locationId)->update(['terminals' => json_encode($list)]);
        }
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn('terminals');
        });
    }
};
