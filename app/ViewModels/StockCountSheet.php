<?php

namespace App\ViewModels;

use App\Actions\Stock\CommitStockTake;
use App\Models\Article;
use App\Models\Batch;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Support\Money;
use App\Support\NumberFormat;
use App\Support\Settings;
use App\Support\StockCeiling;
use App\Support\Weight;

/**
 * Prompt 318 — one *Inventario* as a person reads it: its lines (grouped by type, with what was counted, by whom and
 * when), the differences sorted by size, and the totals. The counting screen, the review and the PDF report all read
 * this, so they cannot disagree. Every figure is from the line's own snapshot (expected WHEN counted), never "now".
 */
class StockCountSheet
{
    /** @var list<array<string, mixed>> */
    private array $rows;

    public function __construct(public readonly StockTake $take)
    {
        $take->loadMissing(['location', 'openedBy', 'committedBy', 'lines.countable', 'lines.countedBy']);
        $take->lines->each(fn (StockTakeLine $line) => $line->setRelation('stockTake', $take));
        $this->rows = $take->lines->map(fn (StockTakeLine $line): array => $this->row($line))
            ->sortBy([['group', 'asc'], ['name', 'asc']])->values()->all();
    }

    /**
     * Prompt 360 — a count never stops at the premises ceiling (it records what is there, it is not intake), but the review
     * says so BEFORE «Aplicar ajustes» when the counted result would put the sede over it. Null when it would not.
     */
    public function ceilingWarning(): ?string
    {
        $location = $this->take->location;
        if ($location === null || $location->isStore() || ! $this->take->isOpen()) {
            return null;
        }
        $ceiling = StockCeiling::forLocation($location);
        $totals = $this->totals();
        $projected = $ceiling['on_site_cg'] + $totals['net_cg'] + $totals['net_reserve_cg'];

        return $projected > $ceiling['ceiling_cg']
            ? __('Con estos ajustes la sede tendrá :projected, por encima de su techo de :ceiling. El recuento se aplica igualmente (refleja lo que hay); el panel mostrará el aviso del techo.', [
                'projected' => Weight::fromCentigrams($projected)->formatted(), 'ceiling' => Weight::fromCentigrams($ceiling['ceiling_cg'])->formatted(),
            ])
            : null;
    }

