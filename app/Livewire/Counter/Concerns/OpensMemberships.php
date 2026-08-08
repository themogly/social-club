<?php

namespace App\Livewire\Counter\Concerns;

use App\Actions\Memberships\EnrolMembership;
use App\Actions\Memberships\RenewMembership;
use App\Enums\MembershipStatus;
use App\Exceptions\DuplicateMembershipException;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Open a membership at THIS sede — a new one, or a lapsed one restored (prompt 203).
 *
 * **The dead end this exists to close.** A member could be ACTIVE, standing at the counter, with the screen
 * saying *"Sin membresía activa en esta sede"* and the verdict panel telling the operator to *"renew their
 * fee from their record"* — and there was no control on the screen that did that. The three Actions that
 * would have (`EnrolMembership`, `RenewMembership`, `TransferMembership`) were surfaced in exactly one place,
 * the admin panel, which STAFF hold no permission to act in. So the screen's own remedy text pointed a staff
 * user at a door they cannot open, and on a Friday evening with one person working the member goes home.
 *
 * **Three situations arrive at that same dead end and they are not the same problem:**
 *
 *   1. **Lapsed here** — they were a member of this sede and it ran out. `RenewMembership` on the existing
 *      row: the same membership, extended, with its history intact.
 *   2. **Never enrolled here** — an active member of the club who has not been enrolled at this sede.
 *      `EnrolMembership`, on the tier the operator picks, at that tier's DEFAULT fee.
 *   3. **Active at another sede** — treated as case 2, and deliberately so. See below.
 *
 * **Case 3 enrols a SECOND membership; it never transfers.** A transfer moves the row: the other sede loses
 * a member from its register and from `StockCeiling::forLocation()`, decided from a tablet at this one, by a
 * person who may not work there and does not hold `members.transfer`. Enrolling here is additive and local —
 * it changes this sede's register and touches nothing at the other. This is one asociación with several
 * premises, not several clubs, so holding a membership at two of them raises no sole-association question;
 * it is an internal register fact, and the screen states it. If the member genuinely wants to MOVE, that is
 * a register act with two sedes' interests in it and stays in the panel behind `members.transfer`.
 *
 * **What did not move.** Fee overrides, tier changes, suspensions, limits and transfers are all exactly where
 * prompts 127 and 177 left them. A counter route passes the tier's default fee and offers no box — which is
 * also why a membership carrying a NON-default fee is refused here rather than silently renewed onto the
 * default: someone with `membership.fee.override` set that figure deliberately, and a staff renewal must not
 * quietly undo it.
 *
 * **Prompt 211 — the same dead end was still on the other two screens, and this trait had one consumer.**
 * 203 did the architecture right and stopped one screen short: it extracted this concern, and then only
 * `MembershipCounter` used it. `CheckInScreen` and `DispensaryPos` render the SAME verdict, from the SAME
 * resolver, and showed the SAME *"sin membresía activa en esta sede"* with nothing to press — reported twice,
 * once for each. So 211 wires this concern to both rather than building anything: the enrol/renew route, its
 * uniqueness guard, its `membership.enrol` gate and its refusal to touch a non-default fee are 203's, unchanged.
 *
 * The host must expose `flash()`, `resolveLocation()`, `currentUser()`, `requireOperator()` and a member on
 * screen. **Which property holds that member differs per host** — Socios calls it `$feeMemberId` (it is the
 * socio a fee would be collected from), the door and the POS call it `$memberId` — so hosts override
 * {@see self::membershipSubjectId()}. That indirection is the whole of what 211 had to add here.
 */
trait OpensMemberships
{
    /** The tier chosen for a new enrolment. Never a fee — see the class docblock. */
    public ?string $openTierId = null;

