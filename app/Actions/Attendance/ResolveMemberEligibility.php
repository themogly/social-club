<?php

namespace App\Actions\Attendance;

use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipFeePayment;
use App\Support\ActiveScope;
use App\Support\EligibilityVerdict;
use App\Support\MemberEligibility;
use App\Support\Occupancy;
use App\Support\Settings;
use App\Support\Wallet;

/**
 * THE one place the door/counter checks live (membership, age, sanction, aforo,
 * carencia, debt, unpaid fee). Returns a per-rule verdict for a surface, each rule
 * carrying its enforcement mode from the matrix (Settings). The check-in screen
 * (door) and the POS (counter) both call this — never two copies of the logic.
 */
class ResolveMemberEligibility
{
    public function handle(Member $member, Location $location, string $surface): EligibilityVerdict
    {
        return app(ActiveScope::class)->forLocation($location->id, function () use ($member, $location, $surface): EligibilityVerdict {
            $rules = [
                $this->rule($surface, 'membership', $this->hasActiveMembership($member, $location), __('Sin membresía activa en esta sede.')),
                $this->rule($surface, 'age', MemberEligibility::isOldEnough($member->date_of_birth), __('Menor de la edad mínima.')),
                $this->rule($surface, 'sanction', $member->status === MemberStatus::ACTIVE, __('Socio/a suspendido/a o expulsado/a.')),
                $this->rule($surface, 'carencia', MemberEligibility::carenciaPassed($member), __('En periodo de carencia (puede entrar, no puede dispensarse).')),
                $this->rule($surface, 'debt', $this->debtWithinThreshold($member, $location, $surface), __('Deuda por encima del umbral.')),
                $this->rule($surface, 'unpaid_fee', $this->feesPaid($member, $location), __('Cuota de socio pendiente.')),
            ];

            if ($surface === 'door') {
                $rules[] = $this->rule('door', 'aforo', $this->aforoAvailable($location), __('Aforo completo.'));
            }

            // Photo-on-file (prompt 157): the counter verifies identity by comparing a face to a photo, so a
            // member with none cannot be checked. Appended ONLY when a club has opted in (WARN or OVERRIDE);
            // when OFF the rule is absent, so the verdict is byte-identical to a club that never configured it —
            // no surprise block on upgrade. Read OFF-safe (photoEnforcement, NOT enforcement, which would
            // fail-safe a legacy matrix to BLOCK). Never a DOOR rule: the door is where a missing photo gets
            // TAKEN, so blocking entry over it would be self-defeating.
            if ($surface === 'counter') {
                $photoMode = Settings::photoEnforcement('counter');
                // Prompt 348 — *Exigir foto para dispensar* (per sede, on by default): no photo is a hard BLOCK here, resolved by
                // taking one on this screen (the blocked surface offers «Hacer foto»). CommitDispensation refuses too.
                $required = (bool) Settings::get('require_photo_to_dispense', true, (string) $location->getKey());
                if ($required) {
                    $photoMode = 'BLOCK';
                }

                if ($photoMode !== 'OFF') {
                    $rules[] = [
                        'rule' => 'photo',
                        'satisfied' => filled($member->photo_path),
                        'mode' => $photoMode,
                        'message' => $required ? __('Hazle una foto antes de dispensar.') : __('Sin foto en ficha para verificar su identidad.'),
                    ];
                }
            }

            return new EligibilityVerdict($rules);
        });
    }

    /**
     * @return array{rule: string, satisfied: bool, mode: string, message: string}
     */
    private function rule(string $surface, string $key, bool $satisfied, string $message): array
    {
        return ['rule' => $key, 'satisfied' => $satisfied, 'mode' => Settings::enforcement($surface, $key), 'message' => $message];
    }

    private function hasActiveMembership(Member $member, Location $location): bool
    {
        return $member->memberships()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->exists();
    }

    /**
     * Prompt 271 — at the COUNTER a member within their approved tab is not over any threshold ({@see
     * Wallet::debtIsWithinApprovedTab()}, the rule the wallet writer applies); the club cap at 0 means "no cap", as it
     * does there. It used to compare against the club cap as a block threshold, so at the default 0 an approved tab
     * worked once and then blocked. The DOOR keeps its own, independent threshold (default mode WARN): a reminder at
     * the door that somebody owes money is a club's legitimate choice even within an approved tab.
     */
    private function debtWithinThreshold(Member $member, Location $location, string $surface): bool
    {
        if ($surface === 'door') {
            return Wallet::balance($member->id, $location->id) >= -(int) Settings::get('wallet_door_debt_threshold_cents', 0);
        }

        return Wallet::debtIsWithinApprovedTab($member, $location->id);
    }

    private function feesPaid(Member $member, Location $location): bool
    {
        $membership = $member->memberships()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->latest('id')->first();

        if ($membership === null) {
            return true; // handled by the membership rule
        }

        $paid = (int) MembershipFeePayment::query()->where('membership_id', $membership->id)->sum('amount_cents');

        return $membership->fee_cents->cents <= $paid;
    }

    private function aforoAvailable(Location $location): bool
    {
        $capacity = Occupancy::capacity($location);

        return $capacity === null || Occupancy::current($location) < $capacity;
    }
}
