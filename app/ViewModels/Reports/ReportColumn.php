<?php

namespace App\ViewModels\Reports;

use App\Support\Money;
use App\Support\Weight;
use Carbon\CarbonImmutable;

/**
 * A single report column and the ONE place a raw stored value becomes a string.
 *
 * Rows carry RAW integers (money in cents, weight in centigrams) so a totals row is
 * exact; the column decides how that raw value is shown. `display()` is the rich,
 * locale-aware edge (screen + PDF: "1.234,56 €", "3,50 g"); `export()` is the plain,
 * machine-parseable number a spreadsheet can sum ("1234,56"). Both derive from the
 * same raw value, so an exported total can never drift from the on-screen total.
 */
class ReportColumn
{
    public const TEXT = 'text';

    public const MONEY = 'money';       // stored cents

    public const WEIGHT = 'weight';     // stored centigrams

    public const NUMBER = 'number';     // plain integer count

    public const PERCENT = 'percent';   // integer percentage

    public const DATE = 'date';

    public const DATETIME = 'datetime';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = self::TEXT,
        public readonly bool $sortable = true,
        public readonly bool $total = false,
    ) {}

    public static function text(string $key, string $label, bool $sortable = true): self
    {
        return new self($key, $label, self::TEXT, $sortable);
    }

    public static function money(string $key, string $label, bool $total = true, bool $sortable = true): self
    {
        return new self($key, $label, self::MONEY, $sortable, $total);
    }

    public static function weight(string $key, string $label, bool $total = true, bool $sortable = true): self
    {
        return new self($key, $label, self::WEIGHT, $sortable, $total);
    }

    public static function number(string $key, string $label, bool $total = true, bool $sortable = true): self
    {
        return new self($key, $label, self::NUMBER, $sortable, $total);
    }

    public static function percent(string $key, string $label, bool $sortable = true): self
    {
        return new self($key, $label, self::PERCENT, $sortable, false);
    }

    public static function date(string $key, string $label, bool $sortable = true): self
    {
        return new self($key, $label, self::DATE, $sortable, false);
    }

    public static function datetime(string $key, string $label, bool $sortable = true): self
    {
        return new self($key, $label, self::DATETIME, $sortable, false);
    }

    /** Money/weight/counts sit on the right, tabular; everything else on the left. */
    public function numeric(): bool
    {
        return in_array($this->type, [self::MONEY, self::WEIGHT, self::NUMBER, self::PERCENT], true);
    }

    /** Rich, locale-aware string for the screen and the PDF. */
    public function display(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return $this->numeric() ? $this->display(0) : '—';
        }

        return match ($this->type) {
            self::MONEY => Money::fromCents((int) $raw)->formatted(),
            self::WEIGHT => Weight::fromCentigrams((int) $raw)->formatted(),
            self::NUMBER => number_format((int) $raw, 0, ',', app()->getLocale() === 'es' ? '.' : ','),
            self::PERCENT => number_format((int) $raw, 0, ',', '').' %',
            self::DATE => $this->carbon($raw)->translatedFormat('d/m/Y'),
            self::DATETIME => $this->carbon($raw)->translatedFormat('d/m/Y H:i'),
            default => (string) $raw,
        };
    }

    /**
     * Plain, spreadsheet-friendly value: a bare number (no currency symbol, no
     * thousands separators) using the locale decimal mark so it opens in Excel and
     * re-parses to the exact stored integer.
     */
    public function export(mixed $raw, string $decimal = '.'): string
    {
        if ($raw === null || $raw === '') {
            return $this->numeric() ? '0' : '';
        }

        return match ($this->type) {
            self::MONEY => number_format((int) $raw / 100, 2, $decimal, ''),
            self::WEIGHT => number_format((int) $raw / 100, 2, $decimal, ''),
            self::NUMBER, self::PERCENT => (string) (int) $raw,
            self::DATE => $this->carbon($raw)->format('Y-m-d'),
            self::DATETIME => $this->carbon($raw)->format('Y-m-d H:i'),
            default => str_replace(["\r", "\n"], ' ', (string) $raw),
        };
    }

    /** The raw value coerced to something sortable (int for numbers, lowercased string otherwise). */
    public function sortValue(mixed $raw): int|string
    {
        if ($this->numeric()) {
            return (int) $raw;
        }

        if ($this->type === self::DATE || $this->type === self::DATETIME) {
            return $raw === null || $raw === '' ? '' : $this->carbon($raw)->format('YmdHis');
        }

        return mb_strtolower((string) ($raw ?? ''));
    }

    private function carbon(mixed $raw): CarbonImmutable
    {
        return $raw instanceof CarbonImmutable ? $raw : CarbonImmutable::parse((string) $raw);
    }
}
