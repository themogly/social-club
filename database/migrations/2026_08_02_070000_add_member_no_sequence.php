<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 77 — a durable, monotonic per-organisation member-number sequence. `MemberNumber::next()` used
 * COUNT(*) + 1, which (a) races (concurrent enrolments collide on the unique index → 500s) and (b) REISSUES
 * a number after a retention purge or soft-delete removes rows. A high-water-mark counter, allocated under a
 * row lock, fixes both: it only ever increases. Backfilled to the MAX numeric member_no already issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->unsignedInteger('member_no_sequence')->default(0)->after('name');
        });

        // Seed each org's high-water mark from the highest member number ALREADY issued (incl. soft-deleted).
        foreach (DB::table('organisations')->pluck('id') as $orgId) {
            $max = 0;
            $numbers = DB::table('members')->where('organisation_id', $orgId)->pluck('member_no');
            foreach ($numbers as $memberNo) {
                $digits = (int) preg_replace('/\D/', '', (string) $memberNo);
                $max = max($max, $digits);
            }
            DB::table('organisations')->where('id', $orgId)->update(['member_no_sequence' => $max]);
        }
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn('member_no_sequence');
        });
    }
};
