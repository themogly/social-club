<?php

namespace Tests\Unit;

use App\Support\Mrz\MrzParser;
use PHPUnit\Framework\TestCase;

/**
 * Prompt 128 — the deterministic MRZ parser (the measurement harness's core). It is correct-or-null: a clean
 * MRZ parses to the right fields, and any broken check digit is marked invalid so a mis-read never prefills a
 * wrong document number. Proven on the canonical ICAO 9303 examples — no photos, no OCR needed.
 */
class MrzParserTest extends TestCase
{
    public function test_it_parses_a_valid_td3_passport_mrz(): void
    {
        $result = (new MrzParser)->parse(
            "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\n".
            'L898902C36UTO7408122F1204159ZE184226B<<<<<10'
        );

        $this->assertNotNull($result);
        $this->assertSame('TD3', $result['format']);
        $this->assertSame('L898902C3', $result['document_number']);
        $this->assertSame('ERIKSSON', $result['surname']);
        $this->assertSame('ANNA MARIA', $result['given_names']);
        $this->assertSame('UTO', $result['nationality']);
        $this->assertSame('1974-08-12', $result['birth_date']);
        $this->assertSame('2012-04-15', $result['expiry_date']);
        $this->assertSame('F', $result['sex']);
        $this->assertTrue($result['valid']);
    }

    public function test_it_parses_a_valid_td1_id_card_mrz(): void
    {
        $result = (new MrzParser)->parse(
            "I<UTOD231458907<<<<<<<<<<<<<<<\n".
            "7408122F1204159UTO<<<<<<<<<<<6\n".
            'ERIKSSON<<ANNA<MARIA<<<<<<<<<<'
        );

        $this->assertNotNull($result);
        $this->assertSame('TD1', $result['format']);
        $this->assertSame('D23145890', $result['document_number']);
        $this->assertSame('ERIKSSON', $result['surname']);
        $this->assertSame('ANNA MARIA', $result['given_names']);
        $this->assertSame('1974-08-12', $result['birth_date']);
        $this->assertTrue($result['valid']);
    }

    public function test_a_broken_check_digit_is_marked_invalid_never_silently_accepted(): void
    {
        // Same TD3 as above but the document-number check digit is wrong (6 → 5).
        $result = (new MrzParser)->parse(
            "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\n".
            'L898902C35UTO7408122F1204159ZE184226B<<<<<10'
        );

        $this->assertNotNull($result);
        $this->assertFalse($result['valid']); // parsed, but the caller must NOT prefill from an invalid read
    }

    public function test_non_mrz_text_returns_null(): void
    {
        $this->assertNull((new MrzParser)->parse("just some ocr noise\nnot an mrz at all"));
        $this->assertNull((new MrzParser)->parse(''));
    }

    public function test_the_check_digit_matches_icao_9303(): void
    {
        // ICAO 9303 worked example: the document number "D23145890" has check digit 7.
        $this->assertSame(7, (new MrzParser)->checkDigit('D23145890'));
    }
}
