<?php

namespace App\Support;

use App\Models\Member;

/**
 * The libro de socios — the statutory member register, generated for any point in
 * time ("as at" a date) so a historical assembly can be evidenced. It keeps
 * DEPARTED members (a register that silently drops leavers is not a register): the
 * roll "as at" D is everyone who had joined by D (later joiners excluded), each
 * shown with their join and — where applicable — leave date and status.
 */
class MembersRegister
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function asAt(string $organisationId, string $date): array
    {
        return Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $organisationId)
            ->whereDate('joined_at', '<=', $date)
            ->with(['memberships' => fn ($q) => $q->withoutGlobalScopes()->with('location')->latest('id')])
            ->orderBy('member_no')
            ->get()
            ->map(fn (Member $m): array => [
                'member_no' => $m->member_no,
                'nombre' => $m->fullName(),
                'documento' => $m->document_number, // decrypted at the register edge (permissioned)
                'alta' => $m->joined_at?->toDateString(),
                'baja' => $m->left_at?->toDateString(),
                'estado' => $m->status->value,
                'sede' => $m->memberships->first()?->location->name ?? '—',
            ])->all();
    }
}
