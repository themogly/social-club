<?php

namespace App\Support;

/**
 * Handed-over mode: the counter tablet is in the hands of someone who is not a member yet (prompt 173).
 *
 * Session-backed on purpose. It has to be readable by the LAYOUT — which decides whether the counter's
 * chrome renders at all — and by the Livewire component, and it must survive a full page load so the back
 * button cannot return to the counter. A client-side flag could not do any of those.
 *
 * The mode is entered only from the counter, by an identified operator, at a resolved sede. It can never be
 * entered by URL: nothing routes to it, and {@see begin()} is reachable only from a component method that
 * already ran requireOperator().
 *
 * It is NOT a security boundary on its own — `requireOperator()` still refuses every write server-side, and
 * beginning a handover signs the operator out exactly as locking does, so a commit attempted from a stale
 * tab fails the same way it always did.
 */
class CounterHandover
{
    private const KEY = 'counter.handover';

    /** @return array{operator_id: string, location_id: ?string, started_at: string, return_url: ?string}|null */
    public static function current(): ?array
    {
        /** @var array{operator_id: string, location_id: ?string, started_at: string, return_url: ?string}|null $state */
        $state = session(self::KEY);

        return is_array($state) ? $state : null;
    }

    public static function active(): bool
    {
        return self::current() !== null;
    }

    /**
     * Hand the tablet over. The operator is recorded so the audit entry names who did it, and the URL the
     * applicant was sent to is recorded so {@see \App\Http\Middleware\EnforceCounterHandover} can put them
     * back there when they wander off it.
     */
    public static function begin(string $operatorId, ?string $locationId, ?string $returnUrl = null): void
    {
        session([self::KEY => [
            'operator_id' => $operatorId,
            'location_id' => $locationId,
            'started_at' => now()->toIso8601String(),
            'return_url' => $returnUrl,
        ]]);
    }

    /** Where the applicant belongs — the tokenised form they were handed. Null when none was recorded. */
    public static function returnUrl(): ?string
    {
        $url = self::current()['return_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * End it — completed, aborted or timed out. Everything the applicant touched goes with it, which is the
     * "nothing survives the handover" guarantee: the next person handed the tablet must not see a draft, a
     * half-typed document number or an upload preview from the last one.
     */
    public static function end(): void
    {
        session()->forget(self::KEY);
        session()->forget('counter.handover.draft');
    }
}
