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

    public static function set(User $operator): void
    {
        session([self::KEY => $operator->id]);
    }

    public static function id(): ?string
    {
        return session(self::KEY);
    }

    public static function current(): ?User
    {
        $id = self::id();

        return $id !== null ? User::find($id) : null;
    }

    public static function clear(): void
    {
        session()->forget(self::KEY);
    }
}