    /**
     * The socio this concern acts on.
     *
     * Defaults to `CollectsMembershipFees`' `$feeMemberId`, which is what 203's only host used. The door and
     * the POS hold their member in `$memberId` and override this. Deliberately a METHOD rather than a
     * property name convention: a host that resolves its subject some other way can, and the trait cannot be
     * silently pointed at the wrong person by a property that happens to share a name.
     */
    protected function membershipSubjectId(): ?string
    {
        return $this->feeMemberId;
    }

    /**
     * Everything a screen needs to render the fix panel, or null when there is nothing to fix.
     *
     * Resolved HERE so the three screens cannot disagree about which of 203's three cases a member is in —
     * the panel is one shared partial reading one shared resolver.
     *
     * @return array{case: string, lapsed: ?Membership, elsewhere: Collection<int, Membership>, tiers: Collection<int, MembershipTier>, member: Member}|null
     */
    public function membershipFix(): ?array
    {
        $location = $this->resolveLocation();
        $id = $this->membershipSubjectId();
        $member = $id !== null ? Member::query()->find($id) : null;

        if ($member === null || $location === null) {
            return null;
        }

        $case = $this->membershipCase($member, $location);

        if ($case === 'active') {
            return null;
        }

        return [
            'case' => $case,
            'lapsed' => $this->lapsedMembershipHere($member, $location),
            'elsewhere' => $this->membershipsElsewhere($member, $location),
            'tiers' => $this->openTiers($location),
            'member' => $member,
        ];
    }

    /**
     * Restore the lapsed membership this member already holds at this sede.
     *
     * The same row, extended by `RenewMembership` — not a new one — so the membership's fee payments, its
     * history and its id all survive, and the audit reads `membership.renewed` rather than a second alta.
     */
    public function renewMembership(): void
    {
        if (! $this->requireOperator()) {
            return;
        }

        [$member, $location, $user] = $this->openMembershipContext();

        if ($member === null || $location === null || $user === null) {
            return;
        }

        $membership = $this->lapsedMembershipHere($member, $location);

        if ($membership === null) {
            $this->flash(__('Este socio no tiene ninguna membresía que renovar en esta sede.'), 'error');

            return;
        }

        $tier = $membership->tier;

        if ($tier === null) {
            $this->flash(__('Esta membresía no tiene cuota asignada; renuévala desde el panel.'), 'error');

            return;
        }

        // A fee that is not the tier's default was set by somebody holding `membership.fee.override`. The
        // counter has no fee box and must not overwrite that decision in either direction, so it declines
        // and says where the renewal lives instead.
        if ($membership->fee_cents->cents !== $tier->default_fee_cents->cents) {
            $this->flash(__('Esta membresía tiene una cuota especial. Renuévala desde el panel para conservarla.'), 'warning');

            return;
        }

        (new RenewMembership)->handle($membership, ['fee_cents' => $tier->default_fee_cents->cents]);

        $this->afterMembershipOpened($member, __('Membresía renovada. Cobra la cuota cuando quieras.'));
    }

    /**
     * Enrol this member at this sede, on the chosen tier, at that tier's default fee.
     *
     * Covers "never enrolled here" and "active at another sede" — the same act, and the screen words it
     * differently for each because they mean different things to the person reading it.
     */
    public function enrolAtThisSede(): void
    {
        if (! $this->requireOperator()) {
            return;
        }

        [$member, $location, $user] = $this->openMembershipContext();

        if ($member === null || $location === null || $user === null) {
            return;
        }

        $tier = $this->openTierId !== null
            ? MembershipTier::query()->withoutGlobalScopes()
                ->where('organisation_id', $location->organisation_id)
                ->find($this->openTierId)
            : null;

        if ($tier === null) {
            $this->flash(__('Elige una cuota antes de dar de alta.'), 'error');

            return;
        }

        try {
            // No fee_cents: EnrolMembership defaults to the tier's, so this can never be an override and
            // never needs `membership.fee.override`.
            (new EnrolMembership)->handle($member, $location, $tier);
        } catch (DuplicateMembershipException $e) {
            // The Action's guard, not the screen's — a double-tap lands here rather than on a second row.
            $this->flash($e->getMessage(), 'warning');

            return;
        }

        $this->openTierId = null;
        $this->afterMembershipOpened($member, __('Alta en esta sede hecha. Cobra la cuota cuando quieras.'));
    }

