<?php

namespace App\Support;

use App\Enums\MemberDocumentType;
use App\Models\Member;
use App\Models\MemberDocument;

/**
 * Whether a generated member document has DRIFTED from the live member record (prompt 72). Generated
 * documents are immutable, frozen snapshots — that is what makes them evidence — but nothing regenerates
 * them when the underlying figure changes, so a club can hold a signed declaration saying 100 g/month
 * while its own record says 40. This derives that discrepancy on read (never a stored flag that could
 * itself go stale) by comparing the document's snapshot against the member's CURRENT values.
 *
 * The check is GENERAL, not declaration-specific: the same snapshot freezes the member's NAME and
 * DOCUMENT NUMBER too, so a REGISTRATION_FORM drifts if either changes after it was generated. Only the
 * types whose substance IS snapshotted member data are checked — a CONSENT/ID is valid as-signed and does
 * not "drift" because an unrelated figure moved.
 */
class DocumentDrift
{
    /**
     * type => the snapshot fields whose divergence makes a document of that type stale.
     *
     * @var array<string, list<string>>
     */
    private const DRIFTABLE = [
        MemberDocumentType::DECLARATION->value => ['declared_monthly_cg'],
        MemberDocumentType::REGISTRATION_FORM->value => ['declared_monthly_cg', 'nombre', 'documento'],
    ];

    public static function isStale(MemberDocument $document, Member $member): bool
    {
        $fields = self::DRIFTABLE[$document->type->value] ?? [];

        foreach ($fields as $field) {
            // Loose comparison: a JSON-decoded int vs the live int cast, a string name vs string name.
            // A genuine value change (100 g vs 40 g, "Ana Ruiz" vs "Ana Ruiz Gómez") reads as drift; an
            // unchanged value (including null vs null, never declared) does not.
            if (data_get($document->snapshot, $field) != self::currentValue($member, $field)) {
                return true;
            }
        }

        return false;
    }

    private static function currentValue(Member $member, string $field): mixed
    {
        return match ($field) {
            'declared_monthly_cg' => $member->declared_monthly_cg,
            'nombre' => $member->fullName(),
            'documento' => $member->document_number,
            default => null,
        };
    }

    /** Is this a type whose drift we care about at all? (Used to scope which document rows get flagged.) */
    public static function isDriftable(MemberDocument $document): bool
    {
        return array_key_exists($document->type->value, self::DRIFTABLE);
    }
}
