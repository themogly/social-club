<?php

namespace App\ViewModels;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Support\Money;
use App\Support\StockCover;
use App\Support\Units;
use App\Support\Weight;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Prompt 364 — what *Existencias* shows: every batch at the counter's sede with something in the JAR or in the sealed
 * RESERVE (zero/zero only on «Mostrar agotados»), each with its figures, its price, when it was last weighed and the
 * dispensary's own status words. Read live on every render — stock is transactional data, never cached.
 *
 * Order: strain A–Z, then lote oldest first (FEFO) — a lookup screen, found the way the jar is found on the shelf, not a
 * log (so not newest-first). The search box is the fast path.
 */
class CounterStockSheet
{
    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $all = null;

    public function __construct(public readonly Location $location) {}

    /**
     * @param  'all'|'reserve'|'low'|string  $filter
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(string $filter = 'all', bool $showEmpty = false, string $search = ''): Collection
    {
        $needle = mb_strtolower(trim($search));

        return $this->all()
            ->filter(fn (array $row): bool => $showEmpty || ! $row['empty'])
            ->filter(fn (array $row): bool => match ($filter) {
                'reserve' => $row['reserve_cg'] > 0,
                'low' => $row['jar_low'] || ($row['jar_empty'] && $row['reserve_cg'] > 0),
                default => true,
            })
            ->filter(fn (array $row): bool => $needle === '' || str_contains(mb_strtolower($row['name'].' '.$row['batch_no']), $needle))
            // Prompt 365 — the empties, when shown, come AFTER every other row (grouped under «Agotados»), not A–Z among them.
            ->sortBy(fn (array $row): int => $row['empty'] ? 1 : 0)
            ->values();
    }

    /** Prompt 365 — how many zero/zero batches the current filter and search would show on «Mostrar» (0 = no line at all). */
    public function emptyCount(string $filter = 'all', string $search = ''): int
    {
        return $this->rows($filter, true, $search)->where('empty', true)->count();
    }

    /** @return array<string, mixed>|null one batch's row (for the action panel), only if it is at this sede */
    public function row(?string $batchId): ?array
    {
        return $batchId === null ? null : $this->all()->firstWhere('id', $batchId);
    }

