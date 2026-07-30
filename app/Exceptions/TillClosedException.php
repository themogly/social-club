<?php

namespace App\Exceptions;

use RuntimeException;

/** The till session is closed (immutable) — no new movements or transactions may attach. */
class TillClosedException extends RuntimeException {}
