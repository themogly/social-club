<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a consent text is edited without bumping `consent_text_version` (prompt 153/159). Reusing the
 * version would leave every already-consented member recorded against a version whose wording has silently
 * changed — the club could no longer show what anyone actually agreed to. The caller (ManageConsentText)
 * catches this and tells the editor to raise the version; nothing is written.
 */
class ConsentVersionRequiredException extends RuntimeException
{
    public function __construct(public readonly string $currentVersion)
    {
        parent::__construct("Editing consent text requires a new version (current: {$currentVersion}).");
    }
}
