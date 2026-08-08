<?php

namespace App\Actions\Members;

use App\Models\Member;
use Illuminate\Support\Arr;

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
            // Bar/merch purchases + attendance are data the club holds on the member — a portability
            // pack (RGPD Art. 20) must include them, not only the cannabis ledger.
            'orders' => $member->orders()->withoutGlobalScopes()->get()
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'created_at' => $o->created_at,
                    'total_cents' => $o->total_cents?->cents,
                    'status' => $o->status->value,
                    'items' => $o->items,
                ])->all(),
            'visits' => $member->checkIns()->withoutGlobalScopes()->get()
                ->map(fn ($c) => [
                    'checked_in_at' => $c->checked_in_at,
                    'checked_out_at' => $c->checked_out_at,
                    'location_id' => $c->location_id,
                    'method' => $c->method->value,
                ])->all(),
            'wallet' => $member->walletTransactions()->withoutGlobalScopes()->get()
                ->map(fn ($t) => [
                    'created_at' => $t->created_at,
                    'type' => $t->type->value,
                    'amount_cents' => $t->amount_cents?->cents,
                    'balance_after_cents' => $t->balance_after_cents?->cents,
                ])->all(),
            // Consents carry the prompt-220 signature POINTER. A portability pack is the member's own data, so
            // the fact that they signed and which consent it covers both belong in it — but the raw vault path
            // is an internal address, not their data, so it is reported as a flag. The image itself is served
            // the way every other vault artefact is: a signed, access-logged URL, never a path in a JSON blob.
            'consents' => $member->consents()->get()
                ->map(fn ($c) => Arr::except($c->toArray(), ['signature_path']) + ['signed' => $c->isSigned()])
                ->all(),
        ];
    }
}
