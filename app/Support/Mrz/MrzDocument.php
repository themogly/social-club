<?php

namespace App\Support\Mrz;

use App\Enums\IdDocumentType;

/**
 * Prompt 346 — what a VALID parsed zone (`MrzParser`) puts in the sign-up form, in one place for both readers (the
 * applicant's form and the counter's staff form), so the two can never disagree about a field.
 *
 * The document TYPE is set only where the zone says it unambiguously: a passport (TD3) is a passport; a Spanish card
 * (TD1) held by a Spaniard is a DNI. Anything else — a TD1 held by a foreign national could be a TIE (NIE) or their own
 * country's ID card — is left for the person to choose rather than guessed.
 */
class MrzDocument
{
    /**
     * @param  array<string, mixed>  $parsed  a `MrzParser::parse()` result with `valid` true
     * @return array<string, string> form field => value, blanks left out
     */
    public static function fields(array $parsed): array
    {
        $type = match (true) {
            ($parsed['format'] ?? null) === 'TD3' => IdDocumentType::PASSPORT->value,
            ($parsed['format'] ?? null) === 'TD1' && ($parsed['nationality'] ?? null) === 'ESP' => IdDocumentType::DNI->value,
            default => null,
        };

        return array_filter([
            'first_name' => (string) ($parsed['given_names'] ?? ''),
            'last_name' => (string) ($parsed['surname'] ?? ''),
            'document_number' => (string) ($parsed['document_number'] ?? ''),
            // The only nullable one: a TD1/TD3 date can fail to parse while the rest of the zone reads.
            'date_of_birth' => (string) ($parsed['birth_date'] ?? ''),
            'document_type' => (string) $type,
        ], fn (string $value): bool => trim($value) !== '');
    }
}
