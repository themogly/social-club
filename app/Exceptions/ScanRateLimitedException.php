<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when QR-card token resolution is refused for too many FAILED scans in the window — the
 * brute-force guard on ResolveMemberByToken (prompt 58). Carries the seconds until it decays.
 */
class ScanRateLimitedException extends RuntimeException
{
    public function __construct(public readonly int $availableInSeconds = 60)
    {
        parent::__construct('Too many card scan attempts.');
    }
}
