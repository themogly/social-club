<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Prompt 373 — "Where the cash goes". A sede chooses, per kind of money, the till or its own box: edibles, bar & shop, fees
 * (the dispensary is always the till). Replaces 349's all-or-nothing *Botes de efectivo separados*.
 *
 *  - dispensations.edibles_cash_cents — the cash that paid for the edibles, fixed at commit (the edibles' line totals, capped
 *    at the cash); existing rows are 0: no edibles box existed before;
 *  - till_sessions.own_boxes — the session's boxes, snapshotted at opening; existing rows backfilled from separate_pots;
 *  - till_sessions.edibles_* — the edibles box, mirroring bar_* and fees_*;
 *  - settings: separate_cash_pots = 1 → cash_box_bar and cash_box_fees 'own' (exactly today's behaviour), then the old key
 *    is deleted so there is one source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispensations', function (Blueprint $table): void {
            $table->bigInteger('edibles_cash_cents')->default(0)->after('wallet_cents');
        });

        Schema::table('till_sessions', function (Blueprint $table): void {
            $table->json('own_boxes')->nullable()->after('separate_pots');
            $table->bigInteger('edibles_opening_cents')->default(0);
            $table->bigInteger('edibles_counted_cents')->nullable();
            $table->bigInteger('edibles_expected_cents')->nullable();
            $table->bigInteger('edibles_variance_cents')->nullable();
        });

        $this->moveData();
    }

    /** The data half, on its own so a test can run it against seeded rows. */
    public function moveData(): void
    {
        DB::table('till_sessions')->whereNull('own_boxes')->where('separate_pots', true)->update(['own_boxes' => json_encode(['BAR', 'FEES'])]);
        DB::table('till_sessions')->whereNull('own_boxes')->update(['own_boxes' => json_encode([])]);

        $now = now();
        foreach (DB::table('settings')->where('key', 'separate_cash_pots')->get() as $row) {
            if (! in_array(strtolower(trim((string) $row->value)), ['1', 'true'], true)) {
                continue;
            }
            foreach (['cash_box_bar', 'cash_box_fees'] as $key) {
                $exists = DB::table('settings')->where('organisation_id', $row->organisation_id)->where('location_id', $row->location_id)->where('key', $key)->exists();
                if (! $exists) {
                    DB::table('settings')->insert([
                        'id' => (string) Str::ulid(), 'organisation_id' => $row->organisation_id, 'location_id' => $row->location_id,
                        'key' => $key, 'value' => 'own', 'type' => 'STRING', 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
        DB::table('settings')->where('key', 'separate_cash_pots')->delete();
    }

    public function down(): void
    {
        $now = now();
        foreach (DB::table('settings')->where('key', 'cash_box_bar')->where('value', 'own')->get() as $row) {
            DB::table('settings')->insert([
                'id' => (string) Str::ulid(), 'organisation_id' => $row->organisation_id, 'location_id' => $row->location_id,
                'key' => 'separate_cash_pots', 'value' => '1', 'type' => 'BOOL', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('settings')->whereIn('key', ['cash_box_edibles', 'cash_box_bar', 'cash_box_fees', 'count_edibles_nightly'])->delete();

        Schema::table('till_sessions', function (Blueprint $table): void {
            $table->dropColumn(['own_boxes', 'edibles_opening_cents', 'edibles_counted_cents', 'edibles_expected_cents', 'edibles_variance_cents']);
        });
        Schema::table('dispensations', function (Blueprint $table): void {
            $table->dropColumn('edibles_cash_cents');
        });
    }
};
