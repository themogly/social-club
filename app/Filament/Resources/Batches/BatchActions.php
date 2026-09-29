<?php

namespace App\Filament\Resources\Batches;

use App\Actions\Stock\TransferBatch;
use App\Enums\BatchStatus;
use App\Models\Batch;
use App\Models\Location;
use App\Models\User;
use App\Support\Weight;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
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
}
