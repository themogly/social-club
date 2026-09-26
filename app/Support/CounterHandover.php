<?php

namespace App\Support;

use App\Http\Middleware\EnforceCounterHandover;

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

    /** @return array{operator_id: string, location_id: ?string, started_at: string, return_url: ?string, submitted_application_id?: ?string}|null */
    public static function current(): ?array
    {
        /** @var array{operator_id: string, location_id: ?string, started_at: string, return_url: ?string, submitted_application_id?: ?string}|null $state */
        $state = session(self::KEY);

        return is_array($state) ? $state : null;
    }

    public static function active(): bool
    {
        return self::current() !== null;
    }

    /**
     * Hand the tablet over. The operator is recorded so the audit entry names who did it, and the URL the
     * applicant was sent to is recorded so {@see EnforceCounterHandover} can put them
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

    /**
     * Where the applicant belongs — the tokenised form they were handed. Null when none was recorded, AND null
     * once the form has been submitted (prompt 249): a finished form is nowhere to send anyone back to, so the
     * boundary's fallback then lands strays on the counter's PIN pad and the surface's "Continuar con mi
     * solicitud" disappears by itself.
     */
    public static function returnUrl(): ?string
    {
        if (self::submitted()) {
            return null;
        }

        $url = self::current()['return_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Record that the handed-over form was submitted (prompt 249). Called from the applicant controller once
     * `SubmitApplication` has succeeded AND the active handover's form is the one that was submitted — never on
     * the spam-dropped path, never for an emailed invite that shares nothing with the counter. The id is what
     * the PIN then carries the operator to: the review of the application they just watched being filled.
     */
    public static function markSubmitted(string $applicationId): void
    {
        $state = self::current();

        if ($state === null) {
            return;
        }

        $state['submitted_application_id'] = $applicationId;
        session([self::KEY => $state]);
    }

    public static function submitted(): bool
    {
        return self::submittedApplicationId() !== null;
    }

    /** The application the handed-over form submitted, or null while it is still being filled. */
    public static function submittedApplicationId(): ?string
    {
        $id = self::current()['submitted_application_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
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
