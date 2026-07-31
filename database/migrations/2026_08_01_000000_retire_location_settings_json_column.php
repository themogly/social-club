<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Prompt 59 — retire the `locations.settings` JSON column, the second (disconnected) per-location
 * settings store. Its values move into location-scoped `Setting` rows — the ONE mechanism
 * `Settings::get()` reads — so no location silently loses a flag (ring_fenced above all). Only the
 * five booleans (bar_enabled / signature_on_dispensation / restrict_pos_to_checked_in /
 * camera_scan_enabled / ring_fenced) ever lived here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('locations', 'settings')) {
            return;
        }

        $now = now();

        foreach (DB::table('locations')->whereNotNull('settings')->get() as $location) {
            $settings = json_decode((string) $location->settings, true);

            if (! is_array($settings)) {
                continue;
            }

            foreach ($settings as $key => $value) {
                $exists = DB::table('settings')
                    ->where('organisation_id', $location->organisation_id)
                    ->where('location_id', $location->id)
                    ->where('key', $key)
                    ->exists();

                if ($exists) {
                    continue; // never clobber an override already written the new way
                }

                DB::table('settings')->insert([
                    'id' => (string) Str::ulid(),
                    'organisation_id' => $location->organisation_id,
                    'location_id' => $location->id,
                    'key' => $key,
                    'value' => $value ? '1' : '0',
                    'type' => 'BOOL',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn('settings');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->json('settings')->nullable();
        });
    }
};
