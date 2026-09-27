<?php

namespace App\Http\Middleware;

use App\Support\Period;
use Closure;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every date and time in the admin panel reads in the sede's timezone (prompt 271).
 *
 * Storage is UTC. Nothing converted at display, so a Madrid sede's tables, infolists and date pickers showed times one
 * or two hours early — including the audit log and the member register. Filament's global display timezone does the
 * conversion for every column, entry and picker at once (pickers also convert what is typed back to UTC). Persistent,
 * so a Livewire update renders in the same zone as the page. Degrades to the app timezone rather than throwing.
 */
class SetDisplayTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            FilamentTimezone::set(Period::displayTimezone());
        } catch (\Throwable) {
            // A scope/DB hiccup must never break a page over a display nicety — the app timezone stays.
        }

        return $next($request);
    }
}
