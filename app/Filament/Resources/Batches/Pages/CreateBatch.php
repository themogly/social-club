<?php

namespace App\Filament\Resources\Batches\Pages;

use App\Actions\Stock\IntakeBatch;
use App\Exceptions\StockCeilingExceededException;
use App\Filament\Concerns\ReturnsToList;
use App\Filament\Concerns\WarnsBelowCost;
use App\Filament\Forms\DecimalInput;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Batches\Schemas\BatchForm;
use App\Filament\Support\AllOption;
use App\Models\Genetic;
use App\Models\Location;
use App\Support\ActiveScope;
use App\Support\BelowCost;
use App\Support\Money;
use App\Support\SplitQuantity;
use App\Support\Weight;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class CreateBatch extends CreateRecord
{
    use ReturnsToList;
    use WarnsBelowCost;

    protected static string $resource = BatchResource::class;

    /**
     * Prompt 320 — *Crear lote* opened from the "Genética creada" notification arrives with `?genetic=<id>` and starts
     * with that strain chosen. Only a strain this person can see (the organisation scope; not deleted) is taken; any
     * other value is ignored and the field stays empty.
     */
    #[Url(as: 'genetic')]
    public ?string $preselectGenetic = null;

    protected function afterFill(): void
    {
        $id = (string) $this->preselectGenetic;
        $genetic = $id !== '' ? Genetic::query()->find($id) : null;
        if ($genetic instanceof Genetic) {
            $this->data['product_type'] = $genetic->product_type->value; // prompt 323 — the type first, filled from the strain
            $this->data['genetic_id'] = $genetic->id;
        }
    }

    private Genetic $intakeGenetic;

    private Location $intakeLocation;

    private string $intakeQuantity = '';

    /** True when the genetic has no active base price at the chosen sede — the batch will not dispense there. */
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

        // Prompt 303 — one location, or several (a split intake: a part at each).
        $chosen = AllOption::chosen($data['location_id'] ?? null);
        $locationId = $chosen[0] ?? app(ActiveScope::class)->locationId();
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

        $salePriceCents = Money::fromEuros((string) ($data['sale_price_eur'] ?? 0))->cents;
        $intake = [
            'batch_no' => $data['batch_no'] ?? null, // the grow's own number, or null to generate one (prompt 298)
            'label' => $data['label'] ?? null,
            'cost_per_gram_cents' => Money::fromEuros((string) ($data['cost_per_gram_eur'] ?? 0))->cents,
            // The batch's own sale price and photos (prompt 278).
            'price_per_gram_cents' => $genetic->isUnitType() ? null : $salePriceCents,
            'price_per_unit_cents' => $genetic->isUnitType() ? $salePriceCents : null,
            'price_per_eighth_cents' => filled($data['price_per_eighth_eur'] ?? null) ? Money::fromEuros((string) $data['price_per_eighth_eur'])->cents : null,
            // Prompt 382 — the Local / Personal lists; blank stays blank (the standard less the list's default %).
            ...BatchForm::listPrices($data, $genetic->isUnitType()),
            'images' => array_values((array) ($data['images'] ?? [])),
            'acquired_or_harvested_on' => $data['acquired_or_harvested_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'lab_report_path' => $data['lab_report_path'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        if (count($chosen) > 1) {
            return $this->intakeSplit($genetic, $chosen, $data, $intake);
        }

        // Intake in the genetic's own unit — grams for WEIGHT, whole units for UNIT.
        if ($genetic->isUnitType()) {
            $units = (int) ($data['units'] ?? 0);
            $intake['units'] = $units;
            $this->intakeQuantity = trans_choice(':count unidad|:count unidades', $units, ['count' => $units]);
        } else {
            $intake['grams'] = $data['grams'];
            $intake['reserve_grams'] = filled($data['reserve_grams'] ?? null) ? (string) $data['reserve_grams'] : 0; // prompt 359
            // "g" is a unit symbol, not translatable copy; only the number varies.
            $this->intakeQuantity = Weight::fromGrams((string) $data['grams'])->formatted(); // the one formatter — rtrim read 250 as "25 g"
        }

        // Held for the confirmation: what was added, where, and whether it can actually be dispensed there.
        $this->intakeGenetic = $genetic;
        $this->intakeLocation = $location;

        try {
            return (new IntakeBatch)->handle($genetic, $location, $intake);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['data.batch_no' => $e->getMessage()]);
        } catch (StockCeilingExceededException $e) {
            throw ValidationException::withMessages(['data.location_id' => $e->getMessage()]);
        }
    }

    /** How many locations the lote was just split across (prompt 303), for the confirmation. */
    public int $splitCount = 0;

    /**
     * Prompt 303 — one lote, a part at each location ticked, each with its own quantity, all or nothing
     * ({@see IntakeBatch::handleParts()}). Every part must be more than zero (the form says so per location).
     *
     * @param  list<string>  $locationIds
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $intake
     */
    private function intakeSplit(Genetic $genetic, array $locationIds, array $data, array $intake): Model
    {
        $unit = $genetic->isUnitType();
        $locations = Location::query()->withoutGlobalScopes()->whereIn('id', $locationIds)->get()->keyBy('id');
        $parts = array_map(fn (string $id): array => ['location' => $locations[$id]]
            + ($unit ? ['units' => (int) data_get($data, "units_at.{$id}", 0)] : ['grams' => (string) data_get($data, "grams_at.{$id}", '0')]), $locationIds);

        try {
            $batches = (new IntakeBatch)->handleParts($genetic, $parts, $intake);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['data.batch_no' => $e->getMessage()]);
        } catch (StockCeilingExceededException $e) {
            throw ValidationException::withMessages(['data.location_id' => $e->getMessage()]);
        }

        $this->splitCount = $batches->count();
        $this->intakeGenetic = $genetic;
        $this->intakeLocation = $locations[$locationIds[0]];

        return $batches->first();
    }

    /**
     * *Repartir a partes iguales* (prompt 303): the total shared out over the locations ticked, in centigrams or whole
     * units, never losing any — the leftover goes to the first location ({@see SplitQuantity}).
     */
    public function splitEqually(): void
    {
        $ids = AllOption::chosen($this->data['location_id'] ?? null);
        $genetic = filled($this->data['genetic_id'] ?? null) ? Genetic::query()->find($this->data['genetic_id']) : null;
        if (count($ids) < 2 || $genetic === null) {
            return;
        }

        if ($genetic->isUnitType()) {
            $shares = SplitQuantity::evenly(max(0, (int) ($this->data['units'] ?? 0)), count($ids));
            $this->data['units_at'] = array_combine($ids, array_map('strval', $shares));

            return;
        }

        $total = ($grams = DecimalInput::number($this->data['grams'] ?? null)) !== null ? Weight::fromGrams($grams)->centigrams : 0;
        $shares = SplitQuantity::evenly(max(0, $total), count($ids));
        $this->data['grams_at'] = array_combine($ids, array_map(fn (int $cg): string => intdiv($cg, 100).'.'.str_pad((string) ($cg % 100), 2, '0', STR_PAD_LEFT), $shares));
    }

    /** The confirmation names WHAT went WHERE — never a bare "created" that hides the sede a batch belongs to. */
    protected function getCreatedNotification(): ?Notification
    {
        if ($this->splitCount > 1) {
            return Notification::make()->success()
                ->title(__('Lote creado en :count sedes', ['count' => $this->splitCount]))
                ->body($this->intakeGenetic->name);
        }

        return Notification::make()
            ->success()
            ->title(__('Lote añadido'))
            ->body(__(':quantity de :genetic en :sede.', [
                'quantity' => $this->intakeQuantity,
                'genetic' => $this->intakeGenetic->name,
                'sede' => $this->intakeLocation->name,
            ]));
    }

    /** @return list<array{field: string, price_cents: int, cost_cents: int, line: string}> */
    protected function belowCostOffences(): array
    {
        $genetic = filled($this->data['genetic_id'] ?? null) ? Genetic::query()->find($this->data['genetic_id']) : null;
        $unit = $genetic?->isUnitType() ?? false;
        $sale = self::typedCents($this->data['sale_price_eur'] ?? null);

        return BelowCost::offences(
            self::typedCents($this->data['cost_per_gram_eur'] ?? null),
            $unit ? null : $sale,
            $unit ? $sale : null,
            $unit ? null : self::typedCents($this->data['price_per_eighth_eur'] ?? null),
            $genetic?->grams_per_unit_cg !== null ? (int) $genetic->grams_per_unit_cg : null,
        );
    }

    protected function belowCostField(string $offence): string
    {
        return $offence === 'per_eighth' ? 'price_per_eighth_eur' : 'sale_price_eur';
    }
}
