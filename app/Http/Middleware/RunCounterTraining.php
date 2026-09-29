<?php

namespace App\Http\Middleware;

use App\Support\CounterOperator;
use App\Support\TrainingMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prompt 324 — the ONE place *Modo formación* is applied: while it is on, a counter request runs through
 * {@see TrainingMode::run()} (a transaction that is always rolled back, with the non-database side effects switched
 * off). Registered after StartSession (it reads the session) and before the counter guards, so it wraps them and the
 * route alike — every write, including any nobody listed.
 *
 * Training ends here, before the request runs for real, when the counter is no longer the one it started on: another
 * operator or none (a lock, an idle lock, a switch), another sede, or a request that is not the counter's (the panel —
 * *Administración* leaves training first). The panic button is never practice: it ends training and runs for real.
 * An upload is refused outright: a file on disk is not something a rollback can take back.
 */
class RunCounterTraining
{
    public function handle(Request $request, Closure $next): Response
    {
        $state = TrainingMode::state();
        if ($state === null) {
            return $next($request);
        }

        $reason = match (true) {
            CounterOperator::id() !== $state['operator_id'] => 'operator',
            session('counter.location_id') !== $state['location_id'] => 'sede',
            $request->routeIs('counter.panic') => 'panic',
            ! $this->isCounterRequest($request) => 'left_counter',
            default => null,
        };
        if ($reason !== null) {
            TrainingMode::end($reason);

            return $next($request);
        }

        if ($request->routeIs('counter.members.photo', 'livewire.upload-file') || $this->isLivewireUpload($request)) {
            abort(403, __('No disponible en modo formación'));
        }

        return TrainingMode::run(fn (): Response => $next($request));
    }

    /** The counter's own pages and routes, and Livewire requests from a counter page. */
    private function isCounterRequest(Request $request): bool
    {
        if ($request->is('counter', 'counter/*')) {
            return true;
        }

        if ($request->headers->has('X-Livewire')) {
            foreach ((array) $request->input('components', []) as $component) {
                $memo = json_decode((string) ($component['snapshot'] ?? ''), true)['memo'] ?? [];
                if (! str_starts_with((string) ($memo['path'] ?? ''), 'counter')) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function isLivewireUpload(Request $request): bool
    {
        return str_contains($request->path(), 'upload-file');
    }
}
