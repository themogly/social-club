<?php

namespace App\Support;

use App\Enums\MembershipStatus;
use App\Models\Location;
use App\Models\Member;

/**
 * The reasons a fee may be waived for (219), for the counter AND the panel (prompt 325): one list, so a waiver reads the
 * same wherever it was recorded. A record-backed reason is offered — and suggested — only when the record supports it
 * (a therapeutic socio; a socio with a live membership at another sede); "Otro motivo" always, with the text typed.
 * Prompt 333 — for a holder of `reasons.optional` (a manager, by default) "Aprobado por responsable" comes FIRST and is
 * the suggested (pre-selected) one: no text needed. Never offered to anyone else, so a crafted value resolves to nothing.
 */
final class FeeWaiverReasons
{
    /** @return list<array{value: string, label: string, suggested: bool}> */
    public static function options(?Member $member, ?Location $location, bool $managerApproved = false): array
    {
        $therapeutic = (bool) ($member?->is_therapeutic);
        $elsewhere = $member !== null && $location !== null && $member->memberships()->withoutGlobalScopes()
            ->where('location_id', '!=', $location->id)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->exists();

        $options = [];
        if ($managerApproved) {
            $options[] = ['value' => 'MANAGER_APPROVED', 'label' => ManagerApproval::reason(), 'suggested' => true];
        }
        if ($therapeutic) {
            $options[] = ['value' => 'THERAPEUTIC', 'label' => __('Terapéutico'), 'suggested' => true];
        }
        if ($elsewhere) {
            $options[] = ['value' => 'OTHER_SEDE', 'label' => __('Socio en otra sede'), 'suggested' => true];
        }
        $options[] = ['value' => 'OTHER', 'label' => __('Otro motivo'), 'suggested' => false];

        return $options;
    }

    /**
     * The reason as it is recorded: the chosen option's label, or the typed text for "Otro motivo". Null when there is none.
     *
     * @param  list<array{value: string, label: string, suggested: bool}>  $options
     */
    public static function resolve(array $options, string $value, string $text): ?string
    {
        $option = collect($options)->firstWhere('value', $value);
        if ($option === null) {
            return null;
        }
        if ($option['value'] !== 'OTHER') {
            return (string) $option['label'];
        }

        return trim($text) !== '' ? trim($text) : null;
    }
}
