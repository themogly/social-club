<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use App\Support\LocationSwitcher;
use App\Support\PanelRefusal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Post-296 audit, Phase 1 finding 1 — on every panel request (page loads AND Livewire updates: it is persistent), the
 * signed-in user is on a location they may use. Only the owner may be in the "all locations" rollup, where
 * `LocationScope` adds no filter; a non-owner who reaches several locations (their sede and the Almacén, since 277)
 * used to start there and see every sede. Someone with no location at all has nothing to look at.
 */
class EnsureActiveLocation
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $switcher = app(LocationSwitcher::class);
            $switcher->enforce($user);

            // Prompt 310 — to a page that says so (with the way back to the counter), for a page load or a button press.
            if ($switcher->current() === null && ! $user->hasRole(Role::OWNER->value)) {
                return PanelRefusal::to($request, 'no-location', route('panel.no-location'));
            }
        }

        return $next($request);
    }
}
