<?php

namespace App\Filament\Resources\Batches;

use App\Actions\Stock\IntakeBatch;
use App\Actions\Stock\RecountBatch;
use App\Actions\Stock\TransferBatch;
use App\Enums\BatchStatus;
use App\Exceptions\StockCeilingExceededException;
use App\Filament\Resources\Batches\Schemas\BatchForm;
use App\Filament\Support\AllOption;
use App\Models\Batch;
use App\Models\Location;
use App\Models\User;
use App\Rules\GramAmount;
use App\Support\Weight;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;

/**
 * Prompt 302 — the batch actions used in MORE than one place, defined once. *Asignar a sede / Trasladar* is a row button
 * on the batch list and the primary button on the batch's own page: the same form, the same rules, the same
 * `TransferBatch`. (Precio, Ajuste and Retirada stay in the list's ⋮ only — whether they belong on the page too is an
 * open question for the owner.)
 */
final class BatchActions
{
    /** "Asignar a sede" for store stock (it goes out to a sede), "Trasladar" anywhere else. */
    public static function transferLabel(Batch $record): string
    {
        return $record->location?->isStore() ? __('Asignar a sede') : __('Trasladar');
    }

    /**
     * Trasladar / Asignar a sede (prompt 277, Ben's 270) — move some or all of this batch to another location through
     * the one writer, `TransferBatch`. On a batch at the grow / central store it reads "Asignar a sede". Gated on
     * `stock.transfer`; the destinations are the locations this person may write to (stores included).
     */
    public static function transfer(): Action
    {
        return Action::make('transfer')
            ->label(fn (Batch $record): string => self::transferLabel($record))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            // Hidden, never greyed: only with the permission, stock left, and an OPEN batch.
            ->visible(fn (Batch $record): bool => (Auth::user()?->can('stock.transfer') ?? false)
                && $record->status === BatchStatus::OPEN
                && ($record->isUnitType() ? (int) $record->remaining_units : $record->remaining_cg->centigrams) > 0)
            ->modalHeading(fn (Batch $record): string => __('Trasladar :batch', ['batch' => $record->displayName()]))
            ->schema([
                Select::make('to_location_id')
                    ->label(__('Destino'))
                    ->options(fn (Batch $record): array => array_diff_key(Location::assignableOptions(includeStores: true), [$record->location_id => true]))
                    ->required(),
                Toggle::make('all')
                    ->label(__('Todo lo que queda'))
                    ->live(),
                TextInput::make('quantity')
                    ->label(fn (Batch $record): string => $record->isUnitType() ? __('Cantidad (uds)') : __('Cantidad (g)'))
                    ->helperText(fn (Batch $record): string => __('Quedan :left.', ['left' => $record->isUnitType()
                        ? (int) $record->remaining_units.' '.__('uds')
                        : $record->remaining_cg->formatted()]))
                    ->required(fn (Get $get): bool => ! $get('all'))
                    ->hidden(fn (Get $get): bool => (bool) $get('all')),
            ])
            ->modalSubmitActionLabel(__('Trasladar'))
            ->action(function (Batch $record, array $data, mixed $livewire): void {
                $actor = Auth::user();
                $to = Location::query()->withoutGlobalScopes()->find($data['to_location_id'] ?? null);

                try {
                    abort_unless($actor instanceof User && $to instanceof Location, 403);
                    $quantity = ! empty($data['all'])
                        ? ($record->isUnitType() ? (int) $record->remaining_units : $record->remaining_cg->centigrams)
                        : ($record->isUnitType() ? (int) $data['quantity'] : Weight::fromGrams((string) $data['quantity'])->centigrams);

                    (new TransferBatch)->handle($record, $to, $quantity, $actor);

                    Notification::make()->title(__('Stock trasladado a :to', ['to' => $to->name]))->success()->send();

                    // On the batch's own page: what is left, and — after a whole transfer — where it now is.
                    if ($livewire instanceof EditRecord) {
                        $record->refresh();
                        $livewire->refreshFormData(['location_id']);
                    }
                } catch (InvalidArgumentException|RuntimeException|AuthorizationException $e) {
                    Notification::make()->title(__('No se pudo trasladar'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    /**
     * Prompt 305 — the locations this lote does not reach yet, which the user may write to (stores included). Where a
     * part already exists the tool is *Recuento*, never a second part (251's same-location question stays open).
     *
     * @return array<string, string>
     */
    public static function remainingSedes(Batch $record): array
    {
        $held = $record->lotePartsQuery()->pluck('location_id')->all();

        return array_diff_key(Location::assignableOptions(includeStores: true), array_flip($held));
    }

    /**
     * *Añadir existencias en otra sede* (prompt 305) — the SAME lote at more locations as each is visited and weighed:
     * a sibling part per location with its own quantity ({@see IntakeBatch::addParts()}). 303's per-location boxes and
     * live total. Gated on `stock.manage` (what creating a batch needs); hidden for a deleted strain and when every
     * location already holds a part.
     */
    public static function addParts(): Action
    {
        return Action::make('addParts')
            ->label(__('Añadir existencias en otra sede'))
            ->icon(Heroicon::OutlinedPlusCircle)
            ->visible(fn (Batch $record): bool => (Auth::user()?->can('stock.manage') ?? false)
                && $record->strain() !== null && ! $record->strain()->trashed()
                && self::remainingSedes($record) !== [])
            ->modalHeading(fn (Batch $record): string => __('Añadir existencias de :batch', ['batch' => $record->displayName()]))
            ->schema(fn (Batch $record): array => [
                Select::make('location_id')
                    ->label(__('Sedes'))
                    ->multiple()
                    ->required()
                    ->options(fn (): array => count($options = self::remainingSedes($record)) > 1
                        ? [AllOption::KEY => __('Todas las sedes restantes')] + $options
                        : $options)
                    ->live()
                    ->afterStateUpdated(fn (Select $component, mixed $state, mixed $old) => $component->state(
                        AllOption::sync((array) $state, (array) $old, array_keys(self::remainingSedes($record))),
                    )),
                Grid::make(['default' => 1, 'sm' => 2])
                    ->schema(fn (Get $get): array => BatchForm::partFieldsFor(AllOption::chosen($get('location_id')), $record->isUnitType())),
                TextEntry::make('parts_total')
                    ->label(__('Total'))
                    ->state(fn (Get $get): string => BatchForm::splitTotalFor($get, AllOption::chosen($get('location_id')), $record->isUnitType()))
                    ->visible(fn (Get $get): bool => AllOption::chosen($get('location_id')) !== []),
                TextInput::make('reason')
                    ->label(__('Motivo'))
                    ->default(__('Recuento inicial'))
                    ->maxLength(200),
            ])
            ->modalSubmitActionLabel(__('Añadir'))
            ->action(function (Batch $record, array $data): void {
                $ids = AllOption::chosen($data['location_id'] ?? []);
                $locations = Location::query()->withoutGlobalScopes()->whereIn('id', $ids)->get()->keyBy('id');
                $parts = array_map(fn (string $id): array => ['location' => $locations[$id]] + ($record->isUnitType()
                    ? ['units' => (int) data_get($data, "units_at.{$id}", 0)]
                    : ['grams' => (string) data_get($data, "grams_at.{$id}", '0')]), $ids);

                try {
                    (new IntakeBatch)->addParts($record, $parts, ['reason' => $data['reason'] ?? null, 'operator_id' => Auth::id()]);
                } catch (DomainException|StockCeilingExceededException $e) {
                    Notification::make()->title(__('No se pudo añadir'))->body($e->getMessage())->danger()->send();

                    return;
                }

                $names = array_map(fn (string $id): string => (string) $locations[$id]->name, $ids);
                $last = array_pop($names);
                Notification::make()->success()
                    ->title(__('Añadido a :sedes', ['sedes' => $names === [] ? $last : implode(', ', $names).' '.__('y').' '.$last]))
                    ->send();
            });
    }

    /**
     * *Recuento* (prompt 305) — set this part to what the scale says: the COUNTED quantity, the difference shown live,
     * one ADJUSTMENT for it ({@see RecountBatch}, computed against the locked current figure). Gated on `stock.take`.
     */
    public static function recount(): Action
    {
        return Action::make('recount')
            ->label(__('Recuento'))
            ->icon(Heroicon::OutlinedScale)
            ->visible(fn (): bool => Auth::user()?->can('stock.take') ?? false)
            ->modalHeading(fn (Batch $record): string => __('Recuento de :batch', ['batch' => $record->displayName()]))
            ->schema(fn (Batch $record): array => [
                TextEntry::make('in_system')
                    ->hiddenLabel()
                    ->state(fn (): string => __('En sistema: :qty', ['qty' => self::quantityLabel($record, self::current($record))])),
                TextInput::make('counted')
                    ->label(fn (): string => $record->isUnitType() ? __('Cantidad contada (uds)') : __('Cantidad contada (g)'))
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->rules($record->isUnitType() ? ['integer'] : [new GramAmount])
                    ->live(onBlur: true),
                TextEntry::make('difference')
                    ->label(__('Diferencia'))
                    ->state(fn (Get $get): ?string => is_numeric($get('counted'))
                        ? self::signedLabel($record, self::countedAmount($record, (string) $get('counted')) - self::current($record)) : null)
                    ->color(fn (Get $get): string => ! is_numeric($get('counted')) ? 'gray'
                        : (self::countedAmount($record, (string) $get('counted')) < self::current($record) ? 'danger' : 'success'))
                    ->visible(fn (Get $get): bool => is_numeric($get('counted'))),
                TextInput::make('reason')
                    ->label(__('Motivo'))
                    ->default(__('Recuento'))
                    ->required()
                    ->maxLength(200),
            ])
            ->modalSubmitActionLabel(__('Guardar recuento'))
            ->action(function (Batch $record, array $data, mixed $livewire): void {
                $actor = Auth::user();
                abort_unless($actor instanceof User, 403);
                $shown = self::current($record);

                try {
                    $result = (new RecountBatch)->handle($record, self::countedAmount($record, (string) $data['counted']), (string) $data['reason'], $actor);
                } catch (RuntimeException|InvalidArgumentException|AuthorizationException $e) {
                    Notification::make()->title(__('No se pudo guardar el recuento'))->body($e->getMessage())->danger()->send();

                    return;
                }

                $notification = $result['delta'] === 0
                    ? Notification::make()->success()->title(__('Sin diferencia'))
                    : Notification::make()->success()->title(__('Recuento guardado: :diff', ['diff' => self::signedLabel($record, $result['delta'])]));
                if ($result['before'] !== $shown) {
                    // A sale (or another count) landed while the form was open: the difference is against the real figure.
                    $notification->body(__('Mientras contabas, el sistema pasó de :shown a :before; la diferencia se calculó sobre :before.', [
                        'shown' => self::quantityLabel($record, $shown), 'before' => self::quantityLabel($record, $result['before']),
                    ]));
                }
                $notification->send();

                if ($livewire instanceof EditRecord) {
                    $record->refresh();
                }
            });
    }

    /** The part's remaining quantity now: centigrams, or units. */
    private static function current(Batch $record): int
    {
        $fresh = Batch::query()->withoutGlobalScopes()->find($record->getKey()) ?? $record;

        return $fresh->isUnitType() ? (int) ($fresh->remaining_units ?? 0) : $fresh->remaining_cg->centigrams;
    }

    private static function countedAmount(Batch $record, string $typed): int
    {
        return $record->isUnitType() ? (int) $typed : Weight::fromGrams(str_replace(',', '.', $typed))->centigrams;
    }

    private static function quantityLabel(Batch $record, int $amount): string
    {
        return $record->isUnitType() ? __(':count uds', ['count' => $amount]) : Weight::fromCentigrams($amount)->formatted();
    }

    private static function signedLabel(Batch $record, int $delta): string
    {
        return ($delta > 0 ? '+' : ($delta < 0 ? '−' : '')).self::quantityLabel($record, abs($delta));
    }
}
