<?php

namespace App\Actions\Stock;

use App\Actions\RecordAuditLog;
use App\Enums\BatchStatus;
use App\Enums\StockCountReason;
use App\Enums\StockMovementType;
use App\Enums\StockTakeStatus;
use App\Models\Article;
use App\Models\Batch;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\User;
use App\Support\ManagerApproval;
use App\Support\Settings;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Commit an inventory count: for each counted batch/article, record the variance as
 * a StockTakeLine and — when non-zero — an ADJUSTMENT movement (the count as the
 * reference) so the ledger reconciles to the counted figure. Never a silent
 * overwrite; permissioned by the caller and audited.
 *
 * A count entry may instead be flagged `not_counted` (prompt 91): the omission is recorded as a line with
 * its reason, and the batch's stock is left UNTOUCHED — NO variance, NO adjustment, NO merma. It is not a
 * count of zero (that is `counted: 0`); it is "could not weigh this jar", surfaced for a manager to chase.
 *
 * @phpstan-type Count array{type: 'batch'|'article', id: string, counted?: int, not_counted?: bool, reason?: ?string}
 */
class CommitStockTake
{
    /**
     * Prompt 360 — `$reason` is the end-of-day weigh's ONE answer, asked only when the count is off
     * ({@see self::closeCountIsOff()}): stored on the take, and copied onto every adjustment (instead of the bare «Recuento
     * de inventario») and onto every «No contado» line that has no reason of its own.
     *
     * @param  list<Count>  $counts
     */
    public function handle(StockTake $stockTake, array $counts, User $committer, ?string $reason = null): StockTake
    {
        $reason = trim((string) $reason) ?: null;

        return DB::transaction(function () use ($stockTake, $counts, $committer, $reason): StockTake {
            $recorder = new RecordStockMovement;
            if ($reason !== null) {
                $stockTake->update(['reason' => mb_substr($reason, 0, 255)]);
            }

            foreach ($counts as $count) {
                // "Not counted": record the omission and its reason, touch NOTHING in the ledger.
                if (($count['not_counted'] ?? false) === true) {
                    $class = $count['type'] === 'batch' ? Batch::class : Article::class;
                    $stockTake->lines()->create([
                        'countable_type' => $class, 'countable_id' => $count['id'],
                        'not_counted' => true, 'not_counted_reason' => ($count['reason'] ?? null) ?: $reason,
                    ]);

                    continue;
                }

                $counted = (int) ($count['counted'] ?? 0);

                if ($count['type'] === 'batch') {
                    $batch = Batch::withoutGlobalScopes()->findOrFail($count['id']);
                    // Prompt 359 — the JAR is what was weighed, so the jar is what is expected: the sealed bags in the back
                    // are never a "shortfall" to adjust out of stock.
                    $expected = $batch->remaining_cg->centigrams;
                    $variance = $counted - $expected;

                    // A forgotten «Rellenar» (359): a jar counted OVER while the reserve still holds the bag is the top-up it
                    // was — absorbed up to the reserve, never created. One rule, shared with RecountBatch (prompt 364).
                    $absorbed = (new AbsorbUnrecordedTopUp)->handle($batch, $variance, ['stock_take_id' => $stockTake->id, 'operator_id' => $committer->id]);
                    $variance -= $absorbed;

                    $stockTake->lines()->create([
                        'countable_type' => Batch::class, 'countable_id' => $batch->id,
                        'counted_cg' => $counted, 'expected_cg' => $expected, 'variance_cg' => $variance,
                        'unrecorded_topup_cg' => $absorbed > 0 ? $absorbed : null,
                    ]);

                    if ($variance !== 0) {
                        $recorder->handle($batch, StockMovementType::ADJUSTMENT, $variance, [
                            'stock_take_id' => $stockTake->id, 'reason' => $reason ?? 'Recuento de inventario', 'operator_id' => $committer->id,
                        ]);
                    }
                } else {
                    $article = Article::withoutGlobalScopes()->findOrFail($count['id']);
                    $expected = $article->stock;
                    $variance = $counted - $expected;

                    $stockTake->lines()->create([
                        'countable_type' => Article::class, 'countable_id' => $article->id,
                        'counted_units' => $counted, 'expected_units' => $expected, 'variance_units' => $variance,
                    ]);

                    if ($variance !== 0) {
                        $recorder->handle($article, StockMovementType::ADJUSTMENT, $variance, [
                            'stock_take_id' => $stockTake->id, 'reason' => $reason ?? 'Recuento de inventario', 'operator_id' => $committer->id,
                        ]);
                    }
                }
            }

            $stockTake->update([
                'status' => StockTakeStatus::COMMITTED,
                'committed_by' => $committer->id,
                'committed_at' => now(),
            ]);

            (new RecordAuditLog)->handle('stocktake.committed', $stockTake, null, array_filter(['lines' => count($counts), 'reason' => $reason]));

            return $stockTake;
        });
    }

