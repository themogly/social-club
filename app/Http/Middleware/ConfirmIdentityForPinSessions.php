<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Auth\ConfirmIdentity;
use App\Models\User;
use App\Support\PanelIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Post-296 audit, finding 7 — a PIN session reaches a panel page only after "Confirma tu identidad", once a shift per
 * tablet. Persistent, so a Livewire update on a panel page is held to it as well (refused, not redirected). The
 * confirmation page itself, and signing out, always pass.
 */
class ConfirmIdentityForPinSessions
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! PanelIdentity::mustConfirm($user) || $this->alwaysAllowed($request)) {
            return $next($request);
        }

        abort_if($request->hasHeader('X-Livewire'), 403);

        session()->put('url.intended', $request->fullUrl());

        return redirect()->to(ConfirmIdentity::getUrl());
    }

    private function alwaysAllowed(Request $request): bool
    {
        if ($request->routeIs(ConfirmIdentity::getRouteName(), 'filament.admin.auth.logout')) {
            return true;
        }

        // A Livewire update FROM the confirmation page (its form). Filament registers its pages under their class name, and
        // the snapshot is checksummed, so the name is trustworthy.
        $components = $request->input('components', []);

        return is_array($components) && $components !== [] && collect($components)->every(
            fn ($component): bool => is_array($component)
                && (json_decode((string) ($component['snapshot'] ?? ''), true)['memo']['name'] ?? null) === ConfirmIdentity::class
        );
    }
}
