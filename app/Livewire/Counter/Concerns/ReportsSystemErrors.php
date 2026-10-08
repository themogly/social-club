<?php

namespace App\Livewire\Counter\Concerns;

use Throwable;

/**
 * Prompt 366 — the counter's one way to say "this failed because of the system, not because of you". The actions refuse
 * ordinary things with a plain RuntimeException (no stock, a reason missing), so a screen catches those and says them; a
 * DATABASE error is a RuntimeException too, and a broad catch used to dress it up as one of those refusals — Shane's till
 * that would not close "even with a note in the box" was a failure shown as «Hace falta una nota». So: catch
 * `PDOException` FIRST — a `QueryException`, and the `DeadlockException` a transaction re-throws for "database is locked"
 * — report it to Sentry, and say so plainly.
 *
 * The host provides `flash(string $message, string $type)`.
 */
trait ReportsSystemErrors
{
    protected function systemError(Throwable $e, ?string $message = null): void
    {
        report($e);
        $this->flash($message ?? __('No se pudo completar por un error del sistema. Ya está avisado. Inténtalo de nuevo o avisa al responsable.'), 'error');
    }
}
