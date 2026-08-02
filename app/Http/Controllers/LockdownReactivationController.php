<?php

namespace App\Http\Controllers;

use App\Actions\Lockdown\ReactivateOrganisation;
use App\Models\LockdownReactivationToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Prompt 121 — where an owner's emailed reactivation link lands. Token-based, so it works WITHOUT logging into
 * the (locked) panel and from the owner's own device, off the terminal. Single-use, consumed under a row lock so
 * a replayed link is refused. This is the "owner trust" way back; the time-delay and the break-glass CLI are the
 * others.
 */
class LockdownReactivationController extends Controller
{
    public function reactivate(Request $request, string $token): View
    {
        $consumed = DB::transaction(function () use ($token): bool {
            $record = LockdownReactivationToken::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($record === null || ! $record->isUsable()) {
                return false;
            }

            $record->update(['used_at' => now()]);

            $lockdown = $record->lockdown()->withoutGlobalScopes()->first();
            if ($lockdown !== null && $lockdown->isOpen()) {
                (new ReactivateOrganisation)->handle($lockdown, 'owner_link', $record->user);
            }

            return true;
        });

        return view('lockdown.reactivated', ['ok' => $consumed]);
    }
}
