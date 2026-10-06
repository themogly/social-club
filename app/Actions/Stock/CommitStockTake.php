<?php

namespace App\Actions\Stock;

use App\Actions\RecordAuditLog;
use App\Enums\StockCountReason;
use App\Enums\StockMovementType;
use App\Enums\StockTakeStatus;
use App\Models\Article;
use App\Models\Batch;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\User;
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
     * @param  list<Count>  $counts
     */
    public function handle(StockTake $stockTake, array $counts, User $committer): StockTake
    {
        return DB::transaction(function () use ($stockTake, $counts, $committer): StockTake {
            $recorder = new RecordStockMovement;

            foreach ($counts as $count) {
                // "Not counted": record the omission and its reason, touch NOTHING in the ledger.
                if (($count['not_counted'] ?? false) === true) {
                    $class = $count['type'] === 'batch' ? Batch::class : Article::class;
                    $stockTake->lines()->create([
                        'countable_type' => $class, 'countable_id' => $count['id'],
                        'not_counted' => true, 'not_counted_reason' => $count['reason'] ?? null,
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

                    // A forgotten «Rellenar»: a bag opened into the jar with nobody tapping it. The jar counts OVER while the
                    // reserve still holds the bag — absorb the surplus, up to the reserve, as the top-up it was (no stock is
                    // created); only what is beyond the reserve is an adjustment. A jar counted UNDER is a real shortfall,
                    // and the reserve is never touched to cover it. Flagged for the manager, never asked of staff.
                    $absorbed = $variance > 0 ? min($variance, $batch->reserve_cg->centigrams) : 0;
                    if ($absorbed > 0) {
                        $options = ['stock_take_id' => $stockTake->id, 'reason' => 'Rellenado sin registrar', 'operator_id' => $committer->id];
                        $recorder->handle($batch, StockMovementType::RESERVE_OUT, -$absorbed, $options + ['reserve' => true]);
                        $recorder->handle($batch, StockMovementType::RESERVE_OUT, $absorbed, $options);
                        (new RecordAuditLog)->handle('stock.topped_up_unrecorded', $batch, null, ['cg' => $absorbed, 'stock_take_id' => $stockTake->id]);
                    }
                    $variance -= $absorbed;

                    $stockTake->lines()->create([
                        'countable_type' => Batch::class, 'countable_id' => $batch->id,
                        'counted_cg' => $counted, 'expected_cg' => $expected, 'variance_cg' => $variance,
                        'unrecorded_topup_cg' => $absorbed > 0 ? $absorbed : null,
                    ]);

                    if ($variance !== 0) {
                        $recorder->handle($batch, StockMovementType::ADJUSTMENT, $variance, [
                            'stock_take_id' => $stockTake->id, 'reason' => 'Recuento de inventario', 'operator_id' => $committer->id,
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
                            'stock_take_id' => $stockTake->id, 'reason' => 'Recuento de inventario', 'operator_id' => $committer->id,
                        ]);
                    }
                }
            }

            $stockTake->update([
                'status' => StockTakeStatus::COMMITTED,
                'committed_by' => $committer->id,
                'committed_at' => now(),
            ]);

            (new RecordAuditLog)->handle('stocktake.committed', $stockTake, null, ['lines' => count($counts)]);

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
     * @param  array<string, array{reason?: ?string, note?: ?string}>  $reasons  keyed by line id (merged onto the lines)
     *
     * @throws AuthorizationException|DomainException
     */
    public function applyCount(StockTake $stockTake, User $committer, array $reasons): StockTake
    {
        if (! $committer->can('stock.take')) {
            throw new AuthorizationException(__('No tienes permiso para hacer inventarios.'));
        }

        return DB::transaction(function () use ($stockTake, $committer, $reasons): StockTake {
            $take = StockTake::query()->withoutGlobalScopes()->whereKey($stockTake->id)->lockForUpdate()->firstOrFail();
            if (! $take->isOpen() || $take->kind !== StockTake::KIND_INVENTORY) {
                throw new DomainException(__('Este inventario ya no está abierto.'));
            }

            $lines = $take->lines()->with('countable')->get();
            if ($lines->contains(fn (StockTakeLine $line): bool => ! $line->isSettled())) {
                throw new DomainException(__('Quedan líneas sin contar: cuéntalas o márcalas como «No contado».'));
            }

            foreach ($lines as $line) {
                $given = $reasons[$line->id] ?? [];
                $reason = StockCountReason::tryFrom((string) ($given['reason'] ?? '')) ?? $line->adjustment_reason;
                $note = trim((string) ($given['note'] ?? $line->adjustment_note ?? ''));
                if (self::needsReason($line) && ($reason === null || $note === '')) {
                    throw new DomainException(__('La diferencia de :item supera la tolerancia: indica un motivo y una nota.', ['item' => self::itemName($line)]));
                }
                $line->forceFill(['adjustment_reason' => $line->difference() ? $reason : null, 'adjustment_note' => $line->difference() ? ($note ?: null) : null]);
            }

            $recorder = new RecordStockMovement;
            $totals = ['lines' => $lines->count(), 'adjusted' => 0, 'not_counted' => 0, 'net_cg' => 0, 'net_units' => 0, 'net_value_cents' => 0];
            foreach ($lines as $line) {
                if ($line->not_counted) {
                    $totals['not_counted']++;
                    $line->save();

                    continue;
                }

                $difference = (int) $line->difference();
                $line->forceFill($line->isUnit() ? ['variance_units' => $difference] : ['variance_cg' => $difference])->save();
                if ($difference === 0) {
                    continue;
                }

                /** @var Batch|Article $item */
                $item = $line->countable;
                $label = $line->adjustment_reason?->label();
                $recorder->handle($item, StockMovementType::ADJUSTMENT, $difference, [
                    'reserve' => (bool) $line->reserve, // prompt 359 — «Reserva sellada» adjusts the reserve only
                    'stock_take_id' => $take->id, 'operator_id' => $committer->id, 'actor' => $committer,
                    'reason' => trim(__('Inventario').($label !== null ? ' — '.$label : '').($line->adjustment_note ? ': '.$line->adjustment_note : '')),
                ]);
                $totals['adjusted']++;
                $totals[$line->isUnit() ? 'net_units' : 'net_cg'] += $difference;
                $totals['net_value_cents'] += self::valueCents($line);
            }

            $take->update(['status' => StockTakeStatus::COMMITTED, 'committed_by' => $committer->id, 'committed_at' => now()]);
            (new RecordAuditLog)->handle('stock_take.committed', $take, null, $totals + ['location_id' => $take->location_id]);

            return $take;
        });
    }

    /**
     * Above the tolerance a difference needs a reason and a note: 5 % of the expected quantity or 2 g (2 units for a
     * product or a unit batch), whichever is larger — both Settings at the sede (OVERNIGHT-DEFAULT — CONFIRM).
     */
    public static function needsReason(StockTakeLine $line): bool
    {
        $difference = $line->difference();
        if ($difference === null || $difference === 0) {
            return false;
        }

        $locationId = $line->stockTake?->location_id;
        $pct = (float) Settings::get('stock_count_tolerance_pct', 5, $locationId);
        $expected = $line->isUnit() ? (int) $line->expected_units : (int) $line->expected_cg?->centigrams;
        $floor = $line->isUnit()
            ? (int) Settings::get('stock_count_tolerance_units', 2, $locationId)
            : (int) round((float) Settings::get('stock_count_tolerance_g', 2, $locationId) * 100);

        return abs($difference) > max($floor, $expected * $pct / 100);
    }

    /** A line's difference valued at its contribution rate (a batch's per-gram / per-unit rate, a product's price), in cents. */
    public static function valueCents(StockTakeLine $line): int
    {
        $difference = (int) $line->difference();
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
