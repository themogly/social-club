<?php

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Models\Batch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Prompt 368 — the panel's *Ajuste* as Ben asked: "Better just to add a new value — current total and an option to add or
 * take off underneath". The amount is always positive (an iPhone's decimal keypad has no minus key): **Nuevo total** sets the
 * figure, **Añadir** / **Quitar** move it by the amount. The difference is computed HERE, against the locked current figure
 * (as {@see RecountBatch}), so a sale between opening the form and saving it is not counted twice; it is the same single
 * ADJUSTMENT through {@see RecordStockMovement} as before, on the jar or on the sealed reserve (`on_reserve`).
 */
class AdjustBatch
{
    public const TOTAL = 'total';

    public const ADD = 'add';

    public const REMOVE = 'remove';

    /**
     * @param  int  $amount  centigrams (units for a UNIT batch), never negative
     * @return array{before: int, delta: int, after: int}
     */
    public function handle(Batch $batch, string $mode, int $amount, string $reason, ?User $actor, bool $reserve = false): array
    {
        if ($amount < 0) {
            throw new InvalidArgumentException(__('Escribe una cantidad positiva.'));
        }
        if (! in_array($mode, [self::TOTAL, self::ADD, self::REMOVE], true)) {
            throw new InvalidArgumentException('Unknown adjustment mode.');
        }

        return DB::transaction(function () use ($batch, $mode, $amount, $reason, $actor, $reserve): array {
            $locked = Batch::query()->withoutGlobalScopes()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $reserve = $reserve && ! $locked->isUnitType();
            $before = $locked->isUnitType() ? (int) ($locked->remaining_units ?? 0)
                : ($reserve ? $locked->reserve_cg->centigrams : $locked->remaining_cg->centigrams);
            $after = match ($mode) {
                self::TOTAL => $amount,
                self::ADD => $before + $amount,
                default => $before - $amount,
            };
            if ($after < 0) {
                throw new InvalidArgumentException(__('No puede quedar por debajo de 0.'));
            }

            $delta = $after - $before;
            if ($delta !== 0) {
                (new RecordStockMovement)->handle($locked, StockMovementType::ADJUSTMENT, $delta,
                    ['reason' => $reason, 'operator_id' => $actor?->id, 'reserve' => $reserve]);
            }

            return ['before' => $before, 'delta' => $delta, 'after' => $after];
        });
    }
}
