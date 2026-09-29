<?php

namespace App\Support;

/**
 * Prompt 316 — THE display rule for every number a person reads, in every language: a decimal POINT and no thousands
 * separator (`1234.56`, `300.01`, `57.0`). Ben: "We wanted to use a decimal point everywhere, not commas." No grouping at
 * all: a comma or a dot in the thousands place is exactly the ambiguity being removed. OVERNIGHT-DEFAULT — CONFIRM with
 * Ben: if he would rather read `1,234.56`, the grouping is the one `''` below. Weights, money, hours and percentages all
 * format through here; spreadsheet exports keep their own Spanish-Excel rule ({@see Spreadsheet\ReportExport}).
 * Typing still accepts either separator ({@see TypedNumber}); only display changed.
 */
final class NumberFormat
{
    public static function decimal(int|float $value, int $places): string
    {
        return number_format($value, $places, '.', '');
    }
}
