<?php

namespace App\Support;

use App\Models\User;

/**
 * The current counter operator, held in the session for a PIN-unlocked device.
 * Transactions record THIS operator (the person who unlocked), not the device's
 * authenticated user. Counter Actions read `CounterOperator::id()` for the
 * `operator_id` on dispensations, orders, cash movements and check-ins.
 */
class CounterOperator
{
    private const KEY = 'counter.operator_id';

    /** Request attribute holding the resolved operator (prompt 273). */
    private const MEMO = 'counter.operator';

    /** The till close's automatic clock-out may be undone only by the same person, until the counter changes hands (312). */
    public const CLOCK_UNDO = 'counter.clock_undo';

    public static function set(User $operator): void
    {
        session()->forget(self::CLOCK_UNDO);
        session([self::KEY => $operator->id]);
        request()->attributes->remove(self::MEMO);
    }

    public static function id(): ?string
    {
        return session(self::KEY);
    }

    /**
     * The operator, resolved ONCE per request (prompt 273). Every `userCan()` / `counterActor()` asks this, and each
     * `User::find()` also reloaded the person's roles and permissions — 15 of the 49 queries in one POS render. Held on
     * the request (not a static), so it can never outlive the request that resolved it; `set()`/`clear()` drop it.
     */
    public static function current(): ?User
    {
        $id = self::id();

        if ($id === null) {
            return null;
        }

        $memo = request()->attributes->get(self::MEMO);
        if ($memo instanceof User && $memo->getKey() === $id) {
            return $memo;
        }

        $user = User::find($id);
        request()->attributes->set(self::MEMO, $user);

        return $user;
    }

    public static function clear(): void
    {
        session()->forget([self::KEY, self::CLOCK_UNDO]);
        request()->attributes->remove(self::MEMO);
    }
}