    /** Whether the person counting sees the system quantity (the sede's setting; off = blind). */
    public function showsExpected(): bool
    {
        return (bool) Settings::get('stock_count_show_expected', false, $this->take->location_id);
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @return array<string, list<array<string, mixed>>> the lines grouped by type (Flor, Hachís… Productos) */
    public function groups(): array
    {
        return collect($this->rows)->groupBy('group')->map(fn ($rows): array => $rows->values()->all())->all();
    }

    /** @return list<array<string, mixed>> the lines with a difference (jar or reserve), the largest (in € then in quantity) first */
    public function differences(): array
    {
        return collect($this->rows)->filter(fn (array $row): bool => (int) $row['difference'] !== 0 || (int) $row['reserve_difference'] !== 0)
            ->sortBy([fn (array $a, array $b): int => abs($b['value_cents']) <=> abs($a['value_cents']), fn (array $a, array $b): int => abs($b['difference_cg']) <=> abs($a['difference_cg'])])
            ->values()->all();
    }

    /** @return array{lines: int, settled: int, pending: int, not_counted: int, differences: int, net_cg: int, net_reserve_cg: int, net_units: int, net_value_cents: int, net_weight: string, net_reserve_weight: string, net_units_text: string, net_value: string} */
    public function totals(): array
    {
        $rows = collect($this->rows);
        $netCg = (int) $rows->where('unit', false)->sum('difference');
        $netReserveCg = (int) $rows->sum('reserve_difference');
        $netUnits = (int) $rows->where('unit', true)->sum('difference');
        $value = (int) $rows->sum('value_cents');

        return [
            'lines' => $rows->count(),
            'settled' => $rows->where('settled', true)->count(),
            // An optional row («Incluir lotes a cero») left blank is skipped, never "pending" (prompt 360).
            'pending' => $rows->where('settled', false)->where('optional', false)->count(),
            'not_counted' => $rows->where('not_counted', true)->count(),
            'differences' => $rows->filter(fn (array $row): bool => (int) $row['difference'] !== 0 || (int) $row['reserve_difference'] !== 0)->count(),
            'net_cg' => $netCg,
            'net_reserve_cg' => $netReserveCg,
            'net_units' => $netUnits,
            'net_value_cents' => $value,
            'net_weight' => self::signedWeight($netCg),
            'net_reserve_weight' => self::signedWeight($netReserveCg),
            'net_units_text' => self::signedUnits($netUnits),
            'net_value' => ($value > 0 ? '+' : '').Money::fromCents($value)->formatted(),
        ];
    }

    public static function signedWeight(int $cg): string
    {
        return ($cg > 0 ? '+' : '').Weight::fromCentigrams($cg)->formatted();
    }

    public static function signedUnits(int $units): string
    {
        return ($units > 0 ? '+' : '').$units.' '.__('ud.');
    }

    /** @return array<string, mixed> */
    private function row(StockTakeLine $line): array
    {
        $item = $line->countable;
        $unit = $line->isUnit();
        $difference = $line->difference();
        $differs = (bool) $difference || (bool) $line->reserveDifference();
        $quantity = fn (?int $cg, ?int $units): ?string => $unit
            ? ($units === null ? null : $units.' '.__('ud.'))
            : ($cg === null ? null : Weight::fromCentigrams($cg)->formatted());

        return [
            'id' => $line->id,
            'name' => CommitStockTake::itemName($line),
            'reference' => $item instanceof Batch ? (string) $item->batch_no : null,
            'group' => match (true) {
                $item instanceof Article => __('Productos'),
                $item instanceof Batch => (string) ($item->genetic?->product_type?->label() ?? __('Lotes')),
                default => '—',
            },
            'unit' => $unit,
            'settled' => $line->isSettled(),
            'not_counted' => $line->not_counted,
            'not_counted_reason' => $line->not_counted_reason,
            'expected' => $quantity($line->expected_cg?->centigrams, $line->expected_units),
            'current' => $quantity($item instanceof Batch && ! $unit ? $item->remaining_cg->centigrams : null,
                $item instanceof Article ? (int) $item->stock : ($item instanceof Batch && $unit ? (int) $item->remaining_units : null)),
            'counted' => $quantity($line->counted_cg?->centigrams, $line->counted_units),
            'counted_input' => $line->counted_at === null ? '' : ($unit ? (string) $line->counted_units
                : ($line->counted_cg === null ? '' : NumberFormat::decimal($line->counted_cg->centigrams / 100, 2))),
            // Prompt 360 — the sealed reserve, on the same row (weight batches only; blank = not counted, untouched).
            'counts_reserve' => $line->countsReserve(),
            'optional' => (bool) $line->optional,
            'expected_reserve' => $line->expected_reserve_cg === null ? null : $line->expected_reserve_cg->formatted(),
            'current_reserve' => $item instanceof Batch && ! $unit ? $item->reserve_cg->formatted() : null,
            'counted_reserve' => $line->counted_reserve_cg?->formatted(),
            'counted_reserve_input' => $line->counted_at === null || $line->counted_reserve_cg === null ? '' : NumberFormat::decimal($line->counted_reserve_cg->centigrams / 100, 2),
            'reserve_difference' => (int) $line->reserveDifference(),
            'reserve_difference_text' => $line->reserveDifference() === null ? '—' : self::signedWeight((int) $line->reserveDifference()),
            'difference' => (int) $difference,
            'difference_cg' => $unit ? 0 : (int) $difference,
            'difference_text' => $difference === null ? '—' : ($unit ? self::signedUnits($difference) : self::signedWeight($difference)),
            'value_cents' => $differs ? CommitStockTake::valueCents($line) : 0,
            'value_text' => $differs ? (CommitStockTake::valueCents($line) > 0 ? '+' : '').Money::fromCents(CommitStockTake::valueCents($line))->formatted() : '—',
            'needs_reason' => CommitStockTake::needsReason($line),
            'reason' => $line->adjustment_reason?->value,
            'reason_label' => $line->adjustment_reason?->label(),
            'note' => $line->adjustment_note,
            'counted_by' => $line->countedBy?->name,
            'counted_at' => $line->counted_at !== null ? local_datetime($line->counted_at, 'd/m H:i', $this->take->location) : null,
        ];
    }
}
