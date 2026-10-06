<?php

namespace App\Actions\Stock;

use App\Actions\RecordAuditLog;
use App\Enums\StockTakeStatus;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Location;
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
    /** @throws AuthorizationException */
    public function handle(Location $location, User $actor): StockTake
    {
        if (! $actor->can('stock.take') || ! app(LocationSwitcher::class)->canAccess($actor, $location->id)) {
            throw new AuthorizationException(__('No puedes hacer inventario en esta sede.'));
        }

        return DB::transaction(function () use ($location, $actor): StockTake {
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
            foreach ($base()->inStock()->pluck('id') as $id) {
                $take->lines()->create(['countable_type' => Batch::class, 'countable_id' => $id]);
            }
            // Prompt 359 — the full inventory is where sealed stock is actually verified: each batch's reserve is its OWN
            // line («Reserva sellada»), counted as one total, its variance adjusting only the reserve.
            foreach ($base()->where('batches.reserve_cg', '>', 0)->pluck('id') as $id) {
                $take->lines()->create(['countable_type' => Batch::class, 'countable_id' => $id, 'reserve' => true]);
            }

            if (! $location->isStore()) {
                $articles = Article::query()->withoutGlobalScopes()
                    ->where('organisation_id', $location->organisation_id)->where('location_id', $location->id)
                    ->where('active', true)->whereNull('deleted_at')->pluck('id');
                foreach ($articles as $id) {
                    $take->lines()->create(['countable_type' => Article::class, 'countable_id' => $id]);
                }
            }

            (new RecordAuditLog)->handle('stock_take.started', $take, null, ['location_id' => $location->id, 'lines' => $take->lines()->count()]);

            return $take;
        });
    }
}
