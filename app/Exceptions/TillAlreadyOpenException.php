<?php

namespace App\Exceptions;

use RuntimeException;

/** A till session is already open on this terminal — only one may be open at a time. */
class TillAlreadyOpenException extends RuntimeException {}
