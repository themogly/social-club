<?php

namespace App\Exceptions;

use App\Support\StockCeiling;
use RuntimeException;

/**
 * The member already holds an active membership at this location (prompt 203).
 *
 * `EnrolMembership` created a row unconditionally — no schema constraint, no check in the Action — which was
 * survivable while its only callers were a wizard, the admin panel and an import. A counter button on a
 * tablet is none of those: a double-tap, a slow network or an impatient operator would enrol the same person
 * twice at the same sede, and the second row would then count a second time toward
 * {@see StockCeiling::forLocation()} — inflating the sede's legal stock ceiling off a UI slip.
 *
 * The guard is in the Action rather than the screen, so every caller gets it.
 */
class DuplicateMembershipException extends RuntimeException {}
