<?php

namespace App\Actions\Pricing;

use App\Models\Batch;
use App\Models\Location;
use App\Models\User;
use App\Support\LocationSwitcher;
use App\Support\TypedNumber;
use App\ViewModels\SedePriceSheet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prompt 382 — *Precios de la sede* → «Guardar todo»: every batch on the sheet whose prices changed, in ONE transaction,
 * each through the one price writer ({@see SetBatchPrice::prices()}) with its own `batch.prices_updated` audit row (before
 * and after). Unchanged batches are not written. Only this sede's open batches with stock are touched, whatever was posted.
 *
 * A typed price must be a number ≥ 0; a standard price cannot be emptied (a blank one on a batch that never had one is left
 * as it is). Blank Local / Personal = the default. The counter reads prices live, so the next basket uses them.
 */
class SaveSedePrices
{
    /**
     * @param  array<string, array<string, mixed>>  $typed  batch id → column → euros as typed
     * @return int how many batches were updated
     *
     * @throws AuthorizationException|ValidationException
     */
    public function handle(Location $location, array $typed, User $actor): int
    {
        if (! $actor->can('prices.manage') || ! app(LocationSwitcher::class)->canAccess($actor, $location->id)) {
            throw new AuthorizationException(__('No tienes permiso para cambiar precios en esta sede.'));
        }

        $sheet = new SedePriceSheet($location);
        $changes = [];
        $errors = [];
        foreach ($sheet->batches() as $batch) {
            $row = $typed[$batch->id] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $prices = [];
            foreach (SedePriceSheet::columns($batch) as $kinds) {
                foreach ($kinds as $column) {
                    $raw = trim((string) ($row[$column] ?? ''));
                    $cents = TypedNumber::cents($raw);
                    if ($raw !== '' && $cents === null) {
                        $errors['prices.'.$batch->id.'.'.$column] = __('Escribe un precio válido (p. ej. 8.80).');

                        continue;
                    }
                    $prices[$column] = $cents;
                }
            }
            $standard = $batch->isUnitType() ? 'price_per_unit_cents' : 'price_per_gram_cents';
            if (($prices[$standard] ?? null) === null) {
                // A priced batch cannot lose its standard price, nor an unpriced one get a Local / Personal price without one.
                if ($batch->getRawOriginal($standard) !== null || array_filter($prices, fn (?int $c): bool => $c !== null) !== []) {
                    $errors['prices.'.$batch->id.'.'.$standard] = __('El precio Estándar no puede quedar vacío.');
                }

                continue; // an unpriced batch left unpriced: nothing to write
            }
            if ($batch->forceFill($prices)->isDirty()) {
                $changes[$batch->id] = $prices;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($changes, $actor): int {
            $writer = new SetBatchPrice;
            $updated = 0;
            foreach ($changes as $batchId => $prices) {
                $locked = Batch::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($batchId);
                $updated += $writer->prices($locked, $prices, $actor, 'batch.prices_updated') ? 1 : 0;
            }

            return $updated;
        });
    }
}
