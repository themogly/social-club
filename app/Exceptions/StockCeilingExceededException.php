<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An intake would push the premises over its legal stock ceiling and the club has set that rule to BLOCK
 * with no valid override (prompt 110). The ceiling is the compliance figure that separates a lawful CSC from
 * a trafficking case, so — unlike a member limit — it defaults to WARN, but a club may enforce it hard.
 */
class StockCeilingExceededException extends RuntimeException {}