    /** @return array{reserve_cg: int, batches: int, text: string} «Reserva sellada en la sede: 1245.00 g en 9 lotes» */
    public function summary(): array
    {
        $withReserve = $this->all()->where('reserve_cg', '>', 0);
        $cg = (int) $withReserve->sum('reserve_cg');

        return ['reserve_cg' => $cg, 'batches' => $withReserve->count(),
            'text' => __('Reserva sellada en la sede: :grams en :count lotes', ['grams' => Weight::fromCentigrams($cg)->formatted(), 'count' => $withReserve->count()])];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function all(): Collection
    {
        if ($this->all !== null) {
            return $this->all;
        }

        $batches = Batch::query()->withoutGlobalScopes()->with('genetic')
            ->where('organisation_id', $this->location->organisation_id)->where('location_id', $this->location->id)
            ->whereNull('deleted_at')
            ->get()
            ->sortBy([
                fn (Batch $a, Batch $b): int => strcasecmp((string) $a->genetic?->name, (string) $b->genetic?->name),
                fn (Batch $a, Batch $b): int => [(string) $a->expires_on, (string) $a->acquired_or_harvested_on, $a->id] <=> [(string) $b->expires_on, (string) $b->acquired_or_harvested_on, $b->id],
            ])->values();

        $lowGenetics = $this->lowJarGenetics($batches);
        $lastCounts = $this->lastCounts($batches->pluck('id')->all());
        $reserveByGenetic = $batches->groupBy('genetic_id')->map(fn (Collection $group): int => (int) $group->sum(fn (Batch $b): int => $b->reserve_cg->centigrams));

        return $this->all = $batches->map(fn (Batch $batch): array => $this->rowFor($batch, $lowGenetics, $lastCounts, (int) ($reserveByGenetic[$batch->genetic_id] ?? 0)));
    }

    /**
     * @param  list<string>  $lowGenetics
     * @param  array<string, array{at: CarbonInterface, by: ?string}>  $lastCounts
     * @return array<string, mixed>
     */
    private function rowFor(Batch $batch, array $lowGenetics, array $lastCounts, int $strainReserve): array
    {
        $unit = $batch->isUnitType();
        $jar = $unit ? (int) ($batch->remaining_units ?? 0) : $batch->remaining_cg->centigrams;
        $reserve = $unit ? 0 : $batch->reserve_cg->centigrams;
        $jarLow = $jar > 0 && in_array($batch->genetic_id, $lowGenetics, true);

        return [
            'id' => $batch->id,
            'name' => (string) $batch->genetic?->name,
            'subtitle' => $batch->displaySubtitle(short: true),
            'batch_no' => (string) $batch->batch_no,
            'is_unit' => $unit,
            'jar' => $jar,
            'jar_text' => $unit ? Units::count($jar) : Weight::fromCentigrams($jar)->formatted(),
            'reserve_cg' => $reserve,
            'reserve_text' => Weight::fromCentigrams($reserve)->formatted(),
            'price_text' => $unit
                ? Money::fromCents((int) $batch->price_per_unit_cents)->formatted().'/'.__('ud')
                : Money::fromCents((int) $batch->price_per_gram_cents)->formatted().'/g',
            'jar_empty' => $jar === 0,
            'jar_low' => $jarLow,
            'empty' => $jar === 0 && $reserve === 0,
            // The dispensary's own words (359): a prompt to top up, not to reorder.
            'chip' => match (true) {
                $jar === 0 && $reserve > 0 => __('Bote vacío — :grams en reserva', ['grams' => Weight::fromCentigrams($reserve)->formatted()]),
                $jar === 0 => __('Agotado'),
                $jarLow && $strainReserve > 0 => __('Bote bajo, hay reserva'),
                $jarLow => __('Stock bajo'),
                default => null,
            },
            'last_count' => $this->lastCountText($lastCounts[$batch->id] ?? null),
        ];
    }

    /**
     * The strains whose JAR (all their jars here, without the reserve) is low by 216's cover verdict — the same rule the
     * dispensary's «Bote bajo, hay reserva» reads.
     *
     * @param  Collection<int, Batch>  $batches
     * @return list<string>
     */
    private function lowJarGenetics(Collection $batches): array
    {
        $weight = $batches->filter(fn (Batch $b): bool => $b->genetic instanceof Genetic && ! $b->isUnitType());
        $ids = $weight->pluck('genetic_id')->unique()->values()->all();
        if ($ids === []) {
            return [];
        }
        $trailing = StockCover::trailingCgFor($ids, $this->location->id);
        $first = StockCover::firstDispensedAtFor($ids, $this->location->id);

        return $weight->groupBy('genetic_id')->filter(function (Collection $group, string $geneticId) use ($trailing, $first): bool {
            /** @var Genetic $genetic */
            $genetic = $group->first()->genetic;
            $jarCg = (int) $group->sum(fn (Batch $b): int => $b->remaining_cg->centigrams);

            return StockCover::verdictWith($genetic, $this->location->id, $jarCg, $trailing[$geneticId] ?? 0, $first[$geneticId] ?? null)['low'];
        })->keys()->map(fn ($id): string => (string) $id)->values()->all();
    }

    /**
     * When each batch was last weighed, and by whom: a recount (`stock.recounted`, prompt 364) or a committed count line
     * (the evening close, or an Inventario). Two grouped queries for the whole list.
     *
     * @param  list<string>  $ids
     * @return array<string, array{at: CarbonInterface, by: ?string}>
     */
    private function lastCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $morph = (new Batch)->getMorphClass();
        AuditLog::query()->withoutGlobalScopes()->with('actor')->where('action', 'stock.recounted')->where('auditable_type', $morph)
            ->whereIn('auditable_id', $ids)->orderBy('created_at')->get()
            ->each(function (AuditLog $log) use (&$out): void {
                $out[(string) $log->getAttribute('auditable_id')] = ['at' => Carbon::parse($log->created_at), 'by' => $log->actor?->name];
            });

        DB::table('stock_take_lines')->join('stock_takes', 'stock_takes.id', '=', 'stock_take_lines.stock_take_id')
            ->leftJoin('users', 'users.id', '=', DB::raw('COALESCE(stock_take_lines.counted_by, stock_takes.committed_by)'))
            ->where('stock_takes.status', 'COMMITTED')->where('stock_take_lines.countable_type', $morph)
            ->whereIn('stock_take_lines.countable_id', $ids)->where('stock_take_lines.not_counted', false)
            ->get(['stock_take_lines.countable_id', DB::raw('COALESCE(stock_take_lines.counted_at, stock_takes.committed_at) as at'), 'users.name'])
            ->each(function (object $line) use (&$out): void {
                $at = Carbon::parse($line->at);
                if (! isset($out[$line->countable_id]) || $at->greaterThan($out[$line->countable_id]['at'])) {
                    $out[$line->countable_id] = ['at' => $at, 'by' => $line->name];
                }
            });

        return $out;
    }

    /** @param  array{at: CarbonInterface, by: ?string}|null  $count */
    private function lastCountText(?array $count): ?string
    {
        if ($count === null) {
            return null;
        }
        $tz = $this->location->timezone ?: 'Europe/Madrid';
        $at = $count['at']->copy()->setTimezone($tz);
        $today = now($tz);
        $when = match (true) {
            $at->isSameDay($today) => __('hoy :time', ['time' => $at->format('H:i')]),
            $at->isSameDay($today->copy()->subDay()) => __('ayer :time', ['time' => $at->format('H:i')]),
            default => $at->format('d/m H:i'),
        };

        return __('Último recuento: :when', ['when' => $when]).($count['by'] ? ' · '.$count['by'] : '');
    }
}
