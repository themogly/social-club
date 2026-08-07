<?php

namespace App\Actions\Till;

use App\Actions\Stock\SelectBatch;
use App\Models\Location;
use App\Models\TillSession;
use App\Support\TerminalName;
use Illuminate\Support\Collection;

/**
 * THE resolver for "which open caja is this counter posting to?" (code-style audit).
 *
 * Four screens asked that question and answered it in two different ways. `DispensaryPos` and `BarPos`
 * carried byte-identical copies that match the operator's own terminal and fall back to the oldest open
 * session; `CheckInScreen` and `MembershipCounter` took `latest('opened_at')` — the NEWEST — with no
 * terminal at all. With one open till, which is the ordinary case, all four agreed. With two, a cash
 * membership fee posted to a different drawer depending on which screen took it, and every one of them
 * bypassed `TillSession::scopeOpen()`, which already existed.
 *
 * This is the same principle the stock and pricing paths already hold to — {@see SelectBatch}
 * is its direct model, right down to being an Action rather than a helper: choosing WHICH row the counter
 * acts on is a domain decision, not framework plumbing.
 *
 * The tie-break is now stated once, in one place: **oldest open first**. A terminal is preferred when the
 * caller has one (the two POS screens do); the door and Socios do not have a terminal and take the fallback.
 * Oldest rather than newest because the till that has been running the shift is the one holding the float
 * the money is being counted against — and because an arbitrary rule written twice is how the two versions
 * came to disagree.
 */
class SelectTillSession
{
    /**
     * The open session this counter should post to, or null when the sede has none.
     *
     * @param  string|null  $terminal  the caller's own terminal, when it has one; matched by normalised KEY
     *                                 so a spelling variant still resolves (prompt 84)
     */
    public function handle(Location $location, ?string $terminal = null): ?TillSession
    {
        $sessions = $this->openAt($location);

        if ($terminal === null || trim($terminal) === '') {
            return $sessions->first();
        }

        $key = TerminalName::key($terminal);

        return $sessions->first(fn (TillSession $s): bool => TerminalName::key((string) $s->terminal) === $key);
    }

    /**
     * Every open session at this sede, oldest first.
     *
     * `withoutGlobalScopes()` deliberately: a counter screen has already resolved its OWN sede (the session
     * key `counter.location_id`, never the panel scope), and filters here by that id explicitly.
     *
     * @return Collection<int, TillSession>
     */
    public function openAt(Location $location): Collection
    {
        return TillSession::query()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->open()
            ->orderBy('opened_at')
            ->get();
    }
}
