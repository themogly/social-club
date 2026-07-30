<?php

namespace App\Exceptions;

use RuntimeException;

/** A dispensation is blocked by a door/counter check (carencia, inactive membership, …). */
class DispensationBlockedException extends RuntimeException {}
