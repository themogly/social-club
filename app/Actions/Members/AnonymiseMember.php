<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Models\Member;
use Illuminate\Support\Facades\Storage;

/**
 * Right to erasure (RGPD Art. 17): anonymise-not-delete. Scrubs personal fields and
 * removes ID/photo files, but KEEPS the financial and consumption ledger
 * (dispensations, wallet, till) intact and attributed to the anonymised record, so
 * the books stay whole. Consumption data is Article 9 special-category (NOTES §A/B).
 */
class AnonymiseMember
{
    public function handle(Member $member): Member
    {
        $before = $member->only(['first_name', 'last_name', 'email', 'phone']);

        foreach (['photo_path', 'document_scan_path'] as $pathField) {
            if (filled($member->{$pathField})) {
                Storage::disk('documents')->delete($member->{$pathField});
            }
        }

        $member->forceFill([
            'first_name' => 'ANONIMIZADO',
            'last_name' => 'Socio '.substr($member->id, 0, 8),
            'email' => null,
            'phone' => null,
            'address' => null,
            'date_of_birth' => null,
            'document_number' => null,
            'document_hash' => null,
            'photo_path' => null,
            'document_scan_path' => null,
            'anonymised_at' => now(),
        ])->save();

        $member->tokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);

        (new RecordAuditLog)->handle('member.anonymised', $member, $before, null);

        return $member;
    }
}
