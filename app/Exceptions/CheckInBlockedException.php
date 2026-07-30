<?php

namespace App\Exceptions;

use RuntimeException;

/** A door check blocked the check-in and no valid override was authorised. */
class CheckInBlockedException extends RuntimeException {}
