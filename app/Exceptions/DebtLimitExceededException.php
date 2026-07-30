<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when a wallet movement would push a member's debt past the configured limit. */
class DebtLimitExceededException extends RuntimeException {}
