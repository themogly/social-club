<?php

namespace App\ViewModels;

use App\Actions\Pricing\ResolvePrice;
use App\Enums\PriceList;
use App\Models\Batch;
use App\Models\Location;
use App\Support\BelowCost;
use App\Support\Money;
use App\Support\NumberFormat;
use App\Support\TypedNumber;
use App\Support\Units;

/**
 * Prompt 382 — *Precios de la sede*: one row per OPEN batch with stock at a sede (Batch::movable), its cost and its prices for
 * the three lists, in the cells the screen edits. Unit products price per unit; flower per gram and per 3.5 g.
 *
 * A cell is keyed by its column (`local_price_per_gram_cents`); its value is euros as typed («8.80»), blank = not set. A
 * blank Local / Personal cell's default (the standard less the list's default %) and a cell below cost are read from what is
 * TYPED, so the grey hint and the amber warning follow the screen before anything is saved. Live, never cached.
 */
final class SedePriceSheet
{
    /** @var list<Batch>|null */
    private ?array $batches = null;

    public function __construct(private readonly Location $location) {}

    /** @return list<Batch> */
    public function batches(): array
    {
        return $this->batches ??= Batch::query()->withoutGlobalScopes()->with('genetic')
            ->where('location_id', $this->location->id)->movable()
            ->get()
            ->sortBy(fn (Batch $b): string => mb_strtolower($b->displayTitle()).'|'.str_pad((string) $b->lote_seq, 6, '0', STR_PAD_LEFT))
            ->values()->all();
    }

    /**
     * The columns a batch's row edits, by list: [list => [kind => column]].
     *
     * @return array<string, array<string, string>>
     */
    public static function columns(Batch $batch): array
    {
        $kinds = $batch->isUnitType() ? ['unit'] : ['gram', 'eighth'];
        $out = [];
        foreach (PriceList::cases() as $list) {
            foreach ($kinds as $kind) {
                $out[$list->value][$kind] = $list->columnPrefix().'price_per_'.$kind.'_cents';
            }
        }

        return $out;
    }

    /**
     * The cells as stored, in euros («8.80»; blank = not set) — what the screen starts with.
     *
     * @return array<string, array<string, string>> batch id → column → euros
     */
    public function stored(): array
    {
        $out = [];
        foreach ($this->batches() as $batch) {
            foreach (self::columns($batch) as $kinds) {
                foreach ($kinds as $column) {
                    $cents = $batch->getRawOriginal($column);
                    $out[$batch->id][$column] = $cents === null ? '' : NumberFormat::decimal((int) $cents / 100, 2);
                }
            }
        }

        return $out;
    }

    /**
     * Every row for the screen, from what is typed: the batch, its labels, and per cell its hint and below-cost line.
     *
     * @param  array<string, array<string, string>>  $typed
     * @return list<array{id: string, batch: Batch, strain: string, label: string, stock: string, cost: ?string, unit: bool, unpriced: bool, below: bool, cells: array<string, array<string, array{column: string, hint: ?string, below: ?string, below_cost: ?string}>>}>
     */
    public function rows(array $typed): array
    {
        return array_map(fn (Batch $batch): array => $this->row($batch, $typed[$batch->id] ?? []), $this->batches());
    }

    /**
     * @param  array<string, string>  $typed
     * @return array{id: string, batch: Batch, strain: string, label: string, stock: string, cost: ?string, unit: bool, unpriced: bool, below: bool, cells: array<string, array<string, array{column: string, hint: ?string, below: ?string, below_cost: ?string}>>}
     */
    private function row(Batch $batch, array $typed): array
    {
        $unit = $batch->isUnitType();
        $cost = (int) $batch->cost_per_gram_cents;
        $gpu = $batch->genetic?->grams_per_unit_cg !== null ? (int) $batch->genetic->grams_per_unit_cg : null;
        $cells = [];
        $anyBelow = false;
        foreach (self::columns($batch) as $listValue => $kinds) {
            $list = PriceList::from($listValue);
            foreach ($kinds as $kind => $column) {
                $own = TypedNumber::cents($typed[$column] ?? null);
                $standard = TypedNumber::cents($typed['price_per_'.$kind.'_cents'] ?? null);
                $effective = $own ?? ($list === PriceList::STANDARD || $standard === null ? null : Batch::defaulted($standard, $list));
                $offence = BelowCost::forList($list, $cost,
                    $kind === 'gram' ? $effective : null, $kind === 'unit' ? $effective : null, $kind === 'eighth' ? $effective : null, $gpu)[0] ?? null;
                $anyBelow = $anyBelow || $offence !== null;
                $cells[$listValue][$kind] = [
                    'column' => $column,
                    'hint' => $own === null ? Batch::defaultHint($standard, $list) : null,
                    'below' => $offence['line'] ?? null,
                    'below_cost' => isset($offence['cost_cents']) ? Money::fromCents($offence['cost_cents'])->formatted() : null,
                ];
            }
        }

        return [
            'id' => (string) $batch->id,
            'batch' => $batch,
            'strain' => $batch->displayTitle(),
            'label' => $batch->displaySubtitle(short: true),
            'stock' => $unit ? Units::count((int) $batch->remaining_units) : $batch->remaining_cg->formatted(),
            'cost' => $cost > 0 ? Money::fromCents($cost)->formatted() : null,
            'unit' => $unit,
            'unpriced' => TypedNumber::cents($typed[$unit ? 'price_per_unit_cents' : 'price_per_gram_cents'] ?? null) === null,
            'below' => $anyBelow,
            'cells' => $cells,
        ];
    }

