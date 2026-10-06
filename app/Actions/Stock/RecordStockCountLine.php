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
 */
class RecordStockCountLine
{
    /** @throws AuthorizationException|DomainException */
    public function handle(StockTakeLine $line, ?string $typed, User $actor, ?string $notCountedReason = null): StockTakeLine
    {
        if (! $actor->can('stock.take')) {
            throw new AuthorizationException(__('No tienes permiso para hacer inventarios.'));
        }
        if ($line->stockTake === null || ! $line->stockTake->isOpen()) {
            throw new DomainException(__('Este inventario ya no está abierto.'));
        }

        if ($typed === null || trim($typed) === '') {
            if (trim((string) $notCountedReason) === '') {
                throw new DomainException(__('Indica por qué no se cuenta.'));
            }
            $line->forceFill([
                'not_counted' => true, 'not_counted_reason' => trim((string) $notCountedReason),
                'counted_cg' => null, 'counted_units' => null, 'expected_cg' => null, 'expected_units' => null,
                'counted_by' => $actor->id, 'counted_at' => null,
            ])->save();

            return $line;
        }

        return DB::transaction(function () use ($line, $typed, $actor): StockTakeLine {
            $item = $line->countable_type === Batch::class
                ? Batch::query()->withoutGlobalScopes()->whereKey($line->countable_id)->lockForUpdate()->firstOrFail()
                : Article::query()->withoutGlobalScopes()->whereKey($line->countable_id)->lockForUpdate()->firstOrFail();
            $unit = $item instanceof Article || $item->isUnitType();

            if ($unit) {
                if (preg_match('/^\d+$/', trim($typed)) !== 1) {
                    throw new DomainException(__('Escribe un número entero de unidades.'));
                }
                $counted = ['counted_units' => (int) trim($typed), 'expected_units' => $item instanceof Article ? (int) $item->stock : (int) $item->remaining_units,
                    'counted_cg' => null, 'expected_cg' => null];
            } else {
                if (TypedNumber::canonical($typed) === null) {
                    throw new DomainException(__('Escribe los gramos sin separador de miles y con dos decimales como máximo (p. ej. 1000 o 3.5).'));
                }
                $counted = ['counted_cg' => Weight::fromGrams($typed)->centigrams,
                    'expected_cg' => $line->reserve ? $item->reserve_cg->centigrams : $item->remaining_cg->centigrams, // 359
                    'counted_units' => null, 'expected_units' => null];
            }

            $line->forceFill($counted + [
                'not_counted' => false, 'not_counted_reason' => null,
                'counted_by' => $actor->id, 'counted_at' => now(),
            ])->save();

            return $line;
        });
    }
}
