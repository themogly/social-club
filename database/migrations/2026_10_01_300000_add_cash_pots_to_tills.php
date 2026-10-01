<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Prompt 349 — the till's cash in three pots (dispensary, bar, fees).
 *  - cash_movements.pot — which pot a manual movement (entrada, salida, ingreso en banco, caja chica) belongs to; every
 *    existing row is the dispensary's, which is where they all went before;
 *  - till_sessions — whether the session keeps pots (a snapshot at opening, so a session's arithmetic never changes
 *    under it), the bar and fees pots' opening (carried forward until counted), and their count/expected/variance at
 *    close (null counted = not counted that night). The existing float/counted/expected/variance are the DISPENSARY
 *    pot's when pots are on, and the whole drawer's when they are off — exactly as today.
 *  - *Botes de efectivo separados* is switched ON for this club's existing sedes (the store has no till).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->string('pot')->default('DISPENSARY');
        });

        Schema::table('till_sessions', function (Blueprint $table): void {
            $table->boolean('separate_pots')->default(false);
            $table->bigInteger('bar_opening_cents')->default(0);
            $table->bigInteger('bar_counted_cents')->nullable();
            $table->bigInteger('bar_expected_cents')->nullable();
            $table->bigInteger('bar_variance_cents')->nullable();
            $table->bigInteger('fees_opening_cents')->default(0);
            $table->bigInteger('fees_counted_cents')->nullable();
            $table->bigInteger('fees_expected_cents')->nullable();
            $table->bigInteger('fees_variance_cents')->nullable();
        });

        $now = now();
        foreach (DB::table('locations')->where('kind', '!=', 'ALMACEN')->get(['id', 'organisation_id']) as $sede) {
            $exists = DB::table('settings')->where('organisation_id', $sede->organisation_id)->where('location_id', $sede->id)->where('key', 'separate_cash_pots')->exists();
            if (! $exists) {
                DB::table('settings')->insert([
                    'id' => (string) Str::ulid(), 'organisation_id' => $sede->organisation_id, 'location_id' => $sede->id,
                    'key' => 'separate_cash_pots', 'value' => '1', 'type' => 'BOOL', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'separate_cash_pots')->delete();

        Schema::table('till_sessions', function (Blueprint $table): void {
            $table->dropColumn(['separate_pots', 'bar_opening_cents', 'bar_counted_cents', 'bar_expected_cents', 'bar_variance_cents',
                'fees_opening_cents', 'fees_counted_cents', 'fees_expected_cents', 'fees_variance_cents']);
        });

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropColumn('pot');
        });
    }
};