    // --- what the screen reads ----------------------------------------------------

    /**
     * Which of the three situations this member is in at this sede.
     *
     * `active` · `lapsed_here` · `none_here` — the screen renders one control per case and nothing at all
     * for `active`, because there is nothing to put right.
     */
    public function membershipCase(Member $member, Location $location): string
    {
        if ($member->activeMembershipAt($location) !== null) {
            return 'active';
        }

        return $this->lapsedMembershipHere($member, $location) !== null ? 'lapsed_here' : 'none_here';
    }

    /** The most recent non-active membership this member holds at this sede, or null. */
    public function lapsedMembershipHere(Member $member, Location $location): ?Membership
    {
        return $member->memberships()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->whereIn('status', [MembershipStatus::LAPSED, MembershipStatus::EXPIRING_SOON, MembershipStatus::CANCELLED])
            ->latest('id')
            ->first();
    }

    /**
     * Active memberships this member holds at the club's OTHER sedes.
     *
     * Shown because it is the fact that makes case 3 legible: without it the operator sees "no membership
     * here" on somebody who is plainly an active member and cannot tell which of the three situations they
     * are looking at. A register fact within one asociación, not a document.
     *
     * @return Collection<int, Membership>
     */
    public function membershipsElsewhere(Member $member, Location $location): Collection
    {
        return $member->memberships()->withoutGlobalScopes()
            ->with('location')
            ->where('location_id', '!=', $location->id)
            ->active()
            ->get();
    }

    /** @return Collection<int, MembershipTier> */
    public function openTiers(Location $location): Collection
    {
        return MembershipTier::query()->withoutGlobalScopes()
            ->where('organisation_id', $location->organisation_id)
            ->orderBy('name')
            ->get();
    }

    /** May the person at this terminal open a membership at all? Drives copy, not just a hidden button. */
    public function canOpenMembership(): bool
    {
        return (bool) $this->currentUser()?->can('membership.enrol');
    }

    // --- shared plumbing ----------------------------------------------------------

    /**
     * @return array{0: ?Member, 1: ?Location, 2: ?User}
     */
    private function openMembershipContext(): array
    {
        $user = $this->currentUser();
        $location = $this->resolveLocation();

        if ($user === null || ! $user->can('membership.enrol')) {
            $this->flash(__('No tienes permiso para dar de alta ni renovar membresías.'), 'error');

            return [null, null, null];
        }

        if ($location === null) {
            $this->flash(__('Sin sede activa.'), 'error');

            return [null, null, null];
        }

        $subjectId = $this->membershipSubjectId();
        $member = $subjectId !== null ? Member::query()->find($subjectId) : null;

        if ($member === null) {
            $this->flash(__('Selecciona un socio.'), 'error');

            return [null, null, null];
        }

        return [$member, $location, $user];
    }

    /**
     * Hand the member straight to the fee panel already on this screen, and say so.
     *
     * Opening a membership and taking its fee are DELIBERATELY not one transaction — prompt 174's recorded
     * decision, for the same reason: if the fee cannot be taken (no cash, no open drawer) the membership
     * still exists and is owed, which is an ordinary state this product already represents and this very
     * screen already surfaces. Rolling back an admission over a payment failure would be worse. So the
     * confirmation says the alta landed AND that the fee is still to come, rather than implying both.
     */
    private function afterMembershipOpened(Member $member, string $message): void
    {
        // Keep the socio on screen. The host's OWN subject property is already pointing at them — that is how
        // we got here — so this only has to hold Socios' fee target, which every host has because they all
        // carry `CollectsMembershipFees` (the default `membershipSubjectId()` reads it).
        $this->feeMemberId = $member->id;
        $this->flash($message, 'success');
    }
}
