<?php

namespace App\Support;

/**
 * The counter's preconditions, resolved to ONE at a time in dependency order (prompt 175).
 *
 * The dispensary told the operator four things at once, in four places, in four visual languages — an
 * amber strip for the operator, a red card with a destructive-styled button for the till, a grey empty
 * state for the member, and grey helper text repeating the member blocker under the commit button.
 * Nothing said which to fix first, and they are not equal:
 *
 *     sede  →  operator  →  till  →  member
 *
 * Without a sede nothing works. Without an operator nothing may be WRITTEN. Without an open till nothing
 * may be dispensed. Without a member there is nothing to dispense. That is a strict chain, and the screen
 * presented it as a pile. Fix the first and the second appears, or the work does — the operator is never
 * shown a problem they cannot act on yet.
 *
 * The OPERATOR step is deliberately not represented here as a rendered blocker: prompt 173 owns it, on the
 * counter's full-screen surface, and it must not be reimplemented. This resolver reports it so the chain is
 * complete and testable, and the screens skip rendering it because the surface is already covering them.
 *
 * NOT a gate. Every precondition is still enforced server-side where it always was — `requireOperator()`,
 * the till check in the commit path, the member check. This decides what a person is shown, never what
 * they are allowed to do.
 */
class CounterBlocker
{
    public const SEDE = 'sede';

    public const OPERATOR = 'operator';

    public const TILL = 'till';

    public const MEMBER = 'member';

    /** Dependency order. The first unmet precondition is the only one shown. */
    public const CHAIN = [self::SEDE, self::OPERATOR, self::TILL, self::MEMBER];

    /**
     * The single blocker to show, or null when the screen is usable.
     *
     * @param  array<string, bool>  $met  keyed by constant; a precondition absent from the array does not
     *                                    apply to this screen (Recepción has no till or member step).
     */
    public static function first(array $met): ?string
    {
        foreach (self::CHAIN as $precondition) {
            if (array_key_exists($precondition, $met) && $met[$precondition] === false) {
                return $precondition;
            }
        }

        return null;
    }

    /**
     * Is this the blocker a screen should RENDER? The operator step is covered by prompt 173's surface,
     * which is already full-screen and on top — rendering a second blocking state underneath it would put
     * two on screen at once, which is the thing this branch exists to stop.
     */
    public static function rendersInPage(?string $blocker): bool
    {
        return $blocker !== null && $blocker !== self::OPERATOR;
    }
}
