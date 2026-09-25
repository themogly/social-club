<?php

namespace App\Filament\Resources\Batches\Pages;

use App\Actions\Stock\IntakeBatch;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Genetics\GeneticResource;
use App\Models\Genetic;
use App\Models\Location;
use App\Support\ActiveScope;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateBatch extends CreateRecord
{
    protected static string $resource = BatchResource::class;

    private Genetic $intakeGenetic;

    private Location $intakeLocation;

    private string $intakeQuantity = '';

    /** True when the genetic has no active base price at the chosen sede — the batch will not dispense there. */
    private bool $intakeUnpricedHere = false;

    /**
     * Intake never writes remaining_cg directly: it runs through IntakeBatch, which
     * converts grams → integer centigrams and records the opening INTAKE movement.
     * Weight and money cross the edge here (grams, euros) and are converted to
     * integer units.
     *
     * The sede comes from the FORM now (prompt 238), not the active scope: stock always belongs to a sede,
     * and in the "all sedes" rollup there is no scope to inherit. The Select is required, so `location_id`
     * is present; the guard stays as a fail-closed backstop rather than guessing a sede (prompt 148).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var Genetic $genetic */
        $genetic = Genetic::query()->findOrFail($data['genetic_id']);

        $locationId = $data['location_id'] ?? app(ActiveScope::class)->locationId();
        if ($locationId === null) {
            Notification::make()
                ->title(__('Elige una sede'))
                ->body(__('El stock siempre pertenece a una sede. Selecciona en cuál entra este lote.'))
                ->danger()
                ->send();

            throw new Halt;
        }
        /** @var Location $location */
        $location = Location::query()->findOrFail($locationId);

        $intake = [
            'cost_per_gram_cents' => (int) round_half_up(((float) ($data['cost_per_gram_eur'] ?? 0)) * 100),
            'acquired_or_harvested_on' => $data['acquired_or_harvested_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'lab_report_path' => $data['lab_report_path'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        // Intake in the genetic's own unit — grams for WEIGHT, whole units for UNIT.
        if ($genetic->isUnitType()) {
            $units = (int) ($data['units'] ?? 0);
            $intake['units'] = $units;
            $this->intakeQuantity = trans_choice(':count unidad|:count unidades', $units, ['count' => $units]);
        } else {
            $intake['grams'] = $data['grams'];
            // "g" is a unit symbol, not translatable copy; only the number varies.
            $this->intakeQuantity = rtrim(rtrim((string) $data['grams'], '0'), '.').' g';
        }

        // Held for the confirmation: what was added, where, and whether it can actually be dispensed there.
        $this->intakeGenetic = $genetic;
        $this->intakeLocation = $location;
        $this->intakeUnpricedHere = ! $genetic->hasActivePriceAt($location->id);

        return (new IntakeBatch)->handle($genetic, $location, $intake);
    }

    /** The confirmation names WHAT went WHERE — never a bare "created" that hides the sede a batch belongs to. */
    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__('Lote añadido'))
            ->body(__(':quantity de :genetic en :sede.', [
                'quantity' => $this->intakeQuantity,
                'genetic' => $this->intakeGenetic->name,
                'sede' => $this->intakeLocation->name,
            ]));
    }

    /**
     * The `no_price` consequence, said at the moment it is created rather than discovered at the counter: a
     * genetic with stock but no active price at a sede is simply ABSENT from that sede's POS (prompt 95 —
     * filtered out, never an error), so an operator adds stock and then cannot find it. The warning names the
     * gap and links straight to where the price is set.
     */
    protected function afterCreate(): void
    {
        if (! $this->intakeUnpricedHere) {
            return;
        }

        Notification::make()
            ->warning()
            ->title(__(':genetic no tiene precio en :sede', [
                'genetic' => $this->intakeGenetic->name,
                'sede' => $this->intakeLocation->name,
            ]))
            ->body(__('El lote no se dispensará en esta sede hasta que definas un precio. El stock está registrado; solo falta el precio.'))
            ->persistent()
            ->actions([
                Action::make('setPrice')
                    ->label(__('Poner precio'))
                    ->url(GeneticResource::getUrl('edit', ['record' => $this->intakeGenetic]))
                    ->button(),
            ])
            ->send();
    }
}
