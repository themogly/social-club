<?php

namespace App\Support;

/**
 * The provisional prefill: what the browser read off the document, held for one applicant, unconfirmed
 * until they say otherwise (prompt 179).
 *
 * **This is what makes an imperfect reader safe.** Prompt 128 gated the prefill on a ≥~90% read rate, and
 * its reasoning rested on an assumption — that a prefilled value is TRUSTED. Remove that and the read rate
 * stops being load-bearing: every field the reader fills is visibly marked as read from the document, the
 * form cannot be submitted until each is confirmed or corrected, and a wrong read costs a correction rather
 * than a wrong row in the libro de socios. A 60% read rate is then annoying and a 95% one delightful, and
 * neither is dangerous. That matters MORE for a client-side read, not less: it cannot be trusted for
 * correctness and does not need to be, because the applicant is the check.
 *
 * Session-backed and scoped to one invite token, so a shared tablet cannot carry one applicant's read into
 * the next person's form — the same guarantee 173's handover makes about drafts.
 *
 * The RAW MRZ string is never stored here. It is parsed and discarded inside the request that carried it:
 * it is identity data, and the fields it yields are the only thing anything downstream needs.
 */
class MrzPrefill
{
    /** The fields an MRZ can fill. Anything the parser returns outside this list is ignored. */
    public const FIELDS = ['first_name', 'last_name', 'date_of_birth', 'document_number', 'document_type'];

    private static function key(string $token): string
    {
        return 'application.mrz.'.sha1($token);
    }

    /**
     * Remember what was read, for this invite only.
     *
     * @param  array<string, string>  $fields
     */
    public static function remember(string $token, array $fields): void
    {
        $clean = array_filter(
            array_intersect_key($fields, array_flip(self::FIELDS)),
            fn (string $value): bool => trim($value) !== '',
        );

        if ($clean === []) {
            self::forget($token);

            return;
        }

        session([self::key($token) => $clean]);
    }

    /** @return array<string, string> */
    public static function get(string $token): array
    {
        $state = session(self::key($token));

        return is_array($state) ? $state : [];
    }

    /**
     * The field names awaiting confirmation — the list the submit gate checks.
     *
     * @return list<string>
     */
    public static function fields(string $token): array
    {
        return array_keys(self::get($token));
    }

    public static function forget(string $token): void
    {
        session()->forget(self::key($token));
    }
}
