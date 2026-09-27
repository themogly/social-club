<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The stock a line asks for is not there to take — not enough across the sede's dispensable lotes, or a chosen lote that
 * is not this product's at this sede (prompt 273). Its message is translated and names the product and what is left,
 * so the counter SHOWS it; as a plain RuntimeException it was swallowed into "No se pudo registrar la dispensación".
 */
class StockUnavailableException extends RuntimeException {}
