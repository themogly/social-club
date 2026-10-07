<?php

namespace App\Actions\Stock;

use App\Models\Article;
use App\Models\Batch;
use App\Models\StockTakeLine;
use App\Models\User;
use App\Support\TypedNumber;
use App\Support\Weight;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Prompt 318 — one line of an *Inventario*, saved as it is entered (a count can be paused and resumed).
 *
 * THE RULE: the line's expected quantity is the batch's or product's quantity AT THIS MOMENT, read with a row lock, and
 * stored with `counted_at` and who counted it. The difference applied later is `counted − expected-at-count-time`, which
 * is right whatever is sold before or after: a batch at 100 g counted at 98 g, then 5 g sold, is adjusted by −2 g and
 * ends at 93 g — not −7 g (the sale counted twice), not 98 g (the sale undone). Recounting overwrites both.
 *
 * `$typed` is grams for a weighed batch (either decimal separator), whole units for a unit batch or a product; null with a
 * `$notCountedReason` marks the line *No contado* (the existing columns).
 *
 * Prompt 360 — a weight batch's row also takes `$reserve`, its SEALED reserve («Reserva sellada»), snapshotted the same way
 * against `reserve_cg`. Either figure may be blank: that figure was not counted and is left untouched (so the owner can
 * correct only the bags, or only the jar). Both blank is still *No contado*, with its reason.
 */
class RecordStockCountLine
{
    /** @throws AuthorizationException|DomainException */
    public function handle(StockTakeLine $line, ?string $typed, User $actor, ?string $notCountedReason = null, ?string $reserve = null): StockTakeLine
    {
        if (! $actor->can('stock.take')) {
            throw new AuthorizationException(__('No tienes permiso para hacer inventarios.'));
        }
        if ($line->stockTake === null || ! $line->stockTake->isOpen()) {
            throw new DomainException(__('Este inventario ya no está abierto.'));
        }

        $typed = trim((string) $typed);
        $reserve = trim((string) $reserve);
        if ($typed === '' && ($reserve === '' || ! $line->countsReserve())) {
            if (trim((string) $notCountedReason) === '') {
                throw new DomainException(__('Indica por qué no se cuenta.'));
            }
            $line->forceFill([
                'not_counted' => true, 'not_counted_reason' => trim((string) $notCountedReason),
                'counted_cg' => null, 'counted_units' => null, 'expected_cg' => null, 'expected_units' => null,
                'counted_reserve_cg' => null, 'expected_reserve_cg' => null,
                'counted_by' => $actor->id, 'counted_at' => null,
            ])->save();

            return $line;
        }

        return DB::transaction(function () use ($line, $typed, $reserve, $actor): StockTakeLine {
            $item = $line->countable_type === Batch::class
                ? Batch::query()->withoutGlobalScopes()->whereKey($line->countable_id)->lockForUpdate()->firstOrFail()
                : Article::query()->withoutGlobalScopes()->whereKey($line->countable_id)->lockForUpdate()->firstOrFail();
            $unit = $item instanceof Article || $item->isUnitType();

            if ($unit) {
                if (preg_match('/^\d+$/', $typed) !== 1) {
                    throw new DomainException(__('Escribe un número entero de unidades.'));
                }
                $counted = ['counted_units' => (int) $typed, 'expected_units' => $item instanceof Article ? (int) $item->stock : (int) $item->remaining_units,
                    'counted_cg' => null, 'expected_cg' => null];
            } else {
                foreach (array_filter([$typed, $reserve], fn (string $v): bool => $v !== '') as $grams) {
                    if (TypedNumber::canonical($grams) === null) {
                        throw new DomainException(__('Escribe los gramos sin separador de miles y con dos decimales como máximo (p. ej. 1000 o 3.5).'));
                    }
                }
                // Both snapshots, whatever was typed: what the system held for each figure WHEN this row was counted.
                $counted = [
                    'counted_cg' => $typed === '' ? null : Weight::fromGrams($typed)->centigrams, 'expected_cg' => $item->remaining_cg->centigrams,
                    'counted_reserve_cg' => $reserve === '' ? null : Weight::fromGrams($reserve)->centigrams, 'expected_reserve_cg' => $item->reserve_cg->centigrams,
                    'counted_units' => null, 'expected_units' => null,
                ];
            }

            $line->forceFill($counted + [
                'not_counted' => false, 'not_counted_reason' => null,
                'counted_by' => $actor->id, 'counted_at' => now(),
            ])->save();

            return $line;
        });
    }
}