    /** The sede this sheet prices. */
    public function locationId(): string
    {
        return (string) $this->location->id;
    }

    /**
     * The rows after the strain search and the two filters (all read from what is typed).
     *
     * @param  array<string, array<string, string>>  $typed
     * @return list<array{id: string, batch: Batch, strain: string, label: string, stock: string, cost: ?string, unit: bool, unpriced: bool, below: bool, cells: array<string, array<string, array{column: string, hint: ?string, below: ?string, below_cost: ?string}>>}>
     */
    public function visibleRows(array $typed, string $search, bool $onlyUnpriced, bool $onlyBelowCost): array
    {
        $needle = mb_strtolower(trim($search));

        return array_values(array_filter($this->rows($typed), fn (array $row): bool => ($needle === '' || str_contains(mb_strtolower($row['strain'].' '.$row['label']), $needle))
            && (! $onlyUnpriced || $row['unpriced'])
            && (! $onlyBelowCost || $row['below'])));
    }

    /**
     * Quick fill «Local = Estándar −__ %» / «Personal = Estándar −__ %» on these batches: the typed cells, filled.
     *
     * @param  array<string, array<string, string>>  $typed
     * @param  list<string>  $batchIds
     * @return array<string, array<string, string>>
     */
    public function filledFromStandard(array $typed, array $batchIds, PriceList $list, float $percent): array
    {
        foreach ($this->batchesIn($batchIds) as $batch) {
            foreach (self::columns($batch)[$list->value] as $kind => $column) {
                $standard = TypedNumber::cents($typed[$batch->id]['price_per_'.$kind.'_cents'] ?? null);
                if ($standard !== null) {
                    $typed[$batch->id][$column] = NumberFormat::decimal(Batch::lessPercent($standard, $percent) / 100, 2);
                }
            }
        }

        return $typed;
    }

    /**
     * Quick fill «Vaciar Local / Personal» on these batches: back to the defaults.
     *
     * @param  array<string, array<string, string>>  $typed
     * @param  list<string>  $batchIds
     * @return array<string, array<string, string>>
     */
    public function cleared(array $typed, array $batchIds): array
    {
        foreach ($this->batchesIn($batchIds) as $batch) {
            foreach ([PriceList::LOCAL, PriceList::STAFF] as $list) {
                foreach (self::columns($batch)[$list->value] as $column) {
                    $typed[$batch->id][$column] = '';
                }
            }
        }

        return $typed;
    }

    /**
     * Quick fill «Copiar precios de otra sede» on these batches: for the same strain, that sede's current batch's prices.
     *
     * @param  array<string, array<string, string>>  $typed
     * @param  list<string>  $batchIds
     * @return array{0: array<string, array<string, string>>, 1: int} the typed cells, and how many batches were filled
     */
    public function copiedFrom(array $typed, array $batchIds, Location $other): array
    {
        $theirs = $this->pricesAt($other);
        $copied = 0;
        foreach ($this->batchesIn($batchIds) as $batch) {
            $from = $theirs[$batch->genetic_id] ?? null;
            if ($from === null) {
                continue;
            }
            foreach (self::columns($batch) as $kinds) {
                foreach ($kinds as $column) {
                    $typed[$batch->id][$column] = $from[$column] ?? '';
                }
            }
            $copied++;
        }

        return [$typed, $copied];
    }

    /**
     * @param  list<string>  $batchIds
     * @return list<Batch>
     */
    private function batchesIn(array $batchIds): array
    {
        return array_values(array_filter($this->batches(), fn (Batch $batch): bool => in_array((string) $batch->id, $batchIds, true)));
    }

    /**
     * Prompt 382 «Copiar precios de otra sede»: for each strain on this sheet, that sede's current batch's prices — the one
     * its counter shows ({@see ResolvePrice::displayBatch()}) — as euros by column. Strains it has no priced batch of are
     * left out.
     *
     * @return array<string, array<string, string>> genetic id → column → euros
     */
    public function pricesAt(Location $other): array
    {
        $resolver = new ResolvePrice;
        $out = [];
        foreach ($this->batches() as $batch) {
            $genetic = $batch->genetic;
            if ($genetic === null || isset($out[$genetic->id])) {
                continue;
            }
            $current = $resolver->displayBatch($genetic, $other);
            if ($current !== null && $current->hasOwnPrice()) {
                $out[$genetic->id] = array_map(fn (?int $c): string => $c === null ? '' : NumberFormat::decimal($c / 100, 2), $current->storedPrices());
            }
        }

        return $out;
    }
}
