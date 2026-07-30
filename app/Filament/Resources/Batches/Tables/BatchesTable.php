<?php

namespace App\Filament\Resources\Batches\Tables;

use App\Actions\Stock\RecordStockMovement;
use App\Enums\BatchStatus;
use App\Enums\StockMovementType;
use App\Models\Batch;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class BatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('batch_no')->label(__('Nº lote'))->searchable()->sortable(),
                TextColumn::make('genetic.name')->label(__('Genética'))->searchable()->sortable(),
                TextColumn::make('remaining_cg')
                    ->label(__('Restante'))
                    ->state(fn (Batch $record): string => number_format($record->remaining_cg->centigrams / 100, 2).' g'),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->color(fn (BatchStatus $state): string => match ($state) {
                        BatchStatus::OPEN => 'success',
                        BatchStatus::QUARANTINED => 'warning',
                        BatchStatus::CLOSED => 'gray',
                    }),
                TextColumn::make('expires_on')->label(__('Caduca'))->date()->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                self::adjustAction(),
                self::mermaAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /** Ajuste — a signed correction (+/− grams) recorded through the stock ledger. */
    protected static function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label(__('Ajuste'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->schema([
                TextInput::make('grams')
                    ->label(__('Ajuste (g)'))
                    ->numeric()
                    ->required()
                    ->helperText(__('Usa un valor negativo para restar.')),
                Textarea::make('reason')
                    ->label(__('Motivo'))
                    ->required(),
            ])
            ->action(function (Batch $record, array $data): void {
                try {
                    (new RecordStockMovement)->handle(
                        $record,
                        StockMovementType::ADJUSTMENT,
                        (int) round(((float) $data['grams']) * 100),
                        ['reason' => (string) $data['reason'], 'operator_id' => self::operatorId()],
                    );

                    Notification::make()->title(__('Ajuste registrado'))->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Stock insuficiente'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Merma — a loss (spillage, waste, seizure). Gated on stock.merma; always a reduction. */
    protected static function mermaAction(): Action
    {
        return Action::make('merma')
            ->label(__('Merma'))
            ->icon(Heroicon::OutlinedFire)
            ->color('danger')
            ->visible(fn (): bool => Auth::user()?->can('stock.merma') ?? false)
            ->schema([
                TextInput::make('grams')
                    ->label(__('Merma (g)'))
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                Textarea::make('reason')
                    ->label(__('Motivo'))
                    ->required(),
            ])
            ->action(function (Batch $record, array $data): void {
                try {
                    (new RecordStockMovement)->handle(
                        $record,
                        StockMovementType::MERMA,
                        -(int) round(((float) $data['grams']) * 100),
                        ['reason' => (string) $data['reason'], 'operator_id' => self::operatorId(), 'actor' => Auth::user()],
                    );

                    Notification::make()->title(__('Merma registrada'))->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Stock insuficiente'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    protected static function operatorId(): ?string
    {
        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }
}
