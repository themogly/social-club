<?php

namespace App\Enums;

use App\Actions\Attendance\ResolveMemberEligibility;

/**
 * The rules {@see ResolveMemberEligibility} can fire — declared, so a new one cannot
 * arrive at the counter with a dead instruction (prompt 211).
 *
 * **Why this is an enum and not seven string keys.** `VerdictRemedy::describe()` matched on the raw rule name
 * with a `default` that returned the resolver's own message and **no remedy at all**. That is how `age`
 * behaved from the day it shipped, and it is what an eighth rule would inherit: it would appear at the door
 * and the POS as a bare sentence, and nobody would notice, because a missing remedy looks exactly like a
 * rule that deliberately has none.
 *
 * Every method on this enum is a `match ($this)` with **no default**, so Larastan fails the build on a case
 * that has not said what an operator can do about it — including saying "nothing at this counter", which is
 * a real and common answer.
 */
enum EligibilityRule: string
{
    case MEMBERSHIP = 'membership';
    case AGE = 'age';
    case SANCTION = 'sanction';
    case CARENCIA = 'carencia';
    case DEBT = 'debt';
    case UNPAID_FEE = 'unpaid_fee';
    case AFORO = 'aforo';
    case PHOTO = 'photo';

    public function label(): string
    {
        return match ($this) {
            self::MEMBERSHIP => __('Membresía'),
            self::AGE => __('Edad'),
            self::SANCTION => __('Sanción'),
            self::CARENCIA => __('Carencia'),
            self::DEBT => __('Deuda'),
            self::UNPAID_FEE => __('Cuota pendiente'),
            self::AFORO => __('Aforo'),
            self::PHOTO => __('Foto'),
        };
    }

    /**
     * Is there something a permitted operator can DO about this, on a counter terminal, now?
     *
     * The per-rule audit prompt 211 asked for, in the one place it can be enforced:
     *
     *  · `MEMBERSHIP`  **yes, and this is the one that was wrong** — prompt 203 built the enrol/renew route
     *    and the remedy sentence went on pointing at the admin panel that STAFF cannot open.
     *  · `UNPAID_FEE` yes — the collect-fee control is already inline beside the verdict (prompt 127), so the
     *    remedy carries no separate action and must not describe one somewhere else.
     *  · `PHOTO`      yes — the door is where a missing photo gets taken (157), and the capture is on that
     *    screen. Counter-side, but not a navigation.
     *  · `CARENCIA`   no — it resolves on a date. The remedy says WHICH date, which is the useful thing.
     *  · `DEBT`       no — settling a wallet debt is money in, through the till, not a button on a verdict.
     *  · `SANCTION`   no, deliberately, and it says so: a suspension is a governance act.
     *  · `AFORO`      no — somebody has to leave.
     *  · `AGE`        no, and never. It had no case at all before this enum and fell through to a bare
     *    sentence; now it is an explicit "nothing here", which is a different thing from an oversight.
     */
    public function hasCounterAction(): bool
    {
        return match ($this) {
            self::MEMBERSHIP => true,
            self::AGE, self::SANCTION, self::CARENCIA, self::DEBT,
            self::UNPAID_FEE, self::AFORO, self::PHOTO => false,
        };
    }

    /** The permission an operator needs before the action is offered at all. */
    public function actionPermission(): ?string
    {
        return match ($this) {
            self::MEMBERSHIP => 'membership.enrol',
            self::AGE, self::SANCTION, self::CARENCIA, self::DEBT,
            self::UNPAID_FEE, self::AFORO, self::PHOTO => null,
        };
    }
}
