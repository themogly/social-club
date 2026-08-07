<?php

namespace App\Enums;

use App\Actions\Members\RecordMemberConsent;

/**
 * HOW a consent was captured — the distinction prompt 210 had to make before it could add a staff-typed
 * sign-up route.
 *
 * The counter had exactly two ways to begin a sign-up: hand the tablet over, or email a link. Both end with
 * **the applicant themselves** ticking the two Article 9 consents in a session they controlled, and that is
 * what the club's record has always meant. A member of staff typing the form on the applicant's behalf does
 * not change the facts on it — a name and a birth date are the same facts whoever types them — but it changes
 * the consent artefact completely: it stops being a record of consent GIVEN and becomes the club's assertion
 * that it WAS. Under Article 7(1) the controller must be able to demonstrate consent, and for Article 9
 * health data the standard is explicit consent; an assertion and a tick are materially different things to
 * hold in an inspection, and it is the club that carries the difference.
 *
 * So the record says which. A consent row with no channel is an applicant tick, because that is all that
 * existed before this enum — {@see RecordMemberConsent} defaults accordingly and no
 * historical row changes meaning.
 */
enum ConsentChannel: string
{
    /** The applicant tapped it themselves, on their own device or on the handed-over tablet. */
    case APPLICANT = 'APPLICANT';

    /** Signed on paper, held by the club; a member of staff recorded it at the counter and is named on the row. */
    case PAPER = 'PAPER';

    public function label(): string
    {
        return match ($this) {
            self::APPLICANT => __('Aceptado por el socio'),
            self::PAPER => __('Consentimiento en papel'),
        };
    }

    /** Whether this channel's evidence is the applicant's own act, rather than the club's account of it. */
    public function isApplicantsOwnAct(): bool
    {
        return $this === self::APPLICANT;
    }
}
