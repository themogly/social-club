<?php

namespace App\Actions\Stock;

use App\Actions\RecordAuditLog;
use App\Enums\StockMovementType;
use App\Enums\StockTakeStatus;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\User;
use App\Support\LocationSwitcher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Prompt 318 — *Nuevo inventario*: a full stock count for ONE sede (stores included), on the existing stock_takes. One
 * line per batch WITH STOCK there (308's rule for empty) and per ACTIVE product there — except at a store, which has no
 * products (294/297). Only one open count per sede: a second start opens the existing one. Nothing is locked; the
 * counter keeps trading (each line snapshots the system figure when it is counted, {@see RecordStockCountLine}).
 */
class StartStockCount
{
    /**
     * Prompt 360 — `$includeZero` («Incluir lotes a cero») also lists every WEIGHT batch at the sede with nothing in the jar
     * or the reserve: before 359 the evening reweigh adjusted sealed bags out of stock, so some batches read zero while
     * their bags sit in the back, and they can only be put right here. Those rows are optional: left blank, they hold
     * nothing up and touch nothing.
     *
     * @throws AuthorizationException
     */
    public function handle(Location $location, User $actor, bool $includeZero = false): StockTake
    {
        if (! $actor->can('stock.take') || ! app(LocationSwitcher::class)->canAccess($actor, $location->id)) {
            throw new AuthorizationException(__('No puedes hacer inventario en esta sede.'));
        }

        return DB::transaction(function () use ($location, $actor, $includeZero): StockTake {
            $open = StockTake::query()->withoutGlobalScopes()->inventories()
                ->where('location_id', $location->id)->where('status', StockTakeStatus::OPEN->value)->lockForUpdate()->first();
            if ($open !== null) {
                return $open;
            }

            $take = StockTake::query()->withoutGlobalScopes()->create([
                'organisation_id' => $location->organisation_id,
                'location_id' => $location->id,
                'kind' => StockTake::KIND_INVENTORY,
                'opened_by' => $actor->id,
                'opened_at' => now(),
                'status' => StockTakeStatus::OPEN,
            ]);

            $base = fn () => Batch::query()->withoutGlobalScopes()
                ->where('batches.organisation_id', $location->organisation_id)->where('batches.location_id', $location->id)
                ->whereNull('batches.deleted_at');
            // One row per batch with anything at the sede — in the jar OR sealed in the reserve (359): the row counts both
            // (prompt 360), so the full inventory is where the sealed stock is verified.
            $listed = $base()->where(fn ($q) => $q->inStock()->orWhere('batches.reserve_cg', '>', 0))->pluck('id');
            foreach ($listed as $id) {
                $take->lines()->create(['countable_type' => Batch::class, 'countable_id' => $id]);
            }
            if ($includeZero) {
                $empty = $base()->whereNotIn('batches.id', $listed)->where('batches.reserve_cg', 0)
                    ->whereHas('genetic', fn ($q) => $q->withoutGlobalScopes()->where('unit_type', '!=', 'UNIT'))->pluck('id');
                foreach ($empty as $id) {
                    $take->lines()->create(['countable_type' => Batch::class, 'countable_id' => $id, 'optional' => true]);
                }
            }

            if (! $location->isStore()) {
                $articles = Article::query()->withoutGlobalScopes()
                    ->where('organisation_id', $location->organisation_id)->where('location_id', $location->id)
                    ->where('active', true)->whereNull('deleted_at')->pluck('id');
                foreach ($articles as $id) {
                    $take->lines()->create(['countable_type' => Article::class, 'countable_id' => $id]);
                }
            }

            (new RecordAuditLog)->handle('stock_take.started', $take, null, ['location_id' => $location->id, 'lines' => $take->lines()->count(), 'include_zero' => $includeZero]);

            return $take;
        });
    }

    /**
     * The default of «Incluir lotes a cero» (prompt 360): ON while the sede still looks like the go-live cleanup is to do —
     * no batch there holds a reserve yet, and an evening reweigh (not a full inventory) has adjusted some batch DOWN, the
     * shape that took sealed bags out of stock before 359. Off once reserves exist, when listing empty batches is noise.
     */
    public static function suggestsZeroBatches(Location $location): bool
    {
        $batches = fn () => Batch::query()->withoutGlobalScopes()->where('location_id', $location->id)->whereNull('deleted_at');
        if ($batches()->where('reserve_cg', '>', 0)->exists()) {
            return false;
        }

        return StockMovement::query()->withoutGlobalScopes()
            ->where('stock_movements.location_id', $location->id)
            ->where('stock_movements.type', StockMovementType::ADJUSTMENT->value)->where('stock_movements.qty_cg', '<', 0)
            ->whereIn('stock_movements.stock_take_id', StockTake::query()->withoutGlobalScopes()
                ->where('location_id', $location->id)->where('kind', '!=', StockTake::KIND_INVENTORY)->select('id'))
            ->exists();
    }
}