    /**
     * Prompt 318 — *Aplicar ajustes* for an *Inventario* (a full count whose lines were saved one by one by
     * {@see RecordStockCountLine}). The difference applied is each line's `counted − expected-when-counted` — NOT
     * `counted − now` — so a sale made after the line was counted is never counted twice. One transaction: one ADJUSTMENT
     * per non-zero line, carrying its reason; *No contado* lines touch nothing; the count is COMMITTED with who and when,
     * and audited with its totals. Refused while any line is unsettled, or while a difference above the tolerance has
     * no reason and note ({@see self::needsReason()}).
     *
     * Prompt 360 — a weight batch's row carries the JAR and the SEALED RESERVE: each difference is its own ADJUSTMENT, the
     * reserve's marked `on_reserve` (the movement's recorded bucket — one ADJUSTMENT type, two figures). A blank figure is
     * untouched; an optional row («Incluir lotes a cero») left blank is skipped. `$sharedReason` («Usar un motivo para todas
     * las diferencias») stands in for every row with no reason of its own and needs no note; a row's own reason wins. A
     * holder of `reasons.optional` (356) still picks a reason but is never made to type a note. A batch corrected upward from nothing, CLOSED, is
     * reopened so the counter can sell it. The premises ceiling is never checked: a count records what is there.
     *
     * @param  array<string, array{reason?: ?string, note?: ?string}>  $reasons  keyed by line id (merged onto the lines)
     *
     * @throws AuthorizationException|DomainException
     */
    public function applyCount(StockTake $stockTake, User $committer, array $reasons, ?StockCountReason $sharedReason = null): StockTake
    {
        if (! $committer->can('stock.take')) {
            throw new AuthorizationException(__('No tienes permiso para hacer inventarios.'));
        }
        $noteOptional = ManagerApproval::allows($committer);

        return DB::transaction(function () use ($stockTake, $committer, $reasons, $sharedReason, $noteOptional): StockTake {
            $take = StockTake::query()->withoutGlobalScopes()->whereKey($stockTake->id)->lockForUpdate()->firstOrFail();
            if (! $take->isOpen() || $take->kind !== StockTake::KIND_INVENTORY) {
                throw new DomainException(__('Este inventario ya no está abierto.'));
            }

            $lines = $take->lines()->with('countable')->get()->reject(fn (StockTakeLine $line): bool => $line->isSkippable())->values();
            if ($lines->contains(fn (StockTakeLine $line): bool => ! $line->isSettled())) {
                throw new DomainException(__('Quedan líneas sin contar: cuéntalas o márcalas como «No contado».'));
            }

            foreach ($lines as $line) {
                $given = $reasons[$line->id] ?? [];
                $own = StockCountReason::tryFrom((string) ($given['reason'] ?? '')) ?? $line->adjustment_reason;
                $reason = $own ?? $sharedReason;
                $note = trim((string) ($given['note'] ?? $line->adjustment_note ?? ''));
                // A note is asked only for a row's OWN reason: the shared one is the explanation, and 356 waives it.
                $noteMissing = $note === '' && $own !== null && ! $noteOptional;
                if (self::needsReason($line) && ($reason === null || $noteMissing)) {
                    throw new DomainException(__('La diferencia de :item supera la tolerancia: indica un motivo y una nota.', ['item' => self::itemName($line)]));
                }
                $differs = (bool) $line->difference() || (bool) $line->reserveDifference();
                $line->forceFill(['adjustment_reason' => $differs ? $reason : null, 'adjustment_note' => $differs ? ($note ?: null) : null]);
            }

            $recorder = new RecordStockMovement;
            $totals = ['lines' => $lines->count(), 'adjusted' => 0, 'not_counted' => 0, 'net_cg' => 0, 'net_reserve_cg' => 0, 'net_units' => 0, 'net_value_cents' => 0];
            foreach ($lines as $line) {
                if ($line->not_counted) {
                    $totals['not_counted']++;
                    $line->save();

                    continue;
                }

                $difference = $line->difference();
                $reserveDifference = $line->reserveDifference();
                $line->forceFill($line->isUnit() ? ['variance_units' => (int) $difference]
                    : ['variance_cg' => $difference, 'variance_reserve_cg' => $reserveDifference])->save();

                /** @var Batch|Article $item */
                $item = $line->countable;
                $label = $line->adjustment_reason?->label();
                $options = [
                    'stock_take_id' => $take->id, 'operator_id' => $committer->id, 'actor' => $committer,
                    'reason' => trim(__('Inventario').($label !== null ? ' — '.$label : '').($line->adjustment_note ? ': '.$line->adjustment_note : '')),
                ];
                foreach ([[$difference, false], [$reserveDifference, true]] as [$delta, $onReserve]) {
                    if ((int) $delta === 0) {
                        continue;
                    }
                    $recorder->handle($item, StockMovementType::ADJUSTMENT, (int) $delta, $options + ['reserve' => $onReserve]);
                    $totals[$line->isUnit() ? 'net_units' : ($onReserve ? 'net_reserve_cg' : 'net_cg')] += (int) $delta;
                }
                if ((int) $difference !== 0 || (int) $reserveDifference !== 0) {
                    $totals['adjusted']++;
                    $totals['net_value_cents'] += self::valueCents($line);
                    self::reopenIfRestocked($item);
                }
            }

            $take->update(['status' => StockTakeStatus::COMMITTED, 'committed_by' => $committer->id, 'committed_at' => now()]);
            (new RecordAuditLog)->handle('stock_take.committed', $take, null, $totals + ['location_id' => $take->location_id, 'shared_reason' => $sharedReason?->value]);

            return $take;
        });
    }

