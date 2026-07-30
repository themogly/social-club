<?php

namespace App\Actions\Members;

use App\Models\Member;

/**
 * RGPD portability: assemble a single data pack of everything the club holds on a
 * member — personal details, memberships, consumption, wallet ledger, consents.
 * Returned as a plain array (a controller/Filament action streams it as JSON).
 */
class ExportMemberData
{
    /**
     * @return array<string, mixed>
     */
    public function handle(Member $member): array
    {
        return [
            'exported_at' => now()->toIso8601String(),
            'member' => $member->only([
                'id', 'member_no', 'first_name', 'last_name', 'email', 'phone',
                'date_of_birth', 'address', 'document_type', 'status', 'is_therapeutic',
                'joined_at', 'left_at', 'declared_monthly_cg', 'sole_association_declared_at',
            ]),
            'memberships' => $member->memberships()->withoutGlobalScopes()->get()->toArray(),
            'consumption' => $member->dispensations()->withoutGlobalScopes()->get()
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'dispensed_at' => $d->dispensed_at,
                    'total_cents' => $d->total_cents?->cents,
                    'status' => $d->status->value,
                ])->all(),
            'wallet' => $member->walletTransactions()->withoutGlobalScopes()->get()
                ->map(fn ($t) => [
                    'created_at' => $t->created_at,
                    'type' => $t->type->value,
                    'amount_cents' => $t->amount_cents?->cents,
                    'balance_after_cents' => $t->balance_after_cents?->cents,
                ])->all(),
            'consents' => $member->consents()->get()->toArray(),
        ];
    }
}
