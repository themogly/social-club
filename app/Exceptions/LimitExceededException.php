<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A dispensation would breach a daily or monthly consumption limit and no valid
 * override was authorised. The message states the rule, the figure and what remains.
 */
class LimitExceededException extends RuntimeException {}
