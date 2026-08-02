<?php

namespace App\Http\Controllers;

use App\Actions\Lockdown\InitiateLockdown;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Prompt 121 — the counter panic trigger. Staff are the ones in the room during a robbery, so the counter can
 * trip the org-wide lockdown; gated on `lockdown.initiate` (which STAFF hold). After tripping it the response is
 * the ordinary "unavailable" screen the gate now serves — no confirmation, nothing that announces what happened.
 */
class CounterPanicController extends Controller
{
    public function store(Request $request, InitiateLockdown $initiate): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->can('lockdown.initiate'), 403);

        $organisationId = app(ActiveScope::class)->organisationId();
        $organisation = $organisationId !== null
            ? Organisation::query()->withoutGlobalScopes()->find($organisationId)
            : null;
        abort_if($organisation === null, 404);

        $initiate->handle($organisation, ['actor' => $user, 'reason' => $request->string('reason')->trim()->toString() ?: null]);

        // Back to wherever they were — the lockdown gate now serves the ordinary unavailable page.
        return redirect()->back();
    }
}
