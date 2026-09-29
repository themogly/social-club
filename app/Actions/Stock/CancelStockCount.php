<?php

namespace App\Actions\Stock;

use App\Actions\RecordAuditLog;
use App\Enums\StockTakeStatus;
use App\Models\StockTake;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/** Prompt 318 — *Cancelar inventario*: an open count is discarded, audited, and the ledger is never touched. */
class CancelStockCount
{
    /** @throws AuthorizationException|DomainException */
    public function handle(StockTake $take, User $actor): StockTake
    {
        if (! $actor->can('stock.take')) {
            throw new AuthorizationException(__('No tienes permiso para hacer inventarios.'));
        }
        if (! $take->isOpen()) {
            throw new DomainException(__('Este inventario ya no está abierto.'));
        }

        $take->update(['status' => StockTakeStatus::CANCELLED, 'cancelled_by' => $actor->id, 'cancelled_at' => now()]);
        (new RecordAuditLog)->handle('stock_take.cancelled', $take, null, ['location_id' => $take->location_id]);

        return $take;
    }
}
