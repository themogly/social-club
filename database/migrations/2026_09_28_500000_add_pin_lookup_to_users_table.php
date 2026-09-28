<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 286 — a PIN is found by ONE indexed lookup of a keyed HMAC (App\Support\PinLookup), not by bcrypt-checking every
 * person at the sede. Unique: two people can never hold the same PIN again (users carry no organisation_id — one club
 * per install — so the index is on the lookup alone). Legacy bcrypt PINs in `pin` upgrade lazily on first use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('pin_lookup', 64)->nullable()->unique()->after('pin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['pin_lookup']);
            $table->dropColumn('pin_lookup');
        });
    }
};
