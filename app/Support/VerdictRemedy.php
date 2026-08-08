<?php

namespace App\Support;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Enums\EligibilityRule;
use App\Enums\MembershipStatus;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Prompt 135 — turns a fired eligibility rule into what the operator can SAY and DO about it. It is pure
 * presentation: {@see ResolveMemberEligibility} and ResolveMemberLimits are untouched;
 * this reads what they already returned and names the rule in the member's own terms ("en carencia hasta el
 * 14/08", "cuota pendiente 25,00 €", "deuda de 12,50 € por encima del límite") with the fix beside it. Where the
 * answer is genuinely "nothing at the counter", it says so and says when or who — because an operator who knows
 * a member is in carencia until Thursday can tell them, and one who sees a generic refusal cannot.
 *
 * The unpaid-fee ACTION is already inline at the door and POS (prompt 127); this names every other rule and adds
 * the remedy note. It never changes a verdict or makes a rule easier to pass.
 *
 * **Prompt 211 — a remedy can now carry an ACTION, not just a sentence.** Prompt 203 closed the
 * membership dead end: a member with a lapsed or absent membership could be enrolled or renewed at the
 * counter, by STAFF, through audited Actions. It closed it **on the Socios screen** and left this shared
 * string untouched — so the dispensary and the door went on saying *"Renueva su cuota desde su ficha"*,
 * naming the admin panel that 203 exists because staff cannot use. The same defect, on two more screens,
 * from one sentence.
 *
 * So the fix belongs HERE rather than in a screen's blade: the door, the dispensary and Socios all read this,
 * and patching one of them would have left the other two saying the wrong thing tomorrow — which is how this
 * arrived twice already. A rule that has a fix a permitted operator can perform says so and offers it; a rule
 * that does not keeps today's behaviour, and {@see EligibilityRule} makes each of those an
 * explicit declaration rather than a `default`.
 *
 * The remedy is still PURE PRESENTATION. It says a fix EXISTS on this terminal and what it is called; the
 * screen renders 203's own panel (`partials/membership-fix`) to perform it. It changes what the operator can
 * reach, never whether the commit is refused: a BLOCKS verdict still blocks, and `ResolveMemberEligibility`
 * is untouched.
 *
 * @phpstan-type Remedy array{detail: string, remedy: ?string, action: ?array{label: string, inline: bool}}
 */
class VerdictRemedy
{
    /**
     * @param  array{rule: string, satisfied: bool, mode: string, message: string}  $rule
     * @param  ?User  $actor  the operator reading it — a remedy must never instruct somebody to do something
     *                        they hold no permission for, so the WORDING changes with the actor, not just the
     *                        presence of a button
     * @return Remedy
     */
    public static function describe(array $rule, Member $member, Location $location, ?User $actor = null): array
    {
        $case = EligibilityRule::tryFrom($rule['rule']);

        if ($case === null) {
            return ['detail' => $rule['message'], 'remedy' => null, 'action' => null];
        }

        return match ($case) {
            EligibilityRule::CARENCIA => self::plain(
                $member->carencia_ends_at instanceof Carbon
                    ? __('En carencia hasta el :date (puede entrar, aún no dispensarse).', ['date' => $member->carencia_ends_at->format('d/m/Y')])
                    : $rule['message'],
            ),
            // The collect-fee control is already inline beside the verdict (prompt 127), so this must not
            // describe an action somewhere else — the one that exists is a few pixels away.
            EligibilityRule::UNPAID_FEE => self::plain(self::feeDetail($member, $location) ?? $rule['message']),
            EligibilityRule::DEBT => self::plain(
                self::debtDetail($member, $location) ?? $rule['message'],
                __('Debe saldar el monedero para poder continuar.'),
            ),
            EligibilityRule::MEMBERSHIP => self::membershipRemedy($rule, $member, $location, $actor),
            EligibilityRule::SANCTION => self::plain($rule['message'], __('No se resuelve en el mostrador: consulta con un responsable.')),
            EligibilityRule::AFORO => self::plain(self::aforoDetail($location)),
            EligibilityRule::PHOTO => self::plain($rule['message'], __('Haz la foto ahora, con el documento delante.')),
            // No counter fix and never one — it was falling through a `default` before, which is
            // indistinguishable from an oversight. Explicit now.
            EligibilityRule::AGE => self::plain($rule['message']),
        };
    }

    /**
     * The rule prompt 211 exists for.
     *
     * An operator holding `membership.enrol` — which prompt 203 granted STAFF and MANAGER — can put this
     * right at the counter, so the remedy says so and carries the way there. One who does not gets a sentence
     * that asks for the person who can, rather than one naming a screen they cannot open.
     *
     * @param  array{rule: string, satisfied: bool, mode: string, message: string}  $rule
     * @return Remedy
     */
    private static function membershipRemedy(array $rule, Member $member, Location $location, ?User $actor): array
    {
        if ($actor === null || ! $actor->can(EligibilityRule::MEMBERSHIP->actionPermission() ?? '')) {
            return self::plain($rule['message'], __('Pide a un responsable que le dé de alta en esta sede.'));
        }

        return [
            'detail' => $rule['message'],
            'remedy' => __('Puedes darle de alta o renovarle aquí mismo.'),
            // **In place, not a jump.** Sending the operator to Socios with the socio loaded was the other
            // defensible option and was built first; it lost because 203 had already extracted
            // `OpensMemberships` and left it with one consumer. Wiring that concern to the door and the POS
            // is less code than a navigation, keeps the basket and the queue where they are, and answers the
            // door's report too — a jump from a door with people at it is worse than a jump from a POS.
            // So the remedy declares that a fix exists HERE and names the gate; the screen renders 203's
            // panel, from one shared partial.
            'action' => ['label' => __('Resolver su membresía'), 'inline' => true],
        ];
    }

    /**
     * A remedy with no action — the common and correct case.
     *
     * @return Remedy
     */
    private static function plain(string $detail, ?string $remedy = null): array
    {
        return ['detail' => $detail, 'remedy' => $remedy, 'action' => null];
    }

    private static function feeDetail(Member $member, Location $location): ?string
    {
        $membership = self::activeMembership($member, $location);
        if ($membership === null) {
            return null;
        }

        $paid = (int) MembershipFeePayment::query()->where('membership_id', $membership->id)->sum('amount_cents');
        $owed = max(0, $membership->fee_cents->cents - $paid);

        return $owed > 0 ? __('Cuota de socio pendiente: :amount.', ['amount' => Money::fromCents($owed)->formatted()]) : null;
    }

    private static function debtDetail(Member $member, Location $location): ?string
    {
        $balance = Wallet::balance($member->id, $location->id);

        return $balance < 0
            ? __('Deuda de :amount por encima del límite del monedero.', ['amount' => Money::fromCents(-$balance)->formatted()])
            : null;
    }

    private static function aforoDetail(Location $location): string
    {
        $capacity = Occupancy::capacity($location);

        return $capacity !== null
            ? __('Aforo completo (:current/:capacity).', ['current' => Occupancy::current($location), 'capacity' => $capacity])
            : __('Aforo completo.');
    }

    private static function activeMembership(Member $member, Location $location): ?Membership
    {
        return $member->memberships()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->latest('id')->first();
    }
}