    /**
     * Above the tolerance a difference needs a reason and a note: 5 % of the expected quantity or 2 g (2 units for a
     * product or a unit batch), whichever is larger — both Settings at the sede (OVERNIGHT-DEFAULT — CONFIRM).
     */
    public static function needsReason(StockTakeLine $line): bool
    {
        $locationId = $line->stockTake?->location_id;
        $unit = $line->isUnit();

        // Prompt 360 — the jar and the sealed reserve each against their own expected figure.
        return self::beyondTolerance((int) $line->difference(), $unit ? (int) $line->expected_units : (int) $line->expected_cg?->centigrams, $unit, $locationId)
            || self::beyondTolerance((int) $line->reserveDifference(), (int) $line->expected_reserve_cg?->centigrams, false, $locationId);
    }

    /** The one tolerance (prompt 318), shared by Inventario's reasons and the end-of-day weigh's question (prompt 360). */
    public static function beyondTolerance(int $difference, int $expected, bool $unit, ?string $locationId): bool
    {
        if ($difference === 0) {
            return false;
        }

        $pct = (float) Settings::get('stock_count_tolerance_pct', 5, $locationId);
        $floor = $unit
            ? (int) Settings::get('stock_count_tolerance_units', 2, $locationId)
            : (int) round((float) Settings::get('stock_count_tolerance_g', 2, $locationId) * 100);

        return abs($difference) > max($floor, $expected * $pct / 100);
    }

    /**
     * Prompt 360 — is the end-of-day weigh OFF, so staff are asked one reason before it commits? A jar «No contado», or a
     * jar whose difference is beyond the tolerance AFTER a forgotten «Rellenar» has been absorbed by its reserve (359): a
     * surplus up to the reserve is not "off". Read from the same figures {@see self::handle()} will apply.
     *
     * @param  list<Count>  $counts
     */
    public static function closeCountIsOff(array $counts, ?string $locationId): bool
    {
        foreach ($counts as $count) {
            if (($count['not_counted'] ?? false) === true) {
                return true;
            }
            if ($count['type'] !== 'batch') {
                continue;
            }
            $batch = Batch::query()->withoutGlobalScopes()->find($count['id']);
            if ($batch === null) {
                continue;
            }
            $expected = $batch->remaining_cg->centigrams;
            $variance = (int) ($count['counted'] ?? 0) - $expected;
            $variance -= $variance > 0 ? min($variance, $batch->reserve_cg->centigrams) : 0;
            if (self::beyondTolerance($variance, $expected, false, $locationId)) {
                return true;
            }
        }

        return false;
    }

    /** Prompt 360 — a batch corrected back above nothing, CLOSED meanwhile, is reopened so the counter can sell it. */
    private static function reopenIfRestocked(Batch|Article $item): void
    {
        if (! $item instanceof Batch) {
            return;
        }
        $fresh = $item->fresh();
        if ($fresh !== null && $fresh->status === BatchStatus::CLOSED && ($fresh->remaining_cg->centigrams > 0 || (int) $fresh->remaining_units > 0)) {
            $fresh->update(['status' => BatchStatus::OPEN]);
            (new RecordAuditLog)->handle('batch.reopened', $fresh, ['status' => BatchStatus::CLOSED->value], ['status' => BatchStatus::OPEN->value, 'reason' => 'stock_take']);
        }
    }

    /** A line's difference valued at its contribution rate (a batch's per-gram / per-unit rate, a product's price), in cents. */
    public static function valueCents(StockTakeLine $line): int
    {
        $difference = (int) $line->difference() + (int) $line->reserveDifference(); // 360 — the reserve is stock too
        $item = $line->countable;

        return match (true) {
            $item instanceof Article => $difference * $item->price_cents->cents,
            $item instanceof Batch && $item->isUnitType() => $difference * (int) $item->price_per_unit_cents,
            $item instanceof Batch => (int) round_half_up($difference * (int) $item->price_per_gram_cents / 100),
            default => 0,
        };
    }

    /**
     * The line's name: a batch as strain · lote · day in (the SHORT subtitle — the long one carries the intake
     * quantity, and a blind count shows no quantity at all), a product by its name.
     */
    public static function itemName(StockTakeLine $line): string
    {
        $item = $line->countable;

        return match (true) {
            $item instanceof Batch => implode(' · ', array_filter([$item->displayTitle(), $item->displaySubtitle(short: true)], 'filled')),
            $item instanceof Article => (string) $item->name,
            default => '—',
        };
    }
}
