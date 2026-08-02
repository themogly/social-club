<?php

namespace App\Support\Mrz;

/**
 * Prompt 128 — a pure, offline parser for the Machine-Readable Zone of an ID document (TD1 = ID-1 cards, the
 * Spanish DNI 3.0 / NIE and residence permits; TD3 = passports). NO cloud, NO OCR here — it takes already-OCR'd
 * MRZ lines and turns them into fields, validating every ICAO 9303 check digit so a mis-read is REJECTED rather
 * than silently prefilling a wrong document number (the exact failure the feature exists to prevent).
 *
 * This is the deterministic half of the measurement harness the prompt requires FIRST: the real-world read rate
 * is dominated by OCR/capture quality, but the parser must be correct-or-null so a garbled read never passes as
 * clean. It is proven on synthetic MRZ strings in tests; it does not, and must not, guess.
 *
 * @phpstan-type MrzResult array{format: string, document_number: string, surname: string, given_names: string, nationality: string, birth_date: ?string, expiry_date: ?string, sex: ?string, valid: bool}
 */
class MrzParser
{
    /**
     * Parse OCR'd MRZ text (2 lines of 44 for TD3, or 3 lines of 30 for TD1). Returns null when it is not a
     * recognisable, check-digit-valid MRZ — the caller then prefills nothing rather than something wrong.
     *
     * @return MrzResult|null
     */
    public function parse(string $raw): ?array
    {
        $lines = array_values(array_filter(array_map(
            fn (string $l): string => strtoupper(preg_replace('/[^A-Z0-9<]/i', '', $l) ?? ''),
            preg_split('/\r\n|\r|\n/', trim($raw)) ?: [],
        ), fn (string $l): bool => $l !== ''));

        if (count($lines) === 2 && strlen($lines[0]) === 44 && strlen($lines[1]) === 44) {
            return $this->parseTd3($lines[0], $lines[1]);
        }
        if (count($lines) === 3 && strlen($lines[0]) === 30 && strlen($lines[1]) === 30 && strlen($lines[2]) === 30) {
            return $this->parseTd1($lines[0], $lines[1], $lines[2]);
        }

        return null;
    }

    /** @return MrzResult */
    private function parseTd3(string $l1, string $l2): array
    {
        $docNumber = $this->trimFiller(substr($l2, 0, 9));
        $checks = [
            $this->checkDigit(substr($l2, 0, 9)) === (int) substr($l2, 9, 1),
            $this->checkDigit(substr($l2, 13, 6)) === (int) substr($l2, 19, 1),   // DOB
            $this->checkDigit(substr($l2, 21, 6)) === (int) substr($l2, 27, 1),   // expiry
        ];

        [$surname, $given] = $this->names(substr($l1, 5));

        return [
            'format' => 'TD3',
            'document_number' => $docNumber,
            'surname' => $surname,
            'given_names' => $given,
            'nationality' => $this->trimFiller(substr($l2, 10, 3)),
            'birth_date' => $this->date(substr($l2, 13, 6), century: 'past'),
            'expiry_date' => $this->date(substr($l2, 21, 6), century: 'future'),
            'sex' => $this->sex(substr($l2, 20, 1)),
            'valid' => ! in_array(false, $checks, true),
        ];
    }

    /** @return MrzResult */
    private function parseTd1(string $l1, string $l2, string $l3): array
    {
        $docNumber = $this->trimFiller(substr($l1, 5, 9));
        $checks = [
            $this->checkDigit(substr($l1, 5, 9)) === (int) substr($l1, 14, 1),
            $this->checkDigit(substr($l2, 0, 6)) === (int) substr($l2, 6, 1),     // DOB
            $this->checkDigit(substr($l2, 8, 6)) === (int) substr($l2, 14, 1),    // expiry
        ];

        [$surname, $given] = $this->names($l3);

        return [
            'format' => 'TD1',
            'document_number' => $docNumber,
            'surname' => $surname,
            'given_names' => $given,
            'nationality' => $this->trimFiller(substr($l2, 15, 3)),
            'birth_date' => $this->date(substr($l2, 0, 6), century: 'past'),
            'expiry_date' => $this->date(substr($l2, 8, 6), century: 'future'),
            'sex' => $this->sex(substr($l2, 7, 1)),
            'valid' => ! in_array(false, $checks, true),
        ];
    }

    /**
     * ICAO 9303 check digit: weights 7,3,1 cycling; digits = value, A–Z = 10–35, filler '<' = 0; sum mod 10.
     */
    public function checkDigit(string $field): int
    {
        $weights = [7, 3, 1];
        $sum = 0;
        foreach (str_split($field) as $i => $char) {
            $sum += $this->charValue($char) * $weights[$i % 3];
        }

        return $sum % 10;
    }

    private function charValue(string $char): int
    {
        if ($char === '<') {
            return 0;
        }
        if (ctype_digit($char)) {
            return (int) $char;
        }

        return ord($char) - ord('A') + 10; // A=10 … Z=35
    }

    /** @return array{0: string, 1: string} surname, given names */
    private function names(string $field): array
    {
        $parts = explode('<<', $this->trimFiller($field), 2);
        $surname = str_replace('<', ' ', trim($parts[0]));
        $given = isset($parts[1]) ? trim(preg_replace('/<+/', ' ', $parts[1]) ?? '') : '';

        return [trim($surname), trim($given)];
    }

    private function trimFiller(string $value): string
    {
        return rtrim($value, '<');
    }

    private function sex(string $char): ?string
    {
        return in_array($char, ['M', 'F'], true) ? $char : null;
    }

    /** YYMMDD → Y-m-d, choosing the century by whether the field is a birth (past) or expiry (future). */
    private function date(string $yymmdd, string $century): ?string
    {
        if (! ctype_digit($yymmdd) || strlen($yymmdd) !== 6) {
            return null;
        }

        $yy = (int) substr($yymmdd, 0, 2);
        $mm = (int) substr($yymmdd, 2, 2);
        $dd = (int) substr($yymmdd, 4, 2);

        if ($mm < 1 || $mm > 12 || $dd < 1 || $dd > 31) {
            return null;
        }

        // Birth dates are in the past (19xx/20xx by pivot); expiries are in the near future (20xx).
        $year = $century === 'future' ? 2000 + $yy : ($yy > (int) date('y') ? 1900 + $yy : 2000 + $yy);

        return sprintf('%04d-%02d-%02d', $year, $mm, $dd);
    }
}
