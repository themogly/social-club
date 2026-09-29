<?php

use App\Actions\ResolveLocale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Prompt 315 — one-time: every account without a saved language gets the club's CURRENT default, resolved at migration
 * time the one way (ResolveLocale, no subject; `en` when nothing is set). Accounts that already have a language are not
 * touched. From here on the User `creating` hook fills it for every new person.
 */
return new class extends Migration
{
    public function up(): void
    {
        $locale = (new ResolveLocale)->handle();
        $count = DB::table('users')->whereNull('locale')->orWhere('locale', '')->update(['locale' => $locale]);

        Log::info("Backfilled users.locale: {$count} account(s) set to «{$locale}».");
    }

    public function down(): void
    {
        // One-way: which accounts were empty is not recorded, and a saved language is never taken away.
    }
};
